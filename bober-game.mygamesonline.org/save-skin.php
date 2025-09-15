<?php
$conn = new mysqli('fdb1029.awardspace.net','4567560_db', 'Bober1host', '4567560_db');

$data = json_decode(file_get_contents("php://input"));
$userId = $data->userId;
$skin = $data->skin;

$stmt = $conn->prepare("UPDATE users SET skin = ? WHERE id = ?");
$stmt->bind_param("ii", $skin, $userId);
$stmt->execute();
echo json_encode(['message' => '‘чет сохранен.']);
?>
