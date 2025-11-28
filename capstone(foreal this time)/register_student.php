<?php
session_start();
include 'config/database.php';

$error = '';
$success = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $sr_code = trim($_POST['sr_code']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $first_name = trim($_POST['first_name']);
    $middle_name = trim($_POST['middle_name']);
    $last_name = trim($_POST['last_name']);
    $date_of_birth = $_POST['date_of_birth'];
    $age = trim($_POST['age']);
    $sex = $_POST['sex'];
    $civil_status = trim($_POST['civil_status']);
    $contact_number = trim($_POST['contact_number']);
    $address = trim($_POST['address']);
    $program = trim($_POST['program']);
    $year_level = trim($_POST['year_level']);

    if (empty($sr_code) || empty($password) || empty($confirm_password) || empty($first_name) || empty($last_name) || empty($date_of_birth) || empty($sex)) {
        $error = "Required fields are missing.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } else {
        // Check if SR code already exists in users
        $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->bind_param("s", $sr_code);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $error = "SR Code already registered.";
        } else {
            // Check if student_id already exists in patients
            $stmt_patient = $conn->prepare("SELECT * FROM patients WHERE student_id = ?");
            $stmt_patient->bind_param("s", $sr_code);
            $stmt_patient->execute();
            $result_patient = $stmt_patient->get_result();

            if ($result_patient->num_rows > 0) {
                $error = "Student ID already exists.";
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $role = 'user';
                // Combine first name, middle name (if provided), and last name
                $name_parts = array_filter([$first_name, $middle_name, $last_name]);
                $full_name = trim(implode(' ', $name_parts));
                $email = '';

                // Insert into users table
                $stmt = $conn->prepare("INSERT INTO users (username, password, full_name, email, role) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("sssss", $sr_code, $hashed_password, $full_name, $email, $role);

                if ($stmt->execute()) {
                    // Insert into patients table (removed age and civil_status as they don't exist in schema)
                    $stmt_patient = $conn->prepare("INSERT INTO patients (student_id, first_name, middle_name, last_name, date_of_birth, sex, contact_number, address, program, year_level) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt_patient->bind_param("ssssssssss", $sr_code, $first_name, $middle_name, $last_name, $date_of_birth, $sex, $contact_number, $address, $program, $year_level);

                    if ($stmt_patient->execute()) {
                        $success = "Registration successful! You can now log in.";
                    } else {
                        $error = "Patient registration failed. Please try again.";
                    }
                } else {
                    $error = "User registration failed. Please try again.";
                }
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
  <title>Student Registration - BSU Clinic Record Management System</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
  <style>
    /* Custom CSS for the Red-to-Orange Gradient Top Border */
    .gradient-border-top {
        position: relative;
        /* Using a transparent border to reserve the space, 
           as we apply the real border via the ::before pseudo-element */
        border-top: 8px solid transparent; 
    }
    .gradient-border-top::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 8px; /* The thickness of the border */
        border-radius: 1rem 1rem 0 0; /* Matches parent's rounded-2xl (1rem) top corners */
        /* Red-800 (#b91c1c) to Orange-500 (#f97316) */
        background: linear-gradient(90deg, #b91c1c, #f97316); 
    }
    /* Ensure the main content uses Inter font for a modern look */
    body {
        font-family: 'Inter', sans-serif;
    }
  </style>
</head>

<body class="bg-gray-100 flex justify-center items-center min-h-screen p-4">

  <!-- Main Registration Card/Modal -->
  <div class="bg-white shadow-2xl rounded-2xl w-full max-w-lg overflow-hidden gradient-border-top">
    
    <div class="bg-white px-8 py-8">
      <div class="text-center mb-4">
        <i class="bi bi-person-plus-fill text-6xl text-red-700"></i>  
      </div>
      <h2 class="text-3xl font-extrabold text-gray-800 text-center tracking-tight">
        Student Account Registration
      </h2>
      <p class="text-md text-gray-500 text-center mt-2">Secure your clinic portal access with your official SR Code.</p>
    </div>

    <div class="p-8 bg-gray-50 border-t border-gray-100">
      
      <?php if (!empty($error)): ?>
        <div class="bg-red-100 text-red-800 border-l-4 border-red-600 p-4 mb-6 rounded-lg font-medium flex items-center gap-3 shadow-sm">
          <i class="bi bi-exclamation-circle text-lg"></i> <?= $error; ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($success)): ?>
        <div class="bg-green-100 text-green-800 border-l-4 border-green-600 p-4 mb-6 rounded-lg font-medium flex items-center gap-3 shadow-sm">
          <i class="bi bi-check-circle text-lg"></i> <?= $success; ?>
        </div>
      <?php endif; ?>

      <form method="POST" action="" class="space-y-6">

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label for="first_name" class="block text-sm font-semibold text-gray-700 mb-2">
                <i class="bi bi-person mr-2 text-red-700"></i> First Name
            </label>
            <input type="text" name="first_name" id="first_name" placeholder="Enter your first name"
              class="w-full border border-gray-300 rounded-xl px-5 py-3 text-lg focus:border-red-700 focus:ring-1 focus:ring-red-700 focus:outline-none transition duration-150 shadow-sm" required>
          </div>

          <div>
            <label for="middle_name" class="block text-sm font-semibold text-gray-700 mb-2">
                <i class="bi bi-person mr-2 text-red-700"></i> Middle Name
            </label>
            <input type="text" name="middle_name" id="middle_name" placeholder="Enter your middle name (optional)"
              class="w-full border border-gray-300 rounded-xl px-5 py-3 text-lg focus:border-red-700 focus:ring-1 focus:ring-red-700 focus:outline-none transition duration-150 shadow-sm">
          </div>
        </div>

        <div>
          <label for="last_name" class="block text-sm font-semibold text-gray-700 mb-2">
              <i class="bi bi-person mr-2 text-red-700"></i> Last Name
          </label>
          <input type="text" name="last_name" id="last_name" placeholder="Enter your last name"
            class="w-full border border-gray-300 rounded-xl px-5 py-3 text-lg focus:border-red-700 focus:ring-1 focus:ring-red-700 focus:outline-none transition duration-150 shadow-sm" required>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div>
            <label for="date_of_birth" class="block text-sm font-semibold text-gray-700 mb-2">
                <i class="bi bi-calendar mr-2 text-red-700"></i> Date of Birth
            </label>
            <input type="date" name="date_of_birth" id="date_of_birth"
              class="w-full border border-gray-300 rounded-xl px-5 py-3 text-lg focus:border-red-700 focus:ring-1 focus:ring-red-700 focus:outline-none transition duration-150 shadow-sm" required>
          </div>

          <div>
            <label for="age" class="block text-sm font-semibold text-gray-700 mb-2">
                <i class="bi bi-hash mr-2 text-red-700"></i> Age
            </label>
            <input type="number" name="age" id="age" placeholder="Enter age"
              class="w-full border border-gray-300 rounded-xl px-5 py-3 text-lg focus:border-red-700 focus:ring-1 focus:ring-red-700 focus:outline-none transition duration-150 shadow-sm" min="1" max="120">
          </div>

          <div>
            <label for="sex" class="block text-sm font-semibold text-gray-700 mb-2">
                <i class="bi bi-gender-ambiguous mr-2 text-red-700"></i> Sex
            </label>
            <select name="sex" id="sex"
              class="w-full border border-gray-300 rounded-xl px-5 py-3 text-lg focus:border-red-700 focus:ring-1 focus:ring-red-700 focus:outline-none transition duration-150 shadow-sm" required>
              <option value="">Select Sex</option>
              <option value="Male">Male</option>
              <option value="Female">Female</option>
              <option value="Other">Other</option>
            </select>
          </div>
        </div>

        <div>
          <label for="civil_status" class="block text-sm font-semibold text-gray-700 mb-2">
              <i class="bi bi-heart mr-2 text-red-700"></i> Civil Status
          </label>
          <select name="civil_status" id="civil_status"
            class="w-full border border-gray-300 rounded-xl px-5 py-3 text-lg focus:border-red-700 focus:ring-1 focus:ring-red-700 focus:outline-none transition duration-150 shadow-sm">
            <option value="">Select Civil Status</option>
            <option value="Single">Single</option>
            <option value="Married">Married</option>
            <option value="Divorced">Divorced</option>
            <option value="Widowed">Widowed</option>
          </select>
        </div>

        <div>
          <label for="contact_number" class="block text-sm font-semibold text-gray-700 mb-2">
              <i class="bi bi-telephone mr-2 text-red-700"></i> Contact Number
          </label>
          <input type="text" name="contact_number" id="contact_number" placeholder="Enter your contact number"
            class="w-full border border-gray-300 rounded-xl px-5 py-3 text-lg focus:border-red-700 focus:ring-1 focus:ring-red-700 focus:outline-none transition duration-150 shadow-sm">
        </div>

        <div>
          <label for="address" class="block text-sm font-semibold text-gray-700 mb-2">
              <i class="bi bi-geo-alt mr-2 text-red-700"></i> Address
          </label>
          <textarea name="address" id="address" placeholder="Enter your address" rows="3"
            class="w-full border border-gray-300 rounded-xl px-5 py-3 text-lg focus:border-red-700 focus:ring-1 focus:ring-red-700 focus:outline-none transition duration-150 shadow-sm"></textarea>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label for="program" class="block text-sm font-semibold text-gray-700 mb-2">
                <i class="bi bi-mortarboard mr-2 text-red-700"></i> Program/Course
            </label>
            <input type="text" name="program" id="program" placeholder="Enter your program/course"
              class="w-full border border-gray-300 rounded-xl px-5 py-3 text-lg focus:border-red-700 focus:ring-1 focus:ring-red-700 focus:outline-none transition duration-150 shadow-sm">
          </div>

          <div>
            <label for="year_level" class="block text-sm font-semibold text-gray-700 mb-2">
                <i class="bi bi-hash mr-2 text-red-700"></i> Year Level
            </label>
            <select name="year_level" id="year_level"
              class="w-full border border-gray-300 rounded-xl px-5 py-3 text-lg focus:border-red-700 focus:ring-1 focus:ring-red-700 focus:outline-none transition duration-150 shadow-sm">
              <option value="">Select Year Level</option>
              <option value="1st Year">1st Year</option>
              <option value="2nd Year">2nd Year</option>
              <option value="3rd Year">3rd Year</option>
              <option value="4th Year">4th Year</option>
              <option value="5th Year">5th Year</option>
            </select>
          </div>
        </div>

        <div>
          <label for="sr_code" class="block text-sm font-semibold text-gray-700 mb-2">
              <i class="bi bi-person-badge mr-2 text-red-700"></i> SR Code / Student ID
          </label>
          <input type="text" name="sr_code" id="sr_code" placeholder="Enter your SR Code (e.g., 22-55555)"
            class="w-full border border-gray-300 rounded-xl px-5 py-3 text-lg focus:border-red-700 focus:ring-1 focus:ring-red-700 focus:outline-none transition duration-150 shadow-sm" required>
        </div>

        <div>
          <label for="password" class="block text-sm font-semibold text-gray-700 mb-2">
              <i class="bi bi-lock-fill mr-2 text-red-700"></i> Password
          </label>
          <input type="password" name="password" id="password" placeholder="Create a strong password"
            class="w-full border border-gray-300 rounded-xl px-5 py-3 text-lg focus:border-red-700 focus:ring-1 focus:ring-red-700 focus:outline-none transition duration-150 shadow-sm" required>
        </div>

        <div>
          <label for="confirm_password" class="block text-sm font-semibold text-gray-700 mb-2">
              <i class="bi bi-check-circle-fill mr-2 text-red-700"></i> Confirm Password
          </label>
          <input type="password" name="confirm_password" id="confirm_password" placeholder="Re-enter password"
            class="w-full border border-gray-300 rounded-xl px-5 py-3 text-lg focus:border-red-700 focus:ring-1 focus:ring-red-700 focus:outline-none transition duration-150 shadow-sm" required>
        </div>

        <div class="pt-4">
          <button type="submit" class="w-full bg-red-700 hover:bg-red-800 text-white font-extrabold py-3 rounded-xl shadow-lg hover:shadow-xl flex items-center justify-center gap-2 transition transform hover:scale-[1.005] active:scale-[0.99]">
            <i class="bi bi-person-check-fill text-xl"></i> Complete Registration
          </button>
        </div>
      </form>

      <div class="mt-6 text-center">
        <a href="index.php" class="inline-flex items-center gap-2 text-gray-600 hover:text-red-700 font-medium transition duration-150">
          <i class="bi bi-arrow-left"></i> Back to Login
        </a>
      </div>
    </div>
  </div>

</body>
</html>
        