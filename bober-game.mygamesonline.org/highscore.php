<?php
$conn = new mysqli('fdb1029.awardspace.net','4567560_db', 'Bopka_01', '4567560_db');

$result = $conn->query("SELECT login, score FROM users ORDER BY score DESC LIMIT 1");
$highscore = $result->fetch_assoc();
echo json_encode($highscore);
?>
