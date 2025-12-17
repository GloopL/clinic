<?php
session_start();
include '../../config/database.php';

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

// Determine dashboard URL based on role
$dashboard_url = '../../dashboard.php';
if (isset($_SESSION['role'])) {
    if ($_SESSION['role'] === 'doctor') {
        $dashboard_url = '../../doctor_dashboard.php';
    } elseif ($_SESSION['role'] === 'dentist') {
        $dashboard_url = '../../dentist_dashboard.php';
    } elseif ($_SESSION['role'] === 'nurse') {
        $dashboard_url = '../../nurse_dashboard.php';
    } elseif ($_SESSION['role'] === 'staff') {
        $dashboard_url = '../../msa_dashboard.php';
    }
}

// -----------------------------------------------------------
// FIX 1: Ensure all medical staff roles can edit/view
$is_nurse = false;
$user_role = $_SESSION['role'] ?? 'user';
if (isset($_SESSION['role'])) {
    // Include 'dentist', 'admin', 'doctor', 'staff' as roles that can edit/view medical records
    $is_nurse = in_array($_SESSION['role'], ['nurse', 'admin', 'dentist', 'physician', 'doctor', 'staff']);
    $user_role = $_SESSION['role'];
}

// Alternative: Check user role from database if not in session
if (!isset($_SESSION['role'])) {
    $user_id = $_SESSION['user_id'];
    $user_query = $conn->prepare("SELECT role FROM users WHERE id = ?");
    $user_query->bind_param("i", $user_id);
    $user_query->execute();
    $user_result = $user_query->get_result();

    if ($user_result->num_rows > 0) {
        $user_data = $user_result->fetch_assoc();
        $_SESSION['role'] = $user_data['role'];
        $is_nurse = in_array($_SESSION['role'], ['nurse', 'admin', 'dentist', 'physician', 'doctor', 'staff']);
        $user_role = $_SESSION['role'];
    }
}
// -----------------------------------------------------------

$success_message = $_GET['success'] ?? '';
$error_message = '';
$type = $_GET['type'] ?? '';
$id = $_GET['id'] ?? '';
$record = null;
$is_certified = false;
$medical_diagnoses = [];
$existing_diagnosis = null;

// ✅ FIXED BACK BUTTON LOGIC: Simplified
$back_url = "../records/submissions.php"; // Default

// Check if there's a 'from' parameter in the URL (highest priority)
if (isset($_GET['from'])) {
    $from = $_GET['from'];
    if ($from === 'submissions') {
        $back_url = "../records/submissions.php" . ($type ? "?type=" . urlencode($type) : "");
    } elseif ($from === 'verify') {
        $back_url = "../records/verify_submission.php" . ($type ? "?type=" . urlencode($type) . "&id=" . urlencode($id) : "");
    } elseif ($from === 'view_patient') {
        $back_url = "../records/view_patient.php";
    }
} else {
    // Simple default based on user role
    $back_url = "../records/submissions.php" . ($type ? "?type=" . urlencode($type) : "");
}

// Map form types to their respective tables.
$form_map = [
    'history_form' => ['table' => 'history_forms', 'record_type' => 'history_form'],
    'medical_form' => ['table' => 'medical_exams', 'record_type' => 'medical_exam'],
    'medical_exam' => ['table' => 'medical_exams', 'record_type' => 'medical_exam'],
    'dental_form'  => ['table' => 'dental_exams', 'record_type' => 'dental_exam'],
    'dental_exam'  => ['table' => 'dental_exams', 'record_type' => 'dental_exam']
];

$dental_checkbox_map = [
    'periodontal_screening' => [
        'is_gingivitis' => 'Gingivitis',
        'is_early_periodontitis' => 'Early Periodontitis',
        'is_moderate_periodontitis' => 'Moderate Periodontitis',
        'is_advanced_periodontitis' => 'Advanced Periodontitis'
    ],
    'occlusion' => [
        'is_class_molar' => 'Occlusion Class Molar',
        'is_overjet' => 'Overjet',
        'is_overbite' => 'Overbite',
        'is_crossbite' => 'Crossbite',
        'is_midline_deviation' => 'Midline Deviation'
    ],
    'appliances' => [
        'is_orthodontic' => 'Orthodontic Appliance',
        'is_stayplate' => 'Stayplate / Retainer',
        'is_appliance_others' => 'Other Appliance'
    ],
    'tmd_status' => [
        'is_clenching' => 'Clenching',
        'is_clicking' => 'Clicking',
        'is_trismus' => 'Trismus',
        'is_muscle_spasm' => 'Muscle Spasm'
    ]
];

// Add provider_id column to medical_diagnoses table if it doesn't exist
$check_column_query = "SHOW COLUMNS FROM medical_diagnoses LIKE 'provider_id'";
$result = $conn->query($check_column_query);
if ($result->num_rows == 0) {
    $add_column_query = "ALTER TABLE medical_diagnoses ADD COLUMN provider_id INT AFTER provider_name";
    $conn->query($add_column_query);
}

// Add separate nurse_note, doctor_note columns if they don't exist
$check_note_columns = $conn->query("SHOW COLUMNS FROM medical_diagnoses LIKE 'nurse_note'");
if ($check_note_columns->num_rows == 0) {
    $add_note_columns = "ALTER TABLE medical_diagnoses 
                        ADD COLUMN nurse_note TEXT AFTER assessment,
                        ADD COLUMN doctor_note TEXT AFTER nurse_note,
                        ADD COLUMN nurse_diagnosis_date DATE AFTER doctor_note,
                        ADD COLUMN doctor_diagnosis_date DATE AFTER nurse_diagnosis_date";
    $conn->query($add_note_columns);
}

// Helper function to get provider's full name
function getProviderFullName($conn, $provider_username) {
    if (empty($provider_username)) {
        return $provider_username;
    }
    
    $provider_username = trim($provider_username);
    
    // Try to find by username in users table
    $user_query = $conn->prepare("
        SELECT u.full_name, u.id, u.username, u.role
        FROM users u
        WHERE BINARY u.username = ?
        LIMIT 1
    ");
    $user_query->bind_param("s", $provider_username);
    $user_query->execute();
    $user_result = $user_query->get_result();
    
    if ($user_result->num_rows > 0) {
        $user_data = $user_result->fetch_assoc();
        if (!empty($user_data['full_name']) && trim($user_data['full_name']) !== '') {
            $user_query->close();
            return trim($user_data['full_name']);
        }
    }
    $user_query->close();
    
    // Fallback to username if no full name found
    return $provider_username;
}

// Fetch consultation history for display - FILTERED BY CURRENT USER ROLE
$patient_id = $record['patient_id'] ?? null;
$recent_consultations = [];
if ($patient_id !== null) {
    // Determine which consultation types to show based on current user role
    $allowed_consultation_types = [];

    if ($user_role === 'nurse') {
        $allowed_consultation_types = ['medical', 'history'];
    } elseif ($user_role === 'dentist') {
        $allowed_consultation_types = ['dental'];
    } elseif ($user_role === 'doctor' || $user_role === 'physician') {
        $allowed_consultation_types = ['medical'];
    } elseif ($user_role === 'admin' || $user_role === 'staff') {
        // Admin/staff can see all consultation types
        $allowed_consultation_types = ['medical', 'dental', 'history'];
    }

    if (!empty($allowed_consultation_types)) {
        $placeholders = str_repeat('?,', count($allowed_consultation_types) - 1) . '?';
        $stmt = $conn->prepare("
            SELECT ch.consultation_type, ch.consultation_date, ch.physician_name, ch.diagnosis, ch.treatment, ch.recommendations,
                   COALESCE(u.full_name, ch.physician_name) as physician_full_name
            FROM consultation_history ch
            LEFT JOIN users u ON BINARY u.username = ch.physician_name
            WHERE ch.patient_id = ? AND ch.consultation_type IN ($placeholders)
            ORDER BY ch.consultation_date DESC
            LIMIT 3
        ");

        $types = "i" . str_repeat('s', count($allowed_consultation_types));
        $params = array_merge([$patient_id], $allowed_consultation_types);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $recent_consultations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}

// ✅ Handle Update Request (for nurse/admin/dentist/physician only)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['update_record']) && $is_nurse) {
    $record_id = $_POST['record_id'];
    $record_type = $_POST['record_type'];
    
    $effective_type = isset($form_map[$record_type]) ? $record_type : (isset($form_map[$type]) ? $type : null);
    
    if ($effective_type && isset($form_map[$effective_type])) {
        $table = $form_map[$effective_type]['table'];
        
        // Get table structure
        $check_columns = $conn->query("DESCRIBE $table");
        $existing_columns = [];
        while ($column = $check_columns->fetch_assoc()) {
            $existing_columns[] = $column['Field'];
        }
        
        $set_parts = [];
        $params = [];
        $types = '';
        
        // Define ALL possible fields for each form type based on the actual form structures
        $possible_fields = [];
        
        if ($effective_type === 'medical_form' || $effective_type === 'medical_exam') {
    $possible_fields = [
        // Basic measurements
        'height', 'weight', 'bmi', 'blood_pressure', 'heart_rate', 
        'vision_right', 'vision_left', 'examination_date',
        
        // Past medical history
        'past_medical_history', 'family_history', 'occupational_history',
        
        // Physical examination findings
        'general_appearance_findings', 'skin_findings', 'head_scalp_findings',
        'eyes_findings', 'ears_findings', 'nose_throat_findings',
        'mouth_findings', 'neck_thyroid_ln_findings', 'chest_breast_axilla_findings',
        'heart_findings', 'lungs_findings', 'abdomen_findings',
        'anus_rectum_findings', 'genital_findings', 'musculo_skeletal_findings',
        'extremities_findings',
        
        // Diagnostic examination - checkboxes
        'chest_xray_pa', 'chest_xray_lordotic', 
        'chest_xray_findings', 'chest_xray_normal', 'chest_xray_findings_text',
        'cbc_findings', 'cbc_normal', 'cbc_findings_text',
        'urinalysis_findings', 'urinalysis_normal', 'urinalysis_findings_text',
        'stool_findings', 'stool_normal', 'stool_findings_text',
        'hepa_b_findings', 'hepa_b_normal', 'hepa_b_findings_text',
        
        // Drug test
        'methamphetamine_negative', 'methamphetamine_positive',
        'thc_negative', 'thc_positive',
        
        // Hearing and vision status
        'hearing_defective', 'hearing_normal',
        'vision_with_glasses', 'vision_without_glasses',
        
        // Provider info
        'physician_name', 'license_no',
        
        // CERTIFICATION FIELDS - NEW ADDITION
        // Left column fields
        'certified_name', 'certified_weight', 'certified_height', 
        'certified_civil_status', 'certified_exam_date',
        'student_signature_date',
        
        // Classification checkboxes
        'classification_a', 'classification_b', 'classification_c', 'classification_d',
        
        // Treatment/Correction checkboxes
        'needs_treatment_skin', 'needs_treatment_dental', 'needs_treatment_anemia',
        'needs_treatment_vision', 'needs_treatment_uti', 'needs_treatment_parasitism',
        'needs_treatment_hypertension', 'needs_treatment_others_check',
        'needs_treatment_others_text',
        
        // Physician certification date
        'physician_date',
        
        // DIAGNOSIS NOTE FIELDS - SIMPLIFIED TO JUST NOTES
        'diagnosis_note'
    ];
} elseif ($effective_type === 'dental_form' || $effective_type === 'dental_exam') {
    $possible_fields = [
        'remarks', 'dental_chart_data', 'dentist_name', 'license_no', 'dentist_date',
        // Dental checkbox fields - ADDED THESE - FIXED: Now includes all checkbox fields
        'is_gingivitis', 'is_early_periodontitis', 'is_moderate_periodontitis', 'is_advanced_periodontitis',
        'is_class_molar', 'is_overjet', 'is_overbite', 'is_crossbite', 'is_midline_deviation',
        'is_orthodontic', 'is_stayplate', 'is_appliance_others',
        'is_clenching', 'is_clicking', 'is_trismus', 'is_muscle_spasm',
        // Also include the JSON columns for backward compatibility
        'periodontal_screening', 'occlusion', 'appliances', 'tmd_status'
    ];
} elseif ($effective_type === 'history_form') {
    $possible_fields = [
        'denied_participation', 'ashtma', 'seizure_disorder', 'heart_problem', 'diabetes', 
        'high_blood_pressure', 'surgery_history', 'chest_pain', 'injury_history', 'xray_history', 
        'head_injury', 'muscle_cramps', 'vision_problems', 'special_diet', 'menstrual_history',
        'first_menstrual_age', 'sports_event', 
        'height_normal', 'height_findings', 'weight_normal', 'weight_findings', 'bp_normal', 'bp_findings',
        'pulse_normal', 'pulse_findings', 'vision_normal', 'vision_findings', 'appearance_normal', 'appearance_findings',
        'eent_normal', 'eent_findings', 'pupils_normal', 'pupils_findings', 'hearning_normal', 'hearing_findings',
        'chest_normal', 'chest_findings', 'heart_normal', 'heart_findings', 'abdomen_normal', 'abdomen_findings',
        'genitourinary_normal', 'genitourinary_findings', 'neurologic_normal', 'neurologic_findings',
        'neck_normal', 'neck_findings', 'back_normal', 'back_findings', 'shoulder_arm_normal', 'shoulder_arm_findings',
        'elbow_forearm_normal', 'elbow_forearm_findings', 'wrist_hand_normal', 'wrist_hand_findings',
        'knee_normal', 'knee_findings', 'leg_ankle_normal', 'leg_ankle_findings', 'foot_toes_normal', 'foot_toes_findings',
        
        // DIAGNOSIS NOTE FIELDS - SIMPLIFIED TO JUST NOTES
        'diagnosis_note'
    ];
}
        
        // FIX: Process dental checkbox fields BEFORE building payload - FIXED: Handle checkbox processing
        if ($effective_type === 'dental_form' || $effective_type === 'dental_exam') {
            // First, ensure all checkbox fields have values
            $dental_checkbox_fields = [
                'is_gingivitis', 'is_early_periodontitis', 'is_moderate_periodontitis', 'is_advanced_periodontitis',
                'is_class_molar', 'is_overjet', 'is_overbite', 'is_crossbite', 'is_midline_deviation',
                'is_orthodontic', 'is_stayplate', 'is_appliance_others',
                'is_clenching', 'is_clicking', 'is_trismus', 'is_muscle_spasm'
            ];
            
            foreach ($dental_checkbox_fields as $field) {
                if (!isset($_POST[$field])) {
                    $_POST[$field] = 0; // Set to 0 if not submitted
                }
            }
            
            // Then build the JSON payload for database storage (for backward compatibility)
            foreach ($dental_checkbox_map as $column => $fieldMap) {
                $_POST[$column] = buildDentalCheckboxPayload($fieldMap, $_POST);
            }
        }

        // Process each field
        foreach ($possible_fields as $field) {
            if (in_array($field, $existing_columns)) {
                
                // Handle checkbox/boolean fields
                if (str_contains($field, 'is_') || str_contains($field, '_normal') || 
                    $field === 'denied_participation' || $field === 'ashtma' || $field === 'seizure_disorder' ||
                    $field === 'heart_problem' || $field === 'diabetes' || $field === 'high_blood_pressure' ||
                    $field === 'chest_pain' || $field === 'head_injury' || $field === 'muscle_cramps' || 
                    $field === 'vision_problems' || $field === 'needs_follow_up') {
                    
                    $value = isset($_POST[$field]) && $_POST[$field] == '1' ? 1 : 0;
                    $set_parts[] = "$field = ?";
                    $params[] = $value;
                    $types .= 'i';
                } 
                // Handle date fields
                elseif ($field === 'examination_date' || $field === 'dentist_date' || $field === 'physician_date' || $field === 'student_signature_date') {
                    $value = $_POST[$field] ?? '';
                    $set_parts[] = "$field = ?";
                    $params[] = $value;
                    $types .= 's';
                }
                // Handle text/textarea fields
                else {
                    $value = $_POST[$field] ?? '';
                    $set_parts[] = "$field = ?";
                    $params[] = $value;
                    $types .= 's';
                }
            }
        }
        
        // Update medical_records table
        $medical_set_parts = [];
        $medical_params = [];
        $medical_types = '';
        
        $common_fields_to_update = ['physician_name', 'verification_status', 'verification_notes'];

        foreach ($common_fields_to_update as $field) {
            if (isset($_POST[$field])) {
                $medical_set_parts[] = "$field = ?";
                $medical_params[] = $_POST[$field];
                $medical_types .= 's';
            }
        }

        $all_updates_successful = true;
        
        // Execute updates for specific form table
        if (!empty($set_parts)) {
            $sql = "UPDATE $table SET " . implode(', ', $set_parts) . " WHERE record_id = ?";
            $params[] = $record_id;
            $types .= 'i';
            
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param($types, ...$params);
                if (!$stmt->execute()) {
                    $error_message = "Error updating " . $effective_type . " data: " . $conn->error;
                    $all_updates_successful = false;
                }
                $stmt->close();
            }
        }
        
        // Execute update for medical_records table
        if (!empty($medical_set_parts)) {
            $medical_sql = "UPDATE medical_records SET " . implode(', ', $medical_set_parts) . " WHERE id = ?";
            $medical_params[] = $record_id;
            $medical_types .= 'i';
            
            $stmt_medical = $conn->prepare($medical_sql);
            if ($stmt_medical) {
                $stmt_medical->bind_param($medical_types, ...$medical_params);
                if (!$stmt_medical->execute()) {
                    $error_message = ($error_message ? $error_message . " AND " : "") . "Error updating medical record common fields: " . $conn->error;
                    $all_updates_successful = false;
                }
                $stmt_medical->close();
            }
        }
        
        if ($all_updates_successful) {
            $success_message = "Record successfully updated.";
            
            // Handle save and print request
            if (isset($_POST['print_after_save'])) {
                header("Location: generate_certified_pdf.php?type=" . urlencode($type) . "&id=" . urlencode($id));
                exit();
            }
            
            // Handle save and certify request - FIXED: Change status to 'certified' and set certified_by and certified_date
            if (isset($_POST['certify_after_save'])) {
                // Get current user's username for certified_by
                $certified_by = $_SESSION['username'] ?? 'Unknown';
                
                // Update the medical_records table to mark as certified
                $stmt = $conn->prepare("UPDATE medical_records SET verification_status = 'certified', certified_by = ?, certified_date = NOW() WHERE id = ?");
                $stmt->bind_param("si", $certified_by, $record_id);
                
                if ($stmt->execute()) {
                    $success_message = "Record successfully updated and certified!";
                    header("Location: view_record.php?type=" . urlencode($type) . "&id=" . urlencode($id) . "&success=" . urlencode($success_message));
                    exit();
                } else {
                    $error_message = "Error certifying record: " . $conn->error;
                }
                $stmt->close();
            }
            
            // Handle mark for certification
            if (isset($_POST['mark_for_certification'])) {
                // Update the medical_records table to mark as for certification
                $stmt = $conn->prepare("UPDATE medical_records SET verification_status = 'for_certification' WHERE id = ?");
                $stmt->bind_param("i", $record_id);
                
                if ($stmt->execute()) {
                    $success_message = "Record successfully updated and submitted for certification!";
                    header("Location: view_record.php?type=" . urlencode($type) . "&id=" . urlencode($id) . "&success=" . urlencode($success_message));
                    exit();
                }
                $stmt->close();
            }
            
            // Refresh the record data
            header("Location: view_record.php?type=" . urlencode($type) . "&id=" . urlencode($id) . "&success=" . urlencode($success_message));
            exit();
        }
    }
}

// Handle Initialize Dental Chart Request
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['initialize_dental_chart']) && $is_nurse) {
    $record_id = $_POST['record_id'];
    
    // Initialize empty dental chart data
    $empty_dental_data = json_encode([]);
    
    $stmt = $conn->prepare("UPDATE dental_exams SET dental_chart_data = ? WHERE record_id = ?");
    $stmt->bind_param("si", $empty_dental_data, $record_id);
    
    if ($stmt->execute()) {
        $success_message = "Dental chart initialized successfully!";
        header("Location: view_record.php?type=" . urlencode($type) . "&id=" . urlencode($id) . "&success=" . urlencode($success_message));
        exit();
    } else {
        $error_message = "Error initializing dental chart: " . $conn->error;
    }
    $stmt->close();
}

// Handle Delete Request
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['delete'], $_POST['record_id'], $_POST['record_type'])) {
    $record_id = $_POST['record_id'];
    $record_type = $_POST['record_type'];
    
    $effective_type = isset($form_map[$record_type]) ? $record_type : (isset($form_map[$type]) ? $type : null);
    
    if ($effective_type && isset($form_map[$effective_type])) {
        $table = $form_map[$effective_type]['table'];
        
        // Begin transaction
        $conn->begin_transaction();
        
        try {
            // Delete from specific form table
            $delete_form = $conn->prepare("DELETE FROM $table WHERE record_id = ?");
            $delete_form->bind_param("i", $record_id);
            $delete_form->execute();
            $delete_form->close();
            
            // Delete from medical_records table
            $delete_medical = $conn->prepare("DELETE FROM medical_records WHERE id = ?");
            $delete_medical->bind_param("i", $record_id);
            $delete_medical->execute();
            $delete_medical->close();
            
            // Commit transaction
            $conn->commit();
            
            header("Location: ../records/view_patient.php?success=Record+deleted+successfully");
            exit();
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            $error_message = "Error deleting record: " . $e->getMessage();
        }
    } else {
        $error_message = "Invalid record type for deletion.";
    }
}

// Handle Mark for Certification Request
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['mark_for_certification'], $_POST['record_id'], $_POST['record_type']) && $is_nurse) {
    $record_id = $_POST['record_id'];
    $record_type = $_POST['record_type'];
    
    // Update the medical_records table to mark as for certification
    $stmt = $conn->prepare("UPDATE medical_records SET verification_status = 'for_certification' WHERE id = ?");
    $stmt->bind_param("i", $record_id);
    
    if ($stmt->execute()) {
        $success_message = "Record successfully marked for certification!";
        header("Location: view_record.php?type=" . urlencode($type) . "&id=" . urlencode($id) . "&success=" . urlencode($success_message));
        exit();
    } else {
        $error_message = "Error marking record for certification: " . $conn->error;
    }
    $stmt->close();
}

// Handle Mark as Completed Request for Dental Forms - FIXED: Now updates the dental exam record too
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['mark_as_completed'], $_POST['record_id'], $_POST['record_type'])) {
    $record_id = $_POST['record_id'];
    $record_type = $_POST['record_type'];
    
    // Only allow dentists, admins, and staff to mark dental forms as completed
    if (in_array($user_role, ['dentist', 'admin', 'staff']) && 
        ($record_type === 'dental_form' || $record_type === 'dental_exam')) {
        
        // Begin transaction
        $conn->begin_transaction();
        
        try {
            // Update the medical_records table to mark as completed
            $stmt = $conn->prepare("UPDATE medical_records SET verification_status = 'completed' WHERE id = ?");
            $stmt->bind_param("i", $record_id);
            
            if (!$stmt->execute()) {
                throw new Exception("Error updating medical record status: " . $conn->error);
            }
            $stmt->close();
            
            // Also update the dental_exams table with dentist info and date
            $current_user_id = $_SESSION['user_id'] ?? 0;
            $dentist_name = '';
            $license_no = '';
            
            // Get current user's information
            if ($current_user_id) {
                $user_query = $conn->prepare("SELECT full_name, username FROM users WHERE id = ?");
                $user_query->bind_param("i", $current_user_id);
                $user_query->execute();
                $user_result = $user_query->get_result();
                
                if ($user_result->num_rows > 0) {
                    $user_data = $user_result->fetch_assoc();
                    // Use full_name if available, otherwise use username
                    if (!empty($user_data['full_name']) && trim($user_data['full_name']) !== '') {
                        $dentist_name = trim($user_data['full_name']);
                    } else {
                        $dentist_name = $user_data['username'];
                    }
                }
                $user_query->close();
            }
            
            // If no user found, fallback to session username
            if (empty($dentist_name) && isset($_SESSION['username'])) {
                $dentist_name = $_SESSION['username'];
            }
            
            // Final fallback
            if (empty($dentist_name)) {
                $dentist_name = 'Dentist';
            }
            
            // Add D.M.D. title for dentists
            if ($user_role === 'dentist') {
                $dentist_name .= ' D.M.D.';
            }
            
            $current_date = date('Y-m-d');
            
            // Check if dentist_name column exists, if not add it
            $check_dentist_name = $conn->query("SHOW COLUMNS FROM dental_exams LIKE 'dentist_name'");
            if ($check_dentist_name->num_rows == 0) {
                $conn->query("ALTER TABLE dental_exams ADD COLUMN dentist_name VARCHAR(255) AFTER remarks");
            }
            
            // Check if dentist_date column exists, if not add it
            $check_dentist_date = $conn->query("SHOW COLUMNS FROM dental_exams LIKE 'dentist_date'");
            if ($check_dentist_date->num_rows == 0) {
                $conn->query("ALTER TABLE dental_exams ADD COLUMN dentist_date DATE AFTER dentist_name");
            }
            
            // Update dental_exams table
            $update_dental = $conn->prepare("UPDATE dental_exams SET dentist_name = ?, dentist_date = ? WHERE record_id = ?");
            $update_dental->bind_param("ssi", $dentist_name, $current_date, $record_id);
            
            if (!$update_dental->execute()) {
                throw new Exception("Error updating dental exam details: " . $conn->error);
            }
            $update_dental->close();
            
            // Commit transaction
            $conn->commit();
            
            $success_message = "Dental form successfully marked as completed!";
            header("Location: view_record.php?type=" . urlencode($type) . "&id=" . urlencode($id) . "&success=" . urlencode($success_message));
            exit();
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            $error_message = "Error marking form as completed: " . $e->getMessage();
        }
    } else {
        $error_message = "You don't have permission to mark this form as completed.";
    }
}

