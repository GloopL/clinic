<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// First, try to include the database file and check if it works
try {
    include __DIR__ . '/../config/database.php';
    
    // Test the database connection
    if (!$conn) {
        throw new Exception("Database connection failed");
    }
} catch (Exception $e) {
    die("Error connecting to database: " . $e->getMessage());
}

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    header("Location: $protocol://$host/index.php");
    exit();
}

$success_message = '';
$error_message = '';
$has_existing_form = false;
$existing_form_id = null;
$patient_data = null;

// Fetch logged-in user's patient data from registration
if (isset($_SESSION['username'])) {
    $stmt = $conn->prepare("SELECT * FROM patients WHERE student_id = ?");
    if ($stmt) {
        $stmt->bind_param("s", $_SESSION['username']);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $patient_data = $result->fetch_assoc();
            
            // Calculate age from date_of_birth if age is not set or empty
            if (empty($patient_data['age']) && !empty($patient_data['date_of_birth'])) {
                $dob = new DateTime($patient_data['date_of_birth']);
                $now = new DateTime();
                $patient_data['age'] = $now->diff($dob)->y;
            }
            
            // Create middle initial
            $patient_data['middle_initial'] = !empty($patient_data['middle_name']) ? substr($patient_data['middle_name'], 0, 1) . '.' : '';
            
            // Create formatted name with proper capitalization
            $patient_data['first_name_cap'] = ucwords(strtolower($patient_data['first_name']));
            $patient_data['middle_initial_cap'] = !empty($patient_data['middle_initial']) ? strtoupper($patient_data['middle_initial'][0]) . '.' : '';
            $patient_data['last_name_cap'] = ucwords(strtolower($patient_data['last_name']));
            
            $patient_data['full_name_formatted'] = $patient_data['first_name_cap'] . 
                                                  (!empty($patient_data['middle_initial_cap']) ? ' ' . $patient_data['middle_initial_cap'] : '') . 
                                                  ' ' . $patient_data['last_name_cap'];
            
            // Check for existing history form - ONLY pending or verified, NOT rejected
            $check_stmt = $conn->prepare("
                SELECT mr.id 
                FROM medical_records mr 
                INNER JOIN history_forms hf ON mr.id = hf.record_id
                WHERE mr.patient_id = ? 
                AND mr.record_type = 'history_form' 
                AND hf.verification_status IN ('pending', 'verified')
            ");
            if ($check_stmt) {
                $check_stmt->bind_param("i", $patient_data['id']);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows > 0) {
                    $has_existing_form = true;
                    $existing_form = $check_result->fetch_assoc();
                    $existing_form_id = $existing_form['id'];
                }
                $check_stmt->close();
            }
        }
        $stmt->close();
    }
}

