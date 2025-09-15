<?php
$conn = new mysqli('fdb1029.awardspace.net','4567560_db', 'Bopka_01', '4567560_db');

$data = json_decode(file_get_contents("php://input"));
$userId = $data->userId;
$plus = $data->plus;

$stmt = $conn->prepare("UPDATE users SET plus = ? WHERE id = ?");
$stmt->bind_param("ii", $plus, $userId);
$stmt->execute();
echo json_encode(['message' => 'Счет сохранен.']);
?>
