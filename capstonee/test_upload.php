<?php
// Test script for picture upload functionality
include __DIR__ . '/config/database.php';

// Simulate file upload
$test_file = [
    'name' => 'test_image.jpg',
    'type' => 'image/jpeg',
    'tmp_name' => __DIR__ . '/test_image.jpg',
    'error' => UPLOAD_ERR_OK,
    'size' => 1024
];

// Create a test image file
file_put_contents(__DIR__ . '/test_image.jpg', 'fake image content');

// Simulate POST data
$_POST = [
    'student_id' => 'TEST123',
    'first_name' => 'Test',
    'last_name' => 'User',
    'date_of_birth' => '2000-01-01',
    'sex' => 'Male',
    'program' => 'Test Program'
];

$_FILES = [
    'picture' => $test_file
];

// Include the form processing logic
include __DIR__ . '/modules/user_medical_form.php';

// Check if directory was created
$upload_dir = __DIR__ . '/uploads/pictures/';
if (is_dir($upload_dir)) {
    echo "✓ Upload directory created successfully\n";
} else {
    echo "✗ Upload directory not created\n";
}

// Check if test file exists
if (file_exists($upload_dir . 'test_image.jpg')) {
    echo "✓ Test file uploaded successfully\n";
} else {
    echo "✗ Test file not uploaded\n";
}

// Clean up
unlink(__DIR__ . '/test_image.jpg');
array_map('unlink', glob($upload_dir . '*'));
rmdir($upload_dir);
rmdir(__DIR__ . '/uploads');

echo "Test completed\n";
?>
