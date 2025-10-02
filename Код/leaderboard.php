<?php
$conn = new mysqli('sql305.infinityfree.com','if0_39950285', 'tmzPxb2Wu5aj6Lb', 'if0_39950285_base');
$result = $conn->query("SELECT login, MAX(score) as score FROM users GROUP BY login ORDER BY score DESC LIMIT 1");
$leaders = [];
while ($row = $result->fetch_assoc()) {
    $leaders[] = $row;
}
echo json_encode($leaders);
$conn->close();
?>
