<?php
session_start();
include 'config/database.php';

// Set timezone to Philippines
date_default_timezone_set('Asia/Manila');

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Get user information
$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT username, role, full_name, email, created_at FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user_profile = $result->fetch_assoc();

// Get display name - use full_name from users table, fallback to username
$display_name = !empty($user_profile['full_name']) ? trim($user_profile['full_name']) : $user_profile['username'];

// Get counts for dashboard
$total_patients = $conn->query("SELECT COUNT(*) as count FROM patients")->fetch_assoc()['count'];
$total_records = $conn->query("SELECT COUNT(*) as count FROM medical_records")->fetch_assoc()['count'];
$total_history_forms = $conn->query("SELECT COUNT(*) as count FROM medical_records WHERE record_type = 'history_form'")->fetch_assoc()['count'];
$total_dental_exams = $conn->query("SELECT COUNT(*) as count FROM medical_records WHERE record_type = 'dental_exam'")->fetch_assoc()['count'];
$total_medical_exams = $conn->query("SELECT COUNT(*) as count FROM medical_records WHERE record_type = 'medical_exam'")->fetch_assoc()['count'];

// Get pending verifications count
$pending_verifications = $conn->query("SELECT COUNT(*) as count FROM medical_records WHERE verification_status = 'pending'")->fetch_assoc()['count'];

// Get recent patients
$recent_patients_query = "
    SELECT p.*, 
            MAX(mr.examination_date) as last_visit
    FROM patients p
    LEFT JOIN medical_records mr ON p.id = mr.patient_id
    GROUP BY p.id
    ORDER BY last_visit DESC
    LIMIT 5
";
$recent_patients_result = $conn->query($recent_patients_query);
$recent_patients = [];
while ($row = $recent_patients_result->fetch_assoc()) {
    $recent_patients[] = $row;
}

// Get recent activity
$recent_activity_query = "
    SELECT 
        a.action,
        a.timestamp,
        u.username,
        u.full_name
    FROM 
        analytics_data a
    LEFT JOIN 
        users u ON a.user_id = u.id
    ORDER BY 
        a.timestamp DESC
    LIMIT 5
";
$recent_activity_result = $conn->query($recent_activity_query);
$recent_activity = [];
while ($row = $recent_activity_result->fetch_assoc()) {
    // Use full_name if available, otherwise fallback to username
    $row['display_name'] = !empty($row['full_name']) ? trim($row['full_name']) : $row['username'];
    $recent_activity[] = $row;
}

// Get recent activities from user_activities table if it exists
$recent_activities = [];
$check_activities = $conn->query("SHOW TABLES LIKE 'user_activities'");
if ($check_activities->num_rows > 0) {
    $activities_result = $conn->query("
        SELECT activity_type, activity_description, created_at 
        FROM user_activities 
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    if ($activities_result) {
        while($activity = $activities_result->fetch_assoc()) {
            $recent_activities[] = $activity;
        }
    }
}

// Log this dashboard view in analytics
$action = "Viewed dashboard";
$stmt = $conn->prepare("
    INSERT INTO analytics_data (user_id, action, timestamp)
    VALUES (?, ?, NOW())
");
$stmt->bind_param("is", $_SESSION['user_id'], $action);
$stmt->execute();

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
        $initials = 'A'; // Default for admin/staff
    }
    
    return $initials;
}

