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
$diagnosis_type = $_GET['diagnosis_type'] ?? 'nurse'; // nurse, dentist, doctor
$success_message = '';
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

// Fetch current record information
$current_record = null;
if ($record_id) {
    $stmt = $conn->prepare("
        SELECT mr.*, p.first_name, p.last_name, p.student_id 
        FROM medical_records mr 
        JOIN patients p ON mr.patient_id = p.id 
        WHERE mr.id = ?
    ");
    $stmt->bind_param("i", $record_id);
    $stmt->execute();
    $current_record = $stmt->get_result()->fetch_assoc();
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['add_diagnosis'])) {
    $patient_id = $_POST['patient_id'];
    $record_id = $_POST['record_id'];
    $diagnosis_type = $_POST['diagnosis_type'];
    $diagnosis_date = $_POST['diagnosis_date'];
    $provider_name = $_POST['provider_name'];
    $provider_role = $_POST['provider_role'];
    $chief_complaint = $_POST['chief_complaint'];
    $subjective_findings = $_POST['subjective_findings'];
    $objective_findings = $_POST['objective_findings'];
    $assessment = $_POST['assessment'];
    $plan = $_POST['plan'];
    $medications_prescribed = $_POST['medications_prescribed'];
    $follow_up_required = isset($_POST['follow_up_required']) ? 1 : 0;
    $follow_up_date = $_POST['follow_up_date'] ?? null;
    $severity = $_POST['severity'];
    $status = $_POST['status'];
    $notes = $_POST['notes'];

    // Validate required fields
    if (empty($provider_name) || empty($diagnosis_date) || empty($chief_complaint)) {
        $error_message = "Provider name, diagnosis date, and chief complaint are required.";
    } elseif (empty($patient_id) || !is_numeric($patient_id)) {
        $error_message = "Invalid patient ID. Please select a valid patient.";
    } elseif (empty($record_id) || !is_numeric($record_id)) {
        $error_message = "Invalid medical record ID. Please select a valid record.";
    } else {
        // Validate that patient_id exists in patients table
        $validate_patient = $conn->prepare("SELECT id FROM patients WHERE id = ?");
        $validate_patient->bind_param("i", $patient_id);
        $validate_patient->execute();
        $patient_result = $validate_patient->get_result();
        
        if ($patient_result->num_rows === 0) {
            $error_message = "Error: Patient ID does not exist in the database. Please select a valid patient.";
            $validate_patient->close();
        } else {
            $validate_patient->close();
            
            // Validate that record_id exists in medical_records table and belongs to this patient
            $validate_record = $conn->prepare("SELECT id FROM medical_records WHERE id = ? AND patient_id = ?");
            $validate_record->bind_param("ii", $record_id, $patient_id);
            $validate_record->execute();
            $record_result = $validate_record->get_result();
            
            if ($record_result->num_rows === 0) {
                $error_message = "Error: Medical Record ID does not exist or does not belong to this patient. Please select a valid record.";
                $validate_record->close();
            } else {
                $validate_record->close();
                
                // Both validations passed, proceed with insert
                // Insert into medical_diagnoses table
                $stmt = $conn->prepare("
                    INSERT INTO medical_diagnoses 
                    (patient_id, record_id, diagnosis_type, diagnosis_date, provider_name, provider_role, 
                     chief_complaint, subjective_findings, objective_findings, assessment, plan, 
                     medications_prescribed, follow_up_required, follow_up_date, severity, status, notes) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->bind_param("iissssssssssissss", 
                    $patient_id, $record_id, $diagnosis_type, $diagnosis_date, $provider_name, $provider_role,
                    $chief_complaint, $subjective_findings, $objective_findings, $assessment, $plan,
                    $medications_prescribed, $follow_up_required, $follow_up_date, $severity, $status, $notes
                );

                try {
                    if ($stmt->execute()) {
                        $diagnosis_id = $conn->insert_id;
                        
                        // Also create a consultation history entry linked to this diagnosis
                        $consultation_stmt = $conn->prepare("
                            INSERT INTO consultation_history 
                            (patient_id, record_id, diagnosis_id, consultation_type, consultation_date, 
                             physician_name, diagnosis, treatment, recommendations, notes, follow_up_date) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        
                        $consultation_type = $diagnosis_type . '_diagnosis';
                        $physician_name = $provider_name;
                        $diagnosis_text = $assessment ?: $chief_complaint;
                        $treatment_text = $plan ?: 'Treatment plan documented';
                        $recommendations_text = $notes ?: 'Follow provider instructions';
                        
                        $consultation_stmt->bind_param("iiissssssss", 
                            $patient_id, $record_id, $diagnosis_id, $consultation_type, $diagnosis_date,
                            $physician_name, $diagnosis_text, $treatment_text, $recommendations_text, $notes, $follow_up_date
                        );
                        
                        $consultation_stmt->execute();
                        $consultation_stmt->close();
                        
                        $success_message = ucfirst($diagnosis_type) . " diagnosis added successfully!";
                        // Redirect to prevent form resubmission
                        header("Location: medical_diagnosis_form.php?patient_id=" . $patient_id . "&record_id=" . $record_id . "&type=" . $type . "&diagnosis_type=" . $diagnosis_type . "&success=" . urlencode($success_message));
                        exit();
                    } else {
                        $error_message = "Error adding diagnosis: " . $conn->error;
                    }
                } catch (mysqli_sql_exception $e) {
                    // Handle foreign key constraint errors or other database errors
                    if (strpos($e->getMessage(), 'foreign key constraint') !== false) {
                        if (strpos($e->getMessage(), 'patient_id') !== false) {
                            $error_message = "Error: The patient ID does not exist in the database. Please select a valid patient.";
                        } elseif (strpos($e->getMessage(), 'record_id') !== false) {
                            $error_message = "Error: The medical record ID does not exist in the database. Please select a valid record.";
                        } else {
                            $error_message = "Error: Invalid data provided. Please verify that the patient and record exist in the database.";
                        }
                    } else {
                        $error_message = "Database error: " . $e->getMessage();
                    }
                }
                $stmt->close();
            }
        }
    }
}

// Set provider role based on diagnosis type
$provider_role = '';
switch ($diagnosis_type) {
    case 'nurse':
        $provider_role = 'Nurse';
        break;
    case 'dentist':
        $provider_role = 'Dentist';
        break;
    case 'doctor':
        $provider_role = 'Physician';
        break;
}

// Auto-fill provider name with current user's full name
$current_provider = $_SESSION['username'] ?? ''; // Default fallback
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $user_query = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
    $user_query->bind_param("i", $user_id);
    $user_query->execute();
    $user_result = $user_query->get_result();
    
    if ($user_result && $user_result->num_rows > 0) {
        $user_data = $user_result->fetch_assoc();
        if (!empty($user_data['full_name']) && trim($user_data['full_name']) !== '') {
            $current_provider = trim($user_data['full_name']);
        }
    }
    $user_query->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= ucfirst($diagnosis_type) ?> Diagnosis - BSU Clinic</title>
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
        .form-reset-button {
            background: linear-gradient(135deg, #6b7280, #9ca3af);
        }
        .form-reset-button:hover {
            background: linear-gradient(135deg, #4b5563, #6b7280);
        }
        .diagnosis-section {
            border-left: 4px solid #ea580c;
            background: #fffbeb;
        }
        .orange-form-input {
            border: 2px solid #fed7aa;
            background: #fff7ed;
        }
        .orange-form-input:focus {
            border-color: #ea580c;
            background: #ffedd5;
            box-shadow: 0 0 0 3px rgba(234, 88, 12, 0.1);
        }
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
        <div class="max-w-4xl mx-auto">
            <!-- Header -->
            <div class="flex justify-between items-center mb-6">
                <a href="view_record.php?type=<?= $type ?>&id=<?= $record_id ?>" 
                   class="red-orange-gradient-button text-white px-4 py-2 rounded-lg shadow hover:shadow-lg transition-all flex items-center gap-2">
                   <i class="bi bi-arrow-left"></i> Back to Record
                </a>
                <h1 class="text-2xl font-bold text-gray-800">
                    <?= ucfirst($diagnosis_type) ?> Diagnosis Form
                </h1>
            </div>

            <!-- Patient Info -->
            <?php if ($patient_info && $current_record): ?>
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
                        <label class="font-medium text-gray-600">Current Record:</label>
                        <p class="text-gray-800 font-semibold"><?= ucfirst(str_replace('_', ' ', $current_record['record_type'])) ?></p>
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

            <!-- Diagnosis Form -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <form method="POST" id="diagnosis-form">
                    <input type="hidden" name="patient_id" value="<?= $patient_id ?>">
                    <input type="hidden" name="record_id" value="<?= $record_id ?>">
                    <input type="hidden" name="diagnosis_type" value="<?= $diagnosis_type ?>">
                    <input type="hidden" name="provider_role" value="<?= $provider_role ?>">
                    
                    <!-- Basic Information -->
                    <div class="diagnosis-section rounded-lg p-4 mb-6">
                        <h3 class="text-lg font-semibold mb-4 text-gray-700">Basic Information</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Diagnosis Date *</label>
                                <input type="date" name="diagnosis_date" required 
                                       class="w-full orange-form-input rounded-lg px-3 py-2 focus:outline-none"
                                       value="<?= date('Y-m-d') ?>">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Provider Name *</label>
                                <input type="text" name="provider_name" required 
                                       class="w-full orange-form-input rounded-lg px-3 py-2 focus:outline-none"
                                       placeholder="Enter provider name"
                                       value="<?= htmlspecialchars($current_provider) ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Chief Complaint -->
                    <div class="diagnosis-section rounded-lg p-4 mb-6">
                        <h3 class="text-lg font-semibold mb-4 text-gray-700">Chief Complaint</h3>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Chief Complaint *</label>
                            <textarea name="chief_complaint" rows="3" required
                                      class="w-full orange-form-input rounded-lg px-3 py-2 focus:outline-none"
                                      placeholder="Describe the patient's main complaint or reason for visit"></textarea>
                        </div>
                    </div>

                    <!-- Subjective Findings -->
                    <div class="diagnosis-section rounded-lg p-4 mb-6">
                        <h3 class="text-lg font-semibold mb-4 text-gray-700">Subjective Findings</h3>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">History of Present Illness</label>
                            <textarea name="subjective_findings" rows="4"
                                      class="w-full orange-form-input rounded-lg px-3 py-2 focus:outline-none"
                                      placeholder="Include patient's symptoms, duration, severity, associated factors, etc."></textarea>
                        </div>
                    </div>

                    <!-- Objective Findings -->
                    <div class="diagnosis-section rounded-lg p-4 mb-6">
                        <h3 class="text-lg font-semibold mb-4 text-gray-700">Objective Findings</h3>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Physical Examination Findings</label>
                            <textarea name="objective_findings" rows="4"
                                      class="w-full orange-form-input rounded-lg px-3 py-2 focus:outline-none"
                                      placeholder="Include vital signs, physical exam findings, test results, etc."></textarea>
                        </div>
                    </div>

                    <!-- Assessment & Plan -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <!-- Assessment -->
                        <div class="diagnosis-section rounded-lg p-4">
                            <h3 class="text-lg font-semibold mb-4 text-gray-700">Assessment</h3>
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Diagnosis/Impression</label>
                                <textarea name="assessment" rows="4"
                                          class="w-full orange-form-input rounded-lg px-3 py-2 focus:outline-none"
                                          placeholder="Enter diagnosis or clinical impression"></textarea>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Severity</label>
                                    <select name="severity" class="w-full orange-form-input rounded-lg px-3 py-2 focus:outline-none">
                                        <option value="mild">Mild</option>
                                        <option value="moderate">Moderate</option>
                                        <option value="severe">Severe</option>
                                        <option value="critical">Critical</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                                    <select name="status" class="w-full orange-form-input rounded-lg px-3 py-2 focus:outline-none">
                                        <option value="active">Active</option>
                                        <option value="resolved">Resolved</option>
                                        <option value="chronic">Chronic</option>
                                        <option value="follow_up">Follow-up Required</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Plan -->
                        <div class="diagnosis-section rounded-lg p-4">
                            <h3 class="text-lg font-semibold mb-4 text-gray-700">Plan</h3>
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Treatment Plan</label>
                                <textarea name="plan" rows="4"
                                          class="w-full orange-form-input rounded-lg px-3 py-2 focus:outline-none"
                                          placeholder="Enter treatment plan, procedures, referrals, etc."></textarea>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Medications Prescribed</label>
                                <textarea name="medications_prescribed" rows="3"
                                          class="w-full orange-form-input rounded-lg px-3 py-2 focus:outline-none"
                                          placeholder="List medications, dosage, frequency, duration"></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Follow-up & Notes -->
                    <div class="diagnosis-section rounded-lg p-4 mb-6">
                        <h3 class="text-lg font-semibold mb-4 text-gray-700">Follow-up & Additional Notes</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="follow_up_required" value="1" 
                                           class="rounded border-orange-300 text-orange-600 focus:ring-orange-500">
                                    <span class="text-sm font-medium text-gray-700">Follow-up Required</span>
                                </label>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Follow-up Date</label>
                                <input type="date" name="follow_up_date" 
                                       class="w-full orange-form-input rounded-lg px-3 py-2 focus:outline-none"
                                       min="<?= date('Y-m-d', strtotime('+1 day')) ?>">
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Additional Notes</label>
                            <textarea name="notes" rows="3"
                                      class="w-full orange-form-input rounded-lg px-3 py-2 focus:outline-none"
                                      placeholder="Any additional notes, patient education, or instructions"></textarea>
                        </div>
                    </div>

                    <!-- Form Actions -->
                    <div class="flex gap-4">
                        <button type="submit" name="add_diagnosis" 
                                class="orange-gradient-button text-white px-6 py-3 rounded-lg shadow hover:shadow-lg transition-all flex items-center gap-2 font-semibold">
                            <i class="bi bi-file-medical"></i> Save Diagnosis
                        </button>
                        <button type="button" id="reset-form" 
                                class="form-reset-button text-white px-6 py-3 rounded-lg shadow hover:shadow-lg transition-all flex items-center gap-2 font-semibold">
                            <i class="bi bi-arrow-clockwise"></i> Reset Form
                        </button>
                        <a href="diagnosis_history.php?patient_id=<?= $patient_id ?>&record_id=<?= $record_id ?>&type=<?= $type ?>" 
                           class="orange-gradient-button text-white px-6 py-3 rounded-lg shadow hover:shadow-lg transition-all flex items-center gap-2 font-semibold">
                            <i class="bi bi-clock-history"></i> View Diagnosis History
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('diagnosis-form');
            const resetButton = document.getElementById('reset-form');
            
            // Form reset functionality
            resetButton.addEventListener('click', function() {
                if (confirm('Are you sure you want to reset the form? All entered data will be lost.')) {
                    form.reset();
                    
                    // Reset date to today
                    const dateField = document.querySelector('input[name="diagnosis_date"]');
                    if (dateField) {
                        dateField.value = '<?= date('Y-m-d') ?>';
                    }
                    
                    // Reset provider name to current user
                    const providerField = document.querySelector('input[name="provider_name"]');
                    if (providerField) {
                        providerField.value = '<?= htmlspecialchars($current_provider) ?>';
                    }
                    
                    showNotification('Form has been reset successfully!', 'success');
                }
            });

            // Form validation
            form.addEventListener('submit', function(e) {
                const providerName = document.querySelector('input[name="provider_name"]').value.trim();
                const diagnosisDate = document.querySelector('input[name="diagnosis_date"]').value;
                const chiefComplaint = document.querySelector('textarea[name="chief_complaint"]').value.trim();
                
                if (!providerName) {
                    e.preventDefault();
                    showNotification('Please enter provider name.', 'error');
                    return false;
                }
                
                if (!diagnosisDate) {
                    e.preventDefault();
                    showNotification('Please select diagnosis date.', 'error');
                    return false;
                }

                if (!chiefComplaint) {
                    e.preventDefault();
                    showNotification('Please enter chief complaint.', 'error');
                    return false;
                }
            });

            // Auto-show/hide follow-up date based on checkbox
            const followUpCheckbox = document.querySelector('input[name="follow_up_required"]');
            const followUpDateField = document.querySelector('input[name="follow_up_date"]');
            
            if (followUpCheckbox && followUpDateField) {
                followUpCheckbox.addEventListener('change', function() {
                    if (this.checked) {
                        followUpDateField.required = true;
                    } else {
                        followUpDateField.required = false;
                        followUpDateField.value = '';
                    }
                });
            }

            // Notification function
            function showNotification(message, type) {
                // Remove existing notifications
                const existingNotifications = document.querySelectorAll('.custom-notification');
                existingNotifications.forEach(notification => notification.remove());

                // Create new notification
                const notification = document.createElement('div');
                notification.className = `custom-notification fixed top-4 right-4 z-50 px-6 py-4 rounded-lg shadow-lg text-white font-semibold ${
                    type === 'success' ? 'bg-green-500' : 'bg-red-500'
                }`;
                notification.textContent = message;
                
                document.body.appendChild(notification);
                
                // Remove notification after 5 seconds
                setTimeout(() => {
                    notification.remove();
                }, 5000);
            }
        });
    
    </script>
</body>
</html>