<?php
session_start();
require_once '../../vendor/autoload.php';
include '../../config/database.php';

use setasign\Fpdi\Fpdi;

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

// Only doctors/physicians can generate certified PDFs
$user_role = $_SESSION['role'] ?? '';
if (!in_array($user_role, ['doctor', 'physician', 'admin', 'dentist'])) {
    header("Location: ../../dashboard.php");
    exit();
}

$type = $_GET['type'] ?? '';
$id = $_GET['id'] ?? '';

// Map form types to template files
$template_map = [
    'history_form' => 'history_form.pdf',
    'medical_form' => 'medical_exam.pdf',
    'medical_exam' => 'medical_exam.pdf',
    'dental_form'  => 'dental_form.pdf',
    'dental_exam'  => 'dental_form.pdf'
];

if (!isset($template_map[$type])) {
    die("Invalid form type.");
}

$template_file = $template_map[$type];
$template_path = "../../assets/templates/$template_file";

// Check if template exists
if (!file_exists($template_path)) {
    die("Template file not found: $template_file");
}

// Map form types to database tables
$form_map = [
    'history_form' => ['table' => 'history_forms', 'record_type' => 'history_form'],
    'medical_form' => ['table' => 'medical_exams', 'record_type' => 'medical_exam'],
    'medical_exam' => ['table' => 'medical_exams', 'record_type' => 'medical_exam'],
    'dental_form'  => ['table' => 'dental_exams', 'record_type' => 'dental_exam'],
    'dental_exam'  => ['table' => 'dental_exams', 'record_type' => 'dental_exam']
];

// Fetch record data
$record = null;
if ($type && $id && isset($form_map[$type])) {
    $table = $form_map[$type]['table'];
    
    if ($type === 'medical_form' || $type === 'medical_exam') {
        $query = "
            SELECT me.*, mr.*, p.*, mr.id as record_id
            FROM medical_exams me
            JOIN medical_records mr ON me.record_id = mr.id
            JOIN patients p ON mr.patient_id = p.id
            WHERE me.record_id = ?
        ";
    } else {
        $query = "
            SELECT f.*, mr.*, p.*, mr.id as record_id
            FROM $table f
            JOIN medical_records mr ON f.record_id = mr.id
            JOIN patients p ON mr.patient_id = p.id
            WHERE f.record_id = ?
        ";
    }
    
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $record = $result->fetch_assoc();
        $stmt->close();
    }
}

if (!$record) {
    die("Record not found or insufficient permissions.");
}

// Check if record is certified
if (!isset($record['verification_status']) || 
    ($record['verification_status'] !== 'for_certification' && 
     $record['verification_status'] !== 'certified')) {
    die("This record is not yet certified for printing.");
}

// Function to format date
function formatDate($date, $format = 'm/d/Y') {
    if (empty($date) || $date === '0000-00-00') return '';
    $dateObj = new DateTime($date);
    return $dateObj->format($format);
}

// Function to calculate age
function calculateAge($birthDate) {
    if (empty($birthDate) || $birthDate === '0000-00-00') return '';
    $birth = new DateTime($birthDate);
    $today = new DateTime();
    $age = $today->diff($birth)->y;
    return $age;
}

// Create PDF instance
$pdf = new Fpdi();

// Set document properties
$pdf->SetCreator('BSU Clinic System');
$pdf->SetAuthor('BSU Clinic');
$pdf->SetTitle('Medical Certificate - ' . $record['first_name'] . ' ' . $record['last_name']);
$pdf->SetSubject('Medical Certification');

// Import the template (single page only)
$pageCount = $pdf->setSourceFile($template_path);
$templateId = $pdf->importPage(1);
$pdf->AddPage();
$pdf->useTemplate($templateId, 0, 0, null, null, true);

// Set font to Times New Roman (changed from Arial)
$pdf->SetFont('Times', '', 10);

// Define coordinates for each field based on your template
$field_coordinates = [];

