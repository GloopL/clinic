<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include __DIR__ . '/../config/database.php';

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    header("Location: $protocol://$host/index.php");
    exit();
}

$success_message = '';
$error_message = '';
$user_role = $_SESSION['role'] ?? 'student';
$is_staff_user = in_array($user_role, ['doctor', 'nurse', 'dentist', 'staff', 'admin']);

// Fetch logged-in user's patient data from registration
$patient_data = null;
if (isset($_SESSION['username'])) {
    $stmt = $conn->prepare("SELECT * FROM patients WHERE student_id = ?");
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
    }
    $stmt->close();
}

// Process form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get patient ID or create new patient
    $patient_id = isset($_POST['patient_id']) ? $_POST['patient_id'] : null;
    
    if (!$patient_id) {
        // Check if student already exists
        $check = $conn->prepare("SELECT id FROM patients WHERE student_id = ?");
        $check->bind_param("s", $_POST['student_id']);
        $check->execute();
        $check_result = $check->get_result();

        if ($check_result->num_rows > 0) {
            // Student already exists — reuse their patient_id
            $existing_patient = $check_result->fetch_assoc();
            $patient_id = $existing_patient['id'];
        } else {
            // Create new patient
            $stmt = $conn->prepare("INSERT INTO patients (student_id, first_name, middle_name, last_name, date_of_birth, sex, program, year_level) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssssss", $_POST['student_id'], $_POST['first_name'], $_POST['middle_name'], $_POST['last_name'], $_POST['date_of_birth'], $_POST['sex'], $_POST['program'], $_POST['year_level']);
            if ($stmt->execute()) {
                $patient_id = $conn->insert_id;
            } else {
                $error_message = "Error creating patient record: " . $conn->error;
            }
        }
    }
    
    if ($patient_id) {
        // Create medical record entry
        $stmt = $conn->prepare("INSERT INTO medical_records (patient_id, record_type, examination_date, physician_name) VALUES (?, 'medical_exam', ?, ?)");
        $examination_date = date('Y-m-d');
        $physician_name = $_POST['physician_name'] ?? '';
        $stmt->bind_param("iss", $patient_id, $examination_date, $physician_name);
        
        if ($stmt->execute()) {
            $record_id = $conn->insert_id;
            
            // Insert medical exam data
            $stmt = $conn->prepare("
                INSERT INTO medical_exams (
                    record_id, height, weight, bmi, blood_pressure, pulse_rate, temperature,
                    vision_status, physical_findings, diagnostic_results, classification,
                    recommendations, physician_name, license_no
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            // Get form data with proper defaults
            $height = $_POST['height_cert'] ?? '';
            $weight = $_POST['weight_cert'] ?? '';
            $bmi = $_POST['bmi'] ?? '';
            $blood_pressure = $_POST['blood_pressure'] ?? '';
            $pulse_rate = $_POST['pulse_rate'] ?? '';
            $temperature = $_POST['temperature'] ?? '';
            $vision_status = $_POST['vision_status'] ?? '';
            $physical_findings = $_POST['physical_findings'] ?? '';
            $diagnostic_results = $_POST['diagnostic_results'] ?? '';
            $classification = $_POST['classification'] ?? '';
            $recommendations = $_POST['recommendations'] ?? '';
            $physician_name = $_POST['physician_name'] ?? '';
            $license_no = $_POST['license_no'] ?? '';

            $stmt->bind_param(
                "isssssssssssss",
                $record_id,
                $height,
                $weight,
                $bmi,
                $blood_pressure,
                $pulse_rate,
                $temperature,
                $vision_status,
                $physical_findings,
                $diagnostic_results,
                $classification,
                $recommendations,
                $physician_name,
                $license_no
            );

            if ($stmt->execute()) {
                // Generate QR code for this record
                $qr_data = "record_id=" . $record_id . "&type=medical_exam&date=" . $examination_date;
                $qr_code = base64_encode($qr_data);

                // Update patient with QR code
                $stmt = $conn->prepare("UPDATE patients SET qr_code = ? WHERE id = ?");
                $stmt->bind_param("si", $qr_code, $patient_id);
                $stmt->execute();

                // Add to analytics data
                $stmt = $conn->prepare("INSERT INTO analytics_data (data_type, data_value, data_label, data_date) VALUES ('medical_exam', 1, 'New Medical Exam', CURDATE())");
                $stmt->execute();

                // Log user activity
                $activity_type = 'medical_exam';
                $description = 'Medical Examination Form Submitted';
                
                $activity_stmt = $conn->prepare("INSERT INTO user_activities (user_id, activity_type, activity_description) VALUES (?, ?, ?)");
                $activity_stmt->bind_param("iss", $_SESSION['user_id'], $activity_type, $description);
                $activity_stmt->execute();
                $activity_stmt->close();

                $success_message = "Medical examination form successfully submitted! Please wait for admin verification.";
            } else {
                $error_message = "Error saving medical exam data: " . $conn->error;
            }
        } else {
            $error_message = "Error creating medical record: " . $conn->error;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medical Examination Form - BSU Clinic Records</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        /* Disabled physician-only sections */
        .physician-only { background-color: #f8f9fa !important; border: 1px solid #e9ecef !important; cursor: not-allowed !important; }
        .physician-only input,
        .physician-only textarea,
        .physician-only select { background-color: #f8f9fa !important; cursor: not-allowed !important; color: #6c757d !important; }
        .physician-only input[type="checkbox"],
        .physician-only input[type="radio"] { cursor: not-allowed !important; opacity: 0.6; }
        .disabled-label { color: #6c757d !important; font-style: italic; }
        .student-section { background-color: #f0f9ff !important; border-left: 4px solid #3b82f6 !important; }
        .staff-only-section { display: none !important; }
        .staff-only-info {
            background: #e0f2fe;
            border: 1px solid #7dd3fc;
            color: #0369a1;
            border-radius: 0.5rem;
            padding: 1rem;
            font-size: 0.9rem;
            margin-bottom: 2rem;
        }
        
        /* Form field styling */
        .auto-filled {
            background-color: #f0fff4 !important;
            border-color: #68d391 !important;
        }
        .manual-input {
            background-color: #fff7ed !important;
            border-color: #fed7aa !important;
        }
        
        /* Print Styles - Hide navigation, buttons, and non-form elements */
        @media print {
            /* Hide navigation bar, header, and any includes */
            header, nav, .navbar, .navigation, 
            /* Hide buttons */
            button, .btn, a[href], 
            /* Hide back to dashboard button */
            a[href*="dashboard"],
            /* Hide save and print buttons */
            button[type="submit"], button[onclick*="print"],
            /* Hide success/error messages */
            .bg-green-100, .bg-red-100, .alert, .success, .error,
            /* Hide outer container padding/margins */
            .bg-gray-100, .py-8, .mb-6,
            /* Hide any scripts or non-essential elements */
            script, .no-print {
                display: none !important;
                visibility: hidden !important;
            }
            
            /* Hide the container with back button */
            .max-w-5xl.mx-auto.px-4.mb-6 {
                display: none !important;
            }
            
            /* Reset page margins for printing */
            @page {
                margin: 0.5cm;
                size: A4 landscape;
            }
            
            /* Ensure form content prints properly */
            body {
                margin: 0 !important;
                padding: 0 !important;
                background: white !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            /* Hide the outer gray background container */
            .bg-gray-100.min-h-screen {
                background: white !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            
            /* Make sure form container prints full width */
            .max-w-5xl {
                max-width: 100% !important;
                margin: 0 auto !important;
                padding: 0 10px !important;
            }
            
            /* Remove shadows and rounded corners for cleaner print */
            .shadow-lg, .shadow, .rounded-lg, .rounded-t-lg {
                box-shadow: none !important;
                border-radius: 0 !important;
            }
            
            /* Ensure form content is visible */
            .bg-white {
                background: white !important;
            }
            
            /* Print form only - show the white form container */
            .bg-white.rounded-lg.shadow-lg {
                margin: 0 !important;
                padding: 20px !important;
                box-shadow: none !important;
                border: none !important;
            }
            
            /* Ensure form header (title) prints */
            .red-orange-gradient {
                background: #dc2626 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            /* Hide any sticky elements */
            .sticky {
                position: static !important;
            }
        }
    </style>
</head>
<body>
    
    <div class="bg-gray-100 min-h-screen py-8">
        <div class="max-w-5xl mx-auto px-4 mb-6 no-print">
            <a href="../user_dashboard.php" class="inline-block bg-blue-600 hover:bg-blue-700 text-white font-semibold px-6 py-2 rounded-lg shadow mb-4 transition">Back to Dashboard</a>
        </div>
        <div class="max-w-5xl mx-auto px-4">
            <div class="bg-white rounded-lg shadow-lg">
                <div class="bg-gradient-to-r from-blue-700 via-blue-600 to-blue-500 text-white px-8 py-6 rounded-t-lg">
                    <h2 class="text-2xl font-bold mb-2">PRE-EMPLOYMENT/OJT MEDICAL EXAMINATION FORM</h2>
                    <p class="text-base">Reference No.: BatStateU-FO-HSD-04 | Effectivity Date: March 12, 2024 | Revision No.: 02</p>
                </div>
                <div class="px-8 py-8">
                    <?php if (!empty($success_message)): ?>
                        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-2 rounded mb-4 text-sm">
                            <?php echo $success_message; ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($error_message)): ?>
                        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-2 rounded mb-4 text-sm">
                            <?php echo $error_message; ?>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" id="medicalForm">
                        <!-- Hidden fields for auto-filled data -->
                        <input type="hidden" id="first_name" name="first_name" value="<?php echo isset($patient_data['first_name_cap']) ? htmlspecialchars($patient_data['first_name_cap']) : ''; ?>">
                        <input type="hidden" id="middle_name" name="middle_name" value="<?php echo isset($patient_data['middle_name']) ? htmlspecialchars(ucwords(strtolower($patient_data['middle_name']))) : ''; ?>">
                        <input type="hidden" id="last_name" name="last_name" value="<?php echo isset($patient_data['last_name_cap']) ? htmlspecialchars($patient_data['last_name_cap']) : ''; ?>">
                        <input type="hidden" id="sex" name="sex" value="<?php echo isset($patient_data['sex']) ? htmlspecialchars($patient_data['sex']) : ''; ?>">
                        <input type="hidden" id="student_id" name="student_id" value="<?php echo isset($patient_data['student_id']) ? htmlspecialchars($patient_data['student_id']) : ''; ?>">
                        
                        <!-- Personal Info - Student Section -->
                        <div class="student-section rounded-lg p-6 mb-8">
                            <h3 class="text-lg font-bold text-blue-700 mb-4">Personal Information</h3>
                            
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-4">
                                <div class="bg-green-50 p-4 rounded-lg border-2 border-green-200">
                                    <label class="block text-xs font-semibold text-green-700">Last Name</label>
                                    <input type="text" 
                                           class="w-full rounded border-2 border-green-300 px-2 py-1 text-xs bg-green-50 font-medium text-green-800" 
                                           value="<?php echo isset($patient_data['last_name_cap']) ? htmlspecialchars($patient_data['last_name_cap']) : ''; ?>" 
                                           readonly>
                                    <p class="text-xs text-green-600 mt-1 flex items-center">
                                        <?php if (isset($patient_data['last_name_cap'])): ?>
                                            <i class="bi bi-check-circle-fill mr-1"></i> Auto-filled
                                        <?php else: ?>
                                            <i class="bi bi-exclamation-triangle mr-1"></i> Not in database
                                        <?php endif; ?>
                                    </p>
                                </div>
                                
                                <div class="bg-green-50 p-4 rounded-lg border-2 border-green-200">
                                    <label class="block text-xs font-semibold text-green-700">First Name</label>
                                    <input type="text" 
                                           class="w-full rounded border-2 border-green-300 px-2 py-1 text-xs bg-green-50 font-medium text-green-800" 
                                           value="<?php echo isset($patient_data['first_name_cap']) ? htmlspecialchars($patient_data['first_name_cap']) : ''; ?>" 
                                           readonly>
                                    <p class="text-xs text-green-600 mt-1 flex items-center">
                                        <?php if (isset($patient_data['first_name_cap'])): ?>
                                            <i class="bi bi-check-circle-fill mr-1"></i> Auto-filled
                                        <?php else: ?>
                                            <i class="bi bi-exclamation-triangle mr-1"></i> Not in database
                                        <?php endif; ?>
                                    </p>
                                </div>
                                
                                <div class="bg-green-50 p-4 rounded-lg border-2 border-green-200">
                                    <label class="block text-xs font-semibold text-green-700">Middle Name</label>
                                    <input type="text" 
                                           class="w-full rounded border-2 border-green-300 px-2 py-1 text-xs bg-green-50 font-medium text-green-800" 
                                           value="<?php echo isset($patient_data['middle_name']) ? htmlspecialchars(ucwords(strtolower($patient_data['middle_name']))) : ''; ?>" 
                                           readonly>
                                    <p class="text-xs text-green-600 mt-1 flex items-center">
                                        <?php if (isset($patient_data['middle_name'])): ?>
                                            <i class="bi bi-check-circle-fill mr-1"></i> Auto-filled
                                        <?php else: ?>
                                            <i class="bi bi-exclamation-triangle mr-1"></i> Not in database
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-4">
                                <div class="bg-green-50 p-4 rounded-lg border-2 border-green-200">
                                    <label class="block text-xs font-semibold text-green-700">Sex</label>
                                    <input type="text" 
                                           class="w-full rounded border-2 border-green-300 px-2 py-1 text-xs bg-green-50 font-medium text-green-800" 
                                           value="<?php echo isset($patient_data['sex']) ? htmlspecialchars($patient_data['sex']) : ''; ?>" 
                                           readonly>
                                    <p class="text-xs text-green-600 mt-1 flex items-center">
                                        <?php if (isset($patient_data['sex'])): ?>
                                            <i class="bi bi-check-circle-fill mr-1"></i> Auto-filled
                                        <?php else: ?>
                                            <i class="bi bi-exclamation-triangle mr-1"></i> Not in database
                                        <?php endif; ?>
                                    </p>
                                </div>
                                
                                <div class="bg-orange-50 p-4 rounded-lg border-2 border-orange-200">
                                    <label class="block text-xs font-semibold text-orange-700">Cellphone No.</label>
                                    <input type="text" 
                                           name="cellphone_no" 
                                           class="w-full rounded border-2 border-orange-300 px-2 py-1 text-xs bg-orange-50 focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-orange-500" 
                                           value="<?php echo htmlspecialchars($patient_data['contact_number'] ?? ''); ?>">
                                    <p class="text-xs text-orange-600 mt-1 flex items-center">
                                        <i class="bi bi-pencil-square mr-1"></i> Please enter your cellphone number
                                    </p>
                                </div>
                                
                                <div class="bg-orange-50 p-4 rounded-lg border-2 border-orange-200">
                                    <label class="block text-xs font-semibold text-orange-700">Address</label>
                                    <input type="text" 
                                           name="address" 
                                           class="w-full rounded border-2 border-orange-300 px-2 py-1 text-xs bg-orange-50 focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-orange-500" 
                                           value="<?php echo htmlspecialchars($patient_data['address'] ?? ''); ?>">
                                    <p class="text-xs text-orange-600 mt-1 flex items-center">
                                        <i class="bi bi-pencil-square mr-1"></i> Please enter your address
                                    </p>
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-4">
                                <div class="bg-green-50 p-4 rounded-lg border-2 border-green-200">
                                    <label class="block text-xs font-semibold text-green-700">Date</label>
                                    <input type="date" 
                                           name="date_of_examination" 
                                           class="w-full rounded border-2 border-green-300 px-2 py-1 text-xs bg-green-50 font-medium text-green-800" 
                                           value="<?php echo date('Y-m-d'); ?>" 
                                           required>
                                    <p class="text-xs text-green-600 mt-1 flex items-center">
                                        <i class="bi bi-info-circle mr-1"></i> Today's date
                                    </p>
                                </div>
                                
                                <div class="bg-green-50 p-4 rounded-lg border-2 border-green-200">
                                    <label class="block text-xs font-semibold text-green-700">Birthday</label>
                                    <input type="date" 
                                           name="date_of_birth" 
                                           class="w-full rounded border-2 border-green-300 px-2 py-1 text-xs bg-green-50 font-medium text-green-800" 
                                           value="<?php echo htmlspecialchars($patient_data['date_of_birth'] ?? ''); ?>" 
                                           readonly>
                                    <p class="text-xs text-green-600 mt-1 flex items-center">
                                        <?php if (isset($patient_data['date_of_birth'])): ?>
                                            <i class="bi bi-check-circle-fill mr-1"></i> Auto-filled
                                        <?php else: ?>
                                            <i class="bi bi-exclamation-triangle mr-1"></i> Not in database
                                        <?php endif; ?>
                                    </p>
                                </div>
                                
                                <div class="bg-green-50 p-4 rounded-lg border-2 border-green-200">
                                    <label class="block text-xs font-semibold text-green-700">Age</label>
                                    <input type="number" 
                                           name="age" 
                                           class="w-full rounded border-2 border-green-300 px-2 py-1 text-xs bg-green-50 font-medium text-green-800" 
                                           value="<?php echo htmlspecialchars($patient_data['age'] ?? ''); ?>" 
                                           readonly>
                                    <p class="text-xs text-green-600 mt-1 flex items-center">
                                        <?php if (isset($patient_data['age'])): ?>
                                            <i class="bi bi-check-circle-fill mr-1"></i> Auto-calculated
                                        <?php else: ?>
                                            <i class="bi bi-exclamation-triangle mr-1"></i> Not available
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-4">
                                <div class="bg-orange-50 p-4 rounded-lg border-2 border-orange-200">
                                    <label class="block text-xs font-semibold text-orange-700">Civil Status</label>
                                    <select name="civil_status" 
                                            class="w-full rounded border-2 border-orange-300 px-2 py-1 text-xs bg-orange-50 focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-orange-500" 
                                            required>
                                        <option value="">Select Civil Status</option>
                                        <option value="Single" <?php echo (isset($patient_data['civil_status']) && $patient_data['civil_status'] == 'Single') ? 'selected' : ''; ?>>Single</option>
                                        <option value="Married" <?php echo (isset($patient_data['civil_status']) && $patient_data['civil_status'] == 'Married') ? 'selected' : ''; ?>>Married</option>
                                        <option value="Widowed" <?php echo (isset($patient_data['civil_status']) && $patient_data['civil_status'] == 'Widowed') ? 'selected' : ''; ?>>Widowed</option>
                                        <option value="Separated" <?php echo (isset($patient_data['civil_status']) && $patient_data['civil_status'] == 'Separated') ? 'selected' : ''; ?>>Separated</option>
                                        <option value="Divorced" <?php echo (isset($patient_data['civil_status']) && $patient_data['civil_status'] == 'Divorced') ? 'selected' : ''; ?>>Divorced</option>
                                    </select>
                                    <p class="text-xs text-orange-600 mt-1 flex items-center">
                                        <i class="bi bi-pencil-square mr-1"></i> Please select your civil status
                                    </p>
                                </div>
                                
                                <div class="bg-orange-50 p-4 rounded-lg border-2 border-orange-200">
                                    <label class="block text-xs font-semibold text-orange-700">Tel. No.</label>
                                    <input type="text" 
                                           name="tel_no" 
                                           class="w-full rounded border-2 border-orange-300 px-2 py-1 text-xs bg-orange-50 focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-orange-500">
                                    <p class="text-xs text-orange-600 mt-1 flex items-center">
                                        <i class="bi bi-pencil-square mr-1"></i> Please enter telephone number
                                    </p>
                                </div>
                                
                                <div class="bg-green-50 p-4 rounded-lg border-2 border-green-200">
                                    <label class="block text-xs font-semibold text-green-700">Position/Program/Campus</label>
                                    <input type="text" 
                                           name="program" 
                                           class="w-full rounded border-2 border-green-300 px-2 py-1 text-xs bg-green-50 font-medium text-green-800" 
                                           value="<?php echo htmlspecialchars($patient_data['program'] ?? ''); ?>" 
                                           readonly>
                                    <p class="text-xs text-green-600 mt-1 flex items-center">
                                        <?php if (isset($patient_data['program'])): ?>
                                            <i class="bi bi-check-circle-fill mr-1"></i> Auto-filled
                                        <?php else: ?>
                                            <i class="bi bi-exclamation-triangle mr-1"></i> Not in database
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>
                            
                            <!-- Information Legend -->
                            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mt-4">
                                <h5 class="text-sm font-bold text-blue-700 mb-2 flex items-center">
                                    <i class="bi bi-info-circle-fill mr-2"></i> Form Information
                                </h5>
                                <p class="text-blue-600 text-xs mb-1">
                                    <i class="bi bi-check-circle-fill text-green-500 mr-1"></i> 
                                    <span class="font-semibold">Green fields</span> are auto-filled from your registration.
                                </p>
                                <p class="text-blue-600 text-xs">
                                    <i class="bi bi-pencil-square text-orange-500 mr-1"></i> 
                                    <span class="font-semibold">Orange fields</span> require manual input.
                                </p>
                            </div>
                        </div>
                    <?php if ($is_staff_user): ?>
                    <!-- Physical Examination (Review of System) - Physician Only -->
                    <div class="rounded-lg p-6 mb-8">
                            <h3 class="text-lg font-bold text-blue-700 mb-4 disabled-label">Physical Examination / Review of System (For Physician Use Only)</h3>
                            <div class="overflow-x-auto mb-4">
                                <table class="min-w-full border border-gray-300 text-xs text-center">
                                    <thead class="bg-gray-100">
                                        <tr>
                                            <th>System</th>
                                            <th>Normal</th>
                                            <th>Findings</th>
                                            <th>System</th>
                                            <th>Normal</th>
                                            <th>Findings</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td>General Appearance/Body Built (BMI)</td><td><input type="checkbox" name="bmi_normal" class="physician-only" disabled></td><td><input type="text" name="bmi_findings" class="w-full border rounded px-1 physician-only" readonly></td>
                                            <td>Chest, Breast, Axilla</td><td><input type="checkbox" name="chest_normal" class="physician-only" disabled></td><td><input type="text" name="chest_findings" class="w-full border rounded px-1 physician-only" readonly></td>
                                        </tr>
                                        <tr>
                                            <td>Skin (Tattoo)</td><td><input type="checkbox" name="skin_normal" class="physician-only" disabled></td><td><input type="text" name="skin_findings" class="w-full border rounded px-1 physician-only" readonly></td>
                                            <td>Heart</td><td><input type="checkbox" name="heart_normal" class="physician-only" disabled></td><td><input type="text" name="heart_findings" class="w-full border rounded px-1 physician-only" readonly></td>
                                        </tr>
                                        <tr>
                                            <td>Head and Scalp</td><td><input type="checkbox" name="head_normal" class="physician-only" disabled></td><td><input type="text" name="head_findings" class="w-full border rounded px-1 physician-only" readonly></td>
                                            <td>Lungs</td><td><input type="checkbox" name="lungs_normal" class="physician-only" disabled></td><td><input type="text" name="lungs_findings" class="w-full border rounded px-1 physician-only" readonly></td>
                                        </tr>
                                        <tr>
                                            <td>Eyes (External)</td><td><input type="checkbox" name="eyes_normal" class="physician-only" disabled></td><td><input type="text" name="eyes_findings" class="w-full border rounded px-1 physician-only" readonly></td>
                                            <td>Abdomen</td><td><input type="checkbox" name="abdomen_normal" class="physician-only" disabled></td><td><input type="text" name="abdomen_findings" class="w-full border rounded px-1 physician-only" readonly></td>
                                        </tr>
                                        <tr>
                                            <td>Ears (Piercing)</td><td><input type="checkbox" name="ears_normal" class="physician-only" disabled></td><td><input type="text" name="ears_findings" class="w-full border rounded px-1 physician-only" readonly></td>
                                            <td>Anus, Rectum</td><td><input type="checkbox" name="anus_normal" class="physician-only" disabled></td><td><input type="text" name="anus_findings" class="w-full border rounded px-1 physician-only" readonly></td>
                                        </tr>
                                        <tr>
                                            <td>Nose and Throat</td><td><input type="checkbox" name="nose_normal" class="physician-only" disabled></td><td><input type="text" name="nose_findings" class="w-full border rounded px-1 physician-only" readonly></td>
                                            <td>Genital</td><td><input type="checkbox" name="genital_normal" class="physician-only" disabled></td><td><input type="text" name="genital_findings" class="w-full border rounded px-1 physician-only" readonly></td>
                                        </tr>
                                        <tr>
                                            <td>Mouth</td><td><input type="checkbox" name="mouth_normal" class="physician-only" disabled></td><td><input type="text" name="mouth_findings" class="w-full border rounded px-1 physician-only" readonly></td>
                                            <td>Musculo-Skeletal</td><td><input type="checkbox" name="musculo_normal" class="physician-only" disabled></td><td><input type="text" name="musculo_findings" class="w-full border rounded px-1 physician-only" readonly></td>
                                        </tr>
                                        <tr>
                                            <td>Neck, Thyroid, LN</td><td><input type="checkbox" name="neck_normal" class="physician-only" disabled></td><td><input type="text" name="neck_findings" class="w-full border rounded px-1 physician-only" readonly></td>
                                            <td>Extremities</td><td><input type="checkbox" name="extremities_normal" class="physician-only" disabled></td><td><input type="text" name="extremities_findings" class="w-full border rounded px-1 physician-only" readonly></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                    <!-- Diagnostic Examination - Physician Only -->
                    <div class="rounded-lg p-6 mb-8">
                            <h3 class="text-lg font-bold text-blue-700 mb-4 disabled-label">Diagnostic Examination (For Physician Use Only)</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4">
                                <div>
                                    <label class="block text-xs font-semibold disabled-label">Blood Pressure</label>
                                    <input type="text" name="blood_pressure" class="w-full rounded border border-gray-300 px-2 py-1 text-xs physician-only" readonly>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold disabled-label">Heart Rate</label>
                                    <input type="text" name="heart_rate" class="w-full rounded border border-gray-300 px-2 py-1 text-xs physician-only" readonly>
                                </div>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4">
                                <div>
                                    <label class="block text-xs font-semibold disabled-label">Hearing</label>
                                    <div class="flex gap-4">
                                        <label class="flex items-center gap-1 text-xs cursor-not-allowed"><input type="radio" name="hearing" value="Normal" class="physician-only" disabled> Normal</label>
                                        <label class="flex items-center gap-1 text-xs cursor-not-allowed"><input type="radio" name="hearing" value="Defective" class="physician-only" disabled> Defective</label>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold disabled-label">Vision</label>
                                    <div class="flex gap-4">
                                        <label class="flex items-center gap-1 text-xs cursor-not-allowed"><input type="checkbox" name="vision_glasses" class="physician-only" disabled> With glasses</label>
                                        <label class="flex items-center gap-1 text-xs cursor-not-allowed"><input type="checkbox" name="vision_without_glasses" class="physician-only" disabled> Without glasses</label>
                                    </div>
                                    <div class="flex gap-4 mt-2">
                                        <label class="block text-xs font-semibold disabled-label">R:</label>
                                        <input type="text" name="vision_r" class="w-20 rounded border border-gray-300 px-2 py-1 text-xs physician-only" readonly>
                                        <label class="block text-xs font-semibold disabled-label">L:</label>
                                        <input type="text" name="vision_l" class="w-20 rounded border border-gray-300 px-2 py-1 text-xs physician-only" readonly>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Certification - Mixed Sections -->
                        <div class="rounded-lg p-6 mb-8">
                            <h3 class="text-lg font-bold text-blue-700 mb-4">Certification</h3>
                            
                            <!-- Student Section -->
                            <div class="student-section rounded-lg p-4 mb-6">
                                <h4 class="text-md font-bold text-blue-600 mb-3">Student Information</h4>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4">
                                    <div>
                                        <label class="block text-xs font-semibold">School/Company/Institution</label>
                                        <input type="text" name="school_company" class="w-full rounded border border-gray-300 px-2 py-1 text-xs">
                                        <label class="block text-xs font-semibold mt-2">Weight (kg)</label>
                                        <input type="text" name="weight_cert" class="w-full rounded border border-gray-300 px-2 py-1 text-xs">
                                        <label class="block text-xs font-semibold mt-2">Height (cm)</label>
                                        <input type="text" name="height_cert" class="w-full rounded border border-gray-300 px-2 py-1 text-xs">
                                        <label class="block text-xs font-semibold mt-2">Civil Status</label>
                                        <input type="text" name="civil_status_cert" class="w-full rounded border border-gray-300 px-2 py-1 text-xs" value="<?php echo htmlspecialchars($patient_data['civil_status'] ?? ''); ?>">
                                        <label class="block text-xs font-semibold mt-2">Date of Examination</label>
                                        <input type="date" name="date_cert" class="w-full rounded border border-gray-300 px-2 py-1 text-xs" value="<?php echo date('Y-m-d'); ?>">
                                    </div>
                                    <div class="flex flex-col items-center justify-center">
                                        <label class="block text-xs font-semibold mb-2">Attach picture here</label>
                                        <div class="w-32 h-32 border-2 border-dashed border-gray-300 rounded-lg flex items-center justify-center bg-gray-50">
                                            <span class="text-xs text-gray-500">No Image</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Physician Only Section -->
                            <div class="rounded-lg p-4 mb-6">
                                <h4 class="text-md font-bold text-blue-600 mb-3 disabled-label">Medical Assessment (For Physician Use Only)</h4>
                                <div class="mb-4 text-xs text-gray-700">I certify that I have examined and found the applicant to be physically fit/unfit for employment.</div>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4">
                                    <div>
                                        <label class="block text-xs font-semibold disabled-label">Classification</label>
                                        <div class="flex flex-col gap-2">
                                            <label class="flex items-center gap-1 text-xs cursor-not-allowed"><input type="checkbox" name="class_a" class="physician-only" disabled> Class A - Physically fit to work</label>
                                            <label class="flex items-center gap-1 text-xs cursor-not-allowed"><input type="checkbox" name="class_b" class="physician-only" disabled> Class B - Physically underdeveloped or with correctable defects but otherwise fit to work</label>
                                            <label class="flex items-center gap-1 text-xs cursor-not-allowed"><input type="checkbox" name="class_c" class="physician-only" disabled> Class C - Employable but owing to certain impairments or conditions, requires special placement or limited duty in a specified or selected assignment requiring follow up treatment/periodic evaluation</label>
                                            <label class="flex items-center gap-1 text-xs cursor-not-allowed"><input type="checkbox" name="class_d" class="physician-only" disabled> Class D - Unfit or unsafe for any type of employment</label>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold disabled-label">Needs treatment or operation for:</label>
                                        <div class="flex flex-col gap-2">
                                            <label class="flex items-center gap-1 text-xs cursor-not-allowed"><input type="checkbox" name="skin_disease" class="physician-only" disabled> Skin Disease</label>
                                            <label class="flex items-center gap-1 text-xs cursor-not-allowed"><input type="checkbox" name="dental_defects" class="physician-only" disabled> Dental Defects</label>
                                            <label class="flex items-center gap-1 text-xs cursor-not-allowed"><input type="checkbox" name="anemia" class="physician-only" disabled> Anemia</label>
                                            <label class="flex items-center gap-1 text-xs cursor-not-allowed"><input type="checkbox" name="poor_vision" class="physician-only" disabled> Poor Vision</label>
                                            <label class="flex items-center gap-1 text-xs cursor-not-allowed"><input type="checkbox" name="mild_urinary" class="physician-only" disabled> Mild Urinary Tract Infection</label>
                                            <label class="flex items-center gap-1 text-xs cursor-not-allowed"><input type="checkbox" name="intestinal_parasite" class="physician-only" disabled> Intestinal Parasitism</label>
                                            <label class="flex items-center gap-1 text-xs cursor-not-allowed"><input type="checkbox" name="mild_hypertension" class="physician-only" disabled> Mild Hypertension</label>
                                            <label class="flex items-center gap-1 text-xs cursor-not-allowed"><input type="checkbox" name="others" class="physician-only" disabled> Others, specify: <input type="text" name="others_specify" class="w-32 rounded border border-gray-300 px-2 py-1 text-xs physician-only" readonly></label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Signatures Section -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4">
                                <!-- Student Signature -->
                                <div class="student-section rounded-lg p-4">
                                    <label class="block text-xs font-semibold">Signature over Printed Name of Employee/Student</label>
                                    <input type="text" name="student_signature" class="w-full rounded border border-gray-300 px-2 py-1 text-xs" required>
                                </div>
                                <!-- Physician Signature -->
                                <?php if ($is_staff_user): ?>
                                <div class="rounded-lg p-4">
                                    <label class="block text-xs font-semibold">Signature over Printed Name of Attending Physician</label>
                                    <input type="text" name="physician_name" class="w-full rounded border border-gray-300 px-2 py-1 text-xs" required>
                                    <label class="block text-xs font-semibold mt-2">License No.</label>
                                    <input type="text" name="license_no" class="w-full rounded border border-gray-300 px-2 py-1 text-xs" required>
                                    <label class="block text-xs font-semibold mt-2">Date</label>
                                    <input type="date" name="physician_date" class="w-full rounded border border-gray-300 px-2 py-1 text-xs" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                    <?php endif; ?>
                        <!-- Data Privacy Act Notice -->
                        <div class="border-2 border-red-400 rounded-lg bg-white shadow p-4 mb-6">
                            <p class="text-xs text-gray-600 mb-2">Pursuant to Republic Act No. 10173, also known as the Data Privacy Act of 2012, the Batangas State University, the National Engineering University, recognizes its commitment to protect and respect the privacy of its customers and/or stakeholders and ensure that all information collected from them are all processed in accordance with the principles of transparency, legitimate purpose and proportionality mandated under the Data Privacy Act of 2012.</p>
                        </div>
                        <div class="flex flex-col md:flex-row gap-4 justify-end mt-8 no-print">
                            <button type="submit" class="bg-blue-600 text-white font-semibold px-6 py-2 rounded-lg shadow hover:bg-blue-700 transition">Submit Form</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <script src="../../assets/js/jquery.min.js"></script>
    <script src="../../assets/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
            // Prevent interaction with physician-only sections
            $('.physician-only').on('click focus', function(e) {
                e.preventDefault();
                $(this).blur();
                return false;
            });
        });
    </script>
    
    <?php if (!empty($success_message)): ?>
    <script>
        alert("Medical examination form successfully submitted! Please wait for admin verification.");
        window.location.href = "../user_dashboard.php";
    </script>
    <?php endif; ?>
</body>
</html>