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

// Get current user's full name for form display
$current_physician = $_SESSION['username'] ?? '';
$current_physician_full_name = $current_physician;

if (!empty($current_physician)) {
    $user_query = $conn->prepare("
        SELECT full_name 
        FROM users 
        WHERE BINARY username = ? 
        LIMIT 1
    ");
    $user_query->bind_param("s", $current_physician);
    $user_query->execute();
    $user_result = $user_query->get_result();
    
    if ($user_result->num_rows > 0) {
        $user_data = $user_result->fetch_assoc();
        if (!empty($user_data['full_name']) && trim($user_data['full_name']) !== '') {
            $current_physician_full_name = trim($user_data['full_name']);
        }
    }
    $user_query->close();
}

// Fetch consultation history with diagnosis information
$consultations = [];
if ($patient_id) {
    $stmt = $conn->prepare("
        SELECT ch.*, mr.record_type, md.diagnosis_type, md.chief_complaint, md.assessment, md.severity, md.status as diagnosis_status
        FROM consultation_history ch 
        JOIN medical_records mr ON ch.record_id = mr.id 
        LEFT JOIN medical_diagnoses md ON ch.diagnosis_id = md.id
        WHERE ch.patient_id = ? 
        ORDER BY ch.consultation_date DESC, ch.created_at DESC
    ");
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    $consultations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Handle new consultation submission
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['add_consultation'])) {
    $patient_id = $_POST['patient_id'];
    $record_id = $_POST['record_id'];
    $consultation_type = $_POST['consultation_type'];
    $consultation_date = $_POST['consultation_date'];
    $physician_name = $_POST['physician_name'];
    $diagnosis = $_POST['diagnosis'];
    $treatment = $_POST['treatment'];
    $recommendations = $_POST['recommendations'];
    $notes = $_POST['notes'];
    $follow_up_date = $_POST['follow_up_date'] ?? null;

    // Validate required fields
    if (empty($physician_name) || empty($consultation_date)) {
        $error_message = "Physician name and consultation date are required.";
    } else {
        // Check if physician_name is a full name and convert to username if found
        $physician_name_to_save = trim($physician_name);
        $check_user_query = $conn->prepare("
            SELECT username 
            FROM users 
            WHERE BINARY full_name = ? 
            LIMIT 1
        ");
        $check_user_query->bind_param("s", $physician_name_to_save);
        $check_user_query->execute();
        $check_user_result = $check_user_query->get_result();
        
        if ($check_user_result->num_rows > 0) {
            // Found a user with this full name, use their username instead
            $user_data = $check_user_result->fetch_assoc();
            $physician_name_to_save = $user_data['username'];
        }
        $check_user_query->close();
        
        // If provider_username is provided (from hidden field) and matches current user, use it
        if (isset($_POST['provider_username']) && !empty($_POST['provider_username'])) {
            $submitted_username = trim($_POST['provider_username']);
            $current_user_check = $conn->prepare("
                SELECT full_name 
                FROM users 
                WHERE BINARY username = ? 
                LIMIT 1
            ");
            $current_user_check->bind_param("s", $submitted_username);
            $current_user_check->execute();
            $current_user_result = $current_user_check->get_result();
            
            if ($current_user_result->num_rows > 0) {
                $current_user_data = $current_user_result->fetch_assoc();
                if (trim($current_user_data['full_name']) === $physician_name_to_save) {
                    // The submitted name matches the current user's full name, use username
                    $physician_name_to_save = $submitted_username;
                }
            }
            $current_user_check->close();
        }
        
        $stmt = $conn->prepare("
            INSERT INTO consultation_history 
            (patient_id, record_id, consultation_type, consultation_date, physician_name, diagnosis, treatment, recommendations, notes, follow_up_date) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->bind_param("iissssssss", 
            $patient_id, $record_id, $consultation_type, $consultation_date, 
            $physician_name_to_save, $diagnosis, $treatment, $recommendations, $notes, $follow_up_date
        );

        if ($stmt->execute()) {
            $success_message = "Consultation record added successfully!";
            // Redirect to avoid form resubmission
            header("Location: consultation_history.php?patient_id=" . $patient_id . "&record_id=" . $record_id . "&type=" . $type . "&success=" . urlencode($success_message));
            exit();
        } else {
            $error_message = "Error adding consultation record: " . $conn->error;
        }
    }
}

// Handle delete consultation
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['delete_consultation'])) {
    $consultation_id = $_POST['consultation_id'];
    
    $stmt = $conn->prepare("DELETE FROM consultation_history WHERE id = ?");
    $stmt->bind_param("i", $consultation_id);
    
    if ($stmt->execute()) {
        $success_message = "Consultation record deleted successfully!";
        header("Location: consultation_history.php?patient_id=" . $patient_id . "&record_id=" . $record_id . "&type=" . $type . "&success=" . urlencode($success_message));
        exit();
    } else {
        $error_message = "Error deleting consultation record: " . $conn->error;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consultation History - BSU Clinic</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .red-orange-gradient-button {
            background: linear-gradient(135deg, #dc2626, #ea580c);
        }
        .red-orange-gradient-button:hover {
            background: linear-gradient(135deg, #b91c1c, #c2410c);
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
        .orange-form-input {
            border: 2px solid #fed7aa;
            background: #fff7ed;
        }
        .orange-form-input:focus {
            border-color: #ea580c;
            background: #ffedd5;
            box-shadow: 0 0 0 3px rgba(234, 88, 12, 0.1);
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
<body class="bg-gradient-to-br from-orange-50 to-red-50">
<?php include '../../includes/navigation.php'; ?>
    <div class="min-h-screen py-8 px-4 pt-20">
        <div class="max-w-6xl mx-auto">
            <!-- Header -->
            <div class="flex justify-between items-center mb-6">
                <a href="view_record.php?type=<?= $type ?>&id=<?= $record_id ?>" 
                   class="red-orange-gradient-button text-white px-4 py-2 rounded-lg shadow hover:shadow-lg transition-all flex items-center gap-2">
                   <i class="bi bi-arrow-left"></i> Back to Record
                </a>
                <h1 class="text-2xl font-bold text-gray-800">Consultation History</h1>
                <div class="flex gap-2">
                    <a href="medical_diagnosis_form.php?patient_id=<?= $patient_id ?>&record_id=<?= $record_id ?>&type=<?= $type ?>&diagnosis_type=nurse" 
                       class="orange-gradient-button text-white px-4 py-2 rounded-lg shadow hover:shadow-lg transition-all flex items-center gap-2">
                       <i class="bi bi-file-medical"></i> Add Diagnosis
                    </a>
                </div>
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
                        <label class="font-medium text-gray-600">Current Record:</label>
                        <p class="text-gray-800 font-semibold"><?= ucfirst(str_replace('_', ' ', $current_record['record_type'])) ?></p>
                    </div>
                    <div>
                        <label class="font-medium text-gray-600">Record Date:</label>
                        <p class="text-gray-800 font-semibold"><?= date('M j, Y', strtotime($current_record['examination_date'] ?? $current_record['created_at'])) ?></p>
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

            <!-- Add Consultation Form -->
            <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                <h2 class="text-xl font-semibold mb-4 text-gray-700">Add New Consultation</h2>
                <form method="POST" id="consultation-form">
                    <input type="hidden" name="patient_id" value="<?= $patient_id ?>">
                    <input type="hidden" name="record_id" value="<?= $record_id ?>">
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Consultation Type *</label>
                            <select name="consultation_type" required class="w-full orange-form-input rounded-lg px-3 py-2 focus:outline-none">
                                <option value="medical">Medical Consultation</option>
                                <option value="dental">Dental Consultation</option>
                                <option value="history">Medical History Review</option>
                                <option value="followup">Follow-up Consultation</option>
                                <option value="emergency">Emergency Consultation</option>
                                <option value="nurse_diagnosis">Nurse Diagnosis</option>
                                <option value="dental_diagnosis">Dental Diagnosis</option>
                                <option value="physician_diagnosis">Physician Diagnosis</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Consultation Date *</label>
                            <input type="date" name="consultation_date" required 
                                   class="w-full orange-form-input rounded-lg px-3 py-2 focus:outline-none"
                                   value="<?= date('Y-m-d') ?>">
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Physician/Dentist Name *</label>
                        <input type="text" name="physician_name" required 
                               class="w-full orange-form-input rounded-lg px-3 py-2 focus:outline-none"
                               placeholder="Enter physician or dentist name"
                               value="<?= htmlspecialchars($current_physician_full_name) ?>">
                        <input type="hidden" name="provider_username" value="<?= htmlspecialchars($current_physician) ?>">
                    </div>

                    <div class="grid grid-cols-1 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Diagnosis / Findings *</label>
                            <textarea name="diagnosis" rows="3" required
                                      class="w-full orange-form-input rounded-lg px-3 py-2 focus:outline-none"
                                      placeholder="Enter diagnosis, findings, or observations"></textarea>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Treatment / Procedures *</label>
                            <textarea name="treatment" rows="3" required
                                      class="w-full orange-form-input rounded-lg px-3 py-2 focus:outline-none"
                                      placeholder="Enter treatment details or procedures performed"></textarea>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Recommendations *</label>
                            <textarea name="recommendations" rows="2" required
                                      class="w-full orange-form-input rounded-lg px-3 py-2 focus:outline-none"
                                      placeholder="Enter recommendations for patient"></textarea>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Additional Notes</label>
                            <textarea name="notes" rows="2" 
                                      class="w-full orange-form-input rounded-lg px-3 py-2 focus:outline-none"
                                      placeholder="Any additional notes or observations"></textarea>
                        </div>
                    </div>

                    <div class="mb-6">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Follow-up Date (Optional)</label>
                        <input type="date" name="follow_up_date" 
                               class="w-full orange-form-input rounded-lg px-3 py-2 focus:outline-none"
                               min="<?= date('Y-m-d') ?>">
                    </div>

                    <div class="flex gap-4">
                        <button type="submit" name="add_consultation" 
                                class="orange-gradient-button text-white px-6 py-3 rounded-lg shadow hover:shadow-lg transition-all flex items-center gap-2 font-semibold">
                            <i class="bi bi-plus-circle"></i> Add Consultation Record
                        </button>
                        <button type="button" id="reset-form" 
                                class="form-reset-button text-white px-6 py-3 rounded-lg shadow hover:shadow-lg transition-all flex items-center gap-2 font-semibold">
                            <i class="bi bi-arrow-clockwise"></i> Reset Form
                        </button>
                    </div>
                </form>
            </div>

            <!-- Consultation History List -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-xl font-semibold text-gray-700">Consultation History</h2>
                    <span class="bg-orange-100 text-orange-800 px-3 py-1 rounded-full text-sm font-semibold">
                        Total: <?= count($consultations) ?> records
                    </span>
                </div>
                
                <?php if (empty($consultations)): ?>
                    <div class="text-center py-12 text-gray-500">
                        <i class="bi bi-clipboard-x text-6xl mb-4 block"></i>
                        <p class="text-lg mb-2">No consultation records found</p>
                        <p class="text-sm">Start by adding a consultation record using the form above.</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-6">
                        <?php foreach ($consultations as $consultation): ?>
                        <div class="border border-gray-200 rounded-lg p-6 hover:shadow-lg transition-shadow bg-white">
                            <div class="flex justify-between items-start mb-4">
                                <div class="flex-1">
                                    <div class="flex items-center gap-3 mb-2 flex-wrap">
                                        <h3 class="font-semibold text-xl text-gray-800">
                                            <?= ucfirst($consultation['consultation_type']) ?> Consultation
                                            <?php if ($consultation['diagnosis_type']): ?>
                                                <span class="diagnosis-badge-<?= $consultation['diagnosis_type'] ?> text-white px-2 py-1 rounded text-sm font-semibold ml-2">
                                                    Linked to <?= $consultation['diagnosis_type'] ?> Diagnosis
                                                </span>
                                            <?php endif; ?>
                                        </h3>
                                        <span class="bg-orange-500 text-white px-2 py-1 rounded text-xs font-semibold">
                                            <?= date('M j, Y', strtotime($consultation['consultation_date'])) ?>
                                        </span>
                                        <?php if ($consultation['follow_up_date']): ?>
                                            <span class="bg-orange-600 text-white px-2 py-1 rounded text-xs font-semibold">
                                                Follow-up: <?= date('M j, Y', strtotime($consultation['follow_up_date'])) ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($consultation['severity']): ?>
                                            <span class="severity-<?= $consultation['severity'] ?> text-white px-2 py-1 rounded text-xs font-semibold">
                                                <?= ucfirst($consultation['severity']) ?> Severity
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-sm text-gray-600">
                                        <i class="bi bi-person-check"></i> 
                                        Conducted by: <?= htmlspecialchars($consultation['physician_name']) ?>
                                    </p>
                                    
                                    <!-- Display linked diagnosis information -->
                                    <?php if ($consultation['diagnosis_type'] && $consultation['chief_complaint']): ?>
                                    <div class="mt-2 p-3 bg-purple-50 rounded border border-purple-200">
                                        <h4 class="font-semibold text-purple-700 text-sm mb-1">
                                            <i class="bi bi-file-medical"></i> 
                                            Linked Diagnosis Information
                                            <?php if ($consultation['diagnosis_status']): ?>
                                                <span class="bg-gray-600 text-white px-2 py-1 rounded text-xs ml-2">
                                                    <?= ucfirst($consultation['diagnosis_status']) ?>
                                                </span>
                                            <?php endif; ?>
                                        </h4>
                                        <p class="text-xs text-purple-600">
                                            <strong>Chief Complaint:</strong> <?= htmlspecialchars($consultation['chief_complaint']) ?>
                                        </p>
                                        <?php if ($consultation['assessment']): ?>
                                            <p class="text-xs text-purple-600 mt-1">
                                                <strong>Assessment:</strong> <?= htmlspecialchars(substr($consultation['assessment'], 0, 150)) ?><?= strlen($consultation['assessment']) > 150 ? '...' : '' ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <p class="text-xs text-gray-500 mt-1">
                                        Recorded: <?= date('M j, Y g:i A', strtotime($consultation['created_at'])) ?>
                                    </p>
                                </div>
                                <form method="POST" onsubmit="return confirm('Are you sure you want to delete this consultation record? This action cannot be undone.');" class="flex-shrink-0">
                                    <input type="hidden" name="consultation_id" value="<?= $consultation['id'] ?>">
                                    <input type="hidden" name="patient_id" value="<?= $patient_id ?>">
                                    <button type="submit" name="delete_consultation" 
                                            class="text-red-600 hover:text-red-800 transition-colors bg-red-50 hover:bg-red-100 p-2 rounded-lg">
                                        <i class="bi bi-trash"></i> Delete
                                    </button>
                                </form>
                            </div>
                            
                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-4">
                                <div class="space-y-3">
                                    <?php if ($consultation['diagnosis']): ?>
                                        <div>
                                            <h4 class="font-semibold text-gray-700 mb-1">Diagnosis / Findings:</h4>
                                            <p class="text-gray-700 bg-gray-50 p-3 rounded border"><?= nl2br(htmlspecialchars($consultation['diagnosis'])) ?></p>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($consultation['treatment']): ?>
                                        <div>
                                            <h4 class="font-semibold text-gray-700 mb-1">Treatment / Procedures:</h4>
                                            <p class="text-gray-700 bg-gray-50 p-3 rounded border"><?= nl2br(htmlspecialchars($consultation['treatment'])) ?></p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="space-y-3">
                                    <?php if ($consultation['recommendations']): ?>
                                        <div>
                                            <h4 class="font-semibold text-gray-700 mb-1">Recommendations:</h4>
                                            <p class="text-gray-700 bg-gray-50 p-3 rounded border"><?= nl2br(htmlspecialchars($consultation['recommendations'])) ?></p>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($consultation['notes']): ?>
                                        <div>
                                            <h4 class="font-semibold text-gray-700 mb-1">Additional Notes:</h4>
                                            <p class="text-gray-700 bg-gray-50 p-3 rounded border"><?= nl2br(htmlspecialchars($consultation['notes'])) ?></p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="border-t border-gray-200 pt-3">
                                <p class="text-xs text-gray-500">
                                    <strong>Related Record:</strong> 
                                    <?= ucfirst(str_replace('_', ' ', $consultation['record_type'])) ?> 
                                    (ID: <?= $consultation['record_id'] ?>)
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
        // Auto-fill physician name with current user's name
        document.addEventListener('DOMContentLoaded', function() {
            const physicianField = document.querySelector('input[name="physician_name"]');
            if (physicianField && !physicianField.value) {
                physicianField.value = '<?= htmlspecialchars($current_physician_full_name, ENT_QUOTES) ?>';
            }

            // Form reset functionality
            const resetButton = document.getElementById('reset-form');
            const form = document.getElementById('consultation-form');
            
            resetButton.addEventListener('click', function() {
                // Reset form fields
                form.reset();
                
                // Reset physician name to current user
                if (physicianField) {
                    physicianField.value = '<?= $_SESSION['username'] ?? '' ?>';
                }
                
                // Reset date to today
                const dateField = document.querySelector('input[name="consultation_date"]');
                if (dateField) {
                    dateField.value = '<?= date('Y-m-d') ?>';
                }
                
                // Show success message
                showNotification('Form has been reset successfully!', 'success');
            });

            // Form validation
            form.addEventListener('submit', function(e) {
                const physicianName = document.querySelector('input[name="physician_name"]').value.trim();
                const consultationDate = document.querySelector('input[name="consultation_date"]').value;
                const diagnosis = document.querySelector('textarea[name="diagnosis"]').value.trim();
                const treatment = document.querySelector('textarea[name="treatment"]').value.trim();
                const recommendations = document.querySelector('textarea[name="recommendations"]').value.trim();
                
                if (!physicianName) {
                    e.preventDefault();
                    showNotification('Please enter physician/dentist name.', 'error');
                    return false;
                }
                
                if (!consultationDate) {
                    e.preventDefault();
                    showNotification('Please select consultation date.', 'error');
                    return false;
                }

                if (!diagnosis) {
                    e.preventDefault();
                    showNotification('Please enter diagnosis/findings.', 'error');
                    return false;
                }

                if (!treatment) {
                    e.preventDefault();
                    showNotification('Please enter treatment/procedures.', 'error');
                    return false;
                }

                if (!recommendations) {
                    e.preventDefault();
                    showNotification('Please enter recommendations.', 'error');
                    return false;
                }
            });

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