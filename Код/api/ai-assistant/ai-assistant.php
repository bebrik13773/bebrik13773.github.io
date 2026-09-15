<?php

require_once __DIR__ . '/bootstrap/ai_config.php';
require_once dirname(__DIR__) . '/bootstrap/db.php';
require_once __DIR__ . '/db/ai-chat-schema.php';
require_once __DIR__ . '/context/build-user-context.php';
require_once __DIR__ . '/tools/tool-definitions.php';
require_once __DIR__ . '/tools/tool-get-advice.php';
require_once __DIR__ . '/tools/tool-buy-upgrade.php';
require_once __DIR__ . '/tools/tool-create-ticket.php';
require_once __DIR__ . '/tools/tool-report-player.php';

/**
 * Собирает системный промпт из отдельных .md файлов (личность, тон,
 * знания об игре, границы безопасности) + текущий контекст игрока.
 */
function bober_ai_build_system_prompt(array $userContext)
{
    $promptsDir = __DIR__ . '/prompts';

    $personality = @file_get_contents($promptsDir . '/personality.md') ?: '';
    $toneRules = @file_get_contents($promptsDir . '/tone-rules.md') ?: '';
    $gameKnowledge = @file_get_contents($promptsDir . '/game-knowledge.md') ?: '';
    $safetyBoundaries = @file_get_contents($promptsDir . '/safety-boundaries.md') ?: '';

    $contextJson = bober_ai_user_context_to_json($userContext);

    return $personality . "\n\n"
        . "## Определение тона\n" . $toneRules . "\n\n"
        . "## Знания об игре\n" . $gameKnowledge . "\n\n"
        . "## Границы безопасности\n" . $safetyBoundaries . "\n\n"
        . "## Данные текущего игрока (JSON)\n" . $contextJson;
}

/**
 * Вызывает RouterAI (OpenAI-совместимый Chat Completions API).
 * Возвращает декодированный JSON-ответ или бросает исключение при ошибке сети/API.
 */
function bober_ai_call_router(array $messages, array $tools)
{
    $config = bober_ai_load_config();
    $apiKey = (string) ($config['ai_api_key'] ?? '');
    $baseUrl = rtrim((string) ($config['ai_base_url'] ?? ''), '/');
    $model = (string) ($config['ai_model'] ?? '');

    if ($apiKey === '' || $baseUrl === '' || $model === '') {
        throw new RuntimeException('ИИ-помощник временно не настроен (нет ключа/модели).');
    }

    $payload = [
        'model' => $model,
        'messages' => $messages,
        'tools' => $tools,
        'tool_choice' => 'auto',
        'max_tokens' => 800,
    ];

    $ch = curl_init($baseUrl . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);

    $responseBody = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($responseBody === false) {
        throw new RuntimeException('Не удалось связаться с ИИ-сервисом: ' . $curlError);
    }

    $decoded = json_decode($responseBody, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('ИИ-сервис вернул некорректный ответ.');
    }

    if ($httpCode >= 400) {
        $errorMessage = is_array($decoded['error'] ?? null)
            ? (string) ($decoded['error']['message'] ?? 'Неизвестная ошибка ИИ-сервиса.')
            : 'Ошибка ИИ-сервиса (код ' . $httpCode . ').';
        throw new RuntimeException($errorMessage);
    }

    return $decoded;
}

/**
 * Выполняет вызванный моделью tool и возвращает результат в виде строки
 * (JSON), которую нужно положить обратно в историю сообщений с role=tool.
 */
function bober_ai_execute_tool_call($conn, $userId, $login, array $userContext, $toolName, array $toolArgs)
{
    switch ($toolName) {
        case 'get_advice':
            return bober_ai_tool_get_advice($userContext, $toolArgs);
        case 'buy_upgrade':
            return bober_ai_tool_buy_upgrade($conn, $userId, $toolArgs);
        case 'create_support_ticket':
            return bober_ai_tool_create_support_ticket($conn, $userId, $toolArgs);
        case 'report_player':
            return bober_ai_tool_report_player($conn, $userId, $login, $toolArgs);
        default:
            return ['error' => 'Неизвестный tool: ' . $toolName];
    }
}

