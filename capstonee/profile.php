<?php
session_start();
include 'config/database.php';

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$error = '';
$success = '';
$user_id = $_SESSION['user_id'];

// Get user information
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

// Process form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $full_name = $_POST['full_name'];
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validate input
    if (empty($full_name)) {
        $error = "Full name is required";
    } elseif (!empty($new_password) && empty($current_password)) {
        $error = "Current password is required to set a new password";
    } elseif (!empty($new_password) && $new_password !== $confirm_password) {
        $error = "New passwords do not match";
    } else {
        // Check current password if trying to change password
        $password_valid = true;
        if (!empty($new_password)) {
            $password_valid = password_verify($current_password, $user['password']);
            if (!$password_valid) {
                $error = "Current password is incorrect";
            }
        }
        
        if ($password_valid) {
            if (!empty($new_password)) {
                // Update with new password
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET full_name = ?, password = ? WHERE id = ?");
                $stmt->bind_param("ssi", $full_name, $hashed_password, $user_id);
            } else {
                // Update without changing password
                $stmt = $conn->prepare("UPDATE users SET full_name = ? WHERE id = ?");
                $stmt->bind_param("si", $full_name, $user_id);
            }
            
            if ($stmt->execute()) {
                $success = "Profile updated successfully";
                
                // Update session variable
                $_SESSION['full_name'] = $full_name;
                
                // Refresh user data
                $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $user = $result->fetch_assoc();
                
                // Log this action in analytics
                $action = "Updated profile";
                $stmt = $conn->prepare("
                    INSERT INTO analytics_data (user_id, action, timestamp)
                    VALUES (?, ?, NOW())
                ");
                $stmt->bind_param("is", $user_id, $action);
                $stmt->execute();
            } else {
                $error = "Error: " . $stmt->error;
            }
        }
    }
}

// Log this view in analytics
$action = "Viewed profile";
$stmt = $conn->prepare("
    INSERT INTO analytics_data (user_id, action, timestamp)
    VALUES (?, ?, NOW())
");
$stmt->bind_param("is", $user_id, $action);
$stmt->execute();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - BSU Clinic Record Management System</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container mt-4">
        <div class="row">
            <div class="col-md-8 offset-md-2">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h4><i class="bi bi-person-circle"></i> My Profile</h4>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($error)): ?>
                            <div class="alert alert-danger"><?php echo $error; ?></div>
                        <?php endif; ?>
                        
                        <?php if (!empty($success)): ?>
                            <div class="alert alert-success"><?php echo $success; ?></div>
                        <?php endif; ?>
                        
                        <div class="row mb-4">
                            <div class="col-md-12 text-center">
                                <div class="profile-icon">
                                    <i class="bi bi-person-circle" style="font-size: 5rem;"></i>
                                </div>
                                <h5 class="mt-2"><?php echo htmlspecialchars($user['username']); ?></h5>
                                <span class="badge <?php echo $user['role'] === 'admin' ? 'bg-danger' : 'bg-primary'; ?>">
                                    <?php echo ucfirst(htmlspecialchars($user['role'])); ?>
                                </span>
                            </div>
                        </div>
                        
                        <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                            <div class="mb-3">
                                <label for="username" class="form-label">Username</label>
                                <input type="text" class="form-control" id="username" value="<?php echo htmlspecialchars($user['username']); ?>" disabled>
                                <div class="form-text">Username cannot be changed.</div>
                            </div>
                            <div class="mb-3">
                                <label for="full_name" class="form-label">Full Name</label>
                                <input type="text" class="form-control" id="full_name" name="full_name" value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                            </div>
                            <hr>
                            <h5>Change Password</h5>
                            <div class="mb-3">
                                <label for="current_password" class="form-label">Current Password</label>
                                <input type="password" class="form-control" id="current_password" name="current_password">
                                <div class="form-text">Required only if changing password.</div>
                            </div>
                            <div class="mb-3">
                                <label for="new_password" class="form-label">New Password</label>
                                <input type="password" class="form-control" id="new_password" name="new_password">
                            </div>
                            <div class="mb-3">
                                <label for="confirm_password" class="form-label">Confirm New Password</label>
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password">
                            </div>
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary">Update Profile</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <?php include 'includes/footer.php'; ?>
    
    <script src="assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>