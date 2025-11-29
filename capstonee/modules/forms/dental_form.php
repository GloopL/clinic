<?php
session_start();
include __DIR__ . '/../../config/database.php';

function collectDentalSelections(array $options, array $source): string
{
    $selected = [];
    foreach ($options as $field => $label) {
        if (!empty($source[$field])) {
            $selected[] = $label;
        }
    }
    return json_encode($selected);
}


// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    // Use absolute URL for proper redirect
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    header("Location: $protocol://$host/index.php");
    exit();
}

$user_role = $_SESSION['role'] ?? 'user';
$dashboard_paths = [
    'doctor' => '../../doctor_dashboard.php',
    'dentist' => '../../dentist_dashboard.php',
    'nurse' => '../../nurse_dashboard.php',
    'staff' => '../../msa_dashboard.php',
    'student' => '../../user_dashboard.php',
    'user' => '../../user_dashboard.php'
];
$dashboard_url = $dashboard_paths[$user_role] ?? '../../user_dashboard.php';
$is_staff_user = in_array($user_role, ['doctor', 'dentist', 'nurse', 'staff', 'admin']);

$tooth_labels = [
    1 => 'M3', 2 => 'M2', 3 => 'M1', 4 => 'P2', 5 => 'P1', 6 => 'C', 7 => 'I2', 8 => 'I1',
    9 => 'I1', 10 => 'I2', 11 => 'C', 12 => 'P1', 13 => 'P2', 14 => 'M1', 15 => 'M2', 16 => 'M3',
    17 => 'M3', 18 => 'M2', 19 => 'M1', 20 => 'P2', 21 => 'P1', 22 => 'C', 23 => 'I2', 24 => 'I1',
    25 => 'I1', 26 => 'I2', 27 => 'C', 28 => 'P1', 29 => 'P2', 30 => 'M1', 31 => 'M2', 32 => 'M3'
];
$upper_teeth = range(1, 16);
$lower_teeth = range(17, 32);

$success_message = '';
$error_message = '';

