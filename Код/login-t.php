<?php
$conn = new mysqli('sql305.infinityfree.com','if0_39950285', 'tmzPxb2Wu5aj6Lb', 'if0_39950285_base');
$data = json_decode(file_get_contents("php://input"));
$login = $data->login;
$password = $data->password;

$stmt = $conn->prepare("SELECT id, password, plus, skin, score FROM users WHERE login = ?");
$stmt->bind_param("s", $login);
$stmt->execute();
$stmt->store_result();
$stmt->bind_result($id, $hashedPassword, $score, $plus, $skin);
$stmt->fetch();

if (password_verify($password, $hashedPassword)) {
	echo json_encode(['success' => true, 'userId' => $id, 'score' => $score, 'plus' => $plus, 'skin' => $skin]);
} else {
    echo json_encode(['success' => false, 'message' => 'Неверный логин или пароль.']);
}
?>
