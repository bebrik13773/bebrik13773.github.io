<?php

require_once dirname(__DIR__) . '/bootstrap/db.php';

try {
    $userId = bober_get_logged_in_user_id();
    if ($userId === null) {
        bober_json_response(['success' => false, 'message' => 'Сессия не найдена.'], 401);
    }

    $conn = bober_db_connect();
    bober_ensure_gameplay_schema($conn);
    bober_enforce_runtime_access_rules($conn, $userId);

    $announcementFeed = bober_fetch_user_announcement_feed($conn, $userId, [
        'limit' => 30,
    ]);
    $announcement = null;
    foreach ($announcementFeed as $announcementItem) {
        if (empty($announcementItem['isRead'])) {
            $announcement = $announcementItem;
            break;
        }
    }
    $latestAnnouncement = $announcementFeed[0] ?? bober_fetch_latest_published_announcement($conn);
    $announcementUnreadCount = 0;
    foreach ($announcementFeed as $announcementItem) {
        if (empty($announcementItem['isRead'])) {
            $announcementUnreadCount += 1;
        }
    }

    $conn->close();

    bober_json_response([
        'success' => true,
        'announcement' => $announcement,
        'latestAnnouncement' => $latestAnnouncement,
        'announcementFeed' => $announcementFeed,
        'announcementUnreadCount' => $announcementUnreadCount,
    ]);
} catch (Throwable $error) {
    bober_json_response(['success' => false, 'message' => bober_exception_message($error)], 500);
}
