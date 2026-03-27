<?php

require_once __DIR__ . '/db.php';

try {
    $userId = bober_get_logged_in_user_id();

    if ($userId === null) {
        bober_json_response(['success' => false, 'message' => 'Сессия не найдена.'], 401);
    }

    $conn = bober_db_connect();
    bober_ensure_gameplay_schema($conn);

    $sessionValidation = bober_validate_current_game_session($conn, $userId, [
        'source' => 'session_check',
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

    $activeIpBan = bober_fetch_active_ip_ban($conn);
    if ($activeIpBan !== null) {
        $conn->close();
        bober_logout_user();
        bober_json_response([
            'success' => false,
            'message' => $activeIpBan['message'],
            'ipBan' => $activeIpBan,
        ], 403);
    }

    $snapshot = bober_fetch_account_snapshot($conn, $userId);

    $activeBan = bober_fetch_active_user_ban($conn, (int) ($snapshot['userId'] ?? 0));
    if ($activeBan !== null) {
        bober_propagate_user_ban_to_ip_bans($conn, (int) ($snapshot['userId'] ?? 0), $activeBan, [
            'include_current_ip' => false,
        ]);
        $conn->close();
        bober_logout_user();
        bober_json_response([
            'success' => false,
            'message' => $activeBan['message'],
            'ban' => $activeBan,
        ], 403);
    }

    bober_record_user_ip($conn, (int) ($snapshot['userId'] ?? 0));

    $conn->close();

    bober_json_response($snapshot);
} catch (Throwable $error) {
    bober_json_response(['success' => false, 'message' => bober_exception_message($error)], 500);
}
