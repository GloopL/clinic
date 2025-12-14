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

$patient_id = $_GET['id'];
$patient = null;
$records = [];
$age = 0;
$error = '';

try {
    // Fetch patient info
    $stmt = $conn->prepare("SELECT * FROM patients WHERE id = ?");
    if (!$stmt) {
        throw new Exception("Database prepare failed: " . $conn->error);
    }
    
    $stmt->bind_param("i", $patient_id);
    if (!$stmt->execute()) {
        throw new Exception("Query execution failed: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        header("Location: patients.php");
        exit();
    }
    
    $patient = $result->fetch_assoc();
    
    // Calculate age
    if (!empty($patient['date_of_birth'])) {
        $dob = new DateTime($patient['date_of_birth']);
        $now = new DateTime();
        $age = $now->diff($dob)->y;
    }
    
    // Fetch ONLY certified records
    $stmt = $conn->prepare("
        SELECT mr.*, 
               CASE 
                   WHEN mr.record_type = 'history_form' THEN 'Medical History Form'
                   WHEN mr.record_type = 'dental_exam' THEN 'Dental Examination'
                   WHEN mr.record_type = 'medical_exam' THEN 'Medical Examination'
               END AS record_type_name,
               mr.verification_status,
               mr.certified_date,
               mr.certified_by
        FROM medical_records mr
        WHERE mr.patient_id = ? 
        AND mr.verification_status = 'certified'
        ORDER BY mr.certified_date DESC
    ");
    
    if ($stmt) {
        $stmt->bind_param("i", $patient_id);
        $stmt->execute();
        $records_result = $stmt->get_result();
        if ($records_result) {
            $records = $records_result->fetch_all(MYSQLI_ASSOC);
        }
    }
    
} catch (Exception $e) {
    $error = $e->getMessage();
    error_log("Patient details error: " . $e->getMessage());
}

// Only show page if we have patient data
if (!$patient) {
    header("Location: patients.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Patient Details - BSU Clinic Records</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
  <style>
    /* Custom maroon theme (#800000) */
    :root {
      --maroon-primary: #800000;
      --maroon-dark: #660000;
      --maroon-light: #a00000;
      --maroon-bg: #fff5f5;
    }
    
    .maroon-gradient {
      background: linear-gradient(135deg, var(--maroon-primary), var(--maroon-light));
    }
    
    .maroon-gradient-button {
      background: linear-gradient(135deg, var(--maroon-primary), var(--maroon-light));
    }
    
    .maroon-gradient-button:hover {
      background: linear-gradient(135deg, var(--maroon-dark), var(--maroon-primary));
    }
    
    .maroon-table-header {
      background: linear-gradient(135deg, var(--maroon-primary), var(--maroon-light));
    }
    
    .maroon-table-row {
      background: linear-gradient(135deg, #fff5f5, #ffe5e5);
    }
    
    .maroon-table-row:hover {
      background: linear-gradient(135deg, #ffe5e5, #ffcccc);
    }
    
    .maroon-badge {
      background: linear-gradient(135deg, #ffcccc, #ffb3b3);
      color: #800000;
    }
    
    .text-maroon {
      color: var(--maroon-primary);
    }
  </style>
</head>
<body class="bg-gradient-to-br from-red-50 to-pink-50 min-h-screen p-6">

  <div class="max-w-7xl mx-auto">
    <!-- Back Navigation -->
    <div class="mb-6">
      <a href="patients.php" class="inline-flex items-center text-maroon hover:text-maroon-dark font-semibold transition-all group">
        <i class="fas fa-arrow-left mr-2 group-hover:-translate-x-1 transition-transform"></i> Back to Patients
      </a>
    </div>

    <?php if ($error): ?>
      <!-- Error Message -->
      <div class="bg-red-50 border-l-4 border-red-500 p-4 mb-6">
        <div class="flex">
          <div class="flex-shrink-0">
            <i class="fas fa-exclamation-triangle text-red-400"></i>
          </div>
          <div class="ml-3">
            <p class="text-sm text-red-700">Error loading patient data. Please try again.</p>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <!-- Patient Details -->
    <?php if ($patient): ?>
    <div class="bg-white rounded-2xl shadow-xl p-8 mb-8 border border-gray-100">
      <div class="flex items-center justify-between mb-8">
        <div class="flex items-center gap-4">
          <div class="w-16 h-16 maroon-gradient rounded-xl flex items-center justify-center text-white text-2xl font-bold shadow-lg">
            <?php echo strtoupper(substr($patient['first_name'] ?? '', 0, 1) . substr($patient['last_name'] ?? '', 0, 1)); ?>
          </div>
          <div>
            <h2 class="text-3xl font-bold text-gray-800"><?php echo htmlspecialchars($patient['first_name'] ?? '') . ' ' . htmlspecialchars($patient['last_name'] ?? ''); ?></h2>
            <p class="text-gray-600">Student ID: <span class="font-semibold text-maroon"><?php echo htmlspecialchars($patient['student_id'] ?? ''); ?></span></p>
          </div>
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
        <!-- Personal Information -->
        <div class="bg-gray-50 rounded-xl p-6 border border-gray-200">
          <h3 class="text-xl font-bold text-gray-800 mb-6 flex items-center gap-2">
            <i class="fas fa-user-circle text-maroon"></i> Personal Information
          </h3>
          <div class="space-y-4">
            <div class="flex justify-between items-center border-b border-gray-200 pb-3">
              <span class="text-gray-600 font-medium">Student ID</span>
              <span class="text-gray-900 font-semibold text-maroon"><?php echo htmlspecialchars($patient['student_id'] ?? ''); ?></span>
            </div>
            <div class="flex justify-between items-center border-b border-gray-200 pb-3">
              <span class="text-gray-600 font-medium">Full Name</span>
              <span class="text-gray-900 font-semibold">
                <?php 
                echo htmlspecialchars($patient['last_name'] ?? '') . ', ' . 
                     htmlspecialchars($patient['first_name'] ?? '') . ' ' . 
                     htmlspecialchars($patient['middle_name'] ?? '');
                ?>
              </span>
            </div>
            <div class="flex justify-between items-center border-b border-gray-200 pb-3">
              <span class="text-gray-600 font-medium">Sex</span>
              <span class="text-gray-900 font-semibold"><?php echo htmlspecialchars($patient['sex'] ?? ''); ?></span>
            </div>
            <div class="flex justify-between items-center border-b border-gray-200 pb-3">
              <span class="text-gray-600 font-medium">Date of Birth</span>
              <span class="text-gray-900 font-semibold">
                <?php echo htmlspecialchars($patient['date_of_birth'] ?? ''); ?> 
                <?php if ($age > 0): ?>
                  <span class="text-maroon">(<?php echo $age; ?> years old)</span>
                <?php endif; ?>
              </span>
            </div>
            <div class="flex justify-between items-center border-b border-gray-200 pb-3">
              <span class="text-gray-600 font-medium">Program</span>
              <span class="text-gray-900 font-semibold"><?php echo htmlspecialchars($patient['program'] ?? ''); ?></span>
            </div>
            <div class="flex justify-between items-center">
              <span class="text-gray-600 font-medium">Year Level</span>
              <span class="text-gray-900 font-semibold"><?php echo htmlspecialchars($patient['year_level'] ?? ''); ?></span>
            </div>
          </div>
        </div>

        <!-- Contact Information -->
        <div class="bg-gray-50 rounded-xl p-6 border border-gray-200">
          <h3 class="text-xl font-bold text-gray-800 mb-6 flex items-center gap-2">
            <i class="fas fa-address-card text-maroon"></i> Contact Information
          </h3>
          <div class="space-y-4">
            <div class="flex justify-between items-center border-b border-gray-200 pb-3">
              <span class="text-gray-600 font-medium">Contact Number</span>
              <span class="text-gray-900 font-semibold"><?php echo htmlspecialchars($patient['contact_number'] ?? 'N/A'); ?></span>
            </div>
            <div class="flex justify-between items-start">
              <span class="text-gray-600 font-medium">Address</span>
              <span class="text-gray-900 font-semibold text-right max-w-xs"><?php echo htmlspecialchars($patient['address'] ?? 'N/A'); ?></span>
            </div>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Medical Records -->
    <div class="bg-white rounded-2xl shadow-xl p-8 border border-gray-100">
      <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-8 gap-4">
        <div>
          <h2 class="text-3xl font-bold text-gray-800 flex items-center gap-3">
            <i class="fas fa-file-medical-alt text-maroon"></i> Medical Records
          </h2>
          <p class="text-gray-600 mt-2">
            <?php if ($patient): ?>
              Certified medical records for <?php echo htmlspecialchars($patient['first_name'] ?? ''); ?>
            <?php endif; ?>
          </p>
        </div>
        <div class="relative">
          <button onclick="document.getElementById('recordDropdown').classList.toggle('hidden')" 
                  class="maroon-gradient-button text-white px-6 py-3 rounded-xl font-semibold hover:shadow-lg transition-all flex items-center gap-2">
            <i class="fas fa-plus-circle"></i> Add New Record
          </button>
          
          <ul class="hidden absolute right-0 mt-2 w-64 bg-white border border-gray-200 rounded-xl shadow-xl z-10" id="recordDropdown">
            <li><a href="../forms/history_form.php?patient_id=<?php echo $patient_id; ?>" 
                   class="block px-5 py-3 hover:bg-red-50 text-gray-700 transition-all flex items-center gap-2 border-b border-gray-100">
              <i class="fas fa-history text-maroon"></i> Medical History Form
            </a></li>
            <li><a href="../forms/dental_form.php?patient_id=<?php echo $patient_id; ?>" 
                   class="block px-5 py-3 hover:bg-red-50 text-gray-700 transition-all flex items-center gap-2 border-b border-gray-100">
              <i class="fas fa-tooth text-maroon"></i> Dental Examination
            </a></li>
            <li><a href="../forms/medical_form.php?patient_id=<?php echo $patient_id; ?>" 
                   class="block px-5 py-3 hover:bg-red-50 text-gray-700 transition-all flex items-center gap-2">
              <i class="fas fa-stethoscope text-maroon"></i> Medical Examination
            </a></li>
          </ul>
        </div>
      </div>

      <?php if (count($records) > 0): ?>
        <div class="overflow-x-auto rounded-xl border border-gray-200">
          <table class="min-w-full">
            <thead class="maroon-table-header text-white">
              <tr>
                <th class="px-6 py-4 text-left text-sm font-semibold uppercase tracking-wider">Date</th>
                <th class="px-6 py-4 text-left text-sm font-semibold uppercase tracking-wider">Record Type</th>
                <th class="px-6 py-4 text-left text-sm font-semibold uppercase tracking-wider">Physician</th>
                <th class="px-6 py-4 text-left text-sm font-semibold uppercase tracking-wider">Certification Status</th>
                <th class="px-6 py-4 text-left text-sm font-semibold uppercase tracking-wider">Actions</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <?php foreach ($records as $record): ?>
              <tr class="maroon-table-row hover:shadow-md transition-all">
                <td class="px-6 py-4 text-gray-900"><?php echo htmlspecialchars($record['examination_date'] ?? ''); ?></td>
                <td class="px-6 py-4 text-gray-900"><?php echo htmlspecialchars($record['record_type_name'] ?? ''); ?></td>
                <td class="px-6 py-4 text-gray-900"><?php echo htmlspecialchars($record['physician_name'] ?? ''); ?></td>
                <td class="px-6 py-4">
                  <?php 
                  if (!empty($record['verification_status'])) {
                    $status = strtolower($record['verification_status']);
                    $badge_class = '';
                    $status_text = '';
                    
                    switch($status) {
                      case 'pending':
                        $badge_class = 'bg-yellow-100 text-yellow-800 border-yellow-200';
                        $status_text = 'Pending';
                        break;
                      case 'for_certification':
                        $badge_class = 'bg-blue-100 text-blue-800 border-blue-200';
                        $status_text = 'For Certification';
                        break;
                      case 'certified':
                        $badge_class = 'bg-green-100 text-green-800 border-green-200';
                        $status_text = 'Certified';
                        break;
                      case 'rejected':
                        $badge_class = 'bg-red-100 text-red-800 border-red-200';
                        $status_text = 'Rejected';
                        break;
                      default:
                        $badge_class = 'bg-gray-100 text-gray-800 border-gray-200';
                        $status_text = ucfirst($status);
                    }
                    
                    echo '<span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold ' . $badge_class . ' border">';
                    echo htmlspecialchars($status_text);
                    echo '</span>';
                    
                    if ($status === 'certified' && !empty($record['certified_date'])) {
                      echo '<div class="text-xs text-gray-600 mt-1">' . date('M d, Y', strtotime($record['certified_date'])) . '</div>';
                    }
                  } else {
                    echo '<span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-gray-100 text-gray-800 border border-gray-200">Not Submitted</span>';
                  }
                  ?>
                </td>
                <td class="px-6 py-4">
                  <a href="../records/view_record.php?id=<?php echo $record['id']; ?>&type=<?php echo $record['record_type']; ?>" 
                     class="inline-flex items-center gap-1.5 px-4 py-2 maroon-badge rounded-lg hover:shadow-md transition-all text-sm font-semibold"
                     title="View Record">
                    <i class="fas fa-eye"></i> View
                  </a>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        
        <div class="mt-6 text-center text-gray-600 text-sm">
          Showing <?php echo count($records); ?> certified record(s)
        </div>
      <?php else: ?>
        <div class="text-center py-12">
          <div class="w-24 h-24 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
            <i class="fas fa-file-medical text-gray-400 text-3xl"></i>
          </div>
          <h3 class="text-xl font-semibold text-gray-700 mb-2">No Medical Records Found</h3>
          <p class="text-gray-600">No certified medical records are available for this patient.</p>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <script>
    // Simple dropdown toggle
    document.addEventListener('click', function(e) {
      const dropdown = document.getElementById('recordDropdown');
      const button = document.querySelector('button[onclick*="recordDropdown"]');
      
      if (button && button.contains(e.target)) {
        dropdown.classList.toggle('hidden');
      } else if (dropdown && !dropdown.contains(e.target)) {
        dropdown.classList.add('hidden');
      }
    });
  </script>
</body>
</html>