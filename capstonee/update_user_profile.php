<?php
session_start();
include 'config/database.php';

// Set timezone to Philippines
date_default_timezone_set('Asia/Manila');

// Check if this is a modal request
$isModal = isset($_GET['modal']) && $_GET['modal'] === 'true';

// Redirect if not logged in (only if not modal)
if (!isset($_SESSION['user_id'])) {
    if ($isModal) {
        // For modal, send a message to parent to redirect
        echo '<script>window.parent.postMessage("redirectToLogin", "*");</script>';
        exit();
    } else {
        header("Location: index.php");
        exit();
    }
}

$user_id = $_SESSION['user_id'];
$message = '';
$message_type = '';

// Get current user data
$user_data = $conn->query("SELECT username, email FROM users WHERE id = $user_id")->fetch_assoc();

// Fetch logged-in user's patient data from registration
$patient_data = null;
if (isset($_SESSION['username'])) {
    $stmt = $conn->prepare("SELECT * FROM patients WHERE student_id = ?");
    $stmt->bind_param("s", $_SESSION['username']);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $patient_data = $result->fetch_assoc();
    }
    $stmt->close();
}

// Handle form submission - SIMPLIFIED TO ONLY UPDATE PATIENTS TABLE
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $full_name = $_POST['full_name'] ?? '';
    $email = $_POST['email'] ?? '';
    $telephone_number = $_POST['telephone_number'] ?? '';
    $contact_number = $_POST['contact_number'] ?? '';
    $civil_status = $_POST['civil_status'] ?? '';
    $department = $_POST['department'] ?? '';
    $year_level = $_POST['year_level'] ?? '';
    $address = $_POST['address'] ?? '';
    $birthdate = $_POST['birthdate'] ?? '';
    $gender = $_POST['gender'] ?? '';

    // Parse full name into first, middle, last
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

    // Sync with patients table - THIS IS THE MAIN TABLE
    $student_id = $user_data['username']; // SR Code from users table

    // Check if patient record exists
    $check_patient = $conn->prepare("SELECT id FROM patients WHERE student_id = ?");
    $check_patient->bind_param("s", $student_id);
    $check_patient->execute();
    $patient_result = $check_patient->get_result();

    if ($patient_result->num_rows > 0) {
        // Update existing patient record
        $stmt = $conn->prepare("UPDATE patients SET first_name=?, middle_name=?, last_name=?, date_of_birth=?, sex=?, contact_number=?, telephone_number=?, civil_status=?, program=?, year_level=?, address=? WHERE student_id=?");
        if ($stmt) {
            $stmt->bind_param("ssssssssssss", $first_name, $middle_name, $last_name, $birthdate, $gender, $contact_number, $telephone_number, $civil_status, $department, $year_level, $address, $student_id);
            if ($stmt->execute()) {
                $success = true;
            } else {
                $message = "Error updating patient record: " . $stmt->error;
                $message_type = "error";
            }
            $stmt->close();
        } else {
            $message = "Error preparing update statement: " . $conn->error;
            $message_type = "error";
        }
    } else {
        // Create new patient record
        $stmt = $conn->prepare("INSERT INTO patients (student_id, first_name, middle_name, last_name, date_of_birth, sex, contact_number, telephone_number, civil_status, program, year_level, address) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("ssssssssssss", $student_id, $first_name, $middle_name, $last_name, $birthdate, $gender, $contact_number, $telephone_number, $civil_status, $department, $year_level, $address);
            if ($stmt->execute()) {
                $success = true;
            } else {
                $message = "Error inserting patient record: " . $stmt->error;
                $message_type = "error";
            }
            $stmt->close();
        } else {
            $message = "Error preparing insert statement: " . $conn->error;
            $message_type = "error";
        }
    }
    $check_patient->close();

    // Update email in users table if successful
    if (isset($success) && $success) {
        $update_email = $conn->prepare("UPDATE users SET email = ? WHERE id = ?");
        if ($update_email) {
            $update_email->bind_param("si", $email, $user_id);
            if ($update_email->execute()) {
                $message = "Profile updated successfully!";
                $message_type = "success";
                
                // Check if this is an AJAX request
                if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                    // Return JSON response for AJAX
                    echo json_encode([
                        'success' => true,
                        'message' => $message,
                        'message_type' => $message_type
                    ]);
                    exit();
                }
            } else {
                $message = "Error updating email: " . $update_email->error;
                $message_type = "error";
            }
            $update_email->close();
        }
    }
    
    // If AJAX request and we haven't exited yet (error case)
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        echo json_encode([
            'success' => false,
            'message' => $message ?: 'Unknown error occurred',
            'message_type' => $message_type ?: 'error'
        ]);
        exit();
    }
}

