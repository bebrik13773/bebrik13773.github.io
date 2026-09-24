<?php

require_once dirname(__DIR__) . '/bootstrap/db.php';

try {
    $userId = bober_get_logged_in_user_id();

    if ($userId === null) {
        bober_json_response(['success' => false, 'message' => 'Сначала войдите в игровой аккаунт.'], 401);
    }

    $conn = bober_db_connect();
    bober_ensure_gameplay_schema($conn);

    bober_unlink_telegram_from_user($conn, $userId);

    bober_log_user_activity($conn, $userId, 'telegram_unlink', [
        'action_group' => 'auth',
        'source' => 'telegram_unlink',
        'description' => 'Игрок отвязал Telegram-аккаунт.',
    ]);

    $response = bober_fetch_account_snapshot($conn, $userId);
    $conn->close();

    $response['message'] = 'Telegram отвязан. Теперь можно привязать другой аккаунт.';

    bober_json_response($response);
} catch (Throwable $error) {
    $statusCode = $error instanceof InvalidArgumentException ? 400 : 500;
    bober_json_response(['success' => false, 'message' => bober_exception_message($error)], $statusCode);
}
