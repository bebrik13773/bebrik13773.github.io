<?php
header('Content-Type: application/json; charset=utf-8');

// Подключение к базе данных
$conn = new mysqli('sql305.infinityfree.com','if0_39950285', 'tmzPxb2Wu5aj6Lb', 'if0_39950285_base');
if ($conn->connect_error) {
	http_response_code(500);
	echo json_encode(['error' => 'DB connection failed', 'details' => $conn->connect_error]);
	exit;
}

// Получение данных из запроса
$raw = file_get_contents("php://input");
$data = json_decode($raw);
if (!$data) {
	http_response_code(400);
	echo json_encode(['error' => 'Invalid JSON']);
	$conn->close();
	exit;
}

$userId = isset($data->userId) ? (int)$data->userId : null;
$energy = isset($data->energy) ? (int)$data->energy : null;
$lastEnergyUpdate = isset($data->lastEnergyUpdate) ? $data->lastEnergyUpdate : null; // expected string or null
$ENERGY_MAX = isset($data->ENERGY_MAX) ? (int)$data->ENERGY_MAX : null;

if ($userId === null) {
	http_response_code(400);
	echo json_encode(['error' => 'Missing userId']);
	$conn->close();
	exit;
}

// Подготовка запроса — убрана лишняя запятая до WHERE
$stmt = $conn->prepare("UPDATE users SET ENERGY_MAX = ?, energy = ?, last_energy_update = ? WHERE id = ?");
if (!$stmt) {
	http_response_code(500);
	echo json_encode(['error' => 'Prepare failed', 'details' => $conn->error]);
	$conn->close();
	exit;
}

// Типы параметров: i (int), i (int), s (string/null), i (int)
$stmt->bind_param("iisi", $ENERGY_MAX, $energy, $lastEnergyUpdate, $userId);
$ok = $stmt->execute();
if (!$ok) {
	http_response_code(500);
	echo json_encode(['error' => 'Execute failed', 'details' => $stmt->error]);
	$stmt->close();
	$conn->close();
	exit;
}

$stmt->close();
$conn->close();

echo json_encode(['message' => 'Энергия сохранена.']);
?>
