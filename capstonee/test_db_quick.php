<?php
include 'config/database.php';
echo 'DB: ' . ($conn ? 'OK' : 'FAIL') . PHP_EOL;
$q = $conn->query('SELECT COUNT(*) as c FROM patients');
echo 'Patients: ' . $q->fetch_assoc()['c'] . PHP_EOL;
$check = $conn->query('SHOW TABLES LIKE \'user_details\'');
echo 'user_details table: ' . ($check->num_rows > 0 ? 'EXISTS' : 'NOT EXISTS') . PHP_EOL;
if ($check->num_rows > 0) {
    $q = $conn->query('SELECT COUNT(*) as c FROM user_details');
    echo 'user_details records: ' . $q->fetch_assoc()['c'] . PHP_EOL;
}
$conn->close();
?>
