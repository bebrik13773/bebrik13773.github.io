<?php

require_once __DIR__ . '/db.php';

try {
    $userId = bober_get_logged_in_user_id();

    if ($userId === null) {
        bober_json_response(['success' => false, 'message' => 'Сессия не найдена.'], 401);
    }

    $conn = bober_db_connect();
    bober_ensure_gameplay_schema($conn);
    bober_enforce_runtime_access_rules($conn, $userId);

    $snapshot = bober_fetch_account_snapshot($conn, $userId);

    $conn->close();

    bober_json_response([
        'success' => true,
        'userId' => (int) ($snapshot['userId'] ?? 0),
        'login' => (string) ($snapshot['login'] ?? ''),
        'mainScore' => max(0, (int) ($snapshot['score'] ?? 0)),
        'flyBeaver' => $snapshot['flyBeaver'] ?? bober_default_fly_beaver_progress(),
        'settings' => $snapshot['settings'] ?? bober_default_user_settings(),
        'achievementUnlocks' => $snapshot['achievementUnlocks'] ?? [],
    ]);
} catch (Throwable $error) {
    bober_json_response(['success' => false, 'message' => bober_exception_message($error)], 500);
}
