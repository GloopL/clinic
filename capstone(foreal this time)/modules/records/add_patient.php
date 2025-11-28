<?php
session_start();
include '../../config/database.php';

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$success_message = '';
$error_message = '';

// Process form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Validate input
    $student_id = $_POST['student_id'] ?? '';
    $first_name = $_POST['first_name'] ?? '';
    $middle_name = $_POST['middle_name'] ?? '';
    $last_name = $_POST['last_name'] ?? '';
    $date_of_birth = $_POST['date_of_birth'] ?? '';
    $sex = $_POST['sex'] ?? '';
    $program = $_POST['program'] ?? '';
    $year_level = $_POST['year_level'] ?? '';
    
    // Check if student ID already exists
    $stmt = $conn->prepare("SELECT id FROM patients WHERE student_id = ?");
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $error_message = "Student ID already exists. Please use a different ID.";
    } else {
        // Insert new patient
        $stmt = $conn->prepare("INSERT INTO patients (student_id, first_name, middle_name, last_name, date_of_birth, sex, program, year_level) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssssss", $student_id, $first_name, $middle_name, $last_name, $date_of_birth, $sex, $program, $year_level);
        
        if ($stmt->execute()) {
            $patient_id = $conn->insert_id;
            $success_message = "Patient added successfully!";
            
            // Add to analytics data
            $stmt = $conn->prepare("INSERT INTO analytics_data (data_type, data_value, data_label, data_date) VALUES ('new_patient', 1, ?, CURDATE())");
            $data_label = "New Patient: " . $program;
            $stmt->bind_param("s", $data_label);
            $stmt->execute();
            
            // Redirect to patient view after short delay
            header("refresh:2;url=view_patient.php?id=" . $patient_id);
        } else {
            $error_message = "Error adding patient: " . $conn->error;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Patient - BSU Clinic Records</title>
    <link rel="stylesheet" href="../../assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="../../assets/css/style.css">
</head>
<body>
    <?php include '../../includes/navigation.php'; ?>
    
    <div class="container mt-4">
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h4>Add New Patient</h4>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($success_message)): ?>
                            <div class="alert alert-success"><?php echo $success_message; ?></div>
                        <?php endif; ?>
                        
                        <?php if (!empty($error_message)): ?>
                            <div class="alert alert-danger"><?php echo $error_message; ?></div>
                        <?php endif; ?>
                        
                        <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="student_id" class="form-label">Student ID <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="student_id" name="student_id" required>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-4">
                                    <label for="first_name" class="form-label">First Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="first_name" name="first_name" required>
                                </div>
                                <div class="col-md-4">
                                    <label for="middle_name" class="form-label">Middle Name</label>
                                    <input type="text" class="form-control" id="middle_name" name="middle_name">
                                </div>
                                <div class="col-md-4">
                                    <label for="last_name" class="form-label">Last Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="last_name" name="last_name" required>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-4">
                                    <label for="date_of_birth" class="form-label">Date of Birth <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" id="date_of_birth" name="date_of_birth" required>
                                </div>
                                <div class="col-md-4">
                                    <label for="sex" class="form-label">Sex <span class="text-danger">*</span></label>
                                    <select class="form-select" id="sex" name="sex" required>
                                        <option value="">Select</option>
                                        <option value="Male">Male</option>
                                        <option value="Female">Female</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="program" class="form-label">Program/Course <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="program" name="program" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="year_level" class="form-label">Year Level <span class="text-danger">*</span></label>
                                    <select class="form-select" id="year_level" name="year_level" required>
                                        <option value="">Select</option>
                                        <option value="1st Year">1st Year</option>
                                        <option value="2nd Year">2nd Year</option>
                                        <option value="3rd Year">3rd Year</option>
                                        <option value="4th Year">4th Year</option>
                                        <option value="5th Year">5th Year</option>
                                        <option value="Graduate">Graduate</option>
                                        <option value="Faculty">Faculty</option>
                                        <option value="Staff">Staff</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
                                <a href="patients.php" class="btn btn-secondary">Cancel</a>
                                <button type="submit" class="btn btn-primary">Save Patient</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <?php include '../../includes/footer.php'; ?>
    
    <script src="../../assets/js/jquery.min.js"></script>
    <script src="../../assets/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
            // Calculate age when date of birth changes
            $('#date_of_birth').change(function() {
                var dob = new Date($(this).val());
                var today = new Date();
                var age = today.getFullYear() - dob.getFullYear();
                
                // Adjust age if birthday hasn't occurred yet this year
                if (today.getMonth() < dob.getMonth() || 
                    (today.getMonth() == dob.getMonth() && today.getDate() < dob.getDate())) {
                    age--;
                }
                
                $('#age').val(age);
            });
        });
    </script>
</body>
</html>