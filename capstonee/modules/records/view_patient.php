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
    
    // Fetch certified AND completed records
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
        AND (mr.verification_status = 'certified' OR mr.verification_status = 'completed')
        ORDER BY mr.created_at DESC
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
    
    .diagnosis-badge {
      background: linear-gradient(135deg, #ccffcc, #b3ffb3);
      color: #006600;
    }
    
    .text-maroon {
      color: var(--maroon-primary);
    }
    
    /* Modal Styles */
    .modal-backdrop {
      background-color: rgba(0, 0, 0, 0.7);
    }
    
    .modal-content {
      animation: modalFadeIn 0.3s ease-out;
    }
    
    @keyframes modalFadeIn {
      from {
        opacity: 0;
        transform: translateY(-20px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }
    
    /* PDF Viewer Styles */
    .pdf-container {
      height: 80vh;
    }
    
    .pdf-toolbar {
      background: linear-gradient(135deg, #f8f9fa, #e9ecef);
    }
    
    /* Table fixes */
    table {
      border-collapse: collapse;
      width: 100%;
    }
    
    th {
      background: linear-gradient(135deg, #800000, #a00000);
      color: white;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }
    
    th, td {
      padding: 0.75rem 1.5rem;
      text-align: left;
      border-bottom: 1px solid #e5e7eb;
    }
    
    tr:hover {
      background-color: #fff5f5;
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
      <div class="mb-8">
  <h2 class="text-3xl font-bold text-gray-800 flex items-center gap-3">
    <i class="fas fa-file-medical-alt text-maroon"></i> Medical Records
  </h2>
  <p class="text-gray-600 mt-2">
    <?php if ($patient): ?>
      Certified and completed medical records for <?php echo htmlspecialchars($patient['first_name'] ?? ''); ?>
    <?php endif; ?>
  </p>
</div>

      <?php if (count($records) > 0): ?>
        <div class="overflow-x-auto rounded-xl border border-gray-200">
          <table class="min-w-full">
            <thead class="maroon-table-header text-white">
              <tr>
                <th class="px-6 py-4 text-left text-sm font-semibold uppercase tracking-wider">Date</th>
                <th class="px-6 py-4 text-left text-sm font-semibold uppercase tracking-wider">Record Type</th>
                <th class="px-6 py-4 text-left text-sm font-semibold uppercase tracking-wider">Physician/Dentist</th>
                <th class="px-6 py-4 text-left text-sm font-semibold uppercase tracking-wider">Certification Status</th>
                <th class="px-6 py-4 text-left text-sm font-semibold uppercase tracking-wider">Actions</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <?php 
              foreach ($records as $record): 
                // Fetch diagnosis data for this record
                $diagnosis_data = [
                    'has_nurse_data' => false,
                    'has_doctor_data' => false,
                    'nurse_name' => '',
                    'nurse_diagnosis_date' => '',
                    'nurse_note' => '',
                    'doctor_name' => '',
                    'doctor_diagnosis_date' => '',
                    'doctor_note' => '',
                    'record_type' => $record['record_type']
                ];
                
                $record_id = $record['record_id'] ?? $record['id'];
                $physician_name = $record['physician_name'] ?? '';
                
                // Check if it's a dental exam
                if ($record['record_type'] === 'dental_exam') {
                    // Query dental_exams table for dental records to get dentist name
                    $dental_query = "
                        SELECT 
                            dentist_name,
                            remarks,
                            created_at
                        FROM dental_exams 
                        WHERE record_id = ?
                        ORDER BY created_at DESC
                        LIMIT 1
                    ";
                    
                    $dental_stmt = $conn->prepare($dental_query);
                    if ($dental_stmt) {
                        $dental_stmt->bind_param("i", $record_id);
                        $dental_stmt->execute();
                        $dental_result = $dental_stmt->get_result();
                        
                        if ($dental_row = $dental_result->fetch_assoc()) {
                            $diagnosis_data['has_doctor_data'] = true;
                            $diagnosis_data['doctor_name'] = htmlspecialchars($dental_row['dentist_name'] ?? 'Dentist');
                            $diagnosis_data['doctor_diagnosis_date'] = !empty($dental_row['created_at']) ? 
                                date('M d, Y', strtotime($dental_row['created_at'])) : 'Not specified';
                            $diagnosis_data['doctor_note'] = htmlspecialchars($dental_row['remarks'] ?? '');
                            
                            // Use dentist name for physician column if available
                            if (!empty($dental_row['dentist_name'])) {
                                $physician_name = $dental_row['dentist_name'];
                            }
                        }
                        $dental_stmt->close();
                    }
                } else {
                    // Query medical_diagnoses table for non-dental records
                    $diagnosis_query = "
                        SELECT 
                            provider_name,
                            provider_role,
                            diagnosis_type,
                            nurse_note,
                            nurse_diagnosis_date,
                            doctor_note,
                            doctor_diagnosis_date,
                            notes,
                            diagnosis_date
                        FROM medical_diagnoses 
                        WHERE record_id = ?
                        ORDER BY diagnosis_date DESC
                    ";
                    
                    $diagnosis_stmt = $conn->prepare($diagnosis_query);
                    if ($diagnosis_stmt) {
                        $diagnosis_stmt->bind_param("i", $record_id);
                        $diagnosis_stmt->execute();
                        $diagnosis_result = $diagnosis_stmt->get_result();
                        
                        while ($diagnosis = $diagnosis_result->fetch_assoc()) {
                            // Check for nurse data - either by diagnosis_type OR by nurse_note field
                            if ($diagnosis['diagnosis_type'] === 'nurse' || !empty($diagnosis['nurse_note']) || !empty($diagnosis['nurse_diagnosis_date'])) {
                                $diagnosis_data['has_nurse_data'] = true;
                                $diagnosis_data['nurse_name'] = htmlspecialchars($diagnosis['provider_name'] ?? ($diagnosis['provider_role'] === 'nurse' ? 'Nurse' : 'Healthcare Provider'));
                                
                                // Use nurse_diagnosis_date if available, otherwise use general diagnosis_date
                                if (!empty($diagnosis['nurse_diagnosis_date'])) {
                                    $diagnosis_data['nurse_diagnosis_date'] = date('M d, Y', strtotime($diagnosis['nurse_diagnosis_date']));
                                } elseif (!empty($diagnosis['diagnosis_date'])) {
                                    $diagnosis_data['nurse_diagnosis_date'] = date('M d, Y', strtotime($diagnosis['diagnosis_date']));
                                } else {
                                    $diagnosis_data['nurse_diagnosis_date'] = 'Not specified';
                                }
                                
                                // Use nurse_note if available, otherwise use general notes field
                                $diagnosis_data['nurse_note'] = htmlspecialchars(
                                    !empty($diagnosis['nurse_note']) ? $diagnosis['nurse_note'] : 
                                    (!empty($diagnosis['notes']) ? $diagnosis['notes'] : '')
                                );
                            }
                            
                            // Check for doctor data - either by diagnosis_type OR by doctor_note field
                            if ($diagnosis['diagnosis_type'] === 'doctor' || !empty($diagnosis['doctor_note']) || !empty($diagnosis['doctor_diagnosis_date'])) {
                                $diagnosis_data['has_doctor_data'] = true;
                                $diagnosis_data['doctor_name'] = htmlspecialchars($diagnosis['provider_name'] ?? ($diagnosis['provider_role'] === 'doctor' ? 'Doctor' : 'Healthcare Provider'));
                                
                                // Use doctor_diagnosis_date if available, otherwise use general diagnosis_date
                                if (!empty($diagnosis['doctor_diagnosis_date'])) {
                                    $diagnosis_data['doctor_diagnosis_date'] = date('M d, Y', strtotime($diagnosis['doctor_diagnosis_date']));
                                } elseif (!empty($diagnosis['diagnosis_date'])) {
                                    $diagnosis_data['doctor_diagnosis_date'] = date('M d, Y', strtotime($diagnosis['diagnosis_date']));
                                } else {
                                    $diagnosis_data['doctor_diagnosis_date'] = 'Not specified';
                                }
                                
                                // Use doctor_note if available, otherwise use general notes field
                                $diagnosis_data['doctor_note'] = htmlspecialchars(
                                    !empty($diagnosis['doctor_note']) ? $diagnosis['doctor_note'] : 
                                    (!empty($diagnosis['notes']) ? $diagnosis['notes'] : '')
                                );
                                
                                // Use doctor name for physician column if available and not already set
                                if (empty($physician_name) && !empty($diagnosis['provider_name'])) {
                                    $physician_name = $diagnosis['provider_name'];
                                }
                            }
                        }
                        $diagnosis_stmt->close();
                    }
                }
                
                // Encode diagnosis data as JSON for JavaScript
                $diagnosis_json = htmlspecialchars(json_encode($diagnosis_data), ENT_QUOTES, 'UTF-8');
              ?>
              <tr class="maroon-table-row hover:shadow-md transition-all">
                <td class="px-6 py-4 text-gray-900"><?php echo htmlspecialchars($record['examination_date'] ?? ''); ?></td>
                <td class="px-6 py-4 text-gray-900"><?php echo htmlspecialchars($record['record_type_name'] ?? ''); ?></td>
                <td class="px-6 py-4 text-gray-900"><?php echo htmlspecialchars($physician_name); ?></td>
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
                      case 'completed':
                        $badge_class = 'bg-emerald-100 text-emerald-800 border-emerald-200';
                        $status_text = 'Completed';
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
                    
                    if (($status === 'certified' || $status === 'completed') && !empty($record['certified_date'])) {
                      echo '<div class="text-xs text-gray-600 mt-1">' . date('M d, Y', strtotime($record['certified_date'])) . '</div>';
                    }
                  } else {
                    echo '<span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-gray-100 text-gray-800 border border-gray-200">Not Submitted</span>';
                  }
                  ?>
                </td>
                <td class="px-6 py-4">
                  <div class="flex items-center gap-2">
                    <?php if ($record['record_type'] !== 'dental_exam'): ?>
                    <button onclick="openPdfModal('<?php echo $record_id; ?>', '<?php echo $record['record_type']; ?>')" 
                           class="inline-flex items-center gap-1.5 px-4 py-2 maroon-badge rounded-lg hover:shadow-md transition-all text-sm font-semibold"
                           title="View PDF">
                      <i class="fas fa-file-pdf"></i> View PDF
                    </button>
                    <?php endif; ?>
                    <button onclick="openDiagnosisModal(<?php echo $diagnosis_json; ?>)" 
                           class="inline-flex items-center gap-1.5 px-4 py-2 diagnosis-badge rounded-lg hover:shadow-md transition-all text-sm font-semibold"
                           title="View Diagnosis">
                      <i class="fas fa-stethoscope"></i> Diagnosis
                    </button>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        
        <div class="mt-6 text-center text-gray-600 text-sm">
          Showing <?php echo count($records); ?> certified and completed record(s)
        </div>
      <?php else: ?>
        <div class="text-center py-12">
          <div class="w-24 h-24 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
            <i class="fas fa-file-medical text-gray-400 text-3xl"></i>
          </div>
          <h3 class="text-xl font-semibold text-gray-700 mb-2">No Medical Records Found</h3>
          <p class="text-gray-600">No certified or completed medical records are available for this patient.</p>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- PDF Viewer Modal -->
  <div id="pdfModal" class="fixed inset-0 z-50 hidden">
    <div class="modal-backdrop fixed inset-0 bg-black opacity-70"></div>
    
    <div class="fixed inset-0 flex items-center justify-center p-4">
      <div class="modal-content bg-white rounded-2xl shadow-2xl w-full max-w-6xl h-full max-h-[90vh] flex flex-col">
        <!-- Modal Header -->
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 pdf-toolbar rounded-t-2xl">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 maroon-gradient rounded-lg flex items-center justify-center">
              <i class="fas fa-file-pdf text-white"></i>
            </div>
            <div>
              <h3 class="text-xl font-bold text-gray-800" id="modalTitle">Medical Record</h3>
              <p class="text-sm text-gray-600" id="modalSubtitle">Loading PDF...</p>
            </div>
          </div>
          <div class="flex items-center gap-2">
            <button onclick="downloadPdf()" 
                    class="px-4 py-2 bg-green-500 hover:bg-green-600 text-white rounded-lg font-semibold flex items-center gap-2 transition-all">
              <i class="fas fa-download"></i> Download
            </button>
            <button onclick="closePdfModal()" 
                    class="w-10 h-10 flex items-center justify-center text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-lg transition-all">
              <i class="fas fa-times text-xl"></i>
            </button>
          </div>
        </div>
        
        <!-- PDF Viewer Container -->
        <div class="flex-1 overflow-hidden">
          <iframe id="pdfFrame" class="w-full h-full border-0" 
                  frameborder="0"></iframe>
        </div>
        
        <!-- Modal Footer -->
        <div class="px-6 py-4 border-t border-gray-200 pdf-toolbar rounded-b-2xl">
          <div class="flex items-center justify-between text-sm text-gray-600">
            <div class="flex items-center gap-4">
              <div class="flex items-center gap-2">
                <i class="fas fa-info-circle text-maroon"></i>
                <span>Certified Medical Record</span>
              </div>
              <div class="flex items-center gap-2" id="certificationInfo">
                <i class="fas fa-calendar-check text-maroon"></i>
                <span>Certified on: <span id="certifiedDate"><?php echo date('M d, Y'); ?></span></span>
              </div>
            </div>
            <div class="flex items-center gap-2">
              <button onclick="refreshPdf()" 
                      class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 rounded-lg font-medium transition-all">
                <i class="fas fa-redo-alt mr-1"></i> Refresh
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Diagnosis Modal -->
  <div id="diagnosisModal" class="fixed inset-0 z-50 hidden">
    <div class="modal-backdrop fixed inset-0 bg-black opacity-70"></div>
    
    <div class="fixed inset-0 flex items-center justify-center p-4">
      <div class="modal-content bg-white rounded-2xl shadow-2xl w-full max-w-4xl h-full max-h-[90vh] flex flex-col">
        <!-- Modal Header -->
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-green-50 to-emerald-50 rounded-t-2xl">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-gradient-to-r from-green-500 to-emerald-500 rounded-lg flex items-center justify-center">
              <i class="fas fa-stethoscope text-white"></i>
            </div>
            <div>
              <h3 class="text-xl font-bold text-gray-800" id="diagnosisModalTitle">Medical Diagnosis</h3>
              <p class="text-sm text-gray-600" id="diagnosisModalSubtitle">Diagnosis Information</p>
            </div>
          </div>
        </div>
        
        <!-- Diagnosis Content Container -->
        <div class="flex-1 overflow-auto p-6">
          <div class="space-y-8">
            <!-- Nurse's Diagnosis (only show for non-dental records) -->
            <div id="nurseSection" class="bg-gradient-to-r from-blue-50 to-cyan-50 rounded-xl p-6 border border-blue-100">
              <div class="flex items-center gap-3 mb-6">
                <div class="w-12 h-12 bg-gradient-to-r from-blue-500 to-cyan-500 rounded-xl flex items-center justify-center">
                  <i class="fas fa-user-nurse text-white text-lg"></i>
                </div>
                <div>
                  <h4 class="text-lg font-bold text-gray-800">Nurse's Assessment</h4>
                  <p class="text-sm text-gray-600">Initial assessment and observations</p>
                </div>
              </div>
              
              <div class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div class="bg-white rounded-lg p-4 border border-gray-200">
                    <div class="text-sm text-gray-600 mb-1">Nurse's Name</div>
                    <div class="font-semibold text-gray-800" id="nurseName">Loading...</div>
                  </div>
                  <div class="bg-white rounded-lg p-4 border border-gray-200">
                    <div class="text-sm text-gray-600 mb-1">Assessment Date</div>
                    <div class="font-semibold text-gray-800" id="nurseDate">Loading...</div>
                  </div>
                </div>
                
                <div class="bg-white rounded-lg p-4 border border-gray-200">
                  <div class="text-sm text-gray-600 mb-2">Nurse's Note</div>
                  <div class="text-gray-800 min-h-[100px] p-3 bg-gray-50 rounded border border-gray-200" id="nurseNote">
                    <div class="flex items-center justify-center h-full">
                      <div class="text-center">
                        <i class="fas fa-spinner fa-spin text-blue-500 text-2xl mb-2"></i>
                        <p class="text-gray-600">Loading nurse's note...</p>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            
            <!-- Doctor's Diagnosis (show for all records) -->
            <div id="doctorSection" class="bg-gradient-to-r from-green-50 to-emerald-50 rounded-xl p-6 border border-green-100">
              <div class="flex items-center gap-3 mb-6">
                <div class="w-12 h-12 bg-gradient-to-r from-green-500 to-emerald-500 rounded-xl flex items-center justify-center">
                  <i class="fas fa-user-md text-white text-lg"></i>
                </div>
                <div>
                  <h4 class="text-lg font-bold text-gray-800" id="doctorSectionTitle">Doctor's Diagnosis</h4>
                  <p class="text-sm text-gray-600" id="doctorSectionSubtitle">Final diagnosis and recommendations</p>
                </div>
              </div>
              
              <div class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div class="bg-white rounded-lg p-4 border border-gray-200">
                    <div class="text-sm text-gray-600 mb-1" id="doctorNameLabel">Doctor's Name</div>
                    <div class="font-semibold text-gray-800" id="doctorName">Loading...</div>
                  </div>
                  <div class="bg-white rounded-lg p-4 border border-gray-200">
                    <div class="text-sm text-gray-600 mb-1" id="doctorDateLabel">Diagnosis Date</div>
                    <div class="font-semibold text-gray-800" id="doctorDate">Loading...</div>
                  </div>
                </div>
                
                <div class="bg-white rounded-lg p-4 border border-gray-200">
                  <div class="text-sm text-gray-600 mb-2" id="doctorNoteLabel">Doctor's Note</div>
                  <div class="text-gray-800 min-h-[100px] p-3 bg-gray-50 rounded border border-gray-200" id="doctorNote">
                    <div class="flex items-center justify-center h-full">
                      <div class="text-center">
                        <i class="fas fa-spinner fa-spin text-green-500 text-2xl mb-2"></i>
                        <p class="text-gray-600">Loading doctor's note...</p>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            
            <!-- No Data Message -->
            <div id="noDiagnosisData" class="hidden bg-gradient-to-r from-yellow-50 to-amber-50 rounded-xl p-8 border border-yellow-100 text-center">
              <div class="w-16 h-16 bg-gradient-to-r from-yellow-400 to-amber-500 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fas fa-info-circle text-white text-2xl"></i>
              </div>
              <h4 class="text-lg font-bold text-gray-800 mb-2">No Diagnosis Data Available</h4>
                  <p class="text-gray-600">No diagnosis information has been recorded for this medical record.</p>
                  <p class="text-sm text-gray-500 mt-2">Please check back later or contact the healthcare provider.</p>
                </div>
              </div>
            </div>
          </div>
        </div>
        
        <!-- Modal Footer -->
        <div class="px-6 py-4 border-t border-gray-200 bg-gradient-to-r from-green-50 to-emerald-50 rounded-b-2xl">
          <div class="flex items-center justify-between text-sm text-gray-600">
            <div class="flex items-center gap-2">
              <i class="fas fa-info-circle text-green-600"></i>
              <span>Diagnosis information for medical record</span>
            </div>
            <button onclick="closeDiagnosisModal()" 
                    class="px-4 py-2 bg-gradient-to-r from-green-500 to-emerald-500 hover:from-green-600 hover:to-emerald-600 text-white rounded-lg font-semibold transition-all">
              Close
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script>
   

    // PDF Modal Functions
    let currentRecordId = '';
    let currentRecordType = '';

    function openPdfModal(recordId, recordType) {
      currentRecordId = recordId;
      currentRecordType = recordType;
      
      // Set modal title based on record type
      const recordTypeNames = {
        'history_form': 'Medical History Form',
        'dental_exam': 'Dental Examination',
        'medical_exam': 'Medical Examination'
      };
      
      const title = recordTypeNames[recordType] || 'Medical Record';
      document.getElementById('modalTitle').textContent = title;
      document.getElementById('modalSubtitle').textContent = 'Loading PDF document...';
      
      // Generate PDF URL
      const pdfUrl = `../records/generate_certified_pdf.php?id=${recordId}&type=${recordType}`;
      
      // Load PDF in iframe
      const pdfFrame = document.getElementById('pdfFrame');
      pdfFrame.src = pdfUrl;
      
      // Show modal
      document.getElementById('pdfModal').classList.remove('hidden');
      document.body.style.overflow = 'hidden';
      
      // Update certification date
      document.getElementById('certifiedDate').textContent = new Date().toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
      });
    }

    function closePdfModal() {
      document.getElementById('pdfModal').classList.add('hidden');
      document.body.style.overflow = 'auto';
      
      // Clear iframe source
      const pdfFrame = document.getElementById('pdfFrame');
      pdfFrame.src = '';
    }

    function downloadPdf() {
      // Open download in new tab/window
      const downloadUrl = `../records/generate_certified_pdf.php?id=${currentRecordId}&type=${currentRecordType}`;
      window.open(downloadUrl, '_blank');
    }

    function refreshPdf() {
      const pdfFrame = document.getElementById('pdfFrame');
      const currentSrc = pdfFrame.src;
      pdfFrame.src = '';
      setTimeout(() => {
        pdfFrame.src = currentSrc;
      }, 100);
    }

    // Diagnosis Modal Functions
    function openDiagnosisModal(diagnosisData) {
      console.log('Diagnosis Data:', diagnosisData); // For debugging
      
      // Set modal title based on record type
      const recordType = diagnosisData.record_type || '';
      let modalTitle = 'Medical Diagnosis';
      if (recordType === 'dental_exam') {
        modalTitle = 'Dental Examination Diagnosis';
      }
      document.getElementById('diagnosisModalTitle').textContent = modalTitle;
      document.getElementById('diagnosisModalSubtitle').textContent = 'Diagnosis Information';
      
      // Hide no data message initially
      document.getElementById('noDiagnosisData').classList.add('hidden');
      
      // Show/hide sections based on record type
      const nurseSection = document.getElementById('nurseSection');
      const doctorSection = document.getElementById('doctorSection');
      
      if (recordType === 'dental_exam') {
        // For dental exams, hide nurse section and adjust doctor section labels
        nurseSection.style.display = 'none';
        doctorSection.style.display = 'block';
        document.getElementById('doctorSectionTitle').textContent = 'Dentist\'s Assessment';
        document.getElementById('doctorSectionSubtitle').textContent = 'Dental examination findings and recommendations';
        document.getElementById('doctorNameLabel').textContent = 'Dentist\'s Name';
        document.getElementById('doctorDateLabel').textContent = 'Examination Date';
        document.getElementById('doctorNoteLabel').textContent = 'Dentist\'s Remarks';
      } else {
        // For non-dental records, show both sections with default labels
        nurseSection.style.display = 'block';
        doctorSection.style.display = 'block';
        document.getElementById('doctorSectionTitle').textContent = 'Doctor\'s Diagnosis';
        document.getElementById('doctorSectionSubtitle').textContent = 'Final diagnosis and recommendations';
        document.getElementById('doctorNameLabel').textContent = 'Doctor\'s Name';
        document.getElementById('doctorDateLabel').textContent = 'Diagnosis Date';
        document.getElementById('doctorNoteLabel').textContent = 'Doctor\'s Note';
      }
      
      // Show modal
      document.getElementById('diagnosisModal').classList.remove('hidden');
      document.body.style.overflow = 'hidden';
      
      // Process diagnosis data
      processDiagnosisData(diagnosisData, recordType);
    }

    function closeDiagnosisModal() {
      document.getElementById('diagnosisModal').classList.add('hidden');
      document.body.style.overflow = 'auto';
    }

    function processDiagnosisData(data, recordType) {
      // Check if we have any diagnosis data
      const hasData = data.has_nurse_data || data.has_doctor_data;
      
      if (hasData) {
        // Show nurse data if available and not dental exam
        if (recordType !== 'dental_exam' && data.has_nurse_data) {
          document.getElementById('nurseName').textContent = data.nurse_name || 'Nurse';
          document.getElementById('nurseDate').textContent = data.nurse_diagnosis_date || 'Not specified';
          if (data.nurse_note && data.nurse_note.trim() !== '') {
            document.getElementById('nurseNote').innerHTML = `<div class="whitespace-pre-wrap">${data.nurse_note}</div>`;
          } else {
            document.getElementById('nurseNote').innerHTML = '<div class="text-gray-500 italic">No nurse note recorded.</div>';
          }
        } else if (recordType !== 'dental_exam') {
          document.getElementById('nurseName').textContent = 'No data';
          document.getElementById('nurseDate').textContent = 'Not available';
          document.getElementById('nurseNote').innerHTML = '<div class="text-gray-500 italic">No nurse assessment recorded.</div>';
        }
        
        // Show doctor/dentist data if available
        if (data.has_doctor_data) {
          if (recordType === 'dental_exam') {
            document.getElementById('doctorName').textContent = data.doctor_name || 'Dentist';
          } else {
            document.getElementById('doctorName').textContent = data.doctor_name || 'Doctor';
          }
          document.getElementById('doctorDate').textContent = data.doctor_diagnosis_date || 'Not specified';
          if (data.doctor_note && data.doctor_note.trim() !== '') {
            document.getElementById('doctorNote').innerHTML = `<div class="whitespace-pre-wrap">${data.doctor_note}</div>`;
          } else {
            document.getElementById('doctorNote').innerHTML = '<div class="text-gray-500 italic">No notes recorded.</div>';
          }
        } else {
          if (recordType === 'dental_exam') {
            document.getElementById('doctorName').textContent = 'No data';
          } else {
            document.getElementById('doctorName').textContent = 'No data';
          }
          document.getElementById('doctorDate').textContent = 'Not available';
          document.getElementById('doctorNote').innerHTML = '<div class="text-gray-500 italic">No diagnosis recorded.</div>';
        }
        
        // Hide no data message
        document.getElementById('noDiagnosisData').classList.add('hidden');
      } else {
        // Show no data message
        document.getElementById('noDiagnosisData').classList.remove('hidden');
        
        // Hide both sections
        nurseSection.style.display = 'none';
        doctorSection.style.display = 'none';
      }
    }

    // Close modals with Escape key
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') {
        if (!document.getElementById('pdfModal').classList.contains('hidden')) {
          closePdfModal();
        }
        if (!document.getElementById('diagnosisModal').classList.contains('hidden')) {
          closeDiagnosisModal();
        }
      }
    });

    // Close modals when clicking on backdrop
    document.getElementById('pdfModal').addEventListener('click', function(e) {
      if (e.target.classList.contains('modal-backdrop')) {
        closePdfModal();
      }
    });

    document.getElementById('diagnosisModal').addEventListener('click', function(e) {
      if (e.target.classList.contains('modal-backdrop')) {
        closeDiagnosisModal();
      }
    });

    // Handle iframe load events for PDF modal
    document.getElementById('pdfFrame').addEventListener('load', function() {
      document.getElementById('modalSubtitle').textContent = 'PDF loaded successfully';
    });

    document.getElementById('pdfFrame').addEventListener('error', function() {
      document.getElementById('modalSubtitle').textContent = 'Error loading PDF. Try downloading instead.';
    });
  </script>
</body>
</html>