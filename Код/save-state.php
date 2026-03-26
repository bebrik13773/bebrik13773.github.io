<?php

require_once __DIR__ . '/db.php';

try {
    $data = bober_read_json_request();

    if (!is_array($data)) {
        bober_json_response(['success' => false, 'message' => 'Некорректный JSON.'], 400);
    }

    $sessionUserId = bober_get_logged_in_user_id();
    $requestUserId = max(0, (int) ($data['userId'] ?? 0));

    if ($sessionUserId === null) {
        bober_json_response(['success' => false, 'message' => 'Сессия не найдена. Войдите в аккаунт заново.'], 401);
    }

    if ($requestUserId > 0 && $requestUserId !== $sessionUserId) {
        bober_json_response(['success' => false, 'message' => 'Неверный идентификатор пользователя для активной сессии.'], 403);
    }

    $userId = $sessionUserId;

    $score = max(0, (int) ($data['score'] ?? 0));
    $plus = max(1, (int) ($data['plus'] ?? 1));
    $skin = bober_normalize_skin_json($data['skin'] ?? null);
    $energyMax = max(1, (int) ($data['ENERGY_MAX'] ?? 5000));
    $energy = min($energyMax, max(0, (int) ($data['energy'] ?? 0)));
    $lastEnergyUpdate = (string) max(0, (int) ($data['lastEnergyUpdate'] ?? 0));
    $upgradePurchases = is_array($data['upgradePurchases'] ?? null) ? $data['upgradePurchases'] : [];
    $clientLogBatch = is_array($data['clientLogBatch'] ?? null) ? $data['clientLogBatch'] : null;
    $upgradeTapSmallCount = max(0, (int) ($upgradePurchases['tapSmall'] ?? 0));
    $upgradeTapBigCount = max(0, (int) ($upgradePurchases['tapBig'] ?? 0));
    $upgradeEnergyCount = max(0, (int) ($upgradePurchases['energy'] ?? 0));

    $conn = bober_db_connect();
    bober_enforce_runtime_access_rules($conn, $userId);

    $currentStmt = $conn->prepare('SELECT score, plus, skin, ENERGY_MAX, upgrade_tap_small_count, upgrade_tap_big_count, upgrade_energy_count FROM users WHERE id = ? LIMIT 1');
    if (!$currentStmt) {
        throw new RuntimeException('Ошибка подготовки чтения текущего состояния.');
    }

    $currentStmt->bind_param('i', $userId);
    if (!$currentStmt->execute()) {
        $currentStmt->close();
        throw new RuntimeException('Ошибка чтения текущего состояния.');
    }

    $currentResult = $currentStmt->get_result();
    $currentRow = $currentResult ? $currentResult->fetch_assoc() : null;
    if ($currentResult) {
        $currentResult->free();
    }
    $currentStmt->close();

    $stmt = $conn->prepare('UPDATE users SET score = ?, plus = ?, skin = ?, energy = ?, last_energy_update = ?, ENERGY_MAX = ?, upgrade_tap_small_count = ?, upgrade_tap_big_count = ?, upgrade_energy_count = ? WHERE id = ?');
    if (!$stmt) {
        throw new RuntimeException('Ошибка подготовки запроса.');
    }

    $stmt->bind_param('iisisiiiii', $score, $plus, $skin, $energy, $lastEnergyUpdate, $energyMax, $upgradeTapSmallCount, $upgradeTapBigCount, $upgradeEnergyCount, $userId);

    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Ошибка выполнения запроса.');
    }

    $stmt->close();

    if (is_array($currentRow)) {
        $previousScore = max(0, (int) ($currentRow['score'] ?? 0));
        $previousPlus = max(1, (int) ($currentRow['plus'] ?? 1));
        $previousEnergyMax = max(1, (int) ($currentRow['ENERGY_MAX'] ?? 5000));
        $previousTapSmall = max(0, (int) ($currentRow['upgrade_tap_small_count'] ?? 0));
        $previousTapBig = max(0, (int) ($currentRow['upgrade_tap_big_count'] ?? 0));
        $previousEnergyPurchases = max(0, (int) ($currentRow['upgrade_energy_count'] ?? 0));
        $previousSkinState = bober_decode_skin_state((string) ($currentRow['skin'] ?? ''));
        $nextSkinState = bober_decode_skin_state($skin);

        if ($upgradeTapSmallCount > $previousTapSmall) {
            bober_log_user_activity($conn, $userId, 'upgrade_tap_small_purchase', [
                'action_group' => 'progress',
                'source' => 'save_state',
                'login' => $_SESSION['game_login'] ?? '',
                'description' => 'Куплено улучшение +1 к тапу.',
                'score_delta' => $score - $previousScore,
                'coins_delta' => $score - $previousScore,
                'meta' => [
                    'previous_count' => $previousTapSmall,
                    'next_count' => $upgradeTapSmallCount,
                    'previous_plus' => $previousPlus,
                    'next_plus' => $plus,
                ],
            ]);
        }

        if ($upgradeTapBigCount > $previousTapBig) {
            bober_log_user_activity($conn, $userId, 'upgrade_tap_big_purchase', [
                'action_group' => 'progress',
                'source' => 'save_state',
                'login' => $_SESSION['game_login'] ?? '',
                'description' => 'Куплено улучшение +5 к тапу.',
                'score_delta' => $score - $previousScore,
                'coins_delta' => $score - $previousScore,
                'meta' => [
                    'previous_count' => $previousTapBig,
                    'next_count' => $upgradeTapBigCount,
                    'previous_plus' => $previousPlus,
                    'next_plus' => $plus,
                ],
            ]);
        }

        if ($upgradeEnergyCount > $previousEnergyPurchases || $energyMax > $previousEnergyMax) {
            bober_log_user_activity($conn, $userId, 'upgrade_energy_purchase', [
                'action_group' => 'progress',
                'source' => 'save_state',
                'login' => $_SESSION['game_login'] ?? '',
                'description' => 'Куплено улучшение запаса энергии.',
                'score_delta' => $score - $previousScore,
                'coins_delta' => $score - $previousScore,
                'meta' => [
                    'previous_count' => $previousEnergyPurchases,
                    'next_count' => $upgradeEnergyCount,
                    'previous_energy_max' => $previousEnergyMax,
                    'next_energy_max' => $energyMax,
                ],
            ]);
        }

        if (($previousSkinState['equippedSkinId'] ?? '') !== ($nextSkinState['equippedSkinId'] ?? '')) {
            bober_log_user_activity($conn, $userId, 'equip_skin', [
                'action_group' => 'skins',
                'source' => 'save_state',
                'login' => $_SESSION['game_login'] ?? '',
                'description' => 'Игрок сменил активный скин.',
                'meta' => [
                    'previous_skin_id' => $previousSkinState['equippedSkinId'] ?? '',
                    'next_skin_id' => $nextSkinState['equippedSkinId'] ?? '',
                ],
            ]);
        }

        $previousOwned = array_values(array_diff((array) ($previousSkinState['ownedSkinIds'] ?? []), (array) ($nextSkinState['ownedSkinIds'] ?? [])));
        $newOwned = array_values(array_diff((array) ($nextSkinState['ownedSkinIds'] ?? []), (array) ($previousSkinState['ownedSkinIds'] ?? [])));
        if (!empty($newOwned)) {
            bober_log_user_activity($conn, $userId, 'unlock_skin', [
                'action_group' => 'skins',
                'source' => 'save_state',
                'login' => $_SESSION['game_login'] ?? '',
                'description' => 'Игрок получил новый скин.',
                'score_delta' => $score - $previousScore,
                'coins_delta' => $score - $previousScore,
                'meta' => [
                    'unlocked_skin_ids' => array_values($newOwned),
                    'removed_skin_ids' => array_values($previousOwned),
                ],
            ]);
        }
    }

    $clientLogResult = null;
    if (is_array($clientLogBatch)) {
        try {
            $clientLogResult = bober_store_client_log_batch($conn, $userId, $clientLogBatch, [
                'login' => $_SESSION['game_login'] ?? '',
            ]);
        } catch (Throwable $logError) {
            $clientLogResult = [
                'received' => 0,
                'accepted' => 0,
                'inserted' => 0,
                'duplicates' => 0,
                'warning' => bober_exception_message($logError),
            ];
        }
    }

    $conn->close();

    bober_json_response([
        'success' => true,
        'message' => 'Прогресс сохранен.',
        'clientLog' => $clientLogResult,
    ]);
} catch (Throwable $error) {
    bober_json_response(['success' => false, 'message' => bober_exception_message($error)], 500);
}