try {
    $data = bober_read_json_request();
    if (!is_array($data)) {
        bober_json_response(['success' => false, 'message' => 'Некорректный JSON.'], 400);
    }

    $userMessage = trim((string) ($data['message'] ?? ''));
    $forceNewSession = !empty($data['newSession']);

    if ($userMessage === '' && !$forceNewSession) {
        bober_json_response(['success' => false, 'message' => 'Сообщение не может быть пустым.'], 400);
    }

    $sessionUserId = bober_get_logged_in_user_id();
    if ($sessionUserId === null) {
        bober_json_response(['success' => false, 'message' => 'Войдите в аккаунт, чтобы пообщаться с бобром.'], 401);
    }

    $conn = bober_db_connect();
    bober_ensure_gameplay_schema($conn);
    bober_ai_ensure_schema($conn);

    $sessionValidation = bober_validate_current_game_session($conn, $sessionUserId, [
        'source' => 'ai_assistant',
        'login' => $_SESSION['game_login'] ?? '',
    ]);
    if (empty($sessionValidation['ok'])) {
        $payload = is_array($sessionValidation['payload'] ?? null)
            ? $sessionValidation['payload']
            : bober_build_session_ended_payload();

        $conn->close();
        bober_logout_user(['skip_session_revoke' => true]);
        bober_json_response($payload, 409);
    }

    bober_enforce_runtime_access_rules($conn, $sessionUserId);

    // Если это просто запрос на новый чат без текста — создаём новую сессию
    // и выходим сразу, не тратя обращение к RouterAI.
    if ($userMessage === '' && $forceNewSession) {
        bober_ai_get_or_create_session($conn, $sessionUserId, true);
        $conn->close();
        bober_json_response(['success' => true, 'reply' => '', 'newSessionStarted' => true]);
    }

    // Общий rate-limit на сообщения чата — щедрый лимит, чтобы не мешать
    // нормальному общению, но защищающий бюджет API от накрутки/спама.
    if (!bober_ai_check_and_bump_rate_limit($conn, $sessionUserId, 15)) {
        $conn->close();
        bober_json_response([
            'success' => true,
            'reply' => 'Устал болтать 😴 Отдохни немного и возвращайся через часок — бобру тоже нужен перерыв.',
            'rateLimited' => true,
        ]);
    }

    $chatSessionId = bober_ai_get_or_create_session($conn, $sessionUserId, $forceNewSession);
    $userContext = bober_ai_build_user_context($conn, $sessionUserId);
    $login = (string) ($userContext['login'] ?? '');

    $systemPrompt = bober_ai_build_system_prompt($userContext);
    $priorMessages = bober_ai_fetch_session_messages($conn, $chatSessionId, 20);

    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
    ];
    foreach ($priorMessages as $priorMessage) {
        $role = (string) ($priorMessage['role'] ?? 'user');
        if ($role !== 'user' && $role !== 'assistant') {
            continue; // не тащим служебные tool-сообщения из истории в новый запрос
        }
        $messages[] = ['role' => $role, 'content' => (string) ($priorMessage['content'] ?? '')];
    }
    $messages[] = ['role' => 'user', 'content' => $userMessage];

    bober_ai_append_message($conn, $chatSessionId, 'user', $userMessage);

    $tools = bober_ai_tool_definitions();

    // До двух витков tool-calling: модель может вызвать tool, получить
    // результат и на его основе сформулировать финальный текстовый ответ.
    $finalReplyText = '';
    $maxRounds = 3;

    for ($round = 0; $round < $maxRounds; $round++) {
        $response = bober_ai_call_router($messages, $tools);
        $choice = is_array($response['choices'][0] ?? null) ? $response['choices'][0] : [];
        $assistantMessage = is_array($choice['message'] ?? null) ? $choice['message'] : [];
        $toolCalls = is_array($assistantMessage['tool_calls'] ?? null) ? $assistantMessage['tool_calls'] : [];

        if (empty($toolCalls)) {
            $finalReplyText = trim((string) ($assistantMessage['content'] ?? ''));
            break;
        }

        // Кладём сообщение ассистента (с запросом на tool_calls) обратно в историю запроса
        $messages[] = $assistantMessage;

        foreach ($toolCalls as $toolCall) {
            $toolCallId = (string) ($toolCall['id'] ?? '');
            $functionName = (string) ($toolCall['function']['name'] ?? '');
            $rawArgs = (string) ($toolCall['function']['arguments'] ?? '{}');
            $parsedArgs = json_decode($rawArgs, true);
            if (!is_array($parsedArgs)) {
                $parsedArgs = [];
            }

            $toolResult = bober_ai_execute_tool_call($conn, $sessionUserId, $login, $userContext, $functionName, $parsedArgs);

            // Если купили апгрейд — обновляем контекст игрока для следующего витка,
            // чтобы модель видела актуальный баланс при финальном ответе.
            if ($functionName === 'buy_upgrade' && !empty($toolResult['success'])) {
                $userContext = bober_ai_build_user_context($conn, $sessionUserId);
            }

            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => $toolCallId,
                'content' => json_encode($toolResult, JSON_UNESCAPED_UNICODE),
            ];

            bober_ai_append_message($conn, $chatSessionId, 'tool', json_encode($toolResult, JSON_UNESCAPED_UNICODE), $functionName);
        }
    }

    if ($finalReplyText === '') {
        $finalReplyText = 'Кажется, я немного завис 😅 Попробуй переформулировать вопрос.';
    }

    bober_ai_append_message($conn, $chatSessionId, 'assistant', $finalReplyText);
    bober_ai_touch_session($conn, $chatSessionId);

    $conn->close();

    bober_json_response([
        'success' => true,
        'reply' => $finalReplyText,
    ]);
} catch (Throwable $error) {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }

    bober_json_response([
        'success' => false,
        'message' => bober_exception_message($error, 'Бобёр что-то не может ответить прямо сейчас. Попробуй чуть позже.'),
    ], 500);
}
