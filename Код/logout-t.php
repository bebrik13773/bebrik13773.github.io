<?php

require_once __DIR__ . '/db.php';

try {
    $userId = bober_get_logged_in_user_id();
    if ($userId !== null) {
        $conn = bober_db_connect();
        bober_ensure_gameplay_schema($conn);
        bober_log_user_activity($conn, (int) $userId, 'logout', [
            'action_group' => 'auth',
            'source' => 'logout',
            'login' => $_SESSION['game_login'] ?? '',
            'description' => 'Пользователь вышел из аккаунта.',
        ]);
        $conn->close();
    }

    bober_logout_user(['reason' => 'manual_logout']);
    bober_json_response(['success' => true, 'message' => 'Вы вышли из аккаунта.']);
} catch (Throwable $error) {
    bober_json_response(['success' => false, 'message' => bober_exception_message($error)], 500);
}
