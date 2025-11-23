<?php
// Подключение к базе данных
$conn = new mysqli('sql305.infinityfree.com','if0_39950285', 'tmzPxb2Wu5aj6Lb', 'if0_39950285_base');

// Получение данных из запроса
$data = json_decode(file_get_contents("php://input"));
$userId = $data->userId;
$energy = $data->energy;
$lastEnergyUpdate = isset($data->lastEnergyUpdate) ? $data->lastEnergyUpdate : null;
$ENERGY_MAX = $data->ENERGY_MAX;

// Обновление энергии пользователя
$stmt = $conn->prepare("UPDATE users SET ENERGY_MAX = ?, energy = ?, last_energy_update = ?, WHERE id = ?");
$stmt->bind_param("iiii", $ENERGY_MAX, $energy, $lastEnergyUpdate, $userId);
$stmt->execute();

// Ответ клиенту
echo json_encode(['message' => 'Энергия сохранена.']);
?>
