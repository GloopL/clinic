<?php
include 'config/database.php';
echo 'Database connection successful\n';

// Test patient info retrieval
$test_student_id = '2021001'; // Assuming this exists
$query = $conn->prepare('SELECT * FROM patients WHERE student_id = ?');
$query->bind_param('s', $test_student_id);
$query->execute();
$result = $query->get_result();
if ($result->num_rows > 0) {
    $patient = $result->fetch_assoc();
    echo 'Patient found: ' . $patient['first_name'] . ' ' . $patient['last_name'] . '\n';
    echo 'Patient ID: ' . $patient['id'] . '\n';
} else {
    echo 'No patient found with student_id: ' . $test_student_id . '\n';
}
$query->close();

// Test medical records retrieval
if (isset($patient)) {
    $record_query = $conn->prepare('SELECT COUNT(*) as count FROM medical_records WHERE patient_id = ?');
    $record_query->bind_param('i', $patient['id']);
    $record_query->execute();
    $record_result = $record_query->get_result();
    $count = $record_result->fetch_assoc()['count'];
    echo 'Medical records count for patient: ' . $count . '\n';
    $record_query->close();
}

$conn->close();
?>
