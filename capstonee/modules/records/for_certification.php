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
    } elseif ($_SESSION['role'] === 'staff') {
        $dashboard_url = '../../msa_dashboard.php';
    } elseif ($_SESSION['role'] === 'nurse') {
        $dashboard_url = '../../nurse_dashboard.php';
    }
}

// Initialize variables with default values
$success_message = isset($success_message) ? $success_message : '';
$error_message = isset($error_message) ? $error_message : '';
$type = isset($_GET['type']) ? $_GET['type'] : '';
$id = isset($_GET['id']) ? $_GET['id'] : '';
$record = null;
$records = null;

// Get current user role
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : 'user';

// ✅ Map both "form" and "exam" types to real table names
$form_map = [
    'history_form' => ['table' => 'history_forms', 'record_type' => 'history_form'],
    'history_exam' => ['table' => 'history_forms', 'record_type' => 'history_form'],

    'medical_form' => ['table' => 'medical_exams', 'record_type' => 'medical_exam'],
    'medical_exam' => ['table' => 'medical_exams', 'record_type' => 'medical_exam'],

    'dental_form'  => ['table' => 'dental_exams', 'record_type' => 'dental_exam'],
    'dental_exam'  => ['table' => 'dental_exams', 'record_type' => 'dental_exam']
];

// ✅ Define allowed record types for each role (what they can SEE in the list)
$role_allowed_types = [
    'nurse' => ['history_form', 'medical_exam'],
    'doctor' => ['history_form', 'medical_exam'],
    'dentist' => ['dental_exam'],
    'staff' => ['history_form', 'medical_exam', 'dental_exam'],
    'admin' => ['history_form', 'medical_exam', 'dental_exam']
];

// Get allowed types for current user (for viewing in list)
$allowed_types = isset($role_allowed_types[$user_role]) ? $role_allowed_types[$user_role] : [];

// ✅ Handle Verify / Reject
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action'], $_POST['record_id'], $_POST['record_type'])) {
    $record_id = $_POST['record_id'];
    $record_type = $_POST['record_type'];
    $action = $_POST['action'];

    // ALLOW ALL ROLES TO VERIFY ANY FORM TYPE (no restrictions on verification)
    if (in_array($action, ['verified', 'rejected'])) {
        if (isset($form_map[$record_type])) {
            $table = $form_map[$record_type]['table'];

            // Update both main and specific form table
            $conn->query("UPDATE medical_records SET verification_status='$action' WHERE id='$record_id'");
            $conn->query("UPDATE $table SET verification_status='$action' WHERE record_id='$record_id'");

            $success_message = "Record #$record_id has been marked as " . strtoupper($action) . ".";
        } else {
            $error_message = "Invalid form type during verification.";
        }
    }
}

// ✅ Fetch all or filtered submissions based on user role (only for LIST view)
$filter_sql = "WHERE mr.record_type IN ('" . implode("','", $allowed_types) . "')";

if ($type && isset($form_map[$type])) {
    // Check if the requested type is allowed for this user to SEE in list
    if (in_array($form_map[$type]['record_type'], $allowed_types)) {
        $db_type = $form_map[$type]['record_type'];
        $filter_sql .= " AND mr.record_type = '$db_type'";
    } else {
        $error_message = "You are not authorized to view this type of record in the list.";
        $filter_sql .= " AND 1=0";
    }
}

// Add filter to show only forms marked for certification
$filter_sql .= " AND mr.verification_status = 'for_certification'";

