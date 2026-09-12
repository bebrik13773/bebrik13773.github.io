<?php

require_once dirname(__DIR__) . '/bootstrap/db.php';

try {
    $userId = bober_get_logged_in_user_id();
    if ($userId === null) {
        bober_json_response(['success' => false, 'message' => 'Сессия не найдена.'], 401);
    }

    $conn = bober_db_connect();
    bober_ensure_gameplay_schema($conn);
    bober_enforce_runtime_access_rules($conn, $userId);

    $conn->begin_transaction();
    bober_ensure_match3_progress_row($conn, $userId);

    $accountSnapshotForEconomy = bober_fetch_account_snapshot($conn, $userId);
    $economyMultiplier = is_array($accountSnapshotForEconomy['economy'] ?? null)
        ? max(1.0, (float) ($accountSnapshotForEconomy['economy']['multiplier'] ?? 1.0))
        : 1.0;

    $selectStmt = $conn->prepare('SELECT pending_transfer_score, transfer_window_started_at, transfer_window_coins FROM match3_progress WHERE user_id = ? LIMIT 1 FOR UPDATE');
    if (!$selectStmt) {
        throw new RuntimeException('Не удалось подготовить получение награды Три Бобра.');
    }

    $selectStmt->bind_param('i', $userId);
    if (!$selectStmt->execute()) {
        $selectStmt->close();
        throw new RuntimeException('Не удалось получить награду Три Бобра.');
    }

    $result = $selectStmt->get_result();
    $row = $result ? $result->fetch_assoc() : ['pending_transfer_score' => 0];
    if ($result) {
        $result->free();
    }
    $selectStmt->close();

    $minimumTransferScore = 30;
    $baseCoinsPerScore = 15.0;
    $coinsPerScoreEffective = $baseCoinsPerScore * $economyMultiplier;
    $hourlyCoinsLimit = (int) round(20000 * $economyMultiplier);
    $hourlyWindowSeconds = 60 * 60;
    $pendingScore = max(0, (int) ($row['pending_transfer_score'] ?? 0));
    $windowStartedAtRaw = isset($row['transfer_window_started_at']) ? trim((string) $row['transfer_window_started_at']) : '';
    $windowUsedCoins = max(0, (int) ($row['transfer_window_coins'] ?? 0));
    $nowTimestamp = time();
    $windowStartedTimestamp = $windowStartedAtRaw !== '' ? strtotime($windowStartedAtRaw) : false;
    $isWindowExpired = $windowStartedTimestamp === false || ($nowTimestamp - $windowStartedTimestamp) >= $hourlyWindowSeconds;

    if ($isWindowExpired) {
        $windowUsedCoins = 0;
        $windowStartedTimestamp = false;
        $windowStartedAtRaw = '';
    }

    $remainingCoins = max(0, $hourlyCoinsLimit - $windowUsedCoins);
    $requestedScore = $pendingScore >= $minimumTransferScore ? $pendingScore : 0;
    $maxScoreByHourlyLimit = $coinsPerScoreEffective > 0 ? (int) floor($remainingCoins / $coinsPerScoreEffective) : 0;
    $awardedScore = ($requestedScore > 0 && $maxScoreByHourlyLimit >= $minimumTransferScore)
        ? min($requestedScore, $maxScoreByHourlyLimit)
        : 0;
    $awardedCoins = (int) round($awardedScore * $coinsPerScoreEffective);
    $currentWindowStartedAt = $windowStartedAtRaw;
    $currentWindowCoins = $windowUsedCoins;

    if ($awardedCoins > 0) {
        if ($windowStartedTimestamp === false) {
            $windowStartedTimestamp = $nowTimestamp;
            $currentWindowStartedAt = date('Y-m-d H:i:s', $windowStartedTimestamp);
            $currentWindowCoins = 0;
        }

        $updateUserStmt = $conn->prepare('UPDATE users SET score = score + ? WHERE id = ?');
        if (!$updateUserStmt) {
            throw new RuntimeException('Не удалось подготовить перевод очков в кликер.');
        }

        $updateUserStmt->bind_param('ii', $awardedCoins, $userId);
        if (!$updateUserStmt->execute()) {
            $updateUserStmt->close();
            throw new RuntimeException('Не удалось перевести очки в кликер.');
        }
        $updateUserStmt->close();

        $currentWindowCoins += $awardedCoins;
        $updateMatch3Stmt = $conn->prepare('UPDATE match3_progress SET pending_transfer_score = GREATEST(0, pending_transfer_score - ?), transferred_total_score = transferred_total_score + ?, transfer_window_started_at = ?, transfer_window_coins = ? WHERE user_id = ?');
        if (!$updateMatch3Stmt) {
            throw new RuntimeException('Не удалось обновить прогресс Три Бобра после перевода.');
        }

        $updateMatch3Stmt->bind_param('iisii', $awardedScore, $awardedScore, $currentWindowStartedAt, $currentWindowCoins, $userId);
        if (!$updateMatch3Stmt->execute()) {
            $updateMatch3Stmt->close();
            throw new RuntimeException('Не удалось завершить перевод очков.');
        }
        $updateMatch3Stmt->close();
    }

    $hourlyRemainingCoins = max(0, $hourlyCoinsLimit - $currentWindowCoins);
    $hourlyResetAt = $currentWindowStartedAt !== ''
        ? date('Y-m-d H:i:s', strtotime($currentWindowStartedAt) + $hourlyWindowSeconds)
        : null;

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

    $match3 = bober_fetch_match3_progress($conn, $userId);
    bober_log_user_activity($conn, $userId, 'match3_reward_claim', [
        'action_group' => 'match3',
        'source' => 'match3_claim_reward',
        'login' => $_SESSION['game_login'] ?? '',
        'description' => $awardedCoins > 0
            ? 'Очки Три Бобра переведены в основной кликер.'
            : 'Попытка перевести очки Три Бобра без фактической выдачи.',
        'score_delta' => $awardedScore,
        'coins_delta' => $awardedCoins,
        'meta' => [
            'awarded_score' => $awardedScore,
            'awarded_coins' => $awardedCoins,
            'pending_score_before' => $pendingScore,
            'minimum_transfer_score' => $minimumTransferScore,
            'hourly_limit' => $hourlyCoinsLimit,
            'hourly_used_after' => $currentWindowCoins,
            'hourly_remaining_after' => $hourlyRemainingCoins,
        ],
    ]);
    if ($awardedCoins > 0) {
        bober_increment_user_quest_counters($conn, $userId, [
            'match3RewardCoins' => $awardedCoins,
        ]);
    }

    $conn->commit();
    $conn->close();

    if ($awardedCoins > 0) {
        if ($awardedScore < $requestedScore) {
            $message = "Переведена часть очков из Три Бобра. Достигнут лимит вывода: максимум {$hourlyCoinsLimit} коинов в час, остаток остался в очереди.";
        } else {
            $message = sprintf('Очки из Три Бобра переведены в основной кликер по курсу 1 очко = %s коинов.', rtrim(rtrim(number_format($coinsPerScoreEffective, 2, '.', ''), '0'), '.'));
        }
    } elseif ($requestedScore < $minimumTransferScore) {
        $message = 'Для перевода нужно минимум 30 очков из Три Бобра.';
    } elseif ($remainingCoins < ($minimumTransferScore * $coinsPerScoreEffective)) {
        $message = "Сейчас достигнут лимит вывода: максимум {$hourlyCoinsLimit} коинов в час. Попробуйте позже.";
    } else {
        $message = 'Перевод сейчас недоступен. Попробуйте позже.';
    }

    bober_json_response([
        'success' => true,
        'message' => $message,
        'awardedScore' => $awardedScore,
        'awardedCoins' => $awardedCoins,
        'minimumTransferScore' => $minimumTransferScore,
        'coinsPerScore' => round($coinsPerScoreEffective, 4),
        'hourlyCoinsLimit' => $hourlyCoinsLimit,
        'hourlyCoinsUsed' => $currentWindowCoins,
        'hourlyCoinsRemaining' => $hourlyRemainingCoins,
        'hourlyWindowResetAt' => $hourlyResetAt,
        'mainScore' => max(0, (int) ($scoreRow['score'] ?? 0)),
        'match3' => $match3,
    ]);
} catch (Throwable $error) {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->rollback();
        $conn->close();
    }

    bober_json_response(['success' => false, 'message' => bober_exception_message($error)], 500);
}
