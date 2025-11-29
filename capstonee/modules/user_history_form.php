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
              $activity_type = 'medical_history';
        $description = 'Medical History Form Submitted';
        
        $activity_stmt = $conn->prepare("INSERT INTO user_activities (user_id, activity_type, activity_description) VALUES (?, ?, ?)");
        $activity_stmt->bind_param("iss", $_SESSION['user_id'], $activity_type, $description);
        $activity_stmt->execute();
        $activity_stmt->close();

               
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
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        .nurse-only {
            color: #1e40af;
            font-weight: 500;
            background-color: #f3f4f6 !important;
            cursor: not-allowed;
        }
        .nurse-only-checkbox {
            cursor: not-allowed;
            pointer-events: none;
            opacity: 0.6;
        }
        .staff-only-section {
            display: none !important;
        }
        .staff-only-note {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: #1d4ed8;
            border-radius: 0.5rem;
            padding: 0.75rem 1rem;
            font-size: 0.9rem;
            margin-bottom: 1.5rem;
        }
    </style>
</head>
<body>
    
    <div class="bg-gray-100 min-h-screen py-8">
        <div class="max-w-5xl mx-auto px-4 mb-6">
            <a href="../user_dashboard.php" class="inline-flex items-center gap-2 bg-blue-600 text-white font-semibold px-4 py-2 rounded-lg shadow hover:bg-blue-700 transition">
                <i class="bi bi-arrow-left"></i> Back to Dashboard
            </a>
        </div>
        <div class="max-w-5xl mx-auto px-4">
            <div class="bg-white shadow rounded-lg">
                <div class="bg-gradient-to-r from-blue-700 via-blue-600 to-blue-500 text-white px-8 py-6 rounded-t-lg">
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
                                    <input type="text" class="w-full rounded border border-gray-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" id="student_id" name="student_id" value="<?php echo htmlspecialchars($patient_data['student_id'] ?? ''); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="control_no" class="block font-medium mb-1">Control No.</label>
                                    <input type="text" class="w-full rounded border border-gray-300 px-3 py-2 bg-gray-100" id="control_no" name="control_no" value="UPA-<?php echo date('Ymd') . rand(100, 999); ?>" readonly>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-4">
                                    <label for="first_name" class="block font-medium mb-1">First Name</label>
                                    <input type="text" class="w-full rounded border border-gray-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" id="first_name" name="first_name" value="<?php echo htmlspecialchars($patient_data['first_name'] ?? ''); ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label for="middle_name" class="block font-medium mb-1">Middle Name</label>
                                    <input type="text" class="w-full rounded border border-gray-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" id="middle_name" name="middle_name" value="<?php echo htmlspecialchars($patient_data['middle_name'] ?? ''); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label for="last_name" class="block font-medium mb-1">Last Name</label>
                                    <input type="text" class="w-full rounded border border-gray-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" id="last_name" name="last_name" value="<?php echo htmlspecialchars($patient_data['last_name'] ?? ''); ?>" required>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-4">
                                    <label for="date_of_examination" class="block font-medium mb-1">DATE OF EXAMINATION</label>
                                    <input type="date" class="w-full rounded border border-gray-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" id="date_of_examination" name="date_of_examination" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label for="sex" class="block font-medium mb-1">SEX</label>
                                    <select class="w-full rounded border border-gray-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" id="sex" name="sex" required>
                                        <option value="">Select</option>
                                        <option value="Male" <?php echo (isset($patient_data['sex']) && $patient_data['sex'] == 'Male') ? 'selected' : ''; ?>>Male</option>
                                        <option value="Female" <?php echo (isset($patient_data['sex']) && $patient_data['sex'] == 'Female') ? 'selected' : ''; ?>>Female</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label for="age" class="block font-medium mb-1">AGE</label>
                                    <input type="number" class="w-full rounded border border-gray-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" id="age" name="age" value="<?php echo htmlspecialchars($patient_data['age'] ?? ''); ?>" required>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="program" class="block font-medium mb-1">GRADE/LEVEL/PROGRAM</label>
                                    <input type="text" class="w-full rounded border border-gray-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" id="program" name="program" value="<?php echo htmlspecialchars($patient_data['program'] ?? ''); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="date_of_birth" class="block font-medium mb-1">DATE OF BIRTH</label>
                                    <input type="date" class="w-full rounded border border-gray-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" id="date_of_birth" name="date_of_birth" value="<?php echo htmlspecialchars($patient_data['date_of_birth'] ?? ''); ?>" required>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-12">
                                    <label for="sports_event" class="block font-medium mb-1">SPORTS EVENT</label>
                                    <input type="text" class="w-full rounded border border-gray-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" id="sports_event" name="sports_event" required>
                                </div>
                            </div>
                            
                            
                            
                            <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
                                <div class="flex flex-col md:flex-row gap-4 justify-end mt-8">
                                    <button type="submit" class="bg-blue-600 text-white font-semibold px-6 py-2 rounded-lg shadow hover:bg-blue-700 transition">Submit Form</button>
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
            // Initialize female-only section + Question 11 field state
            function updateFemaleSection() {
                const isFemale = $('#sex').val() === 'Female';
                const q11Input = $('input[name="menstrual_age"]');

                if (isFemale) {
                    // Show female-only block and enable Q11
                    $('.female-only-section').show();
                    q11Input.prop('readonly', false)
                            .removeClass('nurse-only')
                            .removeAttr('disabled');
                } else {
                    // Hide block and reset/disable Q11 for non‑female
                    $('.female-only-section').hide();
                    q11Input.val('')
                            .prop('readonly', true)
                            .attr('disabled', 'disabled');
                }
            }

            // Run on load (handles pre-filled sex)
            updateFemaleSection();

            // Update when sex changes
            $('#sex').on('change', updateFemaleSection);
            
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
    window.location.href = "../user_dashboard.php";
</script>
<?php endif; ?>

</body>
</html>