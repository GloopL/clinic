<?php
include 'config/database.php';
$query = $conn->query('SELECT student_id FROM patients LIMIT 5');
while ($row = $query->fetch_assoc()) {
    echo $row['student_id'] . "\n";
}
$conn->close();
?>
