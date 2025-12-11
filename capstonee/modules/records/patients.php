<?php
session_start();
include '../../config/database.php';

// Set timezone to Philippines
date_default_timezone_set('Asia/Manila');

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

// Check if user is nurse (or other authorized roles)
$allowed_roles = ['nurse', 'doctor', 'dentist', 'staff', 'admin'];
if (!in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: ../../dashboard.php");
    exit();
}

$success_message = '';
$error_message = '';

// Get current user role
$user_role = $_SESSION['role'] ?? 'user';

// Get user information for display
$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT username, role, full_name, email FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user_profile = $result->fetch_assoc();

// Determine display name
$display_name = !empty($user_profile['full_name']) ? trim($user_profile['full_name']) : $user_profile['username'];

// Delete patient if requested
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $patient_id = $_GET['delete'];

    // Start transaction for safety
    $conn->begin_transaction();

    try {
        // Delete related medical records first
        $stmt = $conn->prepare("DELETE FROM medical_records WHERE patient_id = ?");
        $stmt->bind_param("i", $patient_id);
        $stmt->execute();

        // Delete patient
        $stmt = $conn->prepare("DELETE FROM patients WHERE id = ?");
        $stmt->bind_param("i", $patient_id);

        if ($stmt->execute()) {
            $conn->commit();
            $success_message = "Patient and all related medical records deleted successfully!";
        } else {
            $conn->rollback();
            $error_message = "Error deleting patient: " . $conn->error;
        }
    } catch (Exception $e) {
        $conn->rollback();
        $error_message = "Error deleting patient: " . $e->getMessage();
    }
}

// Get search parameters
$search = isset($_GET['search']) ? $_GET['search'] : '';
$search_field = isset($_GET['search_field']) ? $_GET['search_field'] : 'all';

// Prepare base query with role-based filtering
$query = "SELECT DISTINCT p.* FROM patients p WHERE 1=1";

// Add role-based filtering
if ($user_role === 'doctor') {
    // Doctor: Show patients with medical exams OR history forms
    $query .= " AND EXISTS (SELECT 1 FROM medical_records mr 
               WHERE mr.patient_id = p.id 
               AND (mr.record_type = 'medical_exam' OR mr.record_type = 'history_form'))";
} elseif ($user_role === 'dentist') {
    // Dentist: Only show patients with dental exams
    $query .= " AND EXISTS (SELECT 1 FROM medical_records mr 
               JOIN dental_exams de ON mr.id = de.record_id 
               WHERE mr.patient_id = p.id AND mr.record_type = 'dental_exam')";
}
// Nurse, Staff, Admin can see all patients (no additional filtering)

// Add search conditions
if (!empty($search)) {
    switch ($search_field) {
        case 'student_id':
            $query .= " AND p.student_id LIKE ?";
            $search_param = "%$search%";
            break;
        case 'name':
            $query .= " AND (p.first_name LIKE ? OR p.middle_name LIKE ? OR p.last_name LIKE ?)";
            $search_param = "%$search%";
            break;
        case 'program':
            $query .= " AND p.program LIKE ?";
            $search_param = "%$search%";
            break;
        default:
            $query .= " AND (p.student_id LIKE ? OR p.first_name LIKE ? OR p.middle_name LIKE ? OR p.last_name LIKE ? OR p.program LIKE ?)";
            $search_param = "%$search%";
            break;
    }
}

// Add sorting
$query .= " ORDER BY p.last_name, p.first_name";

// Prepare and execute the query
$stmt = $conn->prepare($query);

if (!empty($search)) {
    switch ($search_field) {
        case 'student_id':
            $stmt->bind_param("s", $search_param);
            break;
        case 'name':
            $stmt->bind_param("sss", $search_param, $search_param, $search_param);
            break;
        case 'program':
            $stmt->bind_param("s", $search_param);
            break;
        default:
            $stmt->bind_param("sssss", $search_param, $search_param, $search_param, $search_param, $search_param);
            break;
    }
}

$stmt->execute();
$result = $stmt->get_result();
$patients = [];

while ($row = $result->fetch_assoc()) {
    $patients[] = $row;
}

