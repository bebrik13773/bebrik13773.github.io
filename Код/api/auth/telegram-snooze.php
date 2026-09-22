<?php

require_once dirname(__DIR__) . '/bootstrap/db.php';

try {
    $userId = bober_get_logged_in_user_id();

    if ($userId === null) {
        bober_json_response(['success' => false, 'message' => 'Сначала войдите в игровой аккаунт.'], 401);
    }

    $conn = bober_db_connect();
    bober_ensure_gameplay_schema($conn);

    bober_snooze_telegram_link_prompt($conn, $userId, 14);

    bober_log_user_activity($conn, $userId, 'telegram_link_snoozed', [
        'action_group' => 'auth',
        'source' => 'telegram_snooze',
        'description' => 'Игрок отложил напоминание о привязке Telegram (нет доступа).',
    ]);

    $conn->close();

    bober_json_response(['success' => true]);
} catch (Throwable $error) {
    $statusCode = $error instanceof InvalidArgumentException ? 400 : 500;
    bober_json_response(['success' => false, 'message' => bober_exception_message($error)], $statusCode);
}
