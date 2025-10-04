<?php
$conn = new mysqli('sql305.infinityfree.com','if0_39950285', 'tmzPxb2Wu5aj6Lb', 'if0_39950285_base');

$data = json_decode(file_get_contents("php://input"));
$userId = $data->userId;
$skin = isset($data->skin) ? $data->skin : null;

// Приведение к строке для гарантии
if (!is_string($skin)) {
    $skin = json_encode($skin);
}

$stmt = $conn->prepare("UPDATE users SET skin = ? WHERE id = ?");
$stmt->bind_param("si", $skin, $userId);
$stmt->execute();
echo json_encode(['message' => 'Скин сохранён.']);
?>
