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

    $announcementIds = [];
    if (is_array($data['announcementIds'] ?? null)) {
        $announcementIds = $data['announcementIds'];
    } elseif (array_key_exists('announcementId', $data)) {
        $announcementIds = [$data['announcementId']];
    }

    $normalizedAnnouncementIds = array_values(array_filter(array_map(static function ($item) {
        return max(0, (int) $item);
    }, $announcementIds), static function ($item) {
        return $item > 0;
    }));
    if (count($normalizedAnnouncementIds) < 1) {
        bober_json_response(['success' => false, 'message' => 'Не указан идентификатор новости.'], 400);
    }

    $conn = bober_db_connect();
    bober_ensure_gameplay_schema($conn);
    bober_enforce_runtime_access_rules($conn, $userId);
    $markedAnnouncementIds = bober_mark_announcements_seen($conn, $userId, $normalizedAnnouncementIds);
    $conn->close();

    bober_json_response([
        'success' => true,
        'announcementId' => $markedAnnouncementIds[0] ?? 0,
        'announcementIds' => $markedAnnouncementIds,
        'message' => count($markedAnnouncementIds) > 1
            ? 'Новости помечены как прочитанные.'
            : 'Новость помечена как прочитанная.',
    ]);
} catch (Throwable $error) {
    bober_json_response(['success' => false, 'message' => bober_exception_message($error)], 500);
}
