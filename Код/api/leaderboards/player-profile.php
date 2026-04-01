<?php

require_once dirname(__DIR__) . '/bootstrap/db.php';

try {
    $userId = max(0, (int) ($_GET['userId'] ?? 0));
    if ($userId < 1) {
        throw new InvalidArgumentException('Некорректный идентификатор игрока.');
    }

    $conn = bober_db_connect();
    bober_ensure_gameplay_schema($conn);
    $player = bober_fetch_public_player_profile($conn, $userId);
    $conn->close();

    bober_json_response([
        'success' => true,
        'player' => $player,
    ]);
} catch (Throwable $error) {
    $message = bober_exception_message($error);
    $statusCode = 500;
    if ($error instanceof InvalidArgumentException) {
        $statusCode = 400;
    } elseif ($message === 'Игрок не найден в публичной таблице лидеров.') {
        $statusCode = 404;
    }

    bober_json_response([
        'success' => false,
        'message' => $message,
    ], $statusCode);
}
