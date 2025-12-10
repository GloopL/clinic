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

// Function to create user_details table
function createUserDetailsTable($conn) {
    $sql = "
        CREATE TABLE IF NOT EXISTS user_details (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            full_name VARCHAR(255),
            contact_number VARCHAR(20),
            department VARCHAR(100),
            year_level VARCHAR(20),
            address TEXT,
            birthdate DATE,
            gender ENUM('Male', 'Female', 'Other'),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY unique_user (user_id)
        )
    ";
    
    if ($conn->query($sql) === TRUE) {
        return true;
    } else {
        return false;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $full_name = $_POST['full_name'] ?? '';
    $email = $_POST['email'] ?? '';
    $contact_number = $_POST['contact_number'] ?? '';
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

    // Check if user_details table exists, if not create it
    $check_table = $conn->query("SHOW TABLES LIKE 'user_details'");
    if ($check_table->num_rows == 0) {
        if (!createUserDetailsTable($conn)) {
            $message = "Error creating user details table: " . $conn->error;
            $message_type = "error";
        }
    }

    if (!$message) { // Only proceed if no table creation error
        // Check if user details already exist
        $check_details = $conn->query("SELECT * FROM user_details WHERE user_id = $user_id");

        if ($check_details && $check_details->num_rows > 0) {
            // Update existing details
            $stmt = $conn->prepare("UPDATE user_details SET full_name=?, contact_number=?, department=?, year_level=?, address=?, birthdate=?, gender=?, updated_at=NOW() WHERE user_id=?");
            if ($stmt) {
                $stmt->bind_param("sssssssi", $full_name, $contact_number, $department, $year_level, $address, $birthdate, $gender, $user_id);
                if ($stmt->execute()) {
                    $success = true;
                } else {
                    $message = "Error updating profile: " . $stmt->error;
                    $message_type = "error";
                }
                $stmt->close();
            } else {
                $message = "Error preparing update statement: " . $conn->error;
                $message_type = "error";
            }
        } else {
            // Insert new details
            $stmt = $conn->prepare("INSERT INTO user_details (user_id, full_name, contact_number, department, year_level, address, birthdate, gender) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param("isssssss", $user_id, $full_name, $contact_number, $department, $year_level, $address, $birthdate, $gender);
                if ($stmt->execute()) {
                    $success = true;
                } else {
                    $message = "Error inserting profile: " . $stmt->error;
                    $message_type = "error";
                }
                $stmt->close();
            } else {
                $message = "Error preparing insert statement: " . $conn->error;
                $message_type = "error";
            }
        }

        // Sync with patients table for form pre-population
        if (isset($success) && $success) {
            $student_id = $user_data['username']; // SR Code from users table

            // Check if patient record exists
            $check_patient = $conn->prepare("SELECT id FROM patients WHERE student_id = ?");
            $check_patient->bind_param("s", $student_id);
            $check_patient->execute();
            $patient_result = $check_patient->get_result();

            if ($patient_result->num_rows > 0) {
                // Update existing patient record WITH ADDRESS
                $stmt = $conn->prepare("UPDATE patients SET first_name=?, middle_name=?, last_name=?, date_of_birth=?, sex=?, program=?, year_level=?, address=? WHERE student_id=?");
                $stmt->bind_param("sssssssss", $first_name, $middle_name, $last_name, $birthdate, $gender, $department, $year_level, $address, $student_id);
            } else {
                // Create new patient record WITH ADDRESS
                $stmt = $conn->prepare("INSERT INTO patients (student_id, first_name, middle_name, last_name, date_of_birth, sex, program, year_level, address) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sssssssss", $student_id, $first_name, $middle_name, $last_name, $birthdate, $gender, $department, $year_level, $address);
            }

            if ($stmt->execute()) {
                // Patient record updated/created successfully
            } else {
                // Don't fail the whole operation for patient sync error, just log it
                error_log("Patient sync error: " . $stmt->error);
            }
            $stmt->close();
            $check_patient->close();
        }

        // Update email in users table if successful
        if (isset($success) && $success) {
            $update_email = $conn->prepare("UPDATE users SET email = ? WHERE id = ?");
            if ($update_email) {
                $update_email->bind_param("si", $email, $user_id);
                $update_email->execute();
                $update_email->close();

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
            }
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

// Get user details if table exists
$user_details = [];
$check_table = $conn->query("SHOW TABLES LIKE 'user_details'");
if ($check_table->num_rows > 0) {
    $details_result = $conn->query("SELECT * FROM user_details WHERE user_id = $user_id");
    if ($details_result && $details_result->num_rows > 0) {
        $user_details = $details_result->fetch_assoc();
    }
}

// Pre-fill from patient_data if fields are empty and patient_data exists
if ($patient_data) {
    // Construct full name from patient data if not already set
    if (empty($user_details['full_name'])) {
        $name_parts = array_filter([
            $patient_data['first_name'] ?? '',
            $patient_data['middle_name'] ?? '',
            $patient_data['last_name'] ?? ''
        ]);
        $user_details['full_name'] = implode(' ', $name_parts);
    }
    // Pre-fill other fields if empty
    if (empty($user_details['contact_number'])) {
        $user_details['contact_number'] = $patient_data['contact_number'] ?? '';
    }
    if (empty($user_details['address'])) {
        $user_details['address'] = $patient_data['address'] ?? '';
    }
    if (empty($user_details['birthdate'])) {
        $user_details['birthdate'] = $patient_data['date_of_birth'] ?? '';
    }
    if (empty($user_details['gender'])) {
        $user_details['gender'] = $patient_data['sex'] ?? '';
    }
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
        
        .focus-maroon:focus {
            border-color: var(--maroon-primary);
            ring-color: var(--maroon-primary);
            --tw-ring-color: var(--maroon-primary);
        }
        
        /* Hide scrollbar for iframe */
        body {
            overflow: hidden;
        }
    </style>
</head>
<body class="bg-white">
    <div class="max-h-screen overflow-y-auto p-0">
        <!-- Modal header -->
        <div class="sticky top-0 z-10 bg-white border-b px-6 py-4">
            <div class="flex justify-between items-center">
                <h2 class="text-xl font-bold text-gray-800">Edit Profile Information</h2>
                <button onclick="closeModal()" class="text-gray-500 hover:text-gray-700">
                    <i class="bi bi-x-lg text-2xl"></i>
                </button>
            </div>
        </div>

        <!-- Error message -->
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
                <!-- Basic Information -->
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
                               value="<?php echo htmlspecialchars($user_details['full_name'] ?? ''); ?>"
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
                        <label for="contact_number" class="block text-sm font-medium text-gray-700 mb-1">Contact Number</label>
                        <input type="tel" id="contact_number" name="contact_number"
                               value="<?php echo htmlspecialchars($user_details['contact_number'] ?? ''); ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-maroon focus:border-maroon focus-maroon"
                               placeholder="Enter your contact number">
                    </div>
                </div>

                <!-- Additional Information -->
                <div class="space-y-4">
                    <h3 class="text-lg font-semibold text-gray-800 border-b pb-2">Additional Information</h3>
                    
                    <div>
                        <label for="department" class="block text-sm font-medium text-gray-700 mb-1">Program</label>
                        <input type="text" id="department" name="department" 
                               value="<?php echo htmlspecialchars($user_details['department'] ?? ''); ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-maroon focus:border-maroon focus-maroon"
                               placeholder="Enter Program">
                    </div>

                    <div>
                        <label for="year_level" class="block text-sm font-medium text-gray-700 mb-1">Year Level</label>
                        <select id="year_level" name="year_level" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-maroon focus:border-maroon focus-maroon">
                            <option value="" <?php echo empty($user_details['year_level']) ? 'selected' : ''; ?>>Select Year Level</option>
                            <option value="1st Year" <?php echo ($user_details['year_level'] ?? '') == '1st Year' ? 'selected' : ''; ?>>1st</option>
                            <option value="2nd Year" <?php echo ($user_details['year_level'] ?? '') == '2nd Year' ? 'selected' : ''; ?>>2nd</option>
                            <option value="3rd Year" <?php echo ($user_details['year_level'] ?? '') == '3rd Year' ? 'selected' : ''; ?>>3rd</option>
                            <option value="4th Year" <?php echo ($user_details['year_level'] ?? '') == '4th Year' ? 'selected' : ''; ?>>4th</option>
                            <option value="5th Year" <?php echo ($user_details['year_level'] ?? '') == '5th Year' ? 'selected' : ''; ?>>5th</option>
                        </select>
                    </div>

                    <div>
                        <label for="birthdate" class="block text-sm font-medium text-gray-700 mb-1">Birthdate</label>
                        <input type="date" id="birthdate" name="birthdate"
                               value="<?php echo htmlspecialchars($user_details['birthdate'] ?? ''); ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-maroon focus:border-maroon focus-maroon">
                    </div>

                    <div>
                        <label for="gender" class="block text-sm font-medium text-gray-700 mb-1">Gender</label>
                        <select id="gender" name="gender" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-maroon focus:border-maroon focus-maroon">
                            <option value="">Select Gender</option>
                            <option value="Male" <?php echo ($user_details['gender'] ?? '') == 'Male' ? 'selected' : ''; ?>>Male</option>
                            <option value="Female" <?php echo ($user_details['gender'] ?? '') == 'Female' ? 'selected' : ''; ?>>Female</option>
                            <option value="Other" <?php echo ($user_details['gender'] ?? '') == 'Other' ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="pt-4">
                <label for="address" class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                <textarea id="address" name="address" rows="3"
                          class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-maroon focus:border-maroon focus-maroon"
                          placeholder="Enter your complete address"><?php echo htmlspecialchars($user_details['address'] ?? ''); ?></textarea>
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
        // Function to close the modal
        function closeModal() {
            // Send message to parent to close modal
            window.parent.postMessage('closeProfileModal', '*');
        }
        
        // Handle form submission
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('profileForm');
            
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                
                // Show loading state
                const submitBtn = form.querySelector('button[type="submit"]');
                const originalText = submitBtn.innerHTML;
                submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Updating...';
                submitBtn.disabled = true;
                
                // Submit via AJAX
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
                        // Send success message to parent
                        window.parent.postMessage({
                            type: 'profileUpdateSuccess',
                            message: data.message
                        }, '*');
                        
                        // Close modal after 1.5 seconds
                        setTimeout(() => {
                            closeModal();
                        }, 1500);
                    } else {
                        // Show error message
                        alert('Error: ' + data.message);
                        
                        // Restore button state
                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred. Please try again.');
                    
                    // Restore button state
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                });
            });
        });
    </script>
</body>
</html>