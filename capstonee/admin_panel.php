<?php
session_start();
include 'config/database.php';

// Only admin users can access this page
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Handle user deletion
if (isset($_GET['delete_user']) && is_numeric($_GET['delete_user'])) {
    $user_id = $_GET['delete_user'];

    if ($user_id != $_SESSION['user_id']) {
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        if ($stmt->execute()) {
            $success = "User deleted successfully";

            // Log this action
            $action = "Deleted user ID: $user_id";
            $log = $conn->prepare("INSERT INTO analytics_data (user_id, action, timestamp) VALUES (?, ?, NOW())");
            $log->bind_param("is", $_SESSION['user_id'], $action);
            $log->execute();
        } else {
            $error = "Error deleting user.";
        }
    } else {
        $error = "You cannot delete your own account.";
    }
}

// Get users
$users_result = $conn->query("SELECT * FROM users ORDER BY username");
$users = [];
while ($row = $users_result->fetch_assoc()) {
    $users[] = $row;
}

// Log admin panel view
$action = "Viewed Admin Panel";
$stmt = $conn->prepare("INSERT INTO analytics_data (user_id, action, timestamp) VALUES (?, ?, NOW())");
$stmt->bind_param("is", $_SESSION['user_id'], $action);
$stmt->execute();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Admin Panel - BSU Clinic Record Management System</title>
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
    
    .maroon-gradient-light {
      background: linear-gradient(135deg, #fff5f5, #ffe5e5);
    }
    
    .maroon-gradient-card {
      background: linear-gradient(135deg, var(--maroon-primary), var(--maroon-light));
    }
    
    .maroon-gradient-button {
      background: linear-gradient(135deg, var(--maroon-primary), var(--maroon-light));
    }
    
    .maroon-gradient-button:hover {
      background: linear-gradient(135deg, var(--maroon-dark), var(--maroon-primary));
    }
    
    .maroon-gradient-alert {
      background: linear-gradient(135deg, #fff5f5, #ffe5e5);
      border-left-color: var(--maroon-primary);
    }
    
    .maroon-table-header {
      background: linear-gradient(135deg, var(--maroon-primary), var(--maroon-light));
    }
    
    .maroon-table-row {
      background: linear-gradient(135deg, #fff5f5, #ffe5e5);
    }
    
    .maroon-table-row:hover {
      background: linear-gradient(135deg, #ffe5e5, #ffcccc);
    }
    
    .maroon-badge {
      background: linear-gradient(135deg, #ffcccc, #ffb3b3);
      color: #800000;
    }
    
    .maroon-badge-admin {
      background: linear-gradient(135deg, #ffcccc, #ffb3b3);
      color: #800000;
    }
    
    .maroon-badge-user {
      background: linear-gradient(135deg, #bbf7d0, #86efac);
      color: #166534;
    }
    
    .yellow-orange-button {
      background: linear-gradient(135deg, #f59e0b, #d97706);
    }
    
    .yellow-orange-button:hover {
      background: linear-gradient(135deg, #d97706, #b45309);
    }
    
    .bg-maroon-light {
      background-color: #fff5f5;
    }
  </style>
</head>
<body class="bg-gradient-to-br from-orange-50 to-red-50 flex flex-col min-h-screen">

  <!-- Main -->
  <main class="flex-grow max-w-7xl mx-auto px-4 py-8 w-full">
    <div class="bg-white rounded-xl shadow-md overflow-hidden">
      <div class="maroon-table-header px-6 py-4 flex justify-between items-center">
        <h2 class="text-xl font-bold text-white flex items-center gap-2">
          <i class="bi bi-gear-fill"></i> Admin Panel
        </h2>
        <a href="register.php" class="bg-white text-maroon font-semibold px-4 py-2 rounded-lg hover:bg-gray-100 flex items-center gap-2">
          <i class="bi bi-person-plus-fill"></i> Add New User
        </a>
      </div>

      <div class="p-6">
        <?php if (isset($error)): ?>
          <div class="bg-red-100 text-red-800 border-l-4 border-red-600 p-4 mb-4 rounded-lg">
            <i class="bi bi-exclamation-triangle"></i> <?php echo $error; ?>
          </div>
        <?php endif; ?>

        <?php if (isset($success)): ?>
          <div class="bg-green-100 text-green-800 border-l-4 border-green-600 p-4 mb-4 rounded-lg">
            <i class="bi bi-check-circle"></i> <?php echo $success; ?>
          </div>
        <?php endif; ?>

        <!-- User Management -->
        <div class="overflow-x-auto">
          <table class="min-w-full text-sm border rounded-lg">
            <thead class="maroon-table-header text-white">
              <tr>
                <th class="py-3 px-4 text-left">ID</th>
                <th class="py-3 px-4 text-left">Username</th>
                <th class="py-3 px-4 text-left">Full Name</th>
                <th class="py-3 px-4 text-left">Role</th>
                <th class="py-3 px-4 text-left">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <?php foreach ($users as $user): ?>
              <tr class="maroon-table-row hover:shadow transition-all duration-200">
                <td class="py-2 px-4"><?php echo $user['id']; ?></td>
                <td class="py-2 px-4"><?php echo htmlspecialchars($user['username']); ?></td>
                <td class="py-2 px-4"><?php echo htmlspecialchars($user['full_name']); ?></td>
                <td class="py-2 px-4">
                  <span class="px-2 py-1 text-xs font-semibold rounded-full 
                    <?php echo $user['role'] === 'admin' ? 'maroon-badge-admin' : 'maroon-badge-user'; ?>">
                    <?php echo ucfirst($user['role']); ?>
                  </span>
                </td>
                <td class="py-2 px-4 flex gap-2">
                  <a href="edit_user.php?id=<?php echo $user['id']; ?>" class="inline-flex items-center gap-1 px-3 py-1 bg-gray-200 text-gray-800 rounded hover:bg-gray-300 text-xs font-semibold transition-all">
                    <i class="bi bi-pencil-square"></i> Edit
                  </a>
                  <?php if ($user['id'] != $_SESSION['user_id']): ?>
                  <button onclick="confirmDelete(<?php echo $user['id']; ?>)" class="inline-flex items-center gap-1 px-3 py-1 maroon-gradient-button text-white rounded hover:shadow text-xs font-semibold transition-all">
                    <i class="bi bi-trash"></i> Delete
                  </button>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <!-- System Info -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-10">
          <div class="maroon-gradient-light p-5 rounded-lg border-l-4 border-maroon shadow-sm">
            <h3 class="font-semibold text-gray-800 flex items-center gap-2"><i class="bi bi-info-circle text-maroon"></i> PHP Version</h3>
            <p class="text-gray-600 mt-2"><?php echo phpversion(); ?></p>
          </div>
          <div class="maroon-gradient-light p-5 rounded-lg border-l-4 border-maroon shadow-sm">
            <h3 class="font-semibold text-gray-800 flex items-center gap-2"><i class="bi bi-database text-maroon"></i> Database</h3>
            <p class="text-gray-600 mt-2">MySQL <?php echo $conn->server_info; ?></p>
          </div>
        </div>
      </div>
    </div>
  </main>

  <!-- Footer -->
  <footer class="maroon-gradient text-white py-4 mt-8">
    <div class="max-w-7xl mx-auto px-6 text-center">
      <small>&copy; <?php echo date('Y'); ?> Batangas State University - Clinic Record Management System</small>
    </div>
  </footer>

  <script>
    function confirmDelete(id) {
      if (confirm('Are you sure you want to delete this user?')) {
        window.location.href = 'admin_panel.php?delete_user=' + id;
      }
    }
  </script>
</body>
</html>