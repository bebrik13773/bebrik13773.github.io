<?php

require_once dirname(__DIR__) . '/bootstrap/db.php';

try {
    $userId = bober_get_logged_in_user_id();
    if ($userId === null) {
        bober_json_response(['success' => false, 'message' => 'Сессия не найдена.'], 401);
    }

    $data = bober_read_json_request();
    if (!is_array($data)) {
        bober_json_response(['success' => false, 'message' => 'Некорректный JSON.'], 400);
    }

    $boosterType = trim((string) ($data['boosterType'] ?? ''));
    $allowedBoosters = ['freeMove', 'extraTime'];
    if (!in_array($boosterType, $allowedBoosters, true)) {
        bober_json_response(['success' => false, 'message' => 'Неизвестный тип бустера.'], 400);
    }

    $conn = bober_db_connect();
    bober_ensure_gameplay_schema($conn);
    bober_enforce_runtime_access_rules($conn, $userId);

    $conn->begin_transaction();

    $lockStmt = $conn->prepare('SELECT score FROM users WHERE id = ? LIMIT 1 FOR UPDATE');
    if (!$lockStmt) {
        throw new RuntimeException('Не удалось подготовить проверку баланса.');
    }
    $lockStmt->bind_param('i', $userId);
    if (!$lockStmt->execute()) {
        $lockStmt->close();
        throw new RuntimeException('Не удалось проверить баланс.');
    }
    $lockResult = $lockStmt->get_result();
    $lockRow = $lockResult ? $lockResult->fetch_assoc() : null;
    if ($lockResult) {
        $lockResult->free();
    }
    $lockStmt->close();

    if (!$lockRow) {
        throw new RuntimeException('Пользователь не найден.');
    }

    $currentScore = max(0, (int) ($lockRow['score'] ?? 0));

    $accountSnapshotForEconomy = bober_fetch_account_snapshot($conn, $userId);
    $economyMultiplier = is_array($accountSnapshotForEconomy['economy'] ?? null)
        ? max(1.0, (float) ($accountSnapshotForEconomy['economy']['multiplier'] ?? 1.0))
        : 1.0;

    $baseBoosterPrice = 15000;
    $boosterPrice = (int) round($baseBoosterPrice * $economyMultiplier);

    if ($currentScore < $boosterPrice) {
        $conn->rollback();
        $conn->close();
        bober_json_response([
            'success' => false,
            'message' => 'Недостаточно коинов для покупки бустера.',
            'boosterPrice' => $boosterPrice,
            'mainScore' => $currentScore,
        ], 200);
    }

    $updateStmt = $conn->prepare('UPDATE users SET score = score - ? WHERE id = ? AND score >= ?');
    if (!$updateStmt) {
        throw new RuntimeException('Не удалось подготовить списание коинов.');
    }
    $updateStmt->bind_param('iii', $boosterPrice, $userId, $boosterPrice);
    if (!$updateStmt->execute()) {
        $updateStmt->close();
        throw new RuntimeException('Не удалось списать коины за бустер.');
    }
    $affected = $updateStmt->affected_rows;
    $updateStmt->close();

    if ($affected < 1) {
        $conn->rollback();
        $conn->close();
        bober_json_response([
            'success' => false,
            'message' => 'Недостаточно коинов для покупки бустера.',
            'boosterPrice' => $boosterPrice,
            'mainScore' => $currentScore,
        ], 200);
    }

    $newScore = $currentScore - $boosterPrice;

    bober_log_user_activity($conn, $userId, 'match3_booster_purchase', [
        'action_group' => 'match3',
        'source' => 'match3_buy_booster',
        'login' => $_SESSION['game_login'] ?? '',
        'description' => 'Куплен бустер в Три Бобра: ' . $boosterType,
        'coins_delta' => -$boosterPrice,
        'meta' => [
            'booster_type' => $boosterType,
            'price' => $boosterPrice,
            'economy_multiplier' => $economyMultiplier,
        ],
    ]);

    $conn->commit();
    $conn->close();

    bober_json_response([
        'success' => true,
        'message' => 'Бустер куплен.',
        'boosterType' => $boosterType,
        'boosterPrice' => $boosterPrice,
        'mainScore' => $newScore,
    ]);
} catch (Throwable $error) {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->rollback();
        $conn->close();
    }

    bober_json_response(['success' => false, 'message' => bober_exception_message($error)], 500);
}
