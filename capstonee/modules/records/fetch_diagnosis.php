<?php
// fetch_diagnosis.php
session_start();
include '../../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

if (!isset($_GET['record_id']) || !is_numeric($_GET['record_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid record ID']);
    exit();
}

$record_id = $_GET['record_id'];

try {
    // Query to get diagnosis data for the specific record
    $query = "
        SELECT 
            MAX(CASE WHEN diagnosis_type = 'nurse' THEN provider_name END) as nurse_name,
            MAX(CASE WHEN diagnosis_type = 'nurse' THEN nurse_diagnosis_date END) as nurse_diagnosis_date,
            MAX(CASE WHEN diagnosis_type = 'nurse' THEN nurse_note END) as nurse_note,
            MAX(CASE WHEN diagnosis_type = 'doctor' THEN provider_name END) as doctor_name,
            MAX(CASE WHEN diagnosis_type = 'doctor' THEN doctor_diagnosis_date END) as doctor_diagnosis_date,
            MAX(CASE WHEN diagnosis_type = 'doctor' THEN doctor_note END) as doctor_note,
            COUNT(CASE WHEN diagnosis_type = 'nurse' AND (nurse_note IS NOT NULL OR nurse_diagnosis_date IS NOT NULL) THEN 1 END) as has_nurse,
            COUNT(CASE WHEN diagnosis_type = 'doctor' AND (doctor_note IS NOT NULL OR doctor_diagnosis_date IS NOT NULL) THEN 1 END) as has_doctor
        FROM medical_diagnoses 
        WHERE record_id = ?
        GROUP BY record_id
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $record_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $data = $result->fetch_assoc();
        
        echo json_encode([
            'success' => true,
            'has_nurse_data' => !empty($data['has_nurse']),
            'has_doctor_data' => !empty($data['has_doctor']),
            'nurse_name' => $data['nurse_name'] ?? null,
            'nurse_diagnosis_date' => $data['nurse_diagnosis_date'] ? date('M d, Y', strtotime($data['nurse_diagnosis_date'])) : null,
            'nurse_note' => htmlspecialchars($data['nurse_note'] ?? ''),
            'doctor_name' => $data['doctor_name'] ?? null,
            'doctor_diagnosis_date' => $data['doctor_diagnosis_date'] ? date('M d, Y', strtotime($data['doctor_diagnosis_date'])) : null,
            'doctor_note' => htmlspecialchars($data['doctor_note'] ?? '')
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'has_nurse_data' => false,
            'has_doctor_data' => false,
            'message' => 'No diagnosis data found for this record'
        ]);
    }
    
    $stmt->close();
    
} catch (Exception $e) {
    error_log("Diagnosis fetch error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}

$conn->close();
?>