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

// Get full name for display - prioritize users table full_name (from registration), then user_details, then construct from patient_info
$display_name = $_SESSION['username']; // Default fallback

// Check if user_details table exists (needed for later)
$check_user_details = $conn->query("SHOW TABLES LIKE 'user_details'");

// First, try to get full_name from users table (where we save it during registration)
if (!empty($user_profile['full_name'])) {
    $display_name = trim($user_profile['full_name']);
} else {
    // Fallback to user_details table
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
            $display_name = trim(implode(' ', $name_parts));
        }
    }
}

// If no patient info, try to get from user_details and create patient record
if (!$patient_info) {
    if ($check_user_details->num_rows > 0) {
        $user_details = $conn->query("SELECT * FROM user_details WHERE user_id = $user_id");
        if ($user_details && $user_details->num_rows > 0) {
            $details = $user_details->fetch_assoc();

            // Parse full name into components
            $name_parts = explode(' ', trim($details['full_name']));
            $first_name = $name_parts[0] ?? '';
            $middle_name = '';
            $last_name = '';

            if (count($name_parts) > 2) {
                $middle_name = $name_parts[1];
                $last_name = implode(' ', array_slice($name_parts, 2));
            } elseif (count($name_parts) > 1) {
                $last_name = $name_parts[1];
            }

            // Create patient record from user_details
            $stmt = $conn->prepare("INSERT INTO patients (student_id, first_name, middle_name, last_name, date_of_birth, sex, program, year_level) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssssss", $_SESSION['username'], $first_name, $middle_name, $last_name, $details['birthdate'], $details['gender'], $details['department'], $details['year_level']);
            if ($stmt->execute()) {
                // Now fetch the newly created patient info
                $patient_info = $conn->query("
                    SELECT * FROM patients WHERE student_id = '" . $conn->real_escape_string($_SESSION['username']) . "'
                ")->fetch_assoc();
            }
            $stmt->close();
        }
    }
}

// NEW: Check if user has already submitted forms
$has_submitted_forms = false;
$submitted_forms = [];

if ($patient_info && isset($patient_info['id'])) {
    $submission_check = $conn->prepare("
        SELECT record_type, verification_status, created_at 
        FROM medical_records 
        WHERE patient_id = ? 
        ORDER BY created_at DESC
    ");
    $submission_check->bind_param("i", $patient_info['id']);
    $submission_check->execute();
    $submission_result = $submission_check->get_result();
    
    if ($submission_result->num_rows > 0) {
        $has_submitted_forms = true;
        while($row = $submission_result->fetch_assoc()) {
            $submitted_forms[$row['record_type']] = $row;
        }
    }
    $submission_check->close();
}

// Create activity log table if it doesn't exist
$create_activity_table = $conn->query("
    CREATE TABLE IF NOT EXISTS user_activities (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        activity_type VARCHAR(100) NOT NULL,
        activity_description VARCHAR(255) NOT NULL,
        related_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )
");

// Get recent activities from the new activity log
$recent_activities = $conn->query("
    SELECT activity_type, activity_description, created_at 
    FROM user_activities 
    WHERE user_id = $user_id 
    ORDER BY created_at DESC 
    LIMIT 5
");

// If no activities in the log, check other sources and migrate them
if ($recent_activities->num_rows == 0) {
    // Check medical_records table and migrate data
    $medical_records = $conn->query("
        SELECT record_type, created_at 
        FROM medical_records 
        WHERE patient_id = $user_id 
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    
    if ($medical_records && $medical_records->num_rows > 0) {
        while($record = $medical_records->fetch_assoc()) {
            $activity_type = 'form_submission';
            $description = '';
            
            switch($record['record_type']) {
                case 'history_form':
                    $description = 'History Form for Student-Athletes in Sports Events Submitted';
                    break;
                case 'dental_exam':
                    $description = 'Dental Examination Form Submitted';
                    break;
                case 'medical_exam':
                    $description = 'Medical Examination Form Submitted';
                    break;
                default:
                    $description = 'Form Submitted';
            }
            
            // Insert into activity log
            $stmt = $conn->prepare("INSERT INTO user_activities (user_id, activity_type, activity_description, created_at) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("isss", $user_id, $activity_type, $description, $record['created_at']);
            $stmt->execute();
        }
    }
    
    // Check user_details table for profile activities
    $check_user_details = $conn->query("SHOW TABLES LIKE 'user_details'");
    if ($check_user_details->num_rows > 0) {
        $user_details = $conn->query("SELECT * FROM user_details WHERE user_id = $user_id");
        if ($user_details && $user_details->num_rows > 0) {
            $profile = $user_details->fetch_assoc();
            
            // Add profile creation activity
            $stmt = $conn->prepare("INSERT INTO user_activities (user_id, activity_type, activity_description, created_at) VALUES (?, 'profile_update', 'Profile Created', ?)");
            $stmt->bind_param("is", $user_id, $profile['created_at']);
            $stmt->execute();
            
            // Add profile update activity if updated
            if ($profile['updated_at'] != $profile['created_at']) {
                $stmt = $conn->prepare("INSERT INTO user_activities (user_id, activity_type, activity_description, created_at) VALUES (?, 'profile_update', 'Profile Updated', ?)");
                $stmt->bind_param("is", $user_id, $profile['updated_at']);
                $stmt->execute();
            }
        }
    }
    
    // Get activities again after migration
    $recent_activities = $conn->query("
        SELECT activity_type, activity_description, created_at 
        FROM user_activities 
        WHERE user_id = $user_id 
        ORDER BY created_at DESC 
        LIMIT 5
    ");
}

// Function to get activity icon
function getActivityIcon($activity_type) {
    switch($activity_type) {
        case 'form_submission':
            return 'bi-clipboard-check text-green-600';
        case 'profile_update':
            return 'bi-person-check text-purple-600';
        case 'medical_history':
            return 'bi-clipboard2-pulse-fill text-red-600';
        case 'dental_exam':
            return 'bi-tooth text-blue-600';
        case 'medical_exam':
            return 'bi-heart-pulse-fill text-yellow-600';
        default:
            return 'bi-activity text-gray-600';
    }
}

// Function to get activity background color
function getActivityBg($activity_type) {
    switch($activity_type) {
        case 'form_submission':
            return 'bg-green-100';
        case 'profile_update':
            return 'bg-purple-100';
        case 'medical_history':
            return 'bg-red-100';
        case 'dental_exam':
            return 'bg-blue-100';
        case 'medical_exam':
            return 'bg-yellow-100';
        default:
            return 'bg-gray-100';
    }
}

// Function to generate default avatar with initials
function generateDefaultAvatar($username) {
    $name_parts = explode(' ', $username);
    $initials = '';
    
    // Get first letter of first name
    if (count($name_parts) > 0) {
        $initials .= strtoupper(substr($name_parts[0], 0, 1));
    }
    
    // Get first letter of last name if available
    if (count($name_parts) > 1) {
        $initials .= strtoupper(substr($name_parts[count($name_parts) - 1], 0, 1));
    }
    
    // If no spaces, just get first two characters
    if (empty($initials) && strlen($username) >= 2) {
        $initials = strtoupper(substr($username, 0, 2));
    } elseif (empty($initials)) {
        $initials = 'U'; // Default if username is too short
    }
    
    return $initials;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Dashboard - BSU Clinic Record Management System</title>
    <script src="https://cdn.tailwindcss.com"></script>
     <link rel="icon" type="image/png" href="assets/css/images/logo-bsu.png">
     <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
     <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        /* Custom red to orange gradient theme */
        .red-orange-gradient {
            background: linear-gradient(135deg, #dc2626, #ea580c, #f97316);
        }
        
        .red-orange-gradient-light {
            background: linear-gradient(135deg, #fef2f2, #ffedd5, #fed7aa);
        }
        
        .red-orange-gradient-card {
            background: linear-gradient(135deg, #dc2626, #ea580c, #f97316);
        }
        
        .red-orange-gradient-card-light {
            background: linear-gradient(135deg, #fef2f2, #ffedd5);
        }
        
        .red-orange-gradient-button {
            background: linear-gradient(135deg, #dc2626, #ea580c);
        }
        
        .red-orange-gradient-button:hover {
            background: linear-gradient(135deg, #b91c1c, #c2410c);
        }
        
        .red-orange-gradient-alert {
            background: linear-gradient(135deg, #fef2f2, #ffedd5);
            border-left-color: #ea580c;
        }
        
        .red-orange-table-header {
            background: linear-gradient(135deg, #dc2626, #ea580c);
        }
        
        .red-orange-table-row {
            background: linear-gradient(135deg, #fef2f2, #ffedd5);
        }
        
        .red-orange-table-row:hover {
            background: linear-gradient(135deg, #fee2e2, #fed7aa);
        }
        
        .red-orange-badge {
            background: linear-gradient(135deg, #fecaca, #fed7aa);
            color: #7c2d12;
        }
        
        .red-orange-badge-verified {
            background: linear-gradient(135deg, #dcfce7, #bbf7d0);
            color: #166534;
        }
        
        .red-orange-badge-pending {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            color: #92400e;
        }
        
        .red-orange-badge-rejected {
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            color: #991b1b;
        }
        
        .stats-card-1 {
            background: linear-gradient(135deg, #dc2626, #ea580c);
        }
        
        .stats-card-2 {
            background: linear-gradient(135deg, #ea580c, #f97316);
        }
        
        .stats-card-3 {
            background: linear-gradient(135deg, #f97316, #fb923c);
        }
        
        .form-card-history {
            background: linear-gradient(135deg, #dc2626, #ea580c);
        }
        
        .form-card-dental {
            background: linear-gradient(135deg, #ea580c, #f97316);
        }
        
        .form-card-medical {
            background: linear-gradient(135deg, #f97316, #fb923c);
        }

        /* Dental Chart Styles */
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

        .spinner-border {
            display: inline-block;
            width: 2rem;
            height: 2rem;
            vertical-align: text-bottom;
            border: 0.25em solid currentColor;
            border-right-color: transparent;
            border-radius: 50%;
            animation: spinner-border .75s linear infinite;
        }

        @keyframes spinner-border {
            to { transform: rotate(360deg); }
        }

        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0,0,0,0);
            white-space: nowrap;
            border: 0;
        }

        /* Staff form styles */
        .staff-form-card {
            background: linear-gradient(135deg, #9ca3af, #6b7280, #4b5563);
            cursor: not-allowed;
        }
        
        .staff-form-card:hover {
            background: linear-gradient(135deg, #9ca3af, #6b7280, #4b5563);
            transform: none;
        }
    </style>
</head>
<body class="bg-gradient-to-br from-orange-50 to-red-50 min-h-screen flex flex-col">

    <header class="red-orange-gradient text-white shadow-md sticky top-0 z-10">
        <div class="max-w-7xl mx-auto flex items-center justify-between px-6 py-3">
            <div class="flex items-center gap-3">
                <img src="assets/css/images/logo-bsu.png" alt="BSU Logo" class="w-12 h-12 rounded-full object-cover border-4 border-white bg-white">
                <h1 class="text-lg font-bold">BSU Clinic Record Management System</h1>
            </div>
            <nav class="flex items-center gap-6">
                <a href="user_dashboard.php" class="hover:text-yellow-200 flex items-center gap-1 font-semibold">
                    <i class="bi bi-speedometer2"></i> Dashboard
                </a>
                <a href="my_diagnoses.php" class="hover:text-yellow-200 flex items-center gap-1">
                    <i class="bi bi-clipboard2-heart-fill"></i> My Diagnoses
                </a>
                <a href="update_user_profile.php" class="hover:text-yellow-200 flex items-center gap-1">
                    <i class="bi bi-person-circle"></i> Profile
                </a>
                <a href="logout.php" class="red-orange-gradient-button text-white px-3 py-1 rounded-lg font-semibold hover:shadow-lg flex items-center gap-1">
                    <i class="bi bi-box-arrow-right"></i> Logout
                </a>
            </nav>
        </div>
    </header>

    <main class="flex-grow max-w-7xl mx-auto px-4 py-8 w-full">
        <!-- Welcome Section -->
        <div class="bg-white rounded-2xl shadow-xl overflow-hidden mb-8">
            <div class="red-orange-gradient px-8 py-6">
                <div class="flex items-center gap-4">
                    <!-- Default Profile Avatar -->
                    <div class="w-16 h-16 bg-white rounded-full flex items-center justify-center text-red-800 text-xl font-bold border-4 border-white shadow-lg">
                        <?php echo generateDefaultAvatar($_SESSION['username']); ?>
                    </div>
                    <div class="flex-1">
                        <h3 class="text-xl font-bold text-white mb-1">Welcome, <?php echo htmlspecialchars($display_name); ?></h3>
                        <p class="text-white text-sm">Student Profile</p>
                    </div>
                    <div class="text-right text-white">
                        <p class="text-sm">Today is</p>
                        <p class="font-semibold"><?php echo date('l, F j, Y'); ?></p>
                        <p class="text-sm" id="currentTime"><?php echo date('g:i A'); ?></p>
                    </div>
                </div>
            </div>
            <div class="px-8 py-4 bg-gray-50">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <span class="text-gray-700 font-semibold">SR Code:</span>
                        <span class="text-gray-900"><?php echo htmlspecialchars($_SESSION['username']); ?></span>
                    </div>
                    <div>
                        <span class="text-gray-700 font-semibold">Role:</span>
                        <span class="text-gray-900">Student</span>
                    </div>
                    <div>
                        <span class="text-gray-700 font-semibold">Member Since:</span>
                        <span class="text-gray-900"><?php echo date('M j, Y', strtotime($user_profile['created_at'])); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Left Column: Forms -->
            <div class="lg:col-span-2">
                <!-- Recent Form Submissions -->
                <div class="bg-white rounded-xl shadow-md p-6 mb-8">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-xl font-semibold flex items-center gap-2">
                            <i class="bi bi-clipboard2-pulse-fill text-orange-600"></i> Recent Form Submissions
                        </h3>
                    </div>
                    <?php
                    // Get patient ID from patients table using student_id
                    $patient_query = $conn->prepare("SELECT id FROM patients WHERE student_id = ?");
                    $patient_query->bind_param("s", $_SESSION['username']);
                    $patient_query->execute();
                    $patient_result = $patient_query->get_result();
                    $patient_id = null;
                    if ($patient_result->num_rows > 0) {
                        $patient_row = $patient_result->fetch_assoc();
                        $patient_id = $patient_row['id'];
                    }
                    $patient_query->close();

                    $user_submissions_query = "
                        SELECT mr.id, mr.record_type, mr.examination_date, mr.verification_status, mr.created_at
                        FROM medical_records mr
                        WHERE mr.patient_id = ?
                        ORDER BY mr.created_at DESC
                        LIMIT 5
                    ";
                    $stmt = $conn->prepare($user_submissions_query);
                    $stmt->bind_param("i", $patient_id);
                    $stmt->execute();
                    $user_submissions_result = $stmt->get_result();
                    ?>
                    <?php if ($user_submissions_result && $user_submissions_result->num_rows > 0): ?>
                        <div class="space-y-3">
                            <?php while ($submission = $user_submissions_result->fetch_assoc()): ?>
                                <div class="red-orange-table-row p-3 rounded-lg hover:shadow transition-all duration-200">
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <p class="font-medium"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $submission['record_type']))); ?></p>
                                            <p class="text-sm text-gray-600">Submitted: <?php echo date('M d, Y', strtotime($submission['created_at'])); ?></p>
                                        </div>
                                        <div class="text-right">
                                            <span class="px-2 py-1 rounded-full text-xs font-semibold <?php
                                                echo $submission['verification_status'] === 'verified' ? 'red-orange-badge-verified' :
                                                    ($submission['verification_status'] === 'rejected' ? 'red-orange-badge-rejected' :
                                                    'red-orange-badge-pending');
                                            ?>">
                                                <?php echo strtoupper($submission['verification_status']); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-6">
                            <div class="bg-gray-100 w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-3">
                                <i class="bi bi-clipboard2-pulse text-gray-400 text-2xl"></i>
                            </div>
                            <p class="text-gray-500 mb-2">No form submissions found</p>
                            <p class="text-gray-400 text-sm">Your recent submissions will appear here</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Medical Diagnoses Quick Access Card -->
                <?php
                // Get diagnosis count for display
                $diagnosis_count = 0;
                if ($patient_info && isset($patient_info['id'])) {
                    $count_query = $conn->prepare("SELECT COUNT(*) as count FROM medical_diagnoses WHERE patient_id = ?");
                    $count_query->bind_param("i", $patient_info['id']);
                    $count_query->execute();
                    $count_result = $count_query->get_result();
                    if ($count_result->num_rows > 0) {
                        $count_row = $count_result->fetch_assoc();
                        $diagnosis_count = $count_row['count'];
                    }
                    $count_query->close();
                }
                ?>
                <div class="bg-white rounded-xl shadow-md p-6 mb-8 border-l-4 border-red-500">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-4">
                            <div class="bg-red-100 p-4 rounded-full">
                                <i class="bi bi-clipboard2-heart-fill text-red-600 text-3xl"></i>
                            </div>
                            <div>
                                <h3 class="text-xl font-semibold text-gray-800 mb-1">Medical Diagnoses & Findings</h3>
                                <p class="text-gray-600 text-sm">
                                    <?php if ($diagnosis_count > 0): ?>
                                        You have <?php echo $diagnosis_count; ?> diagnosis(es) from healthcare providers
                                    <?php else: ?>
                                        View your medical diagnoses and findings from doctors, dentists, and nurses
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                        <a href="my_diagnoses.php" class="red-orange-gradient-button text-white px-6 py-3 rounded-lg font-semibold hover:shadow-lg transition-all flex items-center gap-2">
                            <i class="bi bi-arrow-right-circle"></i> View All Diagnoses
                        </a>
                    </div>
                </div>

                <!-- Clinic Forms Section -->
                <div class="bg-white rounded-2xl shadow-xl overflow-hidden">
                    <div class="px-6 pt-6 pb-4 red-orange-table-header text-white">
                        <h2 class="text-xl font-bold">Clinic Forms</h2>
                        <p class="text-white text-sm opacity-90">Fill out your medical forms</p>
                    </div>
                    <div class="p-6">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <!-- Student Fillable Form -->
                            <button onclick="openFormModal('history')" class="block form-card-history text-white rounded-xl p-5 shadow-lg hover:shadow-xl transition duration-300 transform hover:scale-[1.02] border-b-4 border-yellow-500 relative">
                                <div class="flex items-center justify-center mb-3">
                                    <i class="bi bi-clipboard2-pulse-fill text-3xl"></i>
                                </div>
                                <h3 class="text-lg font-semibold tracking-tight text-center">Medical History Form for Athlete</h3>
                                <p class="text-sm mt-1 opacity-90 text-center">Update your medical history</p>
                                <?php if (isset($submitted_forms['history_form'])): ?>
                                    <div class="absolute top-2 right-2 bg-green-500 text-white px-2 py-1 rounded-full text-xs font-semibold">
                                        SUBMITTED
                                    </div>
                                <?php endif; ?>
                            </button>

                            <!-- Dental Examination Form - Now clickable but with staff-only fields inside -->
                         <button onclick="openFormModal('dental')" class="block form-card-dental text-white rounded-xl p-5 shadow-lg hover:shadow-xl transition duration-300 transform hover:scale-[1.02] border-b-4 border-yellow-500 relative">
    <div class="flex items-center justify-center mb-3">
        <i class="fas fa-tooth text-3xl"></i>
    </div>
    <h3 class="text-lg font-semibold tracking-tight text-center">Dental Examination</h3>
    <p class="text-sm mt-1 opacity-90 text-center">Complete dental checkup form</p>
    <?php if (isset($submitted_forms['dental_exam'])): ?>
        <div class="absolute top-2 right-2 bg-green-500 text-white px-2 py-1 rounded-full text-xs font-semibold">
            SUBMITTED
        </div>
    <?php endif; ?>
</button>

                            <!-- Medical Examination Form - Now clickable but with staff-only fields inside -->
                            <button onclick="openFormModal('medical')" class="block form-card-medical text-white rounded-xl p-5 shadow-lg hover:shadow-xl transition duration-300 transform hover:scale-[1.02] border-b-4 border-yellow-500 relative">
                                <div class="flex items-center justify-center mb-3">
                                    <i class="bi bi-heart-pulse text-3xl"></i>
                                </div>
                                <h3 class="text-lg font-semibold tracking-tight text-center">Pre-Employment/OJT Medical Examination</h3>
                                <p class="text-sm mt-1 opacity-90 text-center">Complete medical checkup form</p>
                                <?php if (isset($submitted_forms['medical_exam'])): ?>
                                    <div class="absolute top-2 right-2 bg-green-500 text-white px-2 py-1 rounded-full text-xs font-semibold">
                                        SUBMITTED
                                    </div>
                                <?php endif; ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Profile and Activities -->
            <div class="space-y-8">
                <!-- Profile Summary -->
                <div class="bg-white rounded-2xl shadow-xl overflow-hidden">
                    <div class="px-6 pt-6 pb-4 red-orange-table-header text-white">
                        <h2 class="text-xl font-bold">Profile Summary</h2>
                    </div>
                    <div class="p-6">
                        <div class="flex flex-col items-center mb-4">
                            <!-- Default Profile Avatar -->
                            <div class="w-20 h-20 red-orange-gradient rounded-full flex items-center justify-center text-white text-2xl font-bold mb-3 shadow-lg">
                                <?php echo generateDefaultAvatar($display_name); ?>
                            </div>
                            <h3 class="font-bold text-lg"><?php echo htmlspecialchars($display_name); ?></h3>
                            <p class="text-gray-600 text-sm">Student</p>
                        </div>
                        <div class="space-y-3">
                            <div class="flex justify-between">
                                <span class="text-gray-600">SR Code:</span>
                                <span class="font-medium"><?php echo htmlspecialchars($_SESSION['username']); ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Email:</span>
                                <span class="font-medium"><?php echo htmlspecialchars($user_profile['email'] ?? 'Not set'); ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Member Since:</span>
                                <span class="font-medium"><?php echo date('M j, Y', strtotime($user_profile['created_at'])); ?></span>
                            </div>
                        </div>
                        <a href="update_user_profile.php" class="block w-full mt-4 red-orange-gradient-button text-white text-center py-2 rounded-lg font-medium hover:shadow transition-all">
                            Edit Profile
                        </a>
                    </div>
                </div>

                <!-- Recent Activities -->
                <div class="bg-white rounded-2xl shadow-xl overflow-hidden">
                    <div class="px-6 pt-6 pb-4 red-orange-table-header text-white">
                        <h2 class="text-xl font-bold">Recent Activities</h2>
                    </div>
                    <div class="p-6">
                        <?php if($recent_activities && $recent_activities->num_rows > 0): ?>
                            <div class="space-y-4">
                                <?php while($activity = $recent_activities->fetch_assoc()): ?>
                                    <div class="flex items-start gap-3">
                                        <div class="mt-1">
                                            <div class="<?php echo getActivityBg($activity['activity_type']); ?> p-2 rounded-full">
                                                <i class="bi <?php echo getActivityIcon($activity['activity_type']); ?>"></i>
                                            </div>
                                        </div>
                                        <div class="flex-1">
                                            <p class="font-medium"><?php echo htmlspecialchars($activity['activity_description']); ?></p>
                                            <p class="text-gray-500 text-sm"><?php echo date('M j, Y g:i A', strtotime($activity['created_at'])); ?></p>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-6">
                                <div class="bg-gray-100 w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-3">
                                    <i class="bi bi-activity text-gray-400 text-2xl"></i>
                                </div>
                                <p class="text-gray-500 mb-2">No recent activities</p>
                                <p class="text-gray-400 text-sm">Your activities will appear here</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <footer class="red-orange-gradient text-white py-4 mt-8">
        <div class="max-w-7xl mx-auto px-6 text-center">
            <small>&copy; <?php echo date('Y'); ?> Batangas State University - Clinic Record Management System</small>
        </div>
    </footer>

    <!-- Form Modal -->
    <div id="formModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-6xl w-full max-h-[90vh] overflow-hidden">
                <div class="flex justify-between items-center p-6 border-b">
                    <h2 id="modalTitle" class="text-2xl font-bold text-gray-800">Form</h2>
                    <button onclick="closeFormModal()" class="text-gray-500 hover:text-gray-700">
                        <i class="bi bi-x-lg text-2xl"></i>
                    </button>
                </div>
                <div id="modalContent" class="p-6 overflow-y-auto max-h-[calc(90vh-120px)]">
                    <!-- Form content will be loaded here -->
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript for real-time clock and form tracking -->
    <script>
    // User data for form pre-population - INCLUDING MIDDLE NAME
    const userData = {
        student_id: '<?php echo htmlspecialchars($_SESSION['username']); ?>',
        first_name: '<?php echo htmlspecialchars($patient_info['first_name'] ?? ''); ?>',
        middle_name: '<?php echo htmlspecialchars($patient_info['middle_name'] ?? ''); ?>',
        last_name: '<?php echo htmlspecialchars($patient_info['last_name'] ?? ''); ?>',
        date_of_birth: '<?php echo htmlspecialchars($patient_info['date_of_birth'] ?? ''); ?>',
        sex: '<?php echo htmlspecialchars($patient_info['sex'] ?? ''); ?>',
        program: '<?php echo htmlspecialchars($patient_info['program'] ?? ''); ?>',
        year_level: '<?php echo htmlspecialchars($patient_info['year_level'] ?? ''); ?>',
        email: '<?php echo htmlspecialchars($user_profile['email'] ?? ''); ?>'
    };

    // Dental chart state management
    let dentalChartState = {};

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

    // Update time immediately and then every second
    updateTime();
    setInterval(updateTime, 1000);

    // Form modal functions
    function openFormModal(formType) {
        const modal = document.getElementById('formModal');
        const modalTitle = document.getElementById('modalTitle');
        const modalContent = document.getElementById('modalContent');

        // Set modal title
        const titles = {
            'history': 'Medical History Form',
            'dental': 'Dental Examination Form',
            'medical': 'Medical Examination Form'
        };
        modalTitle.textContent = titles[formType];

        // Load form content
        loadFormContent(formType);

        // Show modal
        modal.classList.remove('hidden');
    }

    function closeFormModal() {
        const modal = document.getElementById('formModal');
        modal.classList.add('hidden');
    }

    function loadFormContent(formType) {
        const modalContent = document.getElementById('modalContent');

        // Show loading
        modalContent.innerHTML = '<div class="text-center py-8"><div class="spinner-border text-orange-600" role="status"><span class="sr-only">Loading...</span></div><p class="mt-2">Loading form...</p></div>';

        // Load form based on type
        fetch(`modules/user_${formType}_form.php`, {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.text())
        .then(html => {
            // Extract the form content from the HTML
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');

            // Get the form element
            const form = doc.querySelector('form');
            if (form) {
                // Remove the session check and redirect logic
                const sessionCheck = form.querySelector('script');
                if (sessionCheck && sessionCheck.textContent.includes('header("Location: ../../login.php")')) {
                    sessionCheck.remove();
                }

                // Update form action to use AJAX
                form.setAttribute('onsubmit', `submitForm(event, '${formType}')`);

                // SPECIAL ENHANCEMENT FOR MEDICAL FORM
                if (formType === 'medical') {
                    enhanceMedicalForm(form);
                }

                // GRAY OUT STAFF-ONLY FIELDS IN ALL FORMS
                grayOutStaffOnlyFields(form, formType);

                modalContent.innerHTML = form.outerHTML;
                
                // Pre-populate form fields AFTER the form is added to DOM
                setTimeout(() => prePopulateForm(), 100);

                // History form specific enhancements (e.g., female-only Q11)
                if (formType === 'history') {
                    setTimeout(() => enhanceHistoryForm(), 150);
                }
                
                // Initialize dental chart if it's a dental form
                if (formType === 'dental') {
                    setTimeout(() => initializeDentalChart(), 200);
                }
                
                // Initialize signature canvas for all forms that have it
                setTimeout(() => initializeSignatureCanvas(), 300);
            } else {
                modalContent.innerHTML = '<div class="text-center py-8 text-red-600">Error loading form</div>';
            }
        })
        .catch(error => {
            console.error('Error loading form:', error);
            modalContent.innerHTML = '<div class="text-center py-8 text-red-600">Error loading form</div>';
        });
    }

    // Function to gray out staff-only fields and add notes
    function grayOutStaffOnlyFields(form, formType) {
        // Common staff-only field patterns across all forms
        const staffFieldPatterns = [
            // Examination fields
            'examination_date', 'exam_date', 'date_of_exam',
            'blood_pressure', 'bp', 'pulse_rate', 'respiratory_rate', 'temperature',
            'height', 'weight', 'bmi', 'vision', 'visual_acuity',
            'clinical_findings', 'findings', 'assessment', 'diagnosis',
            'recommendations', 'treatment', 'remarks', 'notes',
            'physician', 'doctor', 'dentist', 'nurse', 'examined_by',
            'signature', 'license_no', 'prc_no',
            
            // Dental specific
            'oral_hygiene', 'gingival_condition', 'occlusion', 
            'oral_prophylaxis', 'restoration', 'extraction',
            'prosthetic', 'orthodontic', 'periodontal',
            
            // Medical specific  
            'heart', 'lungs', 'abdomen', 'skin', 'extremities',
            'heent', 'neurological', 'musculoskeletal'
        ];

        // Form-specific staff sections
        const formSpecificSections = {
            'dental': [
                'Dental Examination Findings',
                'Oral Diagnosis',
                'Treatment Needed',
                'Dentition Status',
                'Oral Prophylaxis',
                'Restoration',
                'Extraction',
                'Prosthetic',
                'Orthodontic',
                'Periodontal Treatment',
                'Remarks and Recommendations'
            ],
            'medical': [
                'Physical Examination',
                'Clinical Findings',
                'Systemic Review',
                'Diagnosis',
                'Recommendations',
                'Physician\'s Assessment',
                'Laboratory Findings',
                'Clearance'
            ],
            'history': [
                // For history form, only basic info should be student-filled
                'Physical Examination',
                'Clinical Findings',
                'Physician\'s Notes'
            ]
        };

        // Add staff-only notice at the top of the form
        const staffNotice = `
            <div class="bg-blue-50 border-l-4 border-blue-400 p-4 mb-6">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="bi bi-info-circle text-blue-400 text-xl"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm text-blue-700">
                            <strong>Note:</strong> Fields marked with <span class="bg-gray-200 px-2 py-1 rounded text-xs font-medium">STAFF ONLY</span> are for clinic staff (nurse, dentist, doctor) only. 
                            Your basic information has been pre-filled automatically. Fill out what you can, and clinic staff will complete the rest during your appointment.
                        </p>
                    </div>
                </div>
            </div>
        `;

        // Insert notice at the beginning of the form
        form.insertAdjacentHTML('afterbegin', staffNotice);

        // Gray out staff-only fields and add labels
        const allInputs = form.querySelectorAll('input, select, textarea');
        const allLabels = form.querySelectorAll('label');
        const allHeaders = form.querySelectorAll('h1, h2, h3, h4, h5, h6, strong, b');

        // Process input fields
        allInputs.forEach(input => {
            const fieldName = input.name.toLowerCase();
            const fieldId = input.id.toLowerCase();
            
            // Skip student signature fields - these should be editable by students
            if (fieldName.includes('student_signature') || fieldId.includes('student_signature') ||
                fieldName.includes('student_date') || fieldId.includes('student_date')) {
                return; // Skip this field
            }
            
            // Check if this is a staff-only field
            const isStaffField = staffFieldPatterns.some(pattern => 
                fieldName.includes(pattern.toLowerCase()) || fieldId.includes(pattern.toLowerCase())
            );

            if (isStaffField) {
                grayOutField(input);
            }
        });

        // Process section headers
        allHeaders.forEach(header => {
            const headerText = header.textContent.toLowerCase();
            const formSections = formSpecificSections[formType] || [];
            
            const isStaffSection = formSections.some(section => 
                headerText.includes(section.toLowerCase())
            );

            if (isStaffSection) {
                markStaffSection(header);
            }
        });

        // Process labels
        allLabels.forEach(label => {
            const labelText = label.textContent.toLowerCase();
            const isStaffLabel = staffFieldPatterns.some(pattern => 
                labelText.includes(pattern.toLowerCase())
            );

            if (isStaffLabel) {
                markStaffLabel(label);
            }
        });
    }

    function grayOutField(field) {
        field.disabled = true;
        field.readOnly = true;
        field.classList.add('bg-gray-100', 'text-gray-500', 'cursor-not-allowed', 'border-gray-300');
        
        // Add staff-only badge next to the field
        const staffBadge = document.createElement('span');
        staffBadge.className = 'ml-2 bg-gray-200 text-gray-700 text-xs px-2 py-1 rounded font-medium';
        staffBadge.textContent = 'STAFF ONLY';
        
        // Insert badge after the field
        if (field.parentNode) {
            field.parentNode.insertBefore(staffBadge, field.nextSibling);
        }
        
        // Add placeholder text for disabled fields
        if (field.tagName === 'INPUT' && !field.value) {
            field.placeholder = 'To be filled by clinic staff';
        }
    }

    function markStaffSection(sectionElement) {
        const staffBadge = document.createElement('span');
        staffBadge.className = 'ml-2 bg-gray-200 text-gray-700 text-xs px-2 py-1 rounded font-medium';
        staffBadge.textContent = 'STAFF SECTION';
        
        sectionElement.parentNode.insertBefore(staffBadge, sectionElement.nextSibling);
        
        // Add visual styling to the section
        const sectionContainer = sectionElement.closest('.border, .p-4, .mb-6, div') || sectionElement.parentNode;
        sectionContainer.classList.add('bg-gray-50', 'border-gray-200', 'opacity-90');
    }

    function markStaffLabel(label) {
        const labelText = (label.textContent || '').toLowerCase();
        
        // Do NOT mark student/employee signature labels as staff-only
        if (labelText.includes('signature over printed name of student') || labelText.includes('employee')) {
            return;
        }

        const staffBadge = document.createElement('span');
        staffBadge.className = 'ml-2 bg-gray-200 text-gray-700 text-xs px-2 py-1 rounded font-medium';
        staffBadge.textContent = 'STAFF ONLY';
        
        label.parentNode.insertBefore(staffBadge, label.nextSibling);
    }

    // Enhanced dental form function
    function enhanceDentalForm(form) {
        // Find and replace any existing dental chart with our interactive version
        const existingChart = form.querySelector('table, .dental-chart, [class*="chart"], [class*="teeth"]');
        if (existingChart) {
            existingChart.remove();
        }

        // Look for "Dentition Status" or similar sections to replace
        const labels = form.querySelectorAll('label, h3, h4, h5, h6, p, div');
        let dentitionSection = null;
        
        for (let element of labels) {
            if (element.textContent && (
                element.textContent.toLowerCase().includes('dentition') ||
                element.textContent.toLowerCase().includes('dental chart') ||
                element.textContent.toLowerCase().includes('teeth') ||
                element.textContent.toLowerCase().includes('tooth')
            )) {
                dentitionSection = element;
                break;
            }
        }

        // Create the enhanced dental chart HTML
        const dentalChartHTML = `
            <div class="mb-6 bg-gray-50 p-4 rounded-lg border border-gray-200">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-semibold text-gray-700">Dentition Status and Treatment Needs</h3>
                    <span class="bg-gray-200 text-gray-700 text-xs px-2 py-1 rounded font-medium">STAFF ONLY</span>
                </div>
                <p class="text-sm text-gray-600 mb-4">This section will be completed by dental staff during your appointment.</p>
                
                <!-- Dental Chart Controls -->
                <div class="flex flex-wrap gap-4 mb-4 p-4 bg-white rounded-lg border">
                    <div class="flex items-center gap-2">
                        <div class="w-4 h-4 bg-red-500 rounded"></div>
                        <span class="text-sm font-medium">Caries (Cavity)</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="w-4 h-4 bg-blue-500 rounded"></div>
                        <span class="text-sm font-medium">Filling</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="w-4 h-4 bg-yellow-500 rounded"></div>
                        <span class="text-sm font-medium">Extraction Needed</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="w-4 h-4 bg-green-500 rounded"></div>
                        <span class="text-sm font-medium">Healthy</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="w-4 h-4 bg-purple-500 rounded"></div>
                        <span class="text-sm font-medium">Crown/Bridge</span>
                    </div>
                </div>

                <!-- Dental Chart Container -->
                <div class="bg-white border-2 border-gray-300 rounded-xl p-6 mb-4 opacity-70 cursor-not-allowed">
                    <!-- Maxillary (Upper) Teeth -->
                    <div class="mb-8">
                        <h4 class="text-center font-semibold mb-4 text-gray-600">Maxillary (Upper Jaw)</h4>
                        <div class="grid grid-cols-16 gap-1 justify-center mb-2" id="maxillary-teeth">
                            <!-- Teeth 1-8 (Right to Left) -->
                            ${generateToothChart(1, 8, 'maxillary')}
                            <!-- Teeth 9-16 (Left to Right) -->
                            ${generateToothChart(9, 16, 'maxillary')}
                        </div>
                    </div>

                    <!-- Mandibular (Lower) Teeth -->
                    <div>
                        <h4 class="text-center font-semibold mb-4 text-gray-600">Mandibular (Lower Jaw)</h4>
                        <div class="grid grid-cols-16 gap-1 justify-center mb-2" id="mandibular-teeth">
                            <!-- Teeth 17-24 (Left to Right) -->
                            ${generateToothChart(17, 24, 'mandibular')}
                            <!-- Teeth 25-32 (Right to Left) -->
                            ${generateToothChart(25, 32, 'mandibular')}
                        </div>
                    </div>
                </div>

                <div class="text-center text-gray-500 text-sm">
                    <i class="bi bi-lock-fill mr-1"></i> Dental chart will be completed by dental staff
                </div>

                <!-- Hidden input to store dental chart data -->
                <input type="hidden" id="dental_chart_data" name="dental_chart_data" value="" disabled>
            </div>
        `;

        // Insert the dental chart in the appropriate location
        if (dentitionSection) {
            // Replace the existing dentition section
            const parent = dentitionSection.parentElement;
            parent.innerHTML = dentalChartHTML + parent.innerHTML;
            dentitionSection.remove();
        } else {
            // Insert at the beginning of the form
            form.insertAdjacentHTML('afterbegin', dentalChartHTML);
        }
    }

    // Enhanced medical form function
    function enhanceMedicalForm(form) {
        // Add staff notice for medical form
        const medicalNotice = `
            <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 mb-6">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="bi bi-exclamation-triangle text-yellow-400 text-xl"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm text-yellow-700">
                            <strong>Medical Examination Notice:</strong> This form contains sections that must be completed by medical professionals. 
                            Only fill out the basic information sections. Physical examination and assessment will be done by clinic staff.
                        </p>
                    </div>
                </div>
            </div>
        `;
        
        // Insert medical-specific notice
        const existingNotice = form.querySelector('.bg-blue-50');
        if (existingNotice) {
            existingNotice.insertAdjacentHTML('afterend', medicalNotice);
        }
    }

    // History form: handle female-only Question 11 (menstrual_age)
    function enhanceHistoryForm() {
        const form = document.querySelector('#formModal form');
        if (!form) return;

        const sexSelect = form.querySelector('#sex, select[name="sex"]');
        const menstrualAgeInput = form.querySelector('input[name="menstrual_age"]');
        if (!sexSelect || !menstrualAgeInput) return;

        function updateFemaleFields() {
            const isFemale = sexSelect.value === 'Female';

            if (isFemale) {
                menstrualAgeInput.readOnly = false;
                menstrualAgeInput.disabled = false;
                menstrualAgeInput.classList.remove('nurse-only', 'cursor-not-allowed', 'bg-gray-100');
            } else {
                menstrualAgeInput.value = '';
                menstrualAgeInput.readOnly = true;
                menstrualAgeInput.disabled = true;
            }
        }

        // Initial state + listener
        updateFemaleFields();
        sexSelect.addEventListener('change', updateFemaleFields);
    }

    function generateToothChart(start, end, jaw) {
        let html = '';
        const isUpper = jaw === 'maxillary';
        
        for (let i = start; i <= end; i++) {
            const toothName = getToothName(i);
            html += `
                <div class="tooth-container flex flex-col items-center" data-tooth="${i}">
                    <div class="tooth-number text-xs font-semibold text-gray-600 mb-1">${i}</div>
                    <div class="tooth ${isUpper ? 'tooth-upper' : 'tooth-lower'} 
                        w-8 h-12 border-2 border-gray-300 rounded-lg cursor-pointer transition-all duration-200 
                        hover:scale-110 hover:shadow-md bg-white flex items-center justify-center"
                        data-tooth="${i}"
                        onclick="toggleToothCondition(this)">
                        <span class="tooth-label text-xs font-medium text-gray-500">${toothName}</span>
                    </div>
                    <div class="tooth-condition text-xs mt-1 text-center min-h-[16px]"></div>
                </div>
            `;
        }
        return html;
    }

    function getToothName(toothNumber) {
        const toothNames = {
            1: 'M3', 2: 'M2', 3: 'M1', 4: 'P2', 5: 'P1', 6: 'C', 7: 'I2', 8: 'I1',
            9: 'I1', 10: 'I2', 11: 'C', 12: 'P1', 13: 'P2', 14: 'M1', 15: 'M2', 16: 'M3',
            17: 'M3', 18: 'M2', 19: 'M1', 20: 'P2', 21: 'P1', 22: 'C', 23: 'I2', 24: 'I1',
            25: 'I1', 26: 'I2', 27: 'C', 28: 'P1', 29: 'P2', 30: 'M1', 31: 'M2', 32: 'M3'
        };
        return toothNames[toothNumber] || toothNumber.toString();
    }

    function initializeDentalChart() {
        // Initialize empty dental chart state
        dentalChartState = {};
        updateDentalChartSummary();
    }
    
    // Initialize signature canvas for forms loaded in modals
    function initializeSignatureCanvas() {
        const signatureCanvas = document.getElementById('studentSignatureCanvas');
        if (!signatureCanvas) {
            return; // No signature canvas in this form
        }
        
        let canvasContext = signatureCanvas.getContext('2d');
        let drawing = false;
        let lastX = 0;
        let lastY = 0;
        
        // Set canvas size
        const wrapper = signatureCanvas.parentElement;
        const wrapperWidth = wrapper.offsetWidth || wrapper.getBoundingClientRect().width || 600;
        signatureCanvas.width = wrapperWidth;
        signatureCanvas.height = 180;
        
        // Set drawing properties
        canvasContext.lineWidth = 2;
        canvasContext.lineCap = 'round';
        canvasContext.lineJoin = 'round';
        canvasContext.strokeStyle = '#1f2937';
        canvasContext.fillStyle = '#ffffff';
        
        // Clear canvas with white background
        canvasContext.fillRect(0, 0, signatureCanvas.width, signatureCanvas.height);
        
        function getCanvasCoordinates(event) {
            const rect = signatureCanvas.getBoundingClientRect();
            const scaleX = signatureCanvas.width / rect.width;
            const scaleY = signatureCanvas.height / rect.height;
            
            let clientX, clientY;
            
            if (event.touches && event.touches.length > 0) {
                clientX = event.touches[0].clientX;
                clientY = event.touches[0].clientY;
            } else if (event.changedTouches && event.changedTouches.length > 0) {
                clientX = event.changedTouches[0].clientX;
                clientY = event.changedTouches[0].clientY;
            } else {
                clientX = event.clientX;
                clientY = event.clientY;
            }
            
            return {
                x: (clientX - rect.left) * scaleX,
                y: (clientY - rect.top) * scaleY
            };
        }
        
        function updateSignaturePreview() {
            const blank = document.createElement('canvas');
            blank.width = signatureCanvas.width;
            blank.height = signatureCanvas.height;
            const blankCtx = blank.getContext('2d');
            blankCtx.fillStyle = '#ffffff';
            blankCtx.fillRect(0, 0, blank.width, blank.height);
            
            if (signatureCanvas.toDataURL() === blank.toDataURL()) {
                const input = document.getElementById('student_signature_data');
                if (input) input.value = '';
                return;
            }
            
            const dataUrl = signatureCanvas.toDataURL('image/png');
            const input = document.getElementById('student_signature_data');
            if (input) input.value = dataUrl;
        }
        
        function startDrawing(event) {
            drawing = true;
            const coords = getCanvasCoordinates(event);
            lastX = coords.x;
            lastY = coords.y;
            canvasContext.beginPath();
            canvasContext.moveTo(lastX, lastY);
            if (event.preventDefault) event.preventDefault();
            return false;
        }
        
        function draw(event) {
            if (!drawing) return;
            const coords = getCanvasCoordinates(event);
            canvasContext.lineTo(coords.x, coords.y);
            canvasContext.stroke();
            lastX = coords.x;
            lastY = coords.y;
            if (event.preventDefault) event.preventDefault();
            return false;
        }
        
        function stopDrawing(event) {
            if (!drawing) return;
            drawing = false;
            updateSignaturePreview();
            if (event && event.preventDefault) event.preventDefault();
            return false;
        }
        
        // Mouse events
        signatureCanvas.addEventListener('mousedown', startDrawing);
        signatureCanvas.addEventListener('mousemove', draw);
        signatureCanvas.addEventListener('mouseup', stopDrawing);
        signatureCanvas.addEventListener('mouseout', stopDrawing);
        signatureCanvas.addEventListener('mouseleave', stopDrawing);
        
        // Touch events
        signatureCanvas.addEventListener('touchstart', function(e) {
            e.preventDefault();
            startDrawing(e);
        }, { passive: false });
        
        signatureCanvas.addEventListener('touchmove', function(e) {
            e.preventDefault();
            draw(e);
        }, { passive: false });
        
        signatureCanvas.addEventListener('touchend', function(e) {
            e.preventDefault();
            stopDrawing(e);
        }, { passive: false });
        
        signatureCanvas.addEventListener('touchcancel', function(e) {
            e.preventDefault();
            stopDrawing(e);
        }, { passive: false });
        
        // Clear signature button
        const clearBtn = document.getElementById('clearSignature');
        if (clearBtn) {
            clearBtn.onclick = function(e) {
                e.preventDefault();
                canvasContext.clearRect(0, 0, signatureCanvas.width, signatureCanvas.height);
                canvasContext.fillStyle = '#ffffff';
                canvasContext.fillRect(0, 0, signatureCanvas.width, signatureCanvas.height);
                updateSignaturePreview();
            };
        }
        
        // Update signature on form submit
        const form = signatureCanvas.closest('form');
        if (form) {
            form.addEventListener('submit', function() {
                updateSignaturePreview();
            });
        }
        
        console.log('Signature canvas initialized');
    }

    function resetDentalChart() {
        // Reset all teeth to default state
        const allTeeth = document.querySelectorAll('.tooth');
        allTeeth.forEach(tooth => {
            const toothNumber = parseInt(tooth.getAttribute('data-tooth'));
            const conditionContainer = tooth.parentElement.querySelector('.tooth-condition');
            
            delete dentalChartState[toothNumber];
            tooth.className = tooth.className.replace(/bg-\w+-\d+/g, '') + ' bg-white';
            tooth.style.borderColor = '';
            conditionContainer.textContent = '';
            conditionContainer.className = 'tooth-condition text-xs mt-1 text-center min-h-[16px]';
        });
        
        updateDentalChartSummary();
        updateDentalChartHiddenField();
        
        // Show confirmation message
        alert('Dental chart has been reset!');
    }

    function toggleToothCondition(toothElement) {
        const toothNumber = parseInt(toothElement.getAttribute('data-tooth'));
        const conditionContainer = toothElement.parentElement.querySelector('.tooth-condition');
        
        // Cycle through conditions
        const conditions = [
            { name: 'healthy', color: 'bg-green-500', text: 'Healthy', label: 'H' },
            { name: 'caries', color: 'bg-red-500', text: 'Caries', label: 'C' },
            { name: 'filling', color: 'bg-blue-500', text: 'Filling', label: 'F' },
            { name: 'extraction', color: 'bg-yellow-500', text: 'Extraction', label: 'E' },
            { name: 'crown', color: 'bg-purple-500', text: 'Crown/Bridge', label: 'CB' },
            { name: 'none', color: 'bg-gray-200', text: 'None', label: '' }
        ];

        const currentCondition = dentalChartState[toothNumber]?.condition || 'none';
        const currentIndex = conditions.findIndex(cond => cond.name === currentCondition);
        const nextIndex = (currentIndex + 1) % conditions.length;
        const nextCondition = conditions[nextIndex];

        if (nextCondition.name === 'none') {
            // Remove condition
            delete dentalChartState[toothNumber];
            toothElement.className = toothElement.className.replace(/bg-\w+-\d+/g, '') + ' bg-white';
            toothElement.style.borderColor = '';
            conditionContainer.textContent = '';
            conditionContainer.className = 'tooth-condition text-xs mt-1 text-center min-h-[16px]';
        } else {
            // Set new condition
            dentalChartState[toothNumber] = {
                condition: nextCondition.name,
                label: nextCondition.label,
                text: nextCondition.text
            };
            
            // Update visual appearance
            toothElement.className = toothElement.className.replace(/bg-\w+-\d+/g, '') + ` ${nextCondition.color} text-white`;
            toothElement.style.borderColor = getComputedStyle(document.documentElement).getPropertyValue(`--${nextCondition.color.split('-')[1]}-500`) || '#6b7280';
            
            // Update condition label
            conditionContainer.textContent = nextCondition.label;
            conditionContainer.className = `tooth-condition text-xs mt-1 text-center min-h-[16px] font-semibold text-${nextCondition.color.split('-')[1]}-700`;
        }

        updateDentalChartSummary();
        updateDentalChartHiddenField();
    }

    function updateDentalChartSummary() {
        const summaryElement = document.getElementById('selected-teeth-summary');
        if (!summaryElement) {
            console.warn('Element with id "selected-teeth-summary" not found');
            return;
        }
        
        const selectedTeeth = Object.keys(dentalChartState);
        
        if (selectedTeeth.length === 0) {
            summaryElement.innerHTML = 'No teeth selected yet. Click on teeth above to mark conditions.';
            return;
        }

        let summaryHTML = '<div class="grid grid-cols-1 md:grid-cols-2 gap-2">';
        
        // Group by condition
        const conditions = {};
        selectedTeeth.forEach(toothNumber => {
            const condition = dentalChartState[toothNumber];
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
                    <span class="text-gray-700">${teeth.join(', ')}</span>
                </div>
            `;
        });

        summaryHTML += '</div>';
        summaryElement.innerHTML = summaryHTML;
    }

    function getConditionInfo(condition) {
        const conditions = {
            'healthy': { color: 'bg-green-500', text: 'Healthy' },
            'caries': { color: 'bg-red-500', text: 'Caries' },
            'filling': { color: 'bg-blue-500', text: 'Filling' },
            'extraction': { color: 'bg-yellow-500', text: 'Extraction Needed' },
            'crown': { color: 'bg-purple-500', text: 'Crown/Bridge' }
        };
        return conditions[condition] || { color: 'bg-gray-500', text: 'Unknown' };
    }

    function updateDentalChartHiddenField() {
        const hiddenField = document.getElementById('dental_chart_data');
        hiddenField.value = JSON.stringify(dentalChartState);
    }

    function prePopulateForm() {
        const form = document.querySelector('#formModal form');
        if (!form) return;

        console.log('Pre-populating form with user data:', userData);

        // ENHANCED FIELD MAPPING FOR MIDDLE NAME
        const fieldMappings = {
            // Student Information
            'student_id': ['student_id', 'student_number', 'patient_id', 'username'],
            
            // Personal Information - EXPANDED MIDDLE NAME VARIATIONS
            'first_name': ['first_name', 'fname', 'firstname', 'given_name', 'first_name'],
            'middle_name': [
                'middle_name', 'mname', 'middlename', 'middleName', 
                'middle_initial', 'mi', 'middle', 'midname',
                'middle_name', 'm_name', 'middleName'
            ],
            'last_name': ['last_name', 'lname', 'lastname', 'surname', 'family_name'],
            'date_of_birth': ['date_of_birth', 'birthdate', 'dob', 'birth_date'],
            'sex': ['sex', 'gender', 'sex_gender'],
            
            // Academic Information
            'program': ['program', 'course', 'department', 'program_course'],
            'year_level': ['year_level', 'year', 'level', 'academic_year'],
            
            // Contact Information
            'email': ['email', 'email_address']
        };

        // Pre-populate using enhanced field mappings
        Object.keys(fieldMappings).forEach(userDataKey => {
            if (userData[userDataKey] && userData[userDataKey].toString().trim() !== '') {
                fieldMappings[userDataKey].forEach(fieldName => {
                    const input = form.querySelector(`[name="${fieldName}"]`);
                    if (input) {
                        console.log(`Found field: ${fieldName} for key: ${userDataKey}, current value: "${input.value}", will set to: "${userData[userDataKey]}"`);
                        
                        if (input.type === 'radio') {
                            // Handle radio buttons
                            const radioToCheck = form.querySelector(`[name="${fieldName}"][value="${userData[userDataKey]}"]`);
                            if (radioToCheck) {
                                radioToCheck.checked = true;
                                console.log(`Set radio button: ${fieldName} to value: ${userData[userDataKey]}`);
                            }
                        } else if (input.type === 'select-one') {
                            // Handle select dropdowns
                            const optionToSelect = form.querySelector(`[name="${fieldName}"] option[value="${userData[userDataKey]}"]`);
                            if (optionToSelect) {
                                optionToSelect.selected = true;
                                console.log(`Set select: ${fieldName} to value: ${userData[userDataKey]}`);
                            }
                        } else if (!input.value || input.value.trim() === '') {
                            // Handle text inputs, textareas, etc. - only populate if empty
                            input.value = userData[userDataKey];
                            console.log(`Set text field: ${fieldName} to value: "${userData[userDataKey]}"`);
                            
                            // Make student ID field readonly
                            if (userDataKey === 'student_id' || fieldName === 'student_id' || fieldName === 'student_number') {
                                input.readOnly = true;
                                input.classList.add('bg-gray-100', 'cursor-not-allowed');
                                console.log(`Made field readonly: ${fieldName}`);
                            }
                        }
                    }
                });
            } else {
                console.log(`Skipping ${userDataKey} - value is empty or null: "${userData[userDataKey]}"`);
            }
        });

        // Also try direct field name matches as fallback
        Object.keys(userData).forEach(key => {
            if (userData[key] && userData[key].toString().trim() !== '') {
                const input = form.querySelector(`[name="${key}"]`);
                if (input && (!input.value || input.value.trim() === '')) {
                    console.log(`Direct match - Setting ${key} to: "${userData[key]}"`);
                    input.value = userData[key];
                    
                    if (key === 'student_id') {
                        input.readOnly = true;
                        input.classList.add('bg-gray-100', 'cursor-not-allowed');
                    }
                }
            }
        });

        // SPECIAL HANDLING FOR MIDDLE NAME - Try to find any middle name field
        if (userData.middle_name && userData.middle_name.toString().trim() !== '') {
            console.log('Special handling for middle name:', userData.middle_name);
            
            // Try all possible middle name field variations
            const middleNameFields = [
                'middle_name', 'mname', 'middlename', 'middleName', 
                'middle_initial', 'mi', 'middle', 'midname',
                'm_name', 'middleName', 'middle_name'
            ];
            
            let middleNamePopulated = false;
            middleNameFields.forEach(fieldName => {
                const input = form.querySelector(`[name="${fieldName}"]`);
                if (input && (!input.value || input.value.trim() === '')) {
                    input.value = userData.middle_name;
                    console.log(`SPECIAL: Set middle name field "${fieldName}" to: "${userData.middle_name}"`);
                    middleNamePopulated = true;
                }
            });
            
            if (!middleNamePopulated) {
                console.log('No empty middle name field found to populate');
            }
        }

        // Log all form fields for debugging
        const allInputs = form.querySelectorAll('input, select, textarea');
        console.log('All form fields:');
        allInputs.forEach(input => {
            console.log(`Field: ${input.name}, Type: ${input.type}, Value: "${input.value}"`);
        });
    }

    function submitForm(event, formType) {
        event.preventDefault();

        const form = event.target;
        const formData = new FormData(form);

        // Show loading state
        const submitBtn = form.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Submitting...';
        submitBtn.disabled = true;

        fetch(`modules/user_${formType}_form.php`, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.text())
        .then(result => {
            // Check if submission was successful
            if (result.includes('successfully submitted') || result.includes('Form successfully submitted')) {
                // Show success message
                alert('Form submitted successfully! Please wait for admin verification.');

                // Close modal
                closeFormModal();

                // Refresh the page to update recent submissions
                location.reload();
            } else {
                // Show error message
                alert('Error submitting form. Please try again.');
            }
        })
        .catch(error => {
            console.error('Error submitting form:', error);
            alert('Error submitting form. Please try again.');
        })
        .finally(() => {
            // Restore button state
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        });
    }

    // Close modal when clicking outside
    document.getElementById('formModal').addEventListener('click', function(e) {
        if (e.target.id === 'formModal') {
            closeFormModal();
        }
    });
    </script>
</body>
</html> 