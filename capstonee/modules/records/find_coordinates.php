<?php
require_once '../../vendor/autoload.php';

use setasign\Fpdi\Fpdi;

// Check if template exists
$template_path = __DIR__ . '/medical_history_form_template.pdf';
if (!file_exists($template_path)) {
    die("Template PDF not found. Place 'medical_history_form_template.pdf' in the same directory.");
}

try {
    $pdf = new Fpdi();
    
    // Set source file
    $pageCount = $pdf->setSourceFile($template_path);
    
    // Import first page
    $templateId = $pdf->importPage(1);
    $size = $pdf->getTemplateSize($templateId);
    
    // Add page
    $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
    
    // Use template
    $pdf->useTemplate($templateId);
    
    // Add coordinate grid (red lines and numbers)
    $pdf->SetDrawColor(255, 0, 0); // Red
    $pdf->SetTextColor(255, 0, 0); // Red
    $pdf->SetFont('Helvetica', '', 8);
    
    // Draw grid every 10mm with labels every 20mm
    for ($x = 0; $x <= $size['width']; $x += 10) {
        // Vertical line
        $pdf->Line($x, 0, $x, $size['height']);
        
        // Label every 20mm
        if ($x % 20 == 0) {
            for ($y = 0; $y <= $size['height']; $y += 20) {
                $pdf->SetXY($x + 1, $y + 1);
                $pdf->Write(0, "($x,$y)");
            }
        }
    }
    
    for ($y = 0; $y <= $size['height']; $y += 10) {
        // Horizontal line
        $pdf->Line(0, $y, $size['width'], $y);
    }
    
    // Add instructions
    $pdf->SetTextColor(0, 0, 255); // Blue
    $pdf->SetFont('Helvetica', 'B', 12);
    $pdf->SetXY(10, 10);
    $pdf->Write(0, 'HOW TO FIND COORDINATES FOR YOUR PDF FIELDS:');
    
    $pdf->SetTextColor(0, 0, 0); // Black
    $pdf->SetFont('Helvetica', '', 10);
    
    $instructions = [
        '1. Print this PDF with the grid',
        '2. Print your original PDF form',
        '3. Place them on top of each other (hold up to light)',
        '4. For each textbox in your form, note the grid coordinates',
        '5. Update the coordinates in fill_pdf_fpdi.php',
        '',
        'EXAMPLE:',
        'If "Name" field is at intersection of line 45 (X) and 95 (Y)',
        'Change: \'name\' => [\'x\' => 45, \'y\' => 95]',
        '',
        'TIP: FPDI coordinates start from TOP-LEFT corner',
        'X increases to the right, Y increases downward'
    ];
    
    $y_pos = 25;
    foreach ($instructions as $line) {
        $pdf->SetXY(15, $y_pos);
        $pdf->Write(0, $line);
        $y_pos += 6;
    }
    
    // Output
    $pdf->Output('I', 'coordinate_finder.pdf');
    
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>