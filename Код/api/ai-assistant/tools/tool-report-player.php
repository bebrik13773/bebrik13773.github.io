<?php

require_once dirname(__DIR__, 2) . '/bootstrap/db.php';
require_once dirname(__DIR__) . '/db/ai-chat-schema.php';
require_once dirname(__DIR__) . '/bootstrap/ai_config.php';

/**
 * Один запрос к Telegram sendMessage. Возвращает [ok, httpCode, описаниеОшибки].
 * Сначала curl, если его нет или он не смог — запасной вариант через stream-контекст.
 */
function bober_ai_telegram_request($botToken, array $fields)
{
    $url = 'https://api.telegram.org/bot' . $botToken . '/sendMessage';
    $payload = json_encode($fields, JSON_UNESCAPED_UNICODE);
    $body = false;
    $httpCode = 0;
    $transportError = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch !== false) {
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT => 8,
            ]);
            $body = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($body === false) {
                $transportError = 'curl: ' . curl_error($ch);
            }
            curl_close($ch);
        }
    }

    if ($body === false && ini_get('allow_url_fopen')) {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $payload,
                'timeout' => 8,
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            $transportError .= ($transportError !== '' ? '; ' : '') . 'stream: запрос не прошёл';
        } elseif (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $httpCode = (int) $m[1];
        }
    }

    if ($body === false) {
        return [false, $httpCode, $transportError !== '' ? $transportError : 'нет доступного транспорта (curl/allow_url_fopen)'];
    }

    $decoded = json_decode((string) $body, true);
    if (is_array($decoded) && !empty($decoded['ok'])) {
        return [true, $httpCode, ''];
    }

    $description = is_array($decoded) && isset($decoded['description'])
        ? (string) $decoded['description']
        : 'неожиданный ответ: ' . substr((string) $body, 0, 200);

    return [false, $httpCode, $description];
}

/**
 * Отправляет сообщение в Telegram-бот владельцу игры. Синхронно, без очередей —
 * жалобы редкие (1-2 игрока в месяц), нет смысла усложнять инфраструктуру.
 * Ошибка отправки в Telegram НЕ должна ронять создание жалобы — она уже
 * сохранена в БД, это просто дополнительное уведомление.
 *
 * Раньше успех определялся только по сетевой ошибке curl, а ответ Telegram
 * (chat not found, can't parse entities, 403 и т.д.) молча игнорировался —
 * поэтому причину пропажи уведомлений нельзя было найти. Теперь любая
 * причина пишется в error_log, а при ошибке разметки шлём обычным текстом.
 */
function bober_ai_send_telegram_notification($text)
{
    $config = bober_ai_load_config();
    $botToken = trim((string) ($config['tg_bot_token'] ?? ''));
    $chatId = trim((string) ($config['tg_chat_id'] ?? ''));

    if ($botToken === '' || $chatId === '') {
        error_log('[bober][telegram] не заданы tg_bot_token/tg_chat_id — проверь GitHub Secrets BOBER_TG_BOT_TOKEN и BOBER_TG_CHAT_ID');
        return false;
    }

    list($ok, $httpCode, $error) = bober_ai_telegram_request($botToken, [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ]);
    if ($ok) {
        return true;
    }

    error_log('[bober][telegram] sendMessage не прошёл (HTTP ' . $httpCode . '): ' . $error);

    // Ошибка разметки — повторяем без HTML, чтобы жалоба точно дошла.
    if (stripos($error, 'parse') !== false || stripos($error, 'entities') !== false) {
        list($ok, $httpCode, $error) = bober_ai_telegram_request($botToken, [
            'chat_id' => $chatId,
            'text' => strip_tags($text),
            'disable_web_page_preview' => true,
        ]);
        if ($ok) {
            return true;
        }
        error_log('[bober][telegram] повтор без HTML не прошёл (HTTP ' . $httpCode . '): ' . $error);
    }

    return false;
}

function bober_ai_tool_report_player($conn, $userId, $reporterLogin, array $args)
{
    $reportedLogin = trim((string) ($args['reportedLogin'] ?? ''));
    $description = trim((string) ($args['description'] ?? ''));

    if ($reportedLogin === '' || $description === '') {
        return [
            'success' => false,
            'message' => 'Нужны ник игрока и описание жалобы.',
        ];
    }

    // Пробуем сматчить ник с реальным аккаунтом (может не найтись — это ок,
    // сохраняем сырой ник в reported_name_raw для ручного разбора).
    $reportedUserId = null;
    $stmt = $conn->prepare('SELECT id FROM users WHERE login = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('s', $reportedLogin);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        if ($result instanceof mysqli_result) {
            $result->free();
        }
        $stmt->close();
        if (is_array($row)) {
            $reportedUserId = (int) $row['id'];
        }
    }

    try {
        $reportId = bober_ai_create_player_report($conn, $userId, $reportedUserId, $reportedLogin, $description);

        $notificationText = sprintf(
            "⚠️ <b>Новая жалоба на игрока</b>\nОт: %s\nНа: %s%s\nТекст: %s\nID жалобы: %d",
            htmlspecialchars($reporterLogin, ENT_QUOTES),
            htmlspecialchars($reportedLogin, ENT_QUOTES),
            $reportedUserId === null ? ' (аккаунт не найден по нику)' : '',
            htmlspecialchars($description, ENT_QUOTES),
            $reportId
        );
        bober_ai_send_telegram_notification($notificationText);

        return [
            'success' => true,
            'reportId' => $reportId,
            'message' => 'Жалоба принята и передана на разбор.',
        ];
    } catch (Throwable $error) {
        return [
            'success' => false,
            'message' => bober_exception_message($error),
        ];
    }
}
