<?php
// Navigation Component - Unified navigation bar for all pages
// This file should be included after session_start() and database connection

// Determine base path based on current file location
$current_file = $_SERVER['PHP_SELF'];
$base_path = '';

// Calculate relative path to root
if (strpos($current_file, '/modules/') !== false) {
    $base_path = '../../';
} elseif (strpos($current_file, '/includes/') !== false) {
    $base_path = '../';
} else {
    $base_path = '';
}

// Get user role and determine dashboard URL
$user_role = $_SESSION['role'] ?? 'user';
$dashboard_url = $base_path . 'dashboard.php';

// Role-specific dashboard URLs
$role_dashboards = [
    'doctor' => $base_path . 'doctor_dashboard.php',
    'dentist' => $base_path . 'dentist_dashboard.php',
    'nurse' => $base_path . 'nurse_dashboard.php',
    'staff' => $base_path . 'msa_dashboard.php',
    'user' => $base_path . 'user_dashboard.php',
    'student' => $base_path . 'user_dashboard.php'
];

if (isset($role_dashboards[$user_role])) {
    $dashboard_url = $role_dashboards[$user_role];
}

// Get user display name - with error handling
$display_name = $_SESSION['username'] ?? 'User';
if (isset($_SESSION['user_id']) && isset($conn)) {
    try {
        $user_id = $_SESSION['user_id'];
        $user_query = $conn->query("SELECT full_name FROM users WHERE id = $user_id");
        if ($user_query && $user_query->num_rows > 0) {
            $user_data = $user_query->fetch_assoc();
            if (!empty($user_data['full_name'])) {
                $display_name = trim($user_data['full_name']);
            }
        }
    } catch (Exception $e) {
        // If query fails, just use username - don't break the navigation
        error_log("Navigation error: " . $e->getMessage());
    }
}
?>

<header class="red-orange-gradient text-white shadow-md sticky top-0 z-50" style="display: block !important; visibility: visible !important; position: sticky !important; width: 100% !important;">
    <div class="max-w-7xl mx-auto flex items-center justify-between px-6 py-3">
        <div class="flex items-center gap-3">
            <a href="<?php echo $dashboard_url; ?>" class="flex items-center gap-3 hover:opacity-90 transition-opacity">
                <img src="<?php echo $base_path; ?>assets/css/images/logo-bsu.png" alt="BSU Logo" class="w-12 h-12 rounded-full object-cover border-4 border-white bg-white">
                <h1 class="text-lg font-bold">BSU Clinic Record Management System</h1>
            </a>
        </div>
        <nav class="flex items-center gap-6">
            <!-- Dashboard Link -->
            <a href="<?php echo $dashboard_url; ?>" class="hover:text-yellow-200 flex items-center gap-1 font-semibold transition-colors">
                <i class="bi bi-speedometer2"></i> Dashboard
            </a>
            
            <!-- Logout Button -->
            <a href="<?php echo $base_path; ?>logout.php" class="red-orange-gradient-button text-white px-3 py-1 rounded-lg font-semibold hover:shadow-lg flex items-center gap-1 transition-all">
                <i class="bi bi-box-arrow-right"></i> Logout
            </a>
        </nav>
    </div>
</header>

<style>
    /* Red-orange gradient button */
    .red-orange-gradient-button {
        background: linear-gradient(135deg, #dc2626, #ea580c);
    }
    
    .red-orange-gradient-button:hover {
        background: linear-gradient(135deg, #b91c1c, #c2410c);
    }
    
    /* Red-orange gradient header */
    .red-orange-gradient {
        background: linear-gradient(135deg, #dc2626, #ea580c, #f97316);
    }
</style>

