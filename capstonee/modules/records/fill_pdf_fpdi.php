<?php
session_start();
include '../../config/database.php';

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$type = $_GET['type'] ?? '';
$id = $_GET['id'] ?? '';

// Only process history forms
if ($type !== 'history_form') {
    die("This demo only works with history forms.");
}

// Fetch the record
$form_map = ['history_form' => ['table' => 'history_forms', 'record_type' => 'history_form']];
$table = $form_map[$type]['table'];

$query = "
    SELECT f.*, mr.*, p.*, mr.id as record_id
    FROM $table f
    JOIN medical_records mr ON f.record_id = mr.id
    JOIN patients p ON mr.patient_id = p.id
    WHERE f.record_id = ?
";

$stmt = $conn->prepare($query);
if ($stmt) {
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $record = $result->fetch_assoc();
    $stmt->close();
}

if (!$record) {
    die("No record found.");
}

// Calculate age
$age = 'N/A';
if (!empty($record['date_of_birth'])) {
    $birthDate = new DateTime($record['date_of_birth']);
    $today = new DateTime();
    $age = $today->diff($birthDate)->y;
}

// Prepare data for the 7 textboxes
$pdf_data = [
    'name' => ucwords(strtolower($record['first_name'] . ' ' . $record['last_name'])),
    'program' => $record['program'],
    'date_of_birth' => $record['date_of_birth'],
    'date_of_examination' => $record['examination_date'] ?? date('Y-m-d'),
    'sex' => $record['sex'],
    'age' => (string)$age,
    'sports_event' => $record['sports_event'] ?? 'Not specified'
];

// Load FPDI
require_once '../../vendor/autoload.php';

use setasign\Fpdi\Fpdi;

// Clear any output
ob_clean();

try {
    // Initialize FPDI
    $pdf = new Fpdi();
    
    // Set the source file (your converted PDF)
    $template_path = __DIR__ . '/medical_history_form_template.pdf';
    
    if (!file_exists($template_path)) {
        die("Template PDF not found. Make sure 'medical_history_form_template.pdf' is in the same directory.");
    }
    
    // Get number of pages
    $pageCount = $pdf->setSourceFile($template_path);
    
    // Import each page
    for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
        // Import page
        $templateId = $pdf->importPage($pageNo);
        
        // Get the size of the template
        $size = $pdf->getTemplateSize($templateId);
        
        // Add a page with the same orientation and size
        $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
        
        // Use the imported page
        $pdf->useTemplate($templateId);
        
        // Set font for adding text
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->SetTextColor(0, 0, 0); // Black
        
        // ============================================
        // IMPORTANT: ADJUST THESE COORDINATES!
        // ============================================
        // You need to find the exact coordinates for each field
        // Run the coordinate finder script below
        $coordinates = [
            'name' => ['x' => 81, 'y' => 237],   // ← CHANGE THESE!
            'program' => ['x' => 50, 'y' => 120], // ← CHANGE THESE!
            'date_of_birth' => ['x' => 50, 'y' => 140],
            'date_of_examination' => ['x' => 50, 'y' => 160],
            'sex' => ['x' => 50, 'y' => 180],
            'age' => ['x' => 50, 'y' => 200],
            'sports_event' => ['x' => 50, 'y' => 220]
        ];
        
        // Fill each field
        foreach ($pdf_data as $field => $value) {
            if (isset($coordinates[$field])) {
                $x = $coordinates[$field]['x'];
                $y = $coordinates[$field]['y'];
                
                // Set position and write text
                $pdf->SetXY($x, $y);
                $pdf->Write(0, $value);
            }
        }
    }
    
    // Output PDF inline
    $filename = 'medical_history_' . $record['first_name'] . '_' . $record['last_name'] . '.pdf';
    $pdf->Output('I', $filename);
    
} catch (Exception $e) {
    // Clear any buffered output
    ob_clean();
    
    // Show detailed error
    echo "<h2>FPDI Error</h2>";
    echo "<p><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    
    // Check for compression error
    if (strpos($e->getMessage(), 'compression technique') !== false) {
        echo "<h3>Compression Issue Detected</h3>";
        echo "<p>Your PDF still uses unsupported compression. Try:</p>";
        echo "<ol>";
        echo "<li>Open in Adobe Acrobat Pro</li>";
        echo "<li>Go to File → Save As Other → Reduced Size PDF</li>";
        echo "<li>Choose 'Acrobat 4.0 (PDF 1.3) Compatibility'</li>";
        echo "<li>Save with a new name</li>";
        echo "<li>Replace your template with the new file</li>";
        echo "</ol>";
    }
    
    // Show file info
    echo "<h3>File Information</h3>";
    if (file_exists($template_path)) {
        echo "<p>File exists: Yes</p>";
        echo "<p>File size: " . filesize($template_path) . " bytes</p>";
        
        // Check first few bytes
        $handle = fopen($template_path, 'r');
        $first_bytes = fread($handle, 100);
        fclose($handle);
        
        echo "<p>First 100 bytes: <pre>" . htmlspecialchars($first_bytes) . "</pre></p>";
    } else {
        echo "<p>File does not exist: " . htmlspecialchars($template_path) . "</p>";
    }
}

$conn->close();
?>