// Get counts for dashboard
$total_patients = $conn->query("SELECT COUNT(*) as count FROM patients")->fetch_assoc()['count'];
$pending_verifications = $conn->query("SELECT COUNT(*) as count FROM medical_records WHERE verification_status = 'pending'")->fetch_assoc()['count'];

// Check if this is an AJAX request (loaded in dashboard)
$is_ajax_request = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                   strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

if ($is_ajax_request) {
    // Return only the content for AJAX requests
    ob_start();
    ?>
    <div class="bg-white rounded-xl shadow-md p-6">
        <!-- Header with stats -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
            
            <div class="flex flex-col sm:flex-row gap-4">
                <a href="modules/records/add_patient.php" class="inline-flex items-center gap-2 maroon-gradient-button text-white px-4 py-2 rounded-lg font-semibold hover:shadow-lg transition-all text-sm">
                    <i class="bi bi-plus-circle"></i> Add New Patient
                </a>
                <div class="bg-maroon-light border border-maroon rounded-lg px-4 py-2 text-center">
                    <span class="text-sm text-gray-600">Total Patients:</span>
                    <span class="text-xl font-bold text-maroon block"><?php echo $total_patients; ?></span>
                </div>
            </div>
        </div>

        <?php if (!empty($success_message)): ?>
            <div class="mb-4 p-3 bg-green-100 text-green-800 rounded-lg font-semibold border border-green-300">
                <i class="bi bi-check-circle-fill mr-2"></i><?php echo $success_message; ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($error_message)): ?>
            <div class="mb-4 p-3 bg-red-100 text-red-800 rounded-lg font-semibold border border-red-300">
                <i class="bi bi-exclamation-triangle-fill mr-2"></i><?php echo $error_message; ?>
            </div>
        <?php endif; ?>
        
        <!-- Search Form -->
        <form method="GET" action="" class="mb-6 bg-maroon-light border border-maroon rounded-xl p-4">
            <div class="flex flex-col md:flex-row gap-4">
                <div class="flex-1">
                    <div class="flex">
                        <input type="text" name="search" placeholder="Search patients..." value="<?php echo htmlspecialchars($search); ?>" 
                               class="w-full rounded-l border border-maroon px-4 py-2 focus:outline-none focus:ring-2 focus:ring-maroon focus:border-maroon">
                        <button type="submit" class="maroon-gradient-button text-white px-4 py-2 rounded-r hover:shadow transition-all">
                            <i class="bi bi-search"></i>
                        </button>
                    </div>
                </div>
                <div class="w-full md:w-48">
                    <select name="search_field" class="w-full rounded border border-maroon px-4 py-2 focus:outline-none focus:ring-2 focus:ring-maroon focus:border-maroon bg-white">
                        <option value="all" <?php echo $search_field == 'all' ? 'selected' : ''; ?>>All Fields</option>
                        <option value="student_id" <?php echo $search_field == 'student_id' ? 'selected' : ''; ?>>Student ID</option>
                        <option value="name" <?php echo $search_field == 'name' ? 'selected' : ''; ?>>Name</option>
                        <option value="program" <?php echo $search_field == 'program' ? 'selected' : ''; ?>>Program</option>
                    </select>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="maroon-gradient-button text-white px-6 py-2 rounded hover:shadow transition-all font-semibold flex-1">
                        Search
                    </button>
                    <a href="?" class="bg-gray-200 text-gray-800 px-6 py-2 rounded hover:bg-gray-300 transition-all font-semibold text-center">
                        Reset
                    </a>
                </div>
            </div>
        </form>
        
        <!-- Patients Table -->
        <div class="overflow-x-auto rounded-lg border border-gray-200">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Student ID</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Name</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Sex</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Date of Birth</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Program</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Year Level</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-100">
                    <?php if (count($patients) > 0): ?>
                        <?php foreach ($patients as $patient): ?>
                            <tr class="hover:bg-gray-50 transition-all duration-200">
                                <td class="px-6 py-4 text-sm text-gray-900 font-medium"><?php echo htmlspecialchars($patient['student_id']); ?></td>
                                <td class="px-6 py-4 text-sm text-gray-900">
                                    <?php 
                                    echo htmlspecialchars($patient['last_name']) . ', ' . 
                                         htmlspecialchars($patient['first_name']) . ' ' . 
                                         htmlspecialchars($patient['middle_name']); 
                                    ?>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-900"><?php echo htmlspecialchars($patient['sex']); ?></td>
                                <td class="px-6 py-4 text-sm text-gray-900"><?php echo htmlspecialchars($patient['date_of_birth']); ?></td>
                                <td class="px-6 py-4 text-sm text-gray-900"><?php echo htmlspecialchars($patient['program']); ?></td>
                                <td class="px-6 py-4 text-sm text-gray-900"><?php echo htmlspecialchars($patient['year_level']); ?></td>
                                <td class="px-6 py-4 text-sm">
                                    <div class="flex gap-2">
                                        <a href="modules/records/view_patient.php?id=<?php echo $patient['id']; ?>" 
                                           class="inline-flex items-center justify-center bg-blue-500 text-white rounded px-3 py-2 text-sm hover:bg-blue-600 transition-all shadow" 
                                           title="View">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <a href="modules/records/edit_patient.php?id=<?php echo $patient['id']; ?>" 
                                           class="inline-flex items-center justify-center bg-yellow-500 text-white rounded px-3 py-2 text-sm hover:bg-yellow-600 transition-all shadow" 
                                           title="Edit">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <?php if (in_array($user_role, ['nurse', 'staff', 'admin', 'doctor', 'dentist'])): ?>
                                            <button onclick="confirmDelete(<?php echo $patient['id']; ?>, '<?php echo addslashes($patient['first_name'] . ' ' . $patient['last_name']); ?>')"
                                               class="inline-flex items-center justify-center bg-red-500 text-white rounded px-3 py-2 text-sm hover:bg-red-600 transition-all shadow" 
                                               title="Delete">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="px-6 py-8 text-center text-gray-500">
                                <div class="flex flex-col items-center">
                                    <div class="bg-gray-100 w-16 h-16 rounded-full flex items-center justify-center mb-3">
                                        <i class="bi bi-people text-gray-400 text-2xl"></i>
                                    </div>
                                    <?php if ($user_role === 'doctor'): ?>
                                        <p class="text-gray-500 mb-2">No patients with medical examinations or history forms found.</p>
                                    <?php elseif ($user_role === 'dentist'): ?>
                                        <p class="text-gray-500 mb-2">No patients with dental examinations found.</p>
                                    <?php else: ?>
                                        <p class="text-gray-500 mb-2">No patients found.</p>
                                    <?php endif; ?>
                                    <p class="text-gray-400 text-sm">Try adjusting your search criteria</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <?php if (count($patients) > 0): ?>
        <div class="mt-4 text-sm text-gray-500">
            Showing <?php echo count($patients); ?> patient(s)
        </div>
        <?php endif; ?>
    </div>
    
    <script>
    function confirmDelete(id, name) {
        if (confirm("Are you sure you want to delete patient: " + name + "? This action cannot be undone.")) {
            window.location.href = "modules/records/patients.php?delete=" + id;
        }
    }
    </script>
    <?php
    $content = ob_get_clean();
    echo $content;
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Records - BSU Clinic Record Management System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/png" href="../../assets/css/images/logo-bsu.png">
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
        
        .maroon-gradient-button {
            background: linear-gradient(135deg, var(--maroon-primary), var(--maroon-light));
        }
        
        .maroon-gradient-button:hover {
            background: linear-gradient(135deg, var(--maroon-dark), var(--maroon-primary));
        }
        
        .maroon-bg-light {
            background-color: var(--maroon-bg);
        }
        
        .text-maroon {
            color: var(--maroon-primary);
        }
        
        .border-maroon {
            border-color: var(--maroon-primary);
        }
        
        .role-badge {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            color: white;
        }
        
        .role-badge-nurse {
            background: linear-gradient(135deg, #ec4899, #be185d);
            color: white;
        }
        
        .role-badge-doctor {
            background: linear-gradient(135deg, #10b981, #047857);
            color: white;
        }
        
        .role-badge-dentist {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
        }
        
        .role-badge-staff {
            background: linear-gradient(135deg, #6b7280, #374151);
            color: white;
        }
        
        .role-badge-admin {
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
            color: white;
        }
    </style>
</head>
<body class="bg-gradient-to-br from-gray-50 to-gray-100">

   
    <div class="max-w-7xl mx-auto px-4 py-8 pt-20">
        <!-- Header with back button and role badge -->
        <div class="mb-6 flex justify-between items-center">
            
            <div>
                <span class="px-3 py-1 rounded-full text-sm font-semibold inline-block role-badge-<?= $user_role ?>">
                    <i class="bi bi-person-check"></i> <?= ucfirst($user_role) ?> Mode
                </span>
            </div>
        </div>
        
        <!-- Main Content Card -->
        <div class="bg-white rounded-xl shadow-lg overflow-hidden">
            <!-- Card Header -->
            <div class="maroon-gradient text-white px-8 py-6">
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                    <div class="flex items-center gap-3">
                        <i class="bi bi-people-fill text-3xl"></i>
                        <div>
                            <h2 class="text-2xl font-bold tracking-wide">Patient Records</h2>
                            <p class="text-gray-200 text-sm mt-1">
                                <?php 
                                if ($user_role === 'doctor') {
                                    echo "Showing patients with medical examinations and history forms";
                                } elseif ($user_role === 'dentist') {
                                    echo "Showing patients with dental examinations only";
                                } else {
                                    echo "Managing all patient records";
                                }
                                ?>
                            </p>
                        </div>
                    </div>
                    <div class="flex flex-col sm:flex-row gap-4">
                        <a href="add_patient.php" class="inline-flex items-center gap-2 bg-white text-maroon font-semibold px-4 py-2 rounded-lg shadow hover:bg-gray-50 transition-all">
                            <i class="bi bi-plus-circle"></i> Add New Patient
                        </a>
                        <div class="bg-white bg-opacity-20 border border-white border-opacity-30 rounded-lg px-4 py-2 text-center">
                            <span class="text-sm text-gray-200">Total Patients:</span>
                            <span class="text-xl font-bold text-white block"><?php echo $total_patients; ?></span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Card Body -->
            <div class="p-6">
                <?php if (!empty($success_message)): ?>
                    <div class="mb-4 p-3 bg-green-100 text-green-800 rounded-lg font-semibold border border-green-300">
                        <i class="bi bi-check-circle-fill mr-2"></i><?php echo $success_message; ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($error_message)): ?>
                    <div class="mb-4 p-3 bg-red-100 text-red-800 rounded-lg font-semibold border border-red-300">
                        <i class="bi bi-exclamation-triangle-fill mr-2"></i><?php echo $error_message; ?>
                    </div>
                <?php endif; ?>
                
                <!-- Search Form -->
                <form method="GET" action="" class="mb-6 bg-gray-50 border border-gray-200 rounded-xl p-4">
                    <div class="flex flex-col md:flex-row gap-4">
                        <div class="flex-1">
                            <div class="flex">
                                <input type="text" name="search" placeholder="Search patients..." value="<?php echo htmlspecialchars($search); ?>" 
                                       class="w-full rounded-l border border-gray-300 px-4 py-2 focus:outline-none focus:ring-2 focus:ring-maroon focus:border-maroon">
                                <button type="submit" class="maroon-gradient-button text-white px-4 py-2 rounded-r hover:shadow transition-all">
                                    <i class="bi bi-search"></i>
                                </button>
                            </div>
                        </div>
                        <div class="w-full md:w-48">
                            <select name="search_field" class="w-full rounded border border-gray-300 px-4 py-2 focus:outline-none focus:ring-2 focus:ring-maroon focus:border-maroon bg-white">
                                <option value="all" <?php echo $search_field == 'all' ? 'selected' : ''; ?>>All Fields</option>
                                <option value="student_id" <?php echo $search_field == 'student_id' ? 'selected' : ''; ?>>Student ID</option>
                                <option value="name" <?php echo $search_field == 'name' ? 'selected' : ''; ?>>Name</option>
                                <option value="program" <?php echo $search_field == 'program' ? 'selected' : ''; ?>>Program</option>
                            </select>
                        </div>
                        <div class="flex gap-2">
                            <button type="submit" class="maroon-gradient-button text-white px-6 py-2 rounded hover:shadow transition-all font-semibold flex-1">
                                Search
                            </button>
                            <a href="?" class="bg-gray-200 text-gray-800 px-6 py-2 rounded hover:bg-gray-300 transition-all font-semibold text-center">
                                Reset
                            </a>
                        </div>
                    </div>
                </form>
                
                <!-- Patients Table -->
                <div class="overflow-x-auto rounded-lg border border-gray-200">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Student ID</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Name</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Sex</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Date of Birth</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Program</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Year Level</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-100">
                            <?php if (count($patients) > 0): ?>
                                <?php foreach ($patients as $patient): ?>
                                    <tr class="hover:bg-gray-50 transition-all duration-200">
                                        <td class="px-6 py-4 text-sm text-gray-900 font-medium"><?php echo htmlspecialchars($patient['student_id']); ?></td>
                                        <td class="px-6 py-4 text-sm text-gray-900">
                                            <?php 
                                            echo htmlspecialchars($patient['last_name']) . ', ' . 
                                                 htmlspecialchars($patient['first_name']) . ' ' . 
                                                 htmlspecialchars($patient['middle_name']); 
                                            ?>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-900"><?php echo htmlspecialchars($patient['sex']); ?></td>
                                        <td class="px-6 py-4 text-sm text-gray-900"><?php echo htmlspecialchars($patient['date_of_birth']); ?></td>
                                        <td class="px-6 py-4 text-sm text-gray-900"><?php echo htmlspecialchars($patient['program']); ?></td>
                                        <td class="px-6 py-4 text-sm text-gray-900"><?php echo htmlspecialchars($patient['year_level']); ?></td>
                                        <td class="px-6 py-4 text-sm">
                                            <div class="flex gap-2">
                                                <a href="view_patient.php?id=<?php echo $patient['id']; ?>" 
                                                   class="inline-flex items-center justify-center bg-blue-500 text-white rounded px-3 py-2 text-sm hover:bg-blue-600 transition-all shadow" 
                                                   title="View">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <a href="edit_patient.php?id=<?php echo $patient['id']; ?>" 
                                                   class="inline-flex items-center justify-center bg-yellow-500 text-white rounded px-3 py-2 text-sm hover:bg-yellow-600 transition-all shadow" 
                                                   title="Edit">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <?php if (in_array($user_role, ['nurse', 'staff', 'admin', 'doctor', 'dentist'])): ?>
                                                    <button onclick="confirmDelete(<?php echo $patient['id']; ?>, '<?php echo addslashes($patient['first_name'] . ' ' . $patient['last_name']); ?>')"
                                                       class="inline-flex items-center justify-center bg-red-500 text-white rounded px-3 py-2 text-sm hover:bg-red-600 transition-all shadow" 
                                                       title="Delete">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="px-6 py-8 text-center text-gray-500">
                                        <div class="flex flex-col items-center">
                                            <div class="bg-gray-100 w-16 h-16 rounded-full flex items-center justify-center mb-3">
                                                <i class="bi bi-people text-gray-400 text-2xl"></i>
                                            </div>
                                            <?php if ($user_role === 'doctor'): ?>
                                                <p class="text-gray-500 mb-2">No patients with medical examinations or history forms found.</p>
                                            <?php elseif ($user_role === 'dentist'): ?>
                                                <p class="text-gray-500 mb-2">No patients with dental examinations found.</p>
                                            <?php else: ?>
                                                <p class="text-gray-500 mb-2">No patients found.</p>
                                            <?php endif; ?>
                                            <p class="text-gray-400 text-sm">Try adjusting your search criteria</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <?php if (count($patients) > 0): ?>
                <div class="mt-4 text-sm text-gray-500">
                    Showing <?php echo count($patients); ?> patient(s)
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
    function confirmDelete(id, name) {
        if (confirm("Are you sure you want to delete patient: " + name + "? This action cannot be undone.")) {
            window.location.href = "patients.php?delete=" + id;
        }
    }
    </script>
</body>
</html>