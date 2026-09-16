<?php

require_once dirname(__DIR__) . '/bootstrap/db.php';

const MATCH3_TOTAL_LEVELS = 25;

try {
    $userId = bober_get_logged_in_user_id();
    if ($userId === null) {
        bober_json_response(['success' => false, 'message' => 'Сессия не найдена.'], 401);
    }

    $data = bober_read_json_request();
    if (!is_array($data)) {
        bober_json_response(['success' => false, 'message' => 'Некорректный JSON.'], 400);
    }

    $completedLevel = (int) ($data['completedLevel'] ?? 0);
    if ($completedLevel < 1 || $completedLevel > MATCH3_TOTAL_LEVELS) {
        bober_json_response(['success' => false, 'message' => 'Некорректный номер уровня.'], 400);
    }

    $conn = bober_db_connect();
    bober_ensure_gameplay_schema($conn);
    bober_enforce_runtime_access_rules($conn, $userId);

    $accountSnapshotForEconomy = bober_fetch_account_snapshot($conn, $userId);
    $economyMultiplier = is_array($accountSnapshotForEconomy['economy'] ?? null)
        ? max(1.0, (float) ($accountSnapshotForEconomy['economy']['multiplier'] ?? 1.0))
        : 1.0;

    $conn->begin_transaction();

    // Продвигаем прогресс и начисляем монеты только если игрок только что прошел уровень,
    // который на сервере ещё открыт (защита от накрутки/пропуска уровней). Первое прохождение
    // даёт полную награду, повторное прохождение уже пройденного уровня - вдвое меньше.
    $result = bober_advance_match3_level($conn, $userId, $completedLevel, MATCH3_TOTAL_LEVELS, $economyMultiplier);

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

    bober_log_user_activity($conn, $userId, 'match3_level_completed', [
        'action_group' => 'match3',
        'source' => 'match3_complete_level',
        'login' => $_SESSION['game_login'] ?? '',
        'description' => $result['isFirstClear']
            ? 'Пройден новый уровень Три Бобра.'
            : 'Повторно пройден уже открытый уровень Три Бобра.',
        'coins_delta' => $result['awardedCoins'],
        'meta' => [
            'completed_level' => $completedLevel,
            'new_current_level' => $result['progress']['currentLevel'],
            'is_first_clear' => $result['isFirstClear'],
            'awarded_coins' => $result['awardedCoins'],
            'economy_multiplier' => $economyMultiplier,
        ],
    ]);

    if ($result['awardedCoins'] > 0) {
        bober_increment_user_quest_counters($conn, $userId, [
            'match3RewardCoins' => $result['awardedCoins'],
        ]);
    }

    $conn->commit();
    $conn->close();

    bober_json_response([
        'success' => true,
        'match3' => $result['progress'],
        'awardedCoins' => $result['awardedCoins'],
        'isFirstClear' => $result['isFirstClear'],
        'mainScore' => max(0, (int) ($scoreRow['score'] ?? 0)),
    ]);
} catch (Throwable $error) {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->rollback();
        $conn->close();
    }

    bober_json_response(['success' => false, 'message' => bober_exception_message($error)], 500);
}
