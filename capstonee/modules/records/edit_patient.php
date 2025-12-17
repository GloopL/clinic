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
  </style>
</head>
<body class="bg-gradient-to-br from-rose-50 to-pink-50">

<div class="max-w-5xl mx-auto bg-white shadow-lg rounded-lg mt-10 p-8">
  <div class="mb-6">
    <h2 class="text-2xl font-bold text-gray-800 flex items-center gap-2">
      <i class="bi bi-person-circle text-maroon"></i> Edit Patient Information
    </h2>
  </div>

  <?php if ($success_message): ?>
    <div class="bg-green-100 text-green-800 p-3 mb-4 rounded-lg border-l-4 border-green-500">
      <div class="flex items-center">
        <i class="bi bi-check-circle mr-2"></i>
        <?= $success_message ?>
      </div>
    </div>
  <?php elseif ($error_message): ?>
    <div class="bg-red-100 text-red-800 p-3 mb-4 rounded-lg border-l-4 border-red-500">
      <div class="flex items-center">
        <i class="bi bi-exclamation-circle mr-2"></i>
        <?= $error_message ?>
      </div>
    </div>
  <?php endif; ?>

  <form method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-6">
    <div>
      <label class="block font-semibold text-gray-700 mb-2">Student ID</label>
      <input type="text" name="student_id" value="<?= htmlspecialchars($patient['student_id']); ?>" required
             class="w-full border border-gray-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-red-800 focus:border-red-800 focus-maroon transition-all">
    </div>
    <div>
      <label class="block font-semibold text-gray-700 mb-2">Sex</label>
      <select name="sex" required class="w-full border border-gray-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-red-800 focus:border-red-800 focus-maroon transition-all">
        <option value="Male" <?= ($patient['sex'] === 'Male') ? 'selected' : ''; ?>>Male</option>
        <option value="Female" <?= ($patient['sex'] === 'Female') ? 'selected' : ''; ?>>Female</option>
      </select>
    </div>
    <div>
      <label class="block font-semibold text-gray-700 mb-2">Last Name</label>
      <input type="text" name="last_name" value="<?= htmlspecialchars($patient['last_name']); ?>" required
             class="w-full border border-gray-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-red-800 focus:border-red-800 focus-maroon transition-all">
    </div>
    <div>
      <label class="block font-semibold text-gray-700 mb-2">First Name</label>
      <input type="text" name="first_name" value="<?= htmlspecialchars($patient['first_name']); ?>" required
             class="w-full border border-gray-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-red-800 focus:border-red-800 focus-maroon transition-all">
    </div>
    <div>
      <label class="block font-semibold text-gray-700 mb-2">Middle Name</label>
      <input type="text" name="middle_name" value="<?= htmlspecialchars($patient['middle_name']); ?>"
             class="w-full border border-gray-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-red-800 focus:border-red-800 focus-maroon transition-all">
    </div>
    <div>
      <label class="block font-semibold text-gray-700 mb-2">Date of Birth</label>
      <input type="date" name="date_of_birth" value="<?= htmlspecialchars($patient['date_of_birth']); ?>" required
             class="w-full border border-gray-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-red-800 focus:border-red-800 focus-maroon transition-all">
    </div>
    <div>
      <label class="block font-semibold text-gray-700 mb-2">Program</label>
      <input type="text" name="program" value="<?= htmlspecialchars($patient['program']); ?>" required
             class="w-full border border-gray-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-red-800 focus:border-red-800 focus-maroon transition-all">
    </div>
    <div>
      <label class="block font-semibold text-gray-700 mb-2">Year Level</label>
      <select name="year_level" required class="w-full border border-gray-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-red-800 focus:border-red-800 focus-maroon transition-all">
        <?php
        $years = ['1st Year','2nd Year','3rd Year','4th Year','5th Year','Graduate'];
        foreach ($years as $year) {
            $selected = ($patient['year_level'] === $year) ? 'selected' : '';
            echo "<option value='$year' $selected>$year</option>";
        }
        ?>
      </select>
    </div>
    <div class="md:col-span-2 flex justify-end gap-4 pt-6 border-t border-gray-200">
      <button type="submit" class="maroon-gradient-button text-white font-semibold px-6 py-3 rounded-lg hover:shadow-lg transition-all flex items-center gap-2">
        <i class="bi bi-save"></i> Update Information
      </button>
    </div>
  </form>
</div>

</body>
</html>