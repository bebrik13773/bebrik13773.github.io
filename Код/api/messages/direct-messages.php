<?php

require_once dirname(__DIR__) . '/bootstrap/db.php';
require_once __DIR__ . '/db/direct-messages-schema.php';
require_once __DIR__ . '/moderation.php';

try {
    $data = bober_read_json_request();
    if (!is_array($data)) {
        bober_json_response(['success' => false, 'message' => 'Некорректный JSON.'], 400);
    }

    $action = trim((string) ($data['action'] ?? ''));
    if ($action === '') {
        bober_json_response(['success' => false, 'message' => 'Не указано действие.'], 400);
    }

    $conn = bober_db_connect();
    bober_ensure_gameplay_schema($conn);
    bober_dm_ensure_schema($conn);

    $sessionUserId = bober_get_logged_in_user_id();
    if ($sessionUserId === null) {
        $conn->close();
        bober_json_response(['success' => false, 'message' => 'Войдите в аккаунт, чтобы пользоваться личными сообщениями.'], 401);
    }

    $sessionValidation = bober_validate_current_game_session($conn, $sessionUserId, [
        'source' => 'direct_messages',
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

    if ($action === 'find_user') {
        $login = (string) ($data['login'] ?? '');
        $found = bober_dm_find_user_by_login($conn, $login, $sessionUserId);
        $conn->close();

        bober_json_response([
            'success' => true,
            'user' => $found,
        ]);
    }

    if ($action === 'list_conversations') {
        $conversations = bober_dm_fetch_conversations_for_user($conn, $sessionUserId, [
            'limit' => (int) ($data['limit'] ?? 50),
        ]);
        $conn->close();

        bober_json_response([
            'success' => true,
            'conversations' => $conversations,
        ]);
    }

    if ($action === 'get_conversation') {
        $conversationId = (int) ($data['conversationId'] ?? 0);
        if ($conversationId < 1 && !empty($data['withUserId'])) {
            // Открытие переписки по id собеседника (например, из профиля
            // игрока) — если треда ещё нет, он будет создан при первом
            // сообщении, а не здесь, чтобы не плодить пустые треды от
            // одного лишь открытия профиля.
            $withUserId = max(0, (int) $data['withUserId']);
            $existing = bober_dm_fetch_conversations_for_user($conn, $sessionUserId, ['limit' => 100]);
            $match = null;
            foreach ($existing as $item) {
                if ($item['otherUserId'] === $withUserId) {
                    $match = $item;
                    break;
                }
            }

            if ($match === null) {
                $conn->close();
                bober_json_response([
                    'success' => true,
                    'conversation' => null,
                ]);
            }

            $conversationId = $match['conversationId'];
        }

        $conversation = bober_dm_fetch_conversation_messages($conn, $sessionUserId, $conversationId);
        bober_dm_mark_conversation_read($conn, $sessionUserId, $conversationId);
        $conn->close();

        bober_json_response([
            'success' => true,
            'conversation' => $conversation,
        ]);
    }

    if ($action === 'send_message') {
        $recipientUserId = max(0, (int) ($data['recipientUserId'] ?? 0));
        $messageText = (string) ($data['message'] ?? '');

        if ($recipientUserId < 1) {
            $conn->close();
            bober_json_response(['success' => false, 'message' => 'Не указан получатель сообщения.'], 400);
        }

        if (trim($messageText) === '') {
            $conn->close();
            bober_json_response(['success' => false, 'message' => 'Сообщение не может быть пустым.'], 400);
        }

        if (!bober_dm_check_and_bump_rate_limit($conn, $sessionUserId)) {
            $conn->close();
            bober_json_response([
                'success' => false,
                'message' => 'Слишком много сообщений подряд. Подождите немного.',
                'rateLimited' => true,
            ], 429);
        }

        $conn->begin_transaction();
        try {
            $sent = bober_dm_send_message($conn, $sessionUserId, $recipientUserId, $messageText);
        } catch (Throwable $sendError) {
            $conn->rollback();
            $conn->close();
            bober_json_response(['success' => false, 'message' => bober_exception_message($sendError)], 400);
        }
        $conversation = bober_dm_fetch_conversation_messages($conn, $sessionUserId, $sent['conversationId']);
        bober_dm_mark_conversation_read($conn, $sessionUserId, $sent['conversationId']);
        $conn->commit();
        $conn->close();

        bober_json_response([
            'success' => true,
            'conversation' => $conversation,
        ]);
    }

    if ($action === 'mark_read') {
        $conversationId = (int) ($data['conversationId'] ?? 0);
        bober_dm_mark_conversation_read($conn, $sessionUserId, $conversationId);
        $conn->close();

        bober_json_response(['success' => true]);
    }

    if ($action === 'set_block') {
        $conversationId = (int) ($data['conversationId'] ?? 0);
        $blocked = !empty($data['blocked']);
        bober_dm_set_block($conn, $sessionUserId, $conversationId, $blocked);
        $conversation = bober_dm_fetch_conversation_messages($conn, $sessionUserId, $conversationId);
        $conn->close();

        bober_json_response([
            'success' => true,
            'conversation' => $conversation,
        ]);
    }

    if ($action === 'report_message') {
        $conversationId = (int) ($data['conversationId'] ?? 0);
        $messageId = (int) ($data['messageId'] ?? 0);
        $reason = (string) ($data['reason'] ?? '');

        $reportId = bober_dm_report_message($conn, $sessionUserId, $conversationId, $messageId, $reason);
        $conn->close();

        bober_json_response([
            'success' => true,
            'message' => 'Жалоба отправлена. Спасибо, мы рассмотрим переписку.',
            'reportId' => $reportId,
        ]);
    }

    $conn->close();
    bober_json_response(['success' => false, 'message' => 'Неизвестное действие.'], 400);
} catch (Throwable $error) {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->rollback();
        $conn->close();
    }

    bober_json_response(['success' => false, 'message' => bober_exception_message($error)], 500);
}