// Process form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get patient ID from hidden field
    $patient_id = isset($_POST['patient_id']) ? intval($_POST['patient_id']) : 0;
    
    if ($patient_id <= 0) {
        $error_message = "Patient information not found. Please complete your registration first.";
    } else {
        // Get form data
        $sports_event = isset($_POST['sports_event']) ? trim($_POST['sports_event']) : '';
        $date_of_examination = isset($_POST['date_of_examination']) ? $_POST['date_of_examination'] : date('Y-m-d');
        
        if (empty($sports_event)) {
            $error_message = "Please enter the sports event.";
        } else {
            // Check if user confirmed to replace existing form
            $should_replace = false;
            
            // Check if this is a replacement request
            $replace_requested = isset($_POST['replace_existing']) && $_POST['replace_existing'] == '1';
            
            if ($has_existing_form) {
                if (!$replace_requested) {
                    // Show error asking for confirmation
                    $error_message = "You already have a submitted history form. Please check the 'I understand this will replace my existing form' box and try again.";
                } else {
                    $should_replace = true;
                    // Instead of deleting, just update the existing medical record date
                    if ($existing_form_id) {
                        $update_stmt = $conn->prepare("UPDATE medical_records SET examination_date = ? WHERE id = ?");
                        if ($update_stmt) {
                            $update_stmt->bind_param("si", $date_of_examination, $existing_form_id);
                            $update_stmt->execute();
                            $update_stmt->close();
                            
                            // Now create a new entry in history_forms linked to the existing medical record
                            $stmt2 = $conn->prepare("INSERT INTO history_forms (record_id) VALUES (?)");
                            if ($stmt2) {
                                $stmt2->bind_param("i", $existing_form_id);
                                $stmt2->execute();
                                $stmt2->close();
                            }
                        }
                    }
                }
            }
            
            if (empty($error_message)) {
                if (!$has_existing_form || !$should_replace) {
                    // Create NEW medical record entry (first time submission or no replacement)
                    $stmt = $conn->prepare("INSERT INTO medical_records (patient_id, record_type, examination_date) VALUES (?, 'history_form', ?)");
                    if ($stmt) {
                        $stmt->bind_param("is", $patient_id, $date_of_examination);
                        
                        if ($stmt->execute()) {
                            $record_id = $conn->insert_id;
                            
                            // Simple insert - just the record_id (all other fields have defaults)
                            $stmt2 = $conn->prepare("INSERT INTO history_forms (record_id) VALUES (?)");
                            if ($stmt2) {
                                $stmt2->bind_param("i", $record_id);
                                $stmt2->execute();
                                $stmt2->close();
                            }
                        }
                        $stmt->close();
                    }
                }
                
                // Generate QR code (use existing record_id if replacing, new if not)
                $qr_record_id = $should_replace ? $existing_form_id : $record_id;
                $qr_data = "record_id=" . $qr_record_id . "&type=history_form&date=" . $date_of_examination;
                $qr_code = base64_encode($qr_data);

                // Update patient with QR code
                $stmt3 = $conn->prepare("UPDATE patients SET qr_code = ? WHERE id = ?");
                if ($stmt3) {
                    $stmt3->bind_param("si", $qr_code, $patient_id);
                    $stmt3->execute();
                    $stmt3->close();
                }

                // Add to analytics data
                $stmt4 = $conn->prepare("INSERT INTO analytics_data (data_type, data_value, data_label, data_date) VALUES ('history_form', 1, 'New History Form', CURDATE())");
                if ($stmt4) {
                    $stmt4->execute();
                    $stmt4->close();
                }

                // Show success message on same page
                $success_message = $should_replace ? 
                    "Form successfully updated! Please wait for admin verification." : 
                    "Form successfully submitted! Please wait for admin verification.";
                
                $activity_type = 'medical_history';
                $description = $should_replace ? 'Medical History Form Updated' : 'Medical History Form Submitted';
                
                $activity_stmt = $conn->prepare("INSERT INTO user_activities (user_id, activity_type, activity_description) VALUES (?, ?, ?)");
                if ($activity_stmt) {
                    $activity_stmt->bind_param("iss", $_SESSION['user_id'], $activity_type, $description);
                    $activity_stmt->execute();
                    $activity_stmt->close();
                }
                
                // Clear POST data to prevent resubmission
                $_POST = array();
                
                // Update the existing form status
                $has_existing_form = true;
            }
        }
    }
}

