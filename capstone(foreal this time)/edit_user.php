<?php
session_start();
include 'config/database.php';

// Only admin users can access this page
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Validate user ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: admin_panel.php");
    exit();
}

$user_id = $_GET['id'];

// Get user data
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("Location: admin_panel.php");
    exit();
}

$user = $result->fetch_assoc();

$error = '';
$success = '';

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $full_name = trim($_POST['full_name']);
    $role = $_POST['role'];
    $password = $_POST['password'];

    if (empty($username) || empty($full_name)) {
        $error = "Username and Full Name are required.";
    } else {
        // Check if username already exists (for another user)
        $stmt = $conn->prepare("SELECT * FROM users WHERE username = ? AND id != ?");
        $stmt->bind_param("si", $username, $user_id);
        $stmt->execute();
        $duplicate = $stmt->get_result();

        if ($duplicate->num_rows > 0) {
            $error = "Username already exists.";
        } else {
            if (!empty($password)) {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET username=?, full_name=?, role=?, password=? WHERE id=?");
                $stmt->bind_param("ssssi", $username, $full_name, $role, $hashed_password, $user_id);
            } else {
                $stmt = $conn->prepare("UPDATE users SET username=?, full_name=?, role=? WHERE id=?");
                $stmt->bind_param("sssi", $username, $full_name, $role, $user_id);
            }

            if ($stmt->execute()) {
                $success = "User updated successfully.";

                // Log action
                $action = "Updated user: $username";
                $log = $conn->prepare("INSERT INTO analytics_data (user_id, action, timestamp) VALUES (?, ?, NOW())");
                $log->bind_param("is", $_SESSION['user_id'], $action);
                $log->execute();

                // Refresh user data
                $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $user = $result->fetch_assoc();
            } else {
                $error = "Error updating user.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Edit User - BSU Clinic Record Management System</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
  <style>
    /* Custom red to orange gradient theme */
    .red-orange-gradient {
      background: linear-gradient(135deg, #dc2626, #ea580c, #f97316);
    }
    
    .red-orange-gradient-light {
      background: linear-gradient(135deg, #fef2f2, #ffedd5, #fed7aa);
    }
    
    .red-orange-gradient-card {
      background: linear-gradient(135deg, #dc2626, #ea580c, #f97316);
    }
    
    .red-orange-gradient-card-light {
      background: linear-gradient(135deg, #fef2f2, #ffedd5);
    }
    
    .red-orange-gradient-button {
      background: linear-gradient(135deg, #dc2626, #ea580c);
    }
    
    .red-orange-gradient-button:hover {
      background: linear-gradient(135deg, #b91c1c, #c2410c);
    }
    
    .yellow-orange-button {
      background: linear-gradient(135deg, #f59e0b, #d97706);
    }
    
    .yellow-orange-button:hover {
      background: linear-gradient(135deg, #d97706, #b45309);
    }
    
    .red-orange-gradient-alert {
      background: linear-gradient(135deg, #fef2f2, #ffedd5);
      border-left-color: #ea580c;
    }
    
    .focus-red-orange:focus {
      border-color: #ea580c;
      ring-color: #ea580c;
      --tw-ring-color: #ea580c;
    }
  </style>
</head>
<body class="bg-gradient-to-br from-orange-50 to-red-50 flex flex-col min-h-screen">

  <!-- Header -->
  <header class="red-orange-gradient text-white shadow-md">
    <div class="max-w-7xl mx-auto flex items-center justify-between px-6 py-3">
      <div class="flex items-center gap-3">
        <img src="assets/css/images/logo-bsu.png" alt="BSU Logo" class="w-12 h-12 rounded-full object-cover border-4 border-white bg-white">
        <h1 class="text-lg font-bold">BSU Clinic Record Management System</h1>
      </div>
      <nav class="flex items-center gap-6">
        <a href="dashboard.php" class="hover:text-yellow-200 flex items-center gap-1">
          <i class="bi bi-speedometer2"></i> Dashboard
        </a>
        <a href="admin_panel.php" class="text-yellow-200 flex items-center gap-1 font-semibold">
          <i class="bi bi-person-badge"></i> Admin Panel
        </a>
        <a href="logout.php" class="red-orange-gradient-button text-white px-3 py-1 rounded-lg font-semibold hover:shadow-lg flex items-center gap-1">
          <i class="bi bi-box-arrow-right"></i> Logout
        </a>
      </nav>
    </div>
  </header>

  <!-- Main -->
  <main class="flex-grow max-w-3xl mx-auto w-full px-4 py-10">
    <div class="bg-white shadow-xl rounded-2xl overflow-hidden">
      <div class="red-orange-gradient px-6 py-4 flex justify-between items-center">
        <h2 class="text-xl font-bold text-white flex items-center gap-2">
          <i class="bi bi-pencil-square"></i> Edit User
        </h2>
        <a href="admin_panel.php" class="yellow-orange-button text-white font-semibold px-4 py-2 rounded-lg hover:shadow-lg flex items-center gap-2">
          <i class="bi bi-arrow-left"></i> Back to Admin Panel
        </a>
      </div>

      <div class="p-8">
        <?php if (!empty($error)): ?>
          <div class="bg-red-100 text-red-800 border-l-4 border-red-600 p-4 mb-6 rounded-lg">
            <div class="flex items-center">
              <i class="bi bi-exclamation-triangle mr-2"></i>
              <?= $error; ?>
            </div>
          </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
          <div class="bg-green-100 text-green-800 border-l-4 border-green-600 p-4 mb-6 rounded-lg">
            <div class="flex items-center">
              <i class="bi bi-check-circle mr-2"></i>
              <?= $success; ?>
            </div>
          </div>
        <?php endif; ?>

        <form method="POST" class="space-y-6">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
              <label class="block text-sm font-semibold text-gray-700 mb-2">Username</label>
              <input type="text" name="username" value="<?= htmlspecialchars($user['username']); ?>" 
                     class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:ring-2 focus:ring-orange-500 focus:border-orange-500 focus-red-orange transition-all" 
                     required>
            </div>

            <div>
              <label class="block text-sm font-semibold text-gray-700 mb-2">Full Name</label>
              <input type="text" name="full_name" value="<?= htmlspecialchars($user['full_name']); ?>" 
                     class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:ring-2 focus:ring-orange-500 focus:border-orange-500 focus-red-orange transition-all" 
                     required>
            </div>
          </div>

          <div>
            <label class="block text-sm font-semibold text-gray-700 mb-2">Password</label>
            <input type="password" name="password" placeholder="Leave blank to keep current password"
                   class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:ring-2 focus:ring-orange-500 focus:border-orange-500 focus-red-orange transition-all">
            <p class="text-xs text-gray-500 mt-2">Leave blank to keep current password.</p>
          </div>

          <div>
            <label class="block text-sm font-semibold text-gray-700 mb-2">Role</label>
            <select name="role" class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:ring-2 focus:ring-orange-500 focus:border-orange-500 focus-red-orange transition-all">
              <option value="user" <?= $user['role'] === 'user' ? 'selected' : ''; ?>>User</option>
              <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : ''; ?>>Administrator</option>
              <option value="staff" <?= $user['role'] === 'staff' ? 'selected' : ''; ?>>Staff</option>
              <option value="dentist" <?= $user['role'] === 'dentist' ? 'selected' : ''; ?>>Dentist</option>
              <option value="nurse" <?= $user['role'] === 'nurse' ? 'selected' : ''; ?>>Nurse</option>
              <option value="doctor" <?= $user['role'] === 'doctor' ? 'selected' : ''; ?>>Doctor</option>
            </select>
          </div>

          <div class="flex justify-end gap-4 pt-6 border-t border-gray-200">
            <button type="submit" class="red-orange-gradient-button text-white px-6 py-3 rounded-lg font-semibold hover:shadow-lg transition-all flex items-center gap-2">
              <i class="bi bi-save"></i> Update User
            </button>
            <a href="admin_panel.php" class="bg-gray-400 text-white px-6 py-3 rounded-lg hover:bg-gray-500 font-semibold transition-all flex items-center gap-2">
              <i class="bi bi-x-circle"></i> Cancel
            </a>
          </div>
        </form>
      </div>
    </div>
  </main>

  <!-- Footer -->
  <footer class="red-orange-gradient text-white py-4 mt-8">
    <div class="max-w-7xl mx-auto px-6 text-center">
      <small>&copy; <?= date('Y'); ?> Batangas State University - Clinic Record Management System</small>
    </div>
  </footer>

</body>
</html>