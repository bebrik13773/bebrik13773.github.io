<?php

require_once __DIR__ . '/db.php';

try {
    $data = bober_read_json_request();

    if (!is_array($data)) {
        bober_json_response(['success' => false, 'message' => 'Некорректный JSON.'], 400);
    }

    $login = trim((string) ($data['login'] ?? ''));
    $password = (string) ($data['password'] ?? '');

    if ($login === '' || $password === '') {
        bober_json_response(['success' => false, 'message' => 'Введите логин и пароль.'], 400);
    }

    $login = bober_require_game_login($login);

    $conn = bober_db_connect();
    bober_ensure_gameplay_schema($conn);

    $activeIpBan = bober_fetch_active_ip_ban($conn);
    if ($activeIpBan !== null) {
        $conn->close();
        bober_json_response([
            'success' => false,
            'message' => $activeIpBan['message'],
            'ipBan' => $activeIpBan,
        ], 403);
    }

    $checkStmt = $conn->prepare('SELECT id FROM users WHERE login = ? LIMIT 1');
    if (!$checkStmt) {
        throw new RuntimeException('Ошибка подготовки запроса.');
    }

    $checkStmt->bind_param('s', $login);
    if (!$checkStmt->execute()) {
        $checkStmt->close();
        throw new RuntimeException('Ошибка выполнения запроса.');
    }

    $checkStmt->store_result();
    if ($checkStmt->num_rows > 0) {
        $checkStmt->close();
        $conn->close();
        bober_json_response(['success' => false, 'message' => 'Такой логин уже существует.']);
    }
    $checkStmt->close();

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $defaultSkin = bober_default_skin_json();
    $lastEnergyUpdate = (string) round(microtime(true) * 1000);
    $energy = 5000;
    $energyMax = 5000;
    $score = 0;
    $plus = 1;
    $upgradeTapSmallCount = 0;
    $upgradeTapBigCount = 0;
    $upgradeEnergyCount = 0;

    $stmt = $conn->prepare('INSERT INTO users (login, password, plus, skin, energy, last_energy_update, ENERGY_MAX, score, upgrade_tap_small_count, upgrade_tap_big_count, upgrade_energy_count) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    if (!$stmt) {
        throw new RuntimeException('Ошибка подготовки запроса.');
    }

    $stmt->bind_param('ssisisiiiii', $login, $passwordHash, $plus, $defaultSkin, $energy, $lastEnergyUpdate, $energyMax, $score, $upgradeTapSmallCount, $upgradeTapBigCount, $upgradeEnergyCount);

    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Ошибка выполнения запроса.');
    }

    $newUserId = (int) $stmt->insert_id;
    $stmt->close();
    $sessionInfo = bober_login_user($newUserId, $login);
    if (!empty($sessionInfo['previousSessionHash']) && !empty($sessionInfo['currentSessionHash']) && $sessionInfo['previousSessionHash'] !== $sessionInfo['currentSessionHash']) {
        bober_revoke_game_session_by_hash($conn, (string) $sessionInfo['previousSessionHash'], 'session_rotated_after_register');
    }
    bober_sync_current_game_session($conn, $newUserId, $login, [
        'source' => 'register',
    ]);
    bober_record_user_ip($conn, $newUserId);
    $flyBeaver = bober_ensure_fly_progress_row($conn, $newUserId);
    $conn->close();

    bober_json_response([
        'success' => true,
        'message' => 'Регистрация успешна! Вход выполнен автоматически.',
        'userId' => $newUserId,
        'login' => $login,
        'plus' => $plus,
        'skin' => $defaultSkin,
        'energy' => $energy,
        'lastEnergyUpdate' => (int) $lastEnergyUpdate,
        'ENERGY_MAX' => $energyMax,
        'score' => $score,
        'upgradePurchases' => [
            'tapSmall' => $upgradeTapSmallCount,
            'tapBig' => $upgradeTapBigCount,
            'energy' => $upgradeEnergyCount,
        ],
        'flyBeaver' => $flyBeaver,
    ]);
} catch (Throwable $error) {
    $statusCode = $error instanceof InvalidArgumentException ? 400 : 500;
    bober_json_response(['success' => false, 'message' => bober_exception_message($error)], $statusCode);
}