// Function to get activity icon
function getActivityIcon($activity_type) {
    switch($activity_type) {
        case 'form_submission':
            return 'bi-clipboard-check text-green-600';
        case 'profile_update':
            return 'bi-person-check text-purple-600';
        case 'patient_viewed':
            return 'bi-eye-fill text-blue-600';
        case 'verification':
            return 'bi-shield-check text-orange-600';
        case 'record_created':
            return 'bi-file-earmark-plus text-red-600';
        case 'login':
            return 'bi-box-arrow-in-right text-teal-600';
        case 'logout':
            return 'bi-box-arrow-right text-gray-600';
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
        case 'patient_viewed':
            return 'bg-blue-100';
        case 'verification':
            return 'bg-orange-100';
        case 'record_created':
            return 'bg-red-100';
        case 'login':
            return 'bg-teal-100';
        case 'logout':
            return 'bg-gray-100';
        default:
            return 'bg-gray-100';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - BSU Clinic Record Management System</title>
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
        
        .stats-card-4 {
            background: linear-gradient(135deg, #cc0000, #e60000);
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

        /* Pulsing badge animation */
        @keyframes pulse-badge {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }
        
        .pulse-badge {
            animation: pulse-badge 2s infinite;
        }

        /* Iframe loading styles */
        #patients-loading {
            animation: fadeIn 0.3s ease;
        }

        #patients-iframe-container iframe {
            animation: fadeIn 0.5s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        /* Spinner for loading */
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

        /* Add this to your existing styles */
        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 0; /* Important for flex children to scroll */
        }

        .tab-panel {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        /* For patients tab only */
        #patients-content.active ~ .page-footer {
            margin-top: 0;
        }

        #patients-content.active {
            margin: -1.5rem; /* Counteract the p-6 from main-content */
        }

        /* Fix for patients tab only */
        #patients-content {
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        #patients-content .bg-white {
            flex: 1;
            min-height: 0;
        }

        /* FIX: Full-screen iframe styles */
        .fullscreen-iframe-container {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            width: 100%;
            height: 100%;
        }

        .fullscreen-iframe {
            width: 100%;
            height: 100%;
            border: none;
        }

        /* Ensure iframe takes full space */
        .tab-panel > .bg-white {
            position: relative;
            flex: 1;
            min-height: 0;
        }

        #patients-content,
        #admin-content {
            position: relative;
        }

        #patients-content > .bg-white,
        #admin-content > .bg-white {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            width: 100%;
            height: 100%;
            margin: 0;
            border-radius: 0;
            box-shadow: none;
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
                    <p class="text-gray-400 text-sm"><?php echo ucfirst(htmlspecialchars($user_profile['role'])); ?></p>
                </div>
            </div>
            <div class="space-y-2">
                <div class="flex justify-between text-gray-300 text-sm">
                    <span>Username:</span>
                    <span class="font-medium"><?php echo htmlspecialchars($user_profile['username']); ?></span>
                </div>
                <div class="flex justify-between text-gray-300 text-sm">
                    <span>Role:</span>
                    <span class="font-medium"><?php echo ucfirst(htmlspecialchars($user_profile['role'])); ?></span>
                </div>
                <div class="flex justify-between text-gray-300 text-sm">
                    <span>Member Since:</span>
                    <span class="font-medium"><?php echo date('M j, Y', strtotime($user_profile['created_at'])); ?></span>
                </div>
            </div>
        </div>

        <!-- Navigation Tabs -->
        <div class="py-4">
            <a href="#dashboard" class="tab-link active" data-tab="dashboard">
                <i class="bi bi-speedometer2"></i>
                <span>Dashboard</span>
            </a>
            <a href="#patients" class="tab-link" data-tab="patients">
                <i class="bi bi-people-fill"></i>
                <span>Patients</span>
            </a>
            <a href="#forms" class="tab-link" data-tab="forms">
                <i class="bi bi-clipboard-check"></i>
                <span>Forms</span>
                <?php if ($pending_verifications > 0): ?>
                    <span class="ml-auto bg-red-500 text-white text-xs px-2 py-1 rounded-full pulse-badge"><?php echo $pending_verifications; ?></span>
                <?php endif; ?>
            </a>
            <a href="#analytics" class="tab-link" data-tab="analytics">
                <i class="bi bi-bar-chart-line"></i>
                <span>Analytics</span>
            </a>
            <a href="#admin" class="tab-link" data-tab="admin">
                <i class="bi bi-person-badge"></i>
                <span>Admin Panel</span>
            </a>
            <a href="#profile" class="tab-link" data-tab="profile">
                <i class="bi bi-person-circle"></i>
                <span>Profile</span>
            </a>
        </div>

        <!-- Recent Activities Sidebar Section -->
        <div class="px-6 py-4 border-t border-gray-800">
            <h4 class="text-gray-400 text-sm font-semibold mb-3 uppercase tracking-wider">Recent Activities</h4>
            <div class="space-y-3">
                <?php if(count($recent_activities) > 0): ?>
                    <?php foreach($recent_activities as $activity): ?>
                        <div class="flex items-start gap-3">
                            <div class="<?php echo getActivityBg($activity['activity_type']); ?> p-2 rounded-full mt-1">
                                <i class="bi <?php echo getActivityIcon($activity['activity_type']); ?> text-sm"></i>
                            </div>
                            <div class="flex-1">
                                <p class="text-gray-300 text-sm font-medium"><?php echo htmlspecialchars($activity['activity_description']); ?></p>
                                <p class="text-gray-500 text-xs"><?php echo date('M j, g:i A', strtotime($activity['created_at'])); ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="flex items-start gap-3">
                        <div class="bg-gray-100 p-2 rounded-full mt-1">
                            <i class="bi bi-activity text-gray-600 text-sm"></i>
                        </div>
                        <div class="flex-1">
                            <p class="text-gray-300 text-sm font-medium">Dashboard accessed</p>
                            <p class="text-gray-500 text-xs"><?php echo date('M j, g:i A'); ?></p>
                        </div>
                    </div>
                    <p class="text-gray-500 text-sm mt-2">No recent activities</p>
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
            <div id="dashboard-content" class="tab-panel active">
                <!-- Welcome Card with Alert -->
                <div class="bg-white rounded-xl shadow-md p-6 mb-6 border-l-4 border-maroon">
                    <div class="flex items-center justify-between">
                        <div>
                            <h2 class="text-2xl font-bold text-gray-800 mb-2">Welcome, <?php echo htmlspecialchars($display_name); ?>!</h2>
                            <p class="text-gray-600">Manage clinic records, verify submissions, and monitor system activities.</p>
                        </div>
                        <div class="hidden md:block">
                            <div class="w-20 h-20 maroon-gradient rounded-full flex items-center justify-center text-white text-2xl font-bold shadow-lg">
                                <?php echo generateDefaultAvatar($display_name); ?>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($pending_verifications > 0): ?>
                <div class="maroon-gradient-alert p-4 mb-6 rounded-lg shadow-md border-l-4 border-maroon">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <i class="bi bi-exclamation-triangle text-maroon text-3xl pulse-badge"></i>
                            <div>
                                <h3 class="text-lg font-bold text-maroon">Pending Verifications</h3>
                                <p class="text-maroon-light">You have <strong><?php echo $pending_verifications; ?></strong> submission(s) waiting for verification.</p>
                            </div>
                        </div>
                        <a href="#forms" onclick="switchTab('forms')" class="maroon-gradient-button text-white font-semibold px-6 py-3 rounded-lg shadow transition">
                            <i class="bi bi-shield-check mr-2"></i>Review Now
                        </a>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Stats Cards -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                    <!-- Total Patients Card -->
                    <div class="stats-card-1 text-white rounded-xl p-6 shadow-lg">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm opacity-90">Total Patients</p>
                                <p class="text-3xl font-bold mt-2"><?php echo $total_patients; ?></p>
                            </div>
                            <i class="bi bi-people-fill text-4xl opacity-80"></i>
                        </div>
                        <div class="mt-4">
                            <a href="#patients" onclick="switchTab('patients')" class="text-white text-sm font-medium hover:underline flex items-center">
                                View Patients <i class="bi bi-arrow-right ml-1"></i>
                            </a>
                        </div>
                    </div>

                    <!-- Medical History Forms Card -->
                    <div class="stats-card-2 text-white rounded-xl p-6 shadow-lg">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm opacity-90">Medical History Forms</p>
                                <p class="text-3xl font-bold mt-2"><?php echo $total_history_forms; ?></p>
                            </div>
                            <i class="bi bi-clipboard2-pulse-fill text-4xl opacity-80"></i>
                        </div>
                        <div class="mt-4">
                            <a href="#forms" onclick="switchTab('forms')" class="text-white text-sm font-medium hover:underline flex items-center">
                                New Form <i class="bi bi-arrow-right ml-1"></i>
                            </a>
                        </div>
                    </div>

                    <!-- Dental Examinations Card -->
                    <div class="stats-card-3 text-white rounded-xl p-6 shadow-lg">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm opacity-90">Dental Examinations</p>
                                <p class="text-3xl font-bold mt-2"><?php echo $total_dental_exams; ?></p>
                            </div>
                            <i class="bi bi-person-badge-fill text-4xl opacity-80"></i>
                        </div>
                        <div class="mt-4">
                            <a href="#forms" onclick="switchTab('forms')" class="text-white text-sm font-medium hover:underline flex items-center">
                                New Exam <i class="bi bi-arrow-right ml-1"></i>
                            </a>
                        </div>
                    </div>

                    <!-- Medical Examinations Card -->
                    <div class="stats-card-4 text-white rounded-xl p-6 shadow-lg">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm opacity-90">Medical Examinations</p>
                                <p class="text-3xl font-bold mt-2"><?php echo $total_medical_exams; ?></p>
                            </div>
                            <i class="bi bi-heart-pulse-fill text-4xl opacity-80"></i>
                        </div>
                        <div class="mt-4">
                            <a href="#forms" onclick="switchTab('forms')" class="text-white text-sm font-medium hover:underline flex items-center">
                                New Exam <i class="bi bi-arrow-right ml-1"></i>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Recent Data Tables -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                    <!-- Recent Patients Table -->
                    <div class="bg-white rounded-xl shadow-md overflow-hidden">
                        <div class="flex justify-between items-center px-4 py-3 maroon-table-header text-white">
                            <h3 class="font-semibold flex items-center gap-2"><i class="bi bi-clock-history"></i> Recent Patients</h3>
                            <a href="#patients" onclick="switchTab('patients')" class="px-3 py-1 bg-white bg-opacity-20 text-white text-sm font-semibold rounded hover:bg-opacity-30 backdrop-blur-sm">View All</a>
                        </div>
                        <div class="p-4">
                            <?php if (count($recent_patients) > 0): ?>
                            <div class="overflow-x-auto">
                                <table class="min-w-full text-sm">
                                    <thead class="maroon-table-header text-white">
                                        <tr>
                                            <th class="py-2 px-3">Student ID</th>
                                            <th class="py-2 px-3">Name</th>
                                            <th class="py-2 px-3">Last Visit</th>
                                            <th class="py-2 px-3">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <?php foreach ($recent_patients as $patient): ?>
                                        <tr class="maroon-table-row hover:shadow transition-all duration-200">
                                            <td class="py-2 px-3"><?php echo htmlspecialchars($patient['student_id']); ?></td>
                                            <td class="py-2 px-3"><?php echo htmlspecialchars($patient['last_name']) . ', ' . htmlspecialchars($patient['first_name']); ?></td>
                                            <td class="py-2 px-3"><?php echo $patient['last_visit'] ? htmlspecialchars($patient['last_visit']) : 'No visits'; ?></td>
                                            <td class="py-2 px-3">
                                                <a href="modules/records/view_patient.php?id=<?php echo $patient['id']; ?>" class="inline-flex items-center gap-1 px-2 py-1 maroon-badge rounded hover:shadow text-xs font-semibold transition-all">
                                                    <i class="bi bi-eye"></i> View
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php else: ?>
                                <div class="maroon-gradient-alert text-maroon p-3 rounded">No patients found in the system.</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Recent Activity Table -->
                    <div class="bg-white rounded-xl shadow-md overflow-hidden">
                        <div class="flex justify-between items-center px-4 py-3 maroon-table-header text-white">
                            <h3 class="font-semibold flex items-center gap-2"><i class="bi bi-activity"></i> Recent Activity</h3>
                            <a href="#analytics" onclick="switchTab('analytics')" class="maroon-gradient-button text-white px-4 py-2 rounded-lg hover:shadow transition-all">
                                <i class="bi bi-bar-chart-line"></i> View Analytics
                            </a>
                        </div>
                        <div class="p-4">
                            <?php if (count($recent_activity) > 0): ?>
                            <div class="overflow-x-auto">
                                <table class="min-w-full text-sm">
                                    <thead class="maroon-table-header text-white">
                                        <tr>
                                            <th class="py-2 px-3">Action</th>
                                            <th class="py-2 px-3">User</th>
                                            <th class="py-2 px-3">Timestamp</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <?php foreach ($recent_activity as $activity): ?>
                                        <tr class="maroon-table-row hover:shadow transition-all duration-200">
                                            <td class="py-2 px-3"><?php echo htmlspecialchars($activity['action']); ?></td>
                                            <td class="py-2 px-3"><?php echo htmlspecialchars($activity['display_name']); ?></td>
                                            <td class="py-2 px-3"><?php echo htmlspecialchars($activity['timestamp']); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php else: ?>
                                <div class="maroon-gradient-alert text-maroon p-3 rounded">No recent activity found.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="bg-white rounded-xl shadow-md p-6">
                    <h3 class="text-xl font-semibold text-gray-800 mb-6 flex items-center gap-2">
                        <i class="bi bi-lightning-charge text-maroon"></i> Quick Actions
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <a href="modules/records/verify_submission.php" class="bg-maroon-light border border-maroon rounded-lg p-4 hover:bg-red-50 transition-all duration-200 flex items-center gap-3">
                            <div class="bg-maroon text-white p-3 rounded-lg">
                                <i class="bi bi-shield-check"></i>
                            </div>
                            <div>
                                <h4 class="font-semibold text-gray-800">Verify Submissions</h4>
                                <p class="text-gray-600 text-sm">Review pending forms</p>
                            </div>
                        </a>
                        
                        <a href="modules/records/patients.php" class="bg-maroon-light border border-maroon rounded-lg p-4 hover:bg-red-50 transition-all duration-200 flex items-center gap-3">
                            <div class="bg-maroon text-white p-3 rounded-lg">
                                <i class="bi bi-person-plus"></i>
                            </div>
                            <div>
                                <h4 class="font-semibold text-gray-800">Manage Patients</h4>
                                <p class="text-gray-600 text-sm">View all patient records</p>
                            </div>
                        </a>
                        
                        <a href="admin_panel.php" class="bg-maroon-light border border-maroon rounded-lg p-4 hover:bg-red-50 transition-all duration-200 flex items-center gap-3">
                            <div class="bg-maroon text-white p-3 rounded-lg">
                                <i class="bi bi-gear"></i>
                            </div>
                            <div>
                                <h4 class="font-semibold text-gray-800">Admin Panel</h4>
                                <p class="text-gray-600 text-sm">System administration</p>
                            </div>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Patients Tab -->
            <div id="patients-content" class="tab-panel hidden" style="display: none !important;">
                <div class="bg-white rounded-xl shadow-md p-0 overflow-hidden flex-1 h-full">
                    <!-- Loading indicator -->
                    <div id="patients-loading" class="h-full flex items-center justify-center" style="display: none;">
                        <div class="text-center">
                            <div class="spinner-border text-maroon" role="status">
                                <span class="sr-only">Loading...</span>
                            </div>
                            <p class="mt-2 text-gray-600">Loading patient management...</p>
                        </div>
                    </div>
                    
                    <!-- Content container -->
                    <div id="patients-iframe-container" class="hidden h-full" style="display: none;">
                        <iframe 
                            id="patients-iframe"
                            src=""
                            frameborder="0"
                            class="w-full h-full fullscreen-iframe"
                            style="border: none; display: none;"
                            onload="hidePatientsLoading()"
                        ></iframe>
                    </div>
                    
                    <!-- Fallback content if iframe fails -->
                    <div id="patients-fallback" class="hidden h-full flex items-center justify-center" style="display: none;">
                        <div class="text-center p-6">
                            <h2 class="text-2xl font-bold text-gray-800 mb-2 flex items-center justify-center gap-2">
                                <i class="bi bi-people-fill text-maroon"></i> Patient Management
                            </h2>
                            <p class="text-gray-600 mb-6">View and manage all patient records</p>
                            
                            <div class="mb-6">
                                <a href="modules/records/patients.php" target="_blank" class="maroon-gradient-button text-white px-6 py-3 rounded-lg font-semibold hover:shadow-lg transition-all inline-flex items-center gap-2">
                                    <i class="bi bi-box-arrow-up-right"></i> Open Patients Management in New Tab
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Forms Tab -->
            <div id="forms-content" class="tab-panel hidden">
                <div class="bg-white rounded-xl shadow-md p-6">
                    <h2 class="text-2xl font-bold text-gray-800 mb-2 flex items-center gap-2">
                        <i class="bi bi-clipboard-check text-maroon"></i> Forms Management
                    </h2>
                    <p class="text-gray-600 mb-6">Create and manage medical forms</p>
                    
                    <?php if ($pending_verifications > 0): ?>
                    <div class="maroon-gradient-alert p-4 mb-6 rounded-lg shadow-md border-l-4 border-maroon">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <i class="bi bi-exclamation-triangle text-maroon text-2xl"></i>
                                <div>
                                    <h3 class="text-lg font-bold text-maroon">Action Required</h3>
                                    <p class="text-maroon-light">You have <strong><?php echo $pending_verifications; ?></strong> submission(s) waiting for verification.</p>
                                </div>
                            </div>
                            <a href="modules/records/verify_submission.php" class="maroon-gradient-button text-white font-semibold px-6 py-3 rounded-lg shadow transition">
                                <i class="bi bi-shield-check mr-2"></i>Review All
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                        <a href="modules/forms/history_form.php" class="bg-maroon-light border border-maroon rounded-xl p-6 shadow-lg hover:shadow-xl transition duration-300 transform hover:scale-[1.02]">
                            <div class="flex items-center justify-center mb-4">
                                <i class="bi bi-clipboard2-pulse-fill text-4xl text-maroon"></i>
                            </div>
                            <h3 class="text-lg font-semibold tracking-tight text-center mb-2 text-gray-800">Medical History Form</h3>
                            <p class="text-2xl font-bold text-center text-maroon"><?php echo $total_history_forms; ?></p>
                            <p class="text-sm opacity-90 text-center text-gray-600">Create new form</p>
                        </a>

                        <a href="modules/forms/dental_form.php" class="bg-maroon-light border border-maroon rounded-xl p-6 shadow-lg hover:shadow-xl transition duration-300 transform hover:scale-[1.02]">
                            <div class="flex items-center justify-center mb-4">
                                <i class="fas fa-tooth text-4xl text-maroon"></i>
                            </div>
                            <h3 class="text-lg font-semibold tracking-tight text-center mb-2 text-gray-800">Dental Examination</h3>
                            <p class="text-2xl font-bold text-center text-maroon"><?php echo $total_dental_exams; ?></p>
                            <p class="text-sm opacity-90 text-center text-gray-600">Create new exam</p>
                        </a>

                        <a href="modules/forms/medical_form.php" class="bg-maroon-light border border-maroon rounded-xl p-6 shadow-lg hover:shadow-xl transition duration-300 transform hover:scale-[1.02]">
                            <div class="flex items-center justify-center mb-4">
                                <i class="bi bi-heart-pulse text-4xl text-maroon"></i>
                            </div>
                            <h3 class="text-lg font-semibold tracking-tight text-center mb-2 text-gray-800">Medical Examination</h3>
                            <p class="text-2xl font-bold text-center text-maroon"><?php echo $total_medical_exams; ?></p>
                            <p class="text-sm opacity-90 text-center text-gray-600">Create new exam</p>
                        </a>
                    </div>
                    
                    <div class="flex flex-col sm:flex-row gap-4">
                        <a href="modules/records/verify_submission.php" class="flex-1 maroon-gradient-button text-white py-3 rounded-lg font-semibold hover:shadow-lg transition-all text-center">
                            <i class="bi bi-shield-check mr-2"></i> Verify Submissions
                        </a>
                        <a href="modules/records/submissions.php" class="flex-1 bg-gray-200 text-gray-800 py-3 rounded-lg font-semibold hover:bg-gray-300 transition-all text-center">
                            <i class="bi bi-list-check mr-2"></i> View All Submissions
                        </a>
                    </div>
                </div>
            </div>

            <!-- Analytics Tab -->
            <div id="analytics-content" class="tab-panel hidden">
                <div class="bg-white rounded-xl shadow-md p-6">
                    <h2 class="text-2xl font-bold text-gray-800 mb-2 flex items-center gap-2">
                        <i class="bi bi-bar-chart-line text-maroon"></i> Analytics Dashboard
                    </h2>
                    <p class="text-gray-600 mb-6">View clinic statistics and reports</p>
                    
                    <div class="mb-8">
                        <button onclick="openAnalyticsDashboard()" class="maroon-gradient-button text-white px-6 py-3 rounded-lg font-semibold hover:shadow-lg transition-all inline-flex items-center gap-2">
                            <i class="bi bi-bar-chart"></i> Open Analytics Dashboard
                        </button>
                    </div>
                    
                    <!-- Summary Stats -->
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                        <div class="bg-maroon-light border border-maroon rounded-xl p-4">
                            <h3 class="text-sm font-semibold text-gray-600 mb-2">Total Patients</h3>
                            <p class="text-3xl font-bold text-maroon"><?php echo $total_patients; ?></p>
                        </div>
                        <div class="bg-maroon-light border border-maroon rounded-xl p-4">
                            <h3 class="text-sm font-semibold text-gray-600 mb-2">Total Records</h3>
                            <p class="text-3xl font-bold text-maroon"><?php echo $total_records; ?></p>
                        </div>
                        <div class="bg-maroon-light border border-maroon rounded-xl p-4">
                            <h3 class="text-sm font-semibold text-gray-600 mb-2">Pending Verifications</h3>
                            <p class="text-3xl font-bold text-maroon"><?php echo $pending_verifications; ?></p>
                        </div>
                        <div class="bg-maroon-light border border-maroon rounded-xl p-4">
                            <h3 class="text-sm font-semibold text-gray-600 mb-2">Total Forms</h3>
                            <p class="text-3xl font-bold text-maroon"><?php echo $total_history_forms + $total_dental_exams + $total_medical_exams; ?></p>
                        </div>
                    </div>
                    
                    <!-- Chart placeholders -->
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                        <div class="bg-gray-50 border border-gray-200 rounded-xl p-6">
                            <h3 class="text-lg font-semibold text-gray-800 mb-4">Forms by Type</h3>
                            <div class="space-y-4">
                                <div class="flex items-center justify-between">
                                    <span class="text-gray-600">History Forms</span>
                                    <span class="font-semibold text-maroon"><?php echo $total_history_forms; ?></span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-gray-600">Dental Exams</span>
                                    <span class="font-semibold text-maroon"><?php echo $total_dental_exams; ?></span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-gray-600">Medical Exams</span>
                                    <span class="font-semibold text-maroon"><?php echo $total_medical_exams; ?></span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="bg-gray-50 border border-gray-200 rounded-xl p-6">
                            <h3 class="text-lg font-semibold text-gray-800 mb-4">System Overview</h3>
                            <div class="space-y-4">
                                <div class="flex items-center justify-between">
                                    <span class="text-gray-600">Active Users</span>
                                    <span class="font-semibold text-maroon"><?php echo $total_records > 0 ? 'Active' : 'Inactive'; ?></span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-gray-600">Data Integrity</span>
                                    <span class="font-semibold text-green-600">100%</span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-gray-600">System Status</span>
                                    <span class="font-semibold text-green-600">Operational</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Admin Panel Tab -->
            <div id="admin-content" class="tab-panel hidden">
                <div class="bg-white rounded-xl shadow-md p-0 overflow-hidden flex-1 h-full fullscreen-iframe-container">
                    <iframe 
                        src="admin_panel.php" 
                        frameborder="0" 
                        class="w-full h-full fullscreen-iframe"
                        style="border: none;"
                    ></iframe>
                </div>
            </div>

            <!-- Profile Tab -->
            <div id="profile-content" class="tab-panel hidden">
                <div class="bg-white rounded-xl shadow-md p-6">
                    <h2 class="text-2xl font-bold text-gray-800 mb-2 flex items-center gap-2">
                        <i class="bi bi-person-circle text-purple-600"></i> User Profile
                    </h2>
                    
                    <!-- Success Message Container (initially hidden) -->
                    <div id="profileSuccessMessage" class="hidden bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6">
                        <div class="flex items-center">
                            <i class="bi bi-check-circle mr-2"></i>
                            <span id="successMessageText"></span>
                        </div>
                    </div>
                    
                    <p class="text-gray-600 mb-6">Manage your profile information</p>
                    
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
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Username</label>
                                        <p class="text-gray-900 font-medium"><?php echo htmlspecialchars($user_profile['username']); ?></p>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Email Address</label>
                                        <p class="text-gray-900 font-medium"><?php echo htmlspecialchars($user_profile['email'] ?? 'Not set'); ?></p>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Role</label>
                                        <p class="text-gray-900 font-medium"><?php echo ucfirst(htmlspecialchars($user_profile['role'])); ?></p>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Member Since</label>
                                        <p class="text-gray-900 font-medium"><?php echo date('M j, Y', strtotime($user_profile['created_at'])); ?></p>
                                    </div>
                                </div>
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
                                <p class="text-gray-600 text-sm mb-4"><?php echo ucfirst(htmlspecialchars($user_profile['role'])); ?></p>
                                
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
                                        <span class="text-gray-600">Patients Managed:</span>
                                        <span class="font-medium"><?php echo $total_patients; ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600">Forms Processed:</span>
                                        <span class="font-medium"><?php echo $total_records; ?></span>
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
                        <span>Logged in as: <?php echo htmlspecialchars($user_profile['username']); ?> | </span>
                        <span>Role: <?php echo ucfirst(htmlspecialchars($user_profile['role'])); ?> | </span>
                        <span>Last Access: <?php echo date('g:i A'); ?></span>
                    </div>
                </div>
            </div>
        </footer>
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

    <script>
    // Tab switching functionality
    function switchTab(tabId) {
        console.log('Switching to tab:', tabId);
        
        // Update tab links
        document.querySelectorAll('.tab-link').forEach(link => {
            link.classList.remove('active');
            if (link.getAttribute('href') === '#' + tabId) {
                link.classList.add('active');
            }
        });

        // Hide all tab panels
        document.querySelectorAll('.tab-panel').forEach(panel => {
            panel.classList.add('hidden');
            panel.style.display = 'none';
        });

        // Show the active tab panel
        const activePanel = document.getElementById(tabId + '-content');
        if (activePanel) {
            activePanel.classList.remove('hidden');
            activePanel.style.display = 'flex';
            
            // Special handling for patients tab
            if (tabId === 'patients') {
                console.log('Loading patients tab');
                loadPatientsContent();
            }
            
            // Resize iframes when switching to admin tab
            if (tabId === 'admin') {
                setTimeout(resizeAllIframes, 100);
            }
            
            // If switching to analytics tab, restore original content if it was replaced
            if (tabId === 'analytics') {
                const analyticsContent = document.getElementById('analytics-content');
                const originalContent = analyticsContent.getAttribute('data-original-content');
                if (originalContent) {
                    analyticsContent.innerHTML = originalContent;
                    analyticsContent.removeAttribute('data-original-content');
                }
            }
        }

        // Save tab selection
        localStorage.setItem('adminSelectedTab', tabId);

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

    // Logout modal functions
    function openLogoutModal(event) {
        event.preventDefault();
        document.getElementById('logoutModal').classList.remove('hidden');
    }

    function closeLogoutModal() {
        document.getElementById('logoutModal').classList.add('hidden');
    }

    // Profile modal functions
    function openProfileModal() {
        const modal = document.getElementById('profileModal');
        const modalContent = document.getElementById('profileModalContent');

        // Show loading
        modalContent.innerHTML = '<div class="text-center py-8"><div class="spinner-border text-maroon" role="status"><span class="sr-only">Loading...</span></div><p class="mt-2">Loading profile form...</p></div>';

        // Load profile form content (you'll need to create this file)
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
            const form = doc.querySelector('form');
            
            if (form) {
                // Remove any redirect scripts
                const scripts = form.querySelectorAll('script');
                scripts.forEach(script => {
                    if (script.textContent.includes('header("Location:') || 
                        script.textContent.includes('window.location')) {
                        script.remove();
                    }
                });

                // Update form to use AJAX submission
                form.setAttribute('onsubmit', 'submitProfileForm(event)');
                
                modalContent.innerHTML = form.outerHTML;
                
                // Pre-populate the form
                setTimeout(() => {
                    prePopulateProfileForm();
                }, 100);
            } else {
                modalContent.innerHTML = '<div class="text-center py-8">Profile update form will be available soon.</div>';
            }
        })
        .catch(error => {
            console.error('Error loading profile form:', error);
            modalContent.innerHTML = '<div class="text-center py-8">Error loading profile form.</div>';
        });

        // Show modal
        modal.classList.remove('hidden');
    }

    function closeProfileModal() {
        const modal = document.getElementById('profileModal');
        modal.classList.add('hidden');
    }

    // Password modal functions
    function openPasswordModal() {
        const modal = document.getElementById('passwordModal');
        const modalContent = document.getElementById('passwordModalContent');

        // Show loading
        modalContent.innerHTML = '<div class="text-center py-8"><div class="spinner-border text-maroon" role="status"><span class="sr-only">Loading...</span></div><p class="mt-2">Loading password form...</p></div>';

        // Load password form content (you'll need to create this file)
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
            modalContent.innerHTML = '<div class="text-center py-8">Error loading password form.</div>';
        });

        // Show modal
        modal.classList.remove('hidden');
    }

    function closePasswordModal() {
        const modal = document.getElementById('passwordModal');
        modal.classList.add('hidden');
    }

    function prePopulateProfileForm() {
        const form = document.querySelector('#profileModal form');
        if (!form) return;
        
        // Get current user data from PHP variables
        const profileData = {
            full_name: '<?php echo htmlspecialchars($display_name); ?>',
            email: '<?php echo htmlspecialchars($user_profile["email"] ?? ""); ?>',
            username: '<?php echo htmlspecialchars($user_profile["username"]); ?>'
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
                // Show success message
                showProfileSuccessMessage(data.message);
                
                // Close modal after 1.5 seconds
                setTimeout(() => {
                    closeProfileModal();
                }, 1500);
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
        // Switch to profile tab if not already there
        switchTab('profile');
        
        // Show success message
        const successDiv = document.getElementById('profileSuccessMessage');
        const messageText = document.getElementById('successMessageText');
        
        messageText.textContent = message;
        successDiv.classList.remove('hidden');
        
        // Auto-hide success message after 5 seconds
        setTimeout(() => {
            successDiv.classList.add('hidden');
        }, 5000);
        
        // Refresh the page to update user info
        setTimeout(() => {
            location.reload();
        }, 2000);
    }

    // Function to resize all iframes
    function resizeAllIframes() {
        // Resize admin iframe
        const adminIframe = document.querySelector('#admin-content iframe');
        if (adminIframe) {
            resizeAdminIframe(adminIframe);
        }
        
        // Resize patients iframe
        const patientsIframe = document.getElementById('patients-iframe');
        if (patientsIframe && patientsIframe.style.display !== 'none') {
            resizePatientsIframe(patientsIframe);
        }
    }

    function resizeAdminIframe(iframe) {
        if (!iframe) return;
        
        const adminContent = document.getElementById('admin-content');
        if (!adminContent) return;
        
        const header = document.querySelector('header');
        const footer = document.querySelector('.page-footer');
        
        const headerHeight = header ? header.offsetHeight : 0;
        const footerHeight = footer ? footer.offsetHeight : 0;
        
        // Calculate available height
        const windowHeight = window.innerHeight;
        const availableHeight = windowHeight - headerHeight - footerHeight;
        
        // Set iframe height
        iframe.style.height = Math.max(600, availableHeight) + 'px';
        
        // Also resize on window resize
        window.addEventListener('resize', function() {
            const newAvailableHeight = window.innerHeight - headerHeight - footerHeight;
            iframe.style.height = Math.max(600, newAvailableHeight) + 'px';
        });
    }

    function resizePatientsIframe(iframe) {
        if (!iframe) return;
        
        // Get the patients content container
        const patientsContent = document.getElementById('patients-content');
        if (!patientsContent) return;
        
        // Calculate available height
        const header = document.querySelector('header');
        const footer = document.querySelector('.page-footer');
        const mainContent = document.querySelector('.main-content');
        
        const headerHeight = header ? header.offsetHeight : 0;
        const footerHeight = footer ? footer.offsetHeight : 0;
        const mainContentTop = mainContent ? mainContent.getBoundingClientRect().top : 0;
        
        // Calculate available height
        const windowHeight = window.innerHeight;
        const availableHeight = windowHeight - mainContentTop - footerHeight - 20; // 20px buffer
        
        // Set iframe height
        iframe.style.height = Math.max(500, availableHeight) + 'px';
        console.log('Patients iframe resized to:', iframe.style.height);
        
        // Also resize on window resize
        window.addEventListener('resize', function() {
            const newAvailableHeight = window.innerHeight - mainContentTop - footerHeight - 20;
            iframe.style.height = Math.max(500, newAvailableHeight) + 'px';
        });
    }

    // Initialize
    document.addEventListener('DOMContentLoaded', function() {
        updateTime();
        setInterval(updateTime, 1000);
        
        // Load saved tab
        const savedTab = localStorage.getItem('adminSelectedTab') || 'dashboard';
        console.log('Initial tab:', savedTab);
        switchTab(savedTab);
        
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
        
        // Handle window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth > 1024) {
                closeMobileMenu();
            }
            // Resize iframes on window resize
            resizeAllIframes();
        });

        // Add iframe error handling
        const iframe = document.getElementById('patients-iframe');
        if (iframe) {
            iframe.addEventListener('error', function() {
                console.error('Failed to load patients iframe');
                showPatientsFallback();
            });
            
            // Check if iframe loads successfully within 10 seconds
            setTimeout(() => {
                const loadingEl = document.getElementById('patients-loading');
                if (loadingEl && !loadingEl.classList.contains('hidden')) {
                    showPatientsFallback();
                }
            }, 10000);
        }
        
        // Initial resize of iframes
        setTimeout(resizeAllIframes, 500);
    });

    function loadPatientsContent() {
        console.log('Loading patients content');
        
        // Get elements
        const loadingEl = document.getElementById('patients-loading');
        const containerEl = document.getElementById('patients-iframe-container');
        const fallbackEl = document.getElementById('patients-fallback');
        const iframeEl = document.getElementById('patients-iframe');
        
        // Show loading, hide others
        if (loadingEl) {
            loadingEl.style.display = 'flex';
            loadingEl.classList.remove('hidden');
        }
        if (containerEl) {
            containerEl.style.display = 'none';
            containerEl.classList.add('hidden');
        }
        if (fallbackEl) {
            fallbackEl.style.display = 'none';
            fallbackEl.classList.add('hidden');
        }
        if (iframeEl) {
            iframeEl.style.display = 'none';
            // Load the patients page
            iframeEl.src = "modules/records/patients.php";
        }
    }

    function hidePatientsLoading() {
        console.log('Iframe loaded, hiding loading');
        
        const loadingEl = document.getElementById('patients-loading');
        const containerEl = document.getElementById('patients-iframe-container');
        const iframe = document.getElementById('patients-iframe');
        
        if (loadingEl) {
            loadingEl.style.display = 'none';
            loadingEl.classList.add('hidden');
        }
        if (containerEl) {
            containerEl.style.display = 'block';
            containerEl.classList.remove('hidden');
        }
        if (iframe) {
            iframe.style.display = 'block';
            // Resize iframe to fit
            resizePatientsIframe(iframe);
        }
    }

    function showPatientsFallback() {
        const loadingEl = document.getElementById('patients-loading');
        const containerEl = document.getElementById('patients-iframe-container');
        const fallbackEl = document.getElementById('patients-fallback');
        
        if (loadingEl) {
            loadingEl.style.display = 'none';
            loadingEl.classList.add('hidden');
        }
        if (containerEl) {
            containerEl.style.display = 'none';
            containerEl.classList.add('hidden');
        }
        if (fallbackEl) {
            fallbackEl.style.display = 'flex';
            fallbackEl.classList.remove('hidden');
        }
    }

    // Function for analytics dashboard
    function openAnalyticsDashboard() {
        // Replace the current analytics tab content with an iframe
        const analyticsContent = document.getElementById('analytics-content');
        
        // Save the original content first
        const originalContent = analyticsContent.innerHTML;
        
        // Create a container for the iframe that includes a back button
        analyticsContent.innerHTML = `
            <div class="bg-white rounded-xl shadow-md p-6 flex-1 h-full flex flex-col">
                <div class="mb-4">
                    <button onclick="restoreAnalyticsTab()" 
                            class="inline-flex items-center gap-2 bg-white text-maroon font-semibold px-4 py-2 rounded-lg shadow hover:bg-gray-50 transition-all border border-gray-200">
                        <i class="bi bi-arrow-left-circle"></i> Back
                    </button>
                </div>
                <div class="flex-1 min-h-0">
                    <iframe 
                        src="modules/analytics/analytics_dashboard.php" 
                        frameborder="0" 
                        class="w-full h-full"
                        style="border: none;"
                    ></iframe>
                </div>
            </div>
        `;
        
        // Store the original content in a data attribute for restoration
        analyticsContent.setAttribute('data-original-content', originalContent);
        
        // Resize the iframe to fit properly
        setTimeout(() => {
            const iframe = analyticsContent.querySelector('iframe');
            if (iframe) {
                const header = document.querySelector('header');
                const footer = document.querySelector('.page-footer');
                const mainContent = document.querySelector('.main-content');
                
                const headerHeight = header ? header.offsetHeight : 0;
                const footerHeight = footer ? footer.offsetHeight : 0;
                const mainContentTop = mainContent ? mainContent.getBoundingClientRect().top : 0;
                
                const windowHeight = window.innerHeight;
                const availableHeight = windowHeight - mainContentTop - footerHeight - 100; // Account for back button
                
                iframe.style.height = Math.max(600, availableHeight) + 'px';
                
                // Also resize on window resize
                window.addEventListener('resize', function() {
                    const newAvailableHeight = window.innerHeight - mainContentTop - footerHeight - 100;
                    iframe.style.height = Math.max(600, newAvailableHeight) + 'px';
                });
            }
        }, 100);
    }

    // Function to restore analytics tab to original content
    function restoreAnalyticsTab() {
        const analyticsContent = document.getElementById('analytics-content');
        const originalContent = analyticsContent.getAttribute('data-original-content');
        
        if (originalContent) {
            analyticsContent.innerHTML = originalContent;
            analyticsContent.removeAttribute('data-original-content');
        } else {
            // Fallback: reload the page
            location.reload();
        }
    }
    </script>
</body>
</html>