<?php

require_once dirname(__DIR__) . '/bootstrap/db.php';

try {
    $data = bober_read_json_request();

    if (!is_array($data)) {
        bober_json_response(['success' => false, 'message' => 'Некорректный JSON.'], 400);
    }

    $userId = bober_get_logged_in_user_id();
    if ($userId === null) {
        bober_json_response(['success' => false, 'message' => 'Сессия не найдена.'], 401);
    }

    $clientLogBatch = is_array($data['clientLogBatch'] ?? null) ? $data['clientLogBatch'] : null;
    if (!is_array($clientLogBatch)) {
        bober_json_response(['success' => false, 'message' => 'Не передан clientLogBatch.'], 400);
    }

    $conn = bober_db_connect();
    bober_enforce_runtime_access_rules($conn, $userId);

    $logResult = bober_store_client_log_batch($conn, $userId, $clientLogBatch, [
        'login' => $_SESSION['game_login'] ?? '',
    ]);

    $conn->close();

    bober_json_response([
        'success' => true,
        'message' => 'Пакет клиентского лога сохранен.',
        'clientLog' => $logResult,
    ]);
} catch (Throwable $error) {
    bober_json_response(['success' => false, 'message' => bober_exception_message($error)], 500);
}
