<?php
include 'config/database.php';

// Test with an existing student_id
$test_student_id = '12323'; // From the output
$query = $conn->prepare('SELECT * FROM patients WHERE student_id = ?');
$query->bind_param('s', $test_student_id);
$query->execute();
$result = $query->get_result();
if ($result->num_rows > 0) {
    $patient = $result->fetch_assoc();
    echo 'Patient found: ' . $patient['first_name'] . ' ' . $patient['last_name'] . "\n";
    echo 'Patient ID: ' . $patient['id'] . "\n";

    // Test medical records retrieval
    $record_query = $conn->prepare('SELECT COUNT(*) as count FROM medical_records WHERE patient_id = ?');
    $record_query->bind_param('i', $patient['id']);
    $record_query->execute();
    $record_result = $record_query->get_result();
    $count = $record_result->fetch_assoc()['count'];
    echo 'Medical records count for patient: ' . $count . "\n";
    $record_query->close();
} else {
    echo 'No patient found with student_id: ' . $test_student_id . "\n";
}
$query->close();

$conn->close();
?>
