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
    bober_ensure_game_schema($conn);

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

    $stmt = $conn->prepare('INSERT INTO users (login, password, plus, skin, energy, last_energy_update, ENERGY_MAX, score) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    if (!$stmt) {
        throw new RuntimeException('Ошибка подготовки запроса.');
    }

    $stmt->bind_param('ssisisii', $login, $passwordHash, $plus, $defaultSkin, $energy, $lastEnergyUpdate, $energyMax, $score);

    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Ошибка выполнения запроса.');
    }

    $stmt->close();
    $conn->close();

    bober_json_response(['success' => true, 'message' => 'Регистрация успешна!']);
} catch (Throwable $error) {
    bober_json_response(['success' => false, 'message' => 'Ошибка сервера.'], 500);
}
