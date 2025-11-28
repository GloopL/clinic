<?php
session_start();
include 'config/database.php';

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Check if user is nurse
if ($_SESSION['role'] !== 'nurse') {
    header("Location: dashboard.php");
    exit();
}

// Get user information
$stmt = $conn->prepare("SELECT username, role, full_name FROM users WHERE id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

// Determine display name - prefer full_name, fallback to username
$display_name = !empty($user['full_name']) ? trim($user['full_name']) : $user['username'];

// Get counts for dashboard
$total_patients = $conn->query("SELECT COUNT(*) as count FROM patients")->fetch_assoc()['count'];
$total_records = $conn->query("SELECT COUNT(*) as count FROM medical_records")->fetch_assoc()['count'];
$total_history_forms = $conn->query("SELECT COUNT(*) as count FROM medical_records WHERE record_type = 'history_form'")->fetch_assoc()['count'];
$total_dental_exams = $conn->query("SELECT COUNT(*) as count FROM medical_records WHERE record_type = 'dental_exam'")->fetch_assoc()['count'];
$total_medical_exams = $conn->query("SELECT COUNT(*) as count FROM medical_records WHERE record_type = 'medical_exam'")->fetch_assoc()['count'];

// Get pending verifications count
$pending_verifications = $conn->query("SELECT COUNT(*) as count FROM medical_records WHERE verification_status = 'pending'")->fetch_assoc()['count'];

// Get recent patients
$recent_patients_query = "
    SELECT p.*,
            MAX(mr.examination_date) as last_visit
    FROM patients p
    LEFT JOIN medical_records mr ON p.id = mr.patient_id
    GROUP BY p.id
    ORDER BY last_visit DESC
    LIMIT 5
";
$recent_patients_result = $conn->query($recent_patients_query);
$recent_patients = [];
while ($row = $recent_patients_result->fetch_assoc()) {
    $recent_patients[] = $row;
}

// Get recent activity
$recent_activity_query = "
    SELECT
        a.action,
        a.timestamp,
        u.username,
        u.full_name
    FROM
        analytics_data a
    LEFT JOIN
        users u ON a.user_id = u.id
    ORDER BY
        a.timestamp DESC
    LIMIT 5
";
$recent_activity_result = $conn->query($recent_activity_query);
$recent_activity = [];
while ($row = $recent_activity_result->fetch_assoc()) {
    // Use full_name if available, otherwise fallback to username
    $row['display_name'] = !empty($row['full_name']) ? trim($row['full_name']) : $row['username'];
    $recent_activity[] = $row;
}

// Log this dashboard view in analytics
$action = "Viewed Nurse Dashboard";
$stmt = $conn->prepare("
    INSERT INTO analytics_data (user_id, action, timestamp)
    VALUES (?, ?, NOW())
");
$stmt->bind_param("is", $_SESSION['user_id'], $action);
$stmt->execute();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Nurse Dashboard - BSU Clinic Records</title>
  <script src="https://cdn.tailwindcss.com"></script>
   <link rel="icon" type="image/png" href="assets/css/images/logo-bsu.png">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
  <style>
    @keyframes pulse-badge {
      0%, 100% { transform: scale(1); }
      50% { transform: scale(1.1); }
    }
    .pulse-badge {
      animation: pulse-badge 2s infinite;
    }
    
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
    
    .red-orange-gradient-alert {
      background: linear-gradient(135deg, #fef2f2, #ffedd5);
      border-left-color: #ea580c;
    }
    
    .red-orange-table-header {
      background: linear-gradient(135deg, #dc2626, #ea580c);
    }
    
    .red-orange-table-row {
      background: linear-gradient(135deg, #fef2f2, #ffedd5);
    }
    
    .red-orange-table-row:hover {
      background: linear-gradient(135deg, #fee2e2, #fed7aa);
    }
    
    .red-orange-badge {
      background: linear-gradient(135deg, #fecaca, #fed7aa);
      color: #7c2d12;
    }
    
    .red-orange-badge-verified {
      background: linear-gradient(135deg, #dcfce7, #bbf7d0);
      color: #166534;
    }
    
    .red-orange-badge-pending {
      background: linear-gradient(135deg, #fef3c7, #fde68a);
      color: #92400e;
    }
    
    .red-orange-badge-rejected {
      background: linear-gradient(135deg, #fee2e2, #fecaca);
      color: #991b1b;
    }
    
    .stats-card-1 {
      background: linear-gradient(135deg, #dc2626, #ea580c);
    }
    
    .stats-card-2 {
      background: linear-gradient(135deg, #ea580c, #f97316);
    }
    
    .stats-card-3 {
      background: linear-gradient(135deg, #f97316, #fb923c);
    }
    
    .stats-card-4 {
      background: linear-gradient(135deg, #fb923c, #fdba74);
    }
  </style>
</head>
<body class="bg-gradient-to-br from-orange-50 to-red-50 flex flex-col min-h-screen">

  <!-- HEADER (same red-orange navigation used across doctor pages) -->
  <header class="red-orange-gradient text-white shadow-md sticky top-0 z-50">
    <div class="max-w-7xl mx-auto flex items-center justify-between px-6 py-3">
      <div class="flex items-center gap-3">
        <img src="assets/css/images/logo-bsu.png" alt="BSU Logo" class="w-12 h-12 rounded-full object-cover border-4 border-white bg-white">
        <h1 class="text-lg font-bold">BSU Clinic Record Management System</h1>
      </div>
      <nav class="flex items-center gap-6">
        <a href="nurse_dashboard.php" class="hover:text-yellow-200 flex items-center gap-1 font-semibold">
          <i class="bi bi-speedometer2"></i> Dashboard
        </a>
        <a href="logout.php" class="red-orange-gradient-button text-white px-3 py-1 rounded-lg font-semibold hover:shadow-lg flex items-center gap-1">
          <i class="bi bi-box-arrow-right"></i> Logout
        </a>
      </nav>
    </div>
  </header>

  <main class="flex-grow max-w-7xl mx-auto px-4 py-8 w-full">

    <?php if ($pending_verifications > 0): ?>
    <div class="red-orange-gradient-alert p-4 mb-8 rounded-lg shadow-md border-l-4 border-orange-500">
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
          <i class="bi bi-exclamation-triangle text-orange-600 text-3xl pulse-badge"></i>
          <div>
            <h3 class="text-lg font-bold text-orange-800">Pending Verifications</h3>
            <p class="text-orange-700">You have <strong><?php echo $pending_verifications; ?></strong> submission(s) waiting for verification.</p>
          </div>
        </div>
        <a href="modules/records/verify_submission.php" class="red-orange-gradient-button text-white font-semibold px-6 py-3 rounded-lg shadow transition">
          <i class="bi bi-shield-check mr-2"></i>Review Now
        </a>
      </div>
    </div>
    <?php endif; ?>

    <div class="bg-white rounded-xl shadow-md overflow-hidden mb-10">
      <div class="flex justify-between items-center px-4 py-3 red-orange-table-header text-white">
        <h3 class="font-semibold flex items-center gap-2"><i class="bi bi-shield-check"></i> Recent Submissions</h3>
        <a href="modules/records/verify_submission.php" class="px-3 py-1 bg-white bg-opacity-20 text-white text-sm font-semibold rounded hover:bg-opacity-30 backdrop-blur-sm">View All</a>
      </div>
      <div class="p-4">
        <?php
        // Get 5 most recent submissions
        $verifications = [];
        $query = "
            SELECT mr.id, mr.record_type, mr.examination_date, mr.verification_status,
                    p.first_name, p.last_name, p.student_id, mr.created_at
            FROM medical_records mr
            JOIN patients p ON mr.patient_id = p.id
            ORDER BY mr.created_at DESC
            LIMIT 5
        ";
        $result = $conn->query($query);
        while ($row = $result->fetch_assoc()) {
            $verifications[] = $row;
        }
        ?>
        <div class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="red-orange-table-header text-white">
              <tr>
                <th class="py-2 px-3">Student ID</th>
                <th class="py-2 px-3">Name</th>
                <th class="py-2 px-3">Type</th>
                <th class="py-2 px-3">Date</th>
                <th class="py-2 px-3">Status</th>
                <th class="py-2 px-3">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-orange-100">
              <?php if (count($verifications) > 0): ?>
                <?php foreach ($verifications as $v): ?>
                <tr class="red-orange-table-row hover:shadow transition-all duration-200">
                  <td class="py-2 px-3"><?php echo htmlspecialchars($v['student_id']); ?></td>
                  <td class="py-2 px-3"><?php echo htmlspecialchars($v['last_name']) . ', ' . htmlspecialchars($v['first_name']); ?></td>
                  <td class="py-2 px-3"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $v['record_type']))); ?></td>
                  <td class="py-2 px-3"><?php echo htmlspecialchars($v['examination_date']); ?></td>
                  <td class="py-2 px-3">
                    <span class="px-2 py-1 rounded-full text-xs font-semibold <?php
                      echo $v['verification_status'] === 'verified' ? 'red-orange-badge-verified' :
                          ($v['verification_status'] === 'rejected' ? 'red-orange-badge-rejected' :
                          'red-orange-badge-pending');
                    ?>">
                      <?php echo strtoupper($v['verification_status']); ?>
                    </span>
                  </td>
                  <td class="py-2 px-3">
                    <a href="modules/records/verify_submission.php?type=<?php echo $v['record_type']; ?>&id=<?php echo $v['id']; ?>" class="inline-flex items-center gap-1 px-2 py-1 red-orange-badge rounded hover:shadow text-xs font-semibold transition-all">
                      <i class="bi bi-eye"></i> Review
                    </a>
                  </td>
                </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr>
                  <td colspan="6" class="red-orange-gradient-alert text-orange-700 p-3 rounded text-center">No submissions found.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="bg-white shadow-lg rounded-2xl overflow-hidden mb-8">
      <div class="red-orange-gradient px-6 py-4">
        <h1 class="text-2xl font-bold text-white flex items-center gap-2">
          <i class="bi bi-speedometer2"></i> Nurse Dashboard
        </h1>
      </div>
      <div class="p-6">
        <div class="red-orange-gradient-alert p-4 rounded-lg mb-6 border-l-4 border-orange-500">
          <h2 class="text-lg font-semibold text-orange-900">Welcome, <?php echo htmlspecialchars($display_name); ?>!</h2>
          <p class="text-orange-700">This is the Nurse Dashboard. Use the menu to access different modules.</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-10">
          <div class="stats-card-1 text-white rounded-xl p-6 flex flex-col items-center shadow-md hover:shadow-lg transition-all">
            <i class="bi bi-people-fill text-4xl mb-2"></i>
            <h3 class="text-3xl font-bold"><?php echo $total_patients; ?></h3>
            <p>Total Patients</p>
            <a href="modules/records/patients.php" class="mt-4 bg-white text-red-600 px-3 py-1 rounded-lg font-semibold text-sm hover:bg-red-50 transition">View All</a>
          </div>
          <div class="stats-card-2 text-white rounded-xl p-6 flex flex-col items-center shadow-md hover:shadow-lg transition-all">
            <i class="bi bi-clipboard2-pulse-fill text-4xl mb-2"></i>
            <h3 class="text-3xl font-bold"><?php echo $total_history_forms; ?></h3>
            <p>Medical History Forms</p>
            <a href="modules/forms/history_form.php" class="mt-4 bg-white text-orange-600 px-3 py-1 rounded-lg font-semibold text-sm hover:bg-orange-50 transition">New Form</a>
          </div>
          <div class="stats-card-3 text-white rounded-xl p-6 flex flex-col items-center shadow-md hover:shadow-lg transition-all">
            <i class="bi bi-person-badge-fill text-4xl mb-2"></i>
            <h3 class="text-3xl font-bold"><?php echo $total_dental_exams; ?></h3>
            <p>Dental Examinations</p>
            <a href="modules/forms/dental_form.php" class="mt-4 bg-white text-orange-600 px-3 py-1 rounded-lg font-semibold text-sm hover:bg-orange-50 transition">New Exam</a>
          </div>
          <div class="stats-card-4 text-white rounded-xl p-6 flex flex-col items-center shadow-md hover:shadow-lg transition-all">
            <i class="bi bi-heart-pulse-fill text-4xl mb-2"></i>
            <h3 class="text-3xl font-bold"><?php echo $total_medical_exams; ?></h3>
            <p>Medical Examinations</p>
            <a href="modules/forms/medical_form.php" class="mt-4 bg-white text-orange-600 px-3 py-1 rounded-lg font-semibold text-sm hover:bg-orange-50 transition">New Exam</a>
          </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-10">
          <div class="bg-white rounded-xl shadow-md overflow-hidden">
            <div class="flex justify-between items-center px-4 py-3 red-orange-table-header text-white">
              <h3 class="font-semibold flex items-center gap-2"><i class="bi bi-clock-history"></i> Recent Patients</h3>
              <a href="modules/records/patients.php" class="px-3 py-1 bg-white bg-opacity-20 text-white text-sm font-semibold rounded hover:bg-opacity-30 backdrop-blur-sm">View All</a>
            </div>
            <div class="p-4">
              <?php if (count($recent_patients) > 0): ?>
              <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                  <thead class="red-orange-table-header text-white">
                    <tr>
                      <th class="py-2 px-3">Student ID</th>
                      <th class="py-2 px-3">Name</th>
                      <th class="py-2 px-3">Last Visit</th>
                      <th class="py-2 px-3">Actions</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-orange-100">
                    <?php foreach ($recent_patients as $patient): ?>
                    <tr class="red-orange-table-row hover:shadow transition-all duration-200">
                      <td class="py-2 px-3"><?php echo htmlspecialchars($patient['student_id']); ?></td>
                      <td class="py-2 px-3"><?php echo htmlspecialchars($patient['last_name']) . ', ' . htmlspecialchars($patient['first_name']); ?></td>
                      <td class="py-2 px-3"><?php echo $patient['last_visit'] ? htmlspecialchars($patient['last_visit']) : 'No visits'; ?></td>
                      <td class="py-2 px-3">
                        <a href="modules/records/view_patient.php?id=<?php echo $patient['id']; ?>" class="inline-flex items-center gap-1 px-2 py-1 red-orange-badge rounded hover:shadow text-xs font-semibold transition-all">
                          <i class="bi bi-eye"></i> View
                        </a>
                      </td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <?php else: ?>
                <div class="red-orange-gradient-alert text-orange-700 p-3 rounded">No patients found in the system.</div>
              <?php endif; ?>
            </div>
          </div>

          <div class="bg-white rounded-xl shadow-md overflow-hidden">
            <div class="flex justify-between items-center px-4 py-3 red-orange-table-header text-white">
              <h3 class="font-semibold flex items-center gap-2"><i class="bi bi-activity"></i> Recent Activity</h3>
              <a href="modules/analytics/analytics_dashboard.php" class="red-orange-gradient-button text-white px-4 py-2 rounded-lg hover:shadow transition-all">
                <i class="bi bi-bar-chart-line"></i> View Analytics
              </a>
            </div>
            <div class="p-4">
              <?php if (count($recent_activity) > 0): ?>
              <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                  <thead class="red-orange-table-header text-white">
                    <tr>
                      <th class="py-2 px-3">Action</th>
                      <th class="py-2 px-3">User</th>
                      <th class="py-2 px-3">Timestamp</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-orange-100">
                    <?php foreach ($recent_activity as $activity): ?>
                    <tr class="red-orange-table-row hover:shadow transition-all duration-200">
                      <td class="py-2 px-3"><?php echo htmlspecialchars($activity['action']); ?></td>
                      <td class="py-2 px-3"><?php echo htmlspecialchars($activity['display_name']); ?></td>
                      <td class="py-2 px-3"><?php echo htmlspecialchars($activity['timestamp']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <?php else: ?>
                <div class="red-orange-gradient-alert text-orange-700 p-3 rounded">No recent activity found.</div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- QR-related dashboard cards removed (UI-only) to simplify nurse view.
             Backend QR functionality is left intact so it can be restored later if needed. -->
      </div>
    </div>
  </main>

  <footer class="red-orange-gradient text-white py-4 mt-8">
    <div class="max-w-7xl mx-auto px-6 text-center">
      <small>&copy; <?php echo date('Y'); ?> Batangas State University - Clinic Record Management System</small>
    </div>
  </footer>
</body>
</html>