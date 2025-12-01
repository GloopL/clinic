<?php
session_start();
include 'config/database.php';

$error = '';
$success = '';
$return_url = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'index.php';

// Process employee registration
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $employee_id = trim($_POST['employee_id']);
    $password = $_POST['emp_password'];
    $confirm_password = $_POST['emp_confirm_password'];
    $first_name = isset($_POST['emp_first_name']) ? trim($_POST['emp_first_name']) : '';
    $middle_name = isset($_POST['emp_middle_name']) ? trim($_POST['emp_middle_name']) : '';
    $last_name = isset($_POST['emp_last_name']) ? trim($_POST['emp_last_name']) : '';
    $sex = isset($_POST['emp_sex']) ? $_POST['emp_sex'] : '';
    $birthdate = isset($_POST['emp_birthdate']) ? $_POST['emp_birthdate'] : '';
    $contact_number = isset($_POST['emp_contact_number']) ? trim($_POST['emp_contact_number']) : '';
    $address = isset($_POST['emp_address']) ? trim($_POST['emp_address']) : '';
    $department = isset($_POST['department']) ? $_POST['department'] : '';
    $position = isset($_POST['position']) ? $_POST['position'] : '';
    
    // Validate required fields
    $required_fields = [
        'Employee ID' => $employee_id,
        'Password' => $password,
        'Confirm Password' => $confirm_password,
        'First Name' => $first_name,
        'Last Name' => $last_name,
        'Sex' => $sex,
        'Birthdate' => $birthdate
    ];
    
    foreach ($required_fields as $field_name => $value) {
        if (empty($value)) {
            $error = "$field_name is required.";
            break;
        }
    }
    
    if (empty($error) && $password !== $confirm_password) {
        $error = "Passwords do not match.";
    }
    
    if (empty($error)) {
        // Check if Employee ID already exists
        $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->bind_param("s", $employee_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $error = "Employee ID already registered.";
        } else {
            // Hash password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $role = 'user'; // Default role
            
            // Combine names for full name
            $name_parts = array_filter([$first_name, $middle_name, $last_name]);
            $full_name = trim(implode(' ', $name_parts));
            $email = '';
            $date_of_birth = $birthdate;

            // Start transaction
            $conn->begin_transaction();
            
            try {
                // Insert into users table
                $stmt = $conn->prepare("INSERT INTO users (username, password, full_name, email, role) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("sssss", $employee_id, $hashed_password, $full_name, $email, $role);
                
                if (!$stmt->execute()) {
                    throw new Exception("Failed to create user account.");
                }
                
                // Insert into patients table (or create separate employees table later)
                $program = 'Employee - ' . $position;
                $year_level = '';
                
                $stmt_patient = $conn->prepare("INSERT INTO patients (student_id, first_name, middle_name, last_name, date_of_birth, sex, contact_number, address, program, year_level) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt_patient->bind_param("ssssssssss", $employee_id, $first_name, $middle_name, $last_name, $date_of_birth, $sex, $contact_number, $address, $program, $year_level);
                
                if (!$stmt_patient->execute()) {
                    // If patients table insert fails, still keep the user account
                    error_log("Failed to insert employee record: " . $stmt_patient->error);
                }
                
                // Commit transaction
                $conn->commit();
                
                $success = "Employee registration successful! You can now log in.";
                
                // Store success message in session
                $_SESSION['registration_success'] = $success;
                $_SESSION['registration_username'] = $employee_id;
                
                // Redirect to index.php
                header("Location: index.php?register=success");
                exit();
                
            } catch (Exception $e) {
                // Rollback transaction on error
                $conn->rollback();
                $error = $e->getMessage();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Registration - BSU Clinic</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .registration-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            width: 100%;
            max-width: 500px;
            overflow: hidden;
        }
        
        .registration-header {
            background: linear-gradient(to right, #1d4ed8, #3b82f6);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .registration-header img {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid white;
            background: white;
            margin-bottom: 15px;
        }
        
        .registration-header h1 {
            font-size: 24px;
            margin-bottom: 5px;
        }
        
        .registration-header p {
            font-size: 14px;
            opacity: 0.9;
        }
        
        .registration-body {
            padding: 30px;
        }
        
        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
            animation: slideIn 0.5s ease;
        }
        
        .alert.error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
        }
        
        .alert.success {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #16a34a;
        }
        
        .form-section {
            margin-bottom: 25px;
        }
        
        .form-section h3 {
            color: #1d4ed8;
            margin-bottom: 15px;
            font-size: 18px;
            border-bottom: 2px solid #dbeafe;
            padding-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-row {
            display: flex;
            gap: 15px;
        }
        
        .form-row .form-group {
            flex: 1;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            color: #374151;
            font-weight: 500;
            font-size: 14px;
        }
        
        .required::after {
            content: " *";
            color: #dc2626;
        }
        
        input, select, textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #1d4ed8;
            box-shadow: 0 0 0 3px rgba(29, 78, 216, 0.1);
        }
        
        .password-input-container {
            position: relative;
        }
        
        .password-toggle-btn {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #6b7280;
            cursor: pointer;
            padding: 4px;
            border-radius: 4px;
        }
        
        .password-toggle-btn:hover {
            color: #1d4ed8;
        }
        
        .button-group {
            display: flex;
            gap: 15px;
            margin-top: 30px;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            text-align: center;
            flex: 1;
        }
        
        .btn-back {
            background: #f3f4f6;
            color: #374151;
        }
        
        .btn-back:hover {
            background: #e5e7eb;
        }
        
        .btn-submit {
            background: #1d4ed8;
            color: white;
        }
        
        .btn-submit:hover {
            background: #1e40af;
            transform: translateY(-2px);
        }
        
        .form-footer {
            text-align: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            color: #6b7280;
            font-size: 14px;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @media (max-width: 768px) {
            .form-row {
                flex-direction: column;
                gap: 0;
            }
            
            .registration-body {
                padding: 20px;
            }
            
            .registration-header {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="registration-container">
        <div class="registration-header">
            <img src="assets/css/images/logo-bsu.png" alt="BSU Logo">
            <h1>Employee Registration</h1>
            <p>BSU Clinic Record Management System</p>
        </div>
        
        <div class="registration-body">
            <?php if (!empty($error)): ?>
                <div class="alert error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($success)): ?>
                <div class="alert success">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
                </div>
                <script>
                    setTimeout(function() {
                        window.location.href = 'index.php';
                    }, 2000);
                </script>
            <?php endif; ?>
            
            <form method="POST" action="" id="employeeRegistrationForm">
                <!-- Personal Information Section -->
                <div class="form-section">
                    <h3><i class="fas fa-user"></i> Personal Information</h3>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="emp_first_name" class="required">First Name</label>
                            <input type="text" 
                                   name="emp_first_name" 
                                   id="emp_first_name" 
                                   placeholder="Enter your first name"
                                   required
                                   value="<?php echo isset($_POST['emp_first_name']) ? htmlspecialchars($_POST['emp_first_name']) : ''; ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="emp_middle_name">Middle Name</label>
                            <input type="text" 
                                   name="emp_middle_name" 
                                   id="emp_middle_name" 
                                   placeholder="Enter your middle name"
                                   value="<?php echo isset($_POST['emp_middle_name']) ? htmlspecialchars($_POST['emp_middle_name']) : ''; ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="emp_last_name" class="required">Last Name</label>
                        <input type="text" 
                               name="emp_last_name" 
                               id="emp_last_name" 
                               placeholder="Enter your last name"
                               required
                               value="<?php echo isset($_POST['emp_last_name']) ? htmlspecialchars($_POST['emp_last_name']) : ''; ?>">
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="emp_sex" class="required">Sex</label>
                            <select name="emp_sex" id="emp_sex" required>
                                <option value="">Select Sex</option>
                                <option value="Male" <?php echo (isset($_POST['emp_sex']) && $_POST['emp_sex'] == 'Male') ? 'selected' : ''; ?>>Male</option>
                                <option value="Female" <?php echo (isset($_POST['emp_sex']) && $_POST['emp_sex'] == 'Female') ? 'selected' : ''; ?>>Female</option>
                                <option value="Other" <?php echo (isset($_POST['emp_sex']) && $_POST['emp_sex'] == 'Other') ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="emp_birthdate" class="required">Birthdate</label>
                            <input type="date" 
                                   name="emp_birthdate" 
                                   id="emp_birthdate" 
                                   required
                                   value="<?php echo isset($_POST['emp_birthdate']) ? htmlspecialchars($_POST['emp_birthdate']) : ''; ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="emp_contact_number">Contact Number</label>
                        <input type="tel" 
                               name="emp_contact_number" 
                               id="emp_contact_number" 
                               placeholder="Enter your contact number"
                               value="<?php echo isset($_POST['emp_contact_number']) ? htmlspecialchars($_POST['emp_contact_number']) : ''; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="emp_address">Complete Address</label>
                        <textarea name="emp_address" 
                                  id="emp_address" 
                                  placeholder="Enter your complete address (House No., Street, Barangay, City/Municipality, Province)"
                                  rows="3"><?php echo isset($_POST['emp_address']) ? htmlspecialchars($_POST['emp_address']) : ''; ?></textarea>
                    </div>
                </div>
                
                <!-- Employee Information Section -->
                <div class="form-section">
                    <h3><i class="fas fa-briefcase"></i> Employee Information</h3>
                    
                    <div class="form-group">
                        <label for="employee_id" class="required">Employee ID</label>
                        <input type="text" 
                               name="employee_id" 
                               id="employee_id" 
                               placeholder="Enter your Employee ID"
                               required
                               value="<?php echo isset($_POST['employee_id']) ? htmlspecialchars($_POST['employee_id']) : ''; ?>">
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="department">Department</label>
                            <select name="department" id="department">
                                <option value="">Select Department</option>
                                <option value="College of Engineering" <?php echo (isset($_POST['department']) && $_POST['department'] == 'College of Engineering') ? 'selected' : ''; ?>>College of Engineering</option>
                                <option value="College of Education" <?php echo (isset($_POST['department']) && $_POST['department'] == 'College of Education') ? 'selected' : ''; ?>>College of Education</option>
                                <option value="College of Arts and Sciences" <?php echo (isset($_POST['department']) && $_POST['department'] == 'College of Arts and Sciences') ? 'selected' : ''; ?>>College of Arts and Sciences</option>
                                <option value="College of Nursing" <?php echo (isset($_POST['department']) && $_POST['department'] == 'College of Nursing') ? 'selected' : ''; ?>>College of Nursing</option>
                                <option value="College of Accountancy and Business" <?php echo (isset($_POST['department']) && $_POST['department'] == 'College of Accountancy and Business') ? 'selected' : ''; ?>>College of Accountancy and Business</option>
                                <option value="College of Industrial Technology" <?php echo (isset($_POST['department']) && $_POST['department'] == 'College of Industrial Technology') ? 'selected' : ''; ?>>College of Industrial Technology</option>
                                <option value="College of Informatics and Computing Sciences" <?php echo (isset($_POST['department']) && $_POST['department'] == 'College of Informatics and Computing Sciences') ? 'selected' : ''; ?>>College of Informatics and Computing Sciences</option>
                                <option value="Administration" <?php echo (isset($_POST['department']) && $_POST['department'] == 'Administration') ? 'selected' : ''; ?>>Administration</option>
                                <option value="Clinic" <?php echo (isset($_POST['department']) && $_POST['department'] == 'Clinic') ? 'selected' : ''; ?>>Clinic</option>
                                <option value="Maintenance" <?php echo (isset($_POST['department']) && $_POST['department'] == 'Maintenance') ? 'selected' : ''; ?>>Maintenance</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="position">Position</label>
                            <select name="position" id="position">
                                <option value="">Select Position</option>
                                <option value="Faculty" <?php echo (isset($_POST['position']) && $_POST['position'] == 'Faculty') ? 'selected' : ''; ?>>Faculty</option>
                                <option value="Administrative Staff" <?php echo (isset($_POST['position']) && $_POST['position'] == 'Administrative Staff') ? 'selected' : ''; ?>>Administrative Staff</option>
                                <option value="Medical Staff" <?php echo (isset($_POST['position']) && $_POST['position'] == 'Medical Staff') ? 'selected' : ''; ?>>Medical Staff</option>
                                <option value="Technical Staff" <?php echo (isset($_POST['position']) && $_POST['position'] == 'Technical Staff') ? 'selected' : ''; ?>>Technical Staff</option>
                                <option value="Maintenance Staff" <?php echo (isset($_POST['position']) && $_POST['position'] == 'Maintenance Staff') ? 'selected' : ''; ?>>Maintenance Staff</option>
                                <option value="Security" <?php echo (isset($_POST['position']) && $_POST['position'] == 'Security') ? 'selected' : ''; ?>>Security</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <!-- Account Information Section -->
                <div class="form-section">
                    <h3><i class="fas fa-lock"></i> Account Information</h3>
                    
                    <div class="form-group">
                        <label for="emp_password" class="required">Password</label>
                        <div class="password-input-container">
                            <input type="password" 
                                   name="emp_password" 
                                   id="emp_password" 
                                   placeholder="Create a strong password"
                                   required>
                            <button type="button" class="password-toggle-btn" id="empPasswordToggle">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="emp_confirm_password" class="required">Confirm Password</label>
                        <div class="password-input-container">
                            <input type="password" 
                                   name="emp_confirm_password" 
                                   id="emp_confirm_password" 
                                   placeholder="Re-enter password"
                                   required>
                            <button type="button" class="password-toggle-btn" id="empConfirmPasswordToggle">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                </div>
                
                <div class="button-group">
                    <a href="index.php" class="btn btn-back">
                        <i class="fas fa-arrow-left"></i> Back to Home
                    </a>
                    <button type="submit" class="btn btn-submit">
                        <i class="fas fa-user-plus"></i> Register as Employee
                    </button>
                </div>
            </form>
            
            <div class="form-footer">
                <p>© <?php echo date('Y'); ?> Batangas State University. All rights reserved.</p>
                <p style="margin-top: 5px;">Already have an account? <a href="index.php" style="color: #1d4ed8; text-decoration: none;">Login here</a></p>
            </div>
        </div>
    </div>

    <script>
        // Password toggle functionality
        document.getElementById('empPasswordToggle').addEventListener('click', function() {
            const passwordInput = document.getElementById('emp_password');
            const icon = this.querySelector('i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });
        
        document.getElementById('empConfirmPasswordToggle').addEventListener('click', function() {
            const passwordInput = document.getElementById('emp_confirm_password');
            const icon = this.querySelector('i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });
        
        // Auto-format Employee ID input
        document.getElementById('employee_id').addEventListener('input', function(e) {
            let value = e.target.value.toUpperCase().replace(/[^A-Z0-9-]/g, '');
            e.target.value = value;
        });
        
        // Form validation
        document.getElementById('employeeRegistrationForm').addEventListener('submit', function(e) {
            const password = document.getElementById('emp_password').value;
            const confirmPassword = document.getElementById('emp_confirm_password').value;
            
            if (password !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match!');
                return false;
            }
            
            if (password.length < 6) {
                e.preventDefault();
                alert('Password must be at least 6 characters long!');
                return false;
            }
            
            return true;
        });
    </script>
</body>
</html>