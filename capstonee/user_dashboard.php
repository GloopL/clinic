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

// Get recent form submissions for the dashboard
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
            box-shadow: 0 0 0 2px var(--maroon-primary);
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

        /* Tab Navigation Styles */
        .tab-nav {
            position: fixed;
            left: 0;
            top: 0;
            bottom: 0;
            width: 280px;
            background: linear-gradient(180deg, #1f2937 0%, #111827 100%);
            overflow-y: auto;
            z-index: 40;
            box-shadow: 4px 0 12px rgba(0, 0, 0, 0.1);
        }
        
        .tab-content {
            margin-left: 280px;
            min-height: 100vh;
            background: #f9fafb;
            display: flex;
            flex-direction: column;
        }
        
        .main-content {
            flex: 1;
        }
        
        .tab-link {
            display: flex;
            align-items: center;
            padding: 14px 20px;
            color: #9ca3af;
            text-decoration: none;
            transition: all 0.2s ease;
            border-left: 4px solid transparent;
        }
        
        .tab-link:hover {
            background: rgba(255, 255, 255, 0.05);
            color: #ffffff;
            border-left-color: var(--maroon-primary);
        }
        
        .tab-link.active {
            background: rgba(128, 0, 0, 0.1);
            color: #ffffff;
            border-left-color: var(--maroon-primary);
        }
        
        .tab-link i {
            width: 24px;
            margin-right: 12px;
            font-size: 1.2rem;
        }

        .mobile-menu-btn {
            display: none;
        }

        @media (max-width: 1024px) {
            .tab-nav {
                transform: translateX(-100%);
                transition: transform 0.3s ease;
                width: 260px;
            }
            
            .tab-nav.active {
                transform: translateX(0);
            }
            
            .tab-content {
                margin-left: 0;
            }
            
            .mobile-menu-btn {
                display: flex;
            }
            
            .mobile-overlay {
                display: none;
                position: fixed;
                inset: 0;
                background: rgba(0, 0, 0, 0.5);
                z-index: 30;
            }
            
            .mobile-overlay.active {
                display: block;
            }
        }

        /* Footer fix - always at bottom */
        .page-footer {
            margin-top: auto;
            width: 100%;
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
    </style>
</head>
<body class="bg-maroon-light">

    <!-- Mobile Menu Overlay -->
    <div id="mobileOverlay" class="mobile-overlay" onclick="closeMobileMenu()"></div>

    <!-- Tab Navigation -->
    <nav class="tab-nav" id="tabNav">
        <!-- User Profile Header -->
        <div class="p-6 border-b border-gray-800">
            <div class="flex items-center gap-4 mb-4">
                <div class="w-14 h-14 maroon-gradient rounded-full flex items-center justify-center text-white text-xl font-bold shadow-lg">
                    <?php echo generateDefaultAvatar($display_name); ?>
                </div>
                <div>
                    <h3 class="font-bold text-white text-lg"><?php echo htmlspecialchars($display_name); ?></h3>
                    <p class="text-gray-400 text-sm">Student</p>
                </div>
            </div>
            <div class="space-y-2">
                <div class="flex justify-between text-gray-300 text-sm">
                    <span>SR Code:</span>
                    <span class="font-medium"><?php echo htmlspecialchars($_SESSION['username']); ?></span>
                </div>
                <div class="flex justify-between text-gray-300 text-sm">
                    <span>Role:</span>
                    <span class="font-medium">Student</span>
                </div>
            </div>
        </div>

       <!-- Navigation Tabs -->
<div class="py-4">
    <a href="#dashboard" class="tab-link" data-tab="dashboard"> 
        <i class="bi bi-speedometer2"></i>
        <span>Dashboard</span>
    </a>
    <a href="#clinic-forms" class="tab-link" data-tab="clinic-forms">
        <i class="bi bi-clipboard2-pulse-fill"></i>
        <span>Clinic Forms</span>
    </a>
    <a href="#medical-diagnoses" class="tab-link" data-tab="medical-diagnoses">
        <i class="bi bi-clipboard2-heart-fill"></i>
        <span>Medical Diagnoses</span>
        <?php if ($diagnosis_count > 0): ?>
            <span class="ml-auto bg-red-500 text-white text-xs px-2 py-1 rounded-full"><?php echo $diagnosis_count; ?></span>
        <?php endif; ?>
    </a>
    <a href="#update-profile" class="tab-link" data-tab="update-profile">
        <i class="bi bi-person-circle"></i>
        <span>Update Profile</span>
    </a>
</div>

        <!-- Recent Activities Sidebar Section -->
        <div class="px-6 py-4 border-t border-gray-800">
            <h4 class="text-gray-400 text-sm font-semibold mb-3 uppercase tracking-wider">Recent Activities</h4>
            <div class="space-y-3">
                <?php if($recent_activities && $recent_activities->num_rows > 0): ?>
                    <?php while($activity = $recent_activities->fetch_assoc()): ?>
                        <div class="flex items-start gap-3">
                            <div class="<?php echo getActivityBg($activity['activity_type']); ?> p-2 rounded-full mt-1">
                                <i class="bi <?php echo getActivityIcon($activity['activity_type']); ?> text-sm"></i>
                            </div>
                            <div class="flex-1">
                                <p class="text-gray-300 text-sm font-medium"><?php echo htmlspecialchars($activity['activity_description']); ?></p>
                                <p class="text-gray-500 text-xs"><?php echo date('M j, g:i A', strtotime($activity['created_at'])); ?></p>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <p class="text-gray-500 text-sm">No recent activities</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Logout Button -->
        <div class="p-6 border-t border-gray-800 mt-auto">
            <a href="#" onclick="openLogoutModal(event)" class="flex items-center justify-center maroon-gradient-button text-white py-3 rounded-lg font-medium hover:shadow-lg transition-all">
                <i class="bi bi-box-arrow-right mr-2"></i> Logout
            </a>
        </div>
    </nav>

    <!-- Main Content Area -->
    <div class="tab-content">
        <!-- Top Header -->
        <header class="bg-white shadow-sm sticky top-0 z-10">
            <div class="flex items-center justify-between px-6 py-4">
                <!-- Mobile Menu Button -->
                <button id="mobileMenuBtn" class="mobile-menu-btn text-gray-700 hover:text-maroon">
                    <i class="bi bi-list text-2xl"></i>
                </button>
                
                <!-- System Logo and Title -->
                <div class="flex items-center gap-3">
                    <img src="assets/css/images/logo-bsu.png" alt="BSU Logo" class="w-10 h-10 rounded-full object-cover border-2 border-maroon bg-white">
                    <h1 class="text-lg font-bold text-gray-800 hidden md:block">BSU Clinic Record Management System</h1>
                </div>
                
                <!-- Current Date/Time -->
                <div class="text-right">
                    <p class="text-sm text-gray-600">Today is</p>
                    <p class="font-semibold text-gray-800"><?php echo date('l, F j, Y'); ?></p>
                    <p class="text-sm text-gray-600" id="currentTime"><?php echo date('g:i A'); ?></p>
                </div>
            </div>
        </header>

        <!-- Tab Contents -->
        <div class="main-content p-6">
            <!-- Dashboard Tab (Default Active) -->
            <div id="dashboard-content" class="tab-panel">
                <!-- Welcome Card -->
                <div class="bg-white rounded-xl shadow-md p-6 mb-6 border-l-4 border-maroon">
                    <div class="flex items-center justify-between">
                        <div>
                            <h2 class="text-2xl font-bold text-gray-800 mb-2">Welcome, <?php echo htmlspecialchars($display_name); ?>!</h2>
                            <p class="text-gray-600">Here's your clinic dashboard. You can submit forms, view diagnoses, and manage your profile.</p>
                        </div>
                        <div class="hidden md:block">
                            <div class="w-20 h-20 maroon-gradient rounded-full flex items-center justify-center text-white text-2xl font-bold shadow-lg">
                                <?php echo generateDefaultAvatar($display_name); ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Stats Cards -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                    <!-- Form Submissions Card -->
                    <div class="stats-card-1 text-white rounded-xl p-6 shadow-lg">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm opacity-90">Form Submissions</p>
                                <p class="text-3xl font-bold mt-2"><?php echo $user_submissions_result ? $user_submissions_result->num_rows : 0; ?></p>
                            </div>
                            <i class="bi bi-clipboard2-check text-4xl opacity-80"></i>
                        </div>
                        <div class="mt-4">
                            <a href="#clinic-forms" onclick="switchTab('clinic-forms')" class="text-white text-sm font-medium hover:underline flex items-center">
                                View Forms <i class="bi bi-arrow-right ml-1"></i>
                            </a>
                        </div>
                    </div>

                    <!-- Medical Diagnoses Card -->
                    <div class="stats-card-2 text-white rounded-xl p-6 shadow-lg">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm opacity-90">Medical Diagnoses</p>
                                <p class="text-3xl font-bold mt-2"><?php echo $diagnosis_count; ?></p>
                            </div>
                            <i class="bi bi-clipboard2-heart text-4xl opacity-80"></i>
                        </div>
                        <div class="mt-4">
                            <a href="#medical-diagnoses" onclick="switchTab('medical-diagnoses')" class="text-white text-sm font-medium hover:underline flex items-center">
                                View Diagnoses <i class="bi bi-arrow-right ml-1"></i>
                            </a>
                        </div>
                    </div>

                    <!-- Profile Status Card -->
                    <div class="stats-card-3 text-white rounded-xl p-6 shadow-lg">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm opacity-90">Profile Status</p>
                                <p class="text-3xl font-bold mt-2">Complete</p>
                            </div>
                            <i class="bi bi-person-check text-4xl opacity-80"></i>
                        </div>
                        <div class="mt-4">
                            <a href="#update-profile" onclick="switchTab('update-profile')" class="text-white text-sm font-medium hover:underline flex items-center">
                                Update Profile <i class="bi bi-arrow-right ml-1"></i>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Recent Form Submissions -->
                <div class="bg-white rounded-xl shadow-md p-6 mb-8">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-xl font-semibold text-gray-800 flex items-center gap-2">
                            <i class="bi bi-clock-history text-maroon"></i> Recent Form Submissions
                        </h3>
                        <a href="#clinic-forms" onclick="switchTab('clinic-forms')" class="text-maroon hover:text-maroon-dark text-sm font-medium flex items-center">
                            View All <i class="bi bi-arrow-right ml-1"></i>
                        </a>
                    </div>
                    
                    <?php if ($user_submissions_result && $user_submissions_result->num_rows > 0): ?>
                        <div class="space-y-4">
                            <?php while ($submission = $user_submissions_result->fetch_assoc()): ?>
                                <div class="bg-gray-50 hover:bg-gray-100 p-4 rounded-lg border border-gray-200 transition-all duration-200">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center gap-4">
                                            <div class="<?php echo getActivityBg('form_submission'); ?> p-3 rounded-full">
                                                <i class="bi <?php echo getActivityIcon('form_submission'); ?>"></i>
                                            </div>
                                            <div>
                                                <p class="font-medium text-gray-800"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $submission['record_type']))); ?></p>
                                                <p class="text-sm text-gray-600">Submitted: <?php echo date('M d, Y', strtotime($submission['created_at'])); ?></p>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <span class="px-3 py-1 rounded-full text-xs font-semibold <?php
                                                echo $submission['verification_status'] === 'verified' ? 'maroon-badge-verified' :
                                                    ($submission['verification_status'] === 'rejected' ? 'maroon-badge-rejected' :
                                                    'maroon-badge-pending');
                                            ?>">
                                                <?php echo strtoupper($submission['verification_status']); ?>
                                            </span>
                                            <p class="text-xs text-gray-500 mt-1">
                                                Exam Date: <?php echo $submission['examination_date'] ? date('M d, Y', strtotime($submission['examination_date'])) : 'N/A'; ?>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-8">
                            <div class="bg-gray-100 w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-3">
                                <i class="bi bi-clipboard2-pulse text-gray-400 text-2xl"></i>
                            </div>
                            <p class="text-gray-500 mb-2">No form submissions yet</p>
                            <p class="text-gray-400 text-sm">Your submitted forms will appear here</p>
                            <a href="#clinic-forms" onclick="switchTab('clinic-forms')" class="mt-4 inline-block maroon-gradient-button text-white px-4 py-2 rounded-lg text-sm font-medium">
                                Submit Your First Form
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Clinic Forms Tab -->
            <div id="clinic-forms-content" class="tab-panel hidden">
                <div class="bg-white rounded-xl shadow-md p-6 mb-6">
                    <h2 class="text-2xl font-bold text-gray-800 mb-2 flex items-center gap-2">
                        <i class="bi bi-clipboard2-pulse-fill text-maroon"></i> Clinic Forms
                    </h2>
                    <p class="text-gray-600 mb-6">Fill out your medical forms for clinic services</p>

                    <!-- Forms Grid -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                        <!-- Medical History Form -->
                        <button onclick="openFormModal('history')" class="block form-card-history text-white rounded-xl p-6 shadow-lg hover:shadow-xl transition duration-300 transform hover:scale-[1.02] border-b-4 border-yellow-500 relative">
                            <div class="flex items-center justify-center mb-4">
                                <i class="bi bi-clipboard2-pulse-fill text-4xl"></i>
                            </div>
                            <h3 class="text-lg font-semibold tracking-tight text-center mb-2">Medical History Form for Athlete</h3>
                            <p class="text-sm opacity-90 text-center">Update your medical history</p>
                            <?php if (isset($submitted_forms['history_form'])): ?>
                                <div class="absolute top-4 right-4 bg-green-500 text-white px-3 py-1 rounded-full text-xs font-semibold">
                                    SUBMITTED
                                </div>
                            <?php endif; ?>
                        </button>

                        <!-- Dental Examination Form -->
                        <button onclick="openFormModal('dental')" class="block form-card-dental text-white rounded-xl p-6 shadow-lg hover:shadow-xl transition duration-300 transform hover:scale-[1.02] border-b-4 border-yellow-500 relative">
                            <div class="flex items-center justify-center mb-4">
                                <i class="fas fa-tooth text-4xl"></i>
                            </div>
                            <h3 class="text-lg font-semibold tracking-tight text-center mb-2">Dental Examination</h3>
                            <p class="text-sm opacity-90 text-center">Complete dental checkup form</p>
                            <?php if (isset($submitted_forms['dental_exam'])): ?>
                                <div class="absolute top-4 right-4 bg-green-500 text-white px-3 py-1 rounded-full text-xs font-semibold">
                                    SUBMITTED
                                </div>
                            <?php endif; ?>
                        </button>

                        <!-- Medical Examination Form -->
                        <button onclick="openFormModal('medical')" class="block form-card-medical text-white rounded-xl p-6 shadow-lg hover:shadow-xl transition duration-300 transform hover:scale-[1.02] border-b-4 border-yellow-500 relative">
                            <div class="flex items-center justify-center mb-4">
                                <i class="bi bi-heart-pulse text-4xl"></i>
                            </div>
                            <h3 class="text-lg font-semibold tracking-tight text-center mb-2">Pre-Employment/OJT Medical Examination</h3>
                            <p class="text-sm opacity-90 text-center">Complete medical checkup form</p>
                            <?php if (isset($submitted_forms['medical_exam'])): ?>
                                <div class="absolute top-4 right-4 bg-green-500 text-white px-3 py-1 rounded-full text-xs font-semibold">
                                    SUBMITTED
                                </div>
                            <?php endif; ?>
                        </button>
                    </div>

                    <!-- Form Submission Guidelines -->
                    <div class="bg-maroon-light border border-maroon rounded-xl p-6">
                        <h3 class="text-lg font-semibold text-maroon mb-3 flex items-center gap-2">
                            <i class="bi bi-info-circle-fill"></i> Form Submission Guidelines
                        </h3>
                        <ul class="space-y-2 text-gray-700">
                            <li class="flex items-start gap-2">
                                <i class="bi bi-check-circle-fill text-green-500 mt-1"></i>
                                <span>Fill out all required fields marked with asterisk (*)</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <i class="bi bi-check-circle-fill text-green-500 mt-1"></i>
                                <span>Review your information before submission</span>
                            </li>
                            
                            <li class="flex items-start gap-2">
                                <i class="bi bi-check-circle-fill text-green-500 mt-1"></i>
                                <span>You can track submission status in the Dashboard</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Medical Diagnoses Tab -->
            <div id="medical-diagnoses-content" class="tab-panel hidden">
                <div class="bg-white rounded-xl shadow-md p-6 mb-6">
                    <h2 class="text-2xl font-bold text-gray-800 mb-2 flex items-center gap-2">
                        <i class="bi bi-clipboard2-heart-fill text-maroon"></i> Medical Diagnoses
                    </h2>
                    <p class="text-gray-600 mb-6">View your medical diagnoses and findings from healthcare providers</p>

                    <?php if ($diagnosis_count > 0): ?>
                        <!-- Diagnoses List -->
                        <div class="space-y-4">
                            <!-- This would be populated with actual diagnoses from the database -->
                            <div class="bg-gray-50 hover:bg-gray-100 p-5 rounded-lg border border-gray-200 transition-all duration-200">
                                <div class="flex items-start justify-between">
                                    <div class="flex items-start gap-4">
                                        <div class="bg-red-100 p-3 rounded-full">
                                            <i class="bi bi-heart-pulse-fill text-red-600"></i>
                                        </div>
                                        <div>
                                            <h4 class="font-semibold text-gray-800 mb-1">General Checkup Findings</h4>
                                            <p class="text-gray-600 text-sm mb-2">From: Dr. Maria Santos - General Physician</p>
                                            <p class="text-gray-700">Patient is in good health. Mild vitamin D deficiency noted. Recommended dietary supplements.</p>
                                            <p class="text-xs text-gray-500 mt-2">Diagnosed: October 15, 2024</p>
                                        </div>
                                    </div>
                                    <span class="bg-green-100 text-green-800 text-xs px-3 py-1 rounded-full font-semibold">
                                        COMPLETED
                                    </span>
                                </div>
                            </div>

                            <div class="bg-gray-50 hover:bg-gray-100 p-5 rounded-lg border border-gray-200 transition-all duration-200">
                                <div class="flex items-start justify-between">
                                    <div class="flex items-start gap-4">
                                        <div class="bg-blue-100 p-3 rounded-full">
                                            <i class="bi bi-tooth text-blue-600"></i>
                                        </div>
                                        <div>
                                            <h4 class="font-semibold text-gray-800 mb-1">Dental Examination Results</h4>
                                            <p class="text-gray-600 text-sm mb-2">From: Dr. Juan Dela Cruz - Dentist</p>
                                            <p class="text-gray-700">Two cavities detected (teeth #3 and #14). Recommended dental filling appointments.</p>
                                            <p class="text-xs text-gray-500 mt-2">Diagnosed: September 28, 2024</p>
                                        </div>
                                    </div>
                                    <span class="bg-yellow-100 text-yellow-800 text-xs px-3 py-1 rounded-full font-semibold">
                                        PENDING TREATMENT
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- View All Button -->
                        <div class="mt-6 text-center">
                            <a href="my_diagnoses.php" class="inline-flex items-center gap-2 maroon-gradient-button text-white px-6 py-3 rounded-lg font-semibold hover:shadow-lg transition-all">
                                <i class="bi bi-arrow-right-circle"></i> View All Diagnoses
                            </a>
                        </div>
                    <?php else: ?>
                        <!-- No Diagnoses State -->
                        <div class="text-center py-12">
                            <div class="bg-gray-100 w-20 h-20 rounded-full flex items-center justify-center mx-auto mb-4">
                                <i class="bi bi-clipboard2-heart text-gray-400 text-3xl"></i>
                            </div>
                            <h3 class="text-xl font-semibold text-gray-700 mb-2">No diagnoses found</h3>
                            <p class="text-gray-500 mb-6">You haven't received any medical diagnoses yet.</p>
                            <div class="flex flex-col sm:flex-row gap-3 justify-center">
                                <a href="#clinic-forms" onclick="switchTab('clinic-forms')" class="maroon-gradient-button text-white px-6 py-3 rounded-lg font-semibold hover:shadow-lg transition-all">
                                    <i class="bi bi-clipboard2-plus"></i> Submit Medical Forms
                                </a>
                                <a href="#" class="bg-gray-200 text-gray-800 px-6 py-3 rounded-lg font-semibold hover:bg-gray-300 transition-all">
                                    <i class="bi bi-question-circle"></i> Learn More
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Update Profile Tab -->
            <div id="update-profile-content" class="tab-panel hidden">
                <div class="bg-white rounded-xl shadow-md p-6 mb-6">
                    <h2 class="text-2xl font-bold text-gray-800 mb-2 flex items-center gap-2">
                        <i class="bi bi-person-circle text-purple-600"></i> Update Profile
                    </h2>

                        <!-- Success Message Container (initially hidden) -->
                    <div id="profileSuccessMessage" class="hidden bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6">
                        <div class="flex items-center">
                            <i class="bi bi-check-circle mr-2"></i>
                            <span id="successMessageText"></span>
                        </div>
                    </div>

                    <p class="text-gray-600 mb-6">Manage your personal information and account settings</p>

                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                        <!-- Profile Summary -->
                        <div class="lg:col-span-2">
                            <div class="bg-gray-50 rounded-xl p-6 mb-6">
                                <h3 class="text-lg font-semibold text-gray-800 mb-4">Personal Information</h3>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Full Name</label>
                                        <p class="text-gray-900 font-medium"><?php echo htmlspecialchars($display_name); ?></p>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">SR Code</label>
                                        <p class="text-gray-900 font-medium"><?php echo htmlspecialchars($_SESSION['username']); ?></p>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Email Address</label>
                                        <p class="text-gray-900 font-medium"><?php echo htmlspecialchars($user_profile['email'] ?? 'Not set'); ?></p>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Member Since</label>
                                        <p class="text-gray-900 font-medium"><?php echo date('M j, Y', strtotime($user_profile['created_at'])); ?></p>
                                    </div>
                                </div>

                                <!-- Patient Information -->
                                <?php if ($patient_info): ?>
                                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Patient Information</h3>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-1">Date of Birth</label>
                                            <p class="text-gray-900"><?php echo htmlspecialchars($patient_info['date_of_birth'] ?? 'Not set'); ?></p>
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-1">Gender</label>
                                            <p class="text-gray-900"><?php echo htmlspecialchars($patient_info['sex'] ?? 'Not set'); ?></p>
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-1">Program/Course</label>
                                            <p class="text-gray-900"><?php echo htmlspecialchars($patient_info['program'] ?? 'Not set'); ?></p>
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-1">Year Level</label>
                                            <p class="text-gray-900"><?php echo htmlspecialchars($patient_info['year_level'] ?? 'Not set'); ?></p>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Action Buttons -->
                            <div class="flex flex-col sm:flex-row gap-4">
                                <button onclick="openProfileModal()" class="flex-1 maroon-gradient-button text-white py-3 rounded-lg font-semibold hover:shadow-lg transition-all text-center">
                                        <i class="bi bi-pencil-square mr-2"></i> Edit Profile Information
                                    </button>
                                <button onclick="openPasswordModal()" class="flex-1 bg-gray-200 text-gray-800 py-3 rounded-lg font-semibold hover:bg-gray-300 transition-all text-center">
    <i class="bi bi-key-fill mr-2"></i> Change Password
</button>
                            </div>
                        </div>

                        <!-- Profile Avatar -->
                        <div>
                            <div class="bg-gray-50 rounded-xl p-6 text-center">
                                <div class="w-32 h-32 maroon-gradient rounded-full flex items-center justify-center text-white text-3xl font-bold shadow-lg mx-auto mb-4">
                                    <?php echo generateDefaultAvatar($display_name); ?>
                                </div>
                                <h3 class="font-bold text-lg text-gray-800"><?php echo htmlspecialchars($display_name); ?></h3>
                                <p class="text-gray-600 text-sm mb-4">Student</p>
                                
                                <div class="space-y-3 text-left">
                                    <div class="flex justify-between">
                                        <span class="text-gray-600">Account Status:</span>
                                        <span class="font-medium text-green-600">Active</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600">Last Login:</span>
                                        <span class="font-medium"><?php echo date('M j, g:i A'); ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600">Forms Submitted:</span>
                                        <span class="font-medium"><?php echo $user_submissions_result ? $user_submissions_result->num_rows : 0; ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <footer class="page-footer bg-gray-800 text-white py-6 mt-8">
            <div class="max-w-7xl mx-auto px-6">
                <div class="flex flex-col md:flex-row justify-between items-center">
                    <div class="mb-4 md:mb-0">
                        <small>&copy; <?php echo date('Y'); ?> Batangas State University - Clinic Record Management System</small>
                    </div>
                    <div class="text-gray-400 text-sm">
                        <span>Logged in as: <?php echo htmlspecialchars($_SESSION['username']); ?> | </span>
                        <span>Role: Student | </span>
                        <span>Last Access: <?php echo date('g:i A'); ?></span>
                    </div>
                </div>
            </div>
        </footer>
    </div>

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

    <!-- Logout Confirmation Modal -->
    <div id="logoutModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
                <div class="p-6">
                    <div class="flex items-center justify-center mb-4">
                        <div class="bg-red-100 p-3 rounded-full">
                            <i class="bi bi-box-arrow-right text-red-600 text-2xl"></i>
                        </div>
                    </div>
                    <h3 class="text-xl font-semibold text-gray-800 text-center mb-2">Confirm Logout</h3>
                    <p class="text-gray-600 text-center mb-6">Are you sure you want to logout from your account?</p>
                    <div class="flex gap-3">
                        <button onclick="closeLogoutModal()" class="flex-1 bg-gray-200 text-gray-800 py-3 rounded-lg font-medium hover:bg-gray-300 transition">
                            Cancel
                        </button>
                        <a href="logout.php" class="flex-1 maroon-gradient-button text-white py-3 rounded-lg font-medium text-center hover:shadow-lg transition">
                            Yes, Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Profile Update Modal -->
<div id="profileModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full max-h-[90vh] overflow-hidden">
            <div class="flex justify-between items-center p-6 border-b">
                <h2 id="profileModalTitle" class="text-2xl font-bold text-gray-800">Update Profile Information</h2>
                <button onclick="closeProfileModal()" class="text-gray-500 hover:text-gray-700">
                    <i class="bi bi-x-lg text-2xl"></i>
                </button>
            </div>
            <div id="profileModalContent" class="p-6 overflow-y-auto max-h-[calc(90vh-120px)]">
                <!-- Profile form will be loaded here -->
            </div>
        </div>
    </div>
</div>

<!-- Password Change Modal -->
<div id="passwordModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-lg shadow-xl max-w-md w-full max-h-[90vh] overflow-hidden">
            <div class="flex justify-between items-center p-6 border-b">
                <h2 id="passwordModalTitle" class="text-2xl font-bold text-gray-800">Change Password</h2>
                <button onclick="closePasswordModal()" class="text-gray-500 hover:text-gray-700">
                    <i class="bi bi-x-lg text-2xl"></i>
                </button>
            </div>
            <div id="passwordModalContent" class="p-6 overflow-y-auto max-h-[calc(90vh-120px)]">
                <!-- Password form will be loaded here -->
            </div>
        </div>
    </div>
</div>

    <!-- JavaScript -->
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

    // Tab switching functionality
    function switchTab(tabId) {
        // Update tab links
        document.querySelectorAll('.tab-link').forEach(link => {
            link.classList.remove('active');
            if (link.getAttribute('href') === '#' + tabId) {
                link.classList.add('active');
            }
        });

        // Update tab content
        document.querySelectorAll('.tab-panel').forEach(panel => {
            panel.classList.add('hidden');
            panel.classList.remove('active');
        });

        const activePanel = document.getElementById(tabId + '-content');
        if (activePanel) {
            activePanel.classList.remove('hidden');
            activePanel.classList.add('active');
        }

        // Close mobile menu on mobile devices
        if (window.innerWidth <= 1024) {
            closeMobileMenu();
        }
    }

    // Mobile menu functionality
    function toggleMobileMenu() {
        const nav = document.getElementById('tabNav');
        const overlay = document.getElementById('mobileOverlay');
        nav.classList.toggle('active');
        overlay.classList.toggle('active');
    }

    function closeMobileMenu() {
        const nav = document.getElementById('tabNav');
        const overlay = document.getElementById('mobileOverlay');
        nav.classList.remove('active');
        overlay.classList.remove('active');
    }

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


// Password toggle initialization function
function initializePasswordToggles() {
    console.log('Initializing password toggles...');
    
    // Find all password toggle buttons in the modal
    const toggleButtons = document.querySelectorAll('#passwordModalContent button[data-target]');
    console.log('Found toggle buttons:', toggleButtons.length);
    
    toggleButtons.forEach(button => {
        // Remove any existing event listeners
        const newButton = button.cloneNode(true);
        button.parentNode.replaceChild(newButton, button);
        
        // Add click event to the new button
        newButton.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const targetId = this.getAttribute('data-target');
            console.log('Toggle clicked for:', targetId);
            
            const input = document.getElementById(targetId);
            const icon = this.querySelector('i');
            
            if (input && icon) {
                if (input.type === 'password') {
                    input.type = 'text';
                    icon.className = 'bi bi-eye-slash';
                    this.setAttribute('title', 'Hide password');
                } else {
                    input.type = 'password';
                    icon.className = 'bi bi-eye';
                    this.setAttribute('title', 'Show password');
                }
            }
        });
        
        // Ensure button has proper type
        newButton.setAttribute('type', 'button');
        
        // Add hover effect classes if not present
        if (!newButton.className.includes('hover:text-gray-700')) {
            newButton.className += ' hover:text-gray-700';
        }
    });
}

    // Logout modal functions
    function openLogoutModal(event) {
        event.preventDefault();
        document.getElementById('logoutModal').classList.remove('hidden');
    }

    function closeLogoutModal() {
        document.getElementById('logoutModal').classList.add('hidden');
    }

    // Initialize
    updateTime();
    setInterval(updateTime, 1000);

 // Tab click handlers