// Update the field_coordinates array for medical form to include all missing fields
if ($type === 'medical_form' || $type === 'medical_exam') {
    // Coordinates for medical_exam.pdf template (single page)
    $field_coordinates = [
        // Patient Information
        'last_name' => ['x' => 45, 'y' => 30],
        'first_name' => ['x' => 45, 'y' => 34],
        'middle_name' => ['x' => 45, 'y' => 39],
        'date_of_birth' => ['x' => 166, 'y' => 34],
        'age' => ['x' => 166, 'y' => 39],
        'sex' => ['x' => 45, 'y' => 43],
        'civil_status' => ['x' => 166, 'y' => 43],
        'address' => ['x' => 45, 'y' => 53],
        'contact_number' => ['x' => 45, 'y' => 47],
        
        // PAST MEDICAL HISTORY
        'past_medical_history' => ['x' => 64, 'y' => 60],
        
        // FAMILY HISTORY
        'family_history' => ['x' => 64, 'y' => 72],
        
        // OCCUPATIONAL HISTORY
        'occupational_history' => ['x' => 64, 'y' => 85],
        
        // PHYSICAL EXAMINATION - REVIEW OF SYSTEM NORMAL FINDINGS (Left Column)
        'general_appearance_findings' => ['x' => 90, 'y' => 107],
        'skin_findings' => ['x' => 90, 'y' => 111],
        'head_scalp_findings' => ['x' => 90, 'y' => 115],
        'eyes_findings' => ['x' => 90, 'y' => 120],
        'ears_findings' => ['x' => 90, 'y' => 124],
        'nose_throat_findings' => ['x' => 90, 'y' => 128],
        'mouth_findings' => ['x' => 90, 'y' => 132],
        'neck_thyroid_ln_findings' => ['x' => 90, 'y' => 137],
        
        // PHYSICAL EXAMINATION - REVIEW OF SYSTEM NORMAL FINDINGS (Right Column)
        'chest_breast_axilla_findings' => ['x' => 185, 'y' => 107],
        'heart_findings' => ['x' => 185, 'y' => 111],
        'lungs_findings' => ['x' => 185, 'y' => 115],
        'abdomen_findings' => ['x' => 185, 'y' => 120],
        'anus_rectum_findings' => ['x' => 185, 'y' => 124],
        'genital_findings' => ['x' => 185, 'y' => 128],
        'musculo_skeletal_findings' => ['x' => 185, 'y' => 132],
        'extremities_findings' => ['x' => 185, 'y' => 137],
        
        // DIAGNOSTIC EXAMINATION - Top Row
        'blood_pressure' => ['x' => 55, 'y' => 146],
        'heart_rate' => ['x' => 58, 'y' => 151],
        'hearing_normal' => ['x' => 50, 'y' => 153],
        'hearing_defective' => ['x' => 80, 'y' => 153],
        'vision_with_glasses_r' => ['x' => 89, 'y' => 160],
        'vision_without_glasses_l' => ['x' => 89, 'y' => 165],
        
        // DIAGNOSTIC EXAMINATION - Second Row
        'chest_xray_pa' => ['x' => 40, 'y' => 170],
        'chest_xray_lordotic' => ['x' => 40, 'y' => 174],
        'chest_xray_findings_text' => ['x' => 89, 'y' => 174],
        'cbc_normal' => ['x' => 40, 'y' => 181],
        'cbc_findings_text' => ['x' => 89, 'y' => 181],
        
        // DIAGNOSTIC EXAMINATION - Third Row
        'urinalysis_normal' => ['x' => 40, 'y' => 190],
        'urinalysis_findings_text' => ['x' => 89, 'y' => 193],
        'stool_normal' => ['x' => 132, 'y' => 149],
        'stool_findings_text' => ['x' => 180, 'y' => 195],
        
        // DIAGNOSTIC EXAMINATION - Fourth Row
        'hepa_b_normal' => ['x' => 132, 'y' => 158],
        'hepa_b_findings_text' => ['x' => 183, 'y' => 158],
        'methamphetamine_negative' => ['x' => 132, 'y' => 172],
        'methamphetamine_positive' => ['x' => 159, 'y' => 172],
        
        // DIAGNOSTIC EXAMINATION - Fifth Row
        'thc_negative' => ['x' => 132, 'y' => 181],
        'thc_positive' => ['x' => 159, 'y' => 181],
        
        // CERTIFICATION SECTION - Patient Info
        'certified_name' => ['x' => 30, 'y' => 218],
        'certified_weight' => ['x' => 33, 'y' => 228],
        'certified_height' => ['x' => 33, 'y' => 233],
        'certified_civil_status' => ['x' => 33, 'y' => 238],
        'certified_exam_date' => ['x' => 45, 'y' => 243],
        
        // School/Company/Institution (This might need to be set to "BATANGAS STATE UNIVERSITY")
        'school_company_institution' => ['x' => 36, 'y' => 212],
        
        // CLASSIFICATION checkboxes
        'classification_a' => ['x' => 111, 'y' => 216],
        'classification_b' => ['x' => 111, 'y' => 221],
        'classification_c' => ['x' => 111, 'y' => 231],
        'classification_d' => ['x' => 111, 'y' => 278],
        
        // Needs treatment checkboxes - First Column
        'needs_treatment_skin' => ['x' => 114, 'y' => 258],
        'needs_treatment_dental' => ['x' => 114, 'y' => 263],
        'needs_treatment_anemia' => ['x' => 114, 'y' => 267],
        'needs_treatment_vision' => ['x' => 114, 'y' => 272],
        
        // Needs treatment checkboxes - Second Column
        'needs_treatment_uti' => ['x' => 146, 'y' => 259],
        'needs_treatment_parasitism' => ['x' => 146, 'y' => 263],
        'needs_treatment_hypertension' => ['x' => 146, 'y' => 267],
        'needs_treatment_others_check' => ['x' => 146, 'y' => 272],
        
        // Physician Information
        'physician_name' => ['x' => 124, 'y' => 289],
        'license_no' => ['x' => 157, 'y' => 295],
        'physician_date' => ['x' => 150, 'y' => 302],
    ];

} elseif ($type === 'history_form') {
    // Coordinates for history_form.pdf template (single page)
    $field_coordinates = [
        // Patient Information - Top Section
        'name' => ['x' => 45, 'y' => 48],
        'program' => ['x' => 45, 'y' => 57],
        'date_of_birth' => ['x' => 45, 'y' => 66],
        'age' => ['x' => 165, 'y' => 66],
        'sex' => ['x' => 165, 'y' => 57],
        'sports_event' => ['x' => 45, 'y' => 74],
        
        // Physical Examination - FINDINGS (Left Column)
        'height_findings' => ['x' => 78, 'y' => 90],
        'weight_findings' => ['x' => 78, 'y' => 95],
        'bp_findings' => ['x' => 78, 'y' => 100],
        'pulse_findings' => ['x' => 78, 'y' => 105],
        'vision_findings' => ['x' => 78, 'y' => 110],
        'appearance_findings' => ['x' => 78, 'y' => 116],
        'eent_findings' => ['x' => 78, 'y' => 121],
        'pupils_findings' => ['x' => 78, 'y' => 127],
        'hearing_findings' => ['x' => 78, 'y' => 132],
        'chest_findings' => ['x' => 78, 'y' => 137],
        'heart_findings' => ['x' => 78, 'y' => 142],
        
        // Physical Examination - FINDINGS (Right Column)
        'abdomen_findings' => ['x' => 181, 'y' => 90],
        'genitourinary_findings' => ['x' => 181, 'y' => 95],
        'neurologic_findings' => ['x' => 181, 'y' => 100],
        'neck_findings' => ['x' => 181, 'y' => 105],
        'back_findings' => ['x' => 181, 'y' => 110],
        'shoulder_arm_findings' => ['x' => 181, 'y' => 116],
        'elbow_forearm_findings' => ['x' => 181, 'y' => 121],
        'wrist_hand_findings' => ['x' => 181, 'y' => 127],
        'knee_findings' => ['x' => 181, 'y' => 132],
        'leg_ankle_findings' => ['x' => 181, 'y' => 137],
        'foot_toes_findings' => ['x' => 181, 'y' => 142],
        
        // Medical History Checkboxes - YES column (Row 1-9)
        'denied_participation_yes' => ['x' => 167, 'y' => 156],
        'asthma_yes' => ['x' => 167, 'y' => 166],
        'seizure_disorder_yes' => ['x' => 167, 'y' => 172],
        'heart_problem_yes' => ['x' => 167, 'y' => 177],
        'diabetes_yes' => ['x' => 167, 'y' => 182],
        'high_blood_pressure_yes' => ['x' => 167, 'y' => 187],
        'surgery_history_yes' => ['x' => 167, 'y' => 193],
        'chest_pain_yes' => ['x' => 167, 'y' => 198],
        'injury_history_yes' => ['x' => 167, 'y' => 203],
        'xray_history_yes' => ['x' => 167, 'y' => 208],
        'head_injury_yes' => ['x' => 167, 'y' => 214],
        'muscle_cramps_yes' => ['x' => 167, 'y' => 219],
        'vision_problems_yes' => ['x' => 167, 'y' => 224],
        'special_diet_yes' => ['x' => 167, 'y' => 229],
        'menstrual_history_yes' => ['x' => 167, 'y' => 240],
        
        // Medical History Checkboxes - NO column
        'denied_participation_no' => ['x' => 191, 'y' => 156],
        'asthma_no' => ['x' => 191, 'y' => 166],
        'seizure_disorder_no' => ['x' => 191, 'y' => 172],
        'heart_problem_no' => ['x' => 191, 'y' => 177],
        'diabetes_no' => ['x' => 191, 'y' => 182],
        'high_blood_pressure_no' => ['x' => 191, 'y' => 187],
        'surgery_history_no' => ['x' => 191, 'y' => 193],
        'chest_pain_no' => ['x' => 191, 'y' => 198],
        'injury_history_no' => ['x' => 191, 'y' => 203],
        'xray_history_no' => ['x' => 191, 'y' => 208],
        'head_injury_no' => ['x' => 191, 'y' => 214],
        'muscle_cramps_no' => ['x' => 191, 'y' => 219],
        'vision_problems_no' => ['x' => 191, 'y' => 224],
        'special_diet_no' => ['x' => 191, 'y' => 229],
        'menstrual_history_no' => ['x' => 191, 'y' => 240],
        
        // First menstrual age (for females)
        'first_menstrual_age' => ['x' => 166, 'y' => 247],
        
        // Certification - Bottom Section
        'student_certification_date' => ['x' => 50, 'y' => 285],
        'physician_name' => ['x' => 133, 'y' => 270],
        'license_no' => ['x' => 155, 'y' => 278],
        'physician_certification_date' => ['x' => 141, 'y' => 285],
    ];
}

