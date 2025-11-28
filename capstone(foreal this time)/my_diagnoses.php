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
    SELECT username, email, created_at
    FROM users
    WHERE id = $user_id
")->fetch_assoc();

// Get patient information if exists
$patient_info = $conn->query("
    SELECT * FROM patients WHERE student_id = '" . $conn->real_escape_string($_SESSION['username']) . "'
")->fetch_assoc();

// Get full name for display
$display_name = $_SESSION['username'];
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

// If no full_name from user_details, construct from patient_info
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

// Function to get provider's full name
function getProviderFullName($conn, $provider_username) {
    if (empty($provider_username)) {
        return $provider_username;
    }
    
    // Clean the username - remove any whitespace
    $provider_username = trim($provider_username);
    
    // First, check if it's already a full name (contains spaces and doesn't match username pattern)
    // If it looks like a full name (has spaces, doesn't match typical username patterns), return as-is
    if (strpos($provider_username, ' ') !== false && !preg_match('/^[a-z0-9_-]+$/i', $provider_username)) {
        // It might already be a full name, but let's verify it's not a username
        $check_query = $conn->prepare("SELECT username FROM users WHERE BINARY username = ? LIMIT 1");
        $check_query->bind_param("s", $provider_username);
        $check_query->execute();
        $check_result = $check_query->get_result();
        
        // If it's NOT a username in the database, treat it as a full name
        if ($check_result->num_rows == 0) {
            $check_query->close();
            return $provider_username; // Return as-is if it's already a full name
        }
        $check_query->close();
    }
    
    // Try to find by username in users table (for staff: doctors, dentists, nurses)
    // Use BINARY comparison for case-sensitive matching
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

// Get filter parameter
$filter_type = isset($_GET['filter']) ? $_GET['filter'] : 'all';

// Fetch diagnoses for the current patient
$diagnoses = [];
if ($patient_info && isset($patient_info['id'])) {
    $patient_id_for_diagnoses = $patient_info['id'];
    
    // Build query with filter
    $where_clause = "WHERE md.patient_id = ?";
    $params = [$patient_id_for_diagnoses];
    $types = "i";
    
    if ($filter_type !== 'all') {
        $where_clause .= " AND md.diagnosis_type = ?";
        $params[] = $filter_type;
        $types .= "s";
    }
    
    $diagnoses_query = $conn->prepare("
        SELECT md.id, md.diagnosis_type, md.diagnosis_date, md.provider_name, md.provider_role,
               md.chief_complaint, md.subjective_findings, md.objective_findings, 
               md.assessment, md.plan, md.medications_prescribed, md.follow_up_required,
               md.follow_up_date, md.severity, md.status, md.notes, md.created_at,
               COALESCE(u.full_name, md.provider_name) as provider_full_name
        FROM medical_diagnoses md
        LEFT JOIN users u ON BINARY u.username = md.provider_name
        $where_clause
        ORDER BY md.diagnosis_date DESC, md.created_at DESC
    ");
    
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

// Get counts for filter badges
$counts = ['all' => 0, 'doctor' => 0, 'dentist' => 0, 'nurse' => 0];
if ($patient_info && isset($patient_info['id'])) {
    $count_query = $conn->prepare("
        SELECT diagnosis_type, COUNT(*) as count
        FROM medical_diagnoses
        WHERE patient_id = ?
        GROUP BY diagnosis_type
    ");
    $count_query->bind_param("i", $patient_info['id']);
    $count_query->execute();
    $count_result = $count_query->get_result();
    
    $total = 0;
    while ($row = $count_result->fetch_assoc()) {
        $counts[$row['diagnosis_type']] = $row['count'];
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
        .red-orange-gradient {
            background: linear-gradient(135deg, #dc2626, #ea580c, #f97316);
        }
        
        .red-orange-gradient-button {
            background: linear-gradient(135deg, #dc2626, #ea580c);
        }
        
        .red-orange-gradient-button:hover {
            background: linear-gradient(135deg, #b91c1c, #c2410c);
        }
        
        .filter-btn {
            transition: all 0.3s ease;
        }
        
        .filter-btn.active {
            background: linear-gradient(135deg, #dc2626, #ea580c);
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
            border-color: #ea580c;
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
                <a href="user_dashboard.php" class="hover:text-yellow-200 flex items-center gap-1">
                    <i class="bi bi-speedometer2"></i> Dashboard
                </a>
                <a href="my_diagnoses.php" class="hover:text-yellow-200 flex items-center gap-1 font-semibold">
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
        <!-- Page Header -->
        <div class="bg-white rounded-2xl shadow-xl overflow-hidden mb-8">
            <div class="red-orange-gradient px-8 py-6">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-4">
                        <div class="w-16 h-16 bg-white rounded-full flex items-center justify-center text-red-800 text-xl font-bold border-4 border-white shadow-lg">
                            <?php echo generateDefaultAvatar($display_name); ?>
                        </div>
                        <div>
                            <h2 class="text-2xl font-bold text-white mb-1">My Medical Diagnoses & Findings</h2>
                            <p class="text-white text-sm opacity-90">View all diagnoses from doctors, dentists, and nurses</p>
                        </div>
                    </div>
                    <a href="user_dashboard.php" class="bg-white text-orange-600 px-4 py-2 rounded-lg font-semibold hover:shadow-lg transition-all flex items-center gap-2">
                        <i class="bi bi-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="bg-white rounded-xl shadow-md p-6 mb-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-800">Filter by Provider Type</h3>
                <span class="text-sm text-gray-600">Total: <?php echo $counts['all']; ?> diagnosis(es)</span>
            </div>
            <div class="flex flex-wrap gap-3">
                <a href="?filter=all" class="filter-btn px-4 py-2 rounded-lg font-medium flex items-center gap-2 <?php echo $filter_type === 'all' ? 'active' : ''; ?>">
                    <i class="bi bi-list-ul"></i> All
                    <span class="bg-white/20 px-2 py-0.5 rounded-full text-xs"><?php echo $counts['all']; ?></span>
                </a>
                <a href="?filter=doctor" class="filter-btn px-4 py-2 rounded-lg font-medium flex items-center gap-2 <?php echo $filter_type === 'doctor' ? 'active' : ''; ?>">
                    <i class="bi bi-stethoscope"></i> Doctor
                    <span class="bg-white/20 px-2 py-0.5 rounded-full text-xs"><?php echo $counts['doctor']; ?></span>
                </a>
                <a href="?filter=dentist" class="filter-btn px-4 py-2 rounded-lg font-medium flex items-center gap-2 <?php echo $filter_type === 'dentist' ? 'active' : ''; ?>">
                    <i class="bi bi-tooth"></i> Dentist
                    <span class="bg-white/20 px-2 py-0.5 rounded-full text-xs"><?php echo $counts['dentist']; ?></span>
                </a>
                <a href="?filter=nurse" class="filter-btn px-4 py-2 rounded-lg font-medium flex items-center gap-2 <?php echo $filter_type === 'nurse' ? 'active' : ''; ?>">
                    <i class="bi bi-heart-pulse"></i> Nurse
                    <span class="bg-white/20 px-2 py-0.5 rounded-full text-xs"><?php echo $counts['nurse']; ?></span>
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
                ?>
                    <div class="border-l-4 border-orange-500 bg-gradient-to-r from-orange-50 to-red-50 rounded-lg p-6 hover:shadow-lg transition-all duration-200">
                        <div class="flex items-start justify-between mb-4">
                            <div class="flex items-center gap-3">
                                <div class="<?php echo $type_badge_class; ?> px-4 py-2 rounded-full text-sm font-semibold flex items-center gap-2">
                                    <i class="bi <?php echo $type_icon; ?>"></i>
                                    <?php echo ucfirst($diagnosis['diagnosis_type']); ?> Diagnosis
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
                                <span class="font-semibold text-gray-700">Provider:</span>
                                <span class="text-gray-900 ml-2"><?php echo htmlspecialchars($diagnosis['provider_full_name'] ?? $diagnosis['provider_name']); ?></span>
                                <?php if ($diagnosis['provider_role']): ?>
                                    <span class="text-gray-600 text-sm ml-2">(<?php echo htmlspecialchars($diagnosis['provider_role']); ?>)</span>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($diagnosis['chief_complaint']): ?>
                                <div>
                                    <span class="font-semibold text-gray-700">Chief Complaint:</span>
                                    <p class="text-gray-900 mt-1 bg-white p-3 rounded"><?php echo nl2br(htmlspecialchars($diagnosis['chief_complaint'])); ?></p>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($diagnosis['assessment']): ?>
                                <div>
                                    <span class="font-semibold text-gray-700">Assessment/Diagnosis:</span>
                                    <p class="text-gray-900 mt-1 bg-white p-3 rounded border-l-4 border-orange-500 font-medium">
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
                            No <?php echo ucfirst($filter_type); ?> diagnoses found for your account.
                        <?php else: ?>
                            You don't have any diagnoses yet. Diagnoses from doctors, dentists, or nurses will appear here after your clinic visit.
                        <?php endif; ?>
                    </p>
                    <?php if ($filter_type !== 'all'): ?>
                        <a href="?filter=all" class="red-orange-gradient-button text-white px-4 py-2 rounded-lg font-medium inline-block">
                            View All Diagnoses
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <footer class="red-orange-gradient text-white py-4 mt-8">
        <div class="max-w-7xl mx-auto px-6 text-center">
            <small>&copy; <?php echo date('Y'); ?> Batangas State University - Clinic Record Management System</small>
        </div>
    </footer>
</body>
</html>

