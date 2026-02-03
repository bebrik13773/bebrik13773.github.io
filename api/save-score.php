<?php
// Подключение к базе данных
$conn = new mysqli('sql305.infinityfree.com','if0_39950285', 'tmzPxb2Wu5aj6Lb', 'if0_39950285_base');

// Получение данных из запроса
$data = json_decode(file_get_contents("php://input"));
$userId = $data->userId;
$score = $data->score;
$skin = isset($data->skin) ? $data->skin : null;

// Обновление счета и скина пользователя
if ($skin !== null) {
    $stmt = $conn->prepare("UPDATE users SET score = ?, skin = ? WHERE id = ?");
    $stmt->bind_param("isi", $score, $skin, $userId);
} else {
    $stmt = $conn->prepare("UPDATE users SET score = ? WHERE id = ?");
    $stmt->bind_param("ii", $score, $userId);
}
$stmt->execute();

// Ответ клиенту
echo json_encode(['message' => 'Счет сохранен.']);
?>
