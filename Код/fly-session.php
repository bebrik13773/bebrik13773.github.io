<?php

require_once __DIR__ . '/db.php';

try {
    $userId = bober_get_logged_in_user_id();

    if ($userId === null) {
        bober_json_response(['success' => false, 'message' => 'Сессия не найдена.'], 401);
    }

    $conn = bober_db_connect();
    bober_ensure_gameplay_schema($conn);
    bober_enforce_runtime_access_rules($conn, $userId);

    $stmt = $conn->prepare('SELECT id, login, score FROM users WHERE id = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Ошибка подготовки запроса.');
    }

    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Ошибка выполнения запроса.');
    }

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result) {
        $result->free();
    }
    $stmt->close();

    if (!$row) {
        $conn->close();
        bober_logout_user();
        bober_json_response(['success' => false, 'message' => 'Сессия устарела.'], 401);
    }

    $flyBeaver = bober_fetch_fly_beaver_progress($conn, (int) $row['id']);

    $conn->close();

    bober_json_response([
        'success' => true,
        'userId' => (int) $row['id'],
        'login' => (string) $row['login'],
        'mainScore' => max(0, (int) $row['score']),
        'flyBeaver' => $flyBeaver,
    ]);
} catch (Throwable $error) {
    bober_json_response(['success' => false, 'message' => bober_exception_message($error)], 500);
}
