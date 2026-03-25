<?php

require_once __DIR__ . '/db.php';

try {
    $userId = bober_get_logged_in_user_id();
    if ($userId === null) {
        bober_json_response(['success' => false, 'message' => 'Сессия не найдена.'], 401);
    }

    $data = bober_read_json_request();
    if (!is_array($data)) {
        bober_json_response(['success' => false, 'message' => 'Некорректный JSON.'], 400);
    }

    $runToken = trim((string) ($data['runToken'] ?? ''));
    if ($runToken === '' || preg_match('/^[A-Za-z0-9_-]{10,80}$/', $runToken) !== 1) {
        bober_json_response(['success' => false, 'message' => 'Некорректный идентификатор забега.'], 400);
    }

    $score = max(0, (int) ($data['score'] ?? 0));
    $level = max(1, (int) ($data['level'] ?? 1));
    $minimumCreditedScore = 15;
    $creditedScore = $score >= $minimumCreditedScore ? $score : 0;

    $conn = bober_db_connect();
    bober_ensure_gameplay_schema($conn);
    bober_enforce_runtime_access_rules($conn, $userId);

    $conn->begin_transaction();

    bober_ensure_fly_progress_row($conn, $userId);

    $insertRunStmt = $conn->prepare('INSERT IGNORE INTO fly_beaver_runs (user_id, run_token, score, level) VALUES (?, ?, ?, ?)');
    if (!$insertRunStmt) {
        throw new RuntimeException('Не удалось подготовить сохранение забега.');
    }

    $insertRunStmt->bind_param('isii', $userId, $runToken, $score, $level);
    if (!$insertRunStmt->execute()) {
        $insertRunStmt->close();
        throw new RuntimeException('Не удалось сохранить забег.');
    }

    $isDuplicate = $insertRunStmt->affected_rows === 0;
    $insertRunStmt->close();

    if (!$isDuplicate) {
        $updateStmt = $conn->prepare('UPDATE fly_beaver_progress SET best_score = GREATEST(best_score, ?), last_score = ?, last_level = ?, games_played = games_played + 1, total_score = total_score + ?, pending_transfer_score = pending_transfer_score + ?, last_played_at = CURRENT_TIMESTAMP WHERE user_id = ?');
        if (!$updateStmt) {
            throw new RuntimeException('Не удалось подготовить обновление прогресса fly-beaver.');
        }

        $updateStmt->bind_param('iiiiii', $score, $score, $level, $creditedScore, $creditedScore, $userId);
        if (!$updateStmt->execute()) {
            $updateStmt->close();
            throw new RuntimeException('Не удалось обновить прогресс fly-beaver.');
        }

        $updateStmt->close();
    }

    $flyBeaver = bober_fetch_fly_beaver_progress($conn, $userId);
    $conn->commit();
    $conn->close();

    bober_json_response([
        'success' => true,
        'message' => $isDuplicate
            ? 'Этот забег уже сохранен.'
            : ($creditedScore > 0
                ? 'Забег сохранен и засчитан в облачный счет.'
                : 'Забег сохранен в статистику, но в начисление не пошел: нужно минимум 15 очков.'),
        'duplicate' => $isDuplicate,
        'creditedScore' => $creditedScore,
        'minimumCreditedScore' => $minimumCreditedScore,
        'flyBeaver' => $flyBeaver,
    ]);
} catch (Throwable $error) {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->rollback();
        $conn->close();
    }

    bober_json_response(['success' => false, 'message' => bober_exception_message($error)], 500);
}
