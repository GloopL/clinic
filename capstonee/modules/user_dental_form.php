<?php
session_start();
include __DIR__ . '/../config/database.php';

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
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    header("Location: $protocol://$host/index.php");
    exit();
}

$success_message = '';
$error_message = '';
$user_role = $_SESSION['role'] ?? 'student';
$is_staff_user = in_array($user_role, ['dentist', 'doctor', 'nurse', 'staff', 'admin']);

// Get patient data if user is logged in
$patient_data = null;
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
        }
        $stmt->close();
    }
}

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
    $civil_status = trim($_POST['civil_status'] ?? '');
    $address = trim($_POST['address'] ?? '');

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
            (student_id, first_name, middle_name, last_name, date_of_birth, sex, program, year_level, civil_status, address) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssssssss", $student_id, $first_name, $middle_name, $last_name, $date_of_birth, $sex, $program, $year_level, $civil_status, $address);

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
                (record_id, dentition_status, treatment_needs, periodontal_screening, occlusion, appliances, tmd_status, remarks, dentist_name, license_no, verification_status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
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

            $stmt->bind_param(
                "isssssssss",
                $record_id,
                $dentition_status,
                $treatment_needs,
                $periodontal_screening,
                $occlusion,
                $appliances,
                $tmd_status,
                $remarks,
                $dentist_name,
                $license_no
            );

            if ($stmt->execute()) {
                // ✅ Step 5: Redirect to verification page
                $success_message = "Form successfully submitted! Please wait for admin verification.";
                 $activity_type = 'dental_exam';
        $description = 'Dental Examination Form Submitted';
        
        $activity_stmt = $conn->prepare("INSERT INTO user_activities (user_id, activity_type, activity_description) VALUES (?, ?, ?)");
        $activity_stmt->bind_param("iss", $_SESSION['user_id'], $activity_type, $description);
        $activity_stmt->execute();
        $activity_stmt->close();
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
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        .tooth-chart {
            display: grid;
            grid-template-columns: repeat(16, 1fr);
            gap: 2px;
            margin: 20px 0;
        }
        .tooth {
            border: 1px solid #ccc;
            padding: 5px;
            text-align: center;
            font-size: 12px;
            cursor: pointer;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .tooth.selected {
            background-color: #ffcccc;
        }
        .tooth-legend {
            margin-top: 10px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .legend-item { display: flex; align-items: center; margin-right: 15px; }
        .legend-color { width: 20px; height: 20px; margin-right: 5px; }
        .legend-text { font-size: 12px; }
        
        /* Disabled dentist-only sections */
        .dentist-only {
            background-color: #f8f9fa !important;
            border: 1px solid #e9ecef !important;
            cursor: not-allowed !important;
        }
        
        .dentist-only input,
        .dentist-only textarea,
        .dentist-only select {
            background-color: #f8f9fa !important;
            cursor: not-allowed !important;
            color: #6c757d !important;
        }
        
        .dentist-only input[type="checkbox"],
        .dentist-only input[type="radio"] {
            cursor: not-allowed !important;
            opacity: 0.6;
        }
        
        .disabled-overlay {
            position: relative;
        }
        
        .disabled-overlay::after {
            content: "For Dentist Use Only";
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background-color: rgba(0, 0, 0, 0.8);
            color: white;
            padding: 10px 20px;
            border-radius: 5px;
            font-weight: bold;
            z-index: 10;
            display: none;
        }
        
        .disabled-overlay:hover::after { display: block; }
        .disabled-label { color: #6c757d !important; font-style: italic; }
        <?php if (!$is_staff_user): ?>
        .staff-only-section { display: none !important; }
        <?php endif; ?>
        .staff-only-info {
            background: #e0f2fe;
            border: 1px solid #7dd3fc;
            color: #0369a1;
            border-radius: 0.5rem;
            padding: 1rem;
            font-size: 0.9rem;
            margin-bottom: 2rem;
        }
        .signature-pad-wrapper {
            border: 1px dashed #93c5fd;
            border-radius: 0.75rem;
            background: #f8fafc;
            min-height: 180px;
            position: relative;
            overflow: hidden;
            cursor: crosshair;
        }
        .signature-pad-canvas {
            width: 100%;
            height: 180px;
            display: block;
            cursor: crosshair;
            touch-action: none;
            user-select: none;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
        }
        .signature-actions button {
            min-width: 130px;
        }
        .no-print {
            display: block;
        }
        @media print {
            .no-print {
                display: none !important;
            }
            #studentSignatureCanvas,
            .signature-actions {
                display: none !important;
            }
        }
        .auto-filled {
            background-color: #f0fff4 !important;
            border-color: #68d391 !important;
        }
        .manual-input {
            background-color: #fff7ed !important;
            border-color: #fed7aa !important;
        }
    </style>
</head>
<body>
   
    
    <div class="bg-gray-100 min-h-screen py-8">
        <div class="max-w-5xl mx-auto px-4 mb-6 no-print">
            <a href="../user_dashboard.php" class="inline-flex items-center gap-2 bg-blue-600 text-white font-semibold px-4 py-2 rounded-lg shadow hover:bg-blue-700 transition">
                <i class="bi bi-arrow-left"></i> Back to Dashboard
            </a>
        </div>
        <div class="max-w-5xl mx-auto px-4">
            <div class="bg-white shadow rounded-lg">
                <div class="bg-gradient-to-r from-blue-700 via-blue-600 to-blue-500 text-white px-8 py-6 rounded-t-lg">
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
                    <form id="userDentalForm" method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                        <!-- Hidden fields for auto-filled data -->
                        <input type="hidden" id="first_name" name="first_name" value="<?php echo isset($patient_data['first_name_cap']) ? htmlspecialchars($patient_data['first_name_cap']) : ''; ?>">
                        <input type="hidden" id="middle_name" name="middle_name" value="<?php echo isset($patient_data['middle_name']) ? htmlspecialchars(ucwords(strtolower($patient_data['middle_name']))) : ''; ?>">
                        <input type="hidden" id="last_name" name="last_name" value="<?php echo isset($patient_data['last_name_cap']) ? htmlspecialchars($patient_data['last_name_cap']) : ''; ?>">
                        <input type="hidden" id="sex" name="sex" value="<?php echo isset($patient_data['sex']) ? htmlspecialchars($patient_data['sex']) : ''; ?>">
                        
                        <!-- Patient Information Section -->
                        <h5 class="text-lg font-bold text-blue-700 mb-4">Patient Information</h5>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                            <div class="bg-green-50 p-4 rounded-lg border-2 border-green-200">
                                <label class="block font-medium mb-2 text-green-700">
                                    <i class="bi bi-person-circle mr-2"></i>Name
                                </label>
                                <input type="text" 
                                       class="w-full rounded border-2 border-green-300 px-3 py-2 bg-green-50 font-medium text-green-800 capitalize" 
                                       id="display_name" 
                                       value="<?php echo isset($patient_data['full_name_formatted']) ? htmlspecialchars($patient_data['full_name_formatted']) : ''; ?>" 
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
                                    <i class="bi bi-mortarboard mr-2"></i>Program
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
                                <label class="block font-medium mb-2 text-green-700">
                                    <i class="bi bi-person-badge mr-2"></i>SR Code / Student ID
                                </label>
                                <input type="text" 
                                       class="w-full rounded border-2 border-green-300 px-3 py-2 bg-green-50 font-semibold text-green-800" 
                                       id="student_id" 
                                       name="student_id" 
                                       value="<?php echo isset($patient_data['student_id']) ? htmlspecialchars($patient_data['student_id']) : ''; ?>" 
                                       <?php echo isset($patient_data['student_id']) ? 'readonly' : 'required'; ?>>
                                <p class="text-xs text-green-600 mt-2 flex items-center">
                                    <?php if (isset($patient_data['student_id'])): ?>
                                        <i class="bi bi-check-circle-fill mr-1"></i> Auto-filled from your registration
                                    <?php else: ?>
                                        <i class="bi bi-pencil-square mr-1"></i> Please enter your SR Code
                                    <?php endif; ?>
                                </p>
                            </div>
                            
                            <div class="bg-green-50 p-4 rounded-lg border-2 border-green-200">
                                <label class="block font-medium mb-2 text-green-700">
                                    <i class="bi bi-gender-ambiguous mr-2"></i>Sex
                                </label>
                                <input type="text" 
                                       class="w-full rounded border-2 border-green-300 px-3 py-2 bg-green-50 font-medium text-green-800" 
                                       id="sex_display" 
                                       value="<?php echo isset($patient_data['sex']) ? htmlspecialchars($patient_data['sex']) : ''; ?>" 
                                       readonly>
                                <p class="text-xs text-green-600 mt-2 flex items-center">
                                    <?php if (isset($patient_data['sex'])): ?>
                                        <i class="bi bi-check-circle-fill mr-1"></i> Auto-filled from your registration
                                    <?php else: ?>
                                        <i class="bi bi-pencil-square mr-1"></i> Please complete your registration
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                            <div class="bg-green-50 p-4 rounded-lg border-2 border-green-200">
                                <label class="block font-medium mb-2 text-green-700">
                                    <i class="bi bi-calendar2-heart mr-2"></i>Age
                                </label>
                                <input type="number" 
                                       class="w-full rounded border-2 border-green-300 px-3 py-2 bg-green-50 font-medium text-green-800" 
                                       id="age_display" 
                                       value="<?php echo isset($patient_data['age']) ? htmlspecialchars($patient_data['age']) : ''; ?>" 
                                       readonly>
                                <p class="text-xs text-green-600 mt-2 flex items-center">
                                    <?php if (isset($patient_data['age'])): ?>
                                        <i class="bi bi-check-circle-fill mr-1"></i> Auto-calculated from your date of birth
                                    <?php else: ?>
                                        <i class="bi bi-pencil-square mr-1"></i> Please complete your registration
                                    <?php endif; ?>
                                </p>
                            </div>
                            
                            <div class="bg-orange-50 p-4 rounded-lg border-2 border-orange-200">
                                <label for="civil_status" class="block font-medium mb-2 text-orange-700">
                                    <i class="bi bi-person-heart mr-2"></i>Civil Status
                                </label>
                                <select class="w-full rounded border-2 border-orange-300 px-3 py-2 bg-orange-50 focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-orange-500" 
                                        id="civil_status" 
                                        name="civil_status" 
                                        required>
                                    <option value="">Select Civil Status</option>
                                    <option value="Single" <?php echo (isset($patient_data['civil_status']) && $patient_data['civil_status'] == 'Single') ? 'selected' : ''; ?>>Single</option>
                                    <option value="Married" <?php echo (isset($patient_data['civil_status']) && $patient_data['civil_status'] == 'Married') ? 'selected' : ''; ?>>Married</option>
                                    <option value="Widowed" <?php echo (isset($patient_data['civil_status']) && $patient_data['civil_status'] == 'Widowed') ? 'selected' : ''; ?>>Widowed</option>
                                    <option value="Separated" <?php echo (isset($patient_data['civil_status']) && $patient_data['civil_status'] == 'Separated') ? 'selected' : ''; ?>>Separated</option>
                                    <option value="Divorced" <?php echo (isset($patient_data['civil_status']) && $patient_data['civil_status'] == 'Divorced') ? 'selected' : ''; ?>>Divorced</option>
                                </select>
                                <p class="text-xs text-orange-600 mt-2 flex items-center">
                                    <i class="bi bi-pencil-square mr-1"></i> Please select your civil status
                                </p>
                            </div>
                        </div>

                        <div class="mb-6">
                            <div class="bg-orange-50 p-4 rounded-lg border-2 border-orange-200">
                                <label for="address" class="block font-medium mb-2 text-orange-700">
                                    <i class="bi bi-geo-alt mr-2"></i>Address
                                </label>
                                <textarea class="w-full rounded border-2 border-orange-300 px-3 py-2 bg-orange-50 focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-orange-500" 
                                          id="address" 
                                          name="address" 
                                          rows="3" 
                                          placeholder="Enter your complete address" 
                                          required><?php echo isset($patient_data['address']) ? htmlspecialchars($patient_data['address']) : ''; ?></textarea>
                                <p class="text-xs text-orange-600 mt-2 flex items-center">
                                    <i class="bi bi-pencil-square mr-1"></i> Please enter your complete address
                                </p>
                            </div>
                        </div>

                        <!-- Information Legend -->
                        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                            <h5 class="text-lg font-bold text-blue-700 mb-2 flex items-center">
                                <i class="bi bi-info-circle-fill mr-2"></i> Form Information
                            </h5>
                            <p class="text-blue-600 mb-1">
                                <i class="bi bi-check-circle-fill text-green-500 mr-1"></i> 
                                <span class="font-semibold">Green fields</span> are auto-filled from your registration.
                            </p>
                            <p class="text-blue-600">
                                <i class="bi bi-pencil-square text-orange-500 mr-1"></i> 
                                <span class="font-semibold">Orange fields</span> require manual input.
                            </p>
                        </div>

                        <?php if ($is_staff_user): ?>
                        <!-- Dentition Status and Treatment Needs -->
                        <div>
                        <h5 class="mt-8 mb-4 text-lg font-bold text-blue-700">Dentition Status and Treatment Needs</h5>
                        <div class="overflow-x-auto mb-6">
                            <table class="min-w-full border border-gray-300 text-center">
                                <thead class="bg-gray-100">
                                    <tr>
                                        <th colspan="8">Temporary Teeth Right</th>
                                        <th colspan="8">Temporary Teeth Left</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>55</td><td>54</td><td>53</td><td>52</td><td>51</td><td>61</td><td>62</td><td>63</td>
                                        <td>64</td><td>65</td><td></td><td></td><td></td><td></td><td></td><td></td>
                                    </tr>
                                </tbody>
                            </table>
                            <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block font-medium mb-1">Permanent Teeth</label>
                                    <div class="grid grid-cols-8 gap-1">
                                        <span>18</span><span>17</span><span>16</span><span>15</span><span>14</span><span>13</span><span>12</span><span>11</span>
                                        <span>21</span><span>22</span><span>23</span><span>24</span><span>25</span><span>26</span><span>27</span><span>28</span>
                                        <span>48</span><span>47</span><span>46</span><span>45</span><span>44</span><span>43</span><span>42</span><span>41</span>
                                        <span>31</span><span>32</span><span>33</span><span>34</span><span>35</span><span>36</span><span>37</span><span>38</span>
                                    </div>
                                </div>
                                <div>
                                    <label class="block font-medium mb-1 disabled-label">Status (For Dentist Use)</label>
                                    <input type="text" class="w-full rounded border border-gray-300 px-3 py-2 dentist-only" name="status_grid" readonly>
                                </div>
                            </div>
                        </div>
                        </div>

                        <!-- Treatment Record -->
                        <div>
                        <h5 class="mt-8 mb-4 text-lg font-bold text-blue-700">Treatment Record</h5>
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
                                        <td><input type="date" class="w-full border rounded px-2 py-1 dentist-only" name="treatment_date[]" readonly></td>
                                        <td><input type="text" class="w-full border rounded px-2 py-1 dentist-only" name="treatment_tooth[]" readonly></td>
                                        <td><input type="text" class="w-full border rounded px-2 py-1 dentist-only" name="treatment_nature[]" readonly></td>
                                        <td><input type="text" class="w-full border rounded px-2 py-1 dentist-only" name="treatment_dentist[]" readonly></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        </div>

                        <!-- Index Tables -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                            <div>
                                <h6 class="font-bold mb-2 disabled-label">Temporary Teeth d.f.t. (For Dentist Use)</h6>
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
                                <h6 class="font-bold mb-2 disabled-label">Permanent Teeth D.M.F.T. (For Dentist Use)</h6>
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
                        <div>
                        <h5 class="mt-8 mb-4 text-lg font-bold text-blue-700">Legend</h5>
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
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                            <div>
                                <h6 class="font-bold mb-2 disabled-label">Periodontal Screening (For Dentist Use)</h6>
                                <div class="flex flex-col gap-2">
                                    <label class="cursor-not-allowed"><input type="checkbox" name="gingivitis" class="dentist-only" disabled> Gingivitis</label>
                                    <label class="cursor-not-allowed"><input type="checkbox" name="early_periodontitis" class="dentist-only" disabled> Early Periodontitis</label>
                                    <label class="cursor-not-allowed"><input type="checkbox" name="moderate_periodontitis" class="dentist-only" disabled> Moderate Periodontitis</label>
                                    <label class="cursor-not-allowed"><input type="checkbox" name="advanced_periodontitis" class="dentist-only" disabled> Advanced Periodontitis</label>
                                </div>
                            </div>
                            <div>
                                <h6 class="font-bold mb-2 disabled-label">Occlusion (For Dentist Use)</h6>
                                <div class="flex flex-col gap-2">
                                    <label class="cursor-not-allowed"><input type="checkbox" name="class_molar" class="dentist-only" disabled> Occlusion Class Molar</label>
                                    <label class="cursor-not-allowed"><input type="checkbox" name="overjet" class="dentist-only" disabled> Overjet</label>
                                    <label class="cursor-not-allowed"><input type="checkbox" name="overbite" class="dentist-only" disabled> Overbite</label>
                                    <label class="cursor-not-allowed"><input type="checkbox" name="crossbite" class="dentist-only" disabled> Crossbite</label>
                                    <label class="cursor-not-allowed"><input type="checkbox" name="midline_deviation" class="dentist-only" disabled> Midline Deviation</label>
                                </div>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                            <div>
                                <h6 class="font-bold mb-2 disabled-label">Appliances (For Dentist Use)</h6>
                                <div class="flex flex-col gap-2">
                                    <label class="cursor-not-allowed"><input type="checkbox" name="orthodontic" class="dentist-only" disabled> Orthodontic Appliance</label>
                                    <label class="cursor-not-allowed"><input type="checkbox" name="stayplate" class="dentist-only" disabled> Stayplate / Retainer</label>
                                    <label class="cursor-not-allowed"><input type="checkbox" name="appliance_others" class="dentist-only" disabled> Other Appliance</label>
                                </div>
                            </div>
                            <div>
                                <h6 class="font-bold mb-2 disabled-label">TMD (For Dentist Use)</h6>
                                <div class="flex flex-col gap-2">
                                    <label class="cursor-not-allowed"><input type="checkbox" name="clenching" class="dentist-only" disabled> Clenching</label>
                                    <label class="cursor-not-allowed"><input type="checkbox" name="clicking" class="dentist-only" disabled> Clicking</label>
                                    <label class="cursor-not-allowed"><input type="checkbox" name="trismus" class="dentist-only" disabled> Trismus</label>
                                    <label class="cursor-not-allowed"><input type="checkbox" name="muscle_spasm" class="dentist-only" disabled> Muscle Spasm</label>
                                </div>
                            </div>
                        </div>

                        <!-- Remarks -->
                        <div class="mb-6">
                            <label for="remarks" class="block font-medium mb-1 disabled-label">Remarks (For Dentist Use)</label>
                            <textarea class="w-full rounded border border-gray-300 px-3 py-2 dentist-only" id="remarks" name="remarks" rows="2" readonly></textarea>
                        </div>
                        <?php endif; ?>

                        <?php if ($is_staff_user): ?>
                        <!-- Dentist Certification Section -->
                        <h5 class="mt-8 mb-4 text-lg font-bold text-blue-700">Dentist Certification (For Dentist Use Only)</h5>
                        <div class="mb-6">
                            <p class="mb-4 text-gray-700">Examined by:</p>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                <div>
                                    <label for="dentist_name" class="block font-medium mb-1">Signature over Printed Name of Dentist</label>
                                    <input type="text" class="w-full rounded border border-gray-300 px-3 py-2" id="dentist_name" name="dentist_name">
                                </div>
                                <div>
                                    <label for="license_no" class="block font-medium mb-1">License No.</label>
                                    <input type="text" class="w-full rounded border border-gray-300 px-3 py-2" id="license_no" name="license_no">
                                </div>
                                <div>
                                    <label for="dentist_date" class="block font-medium mb-1">Date</label>
                                    <input type="date" class="w-full rounded border border-gray-300 px-3 py-2" id="dentist_date" name="dentist_date" value="<?php echo date('Y-m-d'); ?>">
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Data Privacy Act Section -->
                        <div class="border-2 border-blue-400 rounded-lg bg-white shadow p-4 mb-6">
                            <p class="text-xs text-gray-600 mb-2">Pursuant to Republic Act No. 10173, also known as the Data Privacy Act of 2012, the Batangas State University, the National Engineering University, recognizes its commitment to protect and respect the privacy of its customers and/or stakeholders and ensure that all information collected from them are all processed in accordance with the principles of transparency, legitimate purpose and proportionality mandated under the Data Privacy Act of 2012.</p>
                        </div>
                            
                        <div class="flex flex-col md:flex-row gap-4 justify-end mt-8">
                            <button type="submit" class="bg-blue-600 text-white font-semibold px-6 py-2 rounded-lg shadow hover:bg-blue-700 transition">Submit Form</button>
                        </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- QR Code Modal -->
        <div class="modal fade" id="qrCodeModal" tabindex="-1" aria-labelledby="qrCodeModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="qrCodeModalLabel">QR Code</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body text-center">
                        <div id="qrcode"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="button" class="btn btn-primary" id="downloadQR">Download QR</button>
                    </div>
                </div>
            </div>
        </div>
    

    
    <script src="../../assets/js/jquery.min.js"></script>
    <script src="../../assets/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/qrcode.min.js"></script>
    <script>
        $(document).ready(function() {
            // Tooth chart interaction (unchanged)
            let currentTool = 'caries';
            let dentalChartData = {};
            
            // Digital Signature Canvas Setup - Removed since Student Certification is removed
            // All related signature code has been removed
            
            $('.tooth').click(function() {
                const toothId = $(this).data('tooth');
                $(this).toggleClass('selected');
                if ($(this).hasClass('selected')) {
                    switch(currentTool) {
                        case 'caries': $(this).css('background-color', '#dc3545'); dentalChartData[toothId] = 'caries'; break;
                        case 'missing': $(this).css('background-color', '#ffc107'); dentalChartData[toothId] = 'missing'; break;
                        case 'filled': $(this).css('background-color', '#28a745'); dentalChartData[toothId] = 'filled'; break;
                        case 'extraction': $(this).css('background-color', '#17a2b8'); dentalChartData[toothId] = 'extraction'; break;
                        case 'impacted': $(this).css('background-color', '#6c757d'); dentalChartData[toothId] = 'impacted'; break;
                    }
                } else {
                    $(this).css('background-color', '');
                    delete dentalChartData[toothId];
                }
                $('#dental_chart_data').val(JSON.stringify(dentalChartData));
            });
            $('.legend-item').click(function() {
                $('.legend-item').removeClass('active');
                $(this).addClass('active');
                const legendText = $(this).find('.legend-text').text().toLowerCase();
                switch(legendText) {
                    case 'dental caries': currentTool = 'caries'; break;
                    case 'missing': currentTool = 'missing'; break;
                    case 'filled': currentTool = 'filled'; break;
                    case 'for extraction': currentTool = 'extraction'; break;
                    case 'impacted': currentTool = 'impacted'; break;
                }
            });
            
            // Prevent interaction with dentist-only sections
            $('.dentist-only').on('click focus', function(e) {
                e.preventDefault();
                $(this).blur();
                return false;
            });
        });
    </script>
      <?php if (!empty($success_message)): ?>
        
<script>
    alert("Form successfully submitted! Please wait for admin verification.");
    window.location.href = "../user_dashboard.php";
    
    
</script>

<?php endif; ?>

</body>

</html>