$records = $conn->query("
    SELECT mr.id, mr.record_type, mr.examination_date, mr.verification_status,
           p.first_name, p.last_name, p.student_id
    FROM medical_records mr
    JOIN patients p ON mr.patient_id = p.id
    $filter_sql
    ORDER BY mr.created_at DESC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>For Certification - BSU Clinic</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="icon" type="image/png" href="../../assets/css/images/logo-bsu.png">
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
        
        .maroon-badge-for-cert {
            background: linear-gradient(135deg, #ffedd5, #fed7aa);
            color: #9a3412;
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
        
        /* Role badges */
        .role-badge {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            color: white;
        }
        
        .role-badge-nurse {
            background: linear-gradient(135deg, #ec4899, #be185d);
        }
        
        .role-badge-doctor {
            background: linear-gradient(135deg, #10b981, #047857);
        }
        
        .role-badge-dentist {
            background: linear-gradient(135deg, #f59e0b, #d97706);
        }
        
        .role-badge-staff {
            background: linear-gradient(135deg, #6b7280, #374151);
        }
        
        .filter-button {
            background: linear-gradient(135deg, var(--maroon-light), #cc0000);
        }
        
        .filter-button:hover {
            background: linear-gradient(135deg, var(--maroon-primary), var(--maroon-light));
        }
        
        .btn-disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        /* Pulsing badge animation */
        @keyframes pulse-badge {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }
        
        .pulse-badge {
            animation: pulse-badge 2s infinite;
        }
    </style>
</head>
<body class="bg-gradient-to-br from-red-50 to-pink-50">

<div class="min-h-screen py-6 px-4 pt-2">
    <div class="max-w-7xl mx-auto bg-white shadow-lg rounded-lg p-6">
        
        <!-- Header - Removed back button as requested -->
        <div class="flex justify-between items-center mb-6">
            <div>
                <h2 class="text-2xl font-bold text-maroon">For Certification</h2>
                <span class="px-3 py-1 rounded-full text-sm font-semibold mt-1 inline-block role-badge role-badge-<?php echo isset($user_role) ? $user_role : 'user'; ?>">
                    <i class="bi bi-person-check"></i> <?php echo isset($user_role) ? ucfirst($user_role) : 'User'; ?> Mode
                </span>
            </div>
            <!-- No back button as requested -->
        </div>

        <!-- Notifications -->
        <?php if (isset($success_message) && !empty($success_message)): ?>
            <div class="mb-4 p-3 bg-green-100 text-green-800 text-center rounded font-semibold border border-green-300">
                <?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php elseif (isset($error_message) && !empty($error_message)): ?>
            <div class="mb-4 p-3 bg-red-100 text-red-800 text-center rounded font-semibold border border-red-300">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <!-- ✅ All Submissions List -->
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-semibold text-maroon">
                <?php 
                echo $type ? ucfirst(str_replace('_',' ', $type)) . ' for Certification' : 'All for Certification'; 
                ?>
                <span class="text-sm font-normal text-gray-600 ml-2">
                    <?php if (isset($records) && $records && $records->num_rows > 0): ?>
                        (<?php echo $records->num_rows; ?> record<?php echo $records->num_rows !== 1 ? 's' : ''; ?>)
                    <?php else: ?>
                        (0 records)
                    <?php endif; ?>
                </span>
            </h3>
            <div class="flex gap-2">
                <?php if (isset($allowed_types) && in_array('history_form', $allowed_types)): ?>
                    <a href="for_certification.php?type=history_form" class="filter-button text-white text-sm font-semibold px-4 py-2 rounded hover:shadow transition-all">History Forms</a>
                <?php endif; ?>
                <?php if (isset($allowed_types) && in_array('medical_exam', $allowed_types)): ?>
                    <a href="for_certification.php?type=medical_form" class="filter-button text-white text-sm font-semibold px-4 py-2 rounded hover:shadow transition-all">Medical Exams</a>
                <?php endif; ?>
                <?php if (isset($allowed_types) && in_array('dental_exam', $allowed_types)): ?>
                    <a href="for_certification.php?type=dental_form" class="filter-button text-white text-sm font-semibold px-4 py-2 rounded hover:shadow transition-all">Dental Exams</a>
                <?php endif; ?>
                <?php if (isset($allowed_types) && count($allowed_types) > 1): ?>
                    <a href="for_certification.php" class="bg-gray-500 hover:bg-gray-600 text-white text-sm font-semibold px-4 py-2 rounded hover:shadow transition-all">All Forms</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if (isset($records) && $records && $records->num_rows > 0): ?>
            <div class="overflow-x-auto">
                <table class="min-w-full border border-red-200 text-sm rounded-lg overflow-hidden">
                    <thead class="maroon-table-header text-white">
                        <tr>
                            <th class="py-3 px-4">Student ID</th>
                            <th class="py-3 px-4">Name</th>
                            <th class="py-3 px-4">Form Type</th>
                            <th class="py-3 px-4">Submission Date</th>
                            <th class="py-3 px-4">Status</th>
                            <th class="py-3 px-4">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-red-100">
                        <?php while ($r = $records->fetch_assoc()): ?>
                            <tr class="maroon-table-row hover:shadow transition-all duration-200">
                                <td class="py-3 px-4 font-medium"><?php echo htmlspecialchars($r['student_id']); ?></td>
                                <td class="py-3 px-4"><?php echo htmlspecialchars($r['last_name'] . ', ' . $r['first_name']); ?></td>
                                <td class="py-3 px-4">
                                    <span class="px-2 py-1 bg-red-100 text-red-800 rounded text-xs font-medium">
                                        <?php echo ucfirst(str_replace('_', ' ', $r['record_type'])); ?>
                                    </span>
                                </td>
                                <td class="py-3 px-4"><?php echo date('M j, Y', strtotime($r['examination_date'])); ?></td>
                                <td class="py-3 px-4">
                                    <span class="px-2 py-1 rounded-full text-xs font-semibold maroon-badge-for-cert">
                                        FOR CERTIFICATION
                                    </span>
                                </td>
                                <td class="py-3 px-4">
                                    <?php
                                    // Map database record_type to the correct form type name for the URL
                                    $formType = match($r['record_type']) {
                                        'history_form' => 'history_form',
                                        'medical_exam' => 'medical_form',
                                        'dental_exam'  => 'dental_form',
                                        default => 'history_form'
                                    };
                                    ?>
                                    <a href="../../modules/records/view_record.php?type=<?php echo $formType; ?>&id=<?php echo $r['id']; ?>"
                                       class="inline-flex items-center gap-1 px-3 py-1 maroon-gradient-button text-white rounded hover:shadow text-xs font-semibold transition-all">
                                       <i class="bi bi-award"></i> Certify
                                    </a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

        <?php else: ?>
            <!-- Empty state message -->
            <div class="text-center py-8">
                <i class="bi bi-inbox text-4xl text-red-400 mb-4"></i>
                <p class="text-red-600 text-lg">No submissions found for certification.</p>
                <p class="text-red-500 text-sm mt-2">
                    <?php if ($type): ?>
                        No <?php echo str_replace('_', ' ', $type); ?> submissions marked for certification.
                    <?php else: ?>
                        No submissions marked for certification.
                    <?php endif; ?>
                </p>
            </div>
        <?php endif; ?>
    </div>
</div>

</body>
</html>