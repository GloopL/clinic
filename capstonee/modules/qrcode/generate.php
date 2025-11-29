<?php
session_start();
include '../../config/database.php';

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    header("Location: $protocol://$host/index.php");
    exit();
}

$success_message = '';
$error_message = '';
$patients = [];

// Get all patients
$stmt = $conn->prepare("SELECT id, student_id, first_name, middle_name, last_name FROM patients ORDER BY last_name, first_name");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $patients[] = $row;
}

// Process form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $patient_id = $_POST['patient_id'] ?? null;
    $record_type = $_POST['record_type'] ?? null;
    
    if ($patient_id && $record_type) {
        // Get patient details
        $stmt = $conn->prepare("SELECT * FROM patients WHERE id = ?");
        $stmt->bind_param("i", $patient_id);
        $stmt->execute();
        $patient = $stmt->get_result()->fetch_assoc();
        
        if ($patient) {
            // Generate QR code data
            $qr_data = [
                'patient_id' => $patient_id,
                'student_id' => $patient['student_id'],
                'name' => $patient['first_name'] . ' ' . $patient['last_name'],
                'record_type' => $record_type,
                'generated_date' => date('Y-m-d H:i:s')
            ];
            
            // Convert to JSON and encode
            $qr_code = base64_encode(json_encode($qr_data));
            
            // Update patient with QR code
            $stmt = $conn->prepare("UPDATE patients SET qr_code = ? WHERE id = ?");
            $stmt->bind_param("si", $qr_code, $patient_id);
            
            if ($stmt->execute()) {
                $success_message = "QR code generated successfully!";
            
                $_SESSION['qr_code_data'] = $qr_code; // store in session temporarily
                $_SESSION['qr_patient'] = $patient;
                $_SESSION['qr_record_type'] = $record_type;

                
                // Add to analytics data
                $stmt = $conn->prepare("INSERT INTO analytics_data (data_type, data_value, data_label, data_date) VALUES ('qr_generated', 1, ?, CURDATE())");
                $data_label = "QR Code: " . $record_type;
                $stmt->bind_param("s", $data_label);
                $stmt->execute();
            } else {
                $error_message = "Error generating QR code: " . $conn->error;
            }
        } else {
            $error_message = "Patient not found.";
        }
    } else {
        $error_message = "Please select a patient and record type.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generate QR Code - BSU Clinic Records</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .red-orange-gradient {
            background: linear-gradient(135deg, #dc2626, #ea580c, #f97316);
        }
        .red-orange-gradient-button {
            background: linear-gradient(135deg, #dc2626, #ea580c);
        }
        .red-orange-gradient-button:hover {
            background: linear-gradient(135deg, #b91c1c, #c2410c);
        }
        .card-shadow { box-shadow: 0 4px 12px rgba(220, 38, 38, 0.15); }
        .form-select {
            border: 1px solid #fed7aa;
            border-radius: 8px;
            padding: 10px 15px;
            transition: all 0.3s ease;
        }
        .form-select:focus {
            border-color: #ea580c;
            box-shadow: 0 0 0 3px rgba(234, 88, 12, 0.2);
        }
        .success-card {
            border-left: 4px solid #10B981;
            background-color: #ECFDF5;
        }
        .error-card {
            border-left: 4px solid #EF4444;
            background-color: #FEF2F2;
        }
        .qr-container {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        }
        .patient-info-card {
            background: linear-gradient(135deg, #fef2f2 0%, #ffedd5 100%);
            border-left: 4px solid #ea580c;
        }
        .download-button {
            background: linear-gradient(135deg, #f97316, #fb923c);
        }
        .download-button:hover {
            background: linear-gradient(135deg, #ea580c, #f97316);
        }
    </style>
</head>
<body class="bg-gradient-to-br from-orange-50 to-red-50">
    <!-- HEADER -->
    <header class="red-orange-gradient text-white shadow-md">
        <div class="max-w-7xl mx-auto flex items-center justify-between px-6 py-3">
            <div class="flex items-center gap-3">
                <img src="../../assets/css/images/logo-bsu.png" alt="BSU Logo" class="w-12 h-12 rounded-full object-cover border-4 border-white bg-white">
                <h1 class="text-lg font-bold">BSU Clinic Record Management System</h1>
            </div>
            <nav class="flex items-center gap-6">
                <?php
                $dashboard_url = '../../dashboard.php';
                if (isset($_SESSION['role'])) {
                    if ($_SESSION['role'] === 'dentist') {
                        $dashboard_url = '../../dentist_dashboard.php';
                    } elseif ($_SESSION['role'] === 'doctor') {
                        $dashboard_url = '../../doctor_dashboard.php';
                    } elseif ($_SESSION['role'] === 'nurse') {
                        $dashboard_url = '../../nurse_dashboard.php';
                    } elseif ($_SESSION['role'] === 'staff') {
                        $dashboard_url = '../../msa_dashboard.php';
                    }
                }
                ?>
                <a href="<?php echo $dashboard_url; ?>" class="hover:text-yellow-200 flex items-center gap-1 transition-all">
                    <i class="bi bi-speedometer2"></i> Dashboard
                </a>
                <a href="../../logout.php" class="red-orange-gradient-button text-white px-3 py-1 rounded-lg font-semibold hover:shadow-lg flex items-center gap-1 transition-all">
                    <i class="bi bi-box-arrow-right"></i> Logout
                </a>
            </nav>
        </div>
    </header>

    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-4 py-8">
        <!-- Page Header -->
        <div class="mb-8">
            <h2 class="text-3xl font-bold text-orange-800">Generate QR Codes</h2>
            <p class="text-orange-600 mt-2">Create QR codes for patient records or direct form access</p>
        </div>

        <!-- Alert Messages -->
        <?php if (!empty($success_message)): ?>
            <div class="success-card rounded-lg p-4 mb-6 flex items-start">
                <i class="fas fa-check-circle text-green-500 mt-1 mr-3"></i>
                <div>
                    <h3 class="font-semibold text-green-800">Success</h3>
                    <p class="text-green-700"><?php echo $success_message; ?></p>
                </div>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($error_message)): ?>
            <div class="error-card rounded-lg p-4 mb-6 flex items-start">
                <i class="fas fa-exclamation-circle text-red-500 mt-1 mr-3"></i>
                <div>
                    <h3 class="font-semibold text-red-800">Error</h3>
                    <p class="text-red-700"><?php echo $error_message; ?></p>
                </div>
            </div>
        <?php endif; ?>

        <!-- QR Generator Cards -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- Patient QR Generator -->
            <div class="bg-white rounded-xl card-shadow overflow-hidden border-l-4 border-orange-500">
                <div class="red-orange-gradient text-white py-4 px-6">
                    <h3 class="text-xl font-bold flex items-center">
                        <i class="fas fa-user-injured mr-3"></i>
                        Patient QR Code
                    </h3>
                    <p class="text-sm text-orange-100 mt-1">Generate QR codes linked to specific patient records</p>
                </div>
                <div class="p-6">
                    <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" class="space-y-6">
                        <div>
                            <label for="patient_id" class="block mb-2 font-medium text-orange-700">
                                <i class="fas fa-user mr-2"></i>Select Patient
                            </label>
                            <select class="form-select w-full" id="patient_id" name="patient_id" required>
                                <option value="">-- Select Patient --</option>
                                <?php foreach ($patients as $patient): ?>
                                    <option value="<?php echo $patient['id']; ?>">
                                        <?php echo $patient['student_id'] . ' - ' . $patient['last_name'] . ', ' . $patient['first_name'] . ' ' . $patient['middle_name']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="record_type" class="block mb-2 font-medium text-orange-700">
                                <i class="fas fa-file-medical mr-2"></i>Record Type
                            </label>
                            <select class="form-select w-full" id="record_type" name="record_type" required>
                                <option value="">-- Select Record Type --</option>
                                <option value="history_form">Medical History Form</option>
                                <option value="dental_form">Dental Examination Form</option>
                                <option value="medical_form">Medical Examination Form</option>
                            </select>
                        </div>
                        <div class="flex justify-end pt-4">
                            <button type="submit" class="red-orange-gradient-button text-white font-semibold px-6 py-3 rounded-lg hover:shadow-lg transition-all flex items-center">
                                <i class="fas fa-qrcode mr-2"></i>
                                Generate QR Code
                            </button>
                        </div>
                    </form>
                    
                    <!-- QR Code Result -->
                    <?php if (!empty($success_message)): ?>
                        <div class="mt-8 pt-6 border-t border-orange-200">
                            <h5 class="text-lg font-semibold mb-4 text-center text-orange-800">Generated QR Code</h5>
                            <div class="flex flex-col md:flex-row gap-6">
                                <div class="qr-container flex-1 flex flex-col items-center">
                                    <div id="qrcode" class="mb-4"></div>
                                    <p class="text-orange-600 text-center mb-4">Scan this QR code to access the patient record</p>
                                    <button type="button" class="download-button text-white font-semibold px-5 py-2 rounded-lg hover:shadow-lg transition-all flex items-center" id="downloadQR">
                                        <i class="fas fa-download mr-2"></i>
                                        Download QR Code
                                    </button>
                                </div>
                                <div class="patient-info-card rounded-lg p-5 flex-1">
                                    <h6 class="font-bold text-lg mb-3 text-orange-800">Patient Information</h6>
                                    <div class="space-y-2">
                                        <p class="flex items-start">
                                            <i class="fas fa-id-card mr-2 mt-1 text-orange-600"></i>
                                            <span><span class="font-semibold">Student ID:</span> <?php echo $patient['student_id']; ?></span>
                                        </p>
                                        <p class="flex items-start">
                                            <i class="fas fa-user mr-2 mt-1 text-orange-600"></i>
                                            <span><span class="font-semibold">Name:</span> <?php echo $patient['first_name'] . ' ' . $patient['middle_name'] . ' ' . $patient['last_name']; ?></span>
                                        </p>
                                        <p class="flex items-start">
                                            <i class="fas fa-file-alt mr-2 mt-1 text-orange-600"></i>
                                            <span><span class="font-semibold">Record Type:</span> <?php echo $record_type; ?></span>
                                        </p>
                                        <p class="flex items-start">
                                            <i class="fas fa-calendar-alt mr-2 mt-1 text-orange-600"></i>
                                            <span><span class="font-semibold">Generated Date:</span> <?php echo date('Y-m-d H:i:s'); ?></span>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Form QR Generator -->
            <div class="bg-white rounded-xl card-shadow overflow-hidden border-l-4 border-orange-500">
                <div class="red-orange-gradient text-white py-4 px-6">
                    <h3 class="text-xl font-bold flex items-center">
                        <i class="fas fa-file-signature mr-3"></i>
                        Form QR Code
                    </h3>
                    <p class="text-sm text-orange-100 mt-1">Generate QR codes for direct form access</p>
                </div>
                <div class="p-6">
                    <form id="formQrForm" class="space-y-6">
                        <div>
                            <label for="form_type_select" class="block mb-2 font-medium text-orange-700">
                                <i class="fas fa-file-medical-alt mr-2"></i>Select Form Type
                            </label>
                            <select class="form-select w-full" id="form_type_select" name="form_type_select" required>
                                <option value="">-- Select Form Type --</option>
                                <option value="history_form">Medical History Form</option>
                                <option value="dental_form">Dental Examination Form</option>
                                <option value="medical_form">Medical Examination Form</option>
                            </select>
                        </div>
                        <div class="flex justify-end pt-4">
                            <button type="submit" class="red-orange-gradient-button text-white font-semibold px-6 py-3 rounded-lg hover:shadow-lg transition-all flex items-center">
                                <i class="fas fa-qrcode mr-2"></i>
                                Generate QR Code
                            </button>
                        </div>
                    </form>
                    
                    <!-- Form QR Code Result -->
                    <div id="formQrResult" style="display:none;" class="mt-8 pt-6 border-t border-orange-200">
                        <h5 class="text-lg font-semibold mb-4 text-center text-orange-800" id="formQrLabel"></h5>
                        <div class="flex flex-col items-center">
                            <div class="qr-container mb-4">
                                <div id="form_qrcode"></div>
                            </div>
                            <p class="text-orange-600 text-center mb-4" id="formQrDesc"></p>
                            <button type="button" class="download-button text-white font-semibold px-5 py-2 rounded-lg hover:shadow-lg transition-all flex items-center" id="downloadFormQR">
                                <i class="fas fa-download mr-2"></i>
                                Download QR Code
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <!-- <footer class="red-orange-gradient text-white py-4 mt-8">
        <div class="max-w-7xl mx-auto text-center">
            <small>&copy; <?php echo date('Y'); ?> Batangas State University - Clinic Record Management System</small>
        </div>
    </footer> -->

    <!-- Load QRCode library BEFORE custom JS -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="../../assets/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/qrcode.min.js"></script>

    <script>
    $(document).ready(function() {
        console.log("✅ jQuery is working");
        if (typeof QRCode !== "undefined") {
            console.log("✅ QRCode library loaded successfully");
        } else {
            console.error("❌ QRCode library failed to load");
        }

        /** ✅ PATIENT QR CODE (Generated by PHP) **/
        <?php if (!empty($success_message) && isset($qr_code)): ?>
            const qrContainer = document.getElementById("qrcode");
            if (qrContainer && typeof QRCode !== 'undefined') {
                // Clear and re-generate
                qrContainer.innerHTML = "";
                new QRCode(qrContainer, {
                    text: "<?php echo $qr_code; ?>",
                    width: 200,
                    height: 200
                });

                // ✅ Download QR Code function
                $("#downloadQR").on("click", function() {
                    const canvas = document.querySelector("#qrcode canvas");
                    if (!canvas) {
                        alert("QR Code not found. Please regenerate.");
                        return;
                    }
                    const image = canvas.toDataURL("image/png");
                    const link = document.createElement("a");
                    link.href = image;
                    link.download = "QR_<?php echo $patient['student_id']; ?>_<?php echo $record_type; ?>.png";
                    link.click();
                });
            } else {
                console.error("QR Code library not loaded or container missing.");
            }
        <?php endif; ?>

        /** ✅ FORM QR CODE GENERATOR **/
        const formLabels = {
            'history_form': 'Medical History Form',
            'dental_form': 'Dental Examination Form',
            'medical_form': 'Medical Examination Form'
        };
        const formDescs = {
            'history_form': 'Scan this QR code to access the Medical History Form.',
            'dental_form': 'Scan this QR code to access the Dental Examination Form.',
            'medical_form': 'Scan this QR code to access the Medical Examination Form.'
        };
        const formUrls = {
            'history_form': 'modules/forms/history_form.php',
            'dental_form': 'modules/forms/dental_form.php',
            'medical_form': 'modules/forms/medical_form.php'
        };

        $("#formQrForm").on("submit", function(e) {
            e.preventDefault();
            const formType = $("#form_type_select").val();

            if (!formType) {
                alert("Please select a form type.");
                $("#formQrResult").hide();
                return;
            }

            const formQrContainer = document.getElementById("form_qrcode");
            if (!formQrContainer || typeof QRCode === 'undefined') {
                alert("QR Code library not loaded or container missing.");
                return;
            }

            // Generate QR for form link - use absolute URL
            var baseUrl = window.location.protocol + "//" + window.location.host;
            var formPath = formUrls[formType];
            // Ensure path starts with / for absolute URL (remove any leading ../)
            formPath = formPath.replace(/^\.\.\/\.\.\//, '').replace(/^\.\.\//, '');
            if (!formPath.startsWith('/')) {
                formPath = '/' + formPath;
            }
            var qrCodeText = baseUrl + formPath;
            console.log("Generated QR URL:", qrCodeText); // Debug log
            formQrContainer.innerHTML = "";
            new QRCode(formQrContainer, {
                text: qrCodeText,
                width: 200,
                height: 200
            });

            // Update text
            $("#formQrLabel").text(formLabels[formType]);
            $("#formQrDesc").text(formDescs[formType]);
            $("#formQrResult").show();
            
            // Add download functionality for form QR
            $("#downloadFormQR").off("click").on("click", function() {
                const canvas = document.querySelector("#form_qrcode canvas");
                if (!canvas) {
                    alert("QR Code not found. Please regenerate.");
                    return;
                }
                const image = canvas.toDataURL("image/png");
                const link = document.createElement("a");
                link.href = image;
                link.download = "QR_Form_" + formType + ".png";
                link.click();
            });
        });
    });
    </script>
</body>
<?php
$qr_code = $_SESSION['qr_code_data'] ?? null;
$patient = $_SESSION['qr_patient'] ?? null;
$record_type = $_SESSION['qr_record_type'] ?? null;

// Clear session data after displaying once
unset($_SESSION['qr_code_data'], $_SESSION['qr_patient'], $_SESSION['qr_record_type']);
?>
</html>