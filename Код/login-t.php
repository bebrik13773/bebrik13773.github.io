<?php
$conn = new mysqli('sql305.infinityfree.com','if0_39950285', 'tmzPxb2Wu5aj6Lb', 'if0_39950285_base');
$data = json_decode(file_get_contents("php://input"));
$login = $data->login;
$password = $data->password;

$stmt = $conn->prepare("SELECT id, password, plus, skin, energy, last_energy_update, ENERGY_MAX, score FROM users WHERE login = ?");
$stmt->bind_param("s", $login);
$stmt->execute();
$stmt->store_result();
$stmt->bind_result($id, $hashedPassword, $plus, $skin, $energy, $lastEnergyUpdate, $ENERGY_MAX, $score);
$stmt->fetch();

if (password_verify($password, $hashedPassword)) {
	echo json_encode([
		'success' => true,
		'userId' => $id,
		'plus' => $plus,
		'skin' => $skin,
		'energy' => $energy,
		'lastEnergyUpdate' => $lastEnergyUpdate,
		'ENERGY_MAX' => $ENERGY_MAX,
		'score' => $score,
	]);
} else {
	echo json_encode(['success' => false, 'message' => 'Неверный логин или пароль.']);
}
?>
