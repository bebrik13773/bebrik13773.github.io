<?php

require_once dirname(__DIR__, 2) . '/bootstrap/db.php';
require_once dirname(__DIR__) . '/db/ai-chat-schema.php';
require_once dirname(__DIR__) . '/bootstrap/ai_config.php';

/**
 * Отправляет сообщение в Telegram-бот владельцу игры. Синхронно, без очередей —
 * жалобы редкие (1-2 игрока в месяц), нет смысла усложнять инфраструктуру.
 * Ошибка отправки в Telegram НЕ должна ронять создание жалобы — она уже
 * сохранена в БД, это просто дополнительное уведомление.
 */
function bober_ai_send_telegram_notification($text)
{
    $config = bober_ai_load_config();
    $botToken = (string) ($config['tg_bot_token'] ?? '');
    $chatId = (string) ($config['tg_chat_id'] ?? '');

    if ($botToken === '' || $chatId === '') {
        return false;
    }

    $url = 'https://api.telegram.org/bot' . $botToken . '/sendMessage';
    $payload = json_encode([
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
    ]);
    curl_exec($ch);
    $ok = curl_errno($ch) === 0;
    curl_close($ch);

    return $ok;
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
