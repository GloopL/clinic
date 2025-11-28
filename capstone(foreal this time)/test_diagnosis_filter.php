<?php
session_start();
include 'config/database.php';

// Test function to simulate diagnosis filtering
function test_diagnosis_filter($role, $patient_id = 1) {
    echo "Testing role: $role\n";

    // Simulate session role
    $_SESSION['role'] = $role;

    // Determine which diagnosis types to show based on current user role
    $allowed_diagnosis_types = [];

    if ($_SESSION['role'] === 'nurse') {
        $allowed_diagnosis_types = ['nurse'];
    } elseif ($_SESSION['role'] === 'dentist') {
        $allowed_diagnosis_types = ['dentist'];
    } elseif ($_SESSION['role'] === 'doctor' || $_SESSION['role'] === 'physician') {
        $allowed_diagnosis_types = ['doctor'];
    } elseif ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'staff') {
        // Admin/staff can see all diagnosis types
        $allowed_diagnosis_types = ['nurse', 'dentist', 'doctor'];
    }

    echo "Allowed diagnosis types: " . implode(', ', $allowed_diagnosis_types) . "\n";

    if (!empty($allowed_diagnosis_types)) {
        $placeholders = str_repeat('?,', count($allowed_diagnosis_types) - 1) . '?';
        $stmt = $conn->prepare("
            SELECT md.*, mr.record_type, p.first_name, p.last_name, p.student_id
            FROM medical_diagnoses md
            JOIN medical_records mr ON md.record_id = mr.id
            JOIN patients p ON md.patient_id = p.id
            WHERE md.patient_id = ? AND md.diagnosis_type IN ($placeholders)
            ORDER BY md.diagnosis_date DESC, md.created_at DESC
        ");

        $types = "i" . str_repeat('s', count($allowed_diagnosis_types));
        $params = array_merge([$patient_id], $allowed_diagnosis_types);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $diagnoses = $result->fetch_all(MYSQLI_ASSOC);

        echo "Found " . count($diagnoses) . " diagnoses\n";

        // Show diagnosis types found
        $types_found = array_unique(array_column($diagnoses, 'diagnosis_type'));
        echo "Diagnosis types in results: " . implode(', ', $types_found) . "\n\n";

        $stmt->close();
    } else {
        echo "No allowed diagnosis types for this role\n\n";
    }
}

// Test different roles
test_diagnosis_filter('nurse');
test_diagnosis_filter('dentist');
test_diagnosis_filter('doctor');
test_diagnosis_filter('admin');

$conn->close();
?>
