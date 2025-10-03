<?php
$conn = new mysqli('sql305.infinityfree.com','if0_39950285', 'tmzPxb2Wu5aj6Lb', 'if0_39950285_base');

$data = json_decode(file_get_contents("php://input"));
$userId = $data->userId;
$ban = 1

$stmt = $conn->prepare("UPDATE users SET ban = ? WHERE id = ?");
$stmt->bind_param("ii", $ban, $userId);
$stmt->execute();
echo json_encode(['message' => '‘Забанено']);
?>