// Fetch a single record (for viewing)
if ($type && $id) {
    if (isset($form_map[$type])) {
        $table = $form_map[$type]['table'];
        
        // Build query based on form type
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

            if (!$record) {
                $error_message = "No record found for this submission.";
            } else {
                // ✅ ADD THIS: Check if record is already marked for certification
                $is_certified = false;
                if (isset($record['verification_status'])) {
                    $is_certified = ($record['verification_status'] === 'for_certification' || $record['verification_status'] === 'certified');
                }
                
                // ✅ FIXED: Fetch medical diagnoses AFTER record is loaded
                $stmt_diagnoses = $conn->prepare("
                    SELECT md.*, u.full_name as provider_full_name, u.role as provider_role
                    FROM medical_diagnoses md
                    LEFT JOIN users u ON BINARY u.username = md.provider_name
                    WHERE md.patient_id = ? AND md.record_id = ?
                    ORDER BY md.diagnosis_date DESC, md.created_at DESC
                ");
                $stmt_diagnoses->bind_param("ii", $record['patient_id'], $record['record_id']);
                $stmt_diagnoses->execute();
                $medical_diagnoses = $stmt_diagnoses->get_result()->fetch_all(MYSQLI_ASSOC);
                
                // Get existing diagnosis if any
                if (!empty($medical_diagnoses)) {
                    $existing_diagnosis = $medical_diagnoses[0];
                }
            }
            $stmt->close();
        } else {
            $error_message = "Error preparing query: " . $conn->error;
        }
    } else {
        $error_message = "Invalid form type: " . htmlspecialchars($type);
    }
}

// FIX: Load dental checkbox data correctly
if ($record && ($type === 'dental_form' || $type === 'dental_exam')) {
    // First, try to hydrate from individual checkbox fields
    $has_individual_fields = false;
    foreach ($dental_checkbox_map as $column => $fieldMap) {
        foreach ($fieldMap as $field => $label) {
            if (isset($record[$field])) {
                $has_individual_fields = true;
                break 2;
            }
        }
    }
    
    if (!$has_individual_fields) {
        // If individual fields don't exist, hydrate from JSON columns
        foreach ($dental_checkbox_map as $column => $fieldMap) {
            hydrateDentalCheckboxFlags($record, $column, $fieldMap);
        }
    }
}

