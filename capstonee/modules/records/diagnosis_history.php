<?php
session_start();
include '../../config/database.php';

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

// Check if user has medical staff privileges
$is_medical_staff = in_array($_SESSION['role'], ['doctor', 'dentist', 'nurse', 'admin', 'staff']);
if (!$is_medical_staff) {
    header("Location: ../../dashboard.php");
    exit();
}

$patient_id = $_GET['patient_id'] ?? '';
$record_id = $_GET['record_id'] ?? '';
$type = $_GET['type'] ?? '';
$success_message = $_GET['success'] ?? '';
$error_message = '';

// Determine dashboard URL for header navigation
$dashboard_url = '../../dashboard.php';
if (isset($_SESSION['role'])) {
    switch ($_SESSION['role']) {
        case 'doctor':
            $dashboard_url = '../../doctor_dashboard.php';
            break;
        case 'dentist':
            $dashboard_url = '../../dentist_dashboard.php';
            break;
        case 'nurse':
            $dashboard_url = '../../nurse_dashboard.php';
            break;
        case 'staff':
            $dashboard_url = '../../msa_dashboard.php';
            break;
    }
}

// Fetch patient information
$patient_info = null;
if ($patient_id) {
    $stmt = $conn->prepare("SELECT * FROM patients WHERE id = ?");
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    $patient_info = $stmt->get_result()->fetch_assoc();
}

// Fetch diagnosis history - FILTERED BY CURRENT USER ROLE
$diagnoses = [];
if ($patient_id) {
    // Determine which diagnosis types to show based on current user role
    $allowed_diagnosis_types = [];

    if ($_SESSION['role'] === 'nurse') {
        $allowed_diagnosis_types = ['nurse'];
    } elseif ($_SESSION['role'] === 'dentist') {
        $allowed_diagnosis_types = ['dentist'];
    } elseif ($_SESSION['role'] === 'doctor' || $_SESSION['role'] === 'physician') {
        $allowed_diagnosis_types = ['doctor'];
    } elseif ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'staff') {
        // Admin/staff can see all diagnosis types
        $allowed_diagnosis_types = ['nurse', 'dentist', 'doctor'];
    }

    if (!empty($allowed_diagnosis_types)) {
        $placeholders = str_repeat('?,', count($allowed_diagnosis_types) - 1) . '?';
        $stmt = $conn->prepare("
            SELECT md.*, mr.record_type, p.first_name, p.last_name, p.student_id
            FROM medical_diagnoses md
            JOIN medical_records mr ON md.record_id = mr.id
            JOIN patients p ON md.patient_id = p.id
            WHERE md.patient_id = ? AND md.diagnosis_type IN ($placeholders)
            ORDER BY md.diagnosis_date DESC, md.created_at DESC
        ");

        $types = "i" . str_repeat('s', count($allowed_diagnosis_types));
        $params = array_merge([$patient_id], $allowed_diagnosis_types);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $diagnoses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}

// Handle delete diagnosis
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['delete_diagnosis'])) {
    $diagnosis_id = $_POST['diagnosis_id'];
    
    // Begin transaction
    $conn->begin_transaction();
    
    try {
        // First, remove the link from consultation_history
        $update_consultation = $conn->prepare("UPDATE consultation_history SET diagnosis_id = NULL WHERE diagnosis_id = ?");
        $update_consultation->bind_param("i", $diagnosis_id);
        $update_consultation->execute();
        $update_consultation->close();
        
        // Then delete the diagnosis
        $delete_diagnosis = $conn->prepare("DELETE FROM medical_diagnoses WHERE id = ?");
        $delete_diagnosis->bind_param("i", $diagnosis_id);
        $delete_diagnosis->execute();
        $delete_diagnosis->close();
        
        // Commit transaction
        $conn->commit();
        
        $success_message = "Diagnosis record deleted successfully!";
        header("Location: diagnosis_history.php?patient_id=" . $patient_id . "&record_id=" . $record_id . "&type=" . $type . "&success=" . urlencode($success_message));
        exit();
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        $error_message = "Error deleting diagnosis record: " . $e->getMessage();
    }
}

