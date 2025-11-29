<?php
session_start();
include '../../config/database.php';

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

// Check if patient ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: patients.php");
    exit();
}

// Determine dashboard URL based on role
$dashboard_url = '../../dashboard.php';
if (isset($_SESSION['role'])) {
    if ($_SESSION['role'] === 'doctor') {
        $dashboard_url = '../../doctor_dashboard.php';
    } elseif ($_SESSION['role'] === 'dentist') {
        $dashboard_url = '../../dentist_dashboard.php';
    } elseif ($_SESSION['role'] === 'nurse') {
        $dashboard_url = '../../nurse_dashboard.php';
    } elseif ($_SESSION['role'] === 'staff') {
        $dashboard_url = '../../msa_dashboard.php';
    }
}

$patient_id = $_GET['id'];

// Fetch patient info
$stmt = $conn->prepare("SELECT * FROM patients WHERE id = ?");
$stmt->bind_param("i", $patient_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    header("Location: patients.php");
    exit();
}
$patient = $result->fetch_assoc();

// Fetch records
$stmt = $conn->prepare("
    SELECT mr.*, 
           CASE 
               WHEN mr.record_type = 'history_form' THEN 'Medical History Form'
               WHEN mr.record_type = 'dental_exam' THEN 'Dental Examination'
               WHEN mr.record_type = 'medical_exam' THEN 'Medical Examination'
               ELSE mr.record_type
           END AS record_type_name
    FROM medical_records mr
    WHERE mr.patient_id = ?
    ORDER BY mr.examination_date DESC
");
$stmt->bind_param("i", $patient_id);
$stmt->execute();
$records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Calculate age
$dob = new DateTime($patient['date_of_birth']);
$now = new DateTime();
$age = $now->diff($dob)->y;
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Patient Details - BSU Clinic Records</title>
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
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
    
    .red-orange-table-header {
      background: linear-gradient(135deg, #dc2626, #ea580c);
    }
    
    .red-orange-table-row {
      background: linear-gradient(135deg, #fef2f2, #ffedd5);
    }
    
    .red-orange-table-row:hover {
      background: linear-gradient(135deg, #fee2e2, #fed7aa);
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
        <a href="<?php echo $dashboard_url; ?>" class="hover:text-yellow-200 flex items-center gap-1">
          <i class="bi bi-speedometer2"></i> Dashboard
        </a>
        <!-- <a href="../admin_panel.php" class="hover:text-yellow-200 flex items-center gap-1">
          <i class="bi bi-person-badge"></i> Admin Panel
        </a> -->
        <a href="../../logout.php" class="red-orange-gradient-button text-white px-3 py-1 rounded-lg font-semibold hover:shadow-lg flex items-center gap-1">
          <i class="bi bi-box-arrow-right"></i> Logout
        </a>
      </nav>
    </div>
  </header>

  <main class="max-w-7xl mx-auto p-8">
    <a href="patients.php" class="inline-flex items-center mb-6 text-orange-700 font-semibold hover:text-orange-900 transition-all">
      <i class="bi bi-arrow-left mr-2"></i> Back to Patients
    </a>

    <!-- Patient Details -->
    <div class="bg-white rounded-lg shadow-lg p-8 mb-8">
      <h2 class="text-2xl font-bold text-orange-800 mb-6 flex items-center gap-2">
        <i class="bi bi-person-circle"></i> Patient Information
      </h2>
      <div class="grid md:grid-cols-2 gap-8">
        <div>
          <table class="w-full border border-orange-200 rounded-lg overflow-hidden">
            <tbody class="divide-y divide-orange-100">
              <tr class="red-orange-table-row"><th class="p-3 text-left text-orange-700 font-semibold">Student ID</th><td class="p-3 text-orange-900"><?php echo $patient['student_id']; ?></td></tr>
              <tr class="red-orange-table-row"><th class="p-3 text-left text-orange-700 font-semibold">Full Name</th><td class="p-3 text-orange-900"><?php echo $patient['last_name'] . ', ' . $patient['first_name'] . ' ' . $patient['middle_name']; ?></td></tr>
              <tr class="red-orange-table-row"><th class="p-3 text-left text-orange-700 font-semibold">Sex</th><td class="p-3 text-orange-900"><?php echo $patient['sex']; ?></td></tr>
              <tr class="red-orange-table-row"><th class="p-3 text-left text-orange-700 font-semibold">Date of Birth</th><td class="p-3 text-orange-900"><?php echo $patient['date_of_birth']; ?> (<?php echo $age; ?> years old)</td></tr>
              <tr class="red-orange-table-row"><th class="p-3 text-left text-orange-700 font-semibold">Program</th><td class="p-3 text-orange-900"><?php echo $patient['program']; ?></td></tr>
              <tr class="red-orange-table-row"><th class="p-3 text-left text-orange-700 font-semibold">Year Level</th><td class="p-3 text-orange-900"><?php echo $patient['year_level']; ?></td></tr>
            </tbody>
          </table>
        </div>

        <!-- QR Code section hidden (UI-only). Existing QR data is preserved in the database. -->
      </div>
    </div>

    <!-- Medical Records -->
    <div class="bg-white rounded-lg shadow-lg p-8">
      <div class="flex justify-between items-center mb-6">
        <h2 class="text-2xl font-bold text-orange-800 flex items-center gap-2">
          <i class="bi bi-file-medical"></i> Medical Records
        </h2>
        <div class="relative">
          <button class="red-orange-gradient-button text-white px-4 py-2 rounded-lg shadow hover:shadow-lg flex items-center gap-2 transition-all" id="newRecordBtn">
            <i class="bi bi-plus-circle"></i> New Record
          </button>
          <ul class="hidden absolute right-0 mt-2 w-56 bg-white border border-orange-200 rounded-lg shadow-lg" id="recordDropdown">
            <li><a href="../forms/history_form.php?patient_id=<?php echo $patient_id; ?>" class="block px-4 py-2 hover:bg-orange-50 text-orange-700 transition-all">Medical History Form</a></li>
            <li><a href="../forms/dental_form.php?patient_id=<?php echo $patient_id; ?>" class="block px-4 py-2 hover:bg-orange-50 text-orange-700 transition-all">Dental Examination</a></li>
            <li><a href="../forms/medical_form.php?patient_id=<?php echo $patient_id; ?>" class="block px-4 py-2 hover:bg-orange-50 text-orange-700 transition-all">Medical Examination</a></li>
          </ul>
        </div>
      </div>

      <?php if (count($records) > 0): ?>
        <div class="overflow-x-auto">
          <table class="min-w-full border border-orange-200 rounded-lg overflow-hidden">
            <thead class="red-orange-table-header text-white">
              <tr>
                <th class="px-4 py-3 text-left">Date</th>
                <th class="px-4 py-3 text-left">Record Type</th>
                <th class="px-4 py-3 text-left">Physician</th>
                <th class="px-4 py-3 text-left">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-orange-100">
              <?php foreach ($records as $record): ?>
                <tr class="red-orange-table-row hover:shadow-lg transition-all">
                  <td class="px-4 py-3 text-orange-900"><?php echo $record['examination_date']; ?></td>
                  <td class="px-4 py-3 text-orange-900"><?php echo $record['record_type_name']; ?></td>
                  <td class="px-4 py-3 text-orange-900"><?php echo $record['physician_name']; ?></td>
                  <td class="px-4 py-3">
                  <a href="../records/view_record.php?id=<?php echo $record['id']; ?>&type=<?php echo $record['record_type']; ?>" 
                class="text-orange-600 hover:text-orange-800 mr-3 transition-all" 
                title="View Record">
                <i class="bi bi-eye"></i>
</a>

            
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <p class="text-orange-600 text-center py-4">No medical records found for this patient.</p>
      <?php endif; ?>
    </div>
  </main>

  <script src="../../assets/js/qrcode.min.js"></script>
  <script>
    // Toggle record dropdown
    const btn = document.getElementById("newRecordBtn");
    const dropdown = document.getElementById("recordDropdown");
    btn.addEventListener("click", () => dropdown.classList.toggle("hidden"));

    <?php if (!empty($patient['qr_code'])): ?>
    const qrcode = new QRCode(document.getElementById("qrcode"), {
        text: "<?php echo $patient['qr_code']; ?>",
        width: 200,
        height: 200
    });

    document.getElementById("downloadQR").addEventListener("click", () => {
        const canvas = document.querySelector("#qrcode canvas");
        const image = canvas.toDataURL("image/png");
        const link = document.createElement("a");
        link.href = image;
        link.download = "QR_<?php echo $patient['student_id']; ?>.png";
        link.click();
    });
    <?php endif; ?>
  </script>
</body>
</html>