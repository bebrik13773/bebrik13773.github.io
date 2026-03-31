<?php

require_once dirname(__DIR__) . '/bootstrap/db.php';

try {
    $data = bober_read_json_request();

    if (!is_array($data)) {
        bober_json_response(['success' => false, 'message' => 'Некорректный JSON.'], 400);
    }

    $login = trim((string) ($data['login'] ?? ''));
    $password = (string) ($data['password'] ?? '');
    $clientLogBatch = is_array($data['clientLogBatch'] ?? null) ? $data['clientLogBatch'] : null;

    if ($login === '' || $password === '') {
        bober_json_response(['success' => false, 'message' => 'Введите логин и пароль.'], 400);
    }

    $conn = bober_db_connect();
    bober_ensure_gameplay_schema($conn);

    $activeIpBan = bober_fetch_active_ip_ban($conn);
    if ($activeIpBan !== null) {
        $conn->close();
        bober_json_response([
            'success' => false,
            'message' => $activeIpBan['message'],
            'ipBan' => $activeIpBan,
        ], 403);
    }

    $stmt = $conn->prepare('SELECT id, password FROM users WHERE login = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Ошибка подготовки запроса.');
    }

    $stmt->bind_param('s', $login);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Ошибка выполнения запроса.');
    }

    $stmt->store_result();

    if ($stmt->num_rows !== 1) {
        $stmt->close();
        $conn->close();
        bober_json_response(['success' => false, 'message' => 'Неверный логин или пароль.']);
    }

    $stmt->bind_result($id, $hashedPassword);
    $stmt->fetch();
    $stmt->close();

    if (!is_string($hashedPassword) || $hashedPassword === '' || !password_verify($password, $hashedPassword)) {
        $conn->close();
        bober_json_response(['success' => false, 'message' => 'Неверный логин или пароль.']);
    }

    $activeBan = bober_fetch_active_user_ban($conn, (int) $id);
    if ($activeBan !== null) {
        bober_propagate_user_ban_to_ip_bans($conn, (int) $id, $activeBan, [
            'include_current_ip' => false,
        ]);
        $conn->close();
        bober_json_response([
            'success' => false,
            'message' => $activeBan['message'],
            'ban' => $activeBan,
        ], 403);
    }

    $terminateSessionId = max(0, (int) ($data['terminateSessionId'] ?? 0));
    $currentSessionHash = bober_current_session_hash();
    $activeSessions = bober_fetch_user_active_game_sessions($conn, (int) $id, [
        'exclude_session_hash' => $currentSessionHash,
    ]);

    if (!empty($activeSessions)) {
        if ($terminateSessionId > 0) {
            $terminated = bober_revoke_game_session_by_id($conn, (int) $id, $terminateSessionId, 'replaced_on_new_device');
            if (!$terminated) {
                $conn->close();
                bober_json_response(
                    bober_build_session_conflict_payload(
                        $activeSessions,
                        'Не удалось завершить выбранную сессию. Обновите список и попробуйте еще раз.'
                    ),
                    409
                );
            }

            $activeSessions = bober_fetch_user_active_game_sessions($conn, (int) $id, [
                'exclude_session_hash' => $currentSessionHash,
            ]);
        }

        if (!empty($activeSessions)) {
            $conn->close();
            bober_json_response(bober_build_session_conflict_payload($activeSessions), 409);
        }
    }

    $sessionInfo = bober_login_user($id, $login);
    if (!empty($sessionInfo['previousSessionHash']) && !empty($sessionInfo['currentSessionHash']) && $sessionInfo['previousSessionHash'] !== $sessionInfo['currentSessionHash']) {
        bober_revoke_game_session_by_hash($conn, (string) $sessionInfo['previousSessionHash'], 'session_rotated_after_login');
    }
    bober_sync_current_game_session($conn, (int) $id, $login, [
        'source' => 'login',
    ]);
    bober_record_user_ip($conn, (int) $id);
    bober_log_user_activity($conn, (int) $id, 'login_success', [
        'action_group' => 'auth',
        'source' => 'login',
        'login' => $login,
        'description' => $terminateSessionId > 0
            ? 'Вход выполнен после завершения другой активной сессии.'
            : 'Пользователь вошел в аккаунт.',
        'meta' => [
            'terminated_session_id' => $terminateSessionId > 0 ? $terminateSessionId : null,
        ],
    ]);

    $clientLogResult = null;
    if (is_array($clientLogBatch)) {
        try {
            $clientLogResult = bober_store_client_log_batch($conn, (int) $id, $clientLogBatch, [
                'login' => $login,
            ]);
        } catch (Throwable $logError) {
            $clientLogResult = [
                'received' => 0,
                'accepted' => 0,
                'inserted' => 0,
                'duplicates' => 0,
                'warning' => bober_exception_message($logError),
            ];
        }
    }

    $response = bober_fetch_account_snapshot($conn, (int) $id);
    $response['clientLog'] = $clientLogResult;

    $conn->close();
    bober_json_response($response);
} catch (Throwable $error) {
    bober_json_response(['success' => false, 'message' => bober_exception_message($error)], 500);
}
