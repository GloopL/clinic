<?php
session_start();
include '../../config/database.php';

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

// Check if record ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: patients.php");
    exit();
}

$record_id = $_GET['id'];
$patient_id = isset($_GET['patient_id']) ? $_GET['patient_id'] : null;

// Get record details to determine type
$stmt = $conn->prepare("SELECT record_type FROM medical_records WHERE id = ?");
$stmt->bind_param("i", $record_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['error_message'] = "Record not found.";
    if ($patient_id) {
        header("Location: view_patient.php?id=" . $patient_id);
    } else {
        header("Location: patients.php");
    }
    exit();
}

$record = $result->fetch_assoc();
$record_type = $record['record_type'];

// Begin transaction
$conn->begin_transaction();

try {
    // Delete specific form data based on record type
    switch ($record_type) {
        case 'history_form':
            $stmt = $conn->prepare("DELETE FROM history_forms WHERE record_id = ?");
            $stmt->bind_param("i", $record_id);
            $stmt->execute();
            break;
        case 'dental_exam':
            $stmt = $conn->prepare("DELETE FROM dental_exams WHERE record_id = ?");
            $stmt->bind_param("i", $record_id);
            $stmt->execute();
            break;
        case 'medical_exam':
            $stmt = $conn->prepare("DELETE FROM medical_exams WHERE record_id = ?");
            $stmt->bind_param("i", $record_id);
            $stmt->execute();
            break;
    }
    
    // Delete the medical record
    $stmt = $conn->prepare("DELETE FROM medical_records WHERE id = ?");
    $stmt->bind_param("i", $record_id);
    $stmt->execute();
    
    // Log the deletion in analytics
    $action = "Deleted " . $record_type . " record";
    $stmt = $conn->prepare("
        INSERT INTO analytics_data (user_id, action, related_id, related_type, timestamp)
        VALUES (?, ?, ?, 'record', NOW())
    ");
    $stmt->bind_param("isi", $_SESSION['user_id'], $action, $record_id);
    $stmt->execute();
    
    // Commit transaction
    $conn->commit();
    
    $_SESSION['success_message'] = "Record deleted successfully.";
} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollback();
    $_SESSION['error_message'] = "Error deleting record: " . $e->getMessage();
}

// Redirect back to patient view or patient list
if ($patient_id) {
    header("Location: view_patient.php?id=" . $patient_id);
} else {
    header("Location: patients.php");
}
exit();
?>