// Get patient details for pre-filling
$patient_details = [];
if ($patient_data) {
    // Construct full name from patient data
    $name_parts = array_filter([
        $patient_data['first_name'] ?? '',
        $patient_data['middle_name'] ?? '',
        $patient_data['last_name'] ?? ''
    ]);
    $patient_details['full_name'] = implode(' ', $name_parts);
    
    // Get other fields
    $patient_details['contact_number'] = $patient_data['contact_number'] ?? '';
    $patient_details['telephone_number'] = $patient_data['telephone_number'] ?? '';
    $patient_details['civil_status'] = $patient_data['civil_status'] ?? '';
    $patient_details['address'] = $patient_data['address'] ?? '';
    $patient_details['birthdate'] = $patient_data['date_of_birth'] ?? '';
    $patient_details['gender'] = $patient_data['sex'] ?? '';
    $patient_details['department'] = $patient_data['program'] ?? '';
    $patient_details['year_level'] = $patient_data['year_level'] ?? '';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Profile</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
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
        
        .focus-maroon:focus {
            border-color: var(--maroon-primary);
            ring-color: var(--maroon-primary);
            --tw-ring-color: var(--maroon-primary);
        }
        
        body {
            overflow: hidden;
        }
    </style>
</head>
<body class="bg-white">
    <div class="max-h-screen overflow-y-auto p-0">
        <div class="sticky top-0 z-10 bg-white border-b px-6 py-4">
            <div class="flex justify-between items-center">
                <h2 class="text-xl font-bold text-gray-800">Edit Profile Information</h2>
                <button onclick="closeModal()" class="text-gray-500 hover:text-gray-700">
                    <i class="bi bi-x-lg text-2xl"></i>
                </button>
            </div>
        </div>

        <?php if ($message && $message_type == 'error'): ?>
            <div class="bg-red-100 border-l-4 border-red-400 text-red-700 p-4 mx-6 mt-4">
                <div class="flex items-center">
                    <i class="bi bi-exclamation-circle mr-2"></i>
                    <?php echo $message; ?>
                </div>
            </div>
        <?php endif; ?>

        <form method="POST" class="p-6 space-y-6" id="profileForm">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="space-y-4">
                    <h3 class="text-lg font-semibold text-gray-800 border-b pb-2">Basic Information</h3>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">SR Code</label>
                        <input type="text" value="<?php echo htmlspecialchars($user_data['username']); ?>" 
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-100 focus-maroon" readonly>
                        <p class="text-xs text-gray-500 mt-1">SR Code cannot be changed</p>
                    </div>

                    <div>
                        <label for="full_name" class="block text-sm font-medium text-gray-700 mb-1">Full Name</label>
                        <input type="text" id="full_name" name="full_name"
                               value="<?php echo htmlspecialchars($patient_details['full_name'] ?? ''); ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-maroon focus:border-maroon focus-maroon"
                               placeholder="Enter your full name">
                    </div>

                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email Address</label>
                        <input type="email" id="email" name="email"
                               value="<?php echo htmlspecialchars($user_data['email'] ?? ''); ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-maroon focus:border-maroon focus-maroon"
                               placeholder="Enter your email address">
                    </div>

                    <div>
                        <label for="telephone_number" class="block text-sm font-medium text-gray-700 mb-1">Telephone Number</label>
                        <input type="tel" id="telephone_number" name="telephone_number"
                               value="<?php echo htmlspecialchars($patient_details['telephone_number'] ?? ''); ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-maroon focus:border-maroon focus-maroon"
                               placeholder="Enter telephone number (landline)">
                    </div>

                    <div>
                        <label for="contact_number" class="block text-sm font-medium text-gray-700 mb-1">Cellphone Number</label>
                        <input type="tel" id="contact_number" name="contact_number"
                               value="<?php echo htmlspecialchars($patient_details['contact_number'] ?? ''); ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-maroon focus:border-maroon focus-maroon"
                               placeholder="Enter cellphone number">
                    </div>

                    <div>
                        <label for="civil_status" class="block text-sm font-medium text-gray-700 mb-1">Civil Status</label>
                        <select id="civil_status" name="civil_status" 
        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-maroon focus:border-maroon focus-maroon">
    <option value="">Select Civil Status</option>
    <option value="Single" <?php echo (isset($patient_details['civil_status']) && $patient_details['civil_status'] == 'Single') ? 'selected' : ''; ?>>Single</option>
    <option value="Married" <?php echo (isset($patient_details['civil_status']) && $patient_details['civil_status'] == 'Married') ? 'selected' : ''; ?>>Married</option>
    <option value="Divorced" <?php echo (isset($patient_details['civil_status']) && $patient_details['civil_status'] == 'Divorced') ? 'selected' : ''; ?>>Divorced</option>
    <option value="Widowed" <?php echo (isset($patient_details['civil_status']) && $patient_details['civil_status'] == 'Widowed') ? 'selected' : ''; ?>>Widowed</option>
    <option value="Separated" <?php echo (isset($patient_details['civil_status']) && $patient_details['civil_status'] == 'Separated') ? 'selected' : ''; ?>>Separated</option>
</select>
                    </div>
                </div>

                <div class="space-y-4">
                    <h3 class="text-lg font-semibold text-gray-800 border-b pb-2">Additional Information</h3>
                    
                    <div>
                        <label for="department" class="block text-sm font-medium text-gray-700 mb-1">Program</label>
                        <input type="text" id="department" name="department" 
                               value="<?php echo htmlspecialchars($patient_details['department'] ?? ''); ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-maroon focus:border-maroon focus-maroon"
                               placeholder="Enter Program">
                    </div>

                    <div>
                        <label for="year_level" class="block text-sm font-medium text-gray-700 mb-1">Year Level</label>
                        <select id="year_level" name="year_level" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-maroon focus:border-maroon focus-maroon">
                            <option value="" <?php echo empty($patient_details['year_level']) ? 'selected' : ''; ?>>Select Year Level</option>
                            <option value="1st Year" <?php echo ($patient_details['year_level'] ?? '') == '1st Year' ? 'selected' : ''; ?>>1st</option>
                            <option value="2nd Year" <?php echo ($patient_details['year_level'] ?? '') == '2nd Year' ? 'selected' : ''; ?>>2nd</option>
                            <option value="3rd Year" <?php echo ($patient_details['year_level'] ?? '') == '3rd Year' ? 'selected' : ''; ?>>3rd</option>
                            <option value="4th Year" <?php echo ($patient_details['year_level'] ?? '') == '4th Year' ? 'selected' : ''; ?>>4th</option>
                            <option value="5th Year" <?php echo ($patient_details['year_level'] ?? '') == '5th Year' ? 'selected' : ''; ?>>5th</option>
                        </select>
                    </div>

                    <div>
                        <label for="birthdate" class="block text-sm font-medium text-gray-700 mb-1">Birthdate</label>
                        <input type="date" id="birthdate" name="birthdate"
                               value="<?php echo htmlspecialchars($patient_details['birthdate'] ?? ''); ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-maroon focus:border-maroon focus-maroon">
                    </div>

                    <div>
                        <label for="gender" class="block text-sm font-medium text-gray-700 mb-1">Gender</label>
                        <select id="gender" name="gender" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-maroon focus:border-maroon focus-maroon">
                            <option value="">Select Gender</option>
                            <option value="Male" <?php echo ($patient_details['gender'] ?? '') == 'Male' ? 'selected' : ''; ?>>Male</option>
                            <option value="Female" <?php echo ($patient_details['gender'] ?? '') == 'Female' ? 'selected' : ''; ?>>Female</option>
                            <option value="Other" <?php echo ($patient_details['gender'] ?? '') == 'Other' ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="pt-4">
                <label for="address" class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                <textarea id="address" name="address" rows="3"
                          class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-maroon focus:border-maroon focus-maroon"
                          placeholder="Enter your complete address"><?php echo htmlspecialchars($patient_details['address'] ?? ''); ?></textarea>
            </div>

            <div class="flex gap-4 pt-6 border-t">
                <button type="submit" class="maroon-gradient-button text-white px-6 py-2 rounded-lg font-medium hover:shadow-lg transition-all flex items-center gap-2">
                    <i class="bi bi-check-lg"></i> Update Profile
                </button>
                <button type="button" onclick="closeModal()" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-6 py-2 rounded-lg font-medium transition duration-300 flex items-center gap-2">
                    <i class="bi bi-x-lg"></i> Cancel
                </button>
            </div>
        </form>
    </div>

    <script>
        function closeModal() {
            window.parent.postMessage('closeProfileModal', '*');
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('profileForm');
            
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                
                const submitBtn = form.querySelector('button[type="submit"]');
                const originalText = submitBtn.innerHTML;
                submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Updating...';
                submitBtn.disabled = true;
                
                const formData = new FormData(form);
                
                fetch('update_user_profile.php', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        window.parent.postMessage({
                            type: 'profileUpdateSuccess',
                            message: data.message
                        }, '*');
                        
                        setTimeout(() => {
                            closeModal();
                        }, 1500);
                    } else {
                        alert('Error: ' + data.message);
                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred. Please try again.');
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                });
            });
        });
    </script>
</body>
</html>