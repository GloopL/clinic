<?php
session_start();
include __DIR__ . '/../../config/database.php';


// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    // Use absolute URL for proper redirect
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    header("Location: $protocol://$host/index.php");
    exit();
}

$success_message = '';
$error_message = '';

// Determine appropriate dashboard redirect based on role
$dashboard_url = '../../user_dashboard.php';
$user_role = $_SESSION['role'] ?? 'student';
$is_staff_user = in_array($user_role, ['doctor', 'nurse', 'dentist', 'staff', 'admin']);

if ($user_role === 'doctor') {
    $dashboard_url = '../../doctor_dashboard.php';
} elseif ($user_role === 'nurse') {
    $dashboard_url = '../../nurse_dashboard.php';
} elseif (in_array($user_role, ['admin', 'staff'])) {
    $dashboard_url = '../../dashboard.php';
}

// Process form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get patient ID or create new patient
    $patient_id = isset($_POST['patient_id']) ? $_POST['patient_id'] : null;
    
    if (!$patient_id) {
        // Create new patient
        $stmt = $conn->prepare("INSERT INTO patients (student_id, first_name, middle_name, last_name, date_of_birth, sex, program, year_level) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param(
            "ssssssss",
            $_POST['student_id'],
            $_POST['first_name'],
            $_POST['middle_name'],
            $_POST['last_name'],
            $_POST['date_of_birth'],
            $_POST['sex'],
            $_POST['program'],
            $_POST['year_level']
        );
        
        if ($stmt->execute()) {
            $patient_id = $conn->insert_id;
        } else {
            $error_message = "Error creating patient record: " . $conn->error;
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
                    recommendations, physician_name, license_no, created_at, verification_status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'Pending')
            ");

            $height = $_POST['height'] ?? '';
            $weight = $_POST['weight'] ?? '';
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

            // Corrected bind_param: 1 integer + 13 strings = 14 variables
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
                $qr_data = "record_id=" . $record_id . "&type=medical_form&date=" . $examination_date;
                $qr_code = base64_encode($qr_data);

                // Update patient with QR code
                $stmt = $conn->prepare("UPDATE patients SET qr_code = ? WHERE id = ?");
                $stmt->bind_param("si", $qr_code, $patient_id);
                $stmt->execute();

                // Add to analytics data
                $stmt = $conn->prepare("INSERT INTO analytics_data (data_type, data_value, data_label, data_date) VALUES ('medical_exam', 1, 'New Medical Exam', CURDATE())");
                $stmt->execute();

                // Redirect to verification page
               $success_message = "Form successfully submitted! Please wait for admin verification.";
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        /* Red-orange gradient button */
        .red-orange-gradient-button {
            background: linear-gradient(135deg, #dc2626, #ea580c);
        }
        
        .red-orange-gradient-button:hover {
            background: linear-gradient(135deg, #b91c1c, #c2410c);
        }
        
        /* Red-orange gradient header */
        .red-orange-gradient {
            background: linear-gradient(135deg, #dc2626, #ea580c, #f97316);
        }
        .staff-only-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.65rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 2px 10px;
            border-radius: 999px;
            background: #e5e7eb;
            color: #374151;
        }
        .staff-only-pill i {
            font-size: 0.75rem;
        }
        <?php if (!$is_staff_user): ?>
        .staff-only-section {
            display: none !important;
        }
        <?php endif; ?>
        
        /* Ensure navigation header is visible (not hidden by print styles on screen) */
        header.red-orange-gradient {
            display: block !important;
            visibility: visible !important;
        }
        
        /* Print Styles - hide only nav and elements we explicitly mark as no-print */
        @media print {
            /* Hide navigation bar/header - be very specific */
            header.red-orange-gradient,
            header,
            nav,
            .navbar,
            .navigation,
            body > header {
                display: none !important;
                visibility: hidden !important;
                height: 0 !important;
                margin: 0 !important;
                padding: 0 !important;
                overflow: hidden !important;
            }

            /* Hide elements we mark as no-print (back button, save/print buttons, etc.) */
            .no-print {
                display: none !important;
                visibility: hidden !important;
            }
            
            /* Page setup for A4 */
            @page {
                margin: 1cm;
                size: A4;
            }
            
            /* Ensure form content prints properly */
            body {
                margin: 0 !important;
                padding: 0 !important;
                padding-top: 0 !important;
                background: white !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            /* Remove top spacing where navbar was */
            .bg-gray-100.min-h-screen,
            .no-print-padding {
                padding-top: 0 !important;
                margin-top: 0 !important;
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
            
            /* Page break management - keep sections together */
            /* Prevent page breaks inside major sections */
            h3 {
                page-break-after: avoid;
                break-after: avoid;
            }
            
            /* Keep all major sections together - Personal Info, Medical History, Physical Exam, Diagnostic, Certification */
            .bg-gray-50.rounded-lg.p-6 {
                page-break-inside: avoid;
                break-inside: avoid;
            }
            
            /* Keep Physical Examination table together */
            table {
                page-break-inside: avoid;
                break-inside: avoid;
            }
            
            /* Keep table rows together */
            tr {
                page-break-inside: avoid;
                break-inside: avoid;
            }
            
            /* Keep Data Privacy section together */
            .border-2.border-red-400 {
                page-break-inside: avoid;
                break-inside: avoid;
            }
            
            /* Better control for form sections */
            form > div,
            form > section {
                orphans: 3;
                widows: 3;
            }
            
            /* Add strategic page breaks between major sections if needed */
            .bg-gray-50.rounded-lg.p-6 + .bg-gray-50.rounded-lg.p-6 {
                page-break-before: auto;
                break-before: auto;
            }
            
            /* Reduce padding in sections for print */
            .bg-gray-50.rounded-lg.p-6 {
                padding: 0.75rem !important;
                margin-bottom: 0.75rem !important;
            }
            
            /* Reduce header padding for print */
            .red-orange-gradient {
                padding: 0.5rem 1rem !important;
            }
            
            .red-orange-gradient h2 {
                font-size: 1.25rem !important;
                margin-bottom: 0.25rem !important;
            }
            
            .red-orange-gradient p {
                font-size: 0.7rem !important;
            }
            
            /* Reduce form container padding */
            .px-8.py-8 {
                padding: 0.75rem 1rem !important;
            }
        }
    </style>
</head>
<body class="bg-gradient-to-br from-orange-50 to-red-50">

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
    
    <div class="bg-gray-100 min-h-screen py-8 pt-20 no-print-padding">
        <div class="max-w-5xl mx-auto px-4 mb-6 no-print">
            <a href="<?php echo htmlspecialchars($dashboard_url); ?>" class="inline-block red-orange-gradient-button text-white font-semibold px-6 py-2 rounded-lg shadow hover:shadow-lg mb-4 transition">Back to Dashboard</a>
        </div>
        <div class="max-w-5xl mx-auto px-4">
            <div class="bg-white rounded-lg shadow-lg">
                <div class="red-orange-gradient text-white px-8 py-6 rounded-t-lg">
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
                    <!-- Personal Info -->
                    <div class="bg-gray-50 rounded-lg p-6 mb-8">
                        <h3 class="text-lg font-bold text-orange-700 mb-4">Personal Information</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4">
                            <div>
                                <label class="block text-xs font-semibold">Last Name</label>
                                <input type="text" name="last_name" class="w-full rounded border border-gray-300 px-2 py-1 text-xs" required>
                            </div>
                            <div>
                                  <label class="block text-xs font-semibold">Student ID</label>
            <input type="text" name="student_id" class="w-full rounded border border-gray-300 px-2 py-1 text-xs" required>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold">Date</label>
                                <input type="date" name="date_of_examination" class="w-full rounded border border-gray-300 px-2 py-1 text-xs" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4">
                            <div>
                                <label class="block text-xs font-semibold">First Name</label>
                                <input type="text" name="first_name" class="w-full rounded border border-gray-300 px-2 py-1 text-xs" required>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold">Birthday</label>
                                <input type="date" name="date_of_birth" class="w-full rounded border border-gray-300 px-2 py-1 text-xs" required>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4">
                            <div>
                                <label class="block text-xs font-semibold">Middle Name</label>
                                <input type="text" name="middle_name" class="w-full rounded border border-gray-300 px-2 py-1 text-xs">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold">Age</label>
                                <input type="number" name="age" class="w-full rounded border border-gray-300 px-2 py-1 text-xs" required>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4">
                            <div>
                                <label class="block text-xs font-semibold">Sex</label>
                                <select name="sex" class="w-full rounded border border-gray-300 px-2 py-1 text-xs" required>
                                    <option value="">Select</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold">Civil Status</label>
                                <input type="text" name="civil_status" class="w-full rounded border border-gray-300 px-2 py-1 text-xs">
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4">
                            <div>
                                <label class="block text-xs font-semibold">Cellphone No.</label>
                                <input type="text" name="cellphone_no" class="w-full rounded border border-gray-300 px-2 py-1 text-xs">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold">Tel. No.</label>
                                <input type="text" name="tel_no" class="w-full rounded border border-gray-300 px-2 py-1 text-xs">
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4">
                            <div>
                                <label class="block text-xs font-semibold">Address</label>
                                <input type="text" name="address" class="w-full rounded border border-gray-300 px-2 py-1 text-xs">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold">Position/Program/Campus</label>
                                <input type="text" name="program" class="w-full rounded border border-gray-300 px-2 py-1 text-xs">
                            </div>
                        </div>
                    </div>
                    <!-- Medical & Family History -->
                    <div class="bg-gray-50 rounded-lg p-6 mb-8">
                        <h3 class="text-lg font-bold text-orange-700 mb-4">Medical & Family History</h3>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-4">
                            <div>
                                <label class="block text-xs font-semibold">Past Medical History</label>
                                <textarea name="past_medical_history" class="w-full rounded border border-gray-300 px-2 py-1 text-xs" rows="2"></textarea>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold">Family History</label>
                                <textarea name="family_history" class="w-full rounded border border-gray-300 px-2 py-1 text-xs" rows="2"></textarea>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold">Occupational History</label>
                                <textarea name="occupational_history" class="w-full rounded border border-gray-300 px-2 py-1 text-xs" rows="2"></textarea>
                            </div>
                        </div>
                    </div>
                    <?php if (!$is_staff_user): ?>
                    <div class="bg-blue-50 border border-blue-200 text-sm text-blue-800 rounded-lg p-4 mb-8">
                        The remaining sections will be completed by clinic staff after your appointment. Please save the form once your personal information is complete.
                    </div>
                    <?php endif; ?>
                    <?php if ($is_staff_user): ?>
                    <!-- Physical Examination (Review of System) -->
                    <div class="bg-gray-50 rounded-lg p-6 mb-8">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-bold text-orange-700">Physical Examination / Review of System</h3>
                            <span class="staff-only-pill"><i class="bi bi-shield-lock"></i> Staff Only</span>
                        </div>
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
                                        <td>General Appearance/Body Built (BMI)</td><td><input type="checkbox" name="bmi_normal"></td><td><input type="text" name="bmi_findings" class="w-full border rounded px-1"></td>
                                        <td>Chest, Breast, Axilla</td><td><input type="checkbox" name="chest_normal"></td><td><input type="text" name="chest_findings" class="w-full border rounded px-1"></td>
                                    </tr>
                                    <tr>
                                        <td>Skin (Tattoo)</td><td><input type="checkbox" name="skin_normal"></td><td><input type="text" name="skin_findings" class="w-full border rounded px-1"></td>
                                        <td>Heart</td><td><input type="checkbox" name="heart_normal"></td><td><input type="text" name="heart_findings" class="w-full border rounded px-1"></td>
                                    </tr>
                                    <tr>
                                        <td>Head and Scalp</td><td><input type="checkbox" name="head_normal"></td><td><input type="text" name="head_findings" class="w-full border rounded px-1"></td>
                                        <td>Lungs</td><td><input type="checkbox" name="lungs_normal"></td><td><input type="text" name="lungs_findings" class="w-full border rounded px-1"></td>
                                    </tr>
                                    <tr>
                                        <td>Eyes (External)</td><td><input type="checkbox" name="eyes_normal"></td><td><input type="text" name="eyes_findings" class="w-full border rounded px-1"></td>
                                        <td>Abdomen</td><td><input type="checkbox" name="abdomen_normal"></td><td><input type="text" name="abdomen_findings" class="w-full border rounded px-1"></td>
                                    </tr>
                                    <tr>
                                        <td>Ears (Piercing)</td><td><input type="checkbox" name="ears_normal"></td><td><input type="text" name="ears_findings" class="w-full border rounded px-1"></td>
                                        <td>Anus, Rectum</td><td><input type="checkbox" name="anus_normal"></td><td><input type="text" name="anus_findings" class="w-full border rounded px-1"></td>
                                    </tr>
                                    <tr>
                                        <td>Nose and Throat</td><td><input type="checkbox" name="nose_normal"></td><td><input type="text" name="nose_findings" class="w-full border rounded px-1"></td>
                                        <td>Genital</td><td><input type="checkbox" name="genital_normal"></td><td><input type="text" name="genital_findings" class="w-full border rounded px-1"></td>
                                    </tr>
                                    <tr>
                                        <td>Mouth</td><td><input type="checkbox" name="mouth_normal"></td><td><input type="text" name="mouth_findings" class="w-full border rounded px-1"></td>
                                        <td>Musculo-Skeletal</td><td><input type="checkbox" name="musculo_normal"></td><td><input type="text" name="musculo_findings" class="w-full border rounded px-1"></td>
                                    </tr>
                                    <tr>
                                        <td>Neck, Thyroid, LN</td><td><input type="checkbox" name="neck_normal"></td><td><input type="text" name="neck_findings" class="w-full border rounded px-1"></td>
                                        <td>Extremities</td><td><input type="checkbox" name="extremities_normal"></td><td><input type="text" name="extremities_findings" class="w-full border rounded px-1"></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <!-- Diagnostic Examination -->
                    <div class="bg-gray-50 rounded-lg p-6 mb-8">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-bold text-orange-700">Diagnostic Examination</h3>
                            <span class="staff-only-pill"><i class="bi bi-shield-lock"></i> Staff Only</span>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4">
                            <div>
                                <label class="block text-xs font-semibold">Blood Pressure</label>
                                <input type="text" name="blood_pressure" class="w-full rounded border border-gray-300 px-2 py-1 text-xs">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold">Heart Rate</label>
                                <input type="text" name="heart_rate" class="w-full rounded border border-gray-300 px-2 py-1 text-xs">
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4">
                            <div>
                                <label class="block text-xs font-semibold">Hearing</label>
                                <div class="flex gap-4">
                                    <label class="flex items-center gap-1 text-xs"><input type="radio" name="hearing" value="Normal"> Normal</label>
                                    <label class="flex items-center gap-1 text-xs"><input type="radio" name="hearing" value="Defective"> Defective</label>
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold">Vision</label>
                                <div class="flex gap-4">
                                    <label class="flex items-center gap-1 text-xs"><input type="checkbox" name="vision_glasses"> With glasses</label>
                                    <label class="flex items-center gap-1 text-xs"><input type="checkbox" name="vision_without_glasses"> Without glasses</label>
                                </div>
                                <div class="flex gap-4 mt-2">
                                    <label class="block text-xs font-semibold">R:</label>
                                    <input type="text" name="vision_r" class="w-20 rounded border border-gray-300 px-2 py-1 text-xs">
                                    <label class="block text-xs font-semibold">L:</label>
                                    <input type="text" name="vision_l" class="w-20 rounded border border-gray-300 px-2 py-1 text-xs">
                                </div>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4">
                            <div>
                                <label class="block text-xs font-semibold">Chest X-Ray</label>
                                <div class="flex gap-4">
                                    <label class="flex items-center gap-1 text-xs"><input type="checkbox" name="chest_pa"> PA</label>
                                    <label class="flex items-center gap-1 text-xs"><input type="checkbox" name="chest_lordotic"> Lordotic</label>
                                </div>
                                <div class="flex gap-4 mt-2">
                                    <label class="flex items-center gap-1 text-xs"><input type="checkbox" name="chest_normal"> Normal</label>
                                    <label class="block text-xs font-semibold">Findings:</label>
                                    <input type="text" name="chest_findings_diag" class="w-32 rounded border border-gray-300 px-2 py-1 text-xs">
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold">Complete Blood Count</label>
                                <div class="flex gap-4">
                                    <label class="flex items-center gap-1 text-xs"><input type="checkbox" name="cbc_normal"> Normal</label>
                                    <label class="block text-xs font-semibold">Findings:</label>
                                    <input type="text" name="cbc_findings" class="w-32 rounded border border-gray-300 px-2 py-1 text-xs">
                                </div>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4">
                            <div>
                                <label class="block text-xs font-semibold">Routine Urinalysis</label>
                                <div class="flex gap-4">
                                    <label class="flex items-center gap-1 text-xs"><input type="checkbox" name="urinalysis_normal"> Normal</label>
                                    <label class="block text-xs font-semibold">Findings:</label>
                                    <input type="text" name="urinalysis_findings" class="w-32 rounded border border-gray-300 px-2 py-1 text-xs">
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold">Stool Examination</label>
                                <div class="flex gap-4">
                                    <label class="flex items-center gap-1 text-xs"><input type="checkbox" name="stool_normal"> Normal</label>
                                    <label class="block text-xs font-semibold">Findings:</label>
                                    <input type="text" name="stool_findings" class="w-32 rounded border border-gray-300 px-2 py-1 text-xs">
                                </div>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4">
                            <div>
                                <label class="block text-xs font-semibold">HEPA B Screening</label>
                                <div class="flex gap-4">
                                    <label class="flex items-center gap-1 text-xs"><input type="checkbox" name="hepa_normal"> Normal</label>
                                    <label class="block text-xs font-semibold">Findings:</label>
                                    <input type="text" name="hepa_findings" class="w-32 rounded border border-gray-300 px-2 py-1 text-xs">
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold">Drug Test</label>
                                <div class="flex gap-4">
                                    <label class="block text-xs font-semibold">Methamphetamine:</label>
                                    <label class="flex items-center gap-1 text-xs"><input type="radio" name="meth_negative" value="Negative"> Negative</label>
                                    <label class="flex items-center gap-1 text-xs"><input type="radio" name="meth_positive" value="Positive"> Positive</label>
                                </div>
                                <div class="flex gap-4 mt-2">
                                    <label class="block text-xs font-semibold">Tetrahydrocannabinol:</label>
                                    <label class="flex items-center gap-1 text-xs"><input type="radio" name="thc_negative" value="Negative"> Negative</label>
                                    <label class="flex items-center gap-1 text-xs"><input type="radio" name="thc_positive" value="Positive"> Positive</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <!-- Certification -->
                    <div class="bg-gray-50 rounded-lg p-6 mb-8">
                        <h3 class="text-lg font-bold text-orange-700 mb-4">Certification</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4">
                            <div>
                                <label class="block text-xs font-semibold">School/Company/Institution</label>
                                <input type="text" name="school_company" class="w-full rounded border border-gray-300 px-2 py-1 text-xs">
                                <label class="block text-xs font-semibold mt-2">Weight (kg)</label>
                                <input type="text" name="weight_cert" class="w-full rounded border border-gray-300 px-2 py-1 text-xs">
                                <label class="block text-xs font-semibold mt-2">Height (cm)</label>
                                <input type="text" name="height_cert" class="w-full rounded border border-gray-300 px-2 py-1 text-xs">
                                <label class="block text-xs font-semibold mt-2">Civil Status</label>
                                <input type="text" name="civil_status_cert" class="w-full rounded border border-gray-300 px-2 py-1 text-xs">
                                <label class="block text-xs font-semibold mt-2">Date of Examination</label>
                                <input type="date" name="date_cert" class="w-full rounded border border-gray-300 px-2 py-1 text-xs" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div class="flex flex-col items-center justify-center">
                                <label class="block text-xs font-semibold mb-2">Attach picture here</label>
                                <input type="file" name="picture" class="w-full rounded border border-gray-300 px-2 py-1 text-xs">
                            </div>
                        </div>
                        <div class="mb-4 text-xs text-gray-700">I certify that I have examined and found the applicant to be physically fit/unfit for employment.</div>
                        <?php if ($is_staff_user): ?>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4">
                            <div>
                                <div class="flex items-center justify-between">
                                    <label class="block text-xs font-semibold m-0">Classification</label>
                                    <span class="staff-only-pill"><i class="bi bi-shield-lock"></i> Staff Only</span>
                                </div>
                                <div class="flex flex-col gap-2 mt-2">
                                    <label class="flex items-center gap-1 text-xs"><input type="checkbox" name="class_a"> Class A - Physically fit to work</label>
                                    <label class="flex items-center gap-1 text-xs"><input type="checkbox" name="class_b"> Class B - Physically underdeveloped or with correctable defects but otherwise fit to work</label>
                                    <label class="flex items-center gap-1 text-xs"><input type="checkbox" name="class_c"> Class C - Employable but owing to certain impairments or conditions, requires special placement or limited duty in a specified or selected assignment requiring follow up treatment/periodic evaluation</label>
                                    <label class="flex items-center gap-1 text-xs"><input type="checkbox" name="class_d"> Class D - Unfit or unsafe for any type of employment</label>
                                </div>
                            </div>
                            <div>
                                <div class="flex items-center justify-between">
                                    <label class="block text-xs font-semibold m-0">Needs treatment or operation for:</label>
                                    <span class="staff-only-pill"><i class="bi bi-shield-lock"></i> Staff Only</span>
                                </div>
                                <div class="flex flex-col gap-2 mt-2">
                                    <label class="flex items-center gap-1 text-xs"><input type="checkbox" name="skin_disease"> Skin Disease</label>
                                    <label class="flex items-center gap-1 text-xs"><input type="checkbox" name="dental_defects"> Dental Defects</label>
                                    <label class="flex items-center gap-1 text-xs"><input type="checkbox" name="anemia"> Anemia</label>
                                    <label class="flex items-center gap-1 text-xs"><input type="checkbox" name="poor_vision"> Poor Vision</label>
                                    <label class="flex items-center gap-1 text-xs"><input type="checkbox" name="mild_urinary"> Mild Urinary Tract Infection</label>
                                    <label class="flex items-center gap-1 text-xs"><input type="checkbox" name="intestinal_parasite"> Intestinal Parasitism</label>
                                    <label class="flex items-center gap-1 text-xs"><input type="checkbox" name="mild_hypertension"> Mild Hypertension</label>
                                    <label class="flex items-center gap-1 text-xs"><input type="checkbox" name="others"> Others, specify: <input type="text" name="others_specify" class="w-32 rounded border border-gray-300 px-2 py-1 text-xs"></label>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4">
                            <div>
                                <label class="block text-xs font-semibold">Signature over Printed Name of Employee/Student</label>
                                <input type="text" name="student_signature" class="w-full rounded border border-gray-300 px-2 py-1 text-xs" required>
                            </div>
                            <?php if ($is_staff_user): ?>
                            <div>
                                <div class="flex items-center justify-between">
                                    <label class="block text-xs font-semibold m-0">Signature over Printed Name of Attending Physician</label>
                                    <span class="staff-only-pill"><i class="bi bi-shield-lock"></i> Staff Only</span>
                                </div>
                                <input type="text" name="physician_signature" class="w-full rounded border border-gray-300 px-2 py-1 text-xs mt-2" <?php echo $is_staff_user ? 'required' : ''; ?>>
                                <label class="block text-xs font-semibold mt-2">License No.</label>
                                <input type="text" name="license_no" class="w-full rounded border border-gray-300 px-2 py-1 text-xs" <?php echo $is_staff_user ? 'required' : ''; ?>>
                                <label class="block text-xs font-semibold mt-2">Date</label>
                                <input type="date" name="physician_date" class="w-full rounded border border-gray-300 px-2 py-1 text-xs" value="<?php echo date('Y-m-d'); ?>" <?php echo $is_staff_user ? 'required' : ''; ?>>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <!-- Data Privacy Act Notice -->
                    <div class="border-2 border-red-400 rounded-lg bg-white shadow p-4 mb-6">
                        <p class="text-xs text-gray-600 mb-2">Pursuant to Republic Act No. 10173, also known as the Data Privacy Act of 2012, the Batangas State University, the National Engineering University, recognizes its commitment to protect and respect the privacy of its customers and/or stakeholders and ensure that all information collected from them are all processed in accordance with the principles of transparency, legitimate purpose and proportionality mandated under the Data Privacy Act of 2012.</p>
                        <p class="text-xs text-gray-600 mb-2">This certificate does not cover conditions or diseases that will require procedure and examination for their detection and also those which are asymptomatic at the time of examination. Valid only for three (3) months from the date of examination.</p>
                    </div>
                    <div class="flex flex-col md:flex-row gap-4 justify-end mt-8 no-print">
                        <button type="submit" class="red-orange-gradient-button text-white font-semibold px-6 py-2 rounded-lg shadow hover:shadow-lg transition">Save Form</button>
                        <!-- <button type="button" class="bg-green-500 text-white font-semibold px-6 py-2 rounded-lg shadow hover:bg-green-600 transition" id="generateQR">Generate QR Code</button> -->
                        <button type="button" class="bg-gray-400 text-white font-semibold px-6 py-2 rounded-lg shadow hover:bg-gray-500 transition" onclick="optimizeAndPrint()">Print Form</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
        
        
    
   
    
    <script src="../../assets/js/jquery.min.js"></script>
    <script src="../../assets/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/qrcode.min.js"></script>
    <script>
        $(document).ready(function() {
            // Calculate BMI when weight or height changes
            $('#weight, #height').change(function() {
                calculateBMI();
            });
            
            // Handle normal checkboxes to clear/enable findings fields
            $('input[type="checkbox"]').change(function() {
                var fieldId = $(this).attr('id').replace('_normal', '');
                if ($(this).is(':checked')) {
                    $('#' + fieldId).val('Normal').prop('readonly', true);
                } else {
                    $('#' + fieldId).val('').prop('readonly', false);
                }
            });
            
            // Generate QR code
            $('#generateQR').click(function() {
                var studentId = $('#student_id').val();
                var name = $('#first_name').val() + ' ' + $('#last_name').val();
                var formType = 'Medical Examination Form';
                var date = $('#date_of_examination').val();
                
                if (!studentId || !name) {
                    alert('Please fill in student ID and name fields first.');
                    return;
                }
                
                var qrData = 'Student ID: ' + studentId + '\nName: ' + name + '\nForm: ' + formType + '\nDate: ' + date;
                
                $('#qrcode').empty();
                new QRCode(document.getElementById("qrcode"), {
                    text: qrData,
                    width: 200,
                    height: 200
                });
                
                $('#qrCodeModal').modal('show');
            });
            
            // Download QR code
            $('#downloadQR').click(function() {
                var canvas = document.querySelector("#qrcode canvas");
                var image = canvas.toDataURL("image/png").replace("image/png", "image/octet-stream");
                var link = document.createElement('a');
                link.download = 'qrcode.png';
                link.href = image;
                link.click();
            });
        });
        
        // Calculate BMI function
        function calculateBMI() {
            var weight = parseFloat($('#weight').val());
            var height = parseFloat($('#height').val()) / 100; // convert cm to m
            
            if (weight > 0 && height > 0) {
                var bmi = weight / (height * height);
                $('#bmi').val(bmi.toFixed(2));
                
                // Set BMI category
                var category = '';
                if (bmi < 18.5) {
                    category = 'Underweight';
                } else if (bmi >= 18.5 && bmi < 25) {
                    category = 'Normal weight';
                } else if (bmi >= 25 && bmi < 30) {
                    category = 'Overweight';
                } else {
                    category = 'Obese';
                }
                
                $('#bmi_category').val(category);
            }
        }
        
        // Optimized print function with proper page breaks
        function optimizeAndPrint() {
            // Add a class to body for print optimization
            document.body.classList.add('printing');
            
            // Print directly without alert
            window.print();
            
            // Remove class after print
            setTimeout(function() {
                document.body.classList.remove('printing');
            }, 1000);
        }
    </script>
<?php if (!empty($success_message)): ?>
<script>
    alert("Form successfully submitted! Please wait for admin verification.");
    window.location.href = "<?php echo htmlspecialchars($dashboard_url); ?>";
</script>
<?php endif; ?>
</body>

</html>