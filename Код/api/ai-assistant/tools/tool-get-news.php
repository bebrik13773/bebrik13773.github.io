<?php

/**
 * Tool get_news_full: отдаёт все опубликованные новости игры (announcements),
 * включая уже прочитанные игроком, с датой публикации и отметкой "прочитано".
 * Только чтение, ничего не изменяет и не помечает как прочитанное.
 */
function bober_ai_tool_get_news_full($conn, $userId)
{
    try {
        $announcements = bober_fetch_user_announcement_feed($conn, $userId, [
            'limit' => 100,
        ]);

        $newsOut = [];
        $unreadCount = 0;
        foreach ($announcements as $item) {
            $isRead = !empty($item['isRead']);
            if (!$isRead) {
                $unreadCount++;
            }

            $newsOut[] = [
                'id' => (int) ($item['id'] ?? 0),
                'title' => (string) ($item['title'] ?? ''),
                'body' => (string) ($item['body'] ?? ''),
                'publishedAt' => $item['publishedAt'] ?? $item['createdAt'] ?? null,
                'isReadByPlayer' => $isRead,
            ];
        }

        return [
            'success' => true,
            'totalCount' => count($newsOut),
            'unreadCount' => $unreadCount,
            'news' => $newsOut,
        ];
    } catch (Throwable $error) {
        return ['error' => bober_exception_message($error, 'Не удалось получить список новостей.')];
    }
}
