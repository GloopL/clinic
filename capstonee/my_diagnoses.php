<?php
session_start();
include 'config/database.php';

// Set timezone to Philippines
date_default_timezone_set('Asia/Manila');

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// Only allow 'student' users
$restrictedRoles = ['admin', 'staff', 'doctor', 'nurse'];
if (in_array($_SESSION['role'], $restrictedRoles)) {
    header("Location: dashboard.php");
    exit();
}

// Get user profile information
$user_id = $_SESSION['user_id'];
$user_profile = $conn->query("
    SELECT username, email, full_name, created_at
    FROM users
    WHERE id = $user_id
")->fetch_assoc();

// Get patient information if exists
$patient_info = $conn->query("
    SELECT * FROM patients WHERE student_id = '" . $conn->real_escape_string($_SESSION['username']) . "'
")->fetch_assoc();

// Get full name for display
$display_name = $_SESSION['username'];
if (!empty($user_profile['full_name'])) {
    $display_name = trim($user_profile['full_name']);
} else {
    // Fallback to user_details table if exists
    $check_user_details = $conn->query("SHOW TABLES LIKE 'user_details'");
    if ($check_user_details->num_rows > 0) {
        $user_details = $conn->query("SELECT full_name FROM user_details WHERE user_id = $user_id");
        if ($user_details && $user_details->num_rows > 0) {
            $details = $user_details->fetch_assoc();
            if (!empty($details['full_name'])) {
                $display_name = trim($details['full_name']);
            }
        }
    }
    
    // If still no full_name, construct from patient_info
    if ($display_name === $_SESSION['username'] && $patient_info) {
        $name_parts = array_filter([
            $patient_info['first_name'] ?? '',
            $patient_info['middle_name'] ?? '',
            $patient_info['last_name'] ?? ''
        ]);
        if (!empty($name_parts)) {
            $display_name = implode(' ', $name_parts);
        }
    }
}

// Function to get provider's full name
function getProviderFullName($conn, $provider_username) {
    if (empty($provider_username)) {
        return $provider_username;
    }
    
    // Clean the username - remove any whitespace
    $provider_username = trim($provider_username);
    
    // First, check if it's already a full name (contains spaces and doesn't match username pattern)
    if (strpos($provider_username, ' ') !== false && !preg_match('/^[a-z0-9_-]+$/i', $provider_username)) {
        // It might already be a full name, but let's verify it's not a username
        $check_query = $conn->prepare("SELECT username FROM users WHERE BINARY username = ? LIMIT 1");
        $check_query->bind_param("s", $provider_username);
        $check_query->execute();
        $check_result = $check_query->get_result();
        
        // If it's NOT a username in the database, treat it as a full name
        if ($check_result->num_rows == 0) {
            $check_query->close();
            return $provider_username;
        }
        $check_query->close();
    }
    
    // Try to find by username in users table (for staff: doctors, dentists, nurses)
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
        
        // If full_name exists in users table, use it (this works for staff)
        if (!empty($user_data['full_name']) && trim($user_data['full_name']) !== '') {
            $user_query->close();
            return trim($user_data['full_name']);
        }
    }
    $user_query->close();
    
    // If not found in users.full_name, check user_details table (for students)
    $check_user_details = $conn->query("SHOW TABLES LIKE 'user_details'");
    if ($check_user_details->num_rows > 0) {
        $user_query = $conn->prepare("
            SELECT ud.full_name, p.first_name, p.middle_name, p.last_name
            FROM users u
            LEFT JOIN user_details ud ON u.id = ud.user_id
            LEFT JOIN patients p ON u.username = p.student_id
            WHERE u.username = ?
            LIMIT 1
        ");
        $user_query->bind_param("s", $provider_username);
        $user_query->execute();
        $user_result = $user_query->get_result();
        
        if ($user_result->num_rows > 0) {
            $user_data = $user_result->fetch_assoc();
            
            // Check user_details.full_name
            if (!empty($user_data['full_name'])) {
                $user_query->close();
                return trim($user_data['full_name']);
            }
            
            // Check patients table (construct from first_name, middle_name, last_name)
            if (!empty($user_data['first_name']) || !empty($user_data['last_name'])) {
                $name_parts = array_filter([
                    $user_data['first_name'] ?? '',
                    $user_data['middle_name'] ?? '',
                    $user_data['last_name'] ?? ''
                ]);
                if (!empty($name_parts)) {
                    $user_query->close();
                    return implode(' ', $name_parts);
                }
            }
        }
        $user_query->close();
    }
    
    // Fallback to username if no full name found
    return $provider_username;
}

// Get filter parameter - now by form type
$filter_type = isset($_GET['filter']) ? $_GET['filter'] : 'all';

// Fetch diagnoses for the current patient
$diagnoses = [];
if ($patient_info && isset($patient_info['id'])) {
    $patient_id_for_diagnoses = $patient_info['id'];
    
    // Build base query
    $base_query = "
        SELECT md.id, md.record_id, md.diagnosis_type, md.diagnosis_date, md.provider_name, md.provider_role,
               md.chief_complaint, md.subjective_findings, md.objective_findings, 
               md.assessment, md.plan, md.medications_prescribed, md.follow_up_required,
               md.follow_up_date, md.severity, md.status, md.notes, md.created_at,
               md.nurse_note, md.nurse_diagnosis_date, md.doctor_note, md.doctor_diagnosis_date,
               COALESCE(u.full_name, md.provider_name) as provider_full_name,
               mr.record_type
        FROM medical_diagnoses md
        LEFT JOIN users u ON BINARY u.username = md.provider_name
        LEFT JOIN medical_records mr ON md.record_id = mr.id
        WHERE md.patient_id = ?
    ";
    
    $params = [$patient_id_for_diagnoses];
    $types = "i";
    
    // Add form type filter if not 'all'
    if ($filter_type !== 'all') {
        $base_query .= " AND mr.record_type = ?";
        $params[] = $filter_type;
        $types .= "s";
    }
    
    $base_query .= " ORDER BY md.diagnosis_date DESC, md.created_at DESC";
    
    $diagnoses_query = $conn->prepare($base_query);
    $diagnoses_query->bind_param($types, ...$params);
    $diagnoses_query->execute();
    $diagnoses_result = $diagnoses_query->get_result();
    
    while ($diagnosis = $diagnoses_result->fetch_assoc()) {
        // If provider_full_name is still the username (not found in users table), try the function as fallback
        if ($diagnosis['provider_full_name'] === $diagnosis['provider_name']) {
            $provider_username = trim($diagnosis['provider_name']);
            $diagnosis['provider_full_name'] = getProviderFullName($conn, $provider_username);
        }
        $diagnoses[] = $diagnosis;
    }
    $diagnoses_query->close();
}

// Get counts for filter badges by form type
$counts = ['all' => 0, 'history_form' => 0, 'medical_exam' => 0, 'dental_exam' => 0];
if ($patient_info && isset($patient_info['id'])) {
    $count_query = $conn->prepare("
        SELECT mr.record_type, COUNT(*) as count
        FROM medical_diagnoses md
        JOIN medical_records mr ON md.record_id = mr.id
        WHERE md.patient_id = ?
        GROUP BY mr.record_type
    ");
    $count_query->bind_param("i", $patient_info['id']);
    $count_query->execute();
    $count_result = $count_query->get_result();
    
    $total = 0;
    while ($row = $count_result->fetch_assoc()) {
        $counts[$row['record_type']] = $row['count'];
        $total += $row['count'];
    }
    $counts['all'] = $total;
    $count_query->close();
}

// Function to generate default avatar with initials
function generateDefaultAvatar($username) {
    $name_parts = explode(' ', $username);
    $initials = '';
    
    if (count($name_parts) > 0) {
        $initials .= strtoupper(substr($name_parts[0], 0, 1));
    }
    
    if (count($name_parts) > 1) {
        $initials .= strtoupper(substr($name_parts[count($name_parts) - 1], 0, 1));
    }
    
    if (empty($initials) && strlen($username) >= 2) {
        $initials = strtoupper(substr($username, 0, 2));
    } elseif (empty($initials)) {
        $initials = 'U';
    }
    
    return $initials;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Diagnoses - BSU Clinic Record Management System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/png" href="assets/css/images/logo-bsu.png">
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
        
        .form-card-history {
            background: linear-gradient(135deg, var(--maroon-primary), #990000);
        }
        
        .form-card-dental {
            background: linear-gradient(135deg, #990000, #b30000);
        }
        
        .form-card-medical {
            background: linear-gradient(135deg, #b30000, #cc0000);
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
        
        /* Filter button styles */
        .filter-btn {
            transition: all 0.3s ease;
        }
        
        .filter-btn.active {
            background: linear-gradient(135deg, var(--maroon-primary), var(--maroon-light));
            color: white;
            transform: scale(1.05);
        }
        
        .filter-btn:not(.active) {
            background: white;
            color: #6b7280;
            border: 2px solid #e5e7eb;
        }
        
        .filter-btn:not(.active):hover {
            background: #f9fafb;
            border-color: var(--maroon-primary);
        }
        
        body {
            background: linear-gradient(135deg, #fff5f5, #ffe5e5);
            min-height: 100vh;
            padding: 20px;
        }
    </style>
</head>
<body>

    <!-- Main Content -->
    <div class="max-w-7xl mx-auto">
        <!-- Filter Section -->
        <div class="bg-white rounded-xl shadow-md p-6 mb-8">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-800">Filter by Form Type</h3>
                <span class="text-sm text-gray-600">Total: <?php echo $counts['all']; ?> diagnosis(es)</span>
            </div>
            <div class="flex flex-wrap gap-3">
                <a href="?filter=all" class="filter-btn px-4 py-2 rounded-lg font-medium flex items-center gap-2 <?php echo $filter_type === 'all' ? 'active' : ''; ?>">
                    <i class="bi bi-list-ul"></i> All Forms
                    <span class="bg-white/20 px-2 py-0.5 rounded-full text-xs"><?php echo $counts['all']; ?></span>
                </a>
                <a href="?filter=history_form" class="filter-btn px-4 py-2 rounded-lg font-medium flex items-center gap-2 <?php echo $filter_type === 'history_form' ? 'active' : ''; ?>">
                    <i class="bi bi-clipboard2-pulse-fill"></i> Medical History
                    <span class="bg-white/20 px-2 py-0.5 rounded-full text-xs"><?php echo $counts['history_form']; ?></span>
                </a>
                <a href="?filter=medical_exam" class="filter-btn px-4 py-2 rounded-lg font-medium flex items-center gap-2 <?php echo $filter_type === 'medical_exam' ? 'active' : ''; ?>">
                    <i class="bi bi-heart-pulse"></i> Medical Exam
                    <span class="bg-white/20 px-2 py-0.5 rounded-full text-xs"><?php echo $counts['medical_exam']; ?></span>
                </a>
                <a href="?filter=dental_exam" class="filter-btn px-4 py-2 rounded-lg font-medium flex items-center gap-2 <?php echo $filter_type === 'dental_exam' ? 'active' : ''; ?>">
                    <i class="bi bi-tooth"></i> Dental Exam
                    <span class="bg-white/20 px-2 py-0.5 rounded-full text-xs"><?php echo $counts['dental_exam']; ?></span>
                </a>
            </div>
        </div>

        <!-- Diagnoses List -->
        <div class="space-y-4">
            <?php if (!empty($diagnoses)): ?>
                <?php foreach ($diagnoses as $diagnosis): 
                    // Determine diagnosis type badge color
                    $type_badge_class = '';
                    $type_icon = '';
                    switch(strtolower($diagnosis['diagnosis_type'])) {
                        case 'nurse':
                            $type_badge_class = 'bg-blue-100 text-blue-800';
                            $type_icon = 'bi-heart-pulse';
                            break;
                        case 'dentist':
                            $type_badge_class = 'bg-green-100 text-green-800';
                            $type_icon = 'bi-tooth';
                            break;
                        case 'doctor':
                            $type_badge_class = 'bg-red-100 text-red-800';
                            $type_icon = 'bi-stethoscope';
                            break;
                        default:
                            $type_badge_class = 'bg-gray-100 text-gray-800';
                            $type_icon = 'bi-clipboard2-pulse';
                    }
                    
                    // Determine severity badge
                    $severity_badge_class = '';
                    switch(strtolower($diagnosis['severity'] ?? '')) {
                        case 'mild':
                            $severity_badge_class = 'bg-yellow-100 text-yellow-800';
                            break;
                        case 'moderate':
                            $severity_badge_class = 'bg-orange-100 text-orange-800';
                            break;
                        case 'severe':
                            $severity_badge_class = 'bg-red-100 text-red-800';
                            break;
                        case 'critical':
                            $severity_badge_class = 'bg-red-200 text-red-900 font-bold';
                            break;
                        default:
                            $severity_badge_class = 'bg-gray-100 text-gray-800';
                    }
                    
                    // Determine status badge
                    $status_badge_class = '';
                    switch(strtolower($diagnosis['status'] ?? '')) {
                        case 'active':
                            $status_badge_class = 'bg-blue-100 text-blue-800';
                            break;
                        case 'resolved':
                            $status_badge_class = 'bg-green-100 text-green-800';
                            break;
                        case 'chronic':
                            $status_badge_class = 'bg-orange-100 text-orange-800';
                            break;
                        case 'follow_up':
                            $status_badge_class = 'bg-purple-100 text-purple-800';
                            break;
                        default:
                            $status_badge_class = 'bg-gray-100 text-gray-800';
                    }
                    
                    // Determine form type badge
                    $form_badge_class = '';
                    $form_icon = '';
                    $form_display_name = '';
                    switch($diagnosis['record_type']) {
                        case 'history_form':
                            $form_badge_class = 'bg-purple-100 text-purple-800';
                            $form_icon = 'bi-clipboard2-pulse-fill';
                            $form_display_name = 'Medical History Form';
                            break;
                        case 'medical_exam':
                            $form_badge_class = 'bg-red-100 text-red-800';
                            $form_icon = 'bi-heart-pulse';
                            $form_display_name = 'Medical Examination Form';
                            break;
                        case 'dental_exam':
                            $form_badge_class = 'bg-green-100 text-green-800';
                            $form_icon = 'bi-tooth';
                            $form_display_name = 'Dental Examination Form';
                            break;
                        default:
                            $form_badge_class = 'bg-gray-100 text-gray-800';
                            $form_icon = 'bi-clipboard';
                            $form_display_name = ucfirst(str_replace('_', ' ', $diagnosis['record_type']));
                    }
                ?>
                    <div class="border-l-4 border-maroon bg-gradient-to-r from-maroon-bg to-white rounded-lg p-6 hover:shadow-lg transition-all duration-200">
                        <div class="flex items-start justify-between mb-4">
                            <div class="flex items-center gap-3">
                                <div class="<?php echo $form_badge_class; ?> px-4 py-2 rounded-full text-sm font-semibold flex items-center gap-2">
                                    <i class="bi <?php echo $form_icon; ?>"></i>
                                    <?php echo $form_display_name; ?>
                                </div>
                                <div class="<?php echo $type_badge_class; ?> px-3 py-1 rounded-full text-sm font-semibold flex items-center gap-2">
                                    <i class="bi <?php echo $type_icon; ?>"></i>
                                    <?php echo ucfirst($diagnosis['diagnosis_type']); ?>
                                </div>
                                <span class="text-sm text-gray-600 flex items-center gap-1">
                                    <i class="bi bi-calendar3"></i> <?php echo date('M j, Y', strtotime($diagnosis['diagnosis_date'])); ?>
                                </span>
                            </div>
                            <div class="flex gap-2">
                                <?php if ($diagnosis['severity']): ?>
                                    <span class="px-3 py-1 rounded-full text-xs font-semibold <?php echo $severity_badge_class; ?>">
                                        <?php echo ucfirst($diagnosis['severity']); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($diagnosis['status']): ?>
                                    <span class="px-3 py-1 rounded-full text-xs font-semibold <?php echo $status_badge_class; ?>">
                                        <?php echo ucfirst($diagnosis['status']); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="space-y-3">
                            <div class="bg-white p-3 rounded-lg border-l-2 border-blue-500">
                                <span class="font-semibold text-gray-700">Healthcare Provider:</span>
                                <span class="text-gray-900 ml-2"><?php echo htmlspecialchars($diagnosis['provider_full_name'] ?? $diagnosis['provider_name']); ?></span>
                                <?php if ($diagnosis['provider_role']): ?>
                                    <span class="text-gray-600 text-sm ml-2">(<?php echo htmlspecialchars($diagnosis['provider_role']); ?>)</span>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Display Nurse Notes and Date if available -->
                            <?php if (!empty($diagnosis['nurse_note'])): ?>
                                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                                    <div class="flex items-start justify-between mb-2">
                                        <span class="font-semibold text-blue-800 flex items-center gap-2">
                                            <i class="bi bi-heart-pulse"></i> Nurse's Notes
                                        </span>
                                        <?php if (!empty($diagnosis['nurse_diagnosis_date'])): ?>
                                            <span class="text-blue-600 text-sm">
                                                <i class="bi bi-calendar"></i> <?php echo date('M j, Y', strtotime($diagnosis['nurse_diagnosis_date'])); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-gray-800"><?php echo nl2br(htmlspecialchars($diagnosis['nurse_note'])); ?></p>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Display Doctor Notes and Date if available -->
                            <?php if (!empty($diagnosis['doctor_note'])): ?>
                                <div class="bg-red-50 border border-red-200 rounded-lg p-4">
                                    <div class="flex items-start justify-between mb-2">
                                        <span class="font-semibold text-red-800 flex items-center gap-2">
                                            <i class="bi bi-stethoscope"></i> Doctor's Notes
                                        </span>
                                        <?php if (!empty($diagnosis['doctor_diagnosis_date'])): ?>
                                            <span class="text-red-600 text-sm">
                                                <i class="bi bi-calendar"></i> <?php echo date('M j, Y', strtotime($diagnosis['doctor_diagnosis_date'])); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-gray-800"><?php echo nl2br(htmlspecialchars($diagnosis['doctor_note'])); ?></p>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($diagnosis['chief_complaint']): ?>
                                <div>
                                    <span class="font-semibold text-gray-700">Chief Complaint:</span>
                                    <p class="text-gray-900 mt-1 bg-white p-3 rounded"><?php echo nl2br(htmlspecialchars($diagnosis['chief_complaint'])); ?></p>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($diagnosis['assessment']): ?>
                                <div>
                                    <span class="font-semibold text-gray-700">Assessment/Diagnosis:</span>
                                    <p class="text-gray-900 mt-1 bg-white p-3 rounded border-l-4 border-maroon font-medium">
                                        <?php echo nl2br(htmlspecialchars($diagnosis['assessment'])); ?>
                                    </p>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($diagnosis['objective_findings'] || $diagnosis['subjective_findings']): ?>
                                <div>
                                    <span class="font-semibold text-gray-700">Findings:</span>
                                    <div class="text-gray-900 mt-1 space-y-2">
                                        <?php if ($diagnosis['subjective_findings']): ?>
                                            <div class="bg-white p-3 rounded">
                                                <span class="text-sm font-medium text-gray-600">Subjective:</span>
                                                <p class="mt-1"><?php echo nl2br(htmlspecialchars($diagnosis['subjective_findings'])); ?></p>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($diagnosis['objective_findings']): ?>
                                            <div class="bg-white p-3 rounded">
                                                <span class="text-sm font-medium text-gray-600">Objective:</span>
                                                <p class="mt-1"><?php echo nl2br(htmlspecialchars($diagnosis['objective_findings'])); ?></p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($diagnosis['plan']): ?>
                                <div>
                                    <span class="font-semibold text-gray-700">Treatment Plan:</span>
                                    <p class="text-gray-900 mt-1 bg-white p-3 rounded"><?php echo nl2br(htmlspecialchars($diagnosis['plan'])); ?></p>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($diagnosis['medications_prescribed']): ?>
                                <div>
                                    <span class="font-semibold text-gray-700">Medications Prescribed:</span>
                                    <p class="text-gray-900 mt-1 bg-white p-3 rounded border-l-2 border-green-500"><?php echo nl2br(htmlspecialchars($diagnosis['medications_prescribed'])); ?></p>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($diagnosis['follow_up_required']): ?>
                                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-3">
                                    <span class="font-semibold text-yellow-800">Follow-up Required:</span>
                                    <?php if ($diagnosis['follow_up_date']): ?>
                                        <span class="text-yellow-900 ml-2"><?php echo date('M j, Y', strtotime($diagnosis['follow_up_date'])); ?></span>
                                    <?php else: ?>
                                        <span class="text-yellow-900 ml-2">Please schedule a follow-up appointment</span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($diagnosis['notes']): ?>
                                <div>
                                    <span class="font-semibold text-gray-700">Additional Notes:</span>
                                    <p class="text-gray-900 mt-1 text-sm italic bg-white p-3 rounded"><?php echo nl2br(htmlspecialchars($diagnosis['notes'])); ?></p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="bg-white rounded-xl shadow-md p-12 text-center">
                    <div class="bg-gray-100 w-20 h-20 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="bi bi-clipboard2-heart text-gray-400 text-4xl"></i>
                    </div>
                    <h3 class="text-xl font-semibold text-gray-700 mb-2">No Diagnoses Found</h3>
                    <p class="text-gray-500 mb-4">
                        <?php if ($filter_type !== 'all'): ?>
                            No diagnoses found for <?php echo 
                                $filter_type === 'history_form' ? 'Medical History Forms' : 
                                ($filter_type === 'medical_exam' ? 'Medical Examination Forms' : 
                                'Dental Examination Forms'); ?>.
                        <?php else: ?>
                            You don't have any diagnoses yet. Diagnoses will appear here after your clinic visits.
                        <?php endif; ?>
                    </p>
                    <?php if ($filter_type !== 'all'): ?>
                        <a href="?filter=all" class="maroon-gradient-button text-white px-4 py-2 rounded-lg font-medium inline-block">
                            View All Diagnoses
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Simple Footer -->
        <div class="mt-8 pt-6 border-t border-gray-200 text-center">
            <p class="text-gray-600 text-sm">
                <small>&copy; <?php echo date('Y'); ?> Batangas State University - Clinic Record Management System</small>
            </p>
        </div>
    </div>

    <script>
    // Update time every second
    function updateTime() {
        const now = new Date();
        const options = {
            hour: 'numeric',
            minute: 'numeric',
            second: 'numeric',
            hour12: true
        };
        const timeString = now.toLocaleTimeString('en-PH', options);
        document.getElementById('currentTime').textContent = timeString;
    }

    // Initialize
    updateTime();
    setInterval(updateTime, 1000);
    </script>
</body>
</html>