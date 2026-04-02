<?php

require_once dirname(__DIR__) . '/bootstrap/db.php';

try {
    $userId = bober_get_logged_in_user_id();
    if ($userId === null) {
        bober_json_response(['success' => false, 'message' => 'Сессия не найдена.'], 401);
    }

    $data = bober_read_json_request();
    if (!is_array($data)) {
        bober_json_response(['success' => false, 'message' => 'Некорректный JSON.'], 400);
    }

    $announcementId = max(0, (int) ($data['announcementId'] ?? 0));
    if ($announcementId < 1) {
        bober_json_response(['success' => false, 'message' => 'Не указан идентификатор новости.'], 400);
    }

    $conn = bober_db_connect();
    bober_ensure_gameplay_schema($conn);
    bober_enforce_runtime_access_rules($conn, $userId);
    bober_mark_announcement_seen($conn, $userId, $announcementId);
    $conn->close();

    bober_json_response([
        'success' => true,
        'announcementId' => $announcementId,
        'message' => 'Новость помечена как прочитанная.',
    ]);
} catch (Throwable $error) {
    bober_json_response(['success' => false, 'message' => bober_exception_message($error)], 500);
}