// Function to get diagnosis buttons based on user role
function getDiagnosisButtons($user_role, $patient_id, $record_id, $type) {
    $buttons = [];
    
    if ($user_role === 'nurse') {
        $buttons[] = [
            'url' => "medical_diagnosis_form.php?patient_id=$patient_id&record_id=$record_id&type=$type&diagnosis_type=nurse",
            'text' => 'Nurse Diagnosis',
            'color' => 'orange-gradient-button',
            'icon' => 'bi bi-plus-circle'
        ];
    } elseif ($user_role === 'dentist') {
        $buttons[] = [
            'url' => "medical_diagnosis_form.php?patient_id=$patient_id&record_id=$record_id&type=$type&diagnosis_type=dentist",
            'text' => 'Dental Diagnosis',
            'color' => 'orange-gradient-button',
            'icon' => 'bi bi-tooth'
        ];
    } elseif ($user_role === 'doctor' || $user_role === 'physician') {
        $buttons[] = [
            'url' => "medical_diagnosis_form.php?patient_id=$patient_id&record_id=$record_id&type=$type&diagnosis_type=doctor",
            'text' => 'Physician Diagnosis',
            'color' => 'orange-gradient-button',
            'icon' => 'bi bi-heart-pulse'
        ];
    } elseif ($user_role === 'admin' || $user_role === 'staff') {
        // Admin/staff can add all types
        $buttons = [
            [
                'url' => "medical_diagnosis_form.php?patient_id=$patient_id&record_id=$record_id&type=$type&diagnosis_type=nurse",
                'text' => 'Nurse Diagnosis',
                'color' => 'orange-gradient-button',
                'icon' => 'bi bi-plus-circle'
            ],
            [
                'url' => "medical_diagnosis_form.php?patient_id=$patient_id&record_id=$record_id&type=$type&diagnosis_type=dentist",
                'text' => 'Dental Diagnosis',
                'color' => 'orange-gradient-button',
                'icon' => 'bi bi-tooth'
            ],
            [
                'url' => "medical_diagnosis_form.php?patient_id=$patient_id&record_id=$record_id&type=$type&diagnosis_type=doctor",
                'text' => 'Physician Diagnosis',
                'color' => 'orange-gradient-button',
                'icon' => 'bi bi-heart-pulse'
            ]
        ];
    }
    
    return $buttons;
}

// Function to get page title based on user role
function getPageTitle($user_role) {
    if ($user_role === 'nurse') {
        return 'Nurse Diagnosis History';
    } elseif ($user_role === 'dentist') {
        return 'Dental Diagnosis History';
    } elseif ($user_role === 'doctor' || $user_role === 'physician') {
        return 'Physician Diagnosis History';
    } else {
        return 'Diagnosis History';
    }
}

// Function to get diagnosis type label
function getDiagnosisTypeLabel($diagnosis_type) {
    $labels = [
        'nurse' => 'Nurse',
        'dentist' => 'Dental',
        'doctor' => 'Physician'
    ];
    return $labels[$diagnosis_type] ?? ucfirst($diagnosis_type);
}

