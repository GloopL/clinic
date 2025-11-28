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

// Fetch recent diagnoses for display - FILTERED BY CURRENT USER'S ROLE
$recent_diagnoses = [];
if ($patient_id !== null) {
    // Determine which diagnosis types to show based on current user role
    $allowed_diagnosis_types = [];
    
    if ($user_role === 'nurse') {
        $allowed_diagnosis_types = ['nurse'];
    } elseif ($user_role === 'dentist') {
        $allowed_diagnosis_types = ['dentist'];
    } elseif ($user_role === 'doctor' || $user_role === 'physician') {
        $allowed_diagnosis_types = ['doctor'];
    } elseif ($user_role === 'admin' || $user_role === 'staff') {
        // Admin/staff can see all diagnosis types
        $allowed_diagnosis_types = ['nurse', 'dentist', 'doctor'];
    }
    
    if (!empty($allowed_diagnosis_types)) {
        $placeholders = str_repeat('?,', count($allowed_diagnosis_types) - 1) . '?';
        $stmt = $conn->prepare("
            SELECT md.diagnosis_type, md.diagnosis_date, md.provider_name, md.chief_complaint, 
                   md.assessment, md.severity, md.status,
                   COALESCE(u.full_name, md.provider_name) as provider_full_name
            FROM medical_diagnoses md 
            LEFT JOIN users u ON BINARY u.username = md.provider_name
            WHERE md.patient_id = ? AND md.diagnosis_type IN ($placeholders)
            ORDER BY md.diagnosis_date DESC 
            LIMIT 3
        ");
        
        $types = "i" . str_repeat('s', count($allowed_diagnosis_types));
        $params = array_merge([$patient_id], $allowed_diagnosis_types);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $recent_diagnoses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
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
                'height', 'weight', 'bmi', 'blood_pressure', 'pulse_rate', 'temperature',
                'vision_status', 'physical_findings', 'diagnostic_results', 'classification',
                'recommendations', 'physician_name', 'license_no', 'examination_date'
            ];
        } elseif ($effective_type === 'dental_form' || $effective_type === 'dental_exam') {
            $possible_fields = [
                'remarks', 'dental_chart_data', 'dentist_name', 'license_no', 'dentist_date',
                'dentition_status', 'treatment_needs', 'periodontal_screening', 'occlusion', 'appliances', 'tmd_status'
            ];
        } elseif ($effective_type === 'history_form') {
            $possible_fields = [
                'denied_participation', 'asthma', 'seizure_disorder', 'heart_problem', 'diabetes', 
                'high_blood_pressure', 'surgery_history', 'chest_pain', 'injury_history', 'xray_history', 
                'head_injury', 'muscle_cramps', 'vision_problems', 'special_diet', 'menstrual_history',
                'height_normal', 'height_findings', 'weight_normal', 'weight_findings', 'bp_normal', 'bp_findings',
                'pulse_normal', 'pulse_findings', 'vision_normal', 'vision_findings', 'appearance_normal', 'appearance_findings',
                'eent_normal', 'eent_findings', 'pupils_normal', 'pupils_findings', 'hearing_normal', 'hearing_findings',
                'chest_normal', 'chest_findings', 'heart_normal', 'heart_findings', 'abdomen_normal', 'abdomen_findings',
                'genitourinary_normal', 'genitourinary_findings', 'neurologic_normal', 'neurologic_findings',
                'neck_normal', 'neck_findings', 'back_normal', 'back_findings', 'shoulder_arm_normal', 'shoulder_arm_findings',
                'elbow_forearm_normal', 'elbow_forearm_findings', 'wrist_hand_normal', 'wrist_hand_findings',
                'knee_normal', 'knee_findings', 'leg_ankle_normal', 'leg_ankle_findings', 'foot_toes_normal', 'foot_toes_findings'
            ];
        }
        
        if ($effective_type === 'dental_form' || $effective_type === 'dental_exam') {
            foreach ($dental_checkbox_map as $column => $fieldMap) {
                $_POST[$column] = buildDentalCheckboxPayload($fieldMap, $_POST);
            }
        }

        // Process each field
        foreach ($possible_fields as $field) {
            if (in_array($field, $existing_columns)) {
                
                // Handle checkbox/boolean fields
                if (str_contains($field, 'is_') || str_contains($field, '_normal') || 
                    $field === 'denied_participation' || $field === 'asthma' || $field === 'seizure_disorder' ||
                    $field === 'heart_problem' || $field === 'diabetes' || $field === 'high_blood_pressure' ||
                    $field === 'chest_pain' || $field === 'head_injury' || $field === 'muscle_cramps' || 
                    $field === 'vision_problems') {
                    
                    $value = isset($_POST[$field]) ? 1 : 0;
                    $set_parts[] = "$field = ?";
                    $params[] = $value;
                    $types .= 'i';
                } 
                // Handle date fields
                elseif ($field === 'examination_date' || $field === 'dentist_date') {
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
            }
            $stmt->close();
        } else {
            $error_message = "Error preparing query: " . $conn->error;
        }
    } else {
        $error_message = "Invalid form type: " . htmlspecialchars($type);
    }
}

