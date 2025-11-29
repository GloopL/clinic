<?php
include 'config/database.php';
echo '=== Testing Name Parsing Logic ===' . PHP_EOL;

// Test name parsing function
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

// Test cases
$test_names = [
    'John Doe',
    'John Michael Doe',
    'Jane Smith Johnson',
    'Single',
    'First Last Middle Extra'
];

foreach ($test_names as $name) {
    $parsed = parseFullName($name);
    echo "Input: '$name' -> First: '{$parsed['first_name']}', Middle: '{$parsed['middle_name']}', Last: '{$parsed['last_name']}'" . PHP_EOL;
}

echo PHP_EOL . '=== Testing Patient Creation Logic ===' . PHP_EOL;

// Test patient creation from user_details
$user_details = $conn->query('SELECT * FROM user_details LIMIT 1');
if ($user_details && $user_details->num_rows > 0) {
    $details = $user_details->fetch_assoc();
    echo 'Sample user_details: ' . json_encode($details) . PHP_EOL;

    $parsed_name = parseFullName($details['full_name']);
    echo 'Parsed name: ' . json_encode($parsed_name) . PHP_EOL;
} else {
    echo 'No user_details found' . PHP_EOL;
}

$conn->close();
?>