// Get current user role
$user_role = $_SESSION['role'];
$page_title = getPageTitle($user_role);
$diagnosis_buttons = getDiagnosisButtons($user_role, $patient_id, $record_id, $type);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - BSU Clinic</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .red-orange-gradient-button {
            background: linear-gradient(135deg, #dc2626, #ea580c);
        }
        .red-orange-gradient-button:hover {
            background: linear-gradient(135deg, #b91c1c, #c2410c);
        }
        .red-orange-gradient {
            background: linear-gradient(135deg, #dc2626, #ea580c, #f97316);
        }
        .orange-gradient-button {
            background: linear-gradient(135deg, #ea580c, #f97316);
        }
        .orange-gradient-button:hover {
            background: linear-gradient(135deg, #c2410c, #ea580c);
        }
        .diagnosis-badge-nurse { background-color: #ea580c; }
        .diagnosis-badge-dentist { background-color: #f97316; }
        .diagnosis-badge-doctor { background-color: #dc2626; }
        .severity-mild { background-color: #10b981; }
        .severity-moderate { background-color: #f59e0b; }
        .severity-severe { background-color: #ef4444; }
        .severity-critical { background-color: #7c3aed; }
    </style>
</head>
<body class="bg-gradient-to-br from-orange-50 to-red-50 flex flex-col min-h-screen">

    <!-- HEADER (same red-orange navigation used across doctor pages) -->
    <header class="red-orange-gradient text-white shadow-md sticky top-0 z-50">
        <div class="max-w-7xl mx-auto flex items-center justify-between px-6 py-3">
            <div class="flex items-center gap-3">
                <img src="../../assets/css/images/logo-bsu.png" alt="BSU Logo" class="w-12 h-12 rounded-full object-cover border-4 border-white bg-white">
                <h1 class="text-lg font-bold">BSU Clinic Record Management System</h1>
            </div>
            <nav class="flex items-center gap-6">
                <a href="<?php echo htmlspecialchars($dashboard_url); ?>" class="hover:text-yellow-200 flex items-center gap-1 font-semibold">
                    <i class="bi bi-speedometer2"></i> Dashboard
                </a>
                <a href="../../logout.php" class="red-orange-gradient-button text-white px-3 py-1 rounded-lg font-semibold hover:shadow-lg flex items-center gap-1">
                    <i class="bi bi-box-arrow-right"></i> Logout
                </a>
            </nav>
        </div>
    </header>

    <div class="min-h-screen py-8 px-4 pt-20 flex-1">
        <div class="max-w-6xl mx-auto">
            <!-- Header -->
            <div class="flex justify-between items-center mb-6">
                <a href="view_record.php?type=<?= $type ?>&id=<?= $record_id ?>" 
                   class="red-orange-gradient-button text-white px-4 py-2 rounded-lg shadow hover:shadow-lg transition-all flex items-center gap-2">
                   <i class="bi bi-arrow-left"></i> Back to Record
                </a>
                <h1 class="text-2xl font-bold text-gray-800"><?= $page_title ?></h1>
                <div class="flex gap-2">
                    <?php foreach ($diagnosis_buttons as $button): ?>
                        <a href="<?= $button['url'] ?>" 
                           class="<?= $button['color'] ?> text-white px-4 py-2 rounded-lg shadow hover:shadow-lg transition-all flex items-center gap-2">
                           <i class="<?= $button['icon'] ?>"></i> <?= $button['text'] ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Patient Info -->
            <?php if ($patient_info): ?>
            <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                <h2 class="text-xl font-semibold mb-4 text-gray-700">Patient Information</h2>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div>
                        <label class="font-medium text-gray-600">Student ID:</label>
                        <p class="text-gray-800 font-semibold"><?= htmlspecialchars($patient_info['student_id']) ?></p>
                    </div>
                    <div>
                        <label class="font-medium text-gray-600">Name:</label>
                        <p class="text-gray-800 font-semibold"><?= htmlspecialchars($patient_info['first_name'] . ' ' . $patient_info['last_name']) ?></p>
                    </div>
                    <div>
                        <label class="font-medium text-gray-600">Program/Year:</label>
                        <p class="text-gray-800 font-semibold"><?= htmlspecialchars($patient_info['program'] . ' / ' . $patient_info['year_level']) ?></p>
                    </div>
                    <div>
                        <label class="font-medium text-gray-600">Total Diagnoses:</label>
                        <p class="text-gray-800 font-semibold"><?= count($diagnoses) ?></p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Notifications -->
            <?php if ($success_message): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                    <i class="bi bi-check-circle-fill mr-2"></i><?= htmlspecialchars($success_message) ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error_message): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                    <i class="bi bi-exclamation-triangle-fill mr-2"></i><?= htmlspecialchars($error_message) ?>
                </div>
            <?php endif; ?>

            <!-- Diagnosis Statistics -->
            <?php if (!empty($diagnoses)): ?>
            <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                <h2 class="text-xl font-semibold mb-4 text-gray-700">Diagnosis Statistics</h2>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <?php
                    $nurse_count = 0;
                    $dentist_count = 0;
                    $doctor_count = 0;
                    $active_count = 0;
                    
                    foreach ($diagnoses as $diagnosis) {
                        switch ($diagnosis['diagnosis_type']) {
                            case 'nurse': $nurse_count++; break;
                            case 'dentist': $dentist_count++; break;
                            case 'doctor': $doctor_count++; break;
                        }
                        if ($diagnosis['status'] === 'active') $active_count++;
                    }
                    ?>
                    
                    <?php if ($user_role === 'nurse' || $user_role === 'admin' || $user_role === 'staff'): ?>
                    <div class="text-center p-4 bg-orange-50 rounded-lg border border-orange-200">
                        <div class="text-2xl font-bold text-orange-700"><?= $nurse_count ?></div>
                        <div class="text-sm text-orange-700">Nurse Diagnoses</div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($user_role === 'dentist' || $user_role === 'admin' || $user_role === 'staff'): ?>
                    <div class="text-center p-4 bg-orange-50 rounded-lg border border-orange-200">
                        <div class="text-2xl font-bold text-orange-700"><?= $dentist_count ?></div>
                        <div class="text-sm text-orange-700">Dental Diagnoses</div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($user_role === 'doctor' || $user_role === 'physician' || $user_role === 'admin' || $user_role === 'staff'): ?>
                    <div class="text-center p-4 bg-orange-50 rounded-lg border border-orange-200">
                        <div class="text-2xl font-bold text-orange-700"><?= $doctor_count ?></div>
                        <div class="text-sm text-orange-700">Physician Diagnoses</div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="text-center p-4 bg-orange-50 rounded-lg border border-orange-200">
                        <div class="text-2xl font-bold text-orange-700"><?= $active_count ?></div>
                        <div class="text-sm text-orange-700">Active Cases</div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Diagnosis History List -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-xl font-semibold text-gray-700">
                        <?= 
                            $user_role === 'nurse' ? 'Nurse Diagnoses' : 
                            ($user_role === 'dentist' ? 'Dental Diagnoses' : 
                            ($user_role === 'doctor' || $user_role === 'physician' ? 'Physician Diagnoses' : 'All Diagnoses'))
                        ?>
                    </h2>
                    <span class="bg-orange-100 text-orange-800 px-3 py-1 rounded-full text-sm font-semibold">
                        Total: <?= count($diagnoses) ?> diagnoses
                    </span>
                </div>
                
                <?php if (empty($diagnoses)): ?>
                    <div class="text-center py-12 text-gray-500">
                        <i class="bi bi-file-medical text-6xl mb-4 block"></i>
                        <p class="text-lg mb-2">No <?= 
                            $user_role === 'nurse' ? 'nurse' : 
                            ($user_role === 'dentist' ? 'dental' : 
                            ($user_role === 'doctor' || $user_role === 'physician' ? 'physician' : 'diagnosis'))
                        ?> records found</p>
                        <p class="text-sm mb-4">Start by adding a diagnosis using the buttons above.</p>
                        <div class="flex gap-2 justify-center">
                            <?php if (!empty($diagnosis_buttons)): ?>
                                <a href="<?= $diagnosis_buttons[0]['url'] ?>" 
                                   class="orange-gradient-button text-white px-4 py-2 rounded-lg shadow hover:shadow-lg transition-all flex items-center gap-2">
                                   <i class="bi bi-plus-circle"></i> Add First Diagnosis
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="space-y-6">
                        <?php foreach ($diagnoses as $diagnosis): ?>
                        <div class="border border-gray-200 rounded-lg p-6 hover:shadow-lg transition-shadow bg-white">
                            <div class="flex justify-between items-start mb-4">
                                <div class="flex-1">
                                    <div class="flex items-center gap-3 mb-2 flex-wrap">
                                        <span class="diagnosis-badge-<?= $diagnosis['diagnosis_type'] ?> text-white px-3 py-1 rounded-full text-sm font-semibold">
                                            <?= getDiagnosisTypeLabel($diagnosis['diagnosis_type']) ?> Diagnosis
                                        </span>
                                        <span class="bg-orange-500 text-white px-2 py-1 rounded text-xs font-semibold">
                                            <?= date('M j, Y', strtotime($diagnosis['diagnosis_date'])) ?>
                                        </span>
                                        <span class="severity-<?= $diagnosis['severity'] ?> text-white px-2 py-1 rounded text-xs font-semibold">
                                            <?= ucfirst($diagnosis['severity']) ?> Severity
                                        </span>
                                        <span class="bg-gray-600 text-white px-2 py-1 rounded text-xs font-semibold">
                                            <?= ucfirst($diagnosis['status']) ?>
                                        </span>
                                        <?php if ($diagnosis['follow_up_required'] && $diagnosis['follow_up_date']): ?>
                                            <span class="bg-orange-600 text-white px-2 py-1 rounded text-xs font-semibold">
                                                Follow-up: <?= date('M j, Y', strtotime($diagnosis['follow_up_date'])) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-sm text-gray-600">
                                        <i class="bi bi-person-check"></i> 
                                        Provider: <?= htmlspecialchars($diagnosis['provider_name']) ?> (<?= htmlspecialchars($diagnosis['provider_role']) ?>)
                                    </p>
                                    <p class="text-xs text-gray-500 mt-1">
                                        Recorded: <?= date('M j, Y g:i A', strtotime($diagnosis['created_at'])) ?>
                                    </p>
                                </div>
                                <form method="POST" onsubmit="return confirm('Are you sure you want to delete this diagnosis record? This action cannot be undone.');" class="flex-shrink-0">
                                    <input type="hidden" name="diagnosis_id" value="<?= $diagnosis['id'] ?>">
                                    <input type="hidden" name="patient_id" value="<?= $patient_id ?>">
                                    <button type="submit" name="delete_diagnosis" 
                                            class="text-red-600 hover:text-red-800 transition-colors bg-red-50 hover:bg-red-100 p-2 rounded-lg">
                                        <i class="bi bi-trash"></i> Delete
                                    </button>
                                </form>
                            </div>
                            
                            <!-- Chief Complaint -->
                            <div class="mb-4">
                                <h4 class="font-semibold text-gray-700 mb-1">Chief Complaint:</h4>
                                <p class="text-gray-700 bg-gray-50 p-3 rounded border"><?= nl2br(htmlspecialchars($diagnosis['chief_complaint'])) ?></p>
                            </div>
                            
                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-4">
                                <!-- Left Column -->
                                <div class="space-y-4">
                                    <?php if ($diagnosis['subjective_findings']): ?>
                                        <div>
                                            <h4 class="font-semibold text-gray-700 mb-1">Subjective Findings:</h4>
                                            <p class="text-gray-700 bg-gray-50 p-3 rounded border text-sm"><?= nl2br(htmlspecialchars($diagnosis['subjective_findings'])) ?></p>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($diagnosis['objective_findings']): ?>
                                        <div>
                                            <h4 class="font-semibold text-gray-700 mb-1">Objective Findings:</h4>
                                            <p class="text-gray-700 bg-gray-50 p-3 rounded border text-sm"><?= nl2br(htmlspecialchars($diagnosis['objective_findings'])) ?></p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Right Column -->
                                <div class="space-y-4">
                                    <?php if ($diagnosis['assessment']): ?>
                                        <div>
                                            <h4 class="font-semibold text-gray-700 mb-1">Assessment/Diagnosis:</h4>
                                            <p class="text-gray-700 bg-gray-50 p-3 rounded border text-sm"><?= nl2br(htmlspecialchars($diagnosis['assessment'])) ?></p>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($diagnosis['plan']): ?>
                                        <div>
                                            <h4 class="font-semibold text-gray-700 mb-1">Treatment Plan:</h4>
                                            <p class="text-gray-700 bg-gray-50 p-3 rounded border text-sm"><?= nl2br(htmlspecialchars($diagnosis['plan'])) ?></p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Medications and Notes -->
                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                                <?php if ($diagnosis['medications_prescribed']): ?>
                                    <div>
                                        <h4 class="font-semibold text-gray-700 mb-1">Medications Prescribed:</h4>
                                        <p class="text-gray-700 bg-gray-50 p-3 rounded border text-sm"><?= nl2br(htmlspecialchars($diagnosis['medications_prescribed'])) ?></p>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($diagnosis['notes']): ?>
                                    <div>
                                        <h4 class="font-semibold text-gray-700 mb-1">Additional Notes:</h4>
                                        <p class="text-gray-700 bg-gray-50 p-3 rounded border text-sm"><?= nl2br(htmlspecialchars($diagnosis['notes'])) ?></p>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="border-t border-gray-200 pt-3 mt-4">
                                <p class="text-xs text-gray-500">
                                    <strong>Related Record:</strong> 
                                    <?= ucfirst(str_replace('_', ' ', $diagnosis['record_type'])) ?> 
                                    (Record ID: <?= $diagnosis['record_id'] ?>)
                                </p>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Add smooth scrolling for better UX
            const diagnoses = document.querySelectorAll('.border-gray-200');
            diagnoses.forEach(diagnosis => {
                diagnosis.addEventListener('click', function(e) {
                    if (e.target.tagName !== 'BUTTON' && e.target.tagName !== 'A') {
                        this.classList.toggle('bg-gray-50');
                    }
                });
            });

            // Auto-hide success messages after 5 seconds
            const successMessage = document.querySelector('.bg-green-100');
            if (successMessage) {
                setTimeout(() => {
                    successMessage.style.transition = 'opacity 0.5s ease';
                    successMessage.style.opacity = '0';
                    setTimeout(() => successMessage.remove(), 500);
                }, 5000);
            }
        });
    </script>
</body>
</html>