<?php
include '../../config/database.php';
// Get the 5 most recent verifications
$verifications = [];
$query = "
    SELECT v.*, p.first_name, p.last_name, p.student_id
    FROM medical_records v
    JOIN patients p ON v.patient_id = p.id
    ORDER BY v.examination_date DESC
    LIMIT 5
";
$result = $conn->query($query);
while ($row = $result->fetch_assoc()) {
    $verifications[] = $row;
}
echo json_encode($verifications);
