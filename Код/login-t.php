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

    $conn = bober_db_connect();
    bober_ensure_gameplay_schema($conn);

    $stmt = $conn->prepare('SELECT id, password, plus, skin, energy, last_energy_update, ENERGY_MAX, score FROM users WHERE login = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Ошибка подготовки запроса.');
    }

    $stmt->bind_param('s', $login);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Ошибка выполнения запроса.');
    }

    $stmt->store_result();

    if ($stmt->num_rows !== 1) {
        $stmt->close();
        $conn->close();
        bober_json_response(['success' => false, 'message' => 'Неверный логин или пароль.']);
    }

    $stmt->bind_result($id, $hashedPassword, $plus, $skin, $energy, $lastEnergyUpdate, $energyMax, $score);
    $stmt->fetch();
    $stmt->close();

    if (!is_string($hashedPassword) || $hashedPassword === '' || !password_verify($password, $hashedPassword)) {
        $conn->close();
        bober_json_response(['success' => false, 'message' => 'Неверный логин или пароль.']);
    }

    $activeBan = bober_fetch_active_user_ban($conn, (int) $id);
    if ($activeBan !== null) {
        bober_propagate_user_ban_to_ip_bans($conn, (int) $id, $activeBan, [
            'include_current_ip' => false,
        ]);
        $conn->close();
        bober_json_response([
            'success' => false,
            'message' => $activeBan['message'],
            'ban' => $activeBan,
        ], 403);
    }

    bober_login_user($id, $login);
    bober_record_user_ip($conn, (int) $id);

    $normalizedSkin = bober_normalize_skin_json($skin);
    if ($normalizedSkin !== $skin) {
        $updateStmt = $conn->prepare('UPDATE users SET skin = ? WHERE id = ?');
        if ($updateStmt) {
            $userId = (int) $id;
            $updateStmt->bind_param('si', $normalizedSkin, $userId);
            $updateStmt->execute();
            $updateStmt->close();
        }
    }

    $energyMax = max(1, (int) $energyMax);
    $energy = max(0, min($energyMax, (int) $energy));
    $flyBeaver = bober_fetch_fly_beaver_progress($conn, (int) $id);

    $response = [
        'success' => true,
        'userId' => (int) $id,
        'login' => $login,
        'plus' => max(1, (int) $plus),
        'skin' => $normalizedSkin,
        'energy' => $energy,
        'lastEnergyUpdate' => max(0, (int) $lastEnergyUpdate),
        'ENERGY_MAX' => $energyMax,
        'score' => max(0, (int) $score),
        'flyBeaver' => $flyBeaver,
    ];

    $conn->close();
    bober_json_response($response);
} catch (Throwable $error) {
    bober_json_response(['success' => false, 'message' => bober_exception_message($error)], 500);
}
