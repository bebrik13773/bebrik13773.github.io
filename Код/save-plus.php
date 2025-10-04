<?php
// Подключение к базе данных
$conn = new mysqli('sql305.infinityfree.com','if0_39950285', 'tmzPxb2Wu5aj6Lb', 'if0_39950285_base');

// Получение данных из запроса
$data = json_decode(file_get_contents("php://input"));
$userId = $data->userId;
$plus = $data->plus;

// Обновление значения plus для пользователя
$stmt = $conn->prepare("UPDATE users SET plus = ? WHERE id = ?");
$stmt->bind_param("ii", $plus, $userId);
$stmt->execute();

// Ответ клиенту
echo json_encode(['message' => 'Счет сохранен.']);
?>