document.querySelectorAll('.tab-link').forEach(link => {
    link.addEventListener('click', function(e) {
        e.preventDefault();
        const tabId = this.getAttribute('data-tab');
        switchTab(tabId);
    });
});

    // Mobile menu button event
    document.getElementById('mobileMenuBtn').addEventListener('click', toggleMobileMenu);

    // Load saved tab immediately when DOM is ready
loadSelectedTab();

// Then set up the tab click handlers
document.querySelectorAll('.tab-link').forEach(link => {
    link.addEventListener('click', function(e) {
        e.preventDefault();
        const tabId = this.getAttribute('data-tab');
        switchTab(tabId);
    });
});

// Event delegation for dynamically loaded modal close buttons
document.addEventListener('click', function(e) {
    // Check if the clicked element is the close button in password modal
    if (e.target.closest('#passwordModalContent button[onclick*="closeModal"]') || 
        e.target.closest('#passwordModalContent button[onclick*="closePasswordModal"]')) {
        closePasswordModal();
    }
});

    // Form modal functions (keep existing form modal functions unchanged)
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
        modalContent.innerHTML = '<div class="text-center py-8"><div class="spinner-border text-maroon" role="status"><span class="sr-only">Loading...</span></div><p class="mt-2">Loading form...</p></div>';

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
        // Implementation remains the same as before
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
                        if (input.type === 'radio') {
                            // Handle radio buttons
                            const radioToCheck = form.querySelector(`[name="${fieldName}"][value="${userData[userDataKey]}"]`);
                            if (radioToCheck) {
                                radioToCheck.checked = true;
                            }
                        } else if (input.type === 'select-one') {
                            // Handle select dropdowns
                            const optionToSelect = form.querySelector(`[name="${fieldName}"] option[value="${userData[userDataKey]}"]`);
                            if (optionToSelect) {
                                optionToSelect.selected = true;
                            }
                        } else if (!input.value || input.value.trim() === '') {
                            // Handle text inputs, textareas, etc. - only populate if empty
                            input.value = userData[userDataKey];
                            
                            // Make student ID field readonly
                            if (userDataKey === 'student_id' || fieldName === 'student_id' || fieldName === 'student_number') {
                                input.readOnly = true;
                                input.classList.add('bg-gray-100', 'cursor-not-allowed');
                            }
                        }
                    }
                });
            }
        });

        // Also try direct field name matches as fallback
        Object.keys(userData).forEach(key => {
            if (userData[key] && userData[key].toString().trim() !== '') {
                const input = form.querySelector(`[name="${key}"]`);
                if (input && (!input.value || input.value.trim() === '')) {
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
            // Try all possible middle name field variations
            const middleNameFields = [
                'middle_name', 'mname', 'middlename', 'middleName', 
                'middle_initial', 'mi', 'middle', 'midname',
                'm_name', 'middleName', 'middle_name'
            ];
            
            middleNameFields.forEach(fieldName => {
                const input = form.querySelector(`[name="${fieldName}"]`);
                if (input && (!input.value || input.value.trim() === '')) {
                    input.value = userData.middle_name;
                }
            });
        }
    }

    function submitForm(event, formType) {
        event.preventDefault();

        const form = event.target;
        const formData = new FormData(form);

        // Check if this is a history form that's already been submitted
        if (formType === 'history' && <?php echo isset($submitted_forms['history_form']) ? 'true' : 'false'; ?>) {
            // Ask for confirmation since they already have a submission
            if (!confirm('You have already submitted a history form. Do you want to submit a new one? The previous submission will not be deleted, but clinic staff will see this new version.')) {
                return;
            }
        }

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
            if (result.includes('successfully submitted') || result.includes('Form successfully submitted') || result.includes('successfully')) {
                // Show success message
                alert('Form submitted successfully! Please wait for admin verification.');

                // Close modal
                closeFormModal();

                // Refresh the page to update recent submissions
                location.reload();
            } else {
                // Show error message
                alert('Error submitting form. Please try again. Server response: ' + result.substring(0, 200));
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

    // Close logout modal when clicking outside
    document.getElementById('logoutModal').addEventListener('click', function(e) {
        if (e.target.id === 'logoutModal') {
            closeLogoutModal();
        }
    });

    // Close profile modal when clicking outside
document.getElementById('profileModal').addEventListener('click', function(e) {
    if (e.target.id === 'profileModal') {
        closeProfileModal();
    }
});

// Close password modal when clicking outside
document.getElementById('passwordModal').addEventListener('click', function(e) {
    if (e.target.id === 'passwordModal') {
        closePasswordModal();
    }
});

// Close password modal when clicking outside
document.getElementById('passwordModal').addEventListener('click', function(e) {
    if (e.target.id === 'passwordModal') {
        closePasswordModal();
    }
});

    // Handle window resize
    window.addEventListener('resize', function() {
        if (window.innerWidth > 1024) {
            closeMobileMenu();
        }
    });

    // Listen for messages from password modal
window.addEventListener('message', function(event) {
    if (event.data.type === 'passwordChangeSuccess') {
        // Show success message (you can add this similar to profile update)
        alert(event.data.message);
        closePasswordModal();
    }
    
    if (event.data === 'closePasswordModal') {
        closePasswordModal();
    }
});

    // Listen for messages from password modal
window.addEventListener('message', function(event) {
    if (event.data.type === 'passwordChangeSuccess') {
        // Show success message (you can add this similar to profile update)
        alert(event.data.message);
        closePasswordModal();
    }
    
    if (event.data === 'closePasswordModal') {
        closePasswordModal();
    }
});

    // Profile modal functions
function openProfileModal() {
    const modal = document.getElementById('profileModal');
    const modalContent = document.getElementById('profileModalContent');

    // Show loading
    modalContent.innerHTML = '<div class="text-center py-8"><div class="spinner-border text-maroon" role="status"><span class="sr-only">Loading...</span></div><p class="mt-2">Loading profile form...</p></div>';

    // Load profile form content
    fetch('update_user_profile.php', {
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
            // Remove any session check and redirect scripts
            const scripts = form.querySelectorAll('script');
            scripts.forEach(script => {
                if (script.textContent.includes('header("Location:') || 
                    script.textContent.includes('window.location')) {
                    script.remove();
                }
            });

            // Update form to use AJAX submission
            const originalAction = form.getAttribute('action') || '';
            form.setAttribute('onsubmit', 'submitProfileForm(event)');
            
            // Keep original action for reference if needed
            if (originalAction) {
                form.setAttribute('data-original-action', originalAction);
            }

            modalContent.innerHTML = form.outerHTML;
            
            // Pre-populate the form with current user data
            setTimeout(() => {
                prePopulateProfileForm();
            }, 100);
        } else {
            modalContent.innerHTML = '<div class="text-center py-8 text-red-600">Error loading profile form</div>';
        }
    })
    .catch(error => {
        console.error('Error loading profile form:', error);
        modalContent.innerHTML = '<div class="text-center py-8 text-red-600">Error loading profile form</div>';
    });

    // Show modal
    modal.classList.remove('hidden');
}

function closeProfileModal() {
    const modal = document.getElementById('profileModal');
    modal.classList.add('hidden');
}

function submitProfileForm(event) {
    event.preventDefault();
    
    const form = event.target;
    const formData = new FormData(form);
    
    // Show loading state
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Updating...';
    submitBtn.disabled = true;
    
    // Use AJAX to submit the form
    fetch('update_user_profile.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Show success message on the update profile page
            showProfileSuccessMessage(data.message);
            
            // Close modal after 1.5 seconds
            setTimeout(() => {
                closeProfileModal();
            }, 0);
        } else {
            // Show error message in modal
            const errorDiv = document.createElement('div');
            errorDiv.className = 'bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4';
            errorDiv.innerHTML = `<div class="flex items-center"><i class="bi bi-exclamation-circle mr-2"></i>${data.message}</div>`;
            
            // Insert error message at the top of modal content
            const modalContent = document.getElementById('profileModalContent');
            modalContent.insertBefore(errorDiv, modalContent.firstChild);
            
            // Restore button state
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }
    })
    .catch(error => {
        console.error('Error updating profile:', error);
        
        // Show error message
        const errorDiv = document.createElement('div');
        errorDiv.className = 'bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4';
        errorDiv.innerHTML = '<div class="flex items-center"><i class="bi bi-exclamation-circle mr-2"></i>An error occurred. Please try again.</div>';
        
        const modalContent = document.getElementById('profileModalContent');
        modalContent.insertBefore(errorDiv, modalContent.firstChild);
        
        // Restore button state
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    });
}

// Function to show success message on update profile page
function showProfileSuccessMessage(message) {
    // Switch to update profile tab if not already there
    switchTab('update-profile');
    
    // Show success message
    const successDiv = document.getElementById('profileSuccessMessage');
    const messageText = document.getElementById('successMessageText');
    
    messageText.textContent = message;
    successDiv.classList.remove('hidden');
    
    // Auto-hide success message after 5 seconds
    setTimeout(() => {
        successDiv.classList.add('hidden');
    }, 5000);
}

// Function to pre-populate profile form
function prePopulateProfileForm() {
    const form = document.querySelector('#profileModal form');
    if (!form) return;
    
    // Get current user data from PHP variables
    const profileData = {
        full_name: '<?php echo htmlspecialchars($display_name); ?>',
        email: '<?php echo htmlspecialchars($user_profile["email"] ?? ""); ?>',
        // Add more fields as needed
    };
    
    // Pre-populate form fields
    Object.keys(profileData).forEach(fieldName => {
        if (profileData[fieldName]) {
            const input = form.querySelector(`[name="${fieldName}"]`);
            if (input && (!input.value || input.value.trim() === '')) {
                input.value = profileData[fieldName];
            }
        }
    });
    
    // Also try to pre-populate from userData object
    Object.keys(userData).forEach(key => {
        if (userData[key]) {
            const input = form.querySelector(`[name="${key}"]`);
            if (input && (!input.value || input.value.trim() === '')) {
                input.value = userData[key];
            }
        }
    });
}

function prePopulateProfileForm() {
    const form = document.querySelector('#profileModal form');
    if (!form) return;
    
    // Get current user data from PHP variables
    const profileData = {
        full_name: '<?php echo htmlspecialchars($display_name); ?>',
        email: '<?php echo htmlspecialchars($user_profile["email"] ?? ""); ?>',
        // Add more fields as needed
    };
    
    // Pre-populate form fields
    Object.keys(profileData).forEach(fieldName => {
        if (profileData[fieldName]) {
            const input = form.querySelector(`[name="${fieldName}"]`);
            if (input && (!input.value || input.value.trim() === '')) {
                input.value = profileData[fieldName];
            }
        }
    });
    
    // Also try to pre-populate from userData object
    Object.keys(userData).forEach(key => {
        if (userData[key]) {
            const input = form.querySelector(`[name="${key}"]`);
            if (input && (!input.value || input.value.trim() === '')) {
                input.value = userData[key];
            }
        }
    });
}



// Tab persistence functionality
function saveSelectedTab(tabId) {
    localStorage.setItem('selectedTab', tabId);
}

function loadSelectedTab() {
    const savedTab = localStorage.getItem('selectedTab');
    if (savedTab) {
        switchTab(savedTab);
    } else {
        // If no saved tab, default to dashboard
        switchTab('dashboard');
    }
}

// Update the switchTab function to save the tab
function switchTab(tabId) {
    // Update tab links
    document.querySelectorAll('.tab-link').forEach(link => {
        link.classList.remove('active');
        if (link.getAttribute('href') === '#' + tabId) {
            link.classList.add('active');
        }
    });

    // Update tab content
    document.querySelectorAll('.tab-panel').forEach(panel => {
        panel.classList.add('hidden');
        panel.classList.remove('active');
    });

    const activePanel = document.getElementById(tabId + '-content');
    if (activePanel) {
        activePanel.classList.remove('hidden');
        activePanel.classList.add('active');
    }

    // Save the selected tab
    saveSelectedTab(tabId);

    // Close mobile menu on mobile devices
    if (window.innerWidth <= 1024) {
        closeMobileMenu();
    }
}

// Update tab link click handlers to use the updated switchTab function
document.addEventListener('DOMContentLoaded', function() {
    // Update all tab links to use the enhanced switchTab function
    document.querySelectorAll('.tab-link').forEach(link => {
        const originalOnClick = link.getAttribute('onclick');
        if (originalOnClick && originalOnClick.includes('switchTab')) {
            // Extract the tab ID from the onclick attribute
            const match = originalOnclick.match(/switchTab\('([^']+)'\)/);
            if (match) {
                const tabId = match[1];
                link.setAttribute('onclick', `switchTab('${tabId}')`);
            }
        }
    });
    
    // Load saved tab on page load
    setTimeout(() => {
        loadSelectedTab();
    }, 100);
});


// Password modal functions
function openPasswordModal() {
    const modal = document.getElementById('passwordModal');
    const modalContent = document.getElementById('passwordModalContent');

    // Show loading
    modalContent.innerHTML = '<div class="text-center py-8"><div class="spinner-border text-maroon" role="status"><span class="sr-only">Loading...</span></div><p class="mt-2">Loading password form...</p></div>';

    // Load password form content
    fetch('change_password.php', {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.text())
    .then(html => {
        modalContent.innerHTML = html;
    })
    .catch(error => {
        console.error('Error loading password form:', error);
        modalContent.innerHTML = '<div class="text-center py-8 text-red-600">Error loading password form</div>';
    });

    // Show modal
    modal.classList.remove('hidden');
}

function closePasswordModal() {
    const modal = document.getElementById('passwordModal');
    modal.classList.add('hidden');
}

function submitPasswordForm(event) {
    event.preventDefault();
    
    const form = event.target;
    const formData = new FormData(form);
    
    // Show loading state
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Changing...';
    submitBtn.disabled = true;
    
    // Use AJAX to submit the form
    fetch('change_password.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Show success message
            alert(data.message);
            
            // Close modal after 1.5 seconds
            setTimeout(() => {
                closePasswordModal();
            }, 1500);
        } else {
            // Show error message in modal
            const errorDiv = document.createElement('div');
            errorDiv.className = 'bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4';
            errorDiv.innerHTML = `<div class="flex items-center"><i class="bi bi-exclamation-circle mr-2"></i>${data.message}</div>`;
            
            // Insert error message at the top of modal content
            const modalContent = document.getElementById('passwordModalContent');
            modalContent.insertBefore(errorDiv, modalContent.firstChild);
            
            // Restore button state
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }
    })
    .catch(error => {
        console.error('Error changing password:', error);
        
        // Show error message
        const errorDiv = document.createElement('div');
        errorDiv.className = 'bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4';
        errorDiv.innerHTML = '<div class="flex items-center"><i class="bi bi-exclamation-circle mr-2"></i>An error occurred. Please try again.</div>';
        
        const modalContent = document.getElementById('passwordModalContent');
        modalContent.insertBefore(errorDiv, modalContent.firstChild);
        
        // Restore button state
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    });
}

    </script>
</body>
</html>