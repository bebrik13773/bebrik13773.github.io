<?php

require_once __DIR__ . '/db.php';

try {
    $data = bober_read_json_request();

    if (!is_array($data)) {
        bober_json_response(['success' => false, 'message' => 'Некорректный JSON.'], 400);
    }

    $userId = max(0, (int) ($data['userId'] ?? 0));
    $plus = max(1, (int) ($data['plus'] ?? 1));

    if ($userId < 1) {
        bober_json_response(['success' => false, 'message' => 'Некорректный идентификатор пользователя.'], 400);
    }

    $conn = bober_db_connect();

    $stmt = $conn->prepare('UPDATE users SET plus = ? WHERE id = ?');
    if (!$stmt) {
        throw new RuntimeException('Ошибка подготовки запроса.');
    }

    $stmt->bind_param('ii', $plus, $userId);

    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Ошибка выполнения запроса.');
    }

    $stmt->close();
    $conn->close();

    bober_json_response(['success' => true, 'message' => 'Улучшение сохранено.']);
} catch (Throwable $error) {
    bober_json_response(['success' => false, 'message' => bober_exception_message($error)], 500);
}