// Process form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $student_id = trim($_POST['student_id'] ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $date_of_birth = trim($_POST['date_of_birth'] ?? '');
    $sex = trim($_POST['sex'] ?? '');
    $program = trim($_POST['program'] ?? '');
    $year_level = trim($_POST['year_level'] ?? '');

    // ✅ Step 1: Check if patient already exists by student_id
    $stmt = $conn->prepare("SELECT id FROM patients WHERE student_id = ?");
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        // Patient already exists, fetch ID
        $row = $result->fetch_assoc();
        $patient_id = $row['id'];
    } else {
        // ✅ Step 2: Create new patient if not found
        $stmt = $conn->prepare("INSERT INTO patients 
            (student_id, first_name, middle_name, last_name, date_of_birth, sex, program, year_level) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssssss", $student_id, $first_name, $middle_name, $last_name, $date_of_birth, $sex, $program, $year_level);

        if ($stmt->execute()) {
            $patient_id = $conn->insert_id;
        } else {
            $error_message = "Error creating patient record: " . $conn->error;
        }
    }

    // ✅ Step 3: Create a new medical record entry
    if (!empty($patient_id)) {
        $stmt = $conn->prepare("INSERT INTO medical_records (patient_id, record_type, examination_date, physician_name)
                                VALUES (?, 'dental_exam', ?, ?)");
        $examination_date = date('Y-m-d');
        $physician_name = $_POST['dentist_name'] ?? '';
        $stmt->bind_param("iss", $patient_id, $examination_date, $physician_name);

        if ($stmt->execute()) {
            $record_id = $conn->insert_id;

            // ✅ Step 4: Insert dental exam details
            $stmt = $conn->prepare("
                INSERT INTO dental_exams
                (record_id, dentition_status, treatment_needs, periodontal_screening, occlusion, appliances, tmd_status, remarks, dentist_name, license_no, dental_chart_data, verification_status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
            ");

            $dentition_status = $_POST['status_grid'] ?? '';
            $treatment_needs = $_POST['treatment_nature'][0] ?? '';

            $periodontal_options = [
                'gingivitis' => 'Gingivitis',
                'early_periodontitis' => 'Early Periodontitis',
                'moderate_periodontitis' => 'Moderate Periodontitis',
                'advanced_periodontitis' => 'Advanced Periodontitis'
            ];

            $occlusion_options = [
                'class_molar' => 'Occlusion Class Molar',
                'overjet' => 'Overjet',
                'overbite' => 'Overbite',
                'crossbite' => 'Crossbite',
                'midline_deviation' => 'Midline Deviation'
            ];

            $appliance_options = [
                'orthodontic' => 'Orthodontic Appliance',
                'stayplate' => 'Stayplate / Retainer',
                'appliance_others' => 'Other Appliance'
            ];

            $tmd_options = [
                'clenching' => 'Clenching',
                'clicking' => 'Clicking',
                'trismus' => 'Trismus',
                'muscle_spasm' => 'Muscle Spasm'
            ];

            $periodontal_screening = collectDentalSelections($periodontal_options, $_POST);
            $occlusion = collectDentalSelections($occlusion_options, $_POST);
            $appliances = collectDentalSelections($appliance_options, $_POST);
            $tmd_status = collectDentalSelections($tmd_options, $_POST);
            $remarks = $_POST['remarks'] ?? '';
            $dentist_name = $_POST['dentist_name'] ?? '';
            $license_no = $_POST['license_no'] ?? '';
            $dental_chart_data = $_POST['dental_chart_data'] ?? '';

            $stmt->bind_param(
                "issssssssss",
                $record_id,
                $dentition_status,
                $treatment_needs,
                $periodontal_screening,
                $occlusion,
                $appliances,
                $tmd_status,
                $remarks,
                $dentist_name,
                $license_no,
                $dental_chart_data
            );

            if ($stmt->execute()) {
                // ✅ Step 5: Redirect to verification page
                $success_message = "Form successfully submitted! Please wait for admin verification.";
            } else {
                $error_message = "Error saving dental exam data: " . $conn->error;
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
    <title>Dental Examination Form - BSU Clinic Records</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        .grid-cols-16 {
            grid-template-columns: repeat(16, minmax(0, 1fr));
        }
        .tooth {
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        .tooth-upper {
            border-radius: 50% 50% 0 0;
        }
        .tooth-lower {
            border-radius: 0 0 50% 50%;
        }
        .tooth:hover {
            transform: scale(1.1);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 10;
        }
        .tooth.selected {
            box-shadow: 0 0 0 2px #3b82f6;
        }
        .tooth-label {
            font-size: 0.5rem;
            font-weight: 600;
        }
        @media (max-width: 768px) {
            .grid-cols-16 {
                grid-template-columns: repeat(8, minmax(0, 1fr));
            }
            .tooth {
                width: 2rem;
                height: 3rem;
            }
            .tooth-label {
                font-size: 0.4rem;
            }
        }
        .tooth-condition {
            font-size: 0.5rem;
            margin-top: 0.25rem;
            font-weight: bold;
            min-height: 0.75rem;
        }
        .legend-item {
            cursor: pointer;
            border-radius: 999px;
            padding: 6px 12px;
            border: 1px solid transparent;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .legend-item.active {
            border-color: #f97316;
            box-shadow: 0 0 0 2px rgba(249, 115, 22, 0.25);
        }
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
            
            /* Page break management - keep sections together */
            /* Prevent page breaks inside major sections */
            h5 {
                page-break-after: avoid;
                break-after: avoid;
            }
            
            /* Keep Patient Information section together */
            h5:first-of-type {
                page-break-before: auto;
                break-before: auto;
            }
            
            /* Keep Dental Chart section together - prevent splitting */
            #interactive-dental-chart {
                page-break-inside: avoid;
                break-inside: avoid;
            }
            
            /* Keep treatment record table together */
            .overflow-x-auto {
                page-break-inside: avoid;
                break-inside: avoid;
            }
            
            /* Keep tables together - prevent splitting */
            table {
                page-break-inside: avoid;
                break-inside: avoid;
            }
            
            /* Keep table rows together */
            tr {
                page-break-inside: avoid;
                break-inside: avoid;
            }
            
            /* Keep Index Tables section together */
            .grid.grid-cols-1.md\\:grid-cols-2 {
                page-break-inside: avoid;
                break-inside: avoid;
            }
            
            /* Keep Legend section together */
            .grid.grid-cols-2.md\\:grid-cols-4 {
                page-break-inside: avoid;
                break-inside: avoid;
            }
            
            /* Keep Periodontal Screening and Occlusion sections together */
            .grid.grid-cols-1.md\\:grid-cols-2 > div {
                page-break-inside: avoid;
                break-inside: avoid;
            }
            
            /* Allow page breaks between major sections */
            .staff-only-section {
                page-break-inside: avoid;
                break-inside: avoid;
            }
            
            /* Keep remarks textarea together */
            textarea {
                page-break-inside: avoid;
                break-inside: avoid;
            }
            
            /* Keep Data Privacy section on same page as certification if possible */
            .border-2.border-orange-400 {
                page-break-inside: avoid;
                break-inside: avoid;
            }
            
            /* Better control for form sections */
            form > div,
            form > section {
                orphans: 3;
                widows: 3;
            }
            
            /* Add strategic page breaks before major sections if they don't fit */
            .staff-only-section + .staff-only-section {
                page-break-before: auto;
                break-before: auto;
            }
            
            /* Keep certification heading with its content */
            h5 + .grid {
                page-break-inside: avoid;
                break-inside: avoid;
            }
            
            /* Compact patient information for print - fit on first page with dental chart */
            .patient-info-section {
                margin-bottom: 0.75rem !important;
            }
            
            .patient-info-section h5 {
                margin-bottom: 0.5rem !important;
                font-size: 1rem !important;
            }
            
            .patient-info-section .grid {
                gap: 0.5rem !important;
                margin-bottom: 0.5rem !important;
            }
            
            .patient-info-section label {
                font-size: 0.75rem !important;
                margin-bottom: 0.25rem !important;
            }
            
            .patient-info-section input,
            .patient-info-section select {
                padding: 0.25rem 0.5rem !important;
                font-size: 0.875rem !important;
                height: auto !important;
            }
            
            /* Compact dental chart section for print */
            #interactive-dental-chart {
                margin-top: 0.25rem !important;
                margin-bottom: 0.5rem !important;
            }
            
            .staff-only-section .mt-4 {
                margin-top: 0.5rem !important;
            }
            
            .staff-only-section .mb-3 {
                margin-bottom: 0.5rem !important;
            }
            
            .staff-only-section .mb-4 {
                margin-bottom: 0.5rem !important;
            }
            
            #interactive-dental-chart h5 {
                margin-bottom: 0.5rem !important;
                font-size: 1rem !important;
            }
            
            #interactive-dental-chart p {
                font-size: 0.75rem !important;
                margin-bottom: 0.25rem !important;
            }
            
            /* Smaller teeth in print */
            .tooth {
                width: 1.5rem !important;
                height: 2rem !important;
                min-width: 1.5rem !important;
            }
            
            .tooth-label {
                font-size: 0.5rem !important;
            }
            
            .tooth-number {
                font-size: 0.6rem !important;
                margin-bottom: 0.25rem !important;
            }
            
            .tooth-condition {
                font-size: 0.5rem !important;
                min-height: 0.5rem !important;
            }
            
            /* Compact legend items */
            .legend-item {
                padding: 0.25rem 0.5rem !important;
                font-size: 0.7rem !important;
            }
            
            .legend-item .w-3 {
                width: 0.5rem !important;
                height: 0.5rem !important;
            }
            
            /* Reduce spacing in dental chart container */
            .bg-white.border-2.border-orange-200 {
                padding: 0.75rem !important;
            }
            
            .bg-white.border-2.border-orange-200 .mb-8 {
                margin-bottom: 0.75rem !important;
            }
            
            .bg-white.border-2.border-orange-200 h5 {
                font-size: 0.875rem !important;
                padding: 0.25rem !important;
                margin-bottom: 0.5rem !important;
            }
            
            /* Compact summary box */
            .bg-blue-50.p-4 {
                padding: 0.5rem !important;
            }
            
            .bg-blue-50.p-4 h5 {
                font-size: 0.875rem !important;
                margin-bottom: 0.25rem !important;
            }
            
            .bg-blue-50.p-4 .text-sm {
                font-size: 0.7rem !important;
            }
            
            /* Reduce padding in form container for print */
            .px-8.py-8 {
                padding: 0.75rem 1rem !important;
            }
            
            /* Reduce header padding for print */
            .red-orange-gradient {
                padding: 0.5rem 1rem !important;
            }
            
            .red-orange-gradient h4 {
                font-size: 1.25rem !important;
                margin-bottom: 0.25rem !important;
            }
            
            .red-orange-gradient p {
                font-size: 0.7rem !important;
            }
            
            /* Keep Patient Information and Dental Chart together on first page */
            .patient-info-section {
                page-break-inside: avoid;
                break-inside: avoid;
            }
            
            #interactive-dental-chart {
                page-break-inside: avoid;
                break-inside: avoid;
            }
            
            /* Keep both sections together - no page break between them */
            .patient-info-section + .staff-only-section {
                page-break-before: avoid;
                break-before: avoid;
            }
            
            /* Force page break after dental chart summary (before Treatment Record) */
            .bg-blue-50.p-4.rounded-lg.border.border-blue-200 {
                page-break-after: always;
                break-after: page;
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
                    <h4 class="text-2xl font-bold mb-2">DENTAL EXAMINATION FORM</h4>
                    <p class="mb-0 text-sm">Reference No.: BatStateU-FO-HSD-18 | Effectivity Date: March 12, 2024 | Revision No.: 03</p>
                </div>
                <div class="px-8 py-8">
                    <?php if (!empty($success_message)): ?>
                        <div class="alert alert-success"><?php echo $success_message; ?></div>
                    <?php endif; ?>
                    <?php if (!empty($error_message)): ?>
                        <div class="alert alert-danger"><?php echo $error_message; ?></div>
                    <?php endif; ?>
                    <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                        <!-- Patient Information Section -->
                     <!-- Patient Information Section -->
<div class="patient-info-section">
<h5 class="text-lg font-bold text-orange-700 mb-4">Patient Information</h5>

<div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
    <div>
        <label for="student_id" class="block font-medium mb-1 text-sm">Student ID</label>
        <input type="text" class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm" id="student_id" name="student_id" required>
    </div>
    <div>
        <label for="program" class="block font-medium mb-1 text-sm">Program</label>
        <input type="text" class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm" id="program" name="program" required>
    </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
    <div>
        <label for="first_name" class="block font-medium mb-1 text-sm">First Name</label>
        <input type="text" class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm" id="first_name" name="first_name" required>
    </div>
    <div>
        <label for="middle_name" class="block font-medium mb-1 text-sm">Middle Name</label>
        <input type="text" class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm" id="middle_name" name="middle_name">
    </div>
    <div>
        <label for="last_name" class="block font-medium mb-1 text-sm">Last Name</label>
        <input type="text" class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm" id="last_name" name="last_name" required>
    </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
    <div>
        <label for="date_of_birth" class="block font-medium mb-1 text-sm">Date of Birth</label>
        <input type="date" class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm" id="date_of_birth" name="date_of_birth" required>
    </div>
    <div>
        <label class="block font-medium mb-1 text-sm">Sex</label>
        <select name="sex" id="sex" class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm" required>
            <option value="">Select</option>
            <option value="Male">Male</option>
            <option value="Female">Female</option>
        </select>
    </div>
    <div>
        <label for="year_level" class="block font-medium mb-1 text-sm">Year Level</label>
        <input type="text" class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm" id="year_level" name="year_level" required>
    </div>
</div>
</div>

<?php if (!$is_staff_user): ?>
<div class="bg-blue-50 border border-blue-200 text-sm text-blue-800 rounded-lg p-4 mb-8">
    Dental findings and certification fields are reserved for clinic staff. Please submit the form once your personal information is complete.
</div>
<?php endif; ?>


                        <!-- Dental Chart -->
                        <div class="staff-only-section">
                        <div class="flex items-center justify-between mt-4 mb-3">
                            <h5 class="text-lg font-bold text-orange-700 mb-0">Dental Chart</h5>
                            <span class="staff-only-pill"><i class="bi bi-shield-lock"></i> Staff Only</span>
                        </div>
                        <div class="mb-4" id="interactive-dental-chart">
                            <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
                                <div>
                                    <p class="font-semibold text-orange-700 text-lg">Dental Chart Visualization</p>
                                    <p class="text-sm text-orange-500">Select a condition then click teeth to mark them.</p>
                                </div>
                                <button type="button" id="resetDentalChart" class="no-print red-orange-gradient-button text-white px-4 py-2 rounded-lg font-semibold shadow hover:shadow-lg transition">
                                    <i class="bi bi-arrow-clockwise"></i> Reset Chart
                                </button>
                            </div>
                            <div class="flex flex-wrap gap-3 mb-3 p-3 bg-orange-50 rounded-lg border border-orange-200">
                                <div class="flex items-center gap-2 legend-item active" data-tool="caries">
                                    <div class="w-3 h-3 bg-red-500 rounded"></div>
                                    <span class="text-sm font-medium text-orange-700">Caries (Cavity)</span>
                                </div>
                                <div class="flex items-center gap-2 legend-item" data-tool="filling">
                                    <div class="w-3 h-3 bg-blue-500 rounded"></div>
                                    <span class="text-sm font-medium text-orange-700">Filling</span>
                                </div>
                                <div class="flex items-center gap-2 legend-item" data-tool="extraction">
                                    <div class="w-3 h-3 bg-yellow-500 rounded"></div>
                                    <span class="text-sm font-medium text-orange-700">Extraction Needed</span>
                                </div>
                                <div class="flex items-center gap-2 legend-item" data-tool="healthy">
                                    <div class="w-3 h-3 bg-green-500 rounded"></div>
                                    <span class="text-sm font-medium text-orange-700">Healthy</span>
                                </div>
                                <div class="flex items-center gap-2 legend-item" data-tool="crown">
                                    <div class="w-3 h-3 bg-purple-500 rounded"></div>
                                    <span class="text-sm font-medium text-orange-700">Crown/Bridge</span>
                                </div>
                            </div>
                            <div class="bg-white border-2 border-orange-200 rounded-xl p-4 mb-3">
                                <div class="mb-4">
                                    <h5 class="text-center font-semibold mb-2 text-orange-600 bg-orange-100 py-1 rounded">Maxillary (Upper Jaw)</h5>
                                    <div class="grid grid-cols-16 gap-1 justify-center mb-2" id="maxillary-teeth">
                                        <?php foreach ($upper_teeth as $tooth): ?>
                                            <div class="tooth-container flex flex-col items-center" data-tooth="<?php echo $tooth; ?>">
                                                <div class="tooth-number text-xs font-semibold text-orange-600 mb-1"><?php echo $tooth; ?></div>
                                                <div class="tooth tooth-upper w-8 h-12 border-2 border-gray-300 rounded-lg cursor-pointer transition-all duration-200 hover:scale-110 hover:shadow-md flex items-center justify-center" data-tooth="<?php echo $tooth; ?>">
                                                    <span class="tooth-label text-xs font-medium"><?php echo $tooth_labels[$tooth]; ?></span>
                                                </div>
                                                <div class="tooth-condition text-xs mt-1 text-center min-h-[16px] font-semibold"></div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div>
                                    <h5 class="text-center font-semibold mb-2 text-orange-600 bg-orange-100 py-1 rounded">Mandibular (Lower Jaw)</h5>
                                    <div class="grid grid-cols-16 gap-1 justify-center mb-2" id="mandibular-teeth">
                                        <?php foreach ($lower_teeth as $tooth): ?>
                                            <div class="tooth-container flex flex-col items-center" data-tooth="<?php echo $tooth; ?>">
                                                <div class="tooth-number text-xs font-semibold text-orange-600 mb-1"><?php echo $tooth; ?></div>
                                                <div class="tooth tooth-lower w-8 h-12 border-2 border-gray-300 rounded-lg cursor-pointer transition-all duration-200 hover:scale-110 hover:shadow-md flex items-center justify-center" data-tooth="<?php echo $tooth; ?>">
                                                    <span class="tooth-label text-xs font-medium"><?php echo $tooth_labels[$tooth]; ?></span>
                                                </div>
                                                <div class="tooth-condition text-xs mt-1 text-center min-h-[16px] font-semibold"></div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="bg-blue-50 p-3 rounded-lg border border-blue-200">
                                <h5 class="font-semibold mb-1 text-blue-700">Dental Conditions Summary</h5>
                                <div id="selected-teeth-summary" class="text-sm text-blue-600">No dental conditions recorded. Click on teeth to mark conditions.</div>
                            </div>
                        </div>
                        </div>

                        <!-- Treatment Record -->
                        <div class="staff-only-section">
                        <div class="flex items-center justify-between mt-8 mb-4">
                            <h5 class="text-lg font-bold text-orange-700 mb-0">Treatment Record</h5>
                            <span class="staff-only-pill"><i class="bi bi-shield-lock"></i> Staff Only</span>
                        </div>
                        <div class="overflow-x-auto mb-6">
                            <table class="min-w-full border border-gray-300 text-center">
                                <thead class="bg-gray-100">
                                    <tr>
                                        <th>Date</th>
                                        <th>Tooth No.</th>
                                        <th>Nature of Operation</th>
                                        <th>Dentist</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><input type="date" class="w-full border rounded px-2 py-1" name="treatment_date[]"></td>
                                        <td><input type="text" class="w-full border rounded px-2 py-1" name="treatment_tooth[]"></td>
                                        <td><input type="text" class="w-full border rounded px-2 py-1" name="treatment_nature[]"></td>
                                        <td><input type="text" class="w-full border rounded px-2 py-1" name="treatment_dentist[]"></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        </div>

                        <!-- Index Tables -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6 staff-only-section">
                            <div>
                                <h6 class="font-bold mb-2">Temporary Teeth d.f.t.</h6>
                                <table class="min-w-full border border-gray-300 text-center mb-2">
                                    <thead class="bg-gray-100">
                                        <tr><th>Index d.f.t.</th><th>1st</th><th>2nd</th><th>3rd</th><th>4th</th><th>5th</th><th>6th</th></tr>
                                    </thead>
                                    <tbody>
                                        <tr><td>No. T/Decayed</td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
                                        <tr><td>No. T/Filled</td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
                                        <tr><td>Total d.f.t.</td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
                                    </tbody>
                                </table>
                            </div>
                            <div>
                                <h6 class="font-bold mb-2">Permanent Teeth D.M.F.T.</h6>
                                <table class="min-w-full border border-gray-300 text-center mb-2">
                                    <thead class="bg-gray-100">
                                        <tr><th>Index D.M.F.T.</th><th>1st</th><th>2nd</th><th>3rd</th><th>4th</th></tr>
                                    </thead>
                                    <tbody>
                                        <tr><td>D</td><td></td><td></td><td></td><td></td></tr>
                                        <tr><td>M</td><td></td><td></td><td></td><td></td></tr>
                                        <tr><td>F</td><td></td><td></td><td></td><td></td></tr>
                                        <tr><td>Total DMF</td><td></td><td></td><td></td><td></td></tr>
                                        <tr><td>Total Sound Tooth</td><td></td><td></td><td></td><td></td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Legend -->
                        <div class="staff-only-section">
                        <div class="flex items-center justify-between mt-8 mb-4">
                            <h5 class="text-lg font-bold text-orange-700 mb-0">Legend</h5>
                            <span class="staff-only-pill"><i class="bi bi-shield-lock"></i> Staff Only</span>
                        </div>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6 text-sm">
                            <div>X – Carious tooth indicated for extraction</div>
                            <div>C – Carious tooth indicated for filling</div>
                            <div>RF – Root Fragment</div>
                            <div>M – Missing</div>
                            <div>Im – Impacted</div>
                            <div>Un – Unerupted</div>
                            <div>√ – Present Tooth</div>
                            <div>Cm – Congenitally Missing</div>
                            <div>Sp – Supernumerary</div>
                            <div>JC – Jacket Crown</div>
                            <div>Am – Amalgam</div>
                            <div>Comp – Composite</div>
                            <div>TF – Temporary Filling</div>
                            <div>S – Sealant</div>
                            <div>In – Inlay</div>
                            <div>AB – Abutment</div>
                            <div>P – Pontic</div>
                            <div>RPD – Removable Partial Denture</div>
                            <div>CD – Complete Denture</div>
                            <div>FB – Fixed Bridge</div>
                        </div>
                        </div>

                        <!-- Periodontal Screening, Occlusion, Appliances, TMD -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6 staff-only-section">
                            <div>
                                <h6 class="font-bold mb-2">Periodontal Screening</h6>
                                <div class="flex flex-col gap-2">
                                    <label><input type="checkbox" name="gingivitis" value="1"> Gingivitis</label>
                                    <label><input type="checkbox" name="early_periodontitis" value="1"> Early Periodontitis</label>
                                    <label><input type="checkbox" name="moderate_periodontitis" value="1"> Moderate Periodontitis</label>
                                    <label><input type="checkbox" name="advanced_periodontitis" value="1"> Advanced Periodontitis</label>
                                </div>
                            </div>
                            <div>
                                <h6 class="font-bold mb-2">Occlusion</h6>
                                <div class="flex flex-col gap-2">
                                    <label><input type="checkbox" name="class_molar" value="1"> Occlusion Class Molar</label>
                                    <label><input type="checkbox" name="overjet" value="1"> Overjet</label>
                                    <label><input type="checkbox" name="overbite" value="1"> Overbite</label>
                                    <label><input type="checkbox" name="crossbite" value="1"> Crossbite</label>
                                    <label><input type="checkbox" name="midline_deviation" value="1"> Midline Deviation</label>
                                </div>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6 staff-only-section">
                            <div>
                                <h6 class="font-bold mb-2">Appliances</h6>
                                <div class="flex flex-col gap-2">
                                    <label><input type="checkbox" name="orthodontic" value="1"> Orthodontic Appliance</label>
                                    <label><input type="checkbox" name="stayplate" value="1"> Stayplate / Retainer</label>
                                    <label><input type="checkbox" name="appliance_others" value="1"> Other Appliance</label>
                                </div>
                            </div>
                            <div>
                                <h6 class="font-bold mb-2">TMD</h6>
                                <div class="flex flex-col gap-2">
                                    <label><input type="checkbox" name="clenching" value="1"> Clenching</label>
                                    <label><input type="checkbox" name="clicking" value="1"> Clicking</label>
                                    <label><input type="checkbox" name="trismus" value="1"> Trismus</label>
                                    <label><input type="checkbox" name="muscle_spasm" value="1"> Muscle Spasm</label>
                                </div>
                            </div>
                        </div>

                        <!-- Dental Chart Data (Hidden) -->
                        <input type="hidden" id="dental_chart_data" name="dental_chart_data" value="">

                        <!-- Remarks -->
                        <div class="mb-6 staff-only-section">
                            <label for="remarks" class="block font-medium mb-1">Remarks</label>
                            <textarea class="w-full rounded border border-gray-300 px-3 py-2" id="remarks" name="remarks" rows="2"></textarea>
                        </div>

                        <!-- Dentist Signature -->
                        <div class="mb-6 staff-only-section">
                            <div class="flex items-center justify-between mb-2">
                                <label for="dentist_signature" class="block font-medium m-0">Dentist Signature</label>
                                <span class="staff-only-pill"><i class="bi bi-shield-lock"></i> Staff Only</span>
                            </div>
                            <input type="text" class="w-full rounded border border-gray-300 px-3 py-2" id="dentist_signature" name="dentist_signature">
                            <label for="license_no" class="block font-medium mb-1 mt-2">License No.</label>
                            <input type="text" class="w-full rounded border border-gray-300 px-3 py-2" id="license_no" name="license_no">
                        </div>
                       
                        <!-- Certification Section -->
                        <h5 class="mt-8 mb-4 text-lg font-bold text-orange-700">Certification</h5>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                            <div>
                                <p class="mb-2">I hereby certify that the above information given are true and correct as to the best of my knowledge.</p>
                                <div class="mb-3">
                                    <label for="student_signature" class="block font-medium mb-1">Signature over Printed Name of Student</label>
                                    <input type="text" class="w-full rounded border border-gray-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-orange-500" id="student_signature" name="student_signature" required>
                                </div>
                                <div class="mb-3">
                                    <label for="student_date" class="block font-medium mb-1">Date</label>
                                    <input type="date" class="w-full rounded border border-gray-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-orange-500" id="student_date" name="student_date" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                            </div>
                            <div class="staff-only-section">
                                <div class="flex items-center justify-between mb-2">
                                    <p class="mb-0 font-medium">Examined by:</p>
                                    <span class="staff-only-pill"><i class="bi bi-shield-lock"></i> Staff Only</span>
                                </div>
                                <div class="mb-3">
                                    <label for="dentist_name" class="block font-medium mb-1">Signature over Printed Name of Dentist</label>
                                    <input type="text" class="w-full rounded border border-gray-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-orange-500" id="dentist_name" name="dentist_name" <?php echo $is_staff_user ? 'required' : ''; ?>>
                                </div>
                                <div class="mb-3">
                                    <label for="license_no_cert" class="block font-medium mb-1">License No.</label>
                                    <input type="text" class="w-full rounded border border-gray-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-orange-500" id="license_no_cert" name="license_no" <?php echo $is_staff_user ? 'required' : ''; ?>>
                                </div>
                                <div class="mb-3">
                                    <label for="dentist_date" class="block font-medium mb-1">Date</label>
                                    <input type="date" class="w-full rounded border border-gray-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-orange-500" id="dentist_date" name="dentist_date" value="<?php echo date('Y-m-d'); ?>" <?php echo $is_staff_user ? 'required' : ''; ?>>
                                </div>
                            </div>
                        </div>
                        <!-- Data Privacy Act Section -->
                        <div class="border-2 border-orange-400 rounded-lg bg-white shadow p-4 mb-6">
                            <p class="text-xs text-gray-600 mb-2">Pursuant to Republic Act No. 10173, also known as the Data Privacy Act of 2012, the Batangas State University, the National Engineering University, recognizes its commitment to protect and respect the privacy of its customers and/or stakeholders and ensure that all information collected from them are all processed in accordance with the principles of transparency, legitimate purpose and proportionality mandated under the Data Privacy Act of 2012.</p>
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
        </div>
        
        <!
    

    
    <script src="../../assets/js/jquery.min.js"></script>
    <script src="../../assets/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/qrcode.min.js"></script>
    <script>
        $(document).ready(function() {
            let currentTool = 'caries';
            let dentalChartData = {};
            const conditionOptions = {
                caries: {
                    background: '#ef4444',
                    border: '#ef4444',
                    textColor: '#ffffff',
                    label: 'Caries',
                    description: 'Caries (Cavity)'
                },
                filling: {
                    background: '#3b82f6',
                    border: '#3b82f6',
                    textColor: '#ffffff',
                    label: 'Filling',
                    description: 'Filling'
                },
                extraction: {
                    background: '#facc15',
                    border: '#facc15',
                    textColor: '#854d0e',
                    label: 'Extraction',
                    description: 'Extraction Needed'
                },
                healthy: {
                    background: '#22c55e',
                    border: '#22c55e',
                    textColor: '#ffffff',
                    label: 'Healthy',
                    description: 'Healthy'
                },
                crown: {
                    background: '#a855f7',
                    border: '#a855f7',
                    textColor: '#ffffff',
                    label: 'Crown',
                    description: 'Crown / Bridge'
                }
            };

            $('.tooth').click(function() {
                const $tooth = $(this);
                const toothId = $tooth.data('tooth');
                const storedCondition = dentalChartData[toothId] ? dentalChartData[toothId].condition : null;

                if ($tooth.hasClass('selected') && storedCondition === currentTool) {
                    resetTooth($tooth);
                    delete dentalChartData[toothId];
                } else {
                    const option = conditionOptions[currentTool];
                    if (!option) {
                        return;
                    }

                    $tooth.addClass('selected').css({
                        'background-color': option.background,
                        'border-color': option.border,
                        'color': option.textColor
                    });
                    $tooth.closest('.tooth-container').find('.tooth-condition').text(option.label);

                    dentalChartData[toothId] = {
                        condition: currentTool,
                        label: option.label,
                        text: option.description
                    };
                }

                $('#dental_chart_data').val(JSON.stringify(dentalChartData));
                renderDentalSummary();
            });

            $('.legend-item').click(function() {
                $('.legend-item').removeClass('active');
                $(this).addClass('active');
                currentTool = $(this).data('tool');
            });

            $('#resetDentalChart').click(function() {
                dentalChartData = {};
                $('.tooth').each(function() {
                    $(this).removeClass('selected').css({
                        'background-color': '',
                        'border-color': '',
                        'color': ''
                    });
                    $(this).closest('.tooth-container').find('.tooth-condition').text('');
                });
                $('#dental_chart_data').val('');
                renderDentalSummary();
            });

            function renderDentalSummary() {
                const summaryContainer = $('#selected-teeth-summary');
                const entries = Object.keys(dentalChartData);
                if (entries.length === 0) {
                    summaryContainer.text('No dental conditions recorded. Click on teeth to mark conditions.');
                    return;
                }

                const grouped = {};
                entries.forEach((toothId) => {
                    const info = dentalChartData[toothId];
                    if (!grouped[info.condition]) {
                        grouped[info.condition] = [];
                    }
                    grouped[info.condition].push(toothId);
                });

                const conditionInfo = {
                    caries: { color: 'bg-red-500', text: 'Caries (Cavity)' },
                    filling: { color: 'bg-blue-500', text: 'Filling' },
                    extraction: { color: 'bg-yellow-500', text: 'Extraction Needed' },
                    healthy: { color: 'bg-green-500', text: 'Healthy' },
                    crown: { color: 'bg-purple-500', text: 'Crown/Bridge' }
                };

                let html = '<div class="grid grid-cols-1 md:grid-cols-2 gap-2">';
                Object.keys(grouped).forEach((condition) => {
                    const info = conditionInfo[condition] || { color: 'bg-gray-500', text: condition };
                    html += `
                        <div class="flex items-center gap-2 p-2 bg-white rounded border border-orange-200">
                            <div class="w-3 h-3 rounded-full ${info.color}"></div>
                            <span class="font-medium text-orange-700">${info.text}:</span>
                            <span class="text-orange-600">Teeth ${grouped[condition].join(', ')}</span>
                        </div>
                    `;
                });
                html += '</div>';
                summaryContainer.html(html);
            }

            renderDentalSummary();

            // Generate QR code (same logic as history_form.php)
            $('#generateQR').click(function() {
                var studentId = $('#student_id').val();
                var name = $('#first_name').val() + ' ' + $('#last_name').val();
                var formType = 'Dental Examination Form';
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
    window.location.href = <?php echo json_encode($dashboard_url); ?>;
</script>
<?php endif; ?>
</body>
</html>