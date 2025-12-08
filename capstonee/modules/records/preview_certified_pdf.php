<?php
session_start();
include '../../config/database.php';

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

// Only doctors/physicians can preview certified PDFs
$user_role = $_SESSION['role'] ?? '';
if (!in_array($user_role, ['doctor', 'physician', 'admin', 'dentist'])) {
    header("Location: ../../dashboard.php");
    exit();
}

$type = $_GET['type'] ?? '';
$id = $_GET['id'] ?? '';

// Map form types
$form_map = [
    'history_form' => ['table' => 'history_forms', 'record_type' => 'history_form'],
    'medical_form' => ['table' => 'medical_exams', 'record_type' => 'medical_exam'],
    'medical_exam' => ['table' => 'medical_exams', 'record_type' => 'medical_exam'],
    'dental_form'  => ['table' => 'dental_exams', 'record_type' => 'dental_exam'],
    'dental_exam'  => ['table' => 'dental_exams', 'record_type' => 'dental_exam']
];

// Fetch record data
$record = null;
if ($type && $id && isset($form_map[$type])) {
    $table = $form_map[$type]['table'];
    
    if ($type === 'medical_form' || $type === 'medical_exam') {
        $query = "
            SELECT me.*, mr.*, p.*, mr.id as record_id
            FROM medical_exams me
            JOIN medical_records mr ON me.record_id = mr.id
            JOIN patients p ON mr.patient_id = p.id
            WHERE me.record_id = ?
        ";
    } else {
        $query = "
            SELECT f.*, mr.*, p.*, mr.id as record_id
            FROM $table f
            JOIN medical_records mr ON f.record_id = mr.id
            JOIN patients p ON mr.patient_id = p.id
            WHERE f.record_id = ?
        ";
    }
    
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $record = $result->fetch_assoc();
        $stmt->close();
    }
}

if (!$record) {
    die("Record not found or insufficient permissions.");
}

// Check if record is certified
if (!isset($record['verification_status']) || 
    ($record['verification_status'] !== 'for_certification' && 
     $record['verification_status'] !== 'certified')) {
    die("This record is not yet certified for printing.");
}

// Function to format date
function formatDate($date, $format = 'm/d/Y') {
    if (empty($date) || $date === '0000-00-00') return '';
    $dateObj = new DateTime($date);
    return $dateObj->format($format);
}

// Function to calculate age
function calculateAge($birthDate) {
    if (empty($birthDate) || $birthDate === '0000-00-00') return '';
    $birth = new DateTime($birthDate);
    $today = new DateTime();
    $age = $today->diff($birth)->y;
    return $age;
}

// Function to get checkbox display
function displayCheckbox($value) {
    return $value == 1 ? '✓' : '□';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preview Certified PDF - BSU Clinic</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        @media print {
            body { margin: 0; padding: 0; background: white; }
            .no-print { display: none; }
            .preview-container { 
                width: 210mm; 
                min-height: 297mm; 
                margin: 0; 
                padding: 0;
                box-shadow: none;
                border: none;
            }
            .preview-content { font-size: 12pt; }
        }
        .preview-container {
            width: 210mm;
            min-height: 297mm;
            margin: 20px auto;
            background: white;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            font-family: Arial, sans-serif;
            position: relative;
        }
        .preview-content {
            font-size: 11pt;
            line-height: 1.5;
            position: relative;
            z-index: 1;
        }
        .template-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0.3;
            z-index: 0;
            pointer-events: none;
        }
        .field-overlay {
            position: absolute;
            background: rgba(255, 255, 255, 0.8);
            border: 1px dashed #666;
            padding: 2px 5px;
            z-index: 2;
            font-size: 10pt;
            min-width: 50px;
        }
        .checkbox-overlay {
            position: absolute;
            width: 15px;
            height: 15px;
            border: 1px solid #000;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            z-index: 2;
        }
    </style>