if ($record && ($type === 'dental_form' || $type === 'dental_exam')) {
    foreach ($dental_checkbox_map as $column => $fieldMap) {
        hydrateDentalCheckboxFlags($record, $column, $fieldMap);
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

// Fetch recent diagnoses for the patient - FILTERED BY CURRENT USER ROLE
if ($record && isset($record['patient_id'])) {
    // Determine which diagnosis types to show based on current user role
    $allowed_diagnosis_types = [];
    
    if ($user_role === 'nurse') {
        $allowed_diagnosis_types = ['nurse'];
    } elseif ($user_role === 'dentist') {
        $allowed_diagnosis_types = ['dentist'];
    } elseif ($user_role === 'doctor' || $user_role === 'physician') {
        $allowed_diagnosis_types = ['doctor'];
    } elseif ($user_role === 'admin' || $user_role === 'staff') {
        // Admin/staff can see all diagnosis types
        $allowed_diagnosis_types = ['nurse', 'dentist', 'doctor'];
    }
    
    if (!empty($allowed_diagnosis_types)) {
        $placeholders = str_repeat('?,', count($allowed_diagnosis_types) - 1) . '?';
        $stmt = $conn->prepare("
            SELECT md.diagnosis_type, md.diagnosis_date, md.provider_name, md.chief_complaint, 
                   md.assessment, md.severity, md.status,
                   COALESCE(u.full_name, md.provider_name) as provider_full_name
            FROM medical_diagnoses md 
            LEFT JOIN users u ON BINARY u.username = md.provider_name
            WHERE md.patient_id = ? AND md.diagnosis_type IN ($placeholders)
            ORDER BY md.diagnosis_date DESC 
            LIMIT 3
        ");
        
        $types = "i" . str_repeat('s', count($allowed_diagnosis_types));
        $params = array_merge([$record['patient_id']], $allowed_diagnosis_types);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $recent_diagnoses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}

// Helper function for rendering editable fields
function render_editable_field($record, $field, $is_editable, $is_checkbox = false, $input_type = 'text') {
    $value = htmlspecialchars($record[$field] ?? '');
    $editable_class = $is_editable ? 'nurse-editable' : '';
    $readonly_attr = !$is_editable ? 'readonly' : '';
    $checkbox_status = $record[$field] ?? 0;

    if ($is_checkbox) {
        $checked_attr = $checkbox_status ? 'checked' : '';
        $disabled_attr = !$is_editable ? 'disabled' : '';
        return '<input type="checkbox" name="' . $field . '" value="1" class="mr-2 h-4 w-4 text-orange-600 border-orange-300 rounded focus:ring-orange-500 ' . $editable_class . '" ' . $checked_attr . ' ' . $disabled_attr . '>';
    }

    if ($input_type === 'textarea') {
        return '<textarea name="' . $field . '" rows="3" class="w-full rounded border border-orange-300 px-3 py-2 text-sm ' . $editable_class . '" ' . $readonly_attr . '>' . $value . '</textarea>';
    }

    if ($input_type === 'date') {
        return '<input type="date" name="' . $field . '" value="' . $value . '" class="w-full rounded border border-orange-300 px-3 py-2 text-sm ' . $editable_class . '" ' . $readonly_attr . '>';
    }

    return '<input type="' . $input_type . '" name="' . $field . '" value="' . $value . '" class="w-full rounded border border-orange-300 px-3 py-2 text-sm ' . $editable_class . '" ' . $readonly_attr . '>';
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
    echo '<label class="block font-medium mb-1 text-orange-700">' . $label . '</label>';
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
    $html .= '<input type="radio" name="' . $field . '" value="1" class="h-4 w-4 text-orange-600 ' . $editable_class . '" ' . ($value ? 'checked' : '') . ' ' . $disabled_attr . '> Yes';
    $html .= '</label>';
    $html .= '<label class="flex items-center gap-2">';
    $html .= '<input type="radio" name="' . $field . '" value="0" class="h-4 w-4 text-orange-600 ' . $editable_class . '" ' . (!$value ? 'checked' : '') . ' ' . $disabled_attr . '> No';
    $html .= '</div>';
    
    return $html;
}

// Helper function for history form examination table rows
function render_history_exam_row($record, $field_normal, $field_findings, $label, $is_editable) {
    echo '<tr class="hover:bg-orange-50">';
    echo '<td class="border border-orange-200 px-4 py-2 text-orange-700">' . $label . '</td>';
    echo '<td class="border border-orange-200 px-4 py-2">';
    echo render_editable_field($record, $field_findings, $is_editable);
    echo '</td>';
    echo '</tr>';
}

// Function to generate interactive dental chart visualization
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
            box-shadow: 0 0 0 2px #3b82f6;
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
    </style>

    <div class="mb-6">
        <div class="flex justify-between items-center mb-3">
            <h4 class="font-semibold text-orange-700">Interactive Dental Chart</h4>
            <div class="flex gap-2">
                
            </div>
        </div>
        
        <!-- Dental Chart Controls -->
        <div class="flex flex-wrap gap-4 mb-4 p-4 bg-orange-50 rounded-lg border border-orange-200">
            <div class="flex items-center gap-2">
                <div class="w-3 h-3 bg-red-500 rounded"></div>
                <span class="text-sm font-medium text-orange-700">Caries (Cavity)</span>
            </div>
            <div class="flex items-center gap-2">
                <div class="w-3 h-3 bg-blue-500 rounded"></div>
                <span class="text-sm font-medium text-orange-700">Filling</span>
            </div>
            <div class="flex items-center gap-2">
                <div class="w-3 h-3 bg-yellow-500 rounded"></div>
                <span class="text-sm font-medium text-orange-700">Extraction Needed</span>
            </div>
            <div class="flex items-center gap-2">
                <div class="w-3 h-3 bg-green-500 rounded"></div>
                <span class="text-sm font-medium text-orange-700">Healthy</span>
            </div>
            <div class="flex items-center gap-2">
                <div class="w-3 h-3 bg-purple-500 rounded"></div>
                <span class="text-sm font-medium text-orange-700">Crown/Bridge</span>
            </div>
            <div class="flex items-center gap-2 ml-4">
                <div class="w-3 h-3 border-2 border-orange-500 bg-orange-100 rounded"></div>
                <span class="text-sm font-medium text-orange-700">Click to change condition</span>
            </div>
        </div>

        <!-- Dental Chart Container -->
        <div class="bg-white border-2 border-orange-200 rounded-xl p-6 mb-4">
            <!-- Maxillary (Upper) Teeth -->
            <div class="mb-8">
                <h5 class="text-center font-semibold mb-4 text-orange-600 bg-orange-100 py-1 rounded">Maxillary (Upper Jaw)</h5>
                <div class="grid grid-cols-16 gap-1 justify-center mb-2" id="maxillary-teeth">
                    ' . generateToothSection(1, 16, 'maxillary', $chartData) . '
                </div>
            </div>

            <!-- Mandibular (Lower) Teeth -->
            <div>
                <h5 class="text-center font-semibold mb-4 text-orange-600 bg-orange-100 py-1 rounded">Mandibular (Lower Jaw)</h5>
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
        $initialColor = 'bg-white text-gray-800';
        if ($condition !== 'none') {
            $colorMap = [
                'healthy' => 'bg-green-500 text-white',
                'caries' => 'bg-red-500 text-white',
                'filling' => 'bg-blue-500 text-white',
                'extraction' => 'bg-yellow-500 text-white',
                'crown' => 'bg-purple-500 text-white'
            ];
            $initialColor = $colorMap[$condition] ?? 'bg-white text-gray-800';
        }
        
        $html .= '
            <div class="tooth-container flex flex-col items-center" data-tooth="' . $i . '">
                <div class="tooth-number text-xs font-semibold text-orange-600 mb-1">' . $i . '</div>
                <div class="tooth ' . ($isUpper ? 'tooth-upper' : 'tooth-lower') . ' 
                    w-8 h-12 border-2 border-gray-300 rounded-lg cursor-pointer transition-all duration-200 
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
            <div class="flex items-center gap-2 p-2 bg-white rounded border border-orange-200">
                <div class="w-3 h-3 rounded-full ' . $info['color'] . '"></div>
                <span class="font-medium text-orange-700">' . $info['text'] . ':</span>
                <span class="text-orange-600">Teeth ' . implode(', ', $teeth) . '</span>
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
            'color' => 'orange-gradient-button',
            'icon' => 'bi bi-heart-pulse'
        ];
    } elseif ($user_role === 'dentist') {
        $buttons[] = [
            'url' => "medical_diagnosis_form.php?patient_id=$patient_id&record_id=$record_id&type=$type&diagnosis_type=dentist",
            'text' => 'Dental Diagnosis',
            'color' => 'orange-gradient-button',
            'icon' => 'bi bi-tooth'
        ];
    } elseif ($user_role === 'doctor' || $user_role === 'physician') {
        $buttons[] = [
            'url' => "medical_diagnosis_form.php?patient_id=$patient_id&record_id=$record_id&type=$type&diagnosis_type=doctor",
            'text' => 'Physician Diagnosis',
            'color' => 'orange-gradient-button',
            'icon' => 'bi bi-heart-pulse'
        ];
    } elseif ($user_role === 'admin' || $user_role === 'staff') {
        // Admin/staff can add all types
        $buttons = [
            [
                'url' => "medical_diagnosis_form.php?patient_id=$patient_id&record_id=$record_id&type=$type&diagnosis_type=nurse",
                'text' => 'Nurse Diagnosis',
                'color' => 'orange-gradient-button',
                'icon' => 'bi bi-heart-pulse'
            ],
            [
                'url' => "medical_diagnosis_form.php?patient_id=$patient_id&record_id=$record_id&type=$type&diagnosis_type=dentist",
                'text' => 'Dental Diagnosis',
                'color' => 'orange-gradient-button',
                'icon' => 'bi bi-tooth'
            ],
            [
                'url' => "medical_diagnosis_form.php?patient_id=$patient_id&record_id=$record_id&type=$type&diagnosis_type=doctor",
                'text' => 'Physician Diagnosis',
                'color' => 'orange-gradient-button',
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
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        @media print {
            .no-print { display: none; }
            body { background: white; }
        }
        .nurse-editable {
            background-color: #ffedd5 !important;
            border: 1px dashed #ea580c !important;
        }
        .exam-section {
            border-left: 4px solid #ea580c;
            background: #ffedd5;
        }
        .medical-section {
            border-left: 4px solid #ea580c;
            background: #ffedd5;
        }
        .history-section {
            border-left: 4px solid #ea580c;
            background: #ffedd5;
        }
        .dental-section {
            border-left: 4px solid #ea580c;
            background: #ffedd5;
        }
        /* Shared red-orange header gradient used on doctor pages */
        .red-orange-gradient {
            background: linear-gradient(135deg, #dc2626, #ea580c, #f97316);
        }
        .red-orange-gradient-button {
            background: linear-gradient(135deg, #dc2626, #ea580c);
        }
        .red-orange-gradient-button:hover {
            background: linear-gradient(135deg, #b91c1c, #c2410c);
        }
        .orange-gradient-button {
            background: linear-gradient(135deg, #ea580c, #f97316);
        }
        .orange-gradient-button:hover {
            background: linear-gradient(135deg, #c2410c, #ea580c);
        }
        .diagnosis-badge-nurse { background-color: #3b82f6; }
        .diagnosis-badge-dentist { background-color: #10b981; }
        .diagnosis-badge-doctor { background-color: #ef4444; }
        .severity-mild { background-color: #10b981; }
        .severity-moderate { background-color: #f59e0b; }
        .severity-severe { background-color: #ef4444; }
        .severity-critical { background-color: #7c3aed; }
    </style>
</head>
<body class="bg-gradient-to-br from-orange-50 to-red-50">

    <!-- HEADER (same style as other doctor records pages) -->
    <header class="red-orange-gradient text-white shadow-md sticky top-0 z-50">
        <div class="max-w-7xl mx-auto flex items-center justify-between px-6 py-3">
            <div class="flex items-center gap-3">
                <img src="../../assets/css/images/logo-bsu.png" alt="BSU Logo" class="w-12 h-12 rounded-full object-cover border-4 border-white bg-white">
                <h1 class="text-lg font-bold">BSU Clinic Record Management System</h1>
            </div>
            <nav class="flex items-center gap-6">
                <a href="<?= $dashboard_url ?>" class="hover:text-yellow-200 flex items-center gap-1">
                    <i class="bi bi-speedometer2"></i> Dashboard
                </a>
                <a href="../../logout.php" class="red-orange-gradient-button text-white px-3 py-1 rounded-lg font-semibold hover:shadow-lg flex items-center gap-1">
                    <i class="bi bi-box-arrow-right"></i> Logout
                </a>
            </nav>
        </div>
    </header>

<div class="min-h-screen py-10 px-6">
    <div class="max-w-6xl mx-auto bg-white shadow-lg rounded-lg p-8">
        
        <div class="flex justify-between items-center mb-6 no-print">
            <div class="flex gap-2">
                <a href="<?= $dashboard_url ?>"
                   class="inline-flex items-center gap-2 red-orange-gradient-button text-white font-semibold px-4 py-2 rounded-lg shadow transition-all">
                   <i class="bi bi-arrow-left-circle"></i> Back to Patients
                </a>
                <a href="<?= $dashboard_url ?>"
                   class="inline-flex items-center gap-2 orange-gradient-button text-white font-semibold px-4 py-2 rounded-lg shadow transition-all">
                   <i class="bi bi-house"></i> Dashboard
                </a>
            </div>
            <h2 class="text-2xl font-bold text-orange-700">View Record Details</h2>
            <?php if ($is_nurse): ?>
                <span class="bg-orange-100 text-orange-800 px-3 py-1 rounded-full text-sm font-semibold">
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
            <form method="POST" id="record-form">
                <input type="hidden" name="record_id" value="<?= $record['record_id']; ?>">
                <input type="hidden" name="record_type" value="<?= $type; ?>">
                
                <div class="bg-orange-50 rounded-lg p-4 mb-6 border-l-4 border-orange-500">
                    <h3 class="font-semibold text-lg mb-3 text-orange-800">Patient Information (Record ID: <?= $record['record_id'] ?>)</h3>
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                        <p><strong>Student ID:</strong> <?= htmlspecialchars($record['student_id'] ?? '') ?></p>
                        <p><strong>Name:</strong> <?= htmlspecialchars($record['first_name'] . ' ' . $record['last_name']); ?></p>
                        <p><strong>Program/Year:</strong> <?= htmlspecialchars($record['program'] . ' / ' . $record['year_level']); ?></p>
                        <p><strong>DOB/Sex:</strong> <?= htmlspecialchars($record['date_of_birth'] . ' / ' . $record['sex']); ?></p>
                        <p><strong>Submitted On:</strong> <?= htmlspecialchars($record['examination_date'] ?? 'N/A') ?></p>
                    </div>
                </div>

                <!-- Diagnosis History Section -->
                <div class="bg-purple-50 rounded-lg p-4 mb-6 border-l-4 border-purple-500">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="font-semibold text-lg text-purple-800">
                            <?= 
                                $user_role === 'nurse' ? 'Nurse Diagnoses' : 
                                ($user_role === 'dentist' ? 'Dental Diagnoses' : 
                                ($user_role === 'doctor' || $user_role === 'physician' ? 'Physician Diagnoses' : 'Medical Diagnoses'))
                            ?>
                        </h3>
                        <?php if ($is_nurse): ?>
                            <div class="flex gap-2">
                                <?php
                                $diagnosis_buttons = getDiagnosisButton($user_role, $record['patient_id'], $record['record_id'], $type);
                                foreach ($diagnosis_buttons as $button): ?>
                                    <a href="<?= $button['url'] ?>" 
                                       class="<?= $button['color'] ?> text-white px-4 py-2 rounded-lg shadow font-semibold transition-all flex items-center gap-2">
                                       <i class="<?= $button['icon'] ?>"></i> <?= $button['text'] ?>
                                    </a>
                                <?php endforeach; ?>
                                <a href="diagnosis_history.php?patient_id=<?= $record['patient_id'] ?>&record_id=<?= $record['record_id'] ?>&type=<?= $type ?>" 
                                   class="orange-gradient-button text-white px-4 py-2 rounded-lg shadow font-semibold transition-all flex items-center gap-2">
                                   <i class="bi bi-clock-history"></i> View All Diagnoses
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (!empty($recent_diagnoses)): ?>
                        <div class="space-y-2">
                            <?php foreach ($recent_diagnoses as $diagnosis): ?>
                                <div class="bg-white p-3 rounded border border-purple-200">
                                    <div class="flex justify-between items-start">
                                        <div>
                                            <div class="flex items-center gap-2 mb-1">
                                                <span class="font-medium text-purple-700">
                                                    <?= ucfirst($diagnosis['diagnosis_type']) ?> Diagnosis
                                                </span>
                                                <span class="text-xs bg-purple-100 text-purple-800 px-2 py-1 rounded">
                                                    <?= date('M j, Y', strtotime($diagnosis['diagnosis_date'])) ?>
                                                </span>
                                                <span class="text-xs severity-<?= $diagnosis['severity'] ?> text-white px-2 py-1 rounded">
                                                    <?= ucfirst($diagnosis['severity']) ?>
                                                </span>
                                                <span class="text-xs bg-gray-100 text-gray-800 px-2 py-1 rounded">
                                                    <?= ucfirst($diagnosis['status']) ?>
                                                </span>
                                            </div>
                                            <p class="text-sm text-gray-600">
                                                <strong>Provider:</strong> <?= htmlspecialchars($diagnosis['provider_full_name'] ?? $diagnosis['provider_name']) ?>
                                            </p>
                                            <?php if ($diagnosis['chief_complaint']): ?>
                                                <p class="text-sm text-gray-700 mt-1">
                                                    <strong>Complaint:</strong> <?= htmlspecialchars(substr($diagnosis['chief_complaint'], 0, 100)) ?><?= strlen($diagnosis['chief_complaint']) > 100 ? '...' : '' ?>
                                                </p>
                                            <?php endif; ?>
                                            <?php if ($diagnosis['assessment']): ?>
                                                <p class="text-sm text-gray-700 mt-1">
                                                    <strong>Assessment:</strong> <?= htmlspecialchars(substr($diagnosis['assessment'], 0, 100)) ?><?= strlen($diagnosis['assessment']) > 100 ? '...' : '' ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-gray-600">No <?= 
                            $user_role === 'nurse' ? 'nurse' : 
                            ($user_role === 'dentist' ? 'dental' : 
                            ($user_role === 'doctor' || $user_role === 'physician' ? 'physician' : 'medical'))
                        ?> diagnosis records found.</p>
                    <?php endif; ?>
                </div>

                <!-- Consultation History Section -->
                <div class="bg-blue-50 rounded-lg p-4 mb-6 border-l-4 border-blue-500">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="font-semibold text-lg text-blue-800">Consultation History</h3>
                        <?php if ($is_nurse): ?>
                            <a href="consultation_history.php?patient_id=<?= $record['patient_id'] ?>&record_id=<?= $record['record_id'] ?>&type=<?= $type ?>" 
                               class="orange-gradient-button text-white px-4 py-2 rounded-lg shadow font-semibold transition-all flex items-center gap-2">
                               <i class="bi bi-clock-history"></i> View/Add Consultation History
                            </a>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (!empty($recent_consultations)): ?>
                        <div class="space-y-2">
                            <?php foreach ($recent_consultations as $consultation): ?>
                                <div class="bg-white p-3 rounded border border-blue-200">
                                    <div class="flex justify-between items-start">
                                        <div>
                                            <p class="font-medium text-blue-700">
                                                <?= ucfirst($consultation['consultation_type']) ?> Consultation
                                            </p>
                                            <p class="text-sm text-gray-600">
                                                <?= date('M j, Y', strtotime($consultation['consultation_date'])) ?> 
                                                by <?= htmlspecialchars($consultation['physician_full_name'] ?? $consultation['physician_name']) ?>
                                            </p>
                                            <?php if ($consultation['diagnosis']): ?>
                                                <p class="text-sm text-gray-700 mt-1">
                                                    <strong>Findings:</strong> <?= htmlspecialchars(substr($consultation['diagnosis'], 0, 100)) ?><?= strlen($consultation['diagnosis']) > 100 ? '...' : '' ?>
                                                </p>
                                            <?php endif; ?>
                                            <?php if ($consultation['treatment']): ?>
                                                <p class="text-sm text-gray-700 mt-1">
                                                    <strong>Treatment:</strong> <?= htmlspecialchars(substr($consultation['treatment'], 0, 100)) ?><?= strlen($consultation['treatment']) > 100 ? '...' : '' ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-gray-600">No consultation history found.</p>
                    <?php endif; ?>
                </div>

                <?php if ($type === 'medical_form' || $type === 'medical_exam'): ?>
                    <!-- Medical Examination Details -->
                    <div class="medical-section rounded-lg p-4 mb-6">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="font-semibold text-lg text-orange-800">Medical Examination Details</h3>
                            <?php if ($is_nurse): ?>
                                <span class="bg-orange-100 text-orange-800 px-3 py-1 rounded-full text-sm font-semibold">
                                    <i class="bi bi-pencil-square"></i> Editable by <?= ucwords($user_role) ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                            <div>
                                <label class="block font-medium mb-1 text-orange-700">Examination Date</label>
                                <?= render_editable_field($record, 'examination_date', $is_nurse, false, 'date') ?>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                            <?php
                            render_medical_field($record, 'height', 'Height (cm)', $is_nurse, 'number');
                            render_medical_field($record, 'weight', 'Weight (kg)', $is_nurse, 'number');
                            render_medical_field($record, 'bmi', 'BMI', $is_nurse, 'number');
                            render_medical_field($record, 'blood_pressure', 'Blood Pressure (mmHg)', $is_nurse, 'text');
                            render_medical_field($record, 'pulse_rate', 'Pulse Rate (bpm)', $is_nurse, 'number');
                            render_medical_field($record, 'temperature', 'Temperature (°C)', $is_nurse, 'number');
                            ?>
                        </div>

                        <div class="grid grid-cols-1 gap-6 mb-6">
                            <?php
                            echo '<div>';
                            echo '<label class="block font-medium mb-1 text-orange-700">Vision Status</label>';
                            echo render_editable_field($record, 'vision_status', $is_nurse, false, 'textarea');
                            echo '</div>';
                            
                            echo '<div>';
                            echo '<label class="block font-medium mb-1 text-orange-700">Physical Findings</label>';
                            echo render_editable_field($record, 'physical_findings', $is_nurse, false, 'textarea');
                            echo '</div>';
                            
                            echo '<div>';
                            echo '<label class="block font-medium mb-1 text-orange-700">Diagnostic Results</label>';
                            echo render_editable_field($record, 'diagnostic_results', $is_nurse, false, 'textarea');
                            echo '</div>';
                            
                            echo '<div>';
                            echo '<label class="block font-medium mb-1 text-orange-700">Recommendations</label>';
                            echo render_editable_field($record, 'recommendations', $is_nurse, false, 'textarea');
                            echo '</div>';
                            ?>
                        </div>

                        <div class="bg-orange-100 p-3 rounded shadow-sm">
                            <h4 class="font-semibold mb-2 text-orange-700 border-b border-orange-300 pb-1">Physician Certification</h4>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <?php
                                render_medical_field($record, 'physician_name', 'Physician Name', $is_nurse, 'text');
                                render_medical_field($record, 'license_no', 'License No.', $is_nurse, 'text');
                                render_medical_field($record, 'classification', 'Classification', $is_nurse, 'text');
                                ?>
                            </div>
                        </div>
                    </div>

                <?php elseif ($type === 'dental_form' || $type === 'dental_exam'): ?>
                    <!-- Dental Examination Details -->
                    <div class="dental-section rounded-lg p-4 mb-6">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="font-semibold text-lg text-orange-800">Dental Examination Details</h3>
                            <?php if ($is_nurse): ?>
                                <span class="bg-orange-100 text-orange-800 px-3 py-1 rounded-full text-sm font-semibold">
                                    <i class="bi bi-pencil-square"></i> Editable by <?= ucwords($user_role) ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                            <div>
                                <label class="block font-medium mb-1 text-orange-700">Examination Date</label>
                                <?= render_editable_field($record, 'dentist_date', $is_nurse, false, 'date') ?>
                            </div>
                        </div>

                        <!-- Enhanced Dental Chart Visualization -->
                        <div class="mb-6 bg-white border-2 border-orange-200 rounded-xl p-6">
                            <div class="flex justify-between items-center mb-4">
                                <h4 class="font-semibold text-orange-700 text-lg">Dental Chart Visualization</h4>
                                <?php if ($is_nurse): ?>
                                    <button type="button" onclick="resetDentalChart()" 
                                            class="orange-gradient-button text-white px-3 py-1 rounded text-sm transition-colors">
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
                                <div class="mt-4 p-4 bg-orange-50 rounded-lg border border-orange-200">
                                    <div class="flex justify-between items-center mb-3">
                                        <label class="block font-medium text-orange-700">Dental Chart Data</label>
                                        <button type="button" onclick="toggleDentalDataView()" 
                                                class="text-xs text-orange-600 hover:text-orange-800 underline">
                                            <span id="toggle-text">Show Raw JSON</span>
                                        </button>
                                    </div>
                                    
                                    <!-- Formatted Display (Default) -->
                                    <div id="formatted-dental-data" class="bg-white rounded-lg p-4 border border-orange-200">
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
                                                  class="w-full border border-orange-300 rounded-lg px-3 py-2 text-sm nurse-editable font-mono text-xs"
                                                  placeholder="Dental chart data in JSON format"><?= 
                                                  !empty($dentalChartData) && $dentalChartData !== 'null' && $dentalChartData !== '""' ? 
                                                  htmlspecialchars(json_encode(json_decode($dentalChartData, true), JSON_PRETTY_PRINT)) : '{}' ?></textarea>
                                    </div>
                                    
                                    <p class="text-xs text-orange-600 mt-2">
                                        <i class="bi bi-info-circle"></i> This field stores the dental chart visualization data. Click "Show Raw JSON" to edit directly.
                                    </p>
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
                            <div class="bg-orange-50 p-3 rounded shadow-sm md:col-span-2">
                                <h4 class="font-semibold mb-3 text-orange-700 border-b border-orange-300 pb-1">Periodontal / Occlusion / Appliances</h4>
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <p class="font-medium text-sm mb-1 text-orange-600">Periodontal:</p>
                                        <div class="space-y-1">
                                            <label class="flex items-center text-sm"> <?= display_dental_status($record, 'is_gingivitis', $is_nurse) ?> Gingivitis</label>
                                            <label class="flex items-center text-sm"> <?= display_dental_status($record, 'is_early_periodontitis', $is_nurse) ?> Early Periodontitis</label>
                                            <label class="flex items-center text-sm"> <?= display_dental_status($record, 'is_moderate_periodontitis', $is_nurse) ?> Moderate Periodontitis</label>
                                            <label class="flex items-center text-sm"> <?= display_dental_status($record, 'is_advanced_periodontitis', $is_nurse) ?> Advanced Periodontitis</label>
                                        </div>
                                    </div>
                                    <div>
                                        <p class="font-medium text-sm mb-1 text-orange-600">Occlusion & Appliances:</p>
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
                                    <div class="col-span-2 border-t border-orange-300 pt-2 mt-2">
                                        <p class="font-medium text-sm mb-1 text-orange-600">TMD Symptoms:</p>
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
                                <label class="block font-medium mb-1 text-orange-700">Dentist's Remarks / Findings / Treatment Plan (Editable)</label>
                                <?= render_editable_field($record, 'remarks', $is_nurse, false, 'textarea') ?>
                            </div>
                            
                            <div class="bg-orange-100 p-3 rounded shadow-sm md:col-span-3">
                                <h4 class="font-semibold mb-2 text-orange-700 border-b border-orange-300 pb-1">Dentist/Professional Certification</h4>
                                <div class="grid grid-cols-3 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium mb-1 text-orange-600">Dentist Name:</label>
                                        <?= render_editable_field($record, 'dentist_name', $is_nurse) ?>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1 text-orange-600">License No.:</label>
                                        <?= render_editable_field($record, 'license_no', $is_nurse) ?>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1 text-orange-600">Date Examined:</label>
                                        <?= render_editable_field($record, 'dentist_date', $is_nurse, false, 'date') ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                <?php elseif ($type === 'history_form'): ?>
                    <!-- Medical History Details -->
                    <div class="history-section rounded-lg p-4 mb-6">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="font-semibold text-lg text-orange-800">Medical History Details</h3>
                            <?php if ($is_nurse): ?>
                                <span class="bg-orange-100 text-orange-800 px-3 py-1 rounded-full text-sm font-semibold">
                                    <i class="bi bi-pencil-square"></i> Editable by <?= ucwords($user_role) ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <!-- Physical Examination Tables -->
                        <h4 class="font-semibold mb-3 text-orange-700">Physical Examination</h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                            <!-- Left Table -->
                            <div class="overflow-x-auto">
                                <table class="min-w-full border-2 border-orange-400 rounded-lg overflow-hidden mb-6 bg-white shadow">
                                    <thead>
                                        <tr class="bg-orange-100">
                                            <th class="border border-orange-300 px-4 py-2 text-orange-700">REVIEW OF SYSTEM</th>
                                            <th class="border border-orange-300 px-4 py-2 text-orange-700">FINDINGS</th>
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
                                        render_history_exam_row($record, 'hearing_normal', 'hearing_findings', 'Hearing', $is_nurse);
                                        render_history_exam_row($record, 'chest_normal', 'chest_findings', 'Chest', $is_nurse);
                                        render_history_exam_row($record, 'heart_normal', 'heart_findings', 'Heart', $is_nurse);
                                        ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Right Table -->
                            <div class="overflow-x-auto">
                                <table class="min-w-full border-2 border-orange-400 rounded-lg overflow-hidden mb-6 bg-white shadow">
                                    <thead>
                                        <tr class="bg-orange-100">
                                            <th class="border border-orange-300 px-4 py-2 text-orange-700">REVIEW OF SYSTEM</th>
                                            <th class="border border-orange-300 px-4 py-2 text-orange-700">FINDINGS</th>
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
                        <h4 class="font-semibold mb-3 text-orange-700">General Questions</h4>
                        <div class="overflow-x-auto mb-6">
                            <table class="min-w-full border-2 border-orange-400 rounded-lg overflow-hidden bg-white shadow">
                                <thead>
                                    <tr class="bg-orange-100">
                                        <th class="border border-orange-300 px-4 py-2 text-orange-700">Check the following for your answers:</th>
                                        <th class="border border-orange-300 px-4 py-2 text-orange-700">Yes</th>
                                        <th class="border border-orange-300 px-4 py-2 text-orange-700">No</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr class="hover:bg-orange-50">
                                        <td class="border border-orange-300 px-4 py-2 text-orange-700">1. Have you been denied or restricted your participation in sports activities</td>
                                        <td class="border border-orange-300 px-4 py-2 text-center"><?= render_history_radio($record, 'denied_participation', $is_nurse) ?></td>
                                    </tr>
                                    <tr class="hover:bg-orange-50">
                                        <td colspan="3" class="border border-orange-300 px-4 py-2 font-semibold text-orange-700">Do you have any of the following conditions:</td>
                                    </tr>
                                    <tr class="hover:bg-orange-50">
                                        <td class="border border-orange-300 px-4 py-2 text-orange-700">a. Asthma</td>
                                        <td class="border border-orange-300 px-4 py-2 text-center"><?= render_history_radio($record, 'asthma', $is_nurse) ?></td>
                                    </tr>
                                    <tr class="hover:bg-orange-50">
                                        <td class="border border-orange-300 px-4 py-2 text-orange-700">b. Seizure disorder</td>
                                        <td class="border border-orange-300 px-4 py-2 text-center"><?= render_history_radio($record, 'seizure_disorder', $is_nurse) ?></td>
                                    </tr>
                                    <tr class="hover:bg-orange-50">
                                        <td class="border border-orange-300 px-4 py-2 text-orange-700">c. Heart problem</td>
                                        <td class="border border-orange-300 px-4 py-2 text-center"><?= render_history_radio($record, 'heart_problem', $is_nurse) ?></td>
                                    </tr>
                                    <tr class="hover:bg-orange-50">
                                        <td class="border border-orange-300 px-4 py-2 text-orange-700">d. Diabetes</td>
                                        <td class="border border-orange-300 px-4 py-2 text-center"><?= render_history_radio($record, 'diabetes', $is_nurse) ?></td>
                                    </tr>
                                    <tr class="hover:bg-orange-50">
                                        <td class="border border-orange-300 px-4 py-2 text-orange-700">e. High Blood Pressure</td>
                                        <td class="border border-orange-300 px-4 py-2 text-center"><?= render_history_radio($record, 'high_blood_pressure', $is_nurse) ?></td>
                                    </tr>
                                    <tr class="hover:bg-orange-50">
                                        <td class="border border-orange-300 px-4 py-2 text-orange-700">2. Have you had any surgery?</td>
                                        <td class="border border-orange-300 px-4 py-2" colspan="2">
                                            <?= render_editable_field($record, 'surgery_history', $is_nurse, false, 'textarea') ?>
                                        </td>
                                    </tr>
                                    <tr class="hover:bg-orange-50">
                                        <td class="border border-orange-300 px-4 py-2 text-orange-700">3. Have you had any discomfort, chest pain or chest tightness in your chest during exercise?</td>
                                        <td class="border border-orange-300 px-4 py-2 text-center"><?= render_history_radio($record, 'chest_pain', $is_nurse) ?></td>
                                    </tr>
                                    <tr class="hover:bg-orange-50">
                                        <td class="border border-orange-300 px-4 py-2 text-orange-700">4. Have you had any injury to the bones, muscle, ligament or tendon?</td>
                                        <td class="border border-orange-300 px-4 py-2" colspan="2">
                                            <?= render_editable_field($record, 'injury_history', $is_nurse, false, 'textarea') ?>
                                        </td>
                                    </tr>
                                    <tr class="hover:bg-orange-50">
                                        <td class="border border-orange-300 px-4 py-2 text-orange-700">5. Have you had any injury that requires x-ray, CT scan or MRI, brace, cast or crutches?</td>
                                        <td class="border border-orange-300 px-4 py-2" colspan="2">
                                            <?= render_editable_field($record, 'xray_history', $is_nurse, false, 'textarea') ?>
                                        </td>
                                    </tr>
                                    <tr class="hover:bg-orange-50">
                                        <td class="border border-orange-300 px-4 py-2 text-orange-700">6. Have you had any head injury or concussion?</td>
                                        <td class="border border-orange-300 px-4 py-2 text-center"><?= render_history_radio($record, 'head_injury', $is_nurse) ?></td>
                                    </tr>
                                    <tr class="hover:bg-orange-50">
                                        <td class="border border-orange-300 px-4 py-2 text-orange-700">7. Do you have frequent muscle cramps when exercising?</td>
                                        <td class="border border-orange-300 px-4 py-2 text-center"><?= render_history_radio($record, 'muscle_cramps', $is_nurse) ?></td>
                                    </tr>
                                    <tr class="hover:bg-orange-50">
                                        <td class="border border-orange-300 px-4 py-2 text-orange-700">8. Have you had any problems with your eyes or vision?</td>
                                        <td class="border border-orange-300 px-4 py-2 text-center"><?= render_history_radio($record, 'vision_problems', $is_nurse) ?></td>
                                    </tr>
                                    <tr class="hover:bg-orange-50">
                                        <td class="border border-orange-300 px-4 py-2 text-orange-700">9. Are you on a special diet or do you avoid certain types of foods?</td>
                                        <td class="border border-orange-300 px-4 py-2" colspan="2">
                                            <?= render_editable_field($record, 'special_diet', $is_nurse, false, 'textarea') ?>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- For Females Only Section -->
                        <div class="female-only-section mt-4">
                            <h5 class="text-orange-600 font-bold">FOR FEMALES ONLY</h5>
                            <table class="min-w-full border-2 border-orange-400 rounded-lg overflow-hidden mb-6 bg-white shadow">
                                <tbody>
                                    <tr class="hover:bg-orange-50">
                                        <td class="border border-orange-300 px-4 py-2 text-orange-700">10. Have you ever had a menstrual period? (LMP)</td>
                                        <td class="border border-orange-300 px-4 py-2" colspan="2">
                                            <?= render_editable_field($record, 'menstrual_history', $is_nurse, false, 'textarea') ?>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

            </form>

            <div class="flex justify-center gap-4 no-print">
                <?php if ($is_nurse): ?>
                    <button type="submit" form="record-form" name="update_record"
                            class="orange-gradient-button text-white px-6 py-2 rounded-lg shadow font-semibold hover:shadow-lg transition-all">
                        <i class="bi bi-check-circle"></i> Update Record
                    </button>
                    <form method="POST" onsubmit="return confirm('Are you sure you want to delete this record? This action cannot be undone.');" class="inline">
                        <input type="hidden" name="record_id" value="<?= $record['record_id']; ?>">
                        <input type="hidden" name="record_type" value="<?= $type; ?>">
                        <button type="submit" name="delete"
                                class="red-orange-gradient-button text-white px-6 py-2 rounded-lg shadow font-semibold hover:shadow-lg transition-all">
                            <i class="bi bi-trash"></i> Delete Record
                        </button>
                    </form>
                <?php endif; ?>
            </div>

        <?php else: ?>
            <p class="text-center text-orange-600 mt-8">No record details available.</p>
        <?php endif; ?>
    </div>
</div>

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
        alert("Error in dental chart data: " + e.message);
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
            conditions[condition].teeth.push(parseInt(toothNumber));
        }
    });
    
    if (Object.keys(conditions).length === 0) {
        return '<div class="text-center py-4 text-gray-500">All teeth are healthy or no conditions marked.</div>';
    }
    
    // Condition info mapping
    const conditionInfo = {
        'healthy': {color: 'bg-green-100', border: 'border-green-300', textColor: 'text-green-800', icon: '✓'},
        'caries': {color: 'bg-red-100', border: 'border-red-300', textColor: 'text-red-800', icon: '⚠'},
        'filling': {color: 'bg-blue-100', border: 'border-blue-300', textColor: 'text-blue-800', icon: '◉'},
        'extraction': {color: 'bg-yellow-100', border: 'border-yellow-300', textColor: 'text-yellow-800', icon: '✕'},
        'crown': {color: 'bg-purple-100', border: 'border-purple-300', textColor: 'text-purple-800', icon: '◈'}
    };
    
    let html = '<div class="space-y-3">';
    
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

// Make sure functions are available globally
window.toggleToothCondition = function(toothElement) {
    const toothNumber = parseInt(toothElement.getAttribute('data-tooth'));
    const conditionContainer = toothElement.parentElement.querySelector('.tooth-condition');
    
    // Cycle through conditions
    const conditions = [
        { name: 'healthy', color: 'bg-green-500', text: 'Healthy', label: 'H' },
        { name: 'caries', color: 'bg-red-500', text: 'Caries', label: 'C' },
        { name: 'filling', color: 'bg-blue-500', text: 'Filling', label: 'F' },
        { name: 'extraction', color: 'bg-yellow-500', text: 'Extraction', label: 'E' },
        { name: 'crown', color: 'bg-purple-500', text: 'Crown/Bridge', label: 'CB' },
        { name: 'none', color: 'bg-white', text: 'None', label: '' }
    ];

    const currentCondition = window.dentalChartState[toothNumber]?.condition || 'none';
    const currentIndex = conditions.findIndex(cond => cond.name === currentCondition);
    const nextIndex = (currentIndex + 1) % conditions.length;
    const nextCondition = conditions[nextIndex];

    if (nextCondition.name === 'none') {
        // Remove condition
        delete window.dentalChartState[toothNumber];
        toothElement.className = toothElement.className.replace(/bg-\w+-\d+/g, '') + ' bg-white text-gray-800';
        toothElement.style.borderColor = '';
        conditionContainer.textContent = '';
        conditionContainer.className = 'tooth-condition text-xs mt-1 text-center min-h-[16px]';
    } else {
        // Set new condition
        window.dentalChartState[toothNumber] = {
            condition: nextCondition.name,
            label: nextCondition.label,
            text: nextCondition.text
        };
        
        // Update visual appearance
        toothElement.className = toothElement.className.replace(/bg-\w+-\d+/g, '') + ` ${nextCondition.color} text-white`;
        
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
        tooth.className = tooth.className.replace(/bg-\w+-\d+/g, '') + ' bg-white text-gray-800 border-2 border-gray-300';
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
                
                toothElement.className = toothElement.className.replace(/bg-\w+-\d+/g, '') + ` ${conditionInfo.color} text-white`;
                conditionContainer.textContent = condition.label;
                conditionContainer.className = `tooth-condition text-xs mt-1 text-center min-h-[16px] font-semibold`;
            }
        });
    }
    
    // Initialize summary
    updateDentalChartSummary();
});
</script>

</body>
</html>