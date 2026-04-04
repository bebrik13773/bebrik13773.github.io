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
    if ($announcementId < 1) {
        bober_json_response(['success' => false, 'message' => 'ID новости обязателен'], 400);
    }

    $stmt = $conn->prepare('DELETE FROM announcements WHERE id = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить удаление новости.');
    }

    $stmt->bind_param('i', $announcementId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Не удалось удалить новость.');
    }

    $affectedRows = $conn->affected_rows;
    $stmt->close();
    $conn->close();

    if ($affectedRows < 1) {
        bober_json_response(['success' => false, 'message' => 'Новость не найдена'], 404);
    }

    bober_json_response([
        'success' => true,
        'message' => 'Новость удалена',
    ]);
} catch (Throwable $error) {
    bober_json_response(['success' => false, 'message' => bober_exception_message($error)], 500);
}