</head>
<body class="bg-gray-100">
    <div class="container mx-auto p-4 no-print">
        <div class="flex justify-between items-center mb-6">
            <a href="view_record.php?type=<?= urlencode($type) ?>&id=<?= urlencode($id) ?>"
               class="inline-flex items-center gap-2 bg-orange-500 text-white font-semibold px-4 py-2 rounded-lg shadow transition-all">
               <i class="bi bi-arrow-left-circle"></i> Back to Record
            </a>
            <h2 class="text-2xl font-bold text-orange-700">Preview Filled PDF</h2>
            <div class="flex gap-2">
                <button onclick="window.print()" 
                        class="bg-blue-500 text-white px-4 py-2 rounded-lg shadow font-semibold hover:bg-blue-600 transition-all">
                    <i class="bi bi-printer"></i> Print Preview
                </button>
                <a href="generate_certified_pdf.php?type=<?= urlencode($type) ?>&id=<?= urlencode($id) ?>" 
                   target="_blank"
                   class="bg-green-500 text-white px-4 py-2 rounded-lg shadow font-semibold hover:bg-green-600 transition-all">
                    <i class="bi bi-download"></i> Download PDF
                </a>
            </div>
        </div>
        
        <div class="bg-white rounded-lg shadow-lg p-4 mb-4 no-print">
            <p class="text-gray-600">
                <i class="bi bi-info-circle text-blue-500"></i>
                This preview shows how the data will appear on the PDF template. Gray background represents the template, white boxes show where data will be placed.
            </p>
        </div>
    </div>

    <!-- PDF Preview Container -->
    <div class="preview-container">
        <!-- Template overlay (gray background) -->
        <div class="template-overlay bg-gray-200"></div>
        
        <!-- Data fields overlay -->
        <div class="preview-content">
            <?php if ($type === 'medical_form' || $type === 'medical_exam'): ?>
                <!-- Medical Exam Template Fields -->
                <div class="field-overlay" style="top: 50px; left: 30px;">
                    <?= htmlspecialchars(ucwords(strtolower($record['last_name'] ?? ''))) ?>
                </div>
                <div class="field-overlay" style="top: 50px; left: 90px;">
                    <?= htmlspecialchars(ucwords(strtolower($record['first_name'] ?? ''))) ?>
                </div>
                <div class="field-overlay" style="top: 50px; left: 150px;">
                    <?= htmlspecialchars(ucwords(strtolower($record['middle_name'] ?? ''))) ?>
                </div>
                <div class="field-overlay" style="top: 60px; left: 30px;">
                    <?= formatDate($record['date_of_birth'] ?? '', 'm/d/Y') ?>
                </div>
                <div class="field-overlay" style="top: 60px; left: 90px;">
                    <?= calculateAge($record['date_of_birth'] ?? '') ?>
                </div>
                <div class="field-overlay" style="top: 60px; left: 130px;">
                    <?= htmlspecialchars($record['sex'] ?? '') ?>
                </div>
                <div class="field-overlay" style="top: 60px; left: 160px;">
                    <?= htmlspecialchars(ucwords(strtolower($record['civil_status'] ?? ''))) ?>
                </div>
                
                <!-- Add more fields as needed based on your template -->
                
                <!-- Certification Section -->
                <div class="field-overlay" style="top: 180px; left: 30px;">
                    <?= htmlspecialchars(ucwords(strtolower($record['first_name'] . ' ' . $record['last_name']))) ?>
                </div>
                <div class="field-overlay" style="top: 190px; left: 30px;">
                    <?= $record['certified_weight'] ?? $record['weight'] ?? '' ?>
                </div>
                <div class="field-overlay" style="top: 190px; left: 80px;">
                    <?= $record['certified_height'] ?? $record['height'] ?? '' ?>
                </div>
                <div class="field-overlay" style="top: 190px; left: 130px;">
                    <?= htmlspecialchars(ucwords(strtolower($record['certified_civil_status'] ?? $record['civil_status'] ?? ''))) ?>
                </div>
                <div class="field-overlay" style="top: 190px; left: 180px;">
                    <?= formatDate($record['certified_exam_date'] ?? $record['examination_date'] ?? '', 'm/d/Y') ?>
                </div>
                
                <!-- Classification Checkboxes -->
                <div class="checkbox-overlay" style="top: 210px; left: 30px;">
                    <?= displayCheckbox($record['classification_a'] ?? 0) ?>
                </div>
                <div class="checkbox-overlay" style="top: 220px; left: 30px;">
                    <?= displayCheckbox($record['classification_b'] ?? 0) ?>
                </div>
                <div class="checkbox-overlay" style="top: 230px; left: 30px;">
                    <?= displayCheckbox($record['classification_c'] ?? 0) ?>
                </div>
                <div class="checkbox-overlay" style="top: 240px; left: 30px;">
                    <?= displayCheckbox($record['classification_d'] ?? 0) ?>
                </div>
                
                <!-- Physician Information -->
                <div class="field-overlay" style="top: 250px; left: 30px;">
                    <?php
                    $physicianName = !empty($record['physician_name']) ? $record['physician_name'] : 'MARSON KIM L. PERMENTILLA M.D.';
                    echo htmlspecialchars($physicianName);
                    ?>
                </div>
                <div class="field-overlay" style="top: 260px; left: 30px;">
                    <?php
                    $licenseNo = !empty($record['license_no']) ? $record['license_no'] : '0169430';
                    echo htmlspecialchars($licenseNo);
                    ?>
                </div>
                <div class="field-overlay" style="top: 260px; left: 100px;">
                    <?= formatDate($record['physician_date'] ?? date('Y-m-d'), 'm/d/Y') ?>
                </div>
                
            <?php elseif ($type === 'history_form'): ?>
                <!-- History Form Template Fields -->
                <div class="field-overlay" style="top: 50px; left: 50px;">
                    <?= htmlspecialchars(ucwords(strtolower($record['first_name'] . ' ' . $record['last_name']))) ?>
                </div>
                <div class="field-overlay" style="top: 50px; left: 150px;">
                    <?= htmlspecialchars($record['program'] ?? '') ?>
                </div>
                <div class="field-overlay" style="top: 60px; left: 50px;">
                    <?= formatDate($record['date_of_birth'] ?? '', 'm/d/Y') ?>
                </div>
                <div class="field-overlay" style="top: 60px; left: 120px;">
                    <?= calculateAge($record['date_of_birth'] ?? '') ?>
                </div>
                <div class="field-overlay" style="top: 60px; left: 150px;">
                    <?= htmlspecialchars($record['sex'] ?? '') ?>
                </div>
                <div class="field-overlay" style="top: 60px; left: 180px;">
                    <?= htmlspecialchars($record['sports_event'] ?? '') ?>
                </div>
                
                <!-- Medical History Checkboxes -->
                <div class="checkbox-overlay" style="top: 100px; left: 20px;">
                    <?= displayCheckbox($record['denied_participation'] ?? 0) ?>
                </div>
                <div class="checkbox-overlay" style="top: 110px; left: 20px;">
                    <?= displayCheckbox($record['asthma'] ?? 0) ?>
                </div>
                <div class="checkbox-overlay" style="top: 120px; left: 20px;">
                    <?= displayCheckbox($record['seizure_disorder'] ?? 0) ?>
                </div>
                <!-- Add more checkboxes as needed -->
                
                <!-- Certification -->
                <div class="field-overlay" style="top: 270px; left: 50px;">
                    <?= formatDate($record['student_signature_date'] ?? date('Y-m-d'), 'm/d/Y') ?>
                </div>
                <div class="field-overlay" style="top: 270px; left: 150px;">
                    <?php
                    $physicianName = !empty($record['physician_name']) ? $record['physician_name'] : 'MARSON KIM L. PERMENTILLA M.D.';
                    echo htmlspecialchars($physicianName);
                    ?>
                </div>
                <div class="field-overlay" style="top: 280px; left: 150px;">
                    <?php
                    $licenseNo = !empty($record['license_no']) ? $record['license_no'] : '0169430';
                    echo htmlspecialchars($licenseNo);
                    ?>
                </div>
                <div class="field-overlay" style="top: 280px; left: 200px;">
                    <?= formatDate($record['physician_date'] ?? date('Y-m-d'), 'm/d/Y') ?>
                </div>
            <?php endif; ?>
            
            <!-- Generation timestamp -->
            <div class="field-overlay" style="top: 280px; left: 10px; font-size: 8pt; background: rgba(255,255,255,0.9);">
                Generated: <?= date('Y-m-d H:i:s') ?>
            </div>
        </div>
    </div>
    
    <!-- Instructions -->
    <div class="container mx-auto p-4 no-print">
        <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 mb-4">
            <div class="flex">
                <div class="flex-shrink-0">
                    <i class="bi bi-exclamation-triangle text-yellow-400 text-xl"></i>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-yellow-800">Important Notes</h3>
                    <div class="mt-2 text-sm text-yellow-700">
                        <p>1. The exact positioning of fields may vary slightly in the actual PDF.</p>
                        <p>2. Make sure all required fields are filled before generating the final PDF.</p>
                        <p>3. Click "Download PDF" to generate the actual filled PDF file.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Auto-print if requested
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('autoprint') === '1') {
            window.print();
        }
        
        // Add click handlers to fields for debugging
        document.querySelectorAll('.field-overlay, .checkbox-overlay').forEach(field => {
            field.addEventListener('click', function(e) {
                console.log('Field clicked:', {
                    text: this.textContent,
                    position: {
                        top: this.style.top,
                        left: this.style.left
                    }
                });
            });
        });
    </script>
</body>
</html>