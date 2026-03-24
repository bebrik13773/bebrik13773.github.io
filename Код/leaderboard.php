<?php

require_once __DIR__ . '/db.php';

try {
    $conn = bober_db_connect();

    $result = $conn->query("SELECT login, MAX(score) AS score FROM users WHERE login IS NOT NULL AND login <> '' GROUP BY login ORDER BY score DESC LIMIT 3");
    if ($result === false) {
        throw new RuntimeException('Ошибка выполнения запроса.');
    }

    $leaders = [];
    while ($row = $result->fetch_assoc()) {
        $leaders[] = [
            'login' => $row['login'],
            'score' => (int) $row['score'],
        ];
    }

    $result->free();
    $conn->close();

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($leaders, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    bober_json_response(['success' => false, 'message' => bober_exception_message($error)], 500);
}
