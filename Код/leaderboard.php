<?php

require_once __DIR__ . '/db.php';

try {
    $conn = bober_db_connect();
    bober_ensure_gameplay_schema($conn);

    $result = $conn->query("
        SELECT u.login, MAX(u.score) AS score
        FROM users u
        LEFT JOIN user_bans b
            ON b.user_id = u.id
            AND b.lifted_at IS NULL
            AND b.ban_until > CURRENT_TIMESTAMP
        WHERE u.login IS NOT NULL
            AND u.login <> ''
            AND LOWER(TRIM(u.login)) <> 'test'
            AND b.id IS NULL
        GROUP BY u.id, u.login
        ORDER BY score DESC
        LIMIT 3
    ");
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
