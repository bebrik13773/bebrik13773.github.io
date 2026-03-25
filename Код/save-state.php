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
    $upgradeTapSmallCount = max(0, (int) ($upgradePurchases['tapSmall'] ?? 0));
    $upgradeTapBigCount = max(0, (int) ($upgradePurchases['tapBig'] ?? 0));
    $upgradeEnergyCount = max(0, (int) ($upgradePurchases['energy'] ?? 0));

    $conn = bober_db_connect();
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
    $conn->close();

    bober_json_response(['success' => true, 'message' => 'Прогресс сохранен.']);
} catch (Throwable $error) {
    bober_json_response(['success' => false, 'message' => bober_exception_message($error)], 500);
}
