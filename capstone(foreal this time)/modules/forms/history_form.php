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

// Determine dashboard redirect based on role
$dashboard_url = '../../user_dashboard.php';
if (isset($_SESSION['role'])) {
    if ($_SESSION['role'] === 'doctor') {
        $dashboard_url = '../../doctor_dashboard.php';
    } elseif ($_SESSION['role'] === 'nurse') {
        $dashboard_url = '../../nurse_dashboard.php';
    } elseif (in_array($_SESSION['role'], ['admin', 'staff'])) {
        $dashboard_url = '../../dashboard.php';
    }
}

$success_message = '';
$error_message = '';

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
        $stmt = $conn->prepare("INSERT INTO medical_records (patient_id, record_type, examination_date, physician_name) VALUES (?, 'history_form', ?, ?)");
        $examination_date = date('Y-m-d');
        $physician_name = $_POST['physician_name'] ?? '';
        $stmt->bind_param("iss", $patient_id, $examination_date, $physician_name);
        
        if ($stmt->execute()) {
            $record_id = $conn->insert_id;
            
            // Insert history form data
            $stmt = $conn->prepare("INSERT INTO history_forms (record_id, denied_participation, asthma, seizure_disorder, heart_problem, diabetes, high_blood_pressure, surgery_history, chest_pain, injury_history, xray_history, head_injury, muscle_cramps, vision_problems, special_diet, menstrual_history) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            $denied_participation = isset($_POST['denied_participation']) ? 1 : 0;
            $asthma = isset($_POST['asthma']) ? 1 : 0;
            $seizure_disorder = isset($_POST['seizure_disorder']) ? 1 : 0;
            $heart_problem = isset($_POST['heart_problem']) ? 1 : 0;
            $diabetes = isset($_POST['diabetes']) ? 1 : 0;
            $high_blood_pressure = isset($_POST['high_blood_pressure']) ? 1 : 0;
            $surgery_history = $_POST['surgery_history'] ?? '';
            $chest_pain = isset($_POST['chest_pain']) ? 1 : 0;
            $injury_history = $_POST['injury_history'] ?? '';
            $xray_history = $_POST['xray_history'] ?? '';
            $head_injury = isset($_POST['head_injury']) ? 1 : 0;
            $muscle_cramps = isset($_POST['muscle_cramps']) ? 1 : 0;
            $vision_problems = isset($_POST['vision_problems']) ? 1 : 0;
            $special_diet = $_POST['special_diet'] ?? '';
            $menstrual_history = $_POST['menstrual_history'] ?? '';
            
          $stmt->bind_param("iiiiiiisissiiiss",
    $record_id,
    $denied_participation,
    $asthma,
    $seizure_disorder,
    $heart_problem,
    $diabetes,
    $high_blood_pressure,
    $surgery_history,
    $chest_pain,
    $injury_history,
    $xray_history,
    $head_injury,
    $muscle_cramps,
    $vision_problems,
    $special_diet,
    $menstrual_history
);

            if ($stmt->execute()) {
                // Generate QR code for this record
                $qr_data = "record_id=" . $record_id . "&type=history_form&date=" . $examination_date;
                $qr_code = base64_encode($qr_data);

                // Update patient with QR code
                $stmt = $conn->prepare("UPDATE patients SET qr_code = ? WHERE id = ?");
                $stmt->bind_param("si", $qr_code, $patient_id);
                $stmt->execute();

                // Add to analytics data
                $stmt = $conn->prepare("INSERT INTO analytics_data (data_type, data_value, data_label, data_date) VALUES ('history_form', 1, 'New History Form', CURDATE())");
                $stmt->execute();

                // Redirect to verification page
            $success_message = "Form successfully submitted! Please wait for admin verification.";

               
            } else {
                $error_message = "Error saving history form data: " . $conn->error;
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
    <title>History Form - BSU Clinic Records</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css">
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
            
            /* Pageless printing - use extremely large page size for continuous printing */
            @page {
                margin: 0;
                size: 100in 100in;
                page-break-inside: avoid;
            }
            
            /* Remove all forced page breaks - truly pageless continuous printing */
            html, body {
                height: auto !important;
                overflow: visible !important;
                page-break-inside: auto !important;
            }
            
            /* Prevent any page breaks anywhere - truly pageless */
            * {
                page-break-before: avoid !important;
                page-break-after: avoid !important;
                page-break-inside: avoid !important;
                break-before: avoid !important;
                break-after: avoid !important;
                break-inside: avoid !important;
                orphans: 9999 !important;
                widows: 9999 !important;
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
            .bg-white.shadow.rounded-lg {
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
            <a href="<?php echo htmlspecialchars($dashboard_url); ?>" class="inline-flex items-center gap-2 red-orange-gradient-button text-white font-semibold px-4 py-2 rounded-lg shadow hover:shadow-lg transition">
                <i class="bi bi-arrow-left"></i> Back to Dashboard
            </a>
        </div>
        <div class="max-w-5xl mx-auto px-4">
            <div class="bg-white shadow rounded-lg">
                <div class="red-orange-gradient text-white px-8 py-6 rounded-t-lg">
                    <h4 class="text-2xl font-bold mb-2">HISTORY FORM FOR STUDENT-ATHLETES IN SPORTS EVENTS</h4>
                    <p class="mb-0 text-sm">Reference No.: BatStateU-FO-HSD-17 | Effectivity Date: March 12, 2024 | Revision No.: 03</p>
                </div>
                <div class="px-8 py-8">
                        <?php if (!empty($success_message)): ?>
                            <div class="alert alert-success"><?php echo $success_message; ?></div>
                        <?php endif; ?>
                        
                        <?php if (!empty($error_message)): ?>
                            <div class="alert alert-danger"><?php echo $error_message; ?></div>
                        <?php endif; ?>
                        
                        <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="student_id" class="block font-medium mb-1">Student ID</label>
                                    <input type="text" class="w-full rounded border border-gray-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-orange-500" id="student_id" name="student_id" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="control_no" class="block font-medium mb-1">Control No.</label>
                                    <input type="text" class="w-full rounded border border-gray-300 px-3 py-2 bg-gray-100" id="control_no" name="control_no" value="UPA-<?php echo date('Ymd') . rand(100, 999); ?>" readonly>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-4">
                                    <label for="first_name" class="block font-medium mb-1">First Name</label>
                                    <input type="text" class="w-full rounded border border-gray-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-orange-500" id="first_name" name="first_name" required>
                                </div>
                                <div class="col-md-4">
                                    <label for="middle_name" class="block font-medium mb-1">Middle Name</label>
                                    <input type="text" class="w-full rounded border border-gray-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-orange-500" id="middle_name" name="middle_name">
                                </div>
                                <div class="col-md-4">
                                    <label for="last_name" class="block font-medium mb-1">Last Name</label>
                                    <input type="text" class="w-full rounded border border-gray-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-orange-500" id="last_name" name="last_name" required>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-4">
                                    <label for="date_of_examination" class="block font-medium mb-1">DATE OF EXAMINATION</label>
                                    <input type="date" class="w-full rounded border border-gray-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-orange-500" id="date_of_examination" name="date_of_examination" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label for="sex" class="block font-medium mb-1">SEX</label>
                                    <select class="w-full rounded border border-gray-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-orange-500" id="sex" name="sex" required>
                                        <option value="">Select</option>
                                        <option value="Male">Male</option>
                                        <option value="Female">Female</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label for="age" class="block font-medium mb-1">AGE</label>
                                    <input type="number" class="w-full rounded border border-gray-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-orange-500" id="age" name="age" required>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="program" class="block font-medium mb-1">GRADE/LEVEL/PROGRAM</label>
                                    <input type="text" class="w-full rounded border border-gray-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-orange-500" id="program" name="program" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="date_of_birth" class="block font-medium mb-1">DATE OF BIRTH</label>
                                    <input type="date" class="w-full rounded border border-gray-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-orange-500" id="date_of_birth" name="date_of_birth" required>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-12">
                                    <label for="sports_event" class="block font-medium mb-1">SPORTS EVENT</label>
                                    <input type="text" class="w-full rounded border border-gray-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-orange-500" id="sports_event" name="sports_event" required>
                                </div>
                            </div>
                            
                            <h5 class="mt-4 mb-3 text-orange-700 font-bold">PHYSICAL EXAMINATION</h5>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <table class="min-w-full border-2 border-orange-400 rounded-lg overflow-hidden mb-6 bg-white shadow">
                                        <thead>
                                            <tr>
                                                <th>REVIEW OF SYSTEM</th>
                                                <th>NORMAL</th>
                                                <th>FINDINGS</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td>Height</td>
                                                <td class="text-center"><input type="checkbox" name="height_normal" class="form-checkbox h-5 w-5 text-orange-600"></td>
                                                <td><input type="text" name="height_findings" class="w-full rounded border border-gray-300 px-2 py-1 bg-gray-50"></td>
                                            </tr>
                                            <tr>
                                                <td>Weight</td>
                                                <td class="text-center"><input type="checkbox" name="weight_normal" class="form-checkbox h-5 w-5 text-orange-600"></td>
                                                <td><input type="text" name="weight_findings" class="w-full rounded border border-gray-300 px-2 py-1 bg-gray-50"></td>
                                            </tr>
                                            <tr>
                                                <td>Blood Pressure</td>
                                                <td class="text-center"><input type="checkbox" name="bp_normal" class="form-checkbox h-5 w-5 text-orange-600"></td>
                                                <td><input type="text" name="bp_findings" class="w-full rounded border border-gray-300 px-2 py-1 bg-gray-50"></td>
                                            </tr>
                                            <tr>
                                                <td>Pulse Rate</td>
                                                <td class="text-center"><input type="checkbox" name="pulse_normal" class="form-checkbox h-5 w-5 text-orange-600"></td>
                                                <td><input type="text" name="pulse_findings" class="w-full rounded border border-gray-300 px-2 py-1 bg-gray-50"></td>
                                            </tr>
                                            <tr>
                                                <td>Vision: R20/ L20/</td>
                                                <td class="text-center"><input type="checkbox" name="vision_normal" class="form-checkbox h-5 w-5 text-orange-600"></td>
                                                <td><input type="text" name="vision_findings" class="w-full rounded border border-gray-300 px-2 py-1 bg-gray-50"></td>
                                            </tr>
                                            <tr>
                                                <td>Appearance</td>
                                                <td class="text-center"><input type="checkbox" name="appearance_normal" class="form-checkbox h-5 w-5 text-orange-600"></td>
                                                <td><input type="text" name="appearance_findings" class="w-full rounded border border-gray-300 px-2 py-1 bg-gray-50"></td>
                                            </tr>
                                            <tr>
                                                <td>Eyes/Ear/Nose/Throat</td>
                                                <td class="text-center"><input type="checkbox" name="eent_normal" class="form-checkbox h-5 w-5 text-orange-600"></td>
                                                <td><input type="text" name="eent_findings" class="w-full rounded border border-gray-300 px-2 py-1 bg-gray-50"></td>
                                            </tr>
                                            <tr>
                                                <td>Pupils Equal</td>
                                                <td class="text-center"><input type="checkbox" name="pupils_normal" class="form-checkbox h-5 w-5 text-orange-600"></td>
                                                <td><input type="text" name="pupils_findings" class="w-full rounded border border-gray-300 px-2 py-1 bg-gray-50"></td>
                                            </tr>
                                            <tr>
                                                <td>Hearing</td>
                                                <td class="text-center"><input type="checkbox" name="hearing_normal" class="form-checkbox h-5 w-5 text-orange-600"></td>
                                                <td><input type="text" name="hearing_findings" class="w-full rounded border border-gray-300 px-2 py-1 bg-gray-50"></td>
                                            </tr>
                                            <tr>
                                                <td>Chest</td>
                                                <td class="text-center"><input type="checkbox" name="chest_normal" class="form-checkbox h-5 w-5 text-orange-600"></td>
                                                <td><input type="text" name="chest_findings" class="w-full rounded border border-gray-300 px-2 py-1 bg-gray-50"></td>
                                            </tr>
                                            <tr>
                                               <td>Heart</td>
                                                <td class="text-center"><input type="checkbox" name="chest_normal" class="form-checkbox h-5 w-5 text-orange-600"></td>
                                                <td><input type="text" name="heart_findings" class="w-full rounded border border-gray-300 px-2 py-1 bg-gray-50"></td>
                                            </tr>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="col-md-6">
                                    <table class="min-w-full border-2 border-orange-400 rounded-lg overflow-hidden mb-6 bg-white shadow">
                                        <thead>
                                            <tr>
                                                <th>REVIEW OF SYSTEM</th>
                                                <th>NORMAL</th>
                                                <th>FINDINGS</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td>Abdomen</td>
                                                <td class="text-center"><input type="checkbox" name="abdomen_normal" class="form-checkbox h-5 w-5 text-orange-600"></td>
                                                <td><input type="text" name="abdomen_findings" class="w-full rounded border border-gray-300 px-2 py-1 bg-gray-50"></td>
                                            </tr>
                                            <tr>
                                                <td>Genitourinary (MALES ONLY)</td>
                                                <td class="text-center"><input type="checkbox" name="genitourinary_normal" class="form-checkbox h-5 w-5 text-orange-600"></td>
                                                <td><input type="text" name="genitourinary_findings" class="w-full rounded border border-gray-300 px-2 py-1 bg-gray-50"></td>
                                            </tr>
                                            <tr>
                                                <td>Neurologic</td>
                                                <td class="text-center"><input type="checkbox" name="neurologic_normal" class="form-checkbox h-5 w-5 text-orange-600"></td>
                                                <td><input type="text" name="neurologic_findings" class="w-full rounded border border-gray-300 px-2 py-1 bg-gray-50"></td>
                                            </tr>
                                            <tr>
                                                <td>Neck</td>
                                                <td class="text-center"><input type="checkbox" name="neck_normal" class="form-checkbox h-5 w-5 text-orange-600"></td>
                                                <td><input type="text" name="neck_findings" class="w-full rounded border border-gray-300 px-2 py-1 bg-gray-50"></td>
                                            </tr>
                                            <tr>
                                                <td>Back</td>
                                                <td class="text-center"><input type="checkbox" name="back_normal" class="form-checkbox h-5 w-5 text-orange-600"></td>
                                                <td><input type="text" name="back_findings" class="w-full rounded border border-gray-300 px-2 py-1 bg-gray-50"></td>
                                            </tr>
                                            <tr>
                                                <td>Shoulder/Arm</td>
                                                <td class="text-center"><input type="checkbox" name="shoulder_arm_normal" class="form-checkbox h-5 w-5 text-orange-600"></td>
                                                <td><input type="text" name="shoulder_arm_findings" class="w-full rounded border border-gray-300 px-2 py-1 bg-gray-50"></td>
                                            </tr>
                                            <tr>
                                                <td>Elbow/Forearm</td>
                                                <td class="text-center"><input type="checkbox" name="elbow_forearm_normal" class="form-checkbox h-5 w-5 text-orange-600"></td>
                                                <td><input type="text" name="elbow_forearm_findings" class="w-full rounded border border-gray-300 px-2 py-1 bg-gray-50"></td>
                                            </tr>
                                            <tr>
                                                <td>Wrist/Hand/Fingers</td>
                                                <td class="text-center"><input type="checkbox" name="wrist_hand_normal" class="form-checkbox h-5 w-5 text-orange-600"></td>
                                                <td><input type="text" name="wrist_hand_findings" class="w-full rounded border border-gray-300 px-2 py-1 bg-gray-50"></td>
                                            </tr>
                                            <tr>
                                                <td>Knee</td>
                                                <td class="text-center"><input type="checkbox" name="knee_normal" class="form-checkbox h-5 w-5 text-orange-600"></td>
                                                <td><input type="text" name="knee_findings" class="w-full rounded border border-gray-300 px-2 py-1 bg-gray-50"></td>
                                            </tr>
                                            <tr>
                                                <td>Leg/Ankle</td>
                                                <td class="text-center"><input type="checkbox" name="leg_ankle_normal" class="form-checkbox h-5 w-5 text-orange-600"></td>
                                                <td><input type="text" name="leg_ankle_findings" class="w-full rounded border border-gray-300 px-2 py-1 bg-gray-50"></td>
                                            </tr>
                                            <tr>
                                                <td>Foot/Toes</td>
                                                <td class="text-center"><input type="checkbox" name="foot_toes_normal" class="form-checkbox h-5 w-5 text-orange-600"></td>
                                                <td><input type="text" name="foot_toes_findings" class="w-full rounded border border-gray-300 px-2 py-1 bg-gray-50"></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            
                            <h5 class="mt-4 mb-3 text-orange-700 font-bold">GENERAL QUESTIONS</h5>
                            
                            <table class="min-w-full border-2 border-orange-400 rounded-lg overflow-hidden mb-6 bg-white shadow">
                                <thead>
                                    <tr>
                                        <th>Check the following for your answers:</th>
                                        <th>Yes</th>
                                        <th>No</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>1. Have you been denied or restricted your participation in sports activities</td>
                                        <td><input class="form-check-input" type="radio" name="denied_participation" value="1"></td>
                                        <td><input class="form-check-input" type="radio" name="denied_participation" value="0" checked></td>
                                    </tr>
                                    <tr>
                                        <td colspan="3">Do you have any of the following conditions:</td>
                                    </tr>
                                    <tr>
                                        <td>a. Asthma</td>
                                        <td><input class="form-check-input" type="radio" name="asthma" value="1"></td>
                                        <td><input class="form-check-input" type="radio" name="asthma" value="0" checked></td>
                                    </tr>
                                    <tr>
                                        <td>b. Seizure disorder</td>
                                        <td><input class="form-check-input" type="radio" name="seizure_disorder" value="1"></td>
                                        <td><input class="form-check-input" type="radio" name="seizure_disorder" value="0" checked></td>
                                    </tr>
                                    <tr>
                                        <td>c. Heart problem</td>
                                        <td><input class="form-check-input" type="radio" name="heart_problem" value="1"></td>
                                        <td><input class="form-check-input" type="radio" name="heart_problem" value="0" checked></td>
                                    </tr>
                                    <tr>
                                        <td>d. Diabetes</td>
                                        <td><input class="form-check-input" type="radio" name="diabetes" value="1"></td>
                                        <td><input class="form-check-input" type="radio" name="diabetes" value="0" checked></td>
                                    </tr>
                                    <tr>
                                        <td>e. High Blood Pressure</td>
                                        <td><input class="form-check-input" type="radio" name="high_blood_pressure" value="1"></td>
                                        <td><input class="form-check-input" type="radio" name="high_blood_pressure" value="0" checked></td>
                                    </tr>
                                    <tr>
                                        <td>2. Have you had any surgery?</td>
                                        <td><input class="form-check-input" type="radio" name="surgery" value="1"></td>
                                        <td><input class="form-check-input" type="radio" name="surgery" value="0" checked></td>
                                    </tr>
                                    <tr>
                                        <td>3. Have you had any discomfort, chest pain or chest tightness in your chest during exercise?</td>
                                        <td><input class="form-check-input" type="radio" name="chest_pain" value="1"></td>
                                        <td><input class="form-check-input" type="radio" name="chest_pain" value="0" checked></td>
                                    </tr>
                                    <tr>
                                        <td>4. Have you had any injury to the bones, muscle, ligament or tendon?</td>
                                        <td><input class="form-check-input" type="radio" name="injury" value="1"></td>
                                        <td><input class="form-check-input" type="radio" name="injury" value="0" checked></td>
                                    </tr>
                                    <tr>
                                        <td>5. Have you had any injury that requires x-ray, CT scan or MRI, brace, cast or crutches?</td>
                                        <td><input class="form-check-input" type="radio" name="xray" value="1"></td>
                                        <td><input class="form-check-input" type="radio" name="xray" value="0" checked></td>
                                    </tr>
                                    <tr>
                                        <td>6. Have you had any head injury or concussion?</td>
                                        <td><input class="form-check-input" type="radio" name="head_injury" value="1"></td>
                                        <td><input class="form-check-input" type="radio" name="head_injury" value="0" checked></td>
                                    </tr>
                                    <tr>
                                        <td>7. Do you have frequent muscle cramps when exercising?</td>
                                        <td><input class="form-check-input" type="radio" name="muscle_cramps" value="1"></td>
                                        <td><input class="form-check-input" type="radio" name="muscle_cramps" value="0" checked></td>
                                    </tr>
                                    <tr>
                                        <td>8. Have you had any problems with your eyes or vision?</td>
                                        <td><input class="form-check-input" type="radio" name="vision_problems" value="1"></td>
                                        <td><input class="form-check-input" type="radio" name="vision_problems" value="0" checked></td>
                                    </tr>
                                    <tr>
                                        <td>9. Are you on a special diet or do you avoid certain types of foods?</td>
                                        <td><input class="form-check-input" type="radio" name="special_diet" value="1"></td>
                                        <td><input class="form-check-input" type="radio" name="special_diet" value="0" checked></td>
                                    </tr>
                                </tbody>
                            </table>
                            
                            <div class="female-only-section mt-4">
                                <h5 class="text-pink-600 font-bold">FOR FEMALES ONLY</h5>
                                <table class="min-w-full border-2 border-pink-400 rounded-lg overflow-hidden mb-6 bg-white shadow">
                                    <tbody>
                                        <tr>
                                            <td>10. Have you ever had a menstrual period? (LMP)</td>
                                            <td><input class="form-check-input" type="radio" name="menstrual_period" value="1"></td>
                                            <td><input class="form-check-input" type="radio" name="menstrual_period" value="0" checked></td>
                                        </tr>
                                        <tr>
                                            <td>11. How old were you when you had your first menstrual period?</td>
                                            <td colspan="2"><input type="text" name="menstrual_age" class="w-full rounded border-2 border-pink-400 px-2 py-1 bg-pink-50"></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            
                            <h5 class="mt-4 mb-3 text-orange-700 font-bold">CERTIFICATION</h5>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <p>I hereby certify that the above information given are true and correct as to the best of my knowledge.</p>
                                    <div class="mb-3">
                                        <label for="student_signature" class="form-label">Signature over Printed Name of Student-Athlete</label>
                                        <input type="text" class="form-control" id="student_signature" name="student_signature" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="student_date" class="form-label">Date</label>
                                        <input type="date" class="form-control" id="student_date" name="student_date" value="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <p>Examined by:</p>
                                    <div class="mb-3">
                                        <label for="physician_name" class="form-label">Signature over Printed Name of Attending Physician / Nurse</label>
                                        <input type="text" class="form-control" id="physician_name" name="physician_name" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="license_no" class="form-label">License No.</label>
                                        <input type="text" id="license_no" name="license_no" required class="w-full rounded border-2 border-orange-400 px-3 py-2 bg-orange-50">
                                    </div>
                                    <div class="mb-3">
                                        <label for="physician_date" class="form-label">Date</label>
                                        <input type="date" class="form-control" id="physician_date" name="physician_date" value="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="border-2 border-orange-400 rounded-lg bg-white shadow p-4 mb-6">
                                <p class="text-xs text-gray-600 mb-2">Pursuant to Republic Act No. 10173, also known as the Data Privacy Act of 2012, the Batangas State University, the National Engineering University, recognizes its commitment to protect and respect the privacy of its customers and/or stakeholders and ensure that all information collected from them are all processed in accordance with the principles of transparency, legitimate purpose and proportionality mandated under the Data Privacy Act of 2012.</p>
                                <p class="text-xs text-gray-600">This certificate does not cover conditions or diseases that will require procedure and examination for their detection and also those which are asymptomatic at the time of examination. Valid only for three (3) months from the date of examination.</p>
                            </div>
                            
                            <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4 no-print">
                                <div class="flex flex-col md:flex-row gap-4 justify-end mt-8">
                                    <button type="submit" class="red-orange-gradient-button text-white font-semibold px-6 py-2 rounded-lg shadow hover:shadow-lg transition">Save Form</button>
                                    <!-- <button type="button" class="bg-green-500 text-white font-semibold px-6 py-2 rounded-lg shadow hover:bg-green-600 transition" id="generateQR">Generate QR Code</button> -->
                                    <button type="button" class="bg-gray-400 text-white font-semibold px-6 py-2 rounded-lg shadow hover:bg-gray-500 transition" onclick="window.print()">Print Form</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        
     
    </div>
    
  
    
    <script src="../../assets/js/jquery.min.js"></script>
    <script src="../../assets/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/qrcode.min.js"></script>
    <script>
        $(document).ready(function() {
            // Show/hide female-only section based on sex selection
            $('#sex').change(function() {
                if ($(this).val() === 'Female') {
                    $('.female-only-section').show();
                } else {
                    $('.female-only-section').hide();
                }
            });
            
            // Generate QR code
            $('#generateQR').click(function() {
                var studentId = $('#student_id').val();
                var name = $('#first_name').val() + ' ' + $('#last_name').val();
                var formType = 'History Form';
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
    </script>
    <?php if (!empty($success_message)): ?>
<script>
    alert("Form successfully submitted! Please wait for admin verification.");
    window.location.href = "<?php echo htmlspecialchars($dashboard_url); ?>";
</script>
<?php endif; ?>

</body>
</html>