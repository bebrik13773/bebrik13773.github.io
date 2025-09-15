<?php
$conn = new mysqli('fdb1029.awardspace.net','4567560_db', 'Bopka_01', '4567560_db');

$data = json_decode(file_get_contents("php://input"));
$userId = $data->userId;
$score = $data->score;

$stmt = $conn->prepare("UPDATE users SET score = ? WHERE id = ?");
$stmt->bind_param("ii", $score, $userId);
$stmt->execute();
echo json_encode(['message' => 'Счет сохранен.']);
?>
