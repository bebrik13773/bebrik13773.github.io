<?php
$conn = new mysqli('fdb1029.awardspace.net','4567560_db', 'Bopka_01', '4567560_db');
$result = $conn->query("SELECT login, MAX(score) as score FROM users GROUP BY login ORDER BY score DESC LIMIT 1");
$leaders = [];
while ($row = $result->fetch_assoc()) {
    $leaders[] = $row;
}
echo json_encode($leaders);
$conn->close();
?>
