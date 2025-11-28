<?php
include 'config/database.php';
session_start();

// Simulate a logged-in user
$_SESSION['user_id'] = 2; // Assuming user ID 2 exists

echo '=== Testing Profile Update Logic ===' . PHP_EOL;

// Simulate form data
$form_data = [
    'full_name' => 'John Michael Smith',
    'email' => 'john.smith@example.com',
    'contact_number' => '09123456789',
    'department' => 'Computer Science',
    'year_level' => '3rd Year',
    'address' => '123 Test Street',
    'birthdate' => '2000-01-01',
    'gender' => 'Male'
];

echo 'Form data: ' . json_encode($form_data) . PHP_EOL;

// Parse full name
function parseFullName($full_name) {
    $name_parts = explode(' ', trim($full_name));
    $first_name = $name_parts[0] ?? '';
    $middle_name = '';
    $last_name = '';

    if (count($name_parts) > 2) {
        $middle_name = $name_parts[1];
        $last_name = implode(' ', array_slice($name_parts, 2));
    } elseif (count($name_parts) > 1) {
        $last_name = $name_parts[1];
    }

    return [
        'first_name' => $first_name,
        'middle_name' => $middle_name,
        'last_name' => $last_name
    ];
}

$parsed_name = parseFullName($form_data['full_name']);
echo 'Parsed name: ' . json_encode($parsed_name) . PHP_EOL;

// Update user_details
$user_id = $_SESSION['user_id'];
$update_query = $conn->prepare("UPDATE user_details SET full_name = ?, contact_number = ?, department = ?, year_level = ?, address = ?, birthdate = ?, gender = ?, updated_at = NOW() WHERE user_id = ?");
$update_query->bind_param('sssssssi', $form_data['full_name'], $form_data['contact_number'], $form_data['department'], $form_data['year_level'], $form_data['address'], $form_data['birthdate'], $form_data['gender'], $user_id);

if ($update_query->execute()) {
    echo 'user_details updated successfully' . PHP_EOL;
} else {
    echo 'Failed to update user_details: ' . $update_query->error . PHP_EOL;
}
$update_query->close();

// Check if patient exists
$check_patient = $conn->prepare("SELECT id FROM patients WHERE student_id = ?");
$check_patient->bind_param('s', $user_id);
$check_patient->execute();
$result = $check_patient->get_result();

if ($result->num_rows > 0) {
    // Update existing patient
    $patient_id = $result->fetch_assoc()['id'];
    $update_patient = $conn->prepare("UPDATE patients SET first_name = ?, middle_name = ?, last_name = ?, contact_number = ?, department = ?, year_level = ?, address = ?, birthdate = ?, gender = ? WHERE id = ?");
    $update_patient->bind_param('sssssssssi', $parsed_name['first_name'], $parsed_name['middle_name'], $parsed_name['last_name'], $form_data['contact_number'], $form_data['department'], $form_data['year_level'], $form_data['address'], $form_data['birthdate'], $form_data['gender'], $patient_id);

    if ($update_patient->execute()) {
        echo 'Patient updated successfully' . PHP_EOL;
    } else {
        echo 'Failed to update patient: ' . $update_patient->error . PHP_EOL;
    }
    $update_patient->close();
} else {
    // Create new patient
    $insert_patient = $conn->prepare("INSERT INTO patients (student_id, first_name, middle_name, last_name, date_of_birth, sex, contact_number, address, program, year_level) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $insert_patient->bind_param('ssssssssss', $user_id, $parsed_name['first_name'], $parsed_name['middle_name'], $parsed_name['last_name'], $form_data['birthdate'], $form_data['gender'], $form_data['contact_number'], $form_data['address'], $form_data['department'], $form_data['year_level']);

    if ($insert_patient->execute()) {
        echo 'Patient created successfully' . PHP_EOL;
    } else {
        echo 'Failed to create patient: ' . $insert_patient->error . PHP_EOL;
    }
    $insert_patient->close();
}

$check_patient->close();

// Verify the updates
echo PHP_EOL . '=== Verification ===' . PHP_EOL;

$user_query = $conn->prepare("SELECT * FROM user_details WHERE user_id = ?");
$user_query->bind_param('i', $user_id);
$user_query->execute();
$user_result = $user_query->get_result();
if ($user_result->num_rows > 0) {
    echo 'Updated user_details: ' . json_encode($user_result->fetch_assoc()) . PHP_EOL;
}
$user_query->close();

$patient_query = $conn->prepare("SELECT * FROM patients WHERE student_id = ?");
$patient_query->bind_param('s', $user_id);
$patient_query->execute();
$patient_result = $patient_query->get_result();
if ($patient_result->num_rows > 0) {
    echo 'Updated patient: ' . json_encode($patient_result->fetch_assoc()) . PHP_EOL;
}
$patient_query->close();

$conn->close();
?>
