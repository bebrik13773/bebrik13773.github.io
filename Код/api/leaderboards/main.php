<?php

require_once dirname(__DIR__) . '/bootstrap/db.php';

try {
    $conn = bober_db_connect();
    bober_ensure_gameplay_schema($conn);
    $leaders = bober_fetch_public_leaderboard($conn, 3);
    $conn->close();

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($leaders, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    bober_json_response(['success' => false, 'message' => bober_exception_message($error)], 500);
}
