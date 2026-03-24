<?php

require_once __DIR__ . '/db.php';

try {
    $data = bober_read_json_request();

    if (!is_array($data)) {
        bober_json_response(['success' => false, 'message' => 'Некорректный JSON.'], 400);
    }

    $userId = max(0, (int) ($data['userId'] ?? 0));
    $energy = max(0, (int) ($data['energy'] ?? 0));
    $energyMax = max(1, (int) ($data['ENERGY_MAX'] ?? 5000));
    $lastEnergyUpdate = (string) max(0, (int) ($data['lastEnergyUpdate'] ?? 0));

    if ($userId < 1) {
        bober_json_response(['success' => false, 'message' => 'Некорректный идентификатор пользователя.'], 400);
    }

    $energy = min($energy, $energyMax);

    $conn = bober_db_connect();
    bober_ensure_game_schema($conn);

    $stmt = $conn->prepare('UPDATE users SET ENERGY_MAX = ?, energy = ?, last_energy_update = ? WHERE id = ?');
    if (!$stmt) {
        throw new RuntimeException('Ошибка подготовки запроса.');
    }

    $stmt->bind_param('iisi', $energyMax, $energy, $lastEnergyUpdate, $userId);

    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Ошибка выполнения запроса.');
    }

    $stmt->close();
    $conn->close();

    bober_json_response(['success' => true, 'message' => 'Энергия сохранена.']);
} catch (Throwable $error) {
    bober_json_response(['success' => false, 'message' => bober_exception_message($error)], 500);
}
