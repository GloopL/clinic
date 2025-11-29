<?php
session_start();
include '../../config/database.php';

// Redirect to login if not authenticated
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
    } elseif ($_SESSION['role'] === 'nurse') {
        $dashboard_url = '../../nurse_dashboard.php';
    } elseif ($_SESSION['role'] === 'staff') {
        $dashboard_url = '../../msa_dashboard.php';
    }
}

$success_message = '';
$error_message = '';

// Get current user role
$user_role = $_SESSION['role'] ?? 'user';

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
    // Doctor: Only show patients with medical exams
    $query .= " AND EXISTS (SELECT 1 FROM medical_records mr 
               JOIN medical_exams me ON mr.id = me.record_id 
               WHERE mr.patient_id = p.id AND mr.record_type = 'medical_exam')";
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Records - BSU Clinic Records</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css">
    <link rel="icon" type="image/png" href="../../assets/css/images/logo-bsu.png">
    <link rel="stylesheet" href="../../assets/css/style.css">
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
        
        .red-orange-table-header {
            background: linear-gradient(135deg, #dc2626, #ea580c);
        }
        
        .red-orange-table-row {
            background: linear-gradient(135deg, #fef2f2, #ffedd5);
        }
        
        .red-orange-table-row:hover {
            background: linear-gradient(135deg, #fee2e2, #fed7aa);
        }
        
        .search-button {
            background: linear-gradient(135deg, #ea580c, #f97316);
        }
        
        .search-button:hover {
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

    <!-- HEADER (same style as QR Scan / verify submission pages) -->
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

    <div class="max-w-7xl mx-auto px-4 py-8 pt-20">
        <div class="mb-6 flex justify-between items-center">
            <div>
                <span class="px-3 py-1 rounded-full text-sm font-semibold mt-1 inline-block role-badge role-badge-<?= $user_role ?>">
                    <i class="bi bi-person-check"></i> <?= ucfirst($user_role) ?> Mode
                </span>
            </div>
            <a href="<?php echo $dashboard_url; ?>" class="inline-flex items-center gap-2 red-orange-gradient-button text-white font-semibold px-4 py-2 rounded-lg shadow hover:shadow-lg transition-all">
                <i class="bi bi-arrow-left"></i> Back to Dashboard
            </a>
        </div>
        
        <div class="bg-white shadow-lg rounded-lg overflow-hidden">
            <div class="flex flex-col sm:flex-row justify-between items-center gap-4 red-orange-gradient text-white px-8 py-6">
                <div class="flex items-center gap-3">
                    <i class="bi bi-people-fill text-3xl"></i>
                    <div>
                        <span class="text-2xl font-bold tracking-wide">Patient Records</span>
                        <p class="text-orange-100 text-sm mt-1">
                            <?php 
                            if ($user_role === 'doctor') {
                                echo "Showing patients with medical examinations only";
                            } elseif ($user_role === 'dentist') {
                                echo "Showing patients with dental examinations only";
                            } else {
                                echo "Showing all patients";
                            }
                            ?>
                        </p>
                    </div>
                </div>
                <!-- <a href="add_patient.php" class="inline-flex items-center gap-2 bg-white text-orange-600 font-semibold px-5 py-2.5 rounded-lg shadow hover:bg-orange-100 transition-all border border-orange-200">
                    <i class="bi bi-plus-circle text-lg"></i> Add New Patient
                </a> -->
            </div>
            
            <div class="px-6 py-6">
                <?php if (!empty($success_message)): ?>
                    <div class="mb-4 px-4 py-3 rounded bg-green-100 text-green-800 border border-green-300 font-semibold">
                        <?php echo $success_message; ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($error_message)): ?>
                    <div class="mb-4 px-4 py-3 rounded bg-red-100 text-red-800 border border-red-300 font-semibold">
                        <?php echo $error_message; ?>
                    </div>
                <?php endif; ?>
                
                <!-- Search Form -->
                <form method="GET" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" class="mb-6">
                    <div class="flex flex-col md:flex-row gap-4">
                        <div class="flex-1">
                            <div class="flex">
                                <input type="text" name="search" placeholder="Search patients..." value="<?php echo htmlspecialchars($search); ?>" 
                                       class="w-full rounded-l border border-orange-300 px-4 py-2 focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-orange-500">
                                <button type="submit" class="search-button text-white px-4 py-2 rounded-r hover:shadow transition-all">
                                    <i class="bi bi-search"></i>
                                </button>
                            </div>
                        </div>
                        <div>
                            <select name="search_field" class="w-full rounded border border-orange-300 px-4 py-2 focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-orange-500">
                                <option value="all" <?php echo $search_field == 'all' ? 'selected' : ''; ?>>All Fields</option>
                                <option value="student_id" <?php echo $search_field == 'student_id' ? 'selected' : ''; ?>>Student ID</option>
                                <option value="name" <?php echo $search_field == 'name' ? 'selected' : ''; ?>>Name</option>
                                <option value="program" <?php echo $search_field == 'program' ? 'selected' : ''; ?>>Program</option>
                            </select>
                        </div>
                        <div>
                            <button type="submit" class="w-full search-button text-white px-6 py-2 rounded hover:shadow transition-all font-semibold">
                                Search
                            </button>
                        </div>
                        <div>
                            <a href="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" 
                               class="w-full block bg-orange-200 text-orange-800 px-6 py-2 rounded hover:bg-orange-300 transition-all font-semibold text-center">
                                Reset
                            </a>
                        </div>
                    </div>
                </form>
                
                <!-- Patients Table -->
                <div class="overflow-x-auto rounded-lg border border-orange-200">
                    <table class="min-w-full divide-y divide-orange-200">
                        <thead class="red-orange-table-header text-white">
                            <tr>
                                <th class="px-6 py-4 text-left text-sm font-semibold">Student ID</th>
                                <th class="px-6 py-4 text-left text-sm font-semibold">Name</th>
                                <th class="px-6 py-4 text-left text-sm font-semibold">Sex</th>
                                <th class="px-6 py-4 text-left text-sm font-semibold">Date of Birth</th>
                                <th class="px-6 py-4 text-left text-sm font-semibold">Program</th>
                                <th class="px-6 py-4 text-left text-sm font-semibold">Year Level</th>
                                <th class="px-6 py-4 text-left text-sm font-semibold">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-orange-100">
                            <?php if (count($patients) > 0): ?>
                                <?php foreach ($patients as $patient): ?>
                                    <tr class="red-orange-table-row hover:shadow-lg transition-all duration-200">
                                        <td class="px-6 py-4 text-sm text-orange-900 font-medium"><?php echo htmlspecialchars($patient['student_id']); ?></td>
                                        <td class="px-6 py-4 text-sm text-orange-900">
                                            <?php 
                                            echo htmlspecialchars($patient['last_name']) . ', ' . 
                                                 htmlspecialchars($patient['first_name']) . ' ' . 
                                                 htmlspecialchars($patient['middle_name']); 
                                            ?>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-orange-900"><?php echo htmlspecialchars($patient['sex']); ?></td>
                                        <td class="px-6 py-4 text-sm text-orange-900"><?php echo htmlspecialchars($patient['date_of_birth']); ?></td>
                                        <td class="px-6 py-4 text-sm text-orange-900"><?php echo htmlspecialchars($patient['program']); ?></td>
                                        <td class="px-6 py-4 text-sm text-orange-900"><?php echo htmlspecialchars($patient['year_level']); ?></td>
                                        <td class="px-6 py-4 text-sm">
                                            <div class="flex gap-2">
                                                <a href="view_patient.php?id=<?php echo $patient['id']; ?>" 
                                                   class="inline-flex items-center justify-center bg-white text-orange-500 border border-orange-300 rounded px-3 py-2 text-sm hover:bg-orange-50 transition-all shadow" 
                                                   title="View">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <a href="edit_patient.php?id=<?php echo $patient['id']; ?>" 
                                                   class="inline-flex items-center justify-center bg-yellow-500 text-white rounded px-3 py-2 text-sm hover:bg-yellow-600 transition-all shadow" 
                                                   title="Edit">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <?php if (in_array($user_role, ['nurse', 'staff', 'admin', 'doctor', 'dentist'])): ?>
                                                    <a href="#" 
                                                       class="inline-flex items-center justify-center bg-red-500 text-white rounded px-3 py-2 text-sm hover:bg-red-600 transition-all shadow" 
                                                       title="Delete" 
                                                       onclick="confirmDelete(<?php echo $patient['id']; ?>, '<?php echo $patient['first_name'] . ' ' . $patient['last_name']; ?>')">
                                                        <i class="bi bi-trash"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="px-6 py-8 text-center text-orange-600">
                                        <i class="bi bi-people text-4xl mb-3 block"></i>
                                        <?php if ($user_role === 'doctor'): ?>
                                            No patients with medical examinations found.
                                        <?php elseif ($user_role === 'dentist'): ?>
                                            No patients with dental examinations found.
                                        <?php else: ?>
                                            No patients found.
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        function confirmDelete(id, name) {
            if (confirm("Are you sure you want to delete patient: " + name + "?")) {
                window.location.href = "patients.php?delete=" + id;
            }
        }
    </script>
</body>
</html>