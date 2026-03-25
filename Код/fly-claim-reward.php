<?php

require_once __DIR__ . '/db.php';

try {
    $userId = bober_get_logged_in_user_id();
    if ($userId === null) {
        bober_json_response(['success' => false, 'message' => 'Сессия не найдена.'], 401);
    }

    $conn = bober_db_connect();
    bober_ensure_gameplay_schema($conn);

    $activeBan = bober_fetch_active_user_ban($conn, $userId);
    if ($activeBan !== null) {
        $conn->close();
        bober_logout_user();
        bober_json_response([
            'success' => false,
            'message' => $activeBan['message'],
            'ban' => $activeBan,
        ], 403);
    }

    $conn->begin_transaction();
    bober_ensure_fly_progress_row($conn, $userId);

    $selectStmt = $conn->prepare('SELECT pending_transfer_score FROM fly_beaver_progress WHERE user_id = ? LIMIT 1 FOR UPDATE');
    if (!$selectStmt) {
        throw new RuntimeException('Не удалось подготовить получение награды fly-beaver.');
    }

    $selectStmt->bind_param('i', $userId);
    if (!$selectStmt->execute()) {
        $selectStmt->close();
        throw new RuntimeException('Не удалось получить награду fly-beaver.');
    }

    $result = $selectStmt->get_result();
    $row = $result ? $result->fetch_assoc() : ['pending_transfer_score' => 0];
    if ($result) {
        $result->free();
    }
    $selectStmt->close();

    $awardedScore = max(0, (int) ($row['pending_transfer_score'] ?? 0));

    if ($awardedScore > 0) {
        $updateUserStmt = $conn->prepare('UPDATE users SET score = score + ? WHERE id = ?');
        if (!$updateUserStmt) {
            throw new RuntimeException('Не удалось подготовить перевод очков в кликер.');
        }

        $updateUserStmt->bind_param('ii', $awardedScore, $userId);
        if (!$updateUserStmt->execute()) {
            $updateUserStmt->close();
            throw new RuntimeException('Не удалось перевести очки в кликер.');
        }
        $updateUserStmt->close();

        $updateFlyStmt = $conn->prepare('UPDATE fly_beaver_progress SET pending_transfer_score = 0, transferred_total_score = transferred_total_score + ? WHERE user_id = ?');
        if (!$updateFlyStmt) {
            throw new RuntimeException('Не удалось обновить прогресс fly-beaver после перевода.');
        }

        $updateFlyStmt->bind_param('ii', $awardedScore, $userId);
        if (!$updateFlyStmt->execute()) {
            $updateFlyStmt->close();
            throw new RuntimeException('Не удалось завершить перевод очков.');
        }
        $updateFlyStmt->close();
    }

    $scoreStmt = $conn->prepare('SELECT score FROM users WHERE id = ? LIMIT 1');
    if (!$scoreStmt) {
        throw new RuntimeException('Не удалось получить новый счет игрока.');
    }

    $scoreStmt->bind_param('i', $userId);
    if (!$scoreStmt->execute()) {
        $scoreStmt->close();
        throw new RuntimeException('Не удалось получить новый счет игрока.');
    }

    $scoreResult = $scoreStmt->get_result();
    $scoreRow = $scoreResult ? $scoreResult->fetch_assoc() : ['score' => 0];
    if ($scoreResult) {
        $scoreResult->free();
    }
    $scoreStmt->close();

    $flyBeaver = bober_fetch_fly_beaver_progress($conn, $userId);

    $conn->commit();
    $conn->close();

    bober_json_response([
        'success' => true,
        'message' => $awardedScore > 0 ? 'Очки из Летающего бобра переведены в основной кликер.' : 'Для перевода пока нет очков.',
        'awardedScore' => $awardedScore,
        'mainScore' => max(0, (int) ($scoreRow['score'] ?? 0)),
        'flyBeaver' => $flyBeaver,
    ]);
} catch (Throwable $error) {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->rollback();
        $conn->close();
    }

    bober_json_response(['success' => false, 'message' => bober_exception_message($error)], 500);
}