// Function to draw checkbox
function drawCheckbox($pdf, $x, $y, $checked) {
    $pdf->SetLineWidth(0.5);
    $pdf->Rect($x, $y, 4, 4);
    if ($checked) {
        $pdf->SetFont('ZapfDingbats', '', 10);
        $pdf->SetXY($x, $y);
        $pdf->Cell(4, 4, '4', 0, 0, 'C'); // Checkmark character
        $pdf->SetFont('Times', '', 10); // Changed from Arial to Times
    }
}

// Function to draw X mark
function drawX($pdf, $x, $y) {
    $pdf->SetFont('ZapfDingbats', '', 10);
    $pdf->SetXY($x, $y);
    $pdf->Cell(4, 4, '8', 0, 0, 'C'); // X mark character
    $pdf->SetFont('Times', '', 10); // Changed from Arial to Times
}

// Fill the PDF fields - SINGLE PAGE ONLY
foreach ($field_coordinates as $field => $coords) {
    $pdf->SetXY($coords['x'], $coords['y']);
    
    if (strpos($field, '_yes') !== false || strpos($field, '_no') !== false) {
        // Handle yes/no checkbox fields for medical history
        $base_field = str_replace(['_yes', '_no'], '', $field);
        $value = $record[$base_field] ?? 0;
        
        if (strpos($field, '_yes') !== false) {
            // YES checkbox
            if ($value == 1) {
                drawCheckbox($pdf, $coords['x'], $coords['y'], true);
            }
        } elseif (strpos($field, '_no') !== false) {
            // NO checkbox
            if ($value == 0) {
                drawCheckbox($pdf, $coords['x'], $coords['y'], true);
            }
        }
    } else {
        // Handle text fields
        $value = '';
        
        switch ($field) {
            case 'last_name':
                $value = ucwords(strtolower($record['last_name'] ?? ''));
                break;
            case 'first_name':
                $value = ucwords(strtolower($record['first_name'] ?? ''));
                break;
            case 'middle_name':
                $value = ucwords(strtolower($record['middle_name'] ?? ''));
                break;
            case 'date_of_birth':
                $value = formatDate($record['date_of_birth'] ?? '', 'm/d/Y');
                break;
            case 'age':
                $value = calculateAge($record['date_of_birth'] ?? '');
                break;
            case 'sex':
                $value = $record['sex'] ?? '';
                break;
            case 'civil_status':
                $value = ucwords(strtolower($record['civil_status'] ?? ''));
                break;
            case 'address':
                $value = $record['address'] ?? '';
                break;
            case 'contact_number':
                $value = $record['contact_number'] ?? '';
                break;
            case 'height':
                $value = $record['height'] ?? '';
                break;
            case 'weight':
                $value = $record['weight'] ?? '';
                break;
            case 'bmi':
                $value = $record['bmi'] ?? '';
                break;
            case 'blood_pressure':
                $value = $record['blood_pressure'] ?? '';
                break;
            case 'heart_rate':
                $value = $record['heart_rate'] ?? '';
                break;
            case 'vision_right':
                $value = $record['vision_right'] ?? '';
                break;
            case 'vision_left':
                $value = $record['vision_left'] ?? '';
                break;
            case 'name':
                $value = ucwords(strtolower($record['first_name'] . ' ' . $record['last_name']));
                break;
            case 'program':
                $value = $record['program'] ?? '';
                break;
            case 'sports_event':
                $value = $record['sports_event'] ?? '';
                break;
            case 'certified_name':
                $value = ucwords(strtolower($record['certified_name'] ?? $record['first_name'] . ' ' . $record['last_name']));
                break;
            case 'certified_weight':
                $value = $record['certified_weight'] ?? $record['weight'] ?? '';
                break;
            case 'certified_height':
                $value = $record['certified_height'] ?? $record['height'] ?? '';
                break;
            case 'certified_civil_status':
                $value = ucwords(strtolower($record['certified_civil_status'] ?? $record['civil_status'] ?? ''));
                break;
            case 'certified_exam_date':
                $value = formatDate($record['certified_exam_date'] ?? $record['examination_date'] ?? '', 'm/d/Y');
                break;
            case 'student_certification_date':
                $value = formatDate($record['student_certification_date'] ?? date('Y-m-d'), 'm/d/Y');
                break;
            case 'physician_name':
                $value = !empty($record['physician_name']) ? $record['physician_name'] : 'MARSON KIM L. PERMENTILLA M.D.';
                break;
            case 'license_no':
                $value = !empty($record['license_no']) ? $record['license_no'] : '0169430';
                break;
            case 'physician_date':
            case 'physician_certification_date':
                $value = formatDate($record['physician_certification_date'] ?? date('Y-m-d'), 'm/d/Y');
                break;
            case 'first_menstrual_age':
                $value = $record['first_menstrual_age'] ?? '';
                break;
                
            // ============ NEW FIELDS ADDED HERE ============
            // History sections
            case 'past_medical_history':
                $value = $record['past_medical_history'] ?? '';
                break;
            case 'family_history':
                $value = $record['family_history'] ?? '';
                break;
            case 'occupational_history':
                $value = $record['occupational_history'] ?? '';
                break;
            
            // Physical examination findings
            case 'general_appearance_findings':
                $value = $record['general_appearance_findings'] ?? '';
                break;
            case 'skin_findings':
                $value = $record['skin_findings'] ?? '';
                break;
            case 'head_scalp_findings':
                $value = $record['head_scalp_findings'] ?? '';
                break;
            case 'eyes_findings':
                $value = $record['eyes_findings'] ?? '';
                break;
            case 'ears_findings':
                $value = $record['ears_findings'] ?? '';
                break;
            case 'nose_throat_findings':
                $value = $record['nose_throat_findings'] ?? '';
                break;
            case 'mouth_findings':
                $value = $record['mouth_findings'] ?? '';
                break;
            case 'neck_thyroid_ln_findings':
                $value = $record['neck_thyroid_ln_findings'] ?? '';
                break;
            case 'chest_breast_axilla_findings':
                $value = $record['chest_breast_axilla_findings'] ?? '';
                break;
            case 'heart_findings':
                $value = $record['heart_findings'] ?? '';
                break;
            case 'lungs_findings':
                $value = $record['lungs_findings'] ?? '';
                break;
            case 'abdomen_findings':
                $value = $record['abdomen_findings'] ?? '';
                break;
            case 'anus_rectum_findings':
                $value = $record['anus_rectum_findings'] ?? '';
                break;
            case 'genital_findings':
                $value = $record['genital_findings'] ?? '';
                break;
            case 'musculo_skeletal_findings':
                $value = $record['musculo_skeletal_findings'] ?? '';
                break;
            case 'extremities_findings':
                $value = $record['extremities_findings'] ?? '';
                break;
            
            // Vision fields
            case 'vision_with_glasses_r':
                $value = $record['vision_right'] ?? '';
                break;
            case 'vision_without_glasses_l':
                $value = $record['vision_left'] ?? '';
                break;
            
            // Diagnostic findings text fields
            case 'chest_xray_findings_text':
                $value = $record['chest_xray_findings_text'] ?? '';
                break;
            case 'cbc_findings_text':
                $value = $record['cbc_findings_text'] ?? '';
                break;
            case 'urinalysis_findings_text':
                $value = $record['urinalysis_findings_text'] ?? '';
                break;
            case 'stool_findings_text':
                $value = $record['stool_findings_text'] ?? '';
                break;
            case 'hepa_b_findings_text':
                $value = $record['hepa_b_findings_text'] ?? '';
                break;
            
            // Institution field
            case 'school_company_institution':
                $value = 'BATANGAS STATE UNIVERSITY'; // Hardcoded as per template
                break;
            
            // ============ CHECKBOX FIELDS ============
            case 'hearing_normal':
            case 'hearing_defective':
            case 'chest_xray_pa':
            case 'chest_xray_lordotic':
            case 'chest_xray_normal':
            case 'chest_xray_findings':
            case 'cbc_normal':
            case 'cbc_findings':
            case 'urinalysis_normal':
            case 'urinalysis_findings':
            case 'stool_normal':
            case 'stool_findings':
            case 'hepa_b_normal':
            case 'hepa_b_findings':
            case 'methamphetamine_negative':
            case 'methamphetamine_positive':
            case 'thc_negative':
            case 'thc_positive':
            case 'classification_a':
            case 'classification_b':
            case 'classification_c':
            case 'classification_d':
            case 'needs_treatment_skin':
            case 'needs_treatment_dental':
            case 'needs_treatment_anemia':
            case 'needs_treatment_vision':
            case 'needs_treatment_uti':
            case 'needs_treatment_parasitism':
            case 'needs_treatment_hypertension':
            case 'needs_treatment_others_check':
                // These are checkbox fields, check if they should be checked
                $checkbox_value = $record[$field] ?? 0;
                if ($checkbox_value == 1) {
                    drawCheckbox($pdf, $coords['x'], $coords['y'], true);
                }
                $value = ''; // Empty for checkbox fields
                break;
            // ============ END OF NEW FIELDS ============
            
            // Physical examination findings for history form
            case 'height_findings':
            case 'weight_findings':
            case 'bp_findings':
            case 'pulse_findings':
            case 'vision_findings':
            case 'appearance_findings':
            case 'eent_findings':
            case 'pupils_findings':
            case 'hearing_findings':
            case 'chest_findings':
            case 'heart_findings':
            case 'abdomen_findings':
            case 'genitourinary_findings':
            case 'neurologic_findings':
            case 'neck_findings':
            case 'back_findings':
            case 'shoulder_arm_findings':
            case 'elbow_forearm_findings':
            case 'wrist_hand_findings':
            case 'knee_findings':
            case 'leg_ankle_findings':
            case 'foot_toes_findings':
                $value = $record[$field] ?? '';
                // Truncate long findings to fit in single line
                if (strlen($value) > 20) {
                    $value = substr($value, 0, 20);
                }
                break;
            default:
                $value = $record[$field] ?? '';
                break;
        }
        
        // Only output text if it's not a checkbox field
        if (!empty($value) && !in_array($field, [
            'hearing_normal', 'hearing_defective', 'chest_xray_pa', 'chest_xray_lordotic', 'chest_xray_normal',
            'chest_xray_findings', 'cbc_normal', 'cbc_findings', 'urinalysis_normal', 'urinalysis_findings',
            'stool_normal', 'stool_findings', 'hepa_b_normal', 'hepa_b_findings', 'methamphetamine_negative',
            'methamphetamine_positive', 'thc_negative', 'thc_positive', 'classification_a', 'classification_b',
            'classification_c', 'classification_d', 'needs_treatment_skin', 'needs_treatment_dental',
            'needs_treatment_anemia', 'needs_treatment_vision', 'needs_treatment_uti', 'needs_treatment_parasitism',
            'needs_treatment_hypertension', 'needs_treatment_others_check'
        ])) {
            $pdf->Cell(0, 0, $value);
        }
    }
}

// Add others text if checked for medical form
if ($type === 'medical_form' || $type === 'medical_exam') {
    if (!empty($record['needs_treatment_others_check']) && !empty($record['needs_treatment_others_text'])) {
        // Adjust coordinates as needed based on your template
        $pdf->SetXY(150, 274); // Adjusted based on your checkbox coordinates
        $pdf->Cell(0, 0, $record['needs_treatment_others_text']);
    }
}

// Output the PDF as single page
$filename = $type . '_certificate_' . $record['first_name'] . '_' . $record['last_name'] . '_' . date('Ymd') . '.pdf';
$pdf->Output('I', $filename);