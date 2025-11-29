<?php
session_start();
include '../../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$success_message = '';
$error_message = '';
$patient_id = $_GET['id'] ?? null;

if (!$patient_id || !is_numeric($patient_id)) {
    header("Location: patients.php");
    exit();
}

// ✅ Process Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_id = $_POST['student_id'];
    $first_name = $_POST['first_name'];
    $middle_name = $_POST['middle_name'];
    $last_name = $_POST['last_name'];
    $sex = $_POST['sex'];
    $date_of_birth = $_POST['date_of_birth'];
    $program = $_POST['program'];
    $year_level = $_POST['year_level'];

    // Check if Student ID already exists for another patient
    $stmt = $conn->prepare("SELECT id FROM patients WHERE student_id = ? AND id != ?");
    $stmt->bind_param("si", $student_id, $patient_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $error_message = "Student ID already exists for another patient.";
    } else {
        // ✅ Update patient record
        $stmt = $conn->prepare("
            UPDATE patients 
            SET student_id = ?, first_name = ?, middle_name = ?, last_name = ?, 
                sex = ?, date_of_birth = ?, program = ?, year_level = ?
            WHERE id = ?
        ");
        $stmt->bind_param("ssssssssi", $student_id, $first_name, $middle_name, $last_name, 
                          $sex, $date_of_birth, $program, $year_level, $patient_id);

        if ($stmt->execute()) {
            // ✅ Log the update in analytics (fixed query)
          // ✅ Corrected logging query
            $action = "Updated patient record";
            $stmt = $conn->prepare("
                INSERT INTO analytics_data (user_id, action, timestamp)
                VALUES (?, ?, NOW())
            ");
            $stmt->bind_param("is", $_SESSION['user_id'], $action);
            $stmt->execute();


            $success_message = "Patient information updated successfully.";
        } else {
            $error_message = "Error updating patient information: " . $conn->error;
        }
    }
}

// ✅ Get patient details
$stmt = $conn->prepare("SELECT * FROM patients WHERE id = ?");
$stmt->bind_param("i", $patient_id);
$stmt->execute();
$result = $stmt->get_result();
$patient = $result->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Edit Patient - BSU Clinic</title>
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
  <style>
    .red-orange-gradient {
      background: linear-gradient(135deg, #dc2626, #ea580c, #f97316);
    }
    
    .red-orange-gradient-button {
      background: linear-gradient(135deg, #dc2626, #ea580c);
    }
    
    .red-orange-gradient-button:hover {
      background: linear-gradient(135deg, #b91c1c, #c2410c);
    }
    
    .red-orange-gradient-light {
      background: linear-gradient(135deg, #fef2f2, #ffedd5, #fed7aa);
    }
  </style>
</head>
<body class="bg-gradient-to-br from-orange-50 to-red-50">

<header class="red-orange-gradient text-white shadow-md">
  <div class="max-w-7xl mx-auto flex items-center justify-between px-6 py-3">
    <div class="flex items-center gap-3">
      <img src="../../assets/css/images/logo-bsu.png" alt="BSU Logo" class="w-10 h-10 rounded-full border-2 border-white bg-white">
      <h1 class="text-lg font-bold">BSU Clinic Record Management System</h1>
    </div>
    <nav class="flex items-center gap-5">
      <a href="../../dashboard.php" class="hover:text-yellow-200"><i class="bi bi-speedometer2"></i> Dashboard</a>
      <a href="../records/view_patient.php" class="hover:text-yellow-200"><i class="bi bi-people"></i> View Patients</a>
      <a href="../../logout.php" class="red-orange-gradient-button text-white px-3 py-1 rounded-lg font-semibold hover:shadow-lg flex items-center gap-1">
        <i class="bi bi-box-arrow-right"></i> Logout
      </a>
    </nav>
  </div>
</header>

<div class="max-w-5xl mx-auto bg-white shadow-lg rounded-lg mt-10 p-8">
  <div class="flex justify-between items-center mb-6">
    <h2 class="text-2xl font-bold text-orange-800">Edit Patient Information</h2>
    <a href="view_patient.php?id=<?php echo $patient_id; ?>" 
       class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg font-semibold shadow transition-all">
       <i class="bi bi-arrow-left"></i> Back
    </a>
  </div>

  <?php if ($success_message): ?>
    <div class="bg-green-100 text-green-800 p-3 mb-4 rounded-lg text-center font-semibold border border-green-300">
      <?= $success_message ?>
    </div>
  <?php elseif ($error_message): ?>
    <div class="bg-red-100 text-red-800 p-3 mb-4 rounded-lg text-center font-semibold border border-red-300">
      <?= $error_message ?>
    </div>
  <?php endif; ?>

  <form method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <div>
      <label class="font-semibold text-orange-700">Student ID</label>
      <input type="text" name="student_id" value="<?= htmlspecialchars($patient['student_id']); ?>" required
             class="w-full border border-orange-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-orange-500 focus:border-orange-500 transition-all">
    </div>
    <div>
      <label class="font-semibold text-orange-700">Sex</label>
      <select name="sex" required class="w-full border border-orange-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-orange-500 focus:border-orange-500 transition-all">
        <option value="Male" <?= ($patient['sex'] === 'Male') ? 'selected' : ''; ?>>Male</option>
        <option value="Female" <?= ($patient['sex'] === 'Female') ? 'selected' : ''; ?>>Female</option>
      </select>
    </div>
    <div>
      <label class="font-semibold text-orange-700">Last Name</label>
      <input type="text" name="last_name" value="<?= htmlspecialchars($patient['last_name']); ?>" required
             class="w-full border border-orange-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-orange-500 focus:border-orange-500 transition-all">
    </div>
    <div>
      <label class="font-semibold text-orange-700">First Name</label>
      <input type="text" name="first_name" value="<?= htmlspecialchars($patient['first_name']); ?>" required
             class="w-full border border-orange-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-orange-500 focus:border-orange-500 transition-all">
    </div>
    <div>
      <label class="font-semibold text-orange-700">Middle Name</label>
      <input type="text" name="middle_name" value="<?= htmlspecialchars($patient['middle_name']); ?>"
             class="w-full border border-orange-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-orange-500 focus:border-orange-500 transition-all">
    </div>
    <div>
      <label class="font-semibold text-orange-700">Date of Birth</label>
      <input type="date" name="date_of_birth" value="<?= htmlspecialchars($patient['date_of_birth']); ?>" required
             class="w-full border border-orange-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-orange-500 focus:border-orange-500 transition-all">
    </div>
    <div>
      <label class="font-semibold text-orange-700">Program</label>
      <input type="text" name="program" value="<?= htmlspecialchars($patient['program']); ?>" required
             class="w-full border border-orange-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-orange-500 focus:border-orange-500 transition-all">
    </div>
    <div>
      <label class="font-semibold text-orange-700">Year Level</label>
      <select name="year_level" required class="w-full border border-orange-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-orange-500 focus:border-orange-500 transition-all">
        <?php
        $years = ['1st Year','2nd Year','3rd Year','4th Year','5th Year','Graduate'];
        foreach ($years as $year) {
            $selected = ($patient['year_level'] === $year) ? 'selected' : '';
            echo "<option value='$year' $selected>$year</option>";
        }
        ?>
      </select>
    </div>
    <div class="md:col-span-2 flex justify-center gap-4 mt-6">
      <button type="submit" class="red-orange-gradient-button text-white font-semibold px-6 py-2 rounded-lg shadow hover:shadow-lg transition-all">
        <i class="bi bi-save"></i> Update Information
      </button>
      <a href="../records/patients.php" 
         class="bg-gray-400 hover:bg-gray-500 text-white font-semibold px-6 py-2 rounded-lg shadow hover:shadow-lg transition-all">
         <i class="bi bi-x-circle"></i> Cancel
      </a>
    </div>
  </form>
</div>

</body>
</html>