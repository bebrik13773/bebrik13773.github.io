<?php
$conn = new mysqli('sql305.infinityfree.com','if0_39950285', 'tmzPxb2Wu5aj6Lb', 'if0_39950285_base');

$data = json_decode(file_get_contents("php://input"));
$login = $data->login;
$password = password_hash($data->password, PASSWORD_DEFAULT);

$stmt = $conn->prepare("INSERT INTO users (login, password) VALUES (?, ?)");
$stmt->bind_param("ss", $login, $password);
if ($stmt->execute()) {
    echo json_encode(['message' => 'Регистрация успешна!']);
} else {
    echo json_encode(['message' => 'Ошибка регистрации.']);
}
?>
