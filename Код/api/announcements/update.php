<?php

require_once dirname(__DIR__) . '/bootstrap/db.php';

try {
    $request = bober_read_json_request() ?? [];
    
    // Проверяем админ-пароль
    $adminPassword = trim((string) ($request['adminPassword'] ?? ''));
    if ($adminPassword === '') {
        bober_json_response(['success' => false, 'message' => 'Админ-пароль не предоставлен'], 403);
    }

    $conn = bober_db_connect();
    bober_ensure_admin_schema($conn);
    bober_ensure_announcement_schema($conn);

    $configuredHash = bober_configured_admin_password_hash();
    if ($configuredHash === null || !password_verify($adminPassword, $configuredHash)) {
        bober_json_response(['success' => false, 'message' => 'Неверный админ-пароль'], 403);
    }

    $announcementId = max(0, (int) ($request['id'] ?? 0));
    $title = trim((string) ($request['title'] ?? ''));
    $body = trim((string) ($request['body'] ?? ''));
    $bodyFormat = trim((string) ($request['bodyFormat'] ?? 'markdown'));
    $isPublished = !empty($request['isPublished']) ? 1 : 0;

    if ($bodyFormat !== 'markdown' && $bodyFormat !== 'plain' && $bodyFormat !== 'html') {
        $bodyFormat = 'markdown';
    }

    if ($title === '') {
        bober_json_response(['success' => false, 'message' => 'Название обязательно'], 400);
    }

    if ($body === '') {
        bober_json_response(['success' => false, 'message' => 'Содержимое обязательно'], 400);
    }

    if ($announcementId > 0) {
        // Обновляем существующую новость
        $stmt = $conn->prepare('
            UPDATE announcements
            SET title = ?, body = ?, body_format = ?, is_published = ?
            WHERE id = ?
            LIMIT 1
        ');
        if (!$stmt) {
            throw new RuntimeException('Не удалось подготовить обновление новости.');
        }

        $stmt->bind_param('sssii', $title, $body, $bodyFormat, $isPublished, $announcementId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Не удалось обновить новость.');
        }
        $stmt->close();

        $announcement = bober_fetch_admin_announcement_item($conn, $announcementId);
        $conn->close();

        bober_json_response([
            'success' => true,
            'message' => 'Новость обновлена',
            'announcement' => $announcement,
        ]);
    } else {
        // Создаем новую новость
        $stmt = $conn->prepare('
            INSERT INTO announcements (title, body, body_format, is_published, published_at)
            VALUES (?, ?, ?, ?, ?)
        ');
        if (!$stmt) {
            throw new RuntimeException('Не удалось подготовить создание новости.');
        }

        $publishedAt = $isPublished ? date('Y-m-d H:i:s') : null;
        $stmt->bind_param('sssss', $title, $body, $bodyFormat, $isPublished, $publishedAt);
        
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Не удалось создать новость.');
        }

        $newId = $conn->insert_id;
        $stmt->close();

        $announcement = bober_fetch_admin_announcement_item($conn, $newId);
        $conn->close();

        bober_json_response([
            'success' => true,
            'message' => 'Новость создана',
            'announcement' => $announcement,
        ]);
    }
} catch (Throwable $error) {
    bober_json_response(['success' => false, 'message' => bober_exception_message($error)], 500);
}
