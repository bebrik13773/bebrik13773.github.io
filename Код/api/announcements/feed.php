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

    // Получаем только непрочитанные новости
    $unreadAnnouncements = bober_fetch_user_unread_announcement_feed($conn, $userId, [
        'limit' => 30,
    ]);
    
    $announcement = $unreadAnnouncements[0] ?? null;
    $announcementUnreadCount = count($unreadAnnouncements);

    // Для совместимости, возвращаем все новости в announcementFeed
    $allAnnouncements = bober_fetch_user_announcement_feed($conn, $userId, [
        'limit' => 50,
    ]);
    
    $latestAnnouncement = $allAnnouncements[0] ?? bober_fetch_latest_published_announcement($conn);

    $conn->close();

    bober_json_response([
        'success' => true,
        'announcement' => $announcement,
        'latestAnnouncement' => $latestAnnouncement,
        'announcementFeed' => $unreadAnnouncements,
        'announcementUnreadCount' => $announcementUnreadCount,
        'allAnnouncements' => $allAnnouncements,
    ]);
} catch (Throwable $error) {
    bober_json_response(['success' => false, 'message' => bober_exception_message($error)], 500);
}
