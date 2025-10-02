<?php
$conn = new mysqli('sql305.infinityfree.com','if0_39950285', 'tmzPxb2Wu5aj6Lb', 'if0_39950285_base');

$data = json_decode(file_get_contents("php://input"));
$userId = $data->userId;
$score = $data->score;

$stmt = $conn->prepare("UPDATE users SET score = ? WHERE id = ?");
$stmt->bind_param("ii", $score, $userId);
$stmt->execute();
echo json_encode(['message' => 'Счет сохранен.']);
?>
