<?php
// Calculate base path dynamically based on where this file is included from
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
?>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm rounded-bottom">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center" href="<?php echo $base_path; ?>index.php">
            <img src="<?php echo $base_path; ?>assets/img/logo.png" alt="Logo" style="height:40px; margin-right:10px;">
            BSU Clinic Records
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo $base_path; ?>dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
                </li>
                <!-- Records Menu -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="recordsDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-folder"></i> Records
                    </a>
                    <ul class="dropdown-menu" aria-labelledby="recordsDropdown">
                        <li><a class="dropdown-item" href="<?php echo $base_path; ?>modules/records/patients.php">All Patients</a></li>
                        <li><a class="dropdown-item" href="<?php echo $base_path; ?>modules/records/add_patient.php">Add New Patient</a></li>
                    </ul>
                </li>
                <!-- Forms Menu -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="formsDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-file-earmark-text"></i> Forms
                    </a>
                    <ul class="dropdown-menu" aria-labelledby="formsDropdown">
                        <li><a class="dropdown-item" href="<?php echo $base_path; ?>modules/forms/history_form.php">Medical History</a></li>
                        <li><a class="dropdown-item" href="<?php echo $base_path; ?>modules/forms/dental_form.php">Dental Examination</a></li>
                        <li><a class="dropdown-item" href="<?php echo $base_path; ?>modules/forms/medical_form.php">Medical Examination</a></li>
                    </ul>
                </li>
                <!-- Analytics -->
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo $base_path; ?>modules/analytics/dashboard.php"><i class="bi bi-graph-up"></i> Analytics</a>
                </li>
                <!-- Admin Panel (only for admin users) -->
                <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo $base_path; ?>admin_panel.php"><i class="bi bi-gear-fill"></i> Admin Panel</a>
                </li>
                <?php endif; ?>
            </ul>
            <!-- User Menu -->
            <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-person-circle"></i> <?php echo isset($_SESSION['username']) ? htmlspecialchars($_SESSION['username']) : 'User'; ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                        <li><a class="dropdown-item" href="#"><i class="bi bi-person"></i> Profile</a></li>
                        <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                        <li><a class="dropdown-item" href="<?php echo $base_path; ?>admin_panel.php"><i class="bi bi-gear-fill"></i> Admin Panel</a></li>
                        <?php endif; ?>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?php echo $base_path; ?>logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>