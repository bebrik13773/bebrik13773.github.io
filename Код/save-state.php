<?php

require_once __DIR__ . '/db.php';

try {
    $data = bober_read_json_request();

    if (!is_array($data)) {
        bober_json_response(['success' => false, 'message' => 'Некорректный JSON.'], 400);
    }

    $sessionUserId = bober_get_logged_in_user_id();
    $requestUserId = max(0, (int) ($data['userId'] ?? 0));

    if ($sessionUserId === null) {
        bober_json_response(['success' => false, 'message' => 'Сессия не найдена. Войдите в аккаунт заново.'], 401);
    }

    if ($requestUserId > 0 && $requestUserId !== $sessionUserId) {
        bober_json_response(['success' => false, 'message' => 'Неверный идентификатор пользователя для активной сессии.'], 403);
    }

    $userId = $sessionUserId;

    $conn = bober_db_connect();
    bober_enforce_runtime_access_rules($conn, $userId);
    $saveResult = bober_apply_user_state_update($conn, $userId, $data);

    $conn->close();

    bober_json_response([
        'success' => true,
        'message' => 'Прогресс сохранен.',
        'clientLog' => $saveResult['clientLog'] ?? null,
    ]);
} catch (Throwable $error) {
    bober_json_response(['success' => false, 'message' => bober_exception_message($error)], 500);
}
