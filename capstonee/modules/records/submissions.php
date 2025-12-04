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

$success_message = '';
$error_message = '';
$type = $_GET['type'] ?? '';
$id = $_GET['id'] ?? '';
$record = null;
$records = null;

// Get current user role
$user_role = $_SESSION['role'] ?? 'user';

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
    'nurse' => ['history_form', 'medical_exam', 'dental_exam'], // Nurse can see all
    'doctor' => ['medical_exam'], // Doctor only sees medical exams
    'dentist' => ['dental_exam'], // Dentist only sees dental exams
    'staff' => ['history_form', 'medical_exam', 'dental_exam'], // Staff can see all
    'admin' => ['history_form', 'medical_exam', 'dental_exam']  // Admin can see all
];

// Get allowed types for current user (for viewing in list)
$allowed_types = $role_allowed_types[$user_role] ?? [];

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

// ✅ Fetch a single record (for detailed review)
if ($type && $id) {
    // ALLOW ALL ROLES TO VIEW ANY RECORD DETAILS (no restrictions on viewing)
    if (isset($form_map[$type])) {
        $table = $form_map[$type]['table'];
        $query = "
            SELECT f.*, mr.*, p.*
            FROM $table f
            JOIN medical_records mr ON f.record_id = mr.id
            JOIN patients p ON mr.patient_id = p.id
            WHERE f.record_id = ?
        ";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $record = $stmt->get_result()->fetch_assoc();

        if (!$record) {
            $error_message = "No record found for this submission.";
        }
    } else {
        $error_message = "Invalid form type.";
    }
} else {
    // ✅ Fetch all or filtered submissions based on user role (only for LIST view)
    $filter_sql = "WHERE mr.record_type IN ('" . implode("','", $allowed_types) . "')";
    
    if ($type && isset($form_map[$type])) {
        // Check if the requested type is allowed for this user to SEE in list
        if (in_array($form_map[$type]['record_type'], $allowed_types)) {
            $db_type = $form_map[$type]['record_type'];
            $filter_sql .= " AND mr.record_type = '$db_type'";
        } else {
            $error_message = "You are not authorized to view this type of record in the list.";
            $filter_sql .= " AND 1=0"; // Force no results
        }
    }

    // Add filter to show only verified forms
    $filter_sql .= " AND mr.verification_status = 'verified'";

    $records = $conn->query("
        SELECT mr.id, mr.record_type, mr.examination_date, mr.verification_status,
               p.first_name, p.last_name, p.student_id
        FROM medical_records mr
        JOIN patients p ON mr.patient_id = p.id
        $filter_sql
        ORDER BY mr.created_at DESC
    ");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Submissions - BSU Clinic</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="icon" type="image/png" href="../../assets/css/images/logo-bsu.png">
    <style>
        .red-orange-gradient {
            background: linear-gradient(135deg, #dc2626, #ea580c, #f97316);
        }
        
        .red-orange-gradient-light {
            background: linear-gradient(135deg, #fef2f2, #ffedd5, #fed7aa);
        }
        
        .red-orange-gradient-button {
            background: linear-gradient(135deg, #dc2626, #ea580c);
        }
        
        .red-orange-gradient-button:hover {
            background: linear-gradient(135deg, #b91c1c, #c2410c);
        }
        
        .red-orange-alert {
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
        
        .filter-button {
            background: linear-gradient(135deg, #ea580c, #f97316);
        }
        
        .filter-button:hover {
            background: linear-gradient(135deg, #c2410c, #ea580c);
        }
        
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
    </style>
</head>
<body class="bg-gradient-to-br from-orange-50 to-red-50">

    <!-- HEADER (same style as QR Scan / patient pages) -->
    <header class="red-orange-gradient text-white shadow-md sticky top-0 z-50">
        <div class="max-w-7xl mx-auto flex items-center justify-between px-6 py-3">
            <div class="flex items-center gap-3">
                <img src="../../assets/css/images/logo-bsu.png" alt="BSU Logo" class="w-12 h-12 rounded-full object-cover border-4 border-white bg-white">
                <h1 class="text-lg font-bold">BSU Clinic Record Management System</h1>
            </div>
            <nav class="flex items-center gap-6">
                <a href="<?php echo $dashboard_url; ?>" class="hover:text-yellow-200 flex items-center gap-1">
                    <i class="bi bi-speedometer2"></i> Dashboard
                </a>
                <a href="../../logout.php" class="red-orange-gradient-button text-white px-3 py-1 rounded-lg font-semibold hover:shadow-lg flex items-center gap-1">
                    <i class="bi bi-box-arrow-right"></i> Logout
                </a>
            </nav>
        </div>
    </header>

<div class="min-h-screen py-10 px-6 pt-20">
    <div class="max-w-6xl mx-auto bg-white shadow-lg rounded-lg p-8">
        
        <!-- Header -->
        <div class="flex justify-between items-center mb-6">
            <a href="<?php echo $dashboard_url; ?>"
               class="inline-flex items-center gap-2 red-orange-gradient-button text-white font-semibold px-4 py-2 rounded-lg shadow hover:shadow-lg transition-all">
               <i class="bi bi-arrow-left-circle"></i> Back to Dashboard
            </a>
            <div class="text-right">
                <h2 class="text-2xl font-bold text-orange-700">Consult</h2>
                <span class="px-3 py-1 rounded-full text-sm font-semibold mt-1 inline-block role-badge role-badge-<?= $user_role ?>">
                    <i class="bi bi-person-check"></i> <?= ucfirst($user_role) ?> Mode
                </span>
            </div>
        </div>

        <!-- Notifications -->
        <?php if ($success_message): ?>
            <div class="mb-4 p-3 bg-green-100 text-green-800 text-center rounded font-semibold border border-green-300">
                <?= $success_message ?>
            </div>
        <?php elseif ($error_message): ?>
            <div class="mb-4 p-3 bg-red-100 text-red-800 text-center rounded font-semibold border border-red-300">
                <?= $error_message ?>
            </div>
        <?php endif; ?>

        <!-- ✅ Single Record Review -->
        <?php if ($record): ?>
            <div class="red-orange-alert rounded-lg p-4 mb-6 border-l-4 border-orange-500">
                <h3 class="font-semibold text-lg mb-3 text-orange-800">Patient Information</h3>
                <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                    <!-- Basic Patient Info Only -->
                    <p><strong>Student ID:</strong> <?= htmlspecialchars($record['student_id'] ?? '') ?></p>
                    <p><strong>Name:</strong> <?= htmlspecialchars(($record['last_name'] ?? '') . ', ' . ($record['first_name'] ?? '') . ' ' . ($record['middle_name'] ?? '')) ?></p>
                    <p><strong>Program:</strong> <?= htmlspecialchars($record['program'] ?? '') ?></p>
                    <p><strong>Year Level:</strong> <?= htmlspecialchars($record['year_level'] ?? '') ?></p>
                    <p><strong>Sex:</strong> <?= htmlspecialchars($record['sex'] ?? '') ?></p>
                    <p><strong>Date of Birth:</strong> <?= htmlspecialchars($record['date_of_birth'] ?? '') ?></p>
                </div>
            </div>

            <div class="bg-green-50 rounded-lg p-4 mb-6 border-l-4 border-green-500">
                <h3 class="font-semibold text-lg mb-3 text-green-800">Form Summary</h3>
                <div class="space-y-3">
                    <?php
                    // Define which fields to show based on form type
                    $important_fields = [];
                    
                    switch($type) {
                        case 'history_form':
                        case 'history_exam':
                            $important_fields = [
                                'sports_intended' => 'Sports Intended',
                                'previous_sports' => 'Previous Sports',
                                'medical_history' => 'Medical History',
                                'family_history' => 'Family History',
                                'allergies' => 'Allergies',
                                'current_medications' => 'Current Medications'
                            ];
                            break;
                            
                        case 'medical_form':
                        case 'medical_exam':
                            $important_fields = [
                                'blood_pressure' => 'Blood Pressure',
                                'heart_rate' => 'Heart Rate',
                                'respiratory_rate' => 'Respiratory Rate',
                                'temperature' => 'Temperature',
                                'height' => 'Height',
                                'weight' => 'Weight',
                                'bmi' => 'BMI',
                                'vision_left' => 'Vision Left',
                                'vision_right' => 'Vision Right',
                                'physical_findings' => 'Physical Findings'
                            ];
                            break;
                            
                        case 'dental_form':
                        case 'dental_exam':
                            $important_fields = [
                                'oral_hygiene' => 'Oral Hygiene',
                                'gingival_condition' => 'Gingival Condition',
                                'occlusion' => 'Occlusion',
                                'oral_prophylaxis' => 'Oral Prophylaxis',
                                'restoration' => 'Restoration Needed',
                                'extraction' => 'Extraction Needed',
                                'prosthetic' => 'Prosthetic Needed',
                                'orthodontic' => 'Orthodontic Treatment',
                                'periodontal' => 'Periodontal Treatment'
                            ];
                            break;
                    }
                    
                    // Display only important fields
                    foreach($important_fields as $field => $label): 
                        if (isset($record[$field]) && !empty($record[$field])): 
                    ?>
                        <div class="flex justify-between border-b pb-2">
                            <span class="font-medium text-gray-700"><?= $label ?>:</span>
                            <span class="text-gray-900"><?= htmlspecialchars($record[$field]) ?></span>
                        </div>
                    <?php 
                        endif;
                    endforeach; 
                    ?>
                    
                    <!-- Examination Date -->
                    <?php if (isset($record['examination_date']) && !empty($record['examination_date'])): ?>
                        <div class="flex justify-between border-b pb-2">
                            <span class="font-medium text-gray-700">Examination Date:</span>
                            <span class="text-gray-900"><?= htmlspecialchars($record['examination_date']) ?></span>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Physician/Dentist Notes if available -->
                    <?php if (isset($record['physician_notes']) && !empty($record['physician_notes'])): ?>
                        <div class="mt-4">
                            <span class="font-medium text-gray-700 block mb-2">Physician Notes:</span>
                            <p class="text-gray-900 bg-white p-3 rounded border"><?= htmlspecialchars($record['physician_notes']) ?></p>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (isset($record['dentist_notes']) && !empty($record['dentist_notes'])): ?>
                        <div class="mt-4">
                            <span class="font-medium text-gray-700 block mb-2">Dentist Notes:</span>
                            <p class="text-gray-900 bg-white p-3 rounded border"><?= htmlspecialchars($record['dentist_notes']) ?></p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Current Status -->
                <div class="mt-4 pt-4 border-t">
                    <p class="font-medium text-gray-700">Current Status:</p>
                    <span class="px-3 py-1 rounded-full text-sm font-semibold mt-2 inline-block
                        <?= $record['verification_status'] === 'verified' ? 'red-orange-badge-verified' :
                            ($record['verification_status'] === 'rejected' ? 'red-orange-badge-rejected' : 'red-orange-badge-pending'); ?>">
                        <?= strtoupper($record['verification_status']); ?>
                    </span>
                </div>
            </div>

            <!-- ✅ Action Buttons - ALL ROLES CAN VERIFY ANY FORM -->
            <form method="POST" class="flex justify-center gap-4">
                <input type="hidden" name="record_id" value="<?= $record['record_id']; ?>">
                <input type="hidden" name="record_type" value="<?= $type; ?>">

                <button type="submit" name="action" value="verified"
                        class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded-lg shadow font-semibold transition-all flex items-center gap-2">
                    <i class="bi bi-check-circle"></i> Verify Submission
                </button>
                <button type="submit" name="action" value="rejected"
                        class="bg-red-600 hover:bg-red-700 text-white px-6 py-2 rounded-lg shadow font-semibold transition-all flex items-center gap-2">
                    <i class="bi bi-x-circle"></i> Reject Submission
                </button>
                <a href="submissions.php?type=<?= $type ?>"
                   class="bg-gray-500 hover:bg-gray-600 text-white px-6 py-2 rounded-lg shadow font-semibold transition-all flex items-center gap-2">
                   <i class="bi bi-arrow-left"></i> Back to List
                </a>
            </form>

        <!-- ✅ All Submissions List -->
        <?php elseif ($records && $records->num_rows > 0): ?>
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-semibold text-orange-800">
                    <?= $type ? ucfirst(str_replace('_',' ', $type)) . ' Submissions' : 'All Submissions'; ?>
                    <span class="text-sm font-normal text-gray-600 ml-2">
                        (<?= $records->num_rows ?> record<?= $records->num_rows !== 1 ? 's' : '' ?>)
                    </span>
                </h3>
                <div class="flex gap-2">
                    <?php if (in_array('history_form', $allowed_types)): ?>
                        <a href="submissions.php?type=history_form" class="filter-button text-white text-sm font-semibold px-4 py-2 rounded hover:shadow transition-all">History Forms</a>
                    <?php endif; ?>
                    <?php if (in_array('medical_exam', $allowed_types)): ?>
                        <a href="submissions.php?type=medical_form" class="filter-button text-white text-sm font-semibold px-4 py-2 rounded hover:shadow transition-all">Medical Exams</a>
                    <?php endif; ?>
                    <?php if (in_array('dental_exam', $allowed_types)): ?>
                        <a href="submissions.php?type=dental_form" class="filter-button text-white text-sm font-semibold px-4 py-2 rounded hover:shadow transition-all">Dental Exams</a>
                    <?php endif; ?>
                    <?php if (count($allowed_types) > 1): ?>
                        <a href="submissions.php" class="bg-gray-500 hover:bg-gray-600 text-white text-sm font-semibold px-4 py-2 rounded hover:shadow transition-all">All Submissions</a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full border border-orange-200 text-sm rounded-lg overflow-hidden">
                    <thead class="red-orange-table-header text-white">
                        <tr>
                            <th class="py-3 px-4">Student ID</th>
                            <th class="py-3 px-4">Name</th>
                            <th class="py-3 px-4">Form Type</th>
                            <th class="py-3 px-4">Submission Date</th>
                            <th class="py-3 px-4">Status</th>
                            <th class="py-3 px-4">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-orange-100">
                        <?php while ($r = $records->fetch_assoc()): ?>
                            <tr class="red-orange-table-row hover:shadow transition-all duration-200">
                                <td class="py-3 px-4 font-medium"><?= htmlspecialchars($r['student_id']); ?></td>
                                <td class="py-3 px-4"><?= htmlspecialchars($r['last_name'] . ', ' . $r['first_name']); ?></td>
                                <td class="py-3 px-4">
                                    <span class="px-2 py-1 bg-orange-100 text-orange-800 rounded text-xs font-medium">
                                        <?= ucfirst(str_replace('_', ' ', $r['record_type'])); ?>
                                    </span>
                                </td>
                                <td class="py-3 px-4"><?= date('M j, Y', strtotime($r['examination_date'])); ?></td>
                                <td class="py-3 px-4">
                                    <span class="px-2 py-1 rounded-full text-xs font-semibold
                                        <?= $r['verification_status'] === 'verified' ? 'red-orange-badge-verified' :
                                            ($r['verification_status'] === 'rejected' ? 'red-orange-badge-rejected' : 'red-orange-badge-pending'); ?>">
                                        <?= strtoupper($r['verification_status']); ?>
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
                                    <a href="submissions.php?type=<?= $formType; ?>&id=<?= $r['id']; ?>"
                                       class="inline-flex items-center gap-1 px-3 py-1 red-orange-gradient-button text-white rounded hover:shadow text-xs font-semibold transition-all">
                                       <i class="bi bi-eye"></i> Review & Verify
                                    </a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

        <?php else: ?>
            <div class="text-center py-8">
                <i class="bi bi-inbox text-4xl text-orange-400 mb-4"></i>
                <p class="text-orange-600 text-lg">No submissions found for verification.</p>
                <p class="text-orange-500 text-sm mt-2">
                    <?php if ($type): ?>
                        No <?= str_replace('_', ' ', $type) ?> submissions found.
                    <?php else: ?>
                        No submissions found for your assigned record types.
                    <?php endif; ?>
                </p>
            </div>
        <?php endif; ?>
    </div>
</div>

</body>
</html>