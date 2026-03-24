<?php

require_once __DIR__ . '/db.php';

try {
    $data = bober_read_json_request();

    if (!is_array($data)) {
        bober_json_response(['success' => false, 'message' => 'Некорректный JSON.'], 400);
    }

    $userId = max(0, (int) ($data['userId'] ?? 0));
    $score = max(0, (int) ($data['score'] ?? 0));
    $skin = array_key_exists('skin', $data) ? bober_normalize_skin_json($data['skin']) : null;

    if ($userId < 1) {
        bober_json_response(['success' => false, 'message' => 'Некорректный идентификатор пользователя.'], 400);
    }

    $conn = bober_db_connect();
    bober_ensure_game_schema($conn);

    if ($skin !== null) {
        $stmt = $conn->prepare('UPDATE users SET score = ?, skin = ? WHERE id = ?');
        if (!$stmt) {
            throw new RuntimeException('Ошибка подготовки запроса.');
        }

        $stmt->bind_param('isi', $score, $skin, $userId);
    } else {
        $stmt = $conn->prepare('UPDATE users SET score = ? WHERE id = ?');
        if (!$stmt) {
            throw new RuntimeException('Ошибка подготовки запроса.');
        }

        $stmt->bind_param('ii', $score, $userId);
    }

    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Ошибка выполнения запроса.');
    }

    $stmt->close();
    $conn->close();

    bober_json_response(['success' => true, 'message' => 'Счет сохранен.']);
} catch (Throwable $error) {
    bober_json_response(['success' => false, 'message' => 'Ошибка сервера.'], 500);
}
