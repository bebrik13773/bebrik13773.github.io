<?php

require_once dirname(__DIR__, 2) . '/bootstrap/db.php';

/**
 * Находит место игрока в общем рейтинге по score и его ближайших соседей
 * (по одному сверху и снизу), не завязываясь на bober_fetch_public_leaderboard
 * (та функция отдаёт только топ-N, без позиции конкретного игрока).
 */
function bober_ai_fetch_leaderboard_position($conn, $userId, $userScore)
{
    $userId = max(0, (int) $userId);
    $userScore = max(0, (int) $userScore);

    // Место = 1 + количество игроков (без активного бана) со строго большим score,
    // либо с равным score, но меньшим id (тот же порядок, что в основном рейтинге).
    $stmt = $conn->prepare(<<<SQL
SELECT COUNT(*) AS cnt
FROM users u
LEFT JOIN user_bans b ON b.user_id = u.id AND b.lifted_at IS NULL AND b.ban_until > CURRENT_TIMESTAMP
WHERE b.id IS NULL
  AND u.login IS NOT NULL AND u.login <> '' AND LOWER(TRIM(u.login)) <> 'test'
  AND (u.score > ? OR (u.score = ? AND u.id < ?))
SQL
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('iii', $userScore, $userScore, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    $rank = max(1, (int) ($row['cnt'] ?? 0) + 1);

    // Ближайший сосед сверху (следующее место выше)
    $aboveStmt = $conn->prepare(<<<SQL
SELECT u.login, u.score
FROM users u
LEFT JOIN user_bans b ON b.user_id = u.id AND b.lifted_at IS NULL AND b.ban_until > CURRENT_TIMESTAMP
WHERE b.id IS NULL
  AND u.login IS NOT NULL AND u.login <> '' AND LOWER(TRIM(u.login)) <> 'test'
  AND (u.score > ? OR (u.score = ? AND u.id < ?))
ORDER BY u.score ASC, u.id DESC
LIMIT 1
SQL
    );
    $neighborAbove = null;
    if ($aboveStmt) {
        $aboveStmt->bind_param('iii', $userScore, $userScore, $userId);
        $aboveStmt->execute();
        $aboveResult = $aboveStmt->get_result();
        $aboveRow = $aboveResult ? $aboveResult->fetch_assoc() : null;
        if ($aboveResult instanceof mysqli_result) {
            $aboveResult->free();
        }
        $aboveStmt->close();
        if (is_array($aboveRow)) {
            $neighborAbove = [
                'login' => (string) $aboveRow['login'],
                'score' => max(0, (int) $aboveRow['score']),
                'scoreDiff' => max(0, (int) $aboveRow['score'] - $userScore),
            ];
        }
    }

    // Ближайший сосед снизу (следующее место ниже)
    $belowStmt = $conn->prepare(<<<SQL
SELECT u.login, u.score
FROM users u
LEFT JOIN user_bans b ON b.user_id = u.id AND b.lifted_at IS NULL AND b.ban_until > CURRENT_TIMESTAMP
WHERE b.id IS NULL AND u.id != ?
  AND u.login IS NOT NULL AND u.login <> '' AND LOWER(TRIM(u.login)) <> 'test'
  AND (u.score < ? OR (u.score = ? AND u.id > ?))
ORDER BY u.score DESC, u.id ASC
LIMIT 1
SQL
    );
    $neighborBelow = null;
    if ($belowStmt) {
        $belowStmt->bind_param('iiii', $userId, $userScore, $userScore, $userId);
        $belowStmt->execute();
        $belowResult = $belowStmt->get_result();
        $belowRow = $belowResult ? $belowResult->fetch_assoc() : null;
        if ($belowResult instanceof mysqli_result) {
            $belowResult->free();
        }
        $belowStmt->close();
        if (is_array($belowRow)) {
            $neighborBelow = [
                'login' => (string) $belowRow['login'],
                'score' => max(0, (int) $belowRow['score']),
                'scoreDiff' => max(0, $userScore - (int) $belowRow['score']),
            ];
        }
    }

    return [
        'rank' => $rank,
        'neighborAbove' => $neighborAbove,
        'neighborBelow' => $neighborBelow,
    ];
}

/**
 * Собирает компактный JSON-контекст игрока для передачи в системный промпт ИИ.
 * НЕ включает техданные об устройстве/ошибках и историю чата между сессиями —
 * только игровые + социальные данные (баланс, апгрейды, скины, лидерборд).
 */
function bober_ai_build_user_context($conn, $userId)
{
    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        throw new InvalidArgumentException('Некорректный идентификатор пользователя.');
    }

    $account = bober_fetch_account_snapshot($conn, $userId);
    $profileBlock = is_array($account['profile'] ?? null) ? $account['profile'] : [];

    $login = (string) ($account['login'] ?? '');
    $score = max(0, (int) ($account['score'] ?? 0));
    $plus = max(1, (int) ($account['plus'] ?? 1));
    $energy = max(0, (int) ($account['energy'] ?? 0));
    $energyMax = max(1, (int) ($account['ENERGY_MAX'] ?? 5000));

    $upgradeCounts = is_array($account['upgradePurchases'] ?? null) ? $account['upgradePurchases'] : bober_normalize_upgrade_counts([]);
    $economyProfile = is_array($profileBlock['economy'] ?? null) ? $profileBlock['economy'] : ['index' => 0, 'multiplier' => 1.0];

    $upgradeCatalog = bober_upgrade_shop_catalog();
    $upgradesInfo = [];
    foreach ($upgradeCatalog as $upgradeType => $meta) {
        $currentPrice = bober_calculate_effective_purchase_price((int) ($meta['baseCost'] ?? 0), $economyProfile);
        $upgradesInfo[] = [
            'type' => $upgradeType,
            'description' => (string) ($meta['description'] ?? ''),
            'ownedCount' => max(0, (int) ($upgradeCounts[$upgradeType] ?? 0)),
            'currentPrice' => $currentPrice,
            'affordable' => $score >= $currentPrice,
        ];
    }

    // ownedSkinIds достаём из сырого поля skin (JSON состояние скинов игрока);
    // bober_decode_skin_state сам нормализует JSON внутри себя.
    $skinState = bober_decode_skin_state($account['skin'] ?? null);
    $ownedSkinIds = array_values(array_unique(array_map('strval', $skinState['ownedSkinIds'] ?? [])));
    $equippedSkinId = (string) ($skinState['equippedSkinId'] ?? '');

    $leaderboardPosition = bober_ai_fetch_leaderboard_position($conn, $userId, $score);

    // Последние тикеты поддержки (включая закрытые/архивные) — компактно,
    // чтобы бобёр мог сориентироваться "писал ли игрок уже об этом" без
    // отдельного вызова tool. Полная переписка тикета сюда не тащим — дорого.
    $recentTickets = [];
    try {
        $ticketRows = bober_fetch_user_support_tickets($conn, $userId, [
            'limit' => 5,
            'includeArchived' => true,
        ]);
        foreach ($ticketRows as $ticketRow) {
            $recentTickets[] = [
                'id' => (int) ($ticketRow['id'] ?? 0),
                'category' => (string) ($ticketRow['category'] ?? ''),
                'subject' => (string) ($ticketRow['subject'] ?? ''),
                'status' => (string) ($ticketRow['status'] ?? ''),
                'createdAt' => (string) ($ticketRow['createdAt'] ?? ''),
                'updatedAt' => (string) ($ticketRow['updatedAt'] ?? ''),
            ];
        }
    } catch (Throwable $ignored) {
        $recentTickets = [];
    }

    // Последние новости/объявления — компактно (заголовки), чтобы бобёр знал
    // об актуальных ивентах/изменениях без отдельного запроса.
    $recentNews = [];
    try {
        $announcementRows = bober_fetch_user_announcement_feed($conn, $userId, ['limit' => 5]);
        foreach ($announcementRows as $announcementRow) {
            $recentNews[] = [
                'title' => (string) ($announcementRow['title'] ?? ''),
                'publishedAt' => (string) ($announcementRow['publishedAt'] ?? ($announcementRow['createdAt'] ?? '')),
                'isRead' => !empty($announcementRow['isRead']),
            ];
        }
    } catch (Throwable $ignored) {
        $recentNews = [];
    }

    return [
        'login' => $login,
        'balance' => $score,
        'plusPerClick' => $plus,
        'energy' => $energy,
        'energyMax' => $energyMax,
        'economyIndex' => (int) ($economyProfile['index'] ?? 0),
        'economyMultiplier' => (float) ($economyProfile['multiplier'] ?? 1.0),
        'upgrades' => $upgradesInfo,
        'ownedSkinCount' => count($ownedSkinIds),
        'ownedSkinIds' => $ownedSkinIds,
        'equippedSkinId' => $equippedSkinId,
        'leaderboard' => $leaderboardPosition,
        'recentSupportTickets' => $recentTickets,
        'recentNews' => $recentNews,
    ];
}

/**
 * Компактизирует контекст в JSON-строку для вставки в системный промпт
 * (экономим токены — плоские поля вместо вложенной "красивой" структуры).
 */
function bober_ai_user_context_to_json($context)
{
    return json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