// Fetch consultation history for the patient
if ($record && isset($record['patient_id'])) {
    $stmt = $conn->prepare("
        SELECT ch.consultation_type, ch.consultation_date, ch.physician_name, ch.diagnosis, ch.treatment, ch.recommendations,
               COALESCE(u.full_name, ch.physician_name) as physician_full_name
        FROM consultation_history ch 
        LEFT JOIN users u ON BINARY u.username = ch.physician_name
        WHERE ch.patient_id = ? 
        ORDER BY ch.consultation_date DESC 
        LIMIT 3
    ");
    $stmt->bind_param("i", $record['patient_id']);
    $stmt->execute();
    $recent_consultations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Handle Medical Diagnosis Submission - MODIFIED: Now with separate notes
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['add_medical_diagnosis'])) {
    $record_id = $_POST['record_id'];
    $patient_id = $_POST['patient_id'];
    $diagnosis_type = $_POST['diagnosis_type'];
    $user_id = $_SESSION['user_id'];
    
    // Get user's full name and role
    $user_query = $conn->prepare("SELECT full_name, username, role FROM users WHERE id = ?");
    $user_query->bind_param("i", $user_id);
    $user_query->execute();
    $user_result = $user_query->get_result();
    $user_data = $user_result->fetch_assoc();
    
    $provider_name = !empty($user_data['full_name']) ? $user_data['full_name'] : $user_data['username'];
    $provider_role = $user_data['role'];
    
    // Check if a diagnosis already exists for this patient and record
    $check_stmt = $conn->prepare("SELECT id FROM medical_diagnoses WHERE patient_id = ? AND record_id = ?");
    $check_stmt->bind_param("ii", $patient_id, $record_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        // Update existing diagnosis
        $existing_diagnosis = $check_result->fetch_assoc();
        $diagnosis_id = $existing_diagnosis['id'];
        
        if ($diagnosis_type === 'nurse') {
            // Update nurse note and date
            $nurse_note = $_POST['nurse_note'] ?? '';
            $stmt = $conn->prepare("
                UPDATE medical_diagnoses 
                SET nurse_note = ?, 
                    nurse_diagnosis_date = CURDATE(),
                    diagnosis_date = CURDATE(),
                    provider_name = ?,
                    provider_role = ?,
                    provider_id = ?
                WHERE id = ?
            ");
            $stmt->bind_param("sssii", 
                $nurse_note,
                $provider_name, 
                $provider_role, 
                $user_id,
                $diagnosis_id);
        } elseif ($diagnosis_type === 'doctor') {
            // Update doctor note and date
            $doctor_note = $_POST['doctor_note'] ?? '';
            $stmt = $conn->prepare("
                UPDATE medical_diagnoses 
                SET doctor_note = ?, 
                    doctor_diagnosis_date = CURDATE(),
                    diagnosis_date = CURDATE(),
                    provider_name = ?,
                    provider_role = ?,
                    provider_id = ?
                WHERE id = ?
            ");
            $stmt->bind_param("sssii", 
                $doctor_note,
                $provider_name, 
                $provider_role, 
                $user_id,
                $diagnosis_id);
        }
    } else {
        // Insert new diagnosis
        if ($diagnosis_type === 'nurse') {
            $nurse_note = $_POST['nurse_note'] ?? '';
            $stmt = $conn->prepare("
                INSERT INTO medical_diagnoses 
                (patient_id, record_id, diagnosis_type, diagnosis_date, 
                 provider_name, provider_role, provider_id,
                 nurse_note, nurse_diagnosis_date) 
                VALUES (?, ?, ?, CURDATE(), ?, ?, ?, ?, CURDATE())
            ");
            $stmt->bind_param("iisssss", 
                $patient_id, $record_id, $diagnosis_type, 
                $provider_name, $provider_role, $user_id,
                $nurse_note);
        } elseif ($diagnosis_type === 'doctor') {
            $doctor_note = $_POST['doctor_note'] ?? '';
            $stmt = $conn->prepare("
                INSERT INTO medical_diagnoses 
                (patient_id, record_id, diagnosis_type, diagnosis_date, 
                 provider_name, provider_role, provider_id,
                 doctor_note, doctor_diagnosis_date) 
                VALUES (?, ?, ?, CURDATE(), ?, ?, ?, ?, CURDATE())
            ");
            $stmt->bind_param("iisssss", 
                $patient_id, $record_id, $diagnosis_type, 
                $provider_name, $provider_role, $user_id,
                $doctor_note);
        }
    }
    
    if ($stmt->execute()) {
        $success_message = ucfirst($diagnosis_type) . " notes added successfully!";
        header("Location: view_record.php?type=" . urlencode($type) . "&id=" . urlencode($id) . "&success=" . urlencode($success_message));
        exit();
    } else {
        $error_message = "Error adding notes: " . $conn->error;
    }
    $stmt->close();
}

// Handle Medical Diagnosis Deletion
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['delete_medical_diagnosis'])) {
    $diagnosis_id = $_POST['diagnosis_id'];
    
    // Check if diagnosis exists and get provider info
    $check_query = $conn->prepare("SELECT provider_id FROM medical_diagnoses WHERE id = ?");
    $check_query->bind_param("i", $diagnosis_id);
    $check_query->execute();
    $check_result = $check_query->get_result();
    
    if ($check_result->num_rows > 0) {
        $diagnosis_data = $check_result->fetch_assoc();
        
        // Allow deletion if user is the original provider or an admin
        if ($diagnosis_data['provider_id'] == $_SESSION['user_id'] || $user_role === 'admin') {
            $delete_stmt = $conn->prepare("DELETE FROM medical_diagnoses WHERE id = ?");
            $delete_stmt->bind_param("i", $diagnosis_id);
            
            if ($delete_stmt->execute()) {
                $success_message = "Notes deleted successfully!";
                header("Location: view_record.php?type=" . urlencode($type) . "&id=" . urlencode($id) . "&success=" . urlencode($success_message));
                exit();
            } else {
                $error_message = "Error deleting notes: " . $conn->error;
            }
            $delete_stmt->close();
        } else {
            $error_message = "You are not authorized to delete these notes.";
        }
    }
    $check_query->close();
}

// Fetch recent diagnoses for the patient - MODIFIED: Get all diagnoses
if ($record && isset($record['patient_id'])) {
    $stmt = $conn->prepare("
        SELECT md.*, u.full_name as provider_full_name, u.role as provider_role
        FROM medical_diagnoses md 
        LEFT JOIN users u ON BINARY u.username = md.provider_name
        WHERE md.patient_id = ?
        ORDER BY md.diagnosis_date DESC 
        LIMIT 3
    ");
    $stmt->bind_param("i", $record['patient_id']);
    $stmt->execute();
    $recent_diagnoses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Helper function for rendering editable fields
function render_editable_field($record, $field, $is_editable, $is_checkbox = false, $input_type = 'text', $disabled = false) {
    $value = htmlspecialchars($record[$field] ?? '');
    $editable_class = $is_editable ? 'nurse-editable' : '';
    $readonly_attr = !$is_editable || $disabled ? 'readonly' : '';
    $disabled_attr = !$is_editable || $disabled ? 'disabled' : '';
    $checkbox_status = $record[$field] ?? 0;

    if ($is_checkbox) {
        $checked_attr = $checkbox_status ? 'checked' : '';
        return '<input type="checkbox" name="' . $field . '" value="1" class="mr-2 h-4 w-4 text-maroon border-maroon rounded focus:ring-maroon ' . $editable_class . '" ' . $checked_attr . ' ' . $disabled_attr . '>';
    }

    if ($input_type === 'textarea') {
        return '<textarea name="' . $field . '" rows="3" class="w-full rounded border border-maroon px-3 py-2 text-sm ' . $editable_class . '" ' . $readonly_attr . ' ' . $disabled_attr . '>' . $value . '</textarea>';
    }

    if ($input_type === 'date') {
        return '<input type="date" name="' . $field . '" value="' . $value . '" class="w-full rounded border border-maroon px-3 py-2 text-sm ' . $editable_class . '" ' . $readonly_attr . ' ' . $disabled_attr . '>';
    }

    // For text fields, ensure proper input type
    if ($input_type === 'number') {
        return '<input type="number" name="' . $field . '" value="' . $value . '" class="w-full rounded border border-maroon px-3 py-2 text-sm ' . $editable_class . '" ' . $readonly_attr . ' ' . $disabled_attr . '>';
    }

    return '<input type="' . $input_type . '" name="' . $field . '" value="' . $value . '" class="w-full rounded border border-maroon px-3 py-2 text-sm ' . $editable_class . '" ' . $readonly_attr . ' ' . $disabled_attr . '>';
}

// Helper function for dental checkbox display/status
function display_dental_status($record, $field, $is_editable) {
    if ($is_editable) {
        return render_editable_field($record, $field, $is_editable, true);
    }
    
    // Non-editable display
    if (!isset($record[$field])) return 'N/A';
    $status = $record[$field] ?? 0;
    return '<span class="font-semibold ' . ($status ? 'text-red-600' : 'text-green-600') . '">' . ($status ? 'YES' : 'NO') . '</span>';
}

function decodeDentalCheckboxValue($raw)
{
    if (empty($raw)) {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        if (is_array($decoded)) {
            return array_map('strval', $decoded);
        }
        if (is_string($decoded)) {
            return [$decoded];
        }
    }

    if (is_string($raw)) {
        $parts = array_filter(array_map('trim', explode(',', $raw)));
        return $parts;
    }

    return [];
}

function hydrateDentalCheckboxFlags(array &$record, string $column, array $fieldMap): void
{
    $selected = decodeDentalCheckboxValue($record[$column] ?? '');
    foreach ($fieldMap as $field => $label) {
        $record[$field] = in_array($label, $selected, true) ? 1 : 0;
    }
}

function buildDentalCheckboxPayload(array $fieldMap, array $source): string
{
    $selected = [];
    foreach ($fieldMap as $field => $label) {
        if (!empty($source[$field])) {
            $selected[] = $label;
        }
    }
    return json_encode($selected);
}

// Helper function for medical form fields
function render_medical_field($record, $field, $label, $is_editable, $input_type = 'text') {
    echo '<div>';
    echo '<label class="block font-medium mb-1 text-maroon">' . $label . '</label>';
    echo render_editable_field($record, $field, $is_editable, false, $input_type);
    echo '</div>';
}

// Helper function for history form radio buttons
function render_history_radio($record, $field, $is_editable) {
    $value = $record[$field] ?? 0;
    $editable_class = $is_editable ? 'nurse-editable' : '';
    $disabled_attr = !$is_editable ? 'disabled' : '';
    
    $html = '<div class="flex gap-4">';
    $html .= '<label class="flex items-center gap-2">';
    $html .= '<input type="radio" name="' . $field . '" value="1" class="h-4 w-4 text-maroon ' . $editable_class . '" ' . ($value ? 'checked' : '') . ' ' . $disabled_attr . '> Yes';
    $html .= '</label>';
    $html .= '<label class="flex items-center gap-2">';
    $html .= '<input type="radio" name="' . $field . '" value="0" class="h-4 w-4 text-maroon ' . $editable_class . '" ' . (!$value ? 'checked' : '') . ' ' . $disabled_attr . '> No';
    $html .= '</div>';
    
    return $html;
}

// Helper function for history form examination table rows
function render_history_exam_row($record, $field_normal, $field_findings, $label, $is_editable) {
    echo '<tr class="hover:bg-maroon-50">';
    echo '<td class="border border-maroon-200 px-4 py-2 text-maroon">' . $label . '</td>';
    echo '<td class="border border-maroon-200 px-4 py-2">';
    echo render_editable_field($record, $field_findings, $is_editable);
    echo '</td>';
    echo '</tr>';
}

// Function to generate interactive dental chart visualization
function generateDentalChartVisualization($dentalChartData) {
    // Always try to decode the data
    $chartData = [];
    
    if (!empty($dentalChartData) && $dentalChartData !== 'null' && $dentalChartData !== '""' && $dentalChartData !== '[]') {
        $decoded = json_decode($dentalChartData, true);
        if (is_array($decoded)) {
            $chartData = $decoded;
        }
    }
    
    // For dental forms, always show the interactive chart
    // If no data exists, use empty array which will show all teeth as "none"
    if (empty($chartData)) {
        $chartData = [];
    }
    
    return generateInteractiveDentalChart($chartData);
}

// Function to generate the interactive dental chart HTML
function generateInteractiveDentalChart($chartData) {
    // Ensure chartData is always an array
    if (!is_array($chartData)) {
        $chartData = [];
    }
    
    $html = '
    <style>
        .grid-cols-16 {
            grid-template-columns: repeat(16, minmax(0, 1fr));
        }
        
        .tooth {
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .tooth-upper {
            border-radius: 50% 50% 0 0;
        }
        
        .tooth-lower {
            border-radius: 0 0 50% 50%;
        }
        
        .tooth:hover {
            transform: scale(1.1);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 10;
        }
        
        .tooth.selected {
            box-shadow: 0 0 0 2px #800000;
        }
        
        .tooth-label {
            font-size: 0.5rem;
            font-weight: 600;
        }
        
        @media (max-width: 768px) {
            .grid-cols-16 {
                grid-template-columns: repeat(8, minmax(0, 1fr));
            }
            
            .tooth {
                width: 2rem;
                height: 3rem;
            }
            
            .tooth-label {
                font-size: 0.4rem;
            }
        }

        .tooth-condition {
            font-size: 0.5rem;
            margin-top: 0.25rem;
            font-weight: bold;
            min-height: 0.75rem;
        }
        
        /* FIX: Ensure colors show properly */
        .bg-green-500 { background-color: #10b981 !important; }
        .bg-red-500 { background-color: #ef4444 !important; }
        .bg-blue-500 { background-color: #3b82f6 !important; }
        .bg-yellow-500 { background-color: #eab308 !important; }
        .bg-purple-500 { background-color: #8b5cf6 !important; }
        .bg-white { background-color: #ffffff !important; }
    </style>

    <div class="mb-6">
        <div class="flex justify-between items-center mb-3">
            <h4 class="font-semibold text-maroon">Interactive Dental Chart</h4>
            <div class="flex gap-2">
                
            </div>
        </div>
        
        <!-- Dental Chart Controls -->
        <div class="flex flex-wrap gap-4 mb-4 p-4 bg-maroon-50 rounded-lg border border-maroon-200">
            <div class="flex items-center gap-2">
                <div class="w-3 h-3 bg-red-500 rounded"></div>
                <span class="text-sm font-medium text-maroon">Caries (Cavity)</span>
            </div>
            <div class="flex items-center gap-2">
                <div class="w-3 h-3 bg-blue-500 rounded"></div>
                <span class="text-sm font-medium text-maroon">Filling</span>
            </div>
            <div class="flex items-center gap-2">
                <div class="w-3 h-3 bg-yellow-500 rounded"></div>
                <span class="text-sm font-medium text-maroon">Extraction Needed</span>
            </div>
            <div class="flex items-center gap-2">
                <div class="w-3 h-3 bg-green-500 rounded"></div>
                <span class="text-sm font-medium text-maroon">Healthy</span>
            </div>
            <div class="flex items-center gap-2">
                <div class="w-3 h-3 bg-purple-500 rounded"></div>
                <span class="text-sm font-medium text-maroon">Crown/Bridge</span>
            </div>
            <div class="flex items-center gap-2 ml-4">
                <div class="w-3 h-3 border-2 border-maroon bg-maroon-100 rounded"></div>
                <span class="text-sm font-medium text-maroon">Click to change condition</span>
            </div>
        </div>

        <!-- Dental Chart Container -->
        <div class="bg-white border-2 border-maroon-200 rounded-xl p-6 mb-4">
            <!-- Maxillary (Upper) Teeth -->
            <div class="mb-8">
                <h5 class="text-center font-semibold mb-4 text-maroon bg-maroon-100 py-1 rounded">Maxillary (Upper Jaw)</h5>
                <div class="grid grid-cols-16 gap-1 justify-center mb-2" id="maxillary-teeth">
                    ' . generateToothSection(1, 16, 'maxillary', $chartData) . '
                </div>
            </div>

            <!-- Mandibular (Lower) Teeth -->
            <div>
                <h5 class="text-center font-semibold mb-4 text-maroon bg-maroon-100 py-1 rounded">Mandibular (Lower Jaw)</h5>
                <div class="grid grid-cols-16 gap-1 justify-center mb-2" id="mandibular-teeth">
                    ' . generateToothSection(17, 32, 'mandibular', $chartData) . '
                </div>
            </div>
        </div>

        <!-- Selected Teeth Summary -->
        <div class="bg-blue-50 p-4 rounded-lg border border-blue-200 mb-4">
            <h5 class="font-semibold mb-2 text-blue-700">Dental Conditions Summary</h5>
            <div id="selected-teeth-summary" class="text-sm text-blue-600">
                ' . generateDentalSummary($chartData) . '
            </div>
        </div>
    </div>';

    return $html;
}

// Function to generate tooth section
function generateToothSection($start, $end, $jaw, $chartData) {
    $html = '';
    $isUpper = $jaw === 'maxillary';
    
    for ($i = $start; $i <= $end; $i++) {
        $toothName = getToothName($i);
        $toothData = $chartData[$i] ?? null;
        $condition = $toothData['condition'] ?? 'none';
        $conditionLabel = $toothData['label'] ?? '';
        $conditionText = $toothData['text'] ?? 'None';
        
        // Determine initial color based on condition
        $initialColor = 'bg-white text-gray-800 border-2 border-gray-300';
        $textColor = 'text-gray-800';
        
        if ($condition !== 'none') {
            $colorMap = [
                'healthy' => ['bg' => 'bg-green-500', 'text' => 'text-white', 'border' => 'border-green-600'],
                'caries' => ['bg' => 'bg-red-500', 'text' => 'text-white', 'border' => 'border-red-600'],
                'filling' => ['bg' => 'bg-blue-500', 'text' => 'text-white', 'border' => 'border-blue-600'],
                'extraction' => ['bg' => 'bg-yellow-500', 'text' => 'text-gray-800', 'border' => 'border-yellow-600'],
                'crown' => ['bg' => 'bg-purple-500', 'text' => 'text-white', 'border' => 'border-purple-600']
            ];
            
            if (isset($colorMap[$condition])) {
                $colorInfo = $colorMap[$condition];
                $initialColor = $colorInfo['bg'] . ' ' . $colorInfo['text'] . ' ' . $colorInfo['border'];
            }
        }
        
        $html .= '
            <div class="tooth-container flex flex-col items-center" data-tooth="' . $i . '">
                <div class="tooth-number text-xs font-semibold text-maroon mb-1">' . $i . '</div>
                <div class="tooth ' . ($isUpper ? 'tooth-upper' : 'tooth-lower') . ' 
                    w-8 h-12 rounded-lg cursor-pointer transition-all duration-200 
                    hover:scale-110 hover:shadow-md ' . $initialColor . ' flex items-center justify-center"
                    data-tooth="' . $i . '"
                    onclick="toggleToothCondition(this)">
                    <span class="tooth-label text-xs font-medium">' . $toothName . '</span>
                </div>
                <div class="tooth-condition text-xs mt-1 text-center min-h-[16px] font-semibold">' . $conditionLabel . '</div>
            </div>
        ';
    }
    return $html;
}

// Helper function to get tooth name
function getToothName($toothNumber) {
    $toothNames = [
        1 => 'M3', 2 => 'M2', 3 => 'M1', 4 => 'P2', 5 => 'P1', 6 => 'C', 7 => 'I2', 8 => 'I1',
        9 => 'I1', 10 => 'I2', 11 => 'C', 12 => 'P1', 13 => 'P2', 14 => 'M1', 15 => 'M2', 16 => 'M3',
        17 => 'M3', 18 => 'M2', 19 => 'M1', 20 => 'P2', 21 => 'P1', 22 => 'C', 23 => 'I2', 24 => 'I1',
        25 => 'I1', 26 => 'I2', 27 => 'C', 28 => 'P1', 29 => 'P2', 30 => 'M1', 31 => 'M2', 32 => 'M3'
    ];
    return $toothNames[$toothNumber] ?? $toothNumber;
}

// Function to generate dental summary
function generateDentalSummary($chartData) {
    if (empty($chartData)) {
        return 'No dental conditions recorded. Click on teeth to mark conditions.';
    }

    $conditions = [];
    foreach ($chartData as $toothNumber => $data) {
        $condition = $data['condition'] ?? 'unknown';
        if ($condition !== 'none' && $condition !== '') {
            if (!isset($conditions[$condition])) {
                $conditions[$condition] = [];
            }
            $conditions[$condition][] = $toothNumber;
        }
    }

    if (empty($conditions)) {
        return 'All teeth are healthy or no conditions marked.';
    }

    $summaryHTML = '<div class="grid grid-cols-1 md:grid-cols-2 gap-2">';
    
    $conditionInfo = [
        'healthy' => ['color' => 'bg-green-500', 'text' => 'Healthy'],
        'caries' => ['color' => 'bg-red-500', 'text' => 'Caries (Cavity)'],
        'filling' => ['color' => 'bg-blue-500', 'text' => 'Filling'],
        'extraction' => ['color' => 'bg-yellow-500', 'text' => 'Extraction Needed'],
        'crown' => ['color' => 'bg-purple-500', 'text' => 'Crown/Bridge']
    ];

    foreach ($conditions as $condition => $teeth) {
        $info = $conditionInfo[$condition] ?? ['color' => 'bg-gray-500', 'text' => ucfirst($condition)];
        $summaryHTML .= '
            <div class="flex items-center gap-2 p-2 bg-white rounded border border-maroon-200">
                <div class="w-3 h-3 rounded-full ' . $info['color'] . '"></div>
                <span class="font-medium text-maroon">' . $info['text'] . ':</span>
                <span class="text-maroon">Teeth ' . implode(', ', $teeth) . '</span>
            </div>
        ';
    }

    $summaryHTML .= '</div>';
    return $summaryHTML;
}

// Function to format dental chart data in an organized table
function formatDentalChartData($dentalChartData) {
    // Handle empty or null data - be more lenient with checks
    if ($dentalChartData === null || $dentalChartData === '') {
        return '<div class="text-center py-4 text-gray-500">No dental chart data recorded.</div>';
    }
    
    // Trim and check for empty strings
    $dentalChartData = trim($dentalChartData);
    if ($dentalChartData === '' || $dentalChartData === 'null' || $dentalChartData === '""' || $dentalChartData === '[]' || $dentalChartData === '{}') {
        return '<div class="text-center py-4 text-gray-500">No dental chart data recorded.</div>';
    }
    
    // Try to decode JSON
    $chartData = json_decode($dentalChartData, true);
    
    // If json_decode failed, try to handle it as a string that might be double-encoded
    if ($chartData === null && json_last_error() !== JSON_ERROR_NONE) {
        // Try decoding again if it's a string representation
        $decoded = json_decode(stripslashes($dentalChartData), true);
        if ($decoded !== null && is_array($decoded)) {
            $chartData = $decoded;
        } else {
            // If still failing, return error message
            return '<div class="text-center py-4 text-red-500">Error parsing dental chart data. Please check the data format.</div>';
        }
    }
    
    // Check if we have valid array data
    if (!is_array($chartData)) {
        return '<div class="text-center py-4 text-gray-500">No dental chart data recorded.</div>';
    }
    
    // If array is empty, return message
    if (empty($chartData)) {
        return '<div class="text-center py-4 text-gray-500">No dental chart data recorded.</div>';
    }
    
    // Group teeth by condition
    $conditions = [];
    foreach ($chartData as $toothNumber => $data) {
        // Handle both array and object formats
        if (is_array($data)) {
            $condition = $data['condition'] ?? 'none';
            $label = $data['label'] ?? '';
            $text = $data['text'] ?? ucfirst($condition);
        } elseif (is_string($data)) {
            // Handle case where data might just be a string condition
            $condition = $data;
            $label = '';
            $text = ucfirst($condition);
        } else {
            continue;
        }
        
        if ($condition !== 'none' && $condition !== '' && $condition !== null) {
            if (!isset($conditions[$condition])) {
                $conditions[$condition] = [
                    'teeth' => [],
                    'label' => $label,
                    'text' => $text
                ];
            }
            $conditions[$condition]['teeth'][] = $toothNumber;
        }
    }
    
    if (empty($conditions)) {
        return '<div class="text-center py-4 text-gray-500">All teeth are healthy or no conditions marked.</div>';
    }
    
    // Condition info mapping
    $conditionInfo = [
        'healthy' => ['color' => 'bg-green-100', 'border' => 'border-green-300', 'text_color' => 'text-green-800', 'icon' => '✓'],
        'caries' => ['color' => 'bg-red-100', 'border' => 'border-red-300', 'text_color' => 'text-red-800', 'icon' => '⚠'],
        'filling' => ['color' => 'bg-blue-100', 'border' => 'border-blue-300', 'text_color' => 'text-blue-800', 'icon' => '◉'],
        'extraction' => ['color' => 'bg-yellow-100', 'border' => 'border-yellow-300', 'text_color' => 'text-yellow-800', 'icon' => '✕'],
        'crown' => ['color' => 'bg-purple-100', 'border' => 'border-purple-300', 'text_color' => 'text-purple-800', 'icon' => '◈']
    ];
    
    $html = '<div class="space-y-3">';
    
    foreach ($conditions as $condition => $data) {
        $info = $conditionInfo[$condition] ?? [
            'color' => 'bg-gray-100',
            'border' => 'border-gray-300',
            'text_color' => 'text-gray-800',
            'icon' => '•'
        ];
        
        sort($data['teeth']); // Sort teeth numbers
        
        $html .= '
            <div class="' . $info['color'] . ' ' . $info['border'] . ' border-2 rounded-lg p-4">
                <div class="flex items-center gap-3 mb-2">
                    <span class="text-xl font-bold ' . $info['text_color'] . '">' . $info['icon'] . '</span>
                    <h5 class="font-semibold text-lg ' . $info['text_color'] . '">' . htmlspecialchars($data['text']) . '</h5>
                    <span class="ml-auto text-sm font-medium ' . $info['text_color'] . '">(' . count($data['teeth']) . ' tooth' . (count($data['teeth']) > 1 ? 'teeth' : '') . ')</span>
                </div>
                <div class="flex flex-wrap gap-2 mt-2">
        ';
        
        foreach ($data['teeth'] as $tooth) {
            $html .= '
                <span class="px-3 py-1 bg-white rounded-full border-2 ' . $info['border'] . ' font-semibold ' . $info['text_color'] . ' text-sm">
                    Tooth #' . $tooth . '
                </span>
            ';
        }
        
        $html .= '
                </div>
            </div>
        ';
    }
    
    $html .= '</div>';
    
    return $html;
}

// Function to get diagnosis button based on user role
function getDiagnosisButton($user_role, $patient_id, $record_id, $type) {
    $buttons = [];
    
    if ($user_role === 'nurse') {
        $buttons[] = [
            'url' => "medical_diagnosis_form.php?patient_id=$patient_id&record_id=$record_id&type=$type&diagnosis_type=nurse",
            'text' => 'Nurse Diagnosis',
            'color' => 'maroon-gradient-button',
            'icon' => 'bi bi-heart-pulse'
        ];
    } elseif ($user_role === 'dentist') {
        $buttons[] = [
            'url' => "medical_diagnosis_form.php?patient_id=$patient_id&record_id=$record_id&type=$type&diagnosis_type=dentist",
            'text' => 'Dental Diagnosis',
            'color' => 'maroon-gradient-button',
            'icon' => 'bi bi-tooth'
        ];
    } elseif ($user_role === 'doctor' || $user_role === 'physician') {
        $buttons[] = [
            'url' => "medical_diagnosis_form.php?patient_id=$patient_id&record_id=$record_id&type=$type&diagnosis_type=doctor",
            'text' => 'Physician Diagnosis',
            'color' => 'maroon-gradient-button',
            'icon' => 'bi bi-heart-pulse'
        ];
    } elseif ($user_role === 'admin' || $user_role === 'staff') {
        // Admin/staff can add all types
        $buttons = [
            [
                'url' => "medical_diagnosis_form.php?patient_id=$patient_id&record_id=$record_id&type=$type&diagnosis_type=nurse",
                'text' => 'Nurse Diagnosis',
                'color' => 'maroon-gradient-button',
                'icon' => 'bi bi-heart-pulse'
            ],
            [
                'url' => "medical_diagnosis_form.php?patient_id=$patient_id&record_id=$record_id&type=$type&diagnosis_type=dentist",
                'text' => 'Dental Diagnosis',
                'color' => 'maroon-gradient-button',
                'icon' => 'bi bi-tooth'
            ],
            [
                'url' => "medical_diagnosis_form.php?patient_id=$patient_id&record_id=$record_id&type=$type&diagnosis_type=doctor",
                'text' => 'Physician Diagnosis',
                'color' => 'maroon-gradient-button',
                'icon' => 'bi bi-heart-pulse'
            ]
        ];
    }
    
    return $buttons;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Record - BSU Clinic</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        /* Custom maroon theme (#800000) */
        :root {
            --maroon-primary: #800000;
            --maroon-dark: #660000;
            --maroon-light: #a00000;
            --maroon-bg: #fff5f5;
        }
        
        .maroon-gradient {
            background: linear-gradient(135deg, var(--maroon-primary), var(--maroon-light));
        }
        
        .maroon-gradient-light {
            background: linear-gradient(135deg, #fff5f5, #ffe5e5);
        }
        
        .maroon-gradient-card {
            background: linear-gradient(135deg, var(--maroon-primary), var(--maroon-light));
        }
        
        .maroon-gradient-button {
            background: linear-gradient(135deg, var(--maroon-primary), var(--maroon-light));
        }
        
        .maroon-gradient-button:hover {
            background: linear-gradient(135deg, var(--maroon-dark), var(--maroon-primary));
        }
        
        .maroon-gradient-alert {
            background: linear-gradient(135deg, #fff5f5, #ffe5e5);
            border-left-color: var(--maroon-primary);
        }
        
        .maroon-table-header {
            background: linear-gradient(135deg, var(--maroon-primary), var(--maroon-light));
        }
        
        .maroon-table-row {
            background: linear-gradient(135deg, #fff5f5, #ffe5e5);
        }
        
        .maroon-table-row:hover {
            background: linear-gradient(135deg, #ffe5e5, #ffcccc);
        }
        
        .maroon-badge {
            background: linear-gradient(135deg, #ffcccc, #ffb3b3);
            color: #800000;
        }
        
        .maroon-badge-verified {
            background: linear-gradient(135deg, #dcfce7, #bbf7d0);
            color: #166534;
        }
        
        .maroon-badge-pending {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            color: #92400e;
        }
        
        .maroon-badge-rejected {
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            color: #991b1b;
        }
        
        .stats-card-1 {
            background: linear-gradient(135deg, var(--maroon-primary), #990000);
        }
        
        .stats-card-2 {
            background: linear-gradient(135deg, #990000, #b30000);
        }
        
        .stats-card-3 {
            background: linear-gradient(135deg, #b30000, #cc0000);
        }
        
        .stats-card-4 {
            background: linear-gradient(135deg, #cc0000, #e60000);
        }
        
        /* Text colors for maroon theme */
        .text-maroon {
            color: var(--maroon-primary);
        }
        
        .text-maroon-light {
            color: var(--maroon-light);
        }
        
        .border-maroon {
            border-color: var(--maroon-primary);
        }
        
        .bg-maroon-light {
            background-color: #fff5f5;
        }
        
        /* Form sections */
        .exam-section {
            border-left: 4px solid var(--maroon-primary);
            background: linear-gradient(135deg, #fff5f5, #ffe5e5);
        }
        
        .medical-section {
            border-left: 4px solid var(--maroon-primary);
            background: linear-gradient(135deg, #fff5f5, #ffe5e5);
        }
        
        .history-section {
            border-left: 4px solid var(--maroon-primary);
            background: linear-gradient(135deg, #fff5f5, #ffe5e5);
        }
        
        .dental-section {
            border-left: 4px solid var(--maroon-primary);
            background: linear-gradient(135deg, #fff5f5, #ffe5e5);
        }
        
        .certification-section {
            border-left: 4px solid #10b981;
            background: #d1fae5;
        }
        
        .diagnosis-note-section {
            border-left: 4px solid #3b82f6;
            background: #dbeafe;
        }
        
        .medical-diagnoses-section {
            border-left: 4px solid #3b82f6;
            background: #dbeafe;
        }
        
        .diagnosis-badge-nurse { background-color: #3b82f6; }
        .diagnosis-badge-dentist { background-color: #10b981; }
        .diagnosis-badge-doctor { background-color: #ef4444; }
        .severity-mild { background-color: #10b981; }
        .severity-moderate { background-color: #f59e0b; }
        .severity-severe { background-color: #ef4444; }
        .severity-critical { background-color: #7c3aed; }
        
        .nurse-editable {
            background-color: #ffe5e5 !important;
            border: 1px dashed var(--maroon-primary) !important;
        }
        
        @media print {
            .no-print { display: none; }
            body { background: white; }
        }
        
        /* Pulsing badge animation */
        @keyframes pulse-badge {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }
        
        .pulse-badge {
            animation: pulse-badge 2s infinite;
        }
        
        /* Add severity badge styles */
        .severity-mild {
            background-color: #d1fae5;
            color: #065f46;
        }
        .severity-moderate {
            background-color: #fef3c7;
            color: #92400e;
        }
        .severity-severe {
            background-color: #fee2e2;
            color: #991b1b;
        }
        .severity-critical {
            background-color: #f3e8ff;
            color: #5b21b6;
        }
    </style>
</head>
<body class="bg-maroon-light">

<div class="min-h-screen py-10 px-6">
    <div class="max-w-6xl mx-auto bg-white shadow-lg rounded-lg p-8">
        
        <div class="flex justify-between items-center mb-6 no-print">
            <div class="flex gap-2">
                <!-- ✅ FIXED BACK BUTTON - Dynamic based on where user came from -->
                <a href="<?= htmlspecialchars($back_url) ?>"
                   class="inline-flex items-center gap-2 maroon-gradient-button text-white font-semibold px-4 py-2 rounded-lg shadow transition-all">
                   <i class="bi bi-arrow-left-circle"></i> Back
                </a>
                
            </div>
            <h2 class="text-2xl font-bold text-maroon">View Record Details</h2>
            <?php if ($is_nurse): ?>
                <span class="bg-maroon-light text-maroon px-3 py-1 rounded-full text-sm font-semibold">
                    <i class="bi bi-shield-check"></i> <?= ucwords($user_role) ?> Mode
                </span>
            <?php endif; ?>
        </div>

        <?php if ($success_message): ?>
            <div class="mb-4 p-3 bg-green-100 text-green-800 text-center rounded font-semibold border border-green-300 no-print">
                <i class="bi bi-check-circle-fill mr-2"></i><?= htmlspecialchars($success_message) ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error_message): ?>
            <div class="mb-4 p-3 bg-red-100 text-red-800 text-center rounded font-semibold border border-red-300 no-print">
                <i class="bi bi-exclamation-triangle-fill mr-2"></i><?= htmlspecialchars($error_message) ?>
            </div>
        <?php endif; ?>

        <?php if ($record): ?>
     <!-- Form Type Display -->
<div class="mb-6 no-print">
    <div class="inline-block px-4 py-2 rounded-lg shadow font-semibold text-white 
        <?php 
        if ($type === 'history_form') {
            echo 'bg-gray-700';
        } elseif (in_array($type, ['medical_form', 'medical_exam'])) {
            echo 'bg-[#800000]'; // Maroon color
        } elseif (in_array($type, ['dental_form', 'dental_exam'])) {
            echo 'bg-green-700';
        } else {
            echo 'bg-gray-700';
        }
        ?>">
        <i class="bi bi-file-text mr-2"></i>
        <?php
        if ($type === 'history_form') {
            echo 'MEDICAL HISTORY FORM';
        } elseif (in_array($type, ['medical_form', 'medical_exam'])) {
            echo 'MEDICAL EXAMINATION FORM';
        } elseif (in_array($type, ['dental_form', 'dental_exam'])) {
            echo 'DENTAL EXAMINATION FORM';
        } else {
            echo strtoupper(str_replace('_', ' ', $type));
        }
        ?>
    </div>
</div>
            
            <form method="POST" id="record-form">
                <input type="hidden" name="record_id" value="<?= $record['record_id']; ?>">
                <input type="hidden" name="record_type" value="<?= $type; ?>">
                
                <div class="bg-maroon-light rounded-lg p-4 mb-6 border-l-4 border-maroon">
                    <h3 class="font-semibold text-lg mb-3 text-maroon">Patient Information (Record ID: <?= $record['record_id'] ?>)</h3>
                    
                    <?php if ($type === 'medical_form' || $type === 'medical_exam'): ?>
                        <!-- Medical Exam Patient Info Layout -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4">
                            <!-- Left Side -->
                            <div class="space-y-3">
                                <div class="flex items-start">
                                    <span class="font-semibold text-maroon w-32">Last Name:</span>
                                    <span class="text-gray-800"><?= htmlspecialchars(ucwords(strtolower($record['last_name']))); ?></span>
                                </div>
                                <div class="flex items-start">
                                    <span class="font-semibold text-maroon w-32">First Name:</span>
                                    <span class="text-gray-800"><?= htmlspecialchars(ucwords(strtolower($record['first_name']))); ?></span>
                                </div>
                                <div class="flex items-start">
                                    <span class="font-semibold text-maroon w-32">Middle Name:</span>
                                    <span class="text-gray-800"><?= !empty($record['middle_name']) ? htmlspecialchars(ucwords(strtolower($record['middle_name']))) : 'N/A'; ?></span>
                                </div>
                                <div class="flex items-start">
                                    <span class="font-semibold text-maroon w-32">Sex:</span>
                                    <span class="text-gray-800"><?= htmlspecialchars($record['sex']); ?></span>
                                </div>
                                <div class="flex items-start">
                                    <span class="font-semibold text-maroon w-32">Cellphone No.:</span>
                                    <span class="text-gray-800"><?= !empty($record['contact_number']) ? htmlspecialchars($record['contact_number']) : 'N/A'; ?></span>
                                </div>
                                <div class="flex items-start">
                                    <span class="font-semibold text-maroon w-32">Address:</span>
                                    <span class="text-gray-800"><?= !empty($record['address']) ? htmlspecialchars($record['address']) : 'N/A'; ?></span>
                                </div>
                            </div>
                            
                            <!-- Right Side -->
                            <div class="space-y-3">
                                <div class="flex items-start">
                                    <span class="font-semibold text-maroon w-32">Date:</span>
                                    <span class="text-gray-800"><?= htmlspecialchars($record['examination_date'] ?? date('Y-m-d')); ?></span>
                                </div>
                                <div class="flex items-start">
                                    <span class="font-semibold text-maroon w-32">Birthday:</span>
                                    <span class="text-gray-800"><?= htmlspecialchars($record['date_of_birth']); ?></span>
                                </div>
                                <div class="flex items-start">
                                    <span class="font-semibold text-maroon w-32">Age:</span>
                                    <span class="text-gray-800">
                                        <?php
                                        if (!empty($record['date_of_birth'])) {
                                            $birthDate = new DateTime($record['date_of_birth']);
                                            $today = new DateTime();
                                            $age = $today->diff($birthDate)->y;
                                            echo $age . ' years old';
                                        } else {
                                            echo 'N/A';
                                        }
                                        ?>
                                    </span>
                                </div>
                                <div class="flex items-start">
                                    <span class="font-semibold text-maroon w-32">Civil Status:</span>
                                    <span class="text-gray-800"><?= !empty($record['civil_status']) ? htmlspecialchars(ucwords(strtolower($record['civil_status']))) : 'N/A'; ?></span>
                                </div>
                                <div class="flex items-start">
                                    <span class="font-semibold text-maroon w-32">Tel. No.:</span>
                                    <span class="text-gray-800"><?= !empty($record['contact_number']) ? htmlspecialchars($record['contact_number']) : 'N/A'; ?></span>
                                </div>
                                <div class="flex items-start">
                                    <span class="font-semibold text-maroon w-32">Program:</span>
                                    <span class="text-gray-800"><?= htmlspecialchars($record['program']); ?></span>
                                </div>
                            </div>
                        <!-- END CERTIFICATION SECTION -->

       
    </div> <!-- This closes the medical-section div -->

<?php elseif ($type === 'dental_form' || $type === 'dental_exam'): ?>
    <!-- FIXED: Added Patient Information Section for Dental Forms -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4">
        <!-- Left Side -->
        <div class="space-y-3">
            <div class="flex items-start">
                <span class="font-semibold text-maroon w-32">Last Name:</span>
                <span class="text-gray-800"><?= htmlspecialchars(ucwords(strtolower($record['last_name']))); ?></span>
            </div>
            <div class="flex items-start">
                <span class="font-semibold text-maroon w-32">First Name:</span>
                <span class="text-gray-800"><?= htmlspecialchars(ucwords(strtolower($record['first_name']))); ?></span>
            </div>
            <div class="flex items-start">
                <span class="font-semibold text-maroon w-32">Middle Name:</span>
                <span class="text-gray-800"><?= !empty($record['middle_name']) ? htmlspecialchars(ucwords(strtolower($record['middle_name']))) : 'N/A'; ?></span>
            </div>
            <div class="flex items-start">
                <span class="font-semibold text-maroon w-32">Sex:</span>
                <span class="text-gray-800"><?= htmlspecialchars($record['sex']); ?></span>
            </div>
        </div>
        
        <!-- Right Side -->
        <div class="space-y-3">
            <div class="flex items-start">
                <span class="font-semibold text-maroon w-32">Date of Birth:</span>
                <span class="text-gray-800"><?= htmlspecialchars($record['date_of_birth']); ?></span>
            </div>
            <div class="flex items-start">
                <span class="font-semibold text-maroon w-32">Age:</span>
                <span class="text-gray-800">
                    <?php
                    if (!empty($record['date_of_birth'])) {
                        $birthDate = new DateTime($record['date_of_birth']);
                        $today = new DateTime();
                        $age = $today->diff($birthDate)->y;
                        echo $age . ' years old';
                    } else {
                        echo 'N/A';
                    }
                    ?>
                </span>
            </div>
            <div class="flex items-start">
                <span class="font-semibold text-maroon w-32">Civil Status:</span>
                <span class="text-gray-800"><?= !empty($record['civil_status']) ? htmlspecialchars(ucwords(strtolower($record['civil_status']))) : 'N/A'; ?></span>
            </div>
            <div class="flex items-start">
                <span class="font-semibold text-maroon w-32">Program:</span>
                <span class="text-gray-800"><?= htmlspecialchars($record['program']); ?></span>
            </div>
        </div>
    </div>
    
    <!-- Contact Information -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-4 pt-4 border-t border-maroon-200">
        <div class="flex items-start">
            <span class="font-semibold text-maroon w-32">Contact Number:</span>
            <span class="text-gray-800"><?= !empty($record['contact_number']) ? htmlspecialchars($record['contact_number']) : 'N/A'; ?></span>
        </div>
        <div class="flex items-start">
            <span class="font-semibold text-maroon w-32">Address:</span>
            <span class="text-gray-800"><?= !empty($record['address']) ? htmlspecialchars($record['address']) : 'N/A'; ?></span>
        </div>
    </div>
                        
<?php else: ?>
    <!-- History Form Patient Info Layout (Current Layout) -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4">
        <!-- Left Side -->
        <div class="space-y-3">
            <div class="flex items-start">
                <span class="font-semibold text-maroon w-32">Name:</span>
                <span class="text-gray-800"><?= htmlspecialchars(ucwords(strtolower($record['first_name'] . ' ' . $record['last_name']))); ?></span>
            </div>
            <div class="flex items-start">
                <span class="font-semibold text-maroon w-32">Year/Program:</span>
                <span class="text-gray-800"><?= htmlspecialchars($record['year_level'] . ' / ' . $record['program']); ?></span>
            </div>
            <div class="flex items-start">
                <span class="font-semibold text-maroon w-32">Date of Birth:</span>
                <span class="text-gray-800"><?= htmlspecialchars($record['date_of_birth']); ?></span>
            </div>
        </div>
        
        <!-- Right Side -->
        <div class="space-y-3">
            <div class="flex items-start">
                <span class="font-semibold text-maroon w-32">Date of Examination:</span>
                <span class="text-gray-800"><?= htmlspecialchars($record['examination_date'] ?? 'N/A') ?></span>
            </div>
            <div class="flex items-start">
                <span class="font-semibold text-maroon w-32">Sex:</span>
                <span class="text-gray-800"><?= htmlspecialchars($record['sex']); ?></span>
            </div>
            <div class="flex items-start">
                <span class="font-semibold text-maroon w-32">Age:</span>
                <span class="text-gray-800">
                    <?php
                    if (!empty($record['date_of_birth'])) {
                        $birthDate = new DateTime($record['date_of_birth']);
                        $today = new DateTime();
                        $age = $today->diff($birthDate)->y;
                        echo $age . ' years old';
                    } else {
                        echo 'N/A';
                    }
                    ?>
                </span>
            </div>
        </div>
    </div>
    
    <!-- Sports Event - Placed below all other fields -->
    <div class="pt-3 border-t border-maroon-200 mt-2">
        <div class="flex items-start">
            <span class="font-semibold text-maroon w-32">Sports Event:</span>
            <span class="text-gray-800"><?= htmlspecialchars($record['sports_event'] ?? 'Not specified') ?></span>
            <!-- Hidden input to preserve sports_event value during form submission -->
            <input type="hidden" name="sports_event" value="<?= htmlspecialchars($record['sports_event'] ?? '') ?>">
        </div>
    </div>
<?php endif; ?>

                
                <?php if ($type === 'medical_form' || $type === 'medical_exam'): ?>
    <!-- Medical Examination Details -->
    <div class="medical-section rounded-lg p-4 mb-6">
        <div class="flex justify-between items-center mb-4">
            <h3 class="font-semibold text-lg text-maroon">Medical Examination Details</h3>
            <?php if ($is_nurse): ?>
                <span class="bg-maroon-light text-maroon px-3 py-1 rounded-full text-sm font-semibold">
                    <i class="bi bi-pencil-square"></i> Editable by <?= ucwords($user_role) ?>
                </span>
            <?php endif; ?>
        </div>

        <!-- Examination Date -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
            <div>
                <label class="block font-medium mb-1 text-maroon">Examination Date</label>
                <?= render_editable_field($record, 'examination_date', $is_nurse, false, 'date') ?>
            </div>
        </div>

        <!-- Past Medical History, Family History, Occupational History -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
            <div>
                <label class="block font-medium mb-1 text-maroon">Past Medical History</label>
                <?= render_editable_field($record, 'past_medical_history', $is_nurse, false, 'textarea') ?>
            </div>
            <div>
                <label class="block font-medium mb-1 text-maroon">Family History</label>
                <?= render_editable_field($record, 'family_history', $is_nurse, false, 'textarea') ?>
            </div>
            <div>
                <label class="block font-medium mb-1 text-maroon">Occupational History</label>
                <?= render_editable_field($record, 'occupational_history', $is_nurse, false, 'textarea') ?>
            </div>
        </div>

        <!-- PHYSICAL EXAMINATION Table - FINDINGS ONLY -->
        <div class="mb-6">
            <h4 class="font-semibold mb-3 text-maroon">PHYSICAL EXAMINATION</h4>
            <div class="overflow-x-auto">
                <table class="min-w-full border-2 border-maroon rounded-lg overflow-hidden bg-white shadow mb-6">
                    <thead>
                        <tr class="bg-maroon-light">
                            <th class="border border-maroon px-4 py-2 text-maroon">REVIEW OF SYSTEM</th>
                            <th class="border border-maroon px-4 py-2 text-maroon">FINDINGS</th>
                            <th class="border border-maroon px-4 py-2 text-maroon">REVIEW OF SYSTEM</th>
                            <th class="border border-maroon px-4 py-2 text-maroon">FINDINGS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Row 1 -->
                        <tr class="hover:bg-maroon-50">
                            <td class="border border-maroon px-4 py-2 text-maroon">General Appearance/Body Built (BMI)</td>
                            <td class="border border-maroon px-4 py-2">
                                <?= render_editable_field($record, 'general_appearance_findings', $is_nurse) ?>
                            </td>
                            <td class="border border-maroon px-4 py-2 text-maroon">Chest, Breast, Axilla</td>
                            <td class="border border-maroon px-4 py-2">
                                <?= render_editable_field($record, 'chest_breast_axilla_findings', $is_nurse) ?>
                            </td>
                        </tr>
                        <!-- Row 2 -->
                        <tr class="hover:bg-maroon-50">
                            <td class="border border-maroon px-4 py-2 text-maroon">Skin (Tattoo)</td>
                            <td class="border border-maroon px-4 py-2">
                                <?= render_editable_field($record, 'skin_findings', $is_nurse) ?>
                            </td>
                            <td class="border border-maroon px-4 py-2 text-maroon">Heart</td>
                            <td class="border border-maroon px-4 py-2">
                                <?= render_editable_field($record, 'heart_findings', $is_nurse) ?>
                            </td>
                        </tr>
                        <!-- Row 3 -->
                        <tr class="hover:bg-maroon-50">
                            <td class="border border-maroon px-4 py-2 text-maroon">Head and Scalp</td>
                            <td class="border border-maroon px-4 py-2">
                                <?= render_editable_field($record, 'head_scalp_findings', $is_nurse) ?>
                            </td>
                            <td class="border border-maroon px-4 py-2 text-maroon">Lungs</td>
                            <td class="border border-maroon px-4 py-2">
                                <?= render_editable_field($record, 'lungs_findings', $is_nurse) ?>
                            </td>
                        </tr>
                        <!-- Row 4 -->
                        <tr class="hover:bg-maroon-50">
                            <td class="border border-maroon px-4 py-2 text-maroon">Eyes (External)</td>
                            <td class="border border-maroon px-4 py-2">
                                <?= render_editable_field($record, 'eyes_findings', $is_nurse) ?>
                            </td>
                            <td class="border border-maroon px-4 py-2 text-maroon">Abdomen</td>
                            <td class="border border-maroon px-4 py-2">
                                <?= render_editable_field($record, 'abdomen_findings', $is_nurse) ?>
                            </td>
                        </tr>
                        <!-- Row 5 -->
                        <tr class="hover:bg-maroon-50">
                            <td class="border border-maroon px-4 py-2 text-maroon">Ears (Piercing)</td>
                            <td class="border border-maroon px-4 py-2">
                                <?= render_editable_field($record, 'ears_findings', $is_nurse) ?>
                            </td>
                            <td class="border border-maroon px-4 py-2 text-maroon">Anus, Rectum</td>
                            <td class="border border-maroon px-4 py-2">
                                <?= render_editable_field($record, 'anus_rectum_findings', $is_nurse) ?>
                            </td>
                        </tr>
                        <!-- Row 6 -->
                        <tr class="hover:bg-maroon-50">
                            <td class="border border-maroon px-4 py-2 text-maroon">Nose and Throat</td>
                            <td class="border border-maroon px-4 py-2">
                                <?= render_editable_field($record, 'nose_throat_findings', $is_nurse) ?>
                            </td>
                            <td class="border border-maroon px-4 py-2 text-maroon">Genital</td>
                            <td class="border border-maroon px-4 py-2">
                                <?= render_editable_field($record, 'genital_findings', $is_nurse) ?>
                            </td>
                        </tr>
                        <!-- Row 7 -->
                        <tr class="hover:bg-maroon-50">
                            <td class="border border-maroon px-4 py-2 text-maroon">Mouth</td>
                            <td class="border border-maroon px-4 py-2">
                                <?= render_editable_field($record, 'mouth_findings', $is_nurse) ?>
                            </td>
                            <td class="border border-maroon px-4 py-2 text-maroon">Musculo-Skeletal</td>
                            <td class="border border-maroon px-4 py-2">
                                <?= render_editable_field($record, 'musculo_skeletal_findings', $is_nurse) ?>
                            </td>
                        </tr>
                        <!-- Row 8 -->
                        <tr class="hover:bg-maroon-50">
                            <td class="border border-maroon px-4 py-2 text-maroon">Neck, Thyroid, LN</td>
                            <td class="border border-maroon px-4 py-2">
                                <?= render_editable_field($record, 'neck_thyroid_ln_findings', $is_nurse) ?>
                            </td>
                            <td class="border border-maroon px-4 py-2 text-maroon">Extremities</td>
                            <td class="border border-maroon px-4 py-2">
                                <?= render_editable_field($record, 'extremities_findings', $is_nurse) ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- DIAGNOSTIC EXAMINATION - Fixed Layout -->
        <div class="mb-6">
            <h4 class="font-semibold mb-3 text-maroon">DIAGNOSTIC EXAMINATION</h4>
            
            <!-- Hidden fields for database storage -->
            <input type="hidden" name="chest_xray_normal" value="<?= $record['chest_xray_normal'] ?? 1 ?>">
            <input type="hidden" name="cbc_normal" value="<?= $record['cbc_normal'] ?? 1 ?>">
            <input type="hidden" name="urinalysis_normal" value="<?= $record['urinalysis_normal'] ?? 1 ?>">
            <input type="hidden" name="stool_normal" value="<?= $record['stool_normal'] ?? 1 ?>">
            <input type="hidden" name="hepa_b_normal" value="<?= $record['hepa_b_normal'] ?? 1 ?>">
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <!-- LEFT COLUMN -->
                <div class="space-y-6">
                    <!-- Blood Pressure - FIXED: Should be text, not number -->
                    <div class="grid grid-cols-1 gap-1">
                        <label class="block font-medium mb-1 text-maroon">BLOOD PRESSURE</label>
                        <?= render_editable_field($record, 'blood_pressure', $is_nurse, false, 'text') ?>
                    </div>
                    
                    <!-- Heart Rate - FIXED: Should be number -->
                    <div class="grid grid-cols-1 gap-1">
                        <label class="block font-medium mb-1 text-maroon">HEART RATE</label>
                        <?= render_editable_field($record, 'heart_rate', $is_nurse, false, 'number') ?>
                    </div>
                    
                    <!-- Hearing -->
                    <div>
                        <label class="block font-medium mb-2 text-maroon">HEARING</label>
                        <div class="grid grid-cols-2 gap-4">
                            <div class="flex items-center gap-2">
                                <input type="radio" name="hearing_status" value="normal" 
                                       class="h-4 w-4 text-maroon border-maroon rounded focus:ring-maroon <?= $is_nurse ? 'nurse-editable' : '' ?>"
                                       <?= (!isset($record['hearing_defective']) || $record['hearing_defective'] == 0) ? 'checked' : '' ?>
                                       <?= !$is_nurse ? 'disabled' : '' ?>
                                       onchange="document.getElementsByName('hearing_defective')[0].checked = false;">
                                <span class="text-sm">Normal</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <input type="radio" name="hearing_status" value="defective" 
                                       class="h-4 w-4 text-maroon border-maroon rounded focus:ring-maroon <?= $is_nurse ? 'nurse-editable' : '' ?>"
                                       <?= (isset($record['hearing_defective']) && $record['hearing_defective'] == 1) ? 'checked' : '' ?>
                                       <?= !$is_nurse ? 'disabled' : '' ?>
                                       onchange="document.getElementsByName('hearing_defective')[0].checked = true;">
                                <span class="text-sm">Defective</span>
                            </div>
                        </div>
                        <input type="hidden" name="hearing_defective" value="<?= $record['hearing_defective'] ?? 0 ?>">
                        <input type="hidden" name="hearing_normal" value="<?= (!isset($record['hearing_defective']) || $record['hearing_defective'] == 0) ? 1 : 0 ?>">
                    </div>
                    
                    <!-- Vision -->
                    <div>
                        <label class="block font-medium mb-2 text-maroon">VISION</label>
                        <div class="grid grid-cols-2 gap-4">
                            <div class="space-y-3">
                                <div class="flex items-center gap-2">
                                    <input type="radio" name="vision_status" value="with_glasses" 
                                           class="h-4 w-4 text-maroon border-maroon rounded focus:ring-maroon <?= $is_nurse ? 'nurse-editable' : '' ?>"
                                           <?= (isset($record['vision_with_glasses']) && $record['vision_with_glasses'] == 1) ? 'checked' : '' ?>
                                           <?= !$is_nurse ? 'disabled' : '' ?>
                                           onchange="document.getElementsByName('vision_with_glasses')[0].checked = true; document.getElementsByName('vision_without_glasses')[0].checked = false;">
                                    <span class="text-sm">With Glasses</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <input type="radio" name="vision_status" value="without_glasses" 
                                           class="h-4 w-4 text-maroon border-maroon rounded focus:ring-maroon <?= $is_nurse ? 'nurse-editable' : '' ?>"
                                           <?= (isset($record['vision_without_glasses']) && $record['vision_without_glasses'] == 1) ? 'checked' : '' ?>
                                           <?= !$is_nurse ? 'disabled' : '' ?>
                                           onchange="document.getElementsByName('vision_with_glasses')[0].checked = false; document.getElementsByName('vision_without_glasses')[0].checked = true;">
                                    <span class="text-sm">Without Glasses</span>
                                </div>
                            </div>
                            <div class="space-y-3">
                                <div>
                                    <label class="block text-sm mb-1 text-maroon">R:</label>
                                    <?= render_editable_field($record, 'vision_right', $is_nurse, false, 'text') ?>
                                </div>
                                <div>
                                    <label class="block text-sm mb-1 text-maroon">L:</label>
                                    <?= render_editable_field($record, 'vision_left', $is_nurse, false, 'text') ?>
                                </div>
                            </div>
                        </div>
                        <input type="hidden" name="vision_with_glasses" value="<?= $record['vision_with_glasses'] ?? 0 ?>">
                        <input type="hidden" name="vision_without_glasses" value="<?= $record['vision_without_glasses'] ?? 0 ?>">
                    </div>
                    
                    <!-- Chest X-ray -->
                    <div>
                        <label class="block font-medium mb-2 text-maroon">CHEST X-RAY</label>
                        <div class="grid grid-cols-2 gap-4">
                            <div class="space-y-3">
                                <div class="flex items-center gap-2">
                                    <?= render_editable_field($record, 'chest_xray_pa', $is_nurse, true) ?>
                                    <span class="text-sm">PA</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <?= render_editable_field($record, 'chest_xray_lordotic', $is_nurse, true) ?>
                                    <span class="text-sm">Lordotic</span>
                                </div>
                            </div>
                            <div class="space-y-3">
                                <div class="flex items-center gap-2">
                                    <input type="radio" name="chest_xray_status" value="normal" 
                                           class="h-4 w-4 text-maroon border-maroon rounded focus:ring-maroon <?= $is_nurse ? 'nurse-editable' : '' ?>"
                                           <?= (!isset($record['chest_xray_findings']) || $record['chest_xray_findings'] == 0) ? 'checked' : '' ?>
                                           <?= !$is_nurse ? 'disabled' : '' ?>
                                           onchange="toggleFindingsTextarea('chest_xray_findings_text', false); document.getElementsByName('chest_xray_findings')[0].checked = false;">
                                    <span class="text-sm">Normal</span>
                                </div>
                                <div class="flex items-center gap-2 mb-1">
                                    <input type="radio" name="chest_xray_status" value="findings" 
                                           class="h-4 w-4 text-maroon border-maroon rounded focus:ring-maroon <?= $is_nurse ? 'nurse-editable' : '' ?>"
                                           <?= (isset($record['chest_xray_findings']) && $record['chest_xray_findings'] == 1) ? 'checked' : '' ?>
                                           <?= !$is_nurse ? 'disabled' : '' ?>
                                           onchange="toggleFindingsTextarea('chest_xray_findings_text', true); document.getElementsByName('chest_xray_findings')[0].checked = true;">
                                    <span class="text-sm">Findings:</span>
                                </div>
                                <input type="hidden" name="chest_xray_findings" value="<?= $record['chest_xray_findings'] ?? 0 ?>">
                                <textarea name="chest_xray_findings_text" rows="3" 
                                          class="w-full rounded border border-maroon px-3 py-2 text-sm <?= $is_nurse ? 'nurse-editable' : '' ?>"
                                          <?= !$is_nurse || (!isset($record['chest_xray_findings']) || $record['chest_xray_findings'] == 0) ? 'readonly' : '' ?>
                                          <?= !$is_nurse || (!isset($record['chest_xray_findings']) || $record['chest_xray_findings'] == 0) ? 'disabled' : '' ?>><?= htmlspecialchars($record['chest_xray_findings_text'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Complete Blood Count -->
                    <div>
                        <label class="block font-medium mb-2 text-maroon">COMPLETE BLOOD COUNT</label>
                        <div class="grid grid-cols-2 gap-4">
                            <div class="flex items-center gap-2">
                                <input type="radio" name="cbc_status" value="normal" 
                                       class="h-4 w-4 text-maroon border-maroon rounded focus:ring-maroon <?= $is_nurse ? 'nurse-editable' : '' ?>"
                                       <?= (!isset($record['cbc_findings']) || $record['cbc_findings'] == 0) ? 'checked' : '' ?>
                                       <?= !$is_nurse ? 'disabled' : '' ?>
                                       onchange="toggleFindingsTextarea('cbc_findings_text', false); document.getElementsByName('cbc_findings')[0].checked = false;">
                                <span class="text-sm">Normal</span>
                            </div>
                            <div>
                                <div class="flex items-center gap-2 mb-1">
                                    <input type="radio" name="cbc_status" value="findings" 
                                           class="h-4 w-4 text-maroon border-maroon rounded focus:ring-maroon <?= $is_nurse ? 'nurse-editable' : '' ?>"
                                           <?= (isset($record['cbc_findings']) && $record['cbc_findings'] == 1) ? 'checked' : '' ?>
                                           <?= !$is_nurse ? 'disabled' : '' ?>
                                           onchange="toggleFindingsTextarea('cbc_findings_text', true); document.getElementsByName('cbc_findings')[0].checked = true;">
                                    <span class="text-sm">Findings:</span>
                                </div>
                                <input type="hidden" name="cbc_findings" value="<?= $record['cbc_findings'] ?? 0 ?>">
                                <textarea name="cbc_findings_text" rows="3" 
                                          class="w-full rounded border border-maroon px-3 py-2 text-sm <?= $is_nurse ? 'nurse-editable' : '' ?>"
                                          <?= !$is_nurse || (!isset($record['cbc_findings']) || $record['cbc_findings'] == 0) ? 'readonly' : '' ?>
                                          <?= !$is_nurse || (!isset($record['cbc_findings']) || $record['cbc_findings'] == 0) ? 'disabled' : '' ?>><?= htmlspecialchars($record['cbc_findings_text'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- RIGHT COLUMN -->
                <div class="space-y-6">
                    <!-- Routine Urinalysis -->
                    <div>
                        <label class="block font-medium mb-2 text-maroon">ROUTINE URINALYSIS</label>
                        <div class="grid grid-cols-2 gap-4">
                            <div class="flex items-center gap-2">
                                <input type="radio" name="urinalysis_status" value="normal" 
                                       class="h-4 w-4 text-maroon border-maroon rounded focus:ring-maroon <?= $is_nurse ? 'nurse-editable' : '' ?>"
                                       <?= (!isset($record['urinalysis_findings']) || $record['urinalysis_findings'] == 0) ? 'checked' : '' ?>
                                       <?= !$is_nurse ? 'disabled' : '' ?>
                                       onchange="toggleFindingsTextarea('urinalysis_findings_text', false); document.getElementsByName('urinalysis_findings')[0].checked = false;">
                                <span class="text-sm">Normal</span>
                            </div>
                            <div>
                                <div class="flex items-center gap-2 mb-1">
                                    <input type="radio" name="urinalysis_status" value="findings" 
                                           class="h-4 w-4 text-maroon border-maroon rounded focus:ring-maroon <?= $is_nurse ? 'nurse-editable' : '' ?>"
                                           <?= (isset($record['urinalysis_findings']) && $record['urinalysis_findings'] == 1) ? 'checked' : '' ?>
                                           <?= !$is_nurse ? 'disabled' : '' ?>
                                           onchange="toggleFindingsTextarea('urinalysis_findings_text', true); document.getElementsByName('urinalysis_findings')[0].checked = true;">
                                    <span class="text-sm">Findings:</span>
                                </div>
                                <input type="hidden" name="urinalysis_findings" value="<?= $record['urinalysis_findings'] ?? 0 ?>">
                                <textarea name="urinalysis_findings_text" rows="3" 
                                          class="w-full rounded border border-maroon px-3 py-2 text-sm <?= $is_nurse ? 'nurse-editable' : '' ?>"
                                          <?= !$is_nurse || (!isset($record['urinalysis_findings']) || $record['urinalysis_findings'] == 0) ? 'readonly' : '' ?>
                                          <?= !$is_nurse || (!isset($record['urinalysis_findings']) || $record['urinalysis_findings'] == 0) ? 'disabled' : '' ?>><?= htmlspecialchars($record['urinalysis_findings_text'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Stool Examination -->
                    <div>
                        <label class="block font-medium mb-2 text-maroon">STOOL EXAMINATION</label>
                        <div class="grid grid-cols-2 gap-4">
                            <div class="flex items-center gap-2">
                                <input type="radio" name="stool_status" value="normal" 
                                       class="h-4 w-4 text-maroon border-maroon rounded focus:ring-maroon <?= $is_nurse ? 'nurse-editable' : '' ?>"
                                       <?= (!isset($record['stool_findings']) || $record['stool_findings'] == 0) ? 'checked' : '' ?>
                                       <?= !$is_nurse ? 'disabled' : '' ?>
                                       onchange="toggleFindingsTextarea('stool_findings_text', false); document.getElementsByName('stool_findings')[0].checked = false;">
                                <span class="text-sm">Normal</span>
                            </div>
                            <div>
                                <div class="flex items-center gap-2 mb-1">
                                    <input type="radio" name="stool_status" value="findings" 
                                       class="h-4 w-4 text-maroon border-maroon rounded focus:ring-maroon <?= $is_nurse ? 'nurse-editable' : '' ?>"
                                       <?= (isset($record['stool_findings']) && $record['stool_findings'] == 1) ? 'checked' : '' ?>
                                       <?= !$is_nurse ? 'disabled' : '' ?>
                                       onchange="toggleFindingsTextarea('stool_findings_text', true); document.getElementsByName('stool_findings')[0].checked = true;">
                                    <span class="text-sm">Findings:</span>
                                </div>
                                <input type="hidden" name="stool_findings" value="<?= $record['stool_findings'] ?? 0 ?>">
                                <textarea name="stool_findings_text" rows="3" 
                                          class="w-full rounded border border-maroon px-3 py-2 text-sm <?= $is_nurse ? 'nurse-editable' : '' ?>"
                                          <?= !$is_nurse || (!isset($record['stool_findings']) || $record['stool_findings'] == 0) ? 'readonly' : '' ?>
                                          <?= !$is_nurse || (!isset($record['stool_findings']) || $record['stool_findings'] == 0) ? 'disabled' : '' ?>><?= htmlspecialchars($record['stool_findings_text'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Hepa B Screening -->
                    <div>
                        <label class="block font-medium mb-2 text-maroon">HEPA B SCREENING</label>
                        <div class="grid grid-cols-2 gap-4">
                            <div class="flex items-center gap-2">
                                <input type="radio" name="hepa_b_status" value="normal" 
                                       class="h-4 w-4 text-maroon border-maroon rounded focus:ring-maroon <?= $is_nurse ? 'nurse-editable' : '' ?>"
                                       <?= (!isset($record['hepa_b_findings']) || $record['hepa_b_findings'] == 0) ? 'checked' : '' ?>
                                       <?= !$is_nurse ? 'disabled' : '' ?>
                                       onchange="toggleFindingsTextarea('hepa_b_findings_text', false); document.getElementsByName('hepa_b_findings')[0].checked = false;">
                                <span class="text-sm">Normal</span>
                            </div>
                            <div>
                                <div class="flex items-center gap-2 mb-1">
                                    <input type="radio" name="hepa_b_status" value="findings" 
                                           class="h-4 w-4 text-maroon border-maroon rounded focus:ring-maroon <?= $is_nurse ? 'nurse-editable' : '' ?>"
                                           <?= (isset($record['hepa_b_findings']) && $record['hepa_b_findings'] == 1) ? 'checked' : '' ?>
                                           <?= !$is_nurse ? 'disabled' : '' ?>
                                           onchange="toggleFindingsTextarea('hepa_b_findings_text', true); document.getElementsByName('hepa_b_findings')[0].checked = true;">
                                    <span class="text-sm">Findings:</span>
                                </div>
                                <input type="hidden" name="hepa_b_findings" value="<?= $record['hepa_b_findings'] ?? 0 ?>">
                                <textarea name="hepa_b_findings_text" rows="3" 
                                          class="w-full rounded border border-maroon px-3 py-2 text-sm <?= $is_nurse ? 'nurse-editable' : '' ?>"
                                          <?= !$is_nurse || (!isset($record['hepa_b_findings']) || $record['hepa_b_findings'] == 0) ? 'readonly' : '' ?>
                                          <?= !$is_nurse || (!isset($record['hepa_b_findings']) || $record['hepa_b_findings'] == 0) ? 'disabled' : '' ?>><?= htmlspecialchars($record['hepa_b_findings_text'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Drug Test -->
                    <div>
                        <label class="block font-medium mb-2 text-maroon">DRUG TEST</label>
                        
                        <!-- Methamphetamine -->
                        <div class="mb-4 p-3 bg-maroon-50 rounded border border-maroon-200">
                            <label class="block text-sm font-medium mb-2 text-maroon">Methamphetamine</label>
                            <div class="grid grid-cols-2 gap-4">
                                <div class="flex items-center gap-2">
                                    <input type="radio" name="methamphetamine_status" value="negative" 
                                           class="h-4 w-4 text-maroon border-maroon rounded focus:ring-maroon <?= $is_nurse ? 'nurse-editable' : '' ?>"
                                           <?= (!isset($record['methamphetamine_positive']) || $record['methamphetamine_positive'] == 0) ? 'checked' : '' ?>
                                           <?= !$is_nurse ? 'disabled' : '' ?>
                                           onchange="document.getElementsByName('methamphetamine_negative')[0].checked = true; document.getElementsByName('methamphetamine_positive')[0].checked = false;">
                                    <span class="text-sm">Negative</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <input type="radio" name="methamphetamine_status" value="positive" 
                                           class="h-4 w-4 text-maroon border-maroon rounded focus:ring-maroon <?= $is_nurse ? 'nurse-editable' : '' ?>"
                                           <?= (isset($record['methamphetamine_positive']) && $record['methamphetamine_positive'] == 1) ? 'checked' : '' ?>
                                           <?= !$is_nurse ? 'disabled' : '' ?>
                                           onchange="document.getElementsByName('methamphetamine_negative')[0].checked = false; document.getElementsByName('methamphetamine_positive')[0].checked = true;">
                                    <span class="text-sm">Positive</span>
                                </div>
                            </div>
                            <input type="hidden" name="methamphetamine_negative" value="<?= $record['methamphetamine_negative'] ?? 0 ?>">
                            <input type="hidden" name="methamphetamine_positive" value="<?= $record['methamphetamine_positive'] ?? 0 ?>">
                        </div>
                        
                        <!-- Tetrahydrocannabinol -->
                        <div class="p-3 bg-maroon-50 rounded border border-maroon-200">
                            <label class="block text-sm font-medium mb-2 text-maroon">Tetrahydrocannabinol</label>
                            <div class="grid grid-cols-2 gap-4">
                                <div class="flex items-center gap-2">
                                    <input type="radio" name="thc_status" value="negative" 
                                           class="h-4 w-4 text-maroon border-maroon rounded focus:ring-maroon <?= $is_nurse ? 'nurse-editable' : '' ?>"
                                           <?= (!isset($record['thc_positive']) || $record['thc_positive'] == 0) ? 'checked' : '' ?>
                                           <?= !$is_nurse ? 'disabled' : '' ?>
                                           onchange="document.getElementsByName('thc_negative')[0].checked = true; document.getElementsByName('thc_positive')[0].checked = false;">
                                    <span class="text-sm">Negative</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <input type="radio" name="thc_status" value="positive" 
                                           class="h-4 w-4 text-maroon border-maroon rounded focus:ring-maroon <?= $is_nurse ? 'nurse-editable' : '' ?>"
                                           <?= (isset($record['thc_positive']) && $record['thc_positive'] == 1) ? 'checked' : '' ?>
                                           <?= !$is_nurse ? 'disabled' : '' ?>
                                           onchange="document.getElementsByName('thc_negative')[0].checked = false; document.getElementsByName('thc_positive')[0].checked = true;">
                                    <span class="text-sm">Positive</span>
                                </div>
                            </div>
                            <input type="hidden" name="thc_negative" value="<?= $record['thc_negative'] ?? 0 ?>">
                            <input type="hidden" name="thc_positive" value="<?= $record['thc_positive'] ?? 0 ?>">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- CERTIFICATION SECTION (Only for doctors/physicians) -->
    <?php if (($user_role === 'doctor' || $user_role === 'physician') && ($type === 'history_form' || $type === 'medical_form' || $type === 'medical_exam')): ?>
    <div class="certification-section rounded-lg p-4 mb-6 border-2 border-green-500 bg-green-50">
        <h3 class="font-semibold text-lg mb-4 text-green-800">CERTIFICATION</h3>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- LEFT COLUMN -->
            <div class="space-y-4">
                <!-- School/Company/Institution -->
                <div>
                    <label class="block font-medium mb-1 text-green-700">School/Company/Institution:</label>
                    <input type="text" name="institution" value="BATANGAS STATE UNIVERSITY" 
                           class="w-full rounded border border-green-300 px-3 py-2 text-sm bg-green-100" readonly>
                </div>
                
                <!-- Name - Fetched from patient record -->
                <div>
                    <label class="block font-medium mb-1 text-green-700">Name:</label>
                    <?php
                    $certified_name = !empty($record['certified_name']) ? $record['certified_name'] : htmlspecialchars(ucwords(strtolower($record['first_name'] . ' ' . $record['last_name'])));
                    ?>
                    <input type="text" name="certified_name" 
                           value="<?= $certified_name ?>" 
                           class="w-full rounded border border-green-300 px-3 py-2 text-sm <?= $is_nurse ? 'nurse-editable' : '' ?>" 
                           <?= !$is_nurse ? 'readonly' : '' ?>>
                </div>
                
                <!-- Weight (kg) - FIXED: Should be number -->
                <div>
                    <label class="block font-medium mb-1 text-green-700">Weight (kg):</label>
                    <?= render_editable_field($record, 'certified_weight', $is_nurse, false, 'number') ?>
                </div>
                
                <!-- Height (cm) - FIXED: Should be number -->
                <div>
                    <label class="block font-medium mb-1 text-green-700">Height (cm):</label>
                    <?= render_editable_field($record, 'certified_height', $is_nurse, false, 'number') ?>
                </div>
                
                <!-- Civil Status - Fetched from patient record -->
                <div>
                    <label class="block font-medium mb-1 text-green-700">Civil Status:</label>
                    <?php
                    $certified_civil_status = !empty($record['certified_civil_status']) ? $record['certified_civil_status'] : (!empty($record['civil_status']) ? htmlspecialchars(ucwords(strtolower($record['civil_status']))) : '');
                    ?>
                    <input type="text" name="certified_civil_status" 
                           value="<?= $certified_civil_status ?>" 
                           class="w-full rounded border border-green-300 px-3 py-2 text-sm <?= $is_nurse ? 'nurse-editable' : '' ?>" 
                           <?= !$is_nurse ? 'readonly' : '' ?>>
                </div>
                
                <!-- Date of Examination - Fetched from medical exam -->
                <div>
                    <label class="block font-medium mb-1 text-green-700">Date of Examination:</label>
                    <?php
                    $certified_exam_date = !empty($record['certified_exam_date']) ? $record['certified_exam_date'] : (!empty($record['examination_date']) ? htmlspecialchars($record['examination_date']) : '');
                    ?>
                    <input type="text" name="certified_exam_date" 
                           value="<?= $certified_exam_date ?>" 
                           class="w-full rounded border border-green-300 px-3 py-2 text-sm <?= $is_nurse ? 'nurse-editable' : '' ?>" 
                           <?= !$is_nurse ? 'readonly' : '' ?>>
                </div>
                
                <!-- Authorization Statement -->
                <div class="mt-4">
                    <p class="text-sm text-green-800 font-medium mb-3">
                        "I hereby authorize BATANGAS STATE UNIVERSITY and its officially designated medical examiner 
                        and examining physician/s to furnish information that the company may need pertaining to my 
                        health status and other pertinent medical findings and do hereby release them from any and 
                        all legal responsibilities by so doing. I also further certify that the medical history 
                        contained herein is true to the best of my knowledge and any false statement will disqualify 
                        me from any employment benefits and claims."
                    </p>
                    
                    <!-- Signature and Date -->
                    <div class="mt-4">
                        
                        <div class="border-b-2 border-green-400 pt-4 pb-1 min-h-[40px] mb-2">
                            <span class="text-sm text-gray-600"><?= htmlspecialchars(ucwords(strtolower($record['first_name'] . ' ' . $record['last_name']))) ?></span>
                        </div>
                        <div>
                            <label class="block font-medium mb-1 text-green-700">Date:</label>
                            <?php
                            $student_signature_date = !empty($record['student_signature_date']) ? $record['student_signature_date'] : date('Y-m-d');
                            ?>
                            <input type="date" name="student_signature_date" value="<?= htmlspecialchars($student_signature_date) ?>" 
                                   class="w-full rounded border border-green-300 px-3 py-2 text-sm <?= $is_nurse ? 'nurse-editable' : '' ?>" 
                                   <?= !$is_nurse ? 'readonly' : '' ?>>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- RIGHT COLUMN -->
            <div class="space-y-4">
                <!-- Certification Statement -->
                <div class="mb-4">
                    <p class="text-sm text-green-800 font-medium mb-3">
                        "I certify that I have examined and found the applicant to be physically fit/unfit for employment."
                    </p>
                    
                    <!-- CLASSIFICATION -->
                    <div class="mt-3">
                        <label class="block font-medium mb-2 text-green-700">CLASSIFICATION:</label>
                        <div class="space-y-2">
                            <div class="flex items-start gap-2">
                                <?= render_editable_field($record, 'classification_a', $is_nurse, true) ?>
                                <span class="text-sm font-medium text-green-700">CLASS A - Physically fit to work</span>
                            </div>
                            <div class="flex items-start gap-2">
                                <?= render_editable_field($record, 'classification_b', $is_nurse, true) ?>
                                <span class="text-sm font-medium text-green-700">CLASS B - Physically underdeveloped or with correctible defects but otherwise fit to work</span>
                            </div>
                            <div class="flex items-start gap-2">
                                <?= render_editable_field($record, 'classification_c', $is_nurse, true) ?>
                                <span class="text-sm font-medium text-green-700">CLASS C - Employable but owing to certain impairments or conditions, requires special placement or limited duty in a specified or selected assignment requiring follow up treatment/ periodic evaluation</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Treatment/Correction Needed -->
                <div class="mb-4">
                    <label class="block font-medium mb-2 text-green-700">Needs treatment or correction of:</label>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-2">
                            <div class="flex items-center gap-2">
                                <?= render_editable_field($record, 'needs_treatment_skin', $is_nurse, true) ?>
                                <span class="text-sm">Skin Disease</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <?= render_editable_field($record, 'needs_treatment_dental', $is_nurse, true) ?>
                                <span class="text-sm">Dental Defects</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <?= render_editable_field($record, 'needs_treatment_anemia', $is_nurse, true) ?>
                                <span class="text-sm">Anemia</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <?= render_editable_field($record, 'needs_treatment_vision', $is_nurse, true) ?>
                                <span class="text-sm">Poor Vision</span>
                            </div>
                        </div>
                        <div class="space-y-2">
                            <div class="flex items-center gap-2">
                                <?= render_editable_field($record, 'needs_treatment_uti', $is_nurse, true) ?>
                                <span class="text-sm">Mild Urinary Tract Infection</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <?= render_editable_field($record, 'needs_treatment_parasitism', $is_nurse, true) ?>
                                <span class="text-sm">Intestinal Parasitism</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <?= render_editable_field($record, 'needs_treatment_hypertension', $is_nurse, true) ?>
                                <span class="text-sm">Mild Hypertension</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <?= render_editable_field($record, 'needs_treatment_others_check', $is_nurse, true) ?>
                                <span class="text-sm">Others, specify:</span>
                            </div>
                            <div class="ml-6">
                                <?= render_editable_field($record, 'needs_treatment_others_text', $is_nurse) ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- CLASS D -->
                <div class="flex items-start gap-2 mb-4">
                    <?= render_editable_field($record, 'classification_d', $is_nurse, true) ?>
                    <span class="text-sm font-medium text-green-700">CLASS D - Unfit or unsafe for any type of employment</span>
                </div>
                
                <!-- Physician Information -->
                <div class="mt-6 pt-4 border-t border-green-300">
                    <label class="block font-medium mb-2 text-green-700">Physician/Medical Examiner</label>
                    <div class="space-y-2">
                        <?php
                        // Get physician name from record or default to the example
                        $physician_name = !empty($record['physician_name']) ? $record['physician_name'] : 'MARSON KIM L. PERMENTILLA M.D.';
                        $license_no = !empty($record['license_no']) ? $record['license_no'] : '0169430';
                        $physician_date = !empty($record['physician_date']) ? $record['physician_date'] : date('Y-m-d');
                        ?>
                        <input type="text" name="physician_name" value="<?= htmlspecialchars($physician_name) ?>" 
                               class="w-full rounded border border-green-300 px-3 py-2 text-sm <?= $is_nurse ? 'nurse-editable' : '' ?>" 
                               <?= !$is_nurse ? 'readonly' : '' ?>>
                        <div class="flex items-center gap-2">
                            <span class="font-medium text-green-700">License No.:</span>
                            <input type="text" name="license_no" value="<?= htmlspecialchars($license_no) ?>" 
                                   class="w-32 rounded border border-green-300 px-3 py-2 text-sm <?= $is_nurse ? 'nurse-editable' : '' ?>" 
                                   <?= !$is_nurse ? 'readonly' : '' ?>>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="font-medium text-green-700">Date:</span>
                            <input type="date" name="physician_date" value="<?= htmlspecialchars($physician_date) ?>" 
                                   class="rounded border border-green-300 px-3 py-2 text-sm <?= $is_nurse ? 'nurse-editable' : '' ?>" 
                                   <?= !$is_nurse ? 'readonly' : '' ?>>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <!-- END CERTIFICATION SECTION -->

    <!-- MODIFIED: COMBINED MEDICAL NOTES SECTION (Nurse and Doctor in one form) -->
    <div class="medical-diagnoses-section rounded-lg p-4 mb-6 border-2 border-blue-500 bg-blue-50">
        <h3 class="font-semibold text-lg mb-4 text-blue-800">MEDICAL DIAGNOSIS NOTES</h3>
        
        <?php 
        // Check who can add/edit notes
        $can_add_nurse_notes = ($user_role === 'nurse' || $user_role === 'admin' || $user_role === 'staff');
        $can_add_doctor_notes = ($user_role === 'doctor' || $user_role === 'physician' || $user_role === 'admin' || $user_role === 'staff');
        ?>
        
        <!-- Combined Diagnosis Form -->
        <div class="mb-6 p-4 bg-white rounded-lg border border-blue-300">
            <h4 class="font-semibold mb-3 text-blue-700">Medical Diagnosis Notes</h4>
            <form method="POST" id="medical-diagnosis-form">
                <input type="hidden" name="record_id" value="<?= $record['record_id']; ?>">
                <input type="hidden" name="patient_id" value="<?= $record['patient_id']; ?>">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Nurse Notes Section -->
                    <div class="border-r border-blue-300 pr-6">
                        <div class="flex items-center gap-2 mb-3">
                            <i class="bi bi-person-badge text-blue-600"></i>
                            <h5 class="font-semibold text-blue-700">Nurse Notes</h5>
                            <?php if (!empty($existing_diagnosis['nurse_diagnosis_date'])): ?>
                                <span class="ml-auto text-xs text-gray-500">
                                    Last updated: <?= date('M j, Y', strtotime($existing_diagnosis['nurse_diagnosis_date'])) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="mb-4">
                            <label class="block font-medium mb-2 text-blue-700">Nurse Assessment:</label>
                            <textarea name="nurse_note" rows="6" 
                                      class="w-full rounded border border-blue-300 px-3 py-2 text-sm"
                                      placeholder="Enter nurse assessment and notes here..." 
                                      <?= !$can_add_nurse_notes ? 'readonly' : '' ?>
                                      <?= !$can_add_nurse_notes ? 'disabled' : '' ?>><?= 
                                      !empty($existing_diagnosis['nurse_note']) ? htmlspecialchars($existing_diagnosis['nurse_note']) : '' ?></textarea>
                        </div>
                        
                        <?php if ($can_add_nurse_notes): ?>
                            <button type="submit" name="add_medical_diagnosis" value="nurse"
                                    class="bg-blue-600 text-white px-4 py-2 rounded-lg shadow font-semibold hover:bg-blue-700 hover:shadow-lg transition-all">
                                <i class="bi bi-save"></i> Save Nurse Notes
                            </button>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Doctor Notes Section -->
                    <div class="pl-0 md:pl-6">
                        <div class="flex items-center gap-2 mb-3">
                            <i class="bi bi-heart-pulse text-red-600"></i>
                            <h5 class="font-semibold text-red-700">Physician/Doctor Notes</h5>
                            <?php if (!empty($existing_diagnosis['doctor_diagnosis_date'])): ?>
                                <span class="ml-auto text-xs text-gray-500">
                                    Last updated: <?= date('M j, Y', strtotime($existing_diagnosis['doctor_diagnosis_date'])) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="mb-4">
                            <label class="block font-medium mb-2 text-red-700">Physician Assessment:</label>
                            <textarea name="doctor_note" rows="6" 
                                      class="w-full rounded border border-red-300 px-3 py-2 text-sm"
                                      placeholder="Enter physician assessment and notes here..." 
                                      <?= !$can_add_doctor_notes ? 'readonly' : '' ?>
                                      <?= !$can_add_doctor_notes ? 'disabled' : '' ?>><?= 
                                      !empty($existing_diagnosis['doctor_note']) ? htmlspecialchars($existing_diagnosis['doctor_note']) : '' ?></textarea>
                        </div>
                        
                        <?php if ($can_add_doctor_notes): ?>
                            <button type="submit" name="add_medical_diagnosis" value="doctor"
                                    class="bg-red-600 text-white px-4 py-2 rounded-lg shadow font-semibold hover:bg-red-700 hover:shadow-lg transition-all">
                                <i class="bi bi-save"></i> Save Doctor Notes
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
                
                <input type="hidden" name="diagnosis_type" id="diagnosis_type" value="">
                
                <script>
                document.addEventListener('DOMContentLoaded', function() {
                    // Set diagnosis type based on which button is clicked
                    const nurseBtn = document.querySelector('button[name="add_medical_diagnosis"][value="nurse"]');
                    const doctorBtn = document.querySelector('button[name="add_medical_diagnosis"][value="doctor"]');
                    
                    if (nurseBtn) {
                        nurseBtn.addEventListener('click', function(e) {
                            document.getElementById('diagnosis_type').value = 'nurse';
                        });
                    }
                    
                    if (doctorBtn) {
                        doctorBtn.addEventListener('click', function(e) {
                            document.getElementById('diagnosis_type').value = 'doctor';
                        });
                    }
                });
                </script>
                
                <p class="text-xs text-gray-500 mt-3">
                    Notes will be attributed to you as 
                    <span class="font-semibold"><?= htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Unknown User') ?></span>
                    and dated for today.
                </p>
            </form>
        </div>

        <!-- Display Existing Notes -->
        <div class="space-y-6">
            <?php if (!empty($existing_diagnosis)): ?>
                <!-- Combined Notes Display -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Nurse Notes Display -->
                    <?php if (!empty($existing_diagnosis['nurse_note'])): ?>
                        <div class="bg-white rounded-lg border border-blue-200 p-4">
                            <div class="flex justify-between items-start mb-3">
                                <div class="flex items-center gap-2">
                                    <span class="font-semibold text-blue-700">
                                        <?= htmlspecialchars($existing_diagnosis['provider_full_name'] ?? $existing_diagnosis['provider_name']) ?>
                                    </span>
                                    <span class="text-sm px-2 py-1 rounded-full bg-blue-100 text-blue-800">
                                        <i class="bi bi-person-badge"></i> Nurse Notes
                                    </span>
                                </div>
                                <?php if (!empty($existing_diagnosis['nurse_diagnosis_date'])): ?>
                                    <div class="text-sm text-gray-500">
                                        <?= date('M j, Y', strtotime($existing_diagnosis['nurse_diagnosis_date'])) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="mt-3">
                                <div class="text-gray-800"><?= nl2br(htmlspecialchars($existing_diagnosis['nurse_note'])) ?></div>
                            </div>
                            
                            <!-- Delete button (only shown to notes owner or admin) -->
                            <?php if (($existing_diagnosis['provider_id'] == $_SESSION['user_id'] || $user_role === 'admin') && $can_add_nurse_notes): ?>
                                <div class="mt-3 pt-3 border-t border-gray-200">
                                    <form method="POST" onsubmit="return confirm('Are you sure you want to delete these notes?');">
                                        <input type="hidden" name="diagnosis_id" value="<?= $existing_diagnosis['id']; ?>">
                                        <button type="submit" name="delete_medical_diagnosis"
                                                class="text-red-600 hover:text-red-800 text-sm font-medium">
                                            <i class="bi bi-trash"></i> Delete Nurse Notes
                                        </button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="bg-gray-50 rounded-lg border border-gray-200 p-4 text-center">
                            <i class="bi bi-person-badge text-3xl text-gray-400 mb-2"></i>
                            <p class="text-gray-500">No nurse notes recorded yet.</p>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Doctor Notes Display -->
                    <?php if (!empty($existing_diagnosis['doctor_note'])): ?>
                        <div class="bg-white rounded-lg border border-red-200 p-4">
                            <div class="flex justify-between items-start mb-3">
                                <div class="flex items-center gap-2">
                                    <span class="font-semibold text-red-700">
                                        <?= htmlspecialchars($existing_diagnosis['provider_full_name'] ?? $existing_diagnosis['provider_name']) ?>
                                    </span>
                                    <span class="text-sm px-2 py-1 rounded-full bg-red-100 text-red-800">
                                        <i class="bi bi-heart-pulse"></i> Physician Notes
                                    </span>
                                </div>
                                <?php if (!empty($existing_diagnosis['doctor_diagnosis_date'])): ?>
                                    <div class="text-sm text-gray-500">
                                        <?= date('M j, Y', strtotime($existing_diagnosis['doctor_diagnosis_date'])) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="mt-3">
                                <div class="text-gray-800"><?= nl2br(htmlspecialchars($existing_diagnosis['doctor_note'])) ?></div>
                            </div>
                            
                            <!-- Delete button (only shown to notes owner or admin) -->
                            <?php if (($existing_diagnosis['provider_id'] == $_SESSION['user_id'] || $user_role === 'admin') && $can_add_doctor_notes): ?>
                                <div class="mt-3 pt-3 border-t border-gray-200">
                                    <form method="POST" onsubmit="return confirm('Are you sure you want to delete these notes?');">
                                        <input type="hidden" name="diagnosis_id" value="<?= $existing_diagnosis['id']; ?>">
                                        <button type="submit" name="delete_medical_diagnosis"
                                                class="text-red-600 hover:text-red-800 text-sm font-medium">
                                            <i class="bi bi-trash"></i> Delete Doctor Notes
                                        </button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="bg-gray-50 rounded-lg border border-gray-200 p-4 text-center">
                            <i class="bi bi-heart-pulse text-3xl text-gray-400 mb-2"></i>
                            <p class="text-gray-500">No physician notes recorded yet.</p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="text-center py-8 text-gray-500 bg-white rounded-lg border border-blue-200">
                    <i class="bi bi-clipboard-pulse text-4xl mb-3"></i>
                    <p class="text-lg">No medical diagnosis notes recorded yet.</p>
                    <p class="text-sm mt-2">Use the form above to add nurse and/or physician notes.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <!-- END MODIFIED MEDICAL NOTES SECTION -->

        <!-- ACTION BUTTONS FOR MEDICAL FORM -->
        <div class="mt-8 pt-6 border-t border-maroon-200 no-print">
            <div class="flex justify-center gap-4 flex-wrap">
                <?php if ($is_nurse): ?>
                    <button type="submit" form="record-form" name="update_record"
                            class="maroon-gradient-button text-white px-6 py-2 rounded-lg shadow font-semibold hover:shadow-lg transition-all">
                        <i class="bi bi-check-circle"></i> Update Record
                    </button>
                    <form method="POST" onsubmit="return confirm('Are you sure you want to delete this record? This action cannot be undone.');" class="inline">
                        <input type="hidden" name="record_id" value="<?= $record['record_id']; ?>">
                        <input type="hidden" name="record_type" value="<?= $type; ?>">
                        <button type="submit" name="delete"
                                class="maroon-gradient-button text-white px-6 py-2 rounded-lg shadow font-semibold hover:shadow-lg transition-all">
                            <i class="bi bi-trash"></i> Delete Record
                        </button>
                    </form>
                    
                    <!-- Submit for Certification Button -->
                    <?php if (!$is_certified && $user_role !== 'doctor' && $user_role !== 'dentist' && $user_role !== 'physician'): ?>
                        <button type="button" onclick="saveAndMarkForCertification()"
                                class="bg-green-500 text-white px-6 py-2 rounded-lg shadow font-semibold hover:bg-green-600 hover:shadow-lg transition-all">
                            <i class="bi bi-award"></i> Save & Submit for Certification
                        </button>
                    <?php elseif ($is_certified && $user_role !== 'doctor' && $user_role !== 'dentist' && $user_role !== 'physician'): ?>
                        <button type="button" disabled
                                class="bg-gray-400 text-white px-6 py-2 rounded-lg shadow font-semibold cursor-not-allowed">
                            <i class="bi bi-award-fill"></i> Already Submitted for Certification
                        </button>
                    <?php endif; ?>
                <?php endif; ?>
                
                <!-- Doctor/Physician Certification - FIXED: Changed to mark as 'certified' -->
                <?php if (($user_role === 'doctor' || $user_role === 'physician') && ($type === 'history_form' || $type === 'medical_form' || $type === 'medical_exam')): ?>
                    <?php if ($record['verification_status'] !== 'certified'): ?>
                        <button type="button" onclick="saveAndCertify()"
                                class="bg-green-500 text-white px-6 py-2 rounded-lg shadow font-semibold hover:bg-green-600 hover:shadow-lg transition-all">
                            <i class="bi bi-award"></i> Save & Certify
                        </button>
                    <?php else: ?>
                        <button type="button" onclick="saveAndPrintCertified()"
                               class="bg-blue-600 text-white px-6 py-2 rounded-lg shadow font-semibold hover:bg-blue-700 hover:shadow-lg transition-all">
                            <i class="bi bi-printer"></i> Print Certified Form
                        </button>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <!-- END ACTION BUTTONS FOR MEDICAL FORM -->

    </div> <!-- This closes the medical-section div -->

<?php elseif ($type === 'dental_form' || $type === 'dental_exam'): ?>
    <!-- Dental Examination Details -->
    <div class="dental-section rounded-lg p-4 mb-6">
        <div class="flex justify-between items-center mb-4">
            <h3 class="font-semibold text-lg text-maroon">Dental Examination Details</h3>
            <?php if ($is_nurse): ?>
                <span class="bg-maroon-light text-maroon px-3 py-1 rounded-full text-sm font-semibold">
                    <i class="bi bi-pencil-square"></i> Editable by <?= ucwords($user_role) ?>
                </span>
            <?php endif; ?>
        </div>

       

        <!-- Enhanced Dental Chart Visualization -->
        <div class="mb-6 bg-white border-2 border-maroon-200 rounded-xl p-6">
            <div class="flex justify-between items-center mb-4">
                <h4 class="font-semibold text-maroon text-lg">Dental Chart Visualization</h4>
                <?php if ($is_nurse): ?>
                    <button type="button" onclick="resetDentalChart()" 
                            class="maroon-gradient-button text-white px-3 py-1 rounded text-sm transition-colors">
                        <i class="bi bi-arrow-clockwise"></i> Reset Chart
                    </button>
                <?php endif; ?>
            </div>
            
            <?php 
            $dentalChartData = $record['dental_chart_data'] ?? '';
            echo generateDentalChartVisualization($dentalChartData);
            ?>
            
            <!-- Dental Chart Data Storage (for nurses to edit) -->
            <?php if ($is_nurse): ?>
                <div class="mt-4 p-4 bg-maroon-50 rounded-lg border border-maroon-200">
                    <div class="flex justify-between items-center mb-3">
                        <label class="block font-medium text-maroon">Dental Chart Data</label>
                        
                    </div>
                    
                    <!-- Formatted Display (Default) -->
                    <div id="formatted-dental-data" class="bg-white rounded-lg p-4 border border-maroon-200">
                        <?php 
                        // Try to get data from database first
                        $displayData = $dentalChartData;
                        
                        // If database is empty, the data will be loaded from JavaScript state
                        // So we'll use JavaScript to populate this section
                        if (empty($displayData) || $displayData === 'null' || $displayData === '""' || trim($displayData) === '{}' || trim($displayData) === '[]') {
                            // Data will be populated by JavaScript from the chart state
                            echo '<div id="dental-data-placeholder" class="text-center py-4 text-gray-500">Loading dental chart data...</div>';
                        } else {
                            echo formatDentalChartData($displayData);
                        }
                        ?>
                    </div>
                    
                    <!-- Raw JSON Textarea (Hidden by default) -->
                    <div id="raw-dental-data" class="hidden">
                        <textarea id="dental_chart_data" name="dental_chart_data" rows="6" 
                                  class="w-full border border-maroon rounded-lg px-3 py-2 text-sm nurse-editable font-mono text-xs"
                                  placeholder="Dental chart data in JSON format"><?= 
                                  !empty($dentalChartData) && $dentalChartData !== 'null' && $dentalChartData !== '""' ? 
                                  htmlspecialchars(json_encode(json_decode($dentalChartData, true), JSON_PRETTY_PRINT)) : '{}' ?></textarea>
                    </div>
                    
                    
                </div>
            <?php else: ?>
                <!-- For non-nurses, show formatted display only -->
                <div class="mt-4 p-4 bg-gray-50 rounded-lg border border-gray-200">
                    <label class="block font-medium mb-3 text-gray-700">Dental Chart Data Summary</label>
                    <div class="bg-white rounded-lg p-4 border border-gray-200">
                        <?php echo formatDentalChartData($dentalChartData); ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div class="bg-maroon-50 p-3 rounded shadow-sm md:col-span-2">
                <h4 class="font-semibold mb-3 text-maroon border-b border-maroon-300 pb-1">Periodontal / Occlusion / Appliances</h4>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <p class="font-medium text-sm mb-1 text-maroon">Periodontal:</p>
                        <div class="space-y-1">
                            <label class="flex items-center text-sm"> <?= display_dental_status($record, 'is_gingivitis', $is_nurse) ?> Gingivitis</label>
                            <label class="flex items-center text-sm"> <?= display_dental_status($record, 'is_early_periodontitis', $is_nurse) ?> Early Periodontitis</label>
                            <label class="flex items-center text-sm"> <?= display_dental_status($record, 'is_moderate_periodontitis', $is_nurse) ?> Moderate Periodontitis</label>
                            <label class="flex items-center text-sm"> <?= display_dental_status($record, 'is_advanced_periodontitis', $is_nurse) ?> Advanced Periodontitis</label>
                        </div>
                    </div>
                    <div>
                        <p class="font-medium text-sm mb-1 text-maroon">Occlusion & Appliances:</p>
                        <div class="space-y-1">
                            <label class="flex items-center text-sm"> <?= display_dental_status($record, 'is_class_molar', $is_nurse) ?> Occlusion Class Molar</label>
                            <label class="flex items-center text-sm"> <?= display_dental_status($record, 'is_overjet', $is_nurse) ?> Overjet</label>
                            <label class="flex items-center text-sm"> <?= display_dental_status($record, 'is_overbite', $is_nurse) ?> Overbite</label>
                            <label class="flex items-center text-sm"> <?= display_dental_status($record, 'is_crossbite', $is_nurse) ?> Crossbite</label>
                            <label class="flex items-center text-sm"> <?= display_dental_status($record, 'is_midline_deviation', $is_nurse) ?> Midline Deviation</label>
                            <label class="flex items-center text-sm"> <?= display_dental_status($record, 'is_orthodontic', $is_nurse) ?> Orthodontic Appliance</label>
                            <label class="flex items-center text-sm"> <?= display_dental_status($record, 'is_stayplate', $is_nurse) ?> Stayplate / Retainer</label>
                            <label class="flex items-center text-sm"> <?= display_dental_status($record, 'is_appliance_others', $is_nurse) ?> Other Appliance</label>
                        </div>
                    </div>
                    <div class="col-span-2 border-t border-maroon-300 pt-2 mt-2">
                        <p class="font-medium text-sm mb-1 text-maroon">TMD Symptoms:</p>
                        <div class="grid grid-cols-4">
                            <label class="flex items-center text-sm"> <?= display_dental_status($record, 'is_clenching', $is_nurse) ?> Clenching</label>
                            <label class="flex items-center text-sm"> <?= display_dental_status($record, 'is_clicking', $is_nurse) ?> Clicking</label>
                            <label class="flex items-center text-sm"> <?= display_dental_status($record, 'is_trismus', $is_nurse) ?> Trismus</label>
                            <label class="flex items-center text-sm"> <?= display_dental_status($record, 'is_muscle_spasm', $is_nurse) ?> Muscle Spasm</label>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="md:col-span-3">
                <label class="block font-medium mb-1 text-maroon">Dentist's Remarks / Findings / Treatment Plan</label>
                <?= render_editable_field($record, 'remarks', $is_nurse, false, 'textarea') ?>
            </div>
            
            
        </div>
        <!-- ACTION BUTTONS FOR DENTAL FORM -->
    <div class="mt-8 pt-6 border-t border-maroon-200 no-print">
        <div class="flex justify-center gap-4 flex-wrap">
            <?php if ($is_nurse): ?>
                <button type="submit" form="record-form" name="update_record"
                        class="maroon-gradient-button text-white px-6 py-2 rounded-lg shadow font-semibold hover:shadow-lg transition-all">
                    <i class="bi bi-check-circle"></i> Update Record
                </button>
                <form method="POST" onsubmit="return confirm('Are you sure you want to delete this record? This action cannot be undone.');" class="inline">
                    <input type="hidden" name="record_id" value="<?= $record['record_id']; ?>">
                    <input type="hidden" name="record_type" value="<?= $type; ?>">
                    <button type="submit" name="delete"
                            class="maroon-gradient-button text-white px-6 py-2 rounded-lg shadow font-semibold hover:shadow-lg transition-all">
                        <i class="bi bi-trash"></i> Delete Record
                    </button>
                </form>
                
                
                
                <!-- Mark as Completed Button for Dentists/Admins -->
                <?php if (in_array($user_role, ['dentist', 'admin', 'staff'])): ?>
                    <?php if ($record['verification_status'] !== 'completed'): ?>
                        <form method="POST" onsubmit="return confirm('Mark this dental form as completed?');" class="inline">
                            <input type="hidden" name="record_id" value="<?= $record['record_id']; ?>">
                            <input type="hidden" name="record_type" value="<?= $type; ?>">
                            <button type="submit" name="mark_as_completed"
                                    class="bg-green-500 text-white px-6 py-2 rounded-lg shadow font-semibold hover:bg-green-600 hover:shadow-lg transition-all">
                                <i class="bi bi-check-circle-fill"></i> Mark as Completed
                            </button>
                        </form>
                    <?php else: ?>
                        <button type="button" disabled
                                class="bg-gray-400 text-white px-6 py-2 rounded-lg shadow font-semibold cursor-not-allowed">
                            <i class="bi bi-check-circle-fill"></i> Already Completed
                        </button>
                    <?php endif; ?>
                <?php endif; ?>
                
            <?php endif; ?>
            
            
        </div>
    </div>
    <!-- END ACTION BUTTONS FOR DENTAL FORM -->
</div> <!-- This closes the dental-section div -->

<!-- REMOVED DENTAL DIAGNOSES SECTION -->

<?php elseif ($type === 'history_form'): ?>
    <!-- Medical History Details -->
    <div class="history-section rounded-lg p-4 mb-6">
        <div class="flex justify-between items-center mb-4">
            <h3 class="font-semibold text-lg text-maroon">Medical History Details</h3>
            <?php if ($is_nurse): ?>
                <span class="bg-maroon-light text-maroon px-3 py-1 rounded-full text-sm font-semibold">
                    <i class="bi bi-pencil-square"></i> Editable by <?= ucwords($user_role) ?>
                </span>
            <?php endif; ?>
        </div>

        <!-- Physical Examination Tables -->
        <h4 class="font-semibold mb-3 text-maroon">Physical Examination</h4>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
            <!-- Left Table -->
            <div class="overflow-x-auto">
                <table class="min-w-full border-2 border-maroon rounded-lg overflow-hidden mb-6 bg-white shadow">
                    <thead>
                        <tr class="bg-maroon-light">
                            <th class="border border-maroon px-4 py-2 text-maroon">REVIEW OF SYSTEM</th>
                            <th class="border border-maroon px-4 py-2 text-maroon">FINDINGS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        render_history_exam_row($record, 'height_normal', 'height_findings', 'Height', $is_nurse);
                        render_history_exam_row($record, 'weight_normal', 'weight_findings', 'Weight', $is_nurse);
                        render_history_exam_row($record, 'bp_normal', 'bp_findings', 'Blood Pressure', $is_nurse);
                        render_history_exam_row($record, 'pulse_normal', 'pulse_findings', 'Pulse Rate', $is_nurse);
                        render_history_exam_row($record, 'vision_normal', 'vision_findings', 'Vision: R20/ L20/', $is_nurse);
                        render_history_exam_row($record, 'appearance_normal', 'appearance_findings', 'Appearance', $is_nurse);
                        render_history_exam_row($record, 'eent_normal', 'eent_findings', 'Eyes/Ear/Nose/Throat', $is_nurse);
                        render_history_exam_row($record, 'pupils_normal', 'pupils_findings', 'Pupils Equal', $is_nurse);
                        render_history_exam_row($record, 'hearning_normal', 'hearing_findings', 'Hearing', $is_nurse);
                        render_history_exam_row($record, 'chest_normal', 'chest_findings', 'Chest', $is_nurse);
                        render_history_exam_row($record, 'heart_normal', 'heart_findings', 'Heart', $is_nurse);
                        ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Right Table -->
            <div class="overflow-x-auto">
                <table class="min-w-full border-2 border-maroon rounded-lg overflow-hidden mb-6 bg-white shadow">
                    <thead>
                        <tr class="bg-maroon-light">
                            <th class="border border-maroon px-4 py-2 text-maroon">REVIEW OF SYSTEM</th>
                            <th class="border border-maroon px-4 py-2 text-maroon">FINDINGS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        render_history_exam_row($record, 'abdomen_normal', 'abdomen_findings', 'Abdomen', $is_nurse);
                        render_history_exam_row($record, 'genitourinary_normal', 'genitourinary_findings', 'Genitourinary (MALES ONLY)', $is_nurse);
                        render_history_exam_row($record, 'neurologic_normal', 'neurologic_findings', 'Neurologic', $is_nurse);
                        render_history_exam_row($record, 'neck_normal', 'neck_findings', 'Neck', $is_nurse);
                        render_history_exam_row($record, 'back_normal', 'back_findings', 'Back', $is_nurse);
                        render_history_exam_row($record, 'shoulder_arm_normal', 'shoulder_arm_findings', 'Shoulder/Arm', $is_nurse);
                        render_history_exam_row($record, 'elbow_forearm_normal', 'elbow_forearm_findings', 'Elbow/Forearm', $is_nurse);
                        render_history_exam_row($record, 'wrist_hand_normal', 'wrist_hand_findings', 'Wrist/Hand/Fingers', $is_nurse);
                        render_history_exam_row($record, 'knee_normal', 'knee_findings', 'Knee', $is_nurse);
                        render_history_exam_row($record, 'leg_ankle_normal', 'leg_ankle_findings', 'Leg/Ankle', $is_nurse);
                        render_history_exam_row($record, 'foot_toes_normal', 'foot_toes_findings', 'Foot/Toes', $is_nurse);
                        ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- General Questions -->
        <h4 class="font-semibold mb-3 text-maroon">General Questions</h4>
        <div class="overflow-x-auto mb-6">
            <table class="min-w-full border-2 border-maroon rounded-lg overflow-hidden bg-white shadow">
                <thead>
                    <tr class="bg-maroon-light">
                        <th class="border border-maroon px-4 py-2 text-maroon">Check the following for your answers:</th>
                        <th class="border border-maroon px-4 py-2 text-maroon">Yes</th>
                        <th class="border border-maroon px-4 py-2 text-maroon">No</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="hover:bg-maroon-50">
                        <td class="border border-maroon px-4 py-2 text-maroon">1. Have you been denied or restricted your participation in sports activities</td>
                        <td class="border border-maroon px-4 py-2 text-center">
                            <input type="radio" name="denied_participation" value="1" class="h-5 w-5 text-maroon border-maroon rounded focus:ring-maroon <?= $is_nurse ? 'nurse-editable' : '' ?>" <?= (isset($record['denied_participation']) && $record['denied_participation'] == 1) ? 'checked' : '' ?> <?= !$is_nurse ? 'disabled' : '' ?>>
                        </td>
                        <td class="border border-maroon px-4 py-2 text-center">
                            <input type="radio" name="denied_participation" value="0" class="h-5 w-5 text-maroon border-maroon rounded focus:ring-maroon <?= $is_nurse ? 'nurse-editable' : '' ?>" <?= (isset($record['denied_participation']) && $record['denied_participation'] == 0) ? 'checked' : '' ?> <?= !$is_nurse ? 'disabled' : '' ?>>
                        </td>
                    </tr>
                    <tr class="hover:bg-maroon-50">
                        <td colspan="3" class="border border-maroon px-4 py-2 text-maroon">
                            &nbsp;&nbsp;&nbsp;&nbsp;Do you have any of the following conditions:
                        </td>
                    </tr>
                    <tr class="hover:bg-maroon-50">
                        <td class="border border-maroon px-4 py-2 text-maroon pl-8">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;a. Asthma</td>
                        <td class="border border-maroon px-4 py-2 text-center">
                            <input type="radio" name="ashtma" value="1" class="h-5 w-5 text-maroon border-maroon rounded focus:ring-maroon <?= $is_nurse ? 'nurse-editable' : '' ?>" <?= (isset($record['ashtma']) && $record['ashtma'] == 1) ? 'checked' : '' ?> <?= !$is_nurse ? 'disabled' : '' ?>>
                        </td>
                        <td class="border border-maroon px-4 py-2 text-center">
                            <input type="radio" name="ashtma" value="0" class="h-5 w-5 text-maroon border-maroon rounded focus:ring-maroon <?= $is_nurse ? 'nurse-editable' : '' ?>" <?= (isset($record['ashtma']) && $record['ashtma'] == 0) ? 'checked' : '' ?> <?= !$is_nurse ? 'disabled' : '' ?>>
                        </td>
                    </tr>
                    <tr class="hover:bg-maroon-50">
                        <td class="border border-maroon px-4 py-2 text-maroon pl-8">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;b. Seizure disorder</td>
                        <td class="border border-maroon px-4 py-2 text-center">
                            <input type="radio" name="seizure_disorder" value="1" class="h-5 w-5 text-maroon border-maroon rounded focus:ring-maroon <?= $is_nurse ? 'nurse-editable' : '' ?>" <?= (isset($record['seizure_disorder']) && $record['seizure_disorder'] == 1) ? 'checked' : '' ?> <?= !$is_nurse ? 'disabled' : '' ?>>
                        </td>
                        <td class="border border-maroon px-4 py-2 text-center">
                            <input type="radio" name="seizure_disorder" value="0" class="h-5 w-5 text-maroon border-maroon rounded focus:ring-maroon <?= $is_nurse ? 'nurse-editable' : '' ?>" <?= (isset($record['seizure_disorder']) && $record['seizure_disorder'] == 0) ? 'checked' : '' ?> <?= !$is_nurse ? 'disabled' : '' ?>>
                        </td>
                    </tr>
                    <tr class="hover:bg-maroon-50">
                        <td class="border border-maroon px-4 py-2 text-maroon pl-8">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;c. Heart problem</td>
                        <td class="border border-maroon px-4 py-2 text-center">
                            <input type="radio" name="heart_problem" value="1" class="h-5 w-5 text-maroon border-maroon rounded focus:ring-maroon <?= $is_nurse ? 'nurse-editable' : '' ?>" <?= (isset($record['heart_problem']) && $record['heart_problem'] == 1) ? 'checked' : '' ?> <?= !$is_nurse ? 'disabled' : '' ?>>
                        </td>
                        <td class="border border-maroon px-4 py-2 text-center">
                            <input type="radio" name="heart_problem" value="0" class="h-5 w-5 text-maroon border-maroon rounded focus:ring-maroon <?= $is_nurse ? 'nurse-editable' : '' ?>" <?= (isset($record['heart_problem']) && $record['heart_problem'] == 0) ? 'checked' : '' ?> <?= !$is_nurse ? 'disabled' : '' ?>>
                        </td>
                    </tr>
                    <tr class="hover:bg-maroon-50">
                        <td class="border border-maroon px-4 py-2 text-maroon pl-8">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;d. Diabetes</td>
                        <td class="border border-maroon px-4 py-2 text-center">
                            <input type="radio" name="diabetes" value="1" class="h-5 w-5 text-maroon border-maroon rounded focus:ring-maroon <?= $is_nurse ? 'nurse-editable' : '' ?>" <?= (isset($record['diabetes']) && $record['diabetes'] == 1) ? 'checked' : '' ?> <?= !$is_nurse ? 'disabled' : '' ?>>
                        </td>
                        <td class="border border-maroon px-4 py-2 text-center">
                            <input type="radio" name="diabetes" value="0" class="h-5 w-5 text-maroon border-maroon rounded focus:ring-maroon <?= $is_nurse ? 'nurse-editable' : '' ?>" <?= (isset($record['diabetes']) && $record['diabetes'] == 0) ? 'checked' : '' ?> <?= !$is_nurse ? 'disabled' : '' ?>>
                        </td>
                    </tr>
                    <tr class="hover:bg-maroon-50">
                        <td class="border border-maroon px-4 py-2 text-maroon pl-8">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;e. High Blood Pressure</td>
                        <td class="border border-maroon px-4 py-2 text-center">
                            <input type="radio" name="high_blood_pressure" value="1" class="h-5 w-5 text-maroon border-maroon rounded focus:ring-maroon <?= $is_nurse ? 'nurse-editable' : '' ?>" <?= (isset($record['high_blood_pressure']) && $record['high_blood_pressure'] == 1) ? 'checked' : '' ?> <?= !$is_nurse ? 'disabled' : '' ?>>
                        </td>
                        <td class="border border-maroon px-4 py-2 text-center">
                            <input type="radio" name="high_blood_pressure" value="0" class="h-5 w-5 text-maroon border-maroon rounded focus:ring-maroon <?= $is_nurse ? 'nurse-editable' : '' ?>" <?= (isset($record['high_blood_pressure']) && $record['high_blood_pressure'] == 0) ? 'checked' : '' ?> <?= !$is_nurse ? 'disabled' : '' ?>>
                        </td>
                    </tr>
                    <tr class="hover:bg-maroon-50">
                        <td class="border border-maroon px-4 py-2 text-maroon">2. Have you had any surgery?</td>
                        <td class="border border-maroon px-4 py-2 text-center">
                            <input type="radio" name="surgery_history" value="1" class="h-5 w-5 text-maroon border-maroon rounded focus:ring-maroon <?= $is_nurse ? 'nurse-editable' : '' ?>" <?= (isset($record['surgery_history']) && $record['surgery_history'] == 1) ? 'checked' : '' ?> <?= !$is_nurse ? 'disabled' : '' ?>>
                        </td>
                        <td class="border border-maroon px-4 py-2 text-center">
                            <input type="radio" name="surgery_history" value="0" class="h-5 w-5 text-maroon border-maroon rounded focus:ring-maroon <?= $is_nurse ? 'nurse-editable' : '' ?>" <?= (isset($record['surgery_history']) && $record['surgery_history'] == 0) ? 'checked' : '' ?> <?= !$is_nurse ? 'disabled' : '' ?>>
                        </td>
                    </tr>
                    <tr class="hover:bg-maroon-50">
                        <td class="border border-maroon px-4 py-2 text-maroon">3. Have you had any discomfort, chest pain or chest tightness in your chest during exercise?</td>
                        <td class="border border-maroon px-4 py-2 text-center">
                            <input type="radio" name="chest_pain" value="1" class="h-5 w-5 text-maroon border-maroon rounded focus:ring-maroon <?= $is_nurse ? 'nurse-editable' : '' ?>" <?= (isset($record['chest_pain']) && $record['chest_pain'] == 1) ? 'checked' : '' ?> <?= !$is_nurse ? 'disabled' : '' ?>>
                        </td>
                        <td class="border border-maroon px-4 py-2 text-center">
                            <input type="radio" name="chest_pain" value="0" class="h-5 w-5 text-maroon border-maroon rounded focus:ring-maroon <?= $is_nurse ? 'nurse-editable' : '' ?>" <?= (isset($record['chest_pain']) && $record['chest_pain'] == 0) ? 'checked' : '' ?> <?= !$is_nurse ? 'disabled' : '' ?>>
                        </td>
                    </tr>
                    <tr class="hover:bg-maroon-50">
                        <td class="border border-maroon px-4 py-2 text-maroon">4. Have you had any injury to the bones, muscle, ligament or tendon?</td>
                        <td class="border border-maroon px-4 py-2 text-center">
                            <input type="radio" name="injury_history" value="1" class="h-5 w-5 text-maroon border-maroon rounded focus:ring-maroon <?= $is_nurse ? 'nurse-editable' : '' ?>" <?= (isset($record['injury_history']) && $record['injury_history'] == 1) ? 'checked' : '' ?> <?= !$is_nurse ? 'disabled' : '' ?>>
                        </td>
                        <td class="border border-maroon px-4 py-2 text-center">
                            <input type="radio" name="injury_history" value="0" class="h-5 w-5 text-maroon border-maroon rounded focus:ring-maroon <?= $is_nurse ? 'nurse-editable' : '' ?>" <?= (isset($record['injury_history']) && $record['injury_history'] == 0) ? 'checked' : '' ?> <?= !$is_nurse ? 'disabled' : '' ?>>
                        </td>
                    </tr>
                    <tr class="hover:bg-maroon-50">
                        <td class="border border-maroon px-4 py-2 text-maroon">5. Have you had any injury that requires x-ray, CT scan or MRI, brace, cast or crutches?</td>
                        <td class="border border-maroon px-4 py-2 text-center">
                            <input type="radio" name="xray_history" value="1" class="h-5 w-5 text-maroon border-maroon rounded focus:ring-maroon <?= $is_nurse ? 'nurse-editable' : '' ?>" <?= (isset($record['xray_history']) && $record['xray_history'] == 1) ? 'checked' : '' ?> <?= !$is_nurse ? 'disabled' : '' ?>>
                        </td>
                        <td class="border border-maroon px-4 py-2 text-center">
                            <input type="radio" name="xray_history" value="0" class="h-5 w-5 text-maroon border-maroon rounded focus:ring-maroon <?= $is_nurse ? 'nurse-editable' : '' ?>" <?= (isset($record['xray_history']) && $record['xray_history'] == 0) ? 'checked' : '' ?> <?= !$is_nurse ? 'disabled' : '' ?>>
                        </td>
                    </tr>
                    <tr class="hover:bg-maroon-50">
                        <td class="border border-maroon px-4 py-2 text-maroon">6. Have you had any head injury or concussion?</td>
                        <td class="border border-maroon px-4 py-2 text-center">
                            <input type="radio" name="head_injury" value="1" class="h-5 w-5 text-maroon border-maroon rounded focus:ring-maroon <?= $is_nurse ? 'nurse-editable' : '' ?>" <?= (isset($record['head_injury']) && $record['head_injury'] == 1) ? 'checked' : '' ?> <?= !$is_nurse ? 'disabled' : '' ?>>
                        </td>
                        <td class="border border-maroon px-4 py-2 text-center">
                            <input type="radio" name="head_injury" value="0" class="h-5 w-5 text-maroon border-maroon rounded focus:ring-maroon <?= $is_nurse ? 'nurse-editable' : '' ?>" <?= (isset($record['head_injury']) && $record['head_injury'] == 0) ? 'checked' : '' ?> <?= !$is_nurse ? 'disabled' : '' ?>>
                        </td>
                    </tr>
                    <tr class="hover:bg-maroon-50">
                        <td class="border border-maroon px-4 py-2 text-maroon">7. Do you have frequent muscle cramps when exercising?</td>
                        <td class="border border-maroon px-4 py-2 text-center">
                            <input type="radio" name="muscle_cramps" value="1" class="h-5 w-5 text-maroon border-maroon rounded focus:ring-maroon <?= $is_nurse ? 'nurse-editable' : '' ?>" <?= (isset($record['muscle_cramps']) && $record['muscle_cramps'] == 1) ? 'checked' : '' ?> <?= !$is_nurse ? 'disabled' : '' ?>>
                        </td>
                        <td class="border border-maroon px-4 py-2 text-center">
                            <input type="radio" name="muscle_cramps" value="0" class="h-5 w-5 text-maroon border-maroon rounded focus:ring-maroon <?= $is_nurse ? 'nurse-editable' : '' ?>" <?= (isset($record['muscle_cramps']) && $record['muscle_cramps'] == 0) ? 'checked' : '' ?> <?= !$is_nurse ? 'disabled' : '' ?>>
                        </td>
                    </tr>
                    <tr class="hover:bg-maroon-50">
                        <td class="border border-maroon px-4 py-2 text-maroon">8. Have you had any problems with your eyes or vision?</td>
                        <td class="border border-maroon px-4 py-2 text-center">
                            <input type="radio" name="vision_problems" value="1" class="h-5 w-5 text-maroon border-maroon rounded focus:ring-maroon <?= $is_nurse ? 'nurse-editable' : '' ?>" <?= (isset($record['vision_problems']) && $record['vision_problems'] == 1) ? 'checked' : '' ?> <?= !$is_nurse ? 'disabled' : '' ?>>
                        </td>
                        <td class="border border-maroon px-4 py-2 text-center">
                            <input type="radio" name="vision_problems" value="0" class="h-5 w-5 text-maroon border-maroon rounded focus:ring-maroon <?= $is_nurse ? 'nurse-editable' : '' ?>" <?= (isset($record['vision_problems']) && $record['vision_problems'] == 0) ? 'checked' : '' ?> <?= !$is_nurse ? 'disabled' : '' ?>>
                        </td>
                    </tr>
                    <tr class="hover:bg-maroon-50">
                        <td class="border border-maroon px-4 py-2 text-maroon">9. Are you on a special diet or do you avoid certain types of foods?</td>
                        <td class="border border-maroon px-4 py-2 text-center">
                            <input type="radio" name="special_diet" value="1" class="h-5 w-5 text-maroon border-maroon rounded focus:ring-maroon <?= $is_nurse ? 'nurse-editable' : '' ?>" <?= (isset($record['special_diet']) && $record['special_diet'] == 1) ? 'checked' : '' ?> <?= !$is_nurse ? 'disabled' : '' ?>>
                        </td>
                        <td class="border border-maroon px-4 py-2 text-center">
                            <input type="radio" name="special_diet" value="0" class="h-5 w-5 text-maroon border-maroon rounded focus:ring-maroon <?= $is_nurse ? 'nurse-editable' : '' ?>" <?= (isset($record['special_diet']) && $record['special_diet'] == 0) ? 'checked' : '' ?> <?= !$is_nurse ? 'disabled' : '' ?>>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

                <!-- For Females Only Section - Only show if patient is female -->
        <?php if (isset($record['sex']) && strtolower($record['sex']) === 'female'): ?>
        <div class="female-only-section mt-4">
            <h5 class="text-maroon font-bold">FOR FEMALES ONLY</h5>
            <table class="min-w-full border-2 border-maroon rounded-lg overflow-hidden mb-6 bg-white shadow">
                <thead>
                    <tr class="bg-maroon-light">
                        <th class="border border-maroon px-4 py-2 text-maroon">Check the following for your answers:</th>
                        <th class="border border-maroon px-4 py-2 text-maroon">Yes</th>
                        <th class="border border-maroon px-4 py-2 text-maroon">No</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="hover:bg-maroon-50">
                        <td class="border border-maroon px-4 py-2 text-maroon">10. Have you ever had a menstrual period? (LMP)</td>
                        <td class="border border-maroon px-4 py-2 text-center">
                            <input type="radio" name="menstrual_history" value="1" class="h-5 w-5 text-maroon border-maroon rounded focus:ring-maroon <?= $is_nurse ? 'nurse-editable' : '' ?>" <?= (isset($record['menstrual_history']) && $record['menstrual_history'] == 1) ? 'checked' : '' ?> <?= !$is_nurse || strtolower($record['sex']) !== 'female' ? 'disabled' : '' ?>>
                        </td>
                        <td class="border border-maroon px-4 py-2 text-center">
                            <input type="radio" name="menstrual_history" value="0" class="h-5 w-5 text-maroon border-maroon rounded focus:ring-maroon <?= $is_nurse ? 'nurse-editable' : '' ?>" <?= (isset($record['menstrual_history']) && $record['menstrual_history'] == 0) ? 'checked' : '' ?> <?= !$is_nurse || strtolower($record['sex']) !== 'female' ? 'disabled' : '' ?>>
                        </td>
                    </tr>
                    <tr class="hover:bg-maroon-50">
                        <td class="border border-maroon px-4 py-2 text-maroon">11. How old were you when you had your first menstrual period?</td>
                        <td class="border border-maroon px-4 py-2" colspan="2">
                            <?= render_editable_field($record, 'first_menstrual_age', $is_nurse, false, 'text', strtolower($record['sex']) !== 'female') ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        <!-- END For Females Only Section -->

        <!-- Certification Section for History Form (Only for doctors/physicians) -->
        <?php if (($user_role === 'doctor' || $user_role === 'physician') && ($type === 'history_form' || $type === 'medical_form' || $type === 'medical_exam')): ?>
        <div class="certification-section rounded-lg p-4 mb-6 border-2 border-green-500 bg-green-50">
            <h3 class="font-semibold text-lg mb-4 text-green-800">CERTIFICATION</h3>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- LEFT COLUMN -->
                <div class="space-y-4">
                    <p class="text-sm text-green-800 font-medium mb-3">
                        "I hereby certify that the above information given are true and correct as to the best of my knowledge."
                    </p>
                    
                    <div class="mt-4">
                        <div class="border-b-2 border-green-400 pt-4 pb-1 min-h-[40px] mb-2">
                            <span class="text-sm text-gray-600"><?= htmlspecialchars(ucwords(strtolower($record['first_name'] . ' ' . $record['last_name']))) ?></span>
                        </div>
                        <div>
                            <label class="block font-medium mb-1 text-green-700">Date:</label>
                            <?php
                            $student_certification_date = !empty($record['student_certification_date']) ? $record['student_certification_date'] : date('Y-m-d');
                            ?>
                            <input type="date" name="student_certification_date" value="<?= htmlspecialchars($student_certification_date) ?>" 
                                   class="w-full rounded border border-green-300 px-3 py-2 text-sm <?= $is_nurse ? 'nurse-editable' : '' ?>" 
                                   <?= !$is_nurse ? 'readonly' : '' ?>>
                        </div>
                    </div>
                </div>
                
                <!-- RIGHT COLUMN -->
                <div class="space-y-4">
                    <div>
                        <label class="block font-medium mb-2 text-green-700">Examined by:</label>
                        <div class="space-y-2">
                            <?php
                            // Get current logged-in user's information
                            $current_user_id = $_SESSION['user_id'] ?? 0;
                            $examiner_name = '';
                            $license_no = '0169430'; // Sample license number
                            
                            if ($current_user_id) {
                                // Fetch user details from database
                                $user_query = $conn->prepare("SELECT full_name, username, role FROM users WHERE id = ?");
                                $user_query->bind_param("i", $current_user_id);
                                $user_query->execute();
                                $user_result = $user_query->get_result();
                                
                                if ($user_result->num_rows > 0) {
                                    $user_data = $user_result->fetch_assoc();
                                    
                                    // Use full_name if available, otherwise use username
                                    if (!empty($user_data['full_name']) && trim($user_data['full_name']) !== '') {
                                        $examiner_name = trim($user_data['full_name']);
                                    } else {
                                        $examiner_name = $user_data['username'];
                                    }
                                    
                                    // Add title based on role
                                    if ($user_data['role'] === 'doctor' || $user_data['role'] === 'physician') {
                                        $examiner_name .= ' M.D.';
                                    } elseif ($user_data['role'] === 'dentist') {
                                        $examiner_name .= ' D.M.D.';
                                    }
                                }
                                $user_query->close();
                            }
                            
                            // If no user found, fallback to session username
                            if (empty($examiner_name) && isset($_SESSION['username'])) {
                                $examiner_name = $_SESSION['username'];
                            }
                            
                            // Final fallback
                            if (empty($examiner_name)) {
                                $examiner_name = 'MARSON KIM L. PERMENTILLA M.D.';
                            }
                            
                            // Check if there's already a physician name in the record
                            if (!empty($record['physician_name'])) {
                                $examiner_name = $record['physician_name'];
                            }
                            
                            $physician_certification_date = !empty($record['physician_certification_date']) ? $record['physician_certification_date'] : date('Y-m-d');
                            ?>
                            <input type="text" name="physician_name" value="<?= htmlspecialchars($examiner_name) ?>" 
                                   class="w-full rounded border border-green-300 px-3 py-2 text-sm <?= $is_nurse ? 'nurse-editable' : '' ?>" 
                                   <?= !$is_nurse ? 'readonly' : '' ?>>
                            <div class="flex items-center gap-2">
                                <span class="font-medium text-green-700">License No.:</span>
                                <input type="text" name="license_no" value="<?= htmlspecialchars($license_no) ?>" 
                                       class="w-32 rounded border border-green-300 px-3 py-2 text-sm <?= $is_nurse ? 'nurse-editable' : '' ?>" 
                                       <?= !$is_nurse ? 'readonly' : '' ?>>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="font-medium text-green-700">Date:</span>
                                <input type="date" name="physician_certification_date" value="<?= htmlspecialchars($physician_certification_date) ?>" 
                                       class="rounded border border-green-300 px-3 py-2 text-sm <?= $is_nurse ? 'nurse-editable' : '' ?>" 
                                       <?= !$is_nurse ? 'readonly' : '' ?>>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <!-- END CERTIFICATION SECTION for History Form -->

        <!-- MODIFIED: COMBINED MEDICAL NOTES SECTION for History Forms -->
        <div class="medical-diagnoses-section rounded-lg p-4 mb-6 border-2 border-blue-500 bg-blue-50">
            <h3 class="font-semibold text-lg mb-4 text-blue-800">MEDICAL DIAGNOSIS NOTES</h3>
            
            <?php 
            // Check who can add/edit notes
            $can_add_nurse_notes = ($user_role === 'nurse' || $user_role === 'admin' || $user_role === 'staff');
            $can_add_doctor_notes = ($user_role === 'doctor' || $user_role === 'physician' || $user_role === 'admin' || $user_role === 'staff');
            ?>
            
            <!-- Combined Diagnosis Form -->
            <div class="mb-6 p-4 bg-white rounded-lg border border-blue-300">
                <h4 class="font-semibold mb-3 text-blue-700">Medical Diagnosis Notes</h4>
                <form method="POST" id="medical-diagnosis-form">
                    <input type="hidden" name="record_id" value="<?= $record['record_id']; ?>">
                    <input type="hidden" name="patient_id" value="<?= $record['patient_id']; ?>">
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Nurse Notes Section -->
                        <div class="border-r border-blue-300 pr-6">
                            <div class="flex items-center gap-2 mb-3">
                                <i class="bi bi-person-badge text-blue-600"></i>
                                <h5 class="font-semibold text-blue-700">Nurse Notes</h5>
                                <?php if (!empty($existing_diagnosis['nurse_diagnosis_date'])): ?>
                                    <span class="ml-auto text-xs text-gray-500">
                                        Last updated: <?= date('M j, Y', strtotime($existing_diagnosis['nurse_diagnosis_date'])) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="mb-4">
                                <label class="block font-medium mb-2 text-blue-700">Nurse Assessment:</label>
                                <textarea name="nurse_note" rows="6" 
                                          class="w-full rounded border border-blue-300 px-3 py-2 text-sm"
                                          placeholder="Enter nurse assessment and notes here..." 
                                          <?= !$can_add_nurse_notes ? 'readonly' : '' ?>
                                          <?= !$can_add_nurse_notes ? 'disabled' : '' ?>><?= 
                                          !empty($existing_diagnosis['nurse_note']) ? htmlspecialchars($existing_diagnosis['nurse_note']) : '' ?></textarea>
                            </div>
                            
                            <?php if ($can_add_nurse_notes): ?>
                                <button type="submit" name="add_medical_diagnosis" value="nurse"
                                        class="bg-blue-600 text-white px-4 py-2 rounded-lg shadow font-semibold hover:bg-blue-700 hover:shadow-lg transition-all">
                                    <i class="bi bi-save"></i> Save Nurse Notes
                                </button>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Doctor Notes Section -->
                        <div class="pl-0 md:pl-6">
                            <div class="flex items-center gap-2 mb-3">
                                <i class="bi bi-heart-pulse text-red-600"></i>
                                <h5 class="font-semibold text-red-700">Physician/Doctor Notes</h5>
                                <?php if (!empty($existing_diagnosis['doctor_diagnosis_date'])): ?>
                                    <span class="ml-auto text-xs text-gray-500">
                                        Last updated: <?= date('M j, Y', strtotime($existing_diagnosis['doctor_diagnosis_date'])) ?>
                                    </span>
                            <?php endif; ?>
                            </div>
                            
                            <div class="mb-4">
                                <label class="block font-medium mb-2 text-red-700">Physician Assessment:</label>
                                <textarea name="doctor_note" rows="6" 
                                          class="w-full rounded border border-red-300 px-3 py-2 text-sm"
                                          placeholder="Enter physician assessment and notes here..." 
                                          <?= !$can_add_doctor_notes ? 'readonly' : '' ?>
                                          <?= !$can_add_doctor_notes ? 'disabled' : '' ?>><?= 
                                          !empty($existing_diagnosis['doctor_note']) ? htmlspecialchars($existing_diagnosis['doctor_note']) : '' ?></textarea>
                            </div>
                            
                            <?php if ($can_add_doctor_notes): ?>
                                <button type="submit" name="add_medical_diagnosis" value="doctor"
                                        class="bg-red-600 text-white px-4 py-2 rounded-lg shadow font-semibold hover:bg-red-700 hover:shadow-lg transition-all">
                                    <i class="bi bi-save"></i> Save Doctor Notes
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <input type="hidden" name="diagnosis_type" id="diagnosis_type" value="">
                    
                    <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        // Set diagnosis type based on which button is clicked
                        const nurseBtn = document.querySelector('button[name="add_medical_diagnosis"][value="nurse"]');
                        const doctorBtn = document.querySelector('button[name="add_medical_diagnosis"][value="doctor"]');
                        
                        if (nurseBtn) {
                            nurseBtn.addEventListener('click', function(e) {
                                document.getElementById('diagnosis_type').value = 'nurse';
                            });
                        }
                        
                        if (doctorBtn) {
                            doctorBtn.addEventListener('click', function(e) {
                                document.getElementById('diagnosis_type').value = 'doctor';
                            });
                        }
                    });
                    </script>
                    
                    <p class="text-xs text-gray-500 mt-3">
                        Notes will be attributed to you as 
                        <span class="font-semibold"><?= htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Unknown User') ?></span>
                        and dated for today.
                    </p>
                </form>
            </div>

            <!-- Display Existing Notes -->
            <div class="space-y-6">
                <?php if (!empty($existing_diagnosis)): ?>
                    <!-- Combined Notes Display -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Nurse Notes Display -->
                        <?php if (!empty($existing_diagnosis['nurse_note'])): ?>
                            <div class="bg-white rounded-lg border border-blue-200 p-4">
                                <div class="flex justify-between items-start mb-3">
                                    <div class="flex items-center gap-2">
                                        <span class="font-semibold text-blue-700">
                                            <?= htmlspecialchars($existing_diagnosis['provider_full_name'] ?? $existing_diagnosis['provider_name']) ?>
                                    </span>
                                        <span class="text-sm px-2 py-1 rounded-full bg-blue-100 text-blue-800">
                                            <i class="bi bi-person-badge"></i> Nurse Notes
                                        </span>
                                    </div>
                                    <?php if (!empty($existing_diagnosis['nurse_diagnosis_date'])): ?>
                                        <div class="text-sm text-gray-500">
                                            <?= date('M j, Y', strtotime($existing_diagnosis['nurse_diagnosis_date'])) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="mt-3">
                                    <div class="text-gray-800"><?= nl2br(htmlspecialchars($existing_diagnosis['nurse_note'])) ?></div>
                                </div>
                                
                                <!-- Delete button (only shown to notes owner or admin) -->
                                <?php if (($existing_diagnosis['provider_id'] == $_SESSION['user_id'] || $user_role === 'admin') && $can_add_nurse_notes): ?>
                                    <div class="mt-3 pt-3 border-t border-gray-200">
                                        <form method="POST" onsubmit="return confirm('Are you sure you want to delete these notes?');">
                                            <input type="hidden" name="diagnosis_id" value="<?= $existing_diagnosis['id']; ?>">
                                            <button type="submit" name="delete_medical_diagnosis"
                                                    class="text-red-600 hover:text-red-800 text-sm font-medium">
                                                <i class="bi bi-trash"></i> Delete Nurse Notes
                                        </button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="bg-gray-50 rounded-lg border border-gray-200 p-4 text-center">
                                <i class="bi bi-person-badge text-3xl text-gray-400 mb-2"></i>
                                <p class="text-gray-500">No nurse notes recorded yet.</p>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Doctor Notes Display -->
                        <?php if (!empty($existing_diagnosis['doctor_note'])): ?>
                            <div class="bg-white rounded-lg border border-red-200 p-4">
                                <div class="flex justify-between items-start mb-3">
                                    <div class="flex items-center gap-2">
                                        <span class="font-semibold text-red-700">
                                            <?= htmlspecialchars($existing_diagnosis['provider_full_name'] ?? $existing_diagnosis['provider_name']) ?>
                                        </span>
                                        <span class="text-sm px-2 py-1 rounded-full bg-red-100 text-red-800">
                                            <i class="bi bi-heart-pulse"></i> Physician Notes
                                        </span>
                                    </div>
                                    <?php if (!empty($existing_diagnosis['doctor_diagnosis_date'])): ?>
                                        <div class="text-sm text-gray-500">
                                            <?= date('M j, Y', strtotime($existing_diagnosis['doctor_diagnosis_date'])) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="mt-3">
                                    <div class="text-gray-800"><?= nl2br(htmlspecialchars($existing_diagnosis['doctor_note'])) ?></div>
                                </div>
                                
                                <!-- Delete button (only shown to notes owner or admin) -->
                                <?php if (($existing_diagnosis['provider_id'] == $_SESSION['user_id'] || $user_role === 'admin') && $can_add_doctor_notes): ?>
                                    <div class="mt-3 pt-3 border-t border-gray-200">
                                        <form method="POST" onsubmit="return confirm('Are you sure you want to delete these notes?');">
                                            <input type="hidden" name="diagnosis_id" value="<?= $existing_diagnosis['id']; ?>">
                                            <button type="submit" name="delete_medical_diagnosis"
                                                    class="text-red-600 hover:text-red-800 text-sm font-medium">
                                                <i class="bi bi-trash"></i> Delete Doctor Notes
                                            </button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="bg-gray-50 rounded-lg border border-gray-200 p-4 text-center">
                                <i class="bi bi-heart-pulse text-3xl text-gray-400 mb-2"></i>
                                <p class="text-gray-500">No physician notes recorded yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-8 text-gray-500 bg-white rounded-lg border border-blue-200">
                        <i class="bi bi-clipboard-pulse text-4xl mb-3"></i>
                        <p class="text-lg">No medical diagnosis notes recorded yet.</p>
                        <p class="text-sm mt-2">Use the form above to add nurse and/or physician notes.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <!-- END MODIFIED MEDICAL NOTES SECTION for History Forms -->

        <!-- ACTION BUTTONS FOR HISTORY FORM -->
        <div class="mt-6 pt-6 border-t border-maroon-200 no-print">
            <div class="flex justify-center gap-4 flex-wrap">
                <?php if ($is_nurse): ?>
                    <button type="submit" form="record-form" name="update_record"
                            class="maroon-gradient-button text-white px-6 py-2 rounded-lg shadow font-semibold hover:shadow-lg transition-all">
                        <i class="bi bi-check-circle"></i> Update Record
                    </button>
                    <form method="POST" onsubmit="return confirm('Are you sure you want to delete this record? This action cannot be undone.');" class="inline">
                        <input type="hidden" name="record_id" value="<?= $record['record_id']; ?>">
                        <input type="hidden" name="record_type" value="<?= $type; ?>">
                        <button type="submit" name="delete"
                                class="maroon-gradient-button text-white px-6 py-2 rounded-lg shadow font-semibold hover:shadow-lg transition-all">
                            <i class="bi bi-trash"></i> Delete Record
                        </button>
                    </form>
                    
                    <!-- Submit for Certification Button -->
                    <?php if (!$is_certified && $user_role !== 'doctor' && $user_role !== 'dentist' && $user_role !== 'physician'): ?>
                        <button type="button" onclick="saveAndMarkForCertification()"
                                class="bg-green-500 text-white px-6 py-2 rounded-lg shadow font-semibold hover:bg-green-600 hover:shadow-lg transition-all">
                            <i class="bi bi-award"></i> Save & Submit for Certification
                        </button>
                    <?php elseif ($is_certified && $user_role !== 'doctor' && $user_role !== 'dentist' && $user_role !== 'physician'): ?>
                        <button type="button" disabled
                                class="bg-gray-400 text-white px-6 py-2 rounded-lg shadow font-semibold cursor-not-allowed">
                            <i class="bi bi-award-fill"></i> Already Submitted for Certification
                        </button>
                    <?php endif; ?>
                <?php endif; ?>
                
                <!-- Doctor/Physician Certification - FIXED: Changed to mark as 'certified' -->
                <?php if (($user_role === 'doctor' || $user_role === 'physician') && ($type === 'history_form' || $type === 'medical_form' || $type === 'medical_exam')): ?>
                    <?php if ($record['verification_status'] !== 'certified'): ?>
                        <button type="button" onclick="saveAndCertify()"
                                class="bg-green-500 text-white px-6 py-2 rounded-lg shadow font-semibold hover:bg-green-600 hover:shadow-lg transition-all">
                            <i class="bi bi-award"></i> Save & Certify
                        </button>
                    <?php else: ?>
                        <button type="button" onclick="saveAndPrintCertified()"
                               class="bg-blue-600 text-white px-6 py-2 rounded-lg shadow font-semibold hover:bg-blue-700 hover:shadow-lg transition-all">
                            <i class="bi bi-printer"></i> Print Certified Form
                        </button>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <!-- END ACTION BUTTONS FOR HISTORY FORM -->

    </div> <!-- This closes the history-section div -->

       
        <?php endif; ?>
    </div>
<?php endif; ?>
                              
            </form>

    
    
   
    
</div> <!-- This closes the min-h-screen py-10 px-6 container -->

<script>
function refreshPage() {
    window.location.reload();
}

// Show success notification if present
document.addEventListener('DOMContentLoaded', function() {
    <?php if ($success_message): ?>
        setTimeout(function() {
            const successDiv = document.querySelector('.bg-green-100');
            if (successDiv) {
                successDiv.style.transition = 'opacity 0.5s ease';
                successDiv.style.opacity = '0';
                setTimeout(() => successDiv.remove(), 500);
            }
        }, 5000);
    <?php endif; ?>
});

// Dental Chart Functions - MUST BE IN GLOBAL SCOPE
function updateDentalChartHiddenField() {
    const hiddenField = document.getElementById('dental_chart_data');
    if (hiddenField) {
        hiddenField.value = JSON.stringify(window.dentalChartState || {});
    }
}

function validateDentalChartData() {
    try {
        const hiddenField = document.getElementById('dental_chart_data');
        if (hiddenField) {
            JSON.parse(hiddenField.value);
            alert("Dental chart data is valid JSON!");
        }
    } catch (e) {
        alert("Error in dental chart data: " . e.message);
    }
}

// Function to format dental chart data for display
function formatDentalChartDataForDisplay(chartData) {
    if (!chartData || Object.keys(chartData).length === 0) {
        return '<div class="text-center py-4 text-gray-500">No dental chart data recorded.</div>';
    }
    
    // Group teeth by condition
    const conditions = {};
    Object.keys(chartData).forEach(toothNumber => {
        const data = chartData[toothNumber];
        const condition = data.condition || 'none';
        const label = data.label || '';
        const text = data.text || condition.charAt(0).toUpperCase() + condition.slice(1);
        
        if (condition !== 'none' && condition !== '') {
            if (!conditions[condition]) {
                conditions[condition] = {
                    teeth: [],
                    label: label,
                    text: text
                };
            }
            conditions[condition]['teeth'].push(parseInt(toothNumber));
        }
    });
    
    if (Object.keys(conditions).length === 0) {
        return 'All teeth are healthy or no conditions marked.';
    }
    
    let html = '<div class="space-y-3">';
    
    const conditionInfo = {
        'healthy': {color: 'bg-green-100', border: 'border-green-300', textColor: 'text-green-800', icon: '✓'},
        'caries': {color: 'bg-red-100', border: 'border-red-300', textColor: 'text-red-800', icon: '⚠'},
        'filling': {color: 'bg-blue-100', border: 'border-blue-300', textColor: 'text-blue-800', icon: '◉'},
        'extraction': {color: 'bg-yellow-100', border: 'border-yellow-300', textColor: 'text-yellow-800', icon: '✕'},
        'crown': {color: 'bg-purple-100', border: 'border-purple-300', textColor: 'text-purple-800', icon: '◈'}
    };
    
    Object.keys(conditions).forEach(condition => {
        const data = conditions[condition];
        const info = conditionInfo[condition] || {
            color: 'bg-gray-100',
            border: 'border-gray-300',
            textColor: 'text-gray-800',
            icon: '•'
        };
        
        data.teeth.sort((a, b) => a - b);
        
        html += `
            <div class="${info.color} ${info.border} border-2 rounded-lg p-4">
                <div class="flex items-center gap-3 mb-2">
                    <span class="text-xl font-bold ${info.textColor}">${info.icon}</span>
                    <h5 class="font-semibold text-lg ${info.textColor}">${data.text}</h5>
                    <span class="ml-auto text-sm font-medium ${info.textColor}">(${data.teeth.length} tooth${data.teeth.length > 1 ? 'teeth' : ''})</span>
                </div>
                <div class="flex flex-wrap gap-2 mt-2">
        `;
        
        data.teeth.forEach(tooth => {
            html += `
                <span class="px-3 py-1 bg-white rounded-full border-2 ${info.border} font-semibold ${info.textColor} text-sm">
                    Tooth #${tooth}
                </span>
            `;
        });
        
        html += `
                </div>
            </div>
        `;
    });
    
    html += '</div>';
    return html;
}

// Function to update formatted view from JavaScript state
function updateFormattedDentalDataView() {
    const formattedView = document.getElementById('formatted-dental-data');
    const placeholder = document.getElementById('dental-data-placeholder');
    
    // Only update if formatted view is visible (not hidden)
    if (formattedView && !formattedView.classList.contains('hidden') && window.dentalChartState) {
        const formattedHTML = formatDentalChartDataForDisplay(window.dentalChartState);
        formattedView.innerHTML = formattedHTML;
    } else if (placeholder && window.dentalChartState) {
        // If placeholder exists, replace it with formatted HTML
        const formattedHTML = formatDentalChartDataForDisplay(window.dentalChartState);
        placeholder.outerHTML = formattedHTML;
    }
}

// Function to toggle between formatted and raw JSON view
function toggleDentalDataView() {
    const formattedView = document.getElementById('formatted-dental-data');
    const rawView = document.getElementById('raw-dental-data');
    const toggleText = document.getElementById('toggle-text');
    
    if (formattedView && rawView && toggleText) {
        if (formattedView.classList.contains('hidden')) {
            // Show formatted view - update it from current state
            formattedView.classList.remove('hidden');
            rawView.classList.add('hidden');
            toggleText.textContent = 'Show Raw JSON';
            updateFormattedDentalDataView();
        } else {
            // Show raw JSON view
            formattedView.classList.add('hidden');
            rawView.classList.remove('hidden');
            toggleText.textContent = 'Show Formatted View';
            // Update textarea with current state
            const textarea = document.getElementById('dental_chart_data');
            if (textarea && window.dentalChartState) {
                textarea.value = JSON.stringify(window.dentalChartState, null, 2);
            }
        }
    }
}

// Initialize dental chart state
window.dentalChartState = <?php 
    if (!empty($record['dental_chart_data']) && $record['dental_chart_data'] !== 'null' && $record['dental_chart_data'] !== '""') {
        $chartData = json_decode($record['dental_chart_data'], true);
        if ($chartData && is_array($chartData)) {
            echo json_encode($chartData);
        } else {
            echo '{}';
        }
    } else {
        echo '{}';
    }
?>;

// Update formatted view when page loads if data exists
document.addEventListener('DOMContentLoaded', function() {
    updateFormattedDentalDataView();
});

// FIX: Updated tooth condition toggle function with proper color handling
window.toggleToothCondition = function(toothElement) {
    const toothNumber = parseInt(toothElement.getAttribute('data-tooth'));
    const conditionContainer = toothElement.parentElement.querySelector('.tooth-condition');
    
    // Cycle through conditions
    const conditions = [
        { name: 'healthy', bg: 'bg-green-500', text: 'text-white', border: 'border-green-600', label: 'H', displayText: 'Healthy' },
        { name: 'caries', bg: 'bg-red-500', text: 'text-white', border: 'border-red-600', label: 'C', displayText: 'Caries' },
        { name: 'filling', bg: 'bg-blue-500', text: 'text-white', border: 'border-blue-600', label: 'F', displayText: 'Filling' },
        { name: 'extraction', bg: 'bg-yellow-500', text: 'text-gray-800', border: 'border-yellow-600', label: 'E', displayText: 'Extraction' },
        { name: 'crown', bg: 'bg-purple-500', text: 'text-white', border: 'border-purple-600', label: 'CB', displayText: 'Crown/Bridge' },
        { name: 'none', bg: 'bg-white', text: 'text-gray-800', border: 'border-gray-300', label: '', displayText: 'None' }
    ];

    const currentCondition = window.dentalChartState[toothNumber]?.condition || 'none';
    const currentIndex = conditions.findIndex(cond => cond.name === currentCondition);
    const nextIndex = (currentIndex + 1) % conditions.length;
    const nextCondition = conditions[nextIndex];

    if (nextCondition.name === 'none') {
        // Remove condition
        delete window.dentalChartState[toothNumber];
        // Reset to default appearance
        toothElement.className = toothElement.className.replace(/bg-\w+-\d+/g, '')
            .replace(/text-\w+-\d+/g, '')
            .replace(/border-\w+-\d+/g, '')
            + ' bg-white text-gray-800 border-2 border-gray-300';
        conditionContainer.textContent = '';
        conditionContainer.className = 'tooth-condition text-xs mt-1 text-center min-h-[16px]';
    } else {
        // Set new condition
        window.dentalChartState[toothNumber] = {
            condition: nextCondition.name,
            label: nextCondition.label,
            text: nextCondition.displayText
        };
        
        // Update visual appearance - remove all color classes first
        toothElement.className = toothElement.className.replace(/bg-\w+-\d+/g, '')
            .replace(/text-\w+-\d+/g, '')
            .replace(/border-\w+-\d+/g, '')
            + ` ${nextCondition.bg} ${nextCondition.text} ${nextCondition.border}`;
        
        // Update condition label
        conditionContainer.textContent = nextCondition.label;
        conditionContainer.className = `tooth-condition text-xs mt-1 text-center min-h-[16px] font-semibold`;
    }

    updateDentalChartSummary();
    updateDentalChartHiddenField();
    // Update formatted dental data view in real-time
    updateFormattedDentalDataView();
}

window.updateDentalChartSummary = function() {
    const summaryElement = document.getElementById('selected-teeth-summary');
    if (!summaryElement) return;
    
    const selectedTeeth = Object.keys(window.dentalChartState);
    
    if (selectedTeeth.length === 0) {
        summaryElement.innerHTML = 'No teeth conditions marked. Click on teeth to mark conditions.';
        return;
    }

    let summaryHTML = '<div class="grid grid-cols-1 md:grid-cols-2 gap-2">';
    
    // Group by condition
    const conditions = {};
    selectedTeeth.forEach(toothNumber => {
        const condition = window.dentalChartState[toothNumber];
        if (!conditions[condition.condition]) {
            conditions[condition.condition] = [];
        }
        conditions[condition.condition].push(toothNumber);
    });

    Object.keys(conditions).forEach(condition => {
        const teeth = conditions[condition];
        const conditionInfo = getConditionInfo(condition);
        summaryHTML += `
            <div class="flex items-center gap-2 p-2 bg-white rounded border">
                <div class="w-3 h-3 rounded-full ${conditionInfo.color}"></div>
                <span class="font-medium">${conditionInfo.text}:</span>
                <span class="text-gray-700">Teeth ${teeth.join(', ')}</span>
            </div>
        `;
    });

    summaryHTML += '</div>';
    summaryElement.innerHTML = summaryHTML;
}

window.getConditionInfo = function(condition) {
    const conditions = {
        'healthy': { color: 'bg-green-500', text: 'Healthy' },
        'caries': { color: 'bg-red-500', text: 'Caries (Cavity)' },
        'filling': { color: 'bg-blue-500', text: 'Filling' },
        'extraction': { color: 'bg-yellow-500', text: 'Extraction Needed' },
        'crown': { color: 'bg-purple-500', text: 'Crown/Bridge' }
    };
    return conditions[condition] || { color: 'bg-gray-500', text: 'Unknown' };
}

window.resetDentalChart = function() {
    if (!confirm('Are you sure you want to reset the entire dental chart? This cannot be undone.')) {
        return;
    }
    
    // Reset all teeth to default state
    const allTeeth = document.querySelectorAll('.tooth');
    allTeeth.forEach(tooth => {
        const toothNumber = parseInt(tooth.getAttribute('data-tooth'));
        const conditionContainer = tooth.parentElement.querySelector('.tooth-condition');
        
        delete window.dentalChartState[toothNumber];
        tooth.className = tooth.className.replace(/bg-\w+-\d+/g, '')
            .replace(/text-\w+-\d+/g, '')
            .replace(/border-\w+-\d+/g, '')
            + ' bg-white text-gray-800 border-2 border-gray-300';
        conditionContainer.textContent = '';
        conditionContainer.className = 'tooth-condition text-xs mt-1 text-center min-h-[16px]';
    });
    
    updateDentalChartSummary();
    updateDentalChartHiddenField();
    // Update formatted dental data view after reset
    updateFormattedDentalDataView();
    
    // Show confirmation message
    alert('Dental chart has been reset!');
}

// Function to save and mark for certification
function saveAndMarkForCertification() {
    if (!confirm('Are you sure you want to save and submit this record for certification?')) {
        return;
    }
    
    const form = document.getElementById('record-form');
    if (form) {
        // Create hidden inputs for save and mark for certification
        const updateInput = document.createElement('input');
        updateInput.type = 'hidden';
        updateInput.name = 'update_record';
        updateInput.value = '1';
        form.appendChild(updateInput);
        
        const certifyInput = document.createElement('input');
        certifyInput.type = 'hidden';
        certifyInput.name = 'mark_for_certification';
        certifyInput.value = '1';
        form.appendChild(certifyInput);
        
        // Submit the form
        form.submit();
    }
}

// Function to save and mark as certified (for doctors/physicians)
function saveAndCertify() {
    if (!confirm('Are you sure you want to save and certify this record? This will mark the form as certified.')) {
        return;
    }
    
    const form = document.getElementById('record-form');
    if (form) {
        // Create hidden inputs for save and certify
        const updateInput = document.createElement('input');
        updateInput.type = 'hidden';
        updateInput.name = 'update_record';
        updateInput.value = '1';
        form.appendChild(updateInput);
        
        const certifyInput = document.createElement('input');
        certifyInput.type = 'hidden';
        certifyInput.name = 'certify_after_save';
        certifyInput.value = '1';
        form.appendChild(certifyInput);
        
        // Submit the form
        form.submit();
    }
}

// Function to print certified form (only when already certified)
function saveAndPrintCertified() {
    const form = document.getElementById('record-form');
    if (form) {
        // Create hidden inputs for save and print
        const updateInput = document.createElement('input');
        updateInput.type = 'hidden';
        updateInput.name = 'update_record';
        updateInput.value = '1';
        form.appendChild(updateInput);
        
        const printInput = document.createElement('input');
        printInput.type = 'hidden';
        printInput.name = 'print_after_save';
        printInput.value = '1';
        form.appendChild(printInput);
        
        // Submit the form
        form.submit();
    }
}

// Initialize the chart when page loads
document.addEventListener('DOMContentLoaded', function() {
    // Set initial tooth states from dentalChartState
    if (window.dentalChartState) {
        Object.keys(window.dentalChartState).forEach(toothNumber => {
            const toothElement = document.querySelector(`.tooth[data-tooth="${toothNumber}"]`);
            if (toothElement) {
                const condition = window.dentalChartState[toothNumber];
                const conditionContainer = toothElement.parentElement.querySelector('.tooth-condition');
                const conditionInfo = window.getConditionInfo(condition.condition);
                
                // Apply appropriate classes based on condition
                let bgClass = '';
                let textClass = '';
                let borderClass = '';
                
                switch(condition.condition) {
                    case 'healthy':
                        bgClass = 'bg-green-500';
                        textClass = 'text-white';
                        borderClass = 'border-green-600';
                        break;
                    case 'caries':
                        bgClass = 'bg-red-500';
                        textClass = 'text-white';
                        borderClass = 'border-red-600';
                        break;
                    case 'filling':
                        bgClass = 'bg-blue-500';
                        textClass = 'text-white';
                        borderClass = 'border-blue-600';
                        break;
                    case 'extraction':
                        bgClass = 'bg-yellow-500';
                        textClass = 'text-gray-800';
                        borderClass = 'border-yellow-600';
                        break;
                    case 'crown':
                        bgClass = 'bg-purple-500';
                        textClass = 'text-white';
                        borderClass = 'border-purple-600';
                        break;
                }
                
                // Remove existing color classes and add new ones
                toothElement.className = toothElement.className.replace(/bg-\w+-\d+/g, '')
                    .replace(/text-\w+-\d+/g, '')
                    .replace(/border-\w+-\d+/g, '')
                    + ` ${bgClass} ${textClass} ${borderClass}`;
                
                conditionContainer.textContent = condition.label;
                conditionContainer.className = `tooth-condition text-xs mt-1 text-center min-h-[16px] font-semibold`;
            }
        });
    }
    
    // Initialize summary
    updateDentalChartSummary();
});
</script>
<script>
// Handle form submission for medical exam checkboxes
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('record-form');
    if (form) {
        form.addEventListener('submit', function(e) {
            // Handle hearing status
            const hearingStatus = document.querySelector('input[name="hearing_status"]:checked');
            if (hearingStatus) {
                document.getElementsByName('hearing_normal')[0].value = (hearingStatus.value === 'normal') ? 1 : 0;
                document.getElementsByName('hearing_defective')[0].value = (hearingStatus.value === 'defective') ? 1 : 0;
            }
            
            // Handle chest xray status
            const chestXrayStatus = document.querySelector('input[name="chest_xray_status"]:checked');
            if (chestXrayStatus) {
                document.getElementsByName('chest_xray_findings')[0].value = (chestXrayStatus.value === 'findings') ? 1 : 0;
                // Set chest_xray_normal based on findings
                document.getElementsByName('chest_xray_normal')[0].value = (chestXrayStatus.value === 'normal') ? 1 : 0;
            }
            
            // Handle CBC status
            const cbcStatus = document.querySelector('input[name="cbc_status"]:checked');
            if (cbcStatus) {
                document.getElementsByName('cbc_findings')[0].value = (cbcStatus.value === 'findings') ? 1 : 0;
                document.getElementsByName('cbc_normal')[0].value = (cbcStatus.value === 'normal') ? 1 : 0;
            }
            
            // Handle urinalysis status
            const urinalysisStatus = document.querySelector('input[name="urinalysis_status"]:checked');
            if (urinalysisStatus) {
                document.getElementsByName('urinalysis_findings')[0].value = (urinalysisStatus.value === 'findings') ? 1 : 0;
                document.getElementsByName('urinalysis_normal')[0].value = (urinalysisStatus.value === 'normal') ? 1 : 0;
            }
            
            // Handle stool status
            const stoolStatus = document.querySelector('input[name="stool_status"]:checked');
            if (stoolStatus) {
                document.getElementsByName('stool_findings')[0].value = (stoolStatus.value === 'findings') ? 1 : 0;
                document.getElementsByName('stool_normal')[0].value = (stoolStatus.value === 'normal') ? 1 : 0;
            }
            
            // Handle hepa b status
            const hepaBStatus = document.querySelector('input[name="hepa_b_status"]:checked');
            if (hepaBStatus) {
                document.getElementsByName('hepa_b_findings')[0].value = (hepaBStatus.value === 'findings') ? 1 : 0;
                document.getElementsByName('hepa_b_normal')[0].value = (hepaBStatus.value === 'normal') ? 1 : 0;
            }
        });
    }
});
</script>
<script>
// Function to toggle findings textarea enabled/disabled
function toggleFindingsTextarea(textareaName, enable) {
    const textarea = document.getElementsByName(textareaName)[0];
    if (textarea) {
        textarea.readOnly = !enable;
        textarea.disabled = !enable;
        if (enable) {
            textarea.classList.add('nurse-editable');
        } else {
            textarea.classList.remove('nurse-editable');
        }
    }
}

// Handle form submission for medical exam checkboxes
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('record-form');
    if (form) {
        form.addEventListener('submit', function(e) {
            // Handle hearing status
            const hearingStatus = document.querySelector('input[name="hearing_status"]:checked');
            if (hearingStatus) {
                document.getElementsByName('hearing_normal')[0].value = (hearingStatus.value === 'normal') ? 1 : 0;
                document.getElementsByName('hearing_defective')[0].value = (hearingStatus.value === 'defective') ? 1 : 0;
            }
            
            // Handle vision status
            const visionStatus = document.querySelector('input[name="vision_status"]:checked');
            if (visionStatus) {
                document.getElementsByName('vision_with_glasses')[0].value = (visionStatus.value === 'with_glasses') ? 1 : 0;
                document.getElementsByName('vision_without_glasses')[0].value = (visionStatus.value === 'without_glasses') ? 1 : 0;
            }
            
            // Handle chest xray status
            const chestXrayStatus = document.querySelector('input[name="chest_xray_status"]:checked');
            if (chestXrayStatus) {
                document.getElementsByName('chest_xray_findings')[0].value = (chestXrayStatus.value === 'findings') ? 1 : 0;
                document.getElementsByName('chest_xray_normal')[0].value = (chestXrayStatus.value === 'normal') ? 1 : 0;
            }
            
            // Handle CBC status
            const cbcStatus = document.querySelector('input[name="cbc_status"]:checked');
            if (cbcStatus) {
                document.getElementsByName('cbc_findings')[0].value = (cbcStatus.value === 'findings') ? 1 : 0;
                document.getElementsByName('cbc_normal')[0].value = (cbcStatus.value === 'normal') ? 1 : 0;
            }
            
            // Handle urinalysis status
            const urinalysisStatus = document.querySelector('input[name="urinalysis_status"]:checked');
            if (urinalysisStatus) {
                document.getElementsByName('urinalysis_findings')[0].value = (urinalysisStatus.value === 'findings') ? 1 : 0;
                document.getElementsByName('urinalysis_normal')[0].value = (urinalysisStatus.value === 'normal') ? 1 : 0;
            }
            
            // Handle stool status
            const stoolStatus = document.querySelector('input[name="stool_status"]:checked');
            if (stoolStatus) {
                document.getElementsByName('stool_findings')[0].value = (stoolStatus.value === 'findings') ? 1 : 0;
                document.getElementsByName('stool_normal')[0].value = (stoolStatus.value === 'normal') ? 1 : 0;
            }
            
            // Handle hepa b status
            const hepaBStatus = document.querySelector('input[name="hepa_b_status"]:checked');
            if (hepaBStatus) {
                document.getElementsByName('hepa_b_findings')[0].value = (hepaBStatus.value === 'findings') ? 1 : 0;
                document.getElementsByName('hepa_b_normal')[0].value = (hepaBStatus.value === 'normal') ? 1 : 0;
            }
            
            // Handle methamphetamine status
            const methStatus = document.querySelector('input[name="methamphetamine_status"]:checked');
            if (methStatus) {
                document.getElementsByName('methamphetamine_negative')[0].value = (methStatus.value === 'negative') ? 1 : 0;
                document.getElementsByName('methamphetamine_positive')[0].value = (methStatus.value === 'positive') ? 1 : 0;
            }
            
            // Handle THC status
            const thcStatus = document.querySelector('input[name="thc_status"]:checked');
            if (thcStatus) {
                document.getElementsByName('thc_negative')[0].value = (thcStatus.value === 'negative') ? 1 : 0;
                document.getElementsByName('thc_positive')[0].value = (thcStatus.value === 'positive') ? 1 : 0;
            }
        });
    }
    
    // Initialize findings textareas based on current state
    <?php 
    // Check each findings field and disable textarea if normal is selected
    $findingsFields = ['chest_xray_findings', 'cbc_findings', 'urinalysis_findings', 'stool_findings', 'hepa_b_findings'];
    foreach ($findingsFields as $field) {
        if (isset($record[$field]) && $record[$field] == 0) {
            echo "toggleFindingsTextarea('{$field}_text', false);";
        }
    }
    ?>
});
</script>
</body>
</html>