// Generate control number
$control_number = "LIPA 25-" . date('Ymd') . '-' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 6));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>History Form - BSU Clinic Records</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        .auto-filled { background-color: #f0fff4 !important; border-color: #68d391 !important; }
        .manual-input { background-color: #fff7ed !important; border-color: #fed7aa !important; }
        .form-header { background: linear-gradient(135deg, #1e40af, #3b82f6, #60a5fa); }
        .btn-primary { background: linear-gradient(135deg, #1e40af, #3b82f6) !important; color: white !important; border: none !important; }
        .btn-primary:hover { background: linear-gradient(135deg, #1e3a8a, #2563eb) !important; }
        .existing-form-alert { background: linear-gradient(135deg, #fef3c7, #fde68a); border: 2px solid #f59e0b; color: #92400e; }
    </style>
</head>
<body class="bg-gradient-to-br from-blue-50 to-indigo-50">
    <div class="bg-gray-100 min-h-screen py-8">
        <div class="max-w-5xl mx-auto px-4 mb-6">
            <a href="../user_dashboard.php" class="inline-flex items-center gap-2 btn-primary text-white font-semibold px-4 py-2 rounded-lg shadow hover:shadow-lg transition">
                <i class="bi bi-arrow-left"></i> Back to Dashboard
            </a>
        </div>
        <div class="max-w-5xl mx-auto px-4">
            <div class="bg-white shadow-lg rounded-xl overflow-hidden">
                <div class="form-header text-white px-8 py-6">
                    <h4 class="text-2xl font-bold mb-2">HISTORY FORM FOR STUDENT-ATHLETES IN SPORTS EVENTS</h4>
                    <p class="mb-0 text-sm opacity-90">Reference No.: BatStateU-FO-HSD-17 | Effectivity Date: March 12, 2024 | Revision No.: 03</p>
                </div>
                <div class="px-8 py-8">
                    <?php if ($has_existing_form && empty($error_message) && empty($success_message)): ?>
                        <div class="existing-form-alert p-4 rounded-lg mb-6">
                            <div class="flex items-center">
                                <i class="bi bi-exclamation-triangle-fill text-xl mr-3"></i>
                                <div>
                                    <h5 class="font-bold">Existing Form Found</h5>
                                    <p class="text-sm">You have already submitted a history form. Submitting a new one will replace your previous submission.</p>
                                </div>
                            </div>
                            <div class="mt-3">
                                <label class="flex items-center">
                                    <input type="checkbox" id="confirm_replace" class="mr-2">
                                    <span>I understand this will replace my existing form</span>
                                </label>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($success_message)): ?>
                        <div class="mb-6 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg">
                            <i class="bi bi-check-circle-fill mr-2"></i><?php echo $success_message; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($error_message)): ?>
                        <div class="mb-6 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg">
                            <i class="bi bi-exclamation-triangle-fill mr-2"></i><?php echo $error_message; ?>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" id="historyForm" onsubmit="return validateForm()">
                        <input type="hidden" name="replace_existing" id="replace_existing" value="0">
                        
                        <!-- Hidden fields for database -->
                        <input type="hidden" id="patient_id" name="patient_id" value="<?php echo isset($patient_data['id']) ? htmlspecialchars($patient_data['id']) : ''; ?>">
                        <input type="hidden" id="first_name" name="first_name" value="<?php echo isset($patient_data['first_name_cap']) ? htmlspecialchars($patient_data['first_name_cap']) : ''; ?>">
                        <input type="hidden" id="middle_name" name="middle_name" value="<?php echo isset($patient_data['middle_name']) ? htmlspecialchars(ucwords(strtolower($patient_data['middle_name']))) : ''; ?>">
                        <input type="hidden" id="last_name" name="last_name" value="<?php echo isset($patient_data['last_name_cap']) ? htmlspecialchars($patient_data['last_name_cap']) : ''; ?>">
                        <input type="hidden" id="student_id" name="student_id" value="<?php echo isset($patient_data['student_id']) ? htmlspecialchars($patient_data['student_id']) : ''; ?>">
                        
                        <!-- Form fields remain the same as before -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                            <div class="bg-blue-50 p-4 rounded-lg border border-blue-200">
                                <label class="block font-medium mb-2 text-blue-700">
                                    <i class="bi bi-person-badge mr-2"></i>Student ID
                                </label>
                                <input type="text" 
                                       class="w-full rounded border-2 border-blue-300 px-3 py-2 bg-white font-semibold text-blue-800" 
                                       value="<?php echo isset($patient_data['student_id']) ? htmlspecialchars($patient_data['student_id']) : 'Not found in database'; ?>" 
                                       readonly>
                                <p class="text-xs text-blue-600 mt-2 flex items-center">
                                    <i class="bi bi-info-circle mr-1"></i> This is your registered student ID
                                </p>
                            </div>
                            
                            <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                                <label for="control_no" class="block font-medium mb-2 text-gray-700">
                                    <i class="bi bi-hash mr-2"></i>Control No.
                                </label>
                                <input type="text" 
                                       class="w-full rounded border border-gray-300 px-3 py-2 bg-gray-100 font-mono text-gray-800" 
                                       id="control_no" 
                                       name="control_no" 
                                       value="<?php echo htmlspecialchars($control_number); ?>" 
                                       readonly>
                                <p class="text-xs text-gray-600 mt-2 flex items-center">
                                    <i class="bi bi-info-circle mr-1"></i> Auto-generated control number
                                </p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                            <div class="bg-green-50 p-4 rounded-lg border-2 border-green-200">
                                <label class="block font-medium mb-2 text-green-700">
                                    <i class="bi bi-person-circle mr-2"></i>Name
                                </label>
                                <input type="text" 
                                       class="w-full rounded border-2 border-green-300 px-3 py-2 bg-green-50 font-medium text-green-800 capitalize" 
                                       id="display_name" 
                                       value="<?php echo isset($patient_data['full_name_formatted']) ? htmlspecialchars($patient_data['full_name_formatted']) : 'Name not found'; ?>" 
                                       readonly>
                                <p class="text-xs text-green-600 mt-2 flex items-center">
                                    <?php if (isset($patient_data['full_name_formatted'])): ?>
                                        <i class="bi bi-check-circle-fill mr-1"></i> Auto-filled from your registration
                                    <?php else: ?>
                                        <i class="bi bi-exclamation-triangle mr-1"></i> Please complete your registration first
                                    <?php endif; ?>
                                </p>
                            </div>
                            
                            <div class="bg-green-50 p-4 rounded-lg border-2 border-green-200">
                                <label for="program_display" class="block font-medium mb-2 text-green-700">
                                    <i class="bi bi-mortarboard mr-2"></i>Grade/Level/Program
                                </label>
                                <input type="text" 
                                       class="w-full rounded border-2 border-green-300 px-3 py-2 bg-green-50 font-medium text-green-800" 
                                       id="program_display" 
                                       name="program" 
                                       value="<?php echo isset($patient_data['program']) ? htmlspecialchars($patient_data['program']) : ''; ?>" 
                                       <?php echo isset($patient_data['program']) ? 'readonly' : 'required'; ?>>
                                <p class="text-xs text-green-600 mt-2 flex items-center">
                                    <?php if (isset($patient_data['program'])): ?>
                                        <i class="bi bi-check-circle-fill mr-1"></i> Auto-filled from your registration
                                    <?php else: ?>
                                        <i class="bi bi-pencil-square mr-1"></i> Please enter your program
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                            <div class="bg-green-50 p-4 rounded-lg border-2 border-green-200">
                                <label for="date_of_birth_display" class="block font-medium mb-2 text-green-700">
                                    <i class="bi bi-calendar-event mr-2"></i>Date of Birth
                                </label>
                                <input type="date" 
                                       class="w-full rounded border-2 border-green-300 px-3 py-2 bg-green-50 font-medium text-green-800" 
                                       id="date_of_birth_display" 
                                       name="date_of_birth" 
                                       value="<?php echo isset($patient_data['date_of_birth']) ? htmlspecialchars($patient_data['date_of_birth']) : ''; ?>" 
                                       <?php echo isset($patient_data['date_of_birth']) ? 'readonly' : 'required'; ?>>
                                <p class="text-xs text-green-600 mt-2 flex items-center">
                                    <?php if (isset($patient_data['date_of_birth'])): ?>
                                        <i class="bi bi-check-circle-fill mr-1"></i> Auto-filled from your registration
                                    <?php else: ?>
                                        <i class="bi bi-pencil-square mr-1"></i> Please enter your date of birth
                                    <?php endif; ?>
                                </p>
                            </div>
                            
                            <div class="grid grid-cols-2 gap-4">
                                <div class="bg-green-50 p-4 rounded-lg border-2 border-green-200">
                                    <label for="sex_display" class="block font-medium mb-2 text-green-700">
                                        <i class="bi bi-gender-ambiguous mr-2"></i>Sex
                                    </label>
                                    <select class="w-full rounded border-2 border-green-300 px-3 py-2 bg-green-50 font-medium text-green-800" 
                                            id="sex_display" 
                                            name="sex_display" 
                                            <?php echo isset($patient_data['sex']) ? 'disabled' : 'required'; ?>>
                                        <option value="">Select</option>
                                        <option value="Male" <?php echo (isset($patient_data['sex']) && $patient_data['sex'] == 'Male') ? 'selected' : ''; ?>>Male</option>
                                        <option value="Female" <?php echo (isset($patient_data['sex']) && $patient_data['sex'] == 'Female') ? 'selected' : ''; ?>>Female</option>
                                    </select>
                                    <input type="hidden" id="sex" name="sex" value="<?php echo isset($patient_data['sex']) ? htmlspecialchars($patient_data['sex']) : ''; ?>">
                                    <p class="text-xs text-green-600 mt-2 flex items-center">
                                        <?php if (isset($patient_data['sex'])): ?>
                                            <i class="bi bi-check-circle-fill mr-1"></i> Auto-filled from your registration
                                        <?php else: ?>
                                            <i class="bi bi-pencil-square mr-1"></i> Please select your sex
                                        <?php endif; ?>
                                    </p>
                                </div>
                                
                                <div class="bg-green-50 p-4 rounded-lg border-2 border-green-200">
                                    <label for="age_display" class="block font-medium mb-2 text-green-700">
                                        <i class="bi bi-calendar2-heart mr-2"></i>Age
                                    </label>
                                    <input type="number" 
                                           class="w-full rounded border-2 border-green-300 px-3 py-2 bg-green-50 font-medium text-green-800" 
                                           id="age_display" 
                                           name="age" 
                                           value="<?php echo isset($patient_data['age']) ? htmlspecialchars($patient_data['age']) : ''; ?>" 
                                           <?php echo isset($patient_data['age']) ? 'readonly' : 'required'; ?>>
                                    <p class="text-xs text-green-600 mt-2 flex items-center">
                                        <?php if (isset($patient_data['age'])): ?>
                                            <i class="bi bi-check-circle-fill mr-1"></i> Auto-calculated from your date of birth
                                        <?php else: ?>
                                            <i class="bi bi-pencil-square mr-1"></i> Please enter your age
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                            <div class="bg-orange-50 p-4 rounded-lg border-2 border-orange-200">
                                <label for="sports_event" class="block font-medium mb-2 text-orange-700">
                                    <i class="bi bi-trophy mr-2"></i>Sports Event
                                </label>
                                <input type="text" 
                                       class="w-full rounded border-2 border-orange-300 px-3 py-2 bg-orange-50 focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-orange-500" 
                                       id="sports_event" 
                                       name="sports_event" 
                                       placeholder="e.g., Basketball, Volleyball, Athletics" 
                                       required>
                                <p class="text-xs text-orange-600 mt-2 flex items-center">
                                    <i class="bi bi-pencil-square mr-1"></i> Please enter the sports event
                                </p>
                            </div>
                            
                            <div class="bg-orange-50 p-4 rounded-lg border-2 border-orange-200">
                                <label for="date_of_examination" class="block font-medium mb-2 text-orange-700">
                                    <i class="bi bi-calendar-check mr-2"></i>Date of Examination
                                </label>
                                <input type="date" 
                                       class="w-full rounded border-2 border-orange-300 px-3 py-2 bg-orange-50 focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-orange-500" 
                                       id="date_of_examination" 
                                       name="date_of_examination" 
                                       value="<?php echo date('Y-m-d'); ?>" 
                                       required>
                                <p class="text-xs text-orange-600 mt-2 flex items-center">
                                    <i class="bi bi-info-circle mr-1"></i> Today's date (can be changed)
                                </p>
                            </div>
                        </div>
                        
                        <div class="bg-blue-50 border border-blue-200 rounded-lg p-6 mb-6">
                            <h5 class="text-lg font-bold text-blue-700 mb-3 flex items-center">
                                <i class="bi bi-info-circle-fill mr-2"></i> Form Information
                            </h5>
                            <p class="text-blue-600 mb-2">
                                <i class="bi bi-check-circle-fill text-green-500 mr-1"></i> 
                                <span class="font-semibold">Green fields</span> are auto-filled from your registration.
                            </p>
                            <p class="text-blue-600 mb-2">
                                <i class="bi bi-pencil-square text-orange-500 mr-1"></i> 
                                <span class="font-semibold">Orange fields</span> require manual input.
                            </p>
                            <p class="text-blue-600">
                                <i class="bi bi-shield-check text-blue-500 mr-1"></i> 
                                Please verify all information before submitting.
                            </p>
                        </div>
                        
                        <div class="flex flex-col md:flex-row gap-4 justify-end mt-8">
                            <button type="submit" class="bg-blue-600 text-white font-semibold px-6 py-3 rounded-lg shadow hover:bg-blue-700 transition flex items-center justify-center gap-2">
                                <i class="bi bi-check-circle"></i> 
                                <?php echo $has_existing_form ? 'Replace Existing Form' : 'Submit History Form'; ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        function validateForm() {
            // Check required fields
            var sportsEvent = document.getElementById('sports_event');
            var dateOfExamination = document.getElementById('date_of_examination');
            var displayName = document.getElementById('display_name');
            
            if (!sportsEvent || !sportsEvent.value) {
                alert('Please enter the Sports Event.');
                return false;
            }
            
            if (!dateOfExamination || !dateOfExamination.value) {
                alert('Please enter the Date of Examination.');
                return false;
            }
            
            if (!displayName || !displayName.value || displayName.value === 'Name not found') {
                alert('Please complete your registration before submitting this form.');
                return false;
            }
            
            // If there's an existing form, check if user confirmed replacement
            <?php if ($has_existing_form): ?>
                var confirmCheckbox = document.getElementById('confirm_replace');
                if (confirmCheckbox && !confirmCheckbox.checked) {
                    alert('Please check the confirmation box to replace your existing form.');
                    return false;
                }
                
                if (!confirm('Are you sure you want to replace your existing history form? This action cannot be undone.')) {
                    return false;
                }
                
                // Set replace flag
                document.getElementById('replace_existing').value = '1';
            <?php endif; ?>
            
            return true;
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-calculate age when date of birth changes
            var dateOfBirthInput = document.getElementById('date_of_birth_display');
            if (dateOfBirthInput && !dateOfBirthInput.readOnly) {
                dateOfBirthInput.addEventListener('change', function() {
                    var birthDate = new Date(this.value);
                    var today = new Date();
                    var age = today.getFullYear() - birthDate.getFullYear();
                    var m = today.getMonth() - birthDate.getMonth();
                    if (m < 0 || (m === 0 && today.getDate() < birthDate.getDate())) {
                        age--;
                    }
                    document.getElementById('age_display').value = age;
                });
            }
            
            // Update hidden sex field
            var sexDisplay = document.getElementById('sex_display');
            var sexHidden = document.getElementById('sex');
            if (sexDisplay && sexHidden) {
                sexDisplay.addEventListener('change', function() {
                    if (!this.disabled) {
                        sexHidden.value = this.value;
                    }
                });
            }
        });
    </script>
</body>
</html>