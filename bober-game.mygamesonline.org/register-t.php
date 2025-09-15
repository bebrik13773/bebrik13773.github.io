<?php
$conn = new mysqli('fdb1029.awardspace.net','4567560_db', 'Bopka_01', '4567560_db');

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
