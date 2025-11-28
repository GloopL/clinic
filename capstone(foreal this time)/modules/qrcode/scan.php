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
$patient_data = null;
$record_data = null;

// Process QR code data
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['qr_data'])) {
    $qr_data = $_POST['qr_data'];
    
    try {
        $decoded_data = json_decode(base64_decode($qr_data), true);
        
        if ($decoded_data && isset($decoded_data['patient_id'])) {
            $patient_id = $decoded_data['patient_id'];
            $record_type = $decoded_data['record_type'] ?? '';
            
            // Get patient details
            $stmt = $conn->prepare("SELECT * FROM patients WHERE id = ?");
            $stmt->bind_param("i", $patient_id);
            $stmt->execute();
            $patient_data = $stmt->get_result()->fetch_assoc();
            
            if ($patient_data) {
                // Fetch record data based on record type
                switch ($record_type) {
                    case 'history_form':
                        $stmt = $conn->prepare("
                            SELECT hf.*, mr.examination_date, mr.physician_name 
                            FROM history_forms hf
                            JOIN medical_records mr ON hf.record_id = mr.id
                            WHERE mr.patient_id = ?
                            ORDER BY mr.examination_date DESC
                            LIMIT 1
                        ");
                        break;
                    case 'dental_form':
                        $stmt = $conn->prepare("
                            SELECT de.*, mr.examination_date, mr.physician_name 
                            FROM dental_exams de
                            JOIN medical_records mr ON de.record_id = mr.id
                            WHERE mr.patient_id = ?
                            ORDER BY mr.examination_date DESC
                            LIMIT 1
                        ");
                        break;
                    case 'medical_form':
                        $stmt = $conn->prepare("
                            SELECT me.*, mr.examination_date, mr.physician_name 
                            FROM medical_exams me
                            JOIN medical_records mr ON me.record_id = mr.id
                            WHERE mr.patient_id = ?
                            ORDER BY mr.examination_date DESC
                            LIMIT 1
                        ");
                        break;
                    default:
                        $error_message = "Unknown record type.";
                        $stmt = null;
                        break;
                }

                if ($stmt) {
                    $stmt->bind_param("i", $patient_id);
                    $stmt->execute();
                    $record_data = $stmt->get_result()->fetch_assoc();
                }

                $success_message = "QR code scanned successfully!";

                $stmt = $conn->prepare("INSERT INTO analytics_data (data_type, data_value, data_label, data_date) VALUES ('qr_scanned', 1, ?, CURDATE())");
                $data_label = "QR Scan: " . $record_type;
                $stmt->bind_param("s", $data_label);
                $stmt->execute();
            } else {
                $error_message = "Patient not found.";
            }
        } else {
            $error_message = "Invalid QR code data.";
        }
    } catch (Exception $e) {
        $error_message = "Error processing QR code: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Scan QR Code - BSU Clinic Records</title>
  <script src="https://cdn.tailwindcss.com"></script>
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
    .patient-info-card {
        background: linear-gradient(135deg, #fef2f2 0%, #ffedd5 100%);
        border-left: 4px solid #ea580c;
    }
    .scanner-container {
        border: 2px solid #ea580c;
        border-radius: 12px;
        overflow: hidden;
    }
    .action-button {
        background: linear-gradient(135deg, #f97316, #fb923c);
    }
    .action-button:hover {
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
        <h2 class="text-3xl font-bold text-orange-800">Scan QR Code</h2>
        <p class="text-orange-600 mt-2">Scan QR codes to retrieve patient information and medical records</p>
    </div>

    <!-- Alert Messages -->
    <?php if ($success_message): ?>
        <div class="success-card rounded-lg p-4 mb-6 flex items-start">
            <i class="fas fa-check-circle text-green-500 mt-1 mr-3"></i>
            <div>
                <h3 class="font-semibold text-green-800">Success</h3>
                <p class="text-green-700"><?php echo $success_message; ?></p>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="error-card rounded-lg p-4 mb-6 flex items-start">
            <i class="fas fa-exclamation-circle text-red-500 mt-1 mr-3"></i>
            <div>
                <h3 class="font-semibold text-red-800">Error</h3>
                <p class="text-red-700"><?php echo $error_message; ?></p>
            </div>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
      
      <!-- LEFT: QR Code Scanner -->
      <div class="bg-white rounded-xl card-shadow overflow-hidden border-l-4 border-orange-500">
        <div class="red-orange-gradient text-white py-4 px-6">
            <h3 class="text-xl font-bold flex items-center">
                <i class="fas fa-qrcode mr-3"></i>
                QR Code Scanner
            </h3>
            <p class="text-sm text-orange-100 mt-1">Scan QR codes from patient records or forms</p>
        </div>
        <div class="p-6">
          <div class="space-y-6">
            <div id="qr-reader" class="scanner-container w-full rounded-lg overflow-hidden shadow-inner"></div>

            <div class="text-center text-orange-600 font-medium">or</div>

            <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" class="space-y-4">
              <div>
                <label for="qr_data" class="block mb-2 font-medium text-orange-700">
                    <i class="fas fa-keyboard mr-2"></i>Enter QR Code Data
                </label>
                <textarea id="qr_data" name="qr_data" rows="3" required class="form-select w-full"></textarea>
              </div>
              <div class="flex justify-end">
                <button type="submit" class="red-orange-gradient-button text-white font-semibold px-6 py-3 rounded-lg hover:shadow-lg transition-all flex items-center">
                    <i class="fas fa-search mr-2"></i>
                    Process QR Code
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>

      <!-- RIGHT: Patient Info -->
      <div class="bg-white rounded-xl card-shadow overflow-hidden border-l-4 border-orange-500">
        <div class="red-orange-gradient text-white py-4 px-6">
            <h3 class="text-xl font-bold flex items-center">
                <i class="fas fa-user-injured mr-3"></i>
                Patient Information
            </h3>
            <p class="text-sm text-orange-100 mt-1">Patient details will appear here after scanning</p>
        </div>
        <div class="p-6">
          <?php if ($patient_data): ?>
            <div class="patient-info-card rounded-lg p-5 mb-6">
              <table class="w-full text-sm text-left text-orange-900">
                <tr><th class="py-2 font-semibold w-2/5">Student ID:</th><td class="font-medium"><?php echo $patient_data['student_id']; ?></td></tr>
                <tr><th class="py-2 font-semibold">Name:</th><td class="font-medium"><?php echo $patient_data['first_name'] . ' ' . $patient_data['middle_name'] . ' ' . $patient_data['last_name']; ?></td></tr>
                <tr><th class="py-2 font-semibold">Date of Birth:</th><td class="font-medium"><?php echo $patient_data['date_of_birth']; ?></td></tr>
                <tr><th class="py-2 font-semibold">Sex:</th><td class="font-medium"><?php echo $patient_data['sex']; ?></td></tr>
                <tr><th class="py-2 font-semibold">Program:</th><td class="font-medium"><?php echo $patient_data['program']; ?></td></tr>
                <tr><th class="py-2 font-semibold">Year Level:</th><td class="font-medium"><?php echo $patient_data['year_level']; ?></td></tr>
              </table>
            </div>

            <div class="flex justify-center space-x-4 mb-6">
              <a href="../../modules/records/view_patient.php?id=<?php echo $patient_data['id']; ?>" class="action-button text-white font-semibold px-6 py-2 rounded-lg hover:shadow-lg transition-all flex items-center">
                <i class="fas fa-file-medical mr-2"></i>
                View Full Record
              </a>
            </div>

            <?php if ($record_data): ?>
              <div class="mt-6 border-t border-orange-200 pt-6">
                <h4 class="text-lg font-semibold text-orange-800 mb-4 flex items-center">
                  <i class="fas fa-clipboard-list mr-2"></i>
                  Record Information
                </h4>
                <div class="bg-orange-50 rounded-lg p-4">
                  <table class="w-full text-sm text-left text-orange-700">
                    <tr><th class="py-2 font-semibold w-2/5">Examination Date:</th><td><?php echo $record_data['examination_date']; ?></td></tr>
                    <tr><th class="py-2 font-semibold">Physician:</th><td><?php echo $record_data['physician_name']; ?></td></tr>
                    <?php if (isset($record_data['chief_complaint'])): ?>
                      <tr><th class="py-2 font-semibold">Chief Complaint:</th><td><?php echo $record_data['chief_complaint']; ?></td></tr>
                    <?php endif; ?>
                    <?php if (isset($record_data['diagnosis'])): ?>
                      <tr><th class="py-2 font-semibold">Diagnosis:</th><td><?php echo $record_data['diagnosis']; ?></td></tr>
                    <?php endif; ?>
                    <?php if (isset($record_data['height']) && isset($record_data['weight'])): ?>
                      <tr><th class="py-2 font-semibold">Height / Weight:</th><td><?php echo $record_data['height']; ?> cm / <?php echo $record_data['weight']; ?> kg</td></tr>
                    <?php endif; ?>
                  </table>
                </div>

                <?php
                $record_type = $decoded_data['record_type'] ?? '';
                $form_url = '';
                switch ($record_type) {
                    case 'history_form': $form_url = '../../modules/forms/history_form.php?id=' . $record_data['record_id']; break;
                    case 'dental_form': $form_url = '../../modules/forms/dental_form.php?id=' . $record_data['record_id']; break;
                    case 'medical_form': $form_url = '../../modules/forms/medical_form.php?id=' . $record_data['record_id']; break;
                }
                if ($form_url):
                ?>
                  <div class="mt-6 text-center">
                    <a href="<?php echo $form_url; ?>" class="action-button text-white font-semibold px-6 py-2 rounded-lg hover:shadow-lg transition-all flex items-center justify-center">
                      <i class="fas fa-external-link-alt mr-2"></i>
                      View Full Form
                    </a>
                  </div>
                <?php endif; ?>
              </div>
            <?php endif; ?>

          <?php else: ?>
            <div class="text-center text-orange-600 py-8">
              <i class="fas fa-qrcode text-4xl mb-4"></i>
              <h3 class="text-lg font-semibold mb-3">Scan QR Code</h3>
              <p class="mb-2">Scan a QR code or manually enter the data to retrieve patient information.</p>
              <p>The QR code should contain encoded details from the BSU Clinic Records System.</p>
            </div>
          <?php endif; ?>
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

  <!-- Scripts -->
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="../../assets/js/html5-qrcode.min.js"></script>
  <script>
  $(document).ready(function() {
      const html5QrCode = new Html5Qrcode("qr-reader");
      const qrConfig = { fps: 10, qrbox: { width: 250, height: 250 } };

      html5QrCode.start(
          { facingMode: "environment" },
          qrConfig,
          onScanSuccess,
          onScanFailure
      ).catch(() => {
          $("#qr-reader").html('<div class="text-center p-4 bg-orange-100 text-orange-800 rounded-lg"><i class="fas fa-exclamation-triangle mr-2"></i>Camera not accessible. Please enter QR code data manually.</div>');
      });

      function onScanSuccess(decodedText) {
          html5QrCode.stop();
          $("#qr_data").val(decodedText);
          $("form").submit();
      }

      function onScanFailure(error) {
          console.warn(`Scan error: ${error}`);
      }
  });
  </script>
</body>
</html>