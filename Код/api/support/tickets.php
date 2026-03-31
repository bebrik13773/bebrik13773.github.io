<?php

require_once dirname(__DIR__) . '/bootstrap/db.php';

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

    $sessionUserId = bober_get_logged_in_user_id();
    if ($sessionUserId !== null) {
        $sessionValidation = bober_validate_current_game_session($conn, $sessionUserId, [
            'source' => 'support_tickets',
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
    }

    $resolveAppealUserId = static function(mysqli $conn, array $payload) {
        $userId = max(0, (int) ($payload['appealUserId'] ?? $payload['userId'] ?? 0));
        $login = trim((string) ($payload['appealLogin'] ?? $payload['login'] ?? ''));
        if ($userId < 1 || $login === '') {
            throw new RuntimeException('Для апелляции нужно войти в аккаунт или передать логин и идентификатор пользователя.');
        }

        $stmt = $conn->prepare('SELECT id FROM users WHERE id = ? AND login = ? LIMIT 1');
        if (!$stmt) {
            throw new RuntimeException('Не удалось подготовить проверку аккаунта для апелляции.');
        }

        $stmt->bind_param('is', $userId, $login);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Не удалось проверить аккаунт для апелляции.');
        }

        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        if ($result instanceof mysqli_result) {
            $result->free();
        }
        $stmt->close();

        if (!is_array($row)) {
            throw new RuntimeException('Аккаунт для апелляции не найден.');
        }

        return $userId;
    };

    if ($action === 'list_tickets') {
        if ($sessionUserId === null) {
            $conn->close();
            bober_json_response(['success' => false, 'message' => 'Войдите в аккаунт, чтобы открыть тикеты.'], 401);
        }

        $tickets = bober_fetch_user_support_tickets($conn, $sessionUserId, [
            'limit' => (int) ($data['limit'] ?? 50),
        ]);
        $summary = bober_fetch_user_support_summary($conn, $sessionUserId);
        $conn->close();

        bober_json_response([
            'success' => true,
            'tickets' => $tickets,
            'summary' => $summary,
        ]);
    }

    if ($action === 'get_ticket') {
        if ($sessionUserId === null) {
            $conn->close();
            bober_json_response(['success' => false, 'message' => 'Войдите в аккаунт, чтобы открыть тикет.'], 401);
        }

        $ticket = bober_fetch_user_support_ticket($conn, $sessionUserId, (int) ($data['ticketId'] ?? 0), true);
        $summary = bober_fetch_user_support_summary($conn, $sessionUserId);
        $conn->close();

        bober_json_response([
            'success' => true,
            'ticket' => $ticket,
            'summary' => $summary,
        ]);
    }

    if ($action === 'create_ticket') {
        $category = bober_normalize_support_ticket_category($data['category'] ?? '');
        $subject = (string) ($data['subject'] ?? '');
        $message = (string) ($data['message'] ?? '');
        $resolvedUserId = $sessionUserId;
        if ($resolvedUserId === null) {
            if ($category !== 'ban_appeal') {
                $conn->close();
                bober_json_response(['success' => false, 'message' => 'Войдите в аккаунт, чтобы создать тикет.'], 401);
            }

            $resolvedUserId = $resolveAppealUserId($conn, $data);
        }

        $conn->begin_transaction();
        $ticket = bober_create_support_ticket($conn, $resolvedUserId, $category, $subject, $message);
        $summary = bober_fetch_user_support_summary($conn, $resolvedUserId);
        $conn->commit();
        $conn->close();

        bober_json_response([
            'success' => true,
            'message' => 'Тикет поддержки создан.',
            'ticket' => $ticket,
            'summary' => $summary,
        ]);
    }

    if ($action === 'reply_ticket') {
        if ($sessionUserId === null) {
            $conn->close();
            bober_json_response(['success' => false, 'message' => 'Войдите в аккаунт, чтобы ответить в тикет.'], 401);
        }

        $conn->begin_transaction();
        $ticket = bober_reply_support_ticket_as_user($conn, $sessionUserId, (int) ($data['ticketId'] ?? 0), (string) ($data['message'] ?? ''));
        $summary = bober_fetch_user_support_summary($conn, $sessionUserId);
        $conn->commit();
        $conn->close();

        bober_json_response([
            'success' => true,
            'message' => 'Ответ отправлен в поддержку.',
            'ticket' => $ticket,
            'summary' => $summary,
        ]);
    }

    if ($action === 'mark_ticket_read') {
        if ($sessionUserId === null) {
            $conn->close();
            bober_json_response(['success' => false, 'message' => 'Войдите в аккаунт, чтобы отметить тикет прочитанным.'], 401);
        }

        bober_mark_user_support_ticket_read($conn, $sessionUserId, (int) ($data['ticketId'] ?? 0));
        $summary = bober_fetch_user_support_summary($conn, $sessionUserId);
        $conn->close();

        bober_json_response([
            'success' => true,
            'summary' => $summary,
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
