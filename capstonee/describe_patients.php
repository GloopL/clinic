<?php
include 'config/database.php';
$result = $conn->query('DESCRIBE patients');
while ($row = $result->fetch_assoc()) {
    echo $row['Field'] . ' - ' . $row['Type'] . PHP_EOL;
}
$conn->close();
?>
