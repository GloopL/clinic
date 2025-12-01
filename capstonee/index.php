<?php
// BSU Clinic Record Management System - RESPONSIVE VERSION
session_start();
include 'config/database.php';

$error = '';
$success = '';
$show_register_modal = false;

// Debug information (remove in production)
error_log("Index.php accessed - User ID: " . (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'not set'));
error_log("Role: " . (isset($_SESSION['role']) ? $_SESSION['role'] : 'not set'));

// Check if user is already logged in - IMPROVED VERSION
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    // Get current page filename
    $current_page = basename($_SERVER['PHP_SELF']);
    
    // Define role to page mapping
    $role_pages = [
        'admin' => 'dashboard.php',
        'staff' => 'msa_dashboard.php',
        'dentist' => 'dentist_dashboard.php',
        'nurse' => 'nurse_dashboard.php',
        'doctor' => 'doctor_dashboard.php',
        'user' => 'user_dashboard.php'
    ];
    
    // Only redirect if we're not already on the correct dashboard
    $target_page = $role_pages[$_SESSION['role']] ?? 'index.php';
    
    if ($current_page != $target_page) {
        header("Location: $target_page");
        exit();
    }
    // If we're already on the correct page, don't redirect
}

// Process login form
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['login'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    
    if (empty($username) || empty($password)) {
        $error = "Username and password are required";
    } else {
        $query = "SELECT * FROM users WHERE username = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows == 1) {
            $user = $result->fetch_assoc();
            if (password_verify($password, $user['password'])) {
                // Regenerate session ID for security
                session_regenerate_id(true);
                
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['login_time'] = time();

                // Use the SAME redirect method as above
                $redirect_pages = [
                    'admin' => 'dashboard.php',
                    'staff' => 'msa_dashboard.php',
                    'dentist' => 'dentist_dashboard.php',
                    'nurse' => 'nurse_dashboard.php',
                    'doctor' => 'doctor_dashboard.php',
                    'user' => 'user_dashboard.php'
                ];
                
                $target_page = $redirect_pages[$user['role']] ?? 'index.php';
                header("Location: $target_page");
                exit();
            } else {
                $error = "Invalid username or password";
            }
        } else {
            $error = "Invalid username or password";
        }
    }
}

// Process STUDENT registration form
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['register_student'])) {
    $sr_code = trim($_POST['sr_code']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $first_name = isset($_POST['first_name']) ? trim($_POST['first_name']) : '';
    $middle_name = isset($_POST['middle_name']) ? trim($_POST['middle_name']) : '';
    $last_name = isset($_POST['last_name']) ? trim($_POST['last_name']) : '';
    $sex = isset($_POST['sex']) ? $_POST['sex'] : '';
    $birthdate = isset($_POST['birthdate']) ? $_POST['birthdate'] : '';
    $contact_number = isset($_POST['contact_number']) ? trim($_POST['contact_number']) : '';
    $address = isset($_POST['address']) ? trim($_POST['address']) : '';
    $program = isset($_POST['program']) ? trim($_POST['program']) : '';
    $year_level = isset($_POST['year_level']) ? trim($_POST['year_level']) : '';

    if (empty($sr_code) || empty($password) || empty($confirm_password)) {
        $error = "All fields are required.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } else {
        $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->bind_param("s", $sr_code);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $error = "SR Code already registered.";
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $role = 'user';
            // Combine first name, middle name (if provided), and last name
            $name_parts = array_filter([$first_name, $middle_name, $last_name]);
            $full_name = trim(implode(' ', $name_parts));
            $email = '';

            $stmt = $conn->prepare("INSERT INTO users (username, password, full_name, email, role) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssss", $sr_code, $hashed_password, $full_name, $email, $role);

            if ($stmt->execute()) {
                // Also insert into patients table
                $date_of_birth = $birthdate;
                
                // Only insert into patients if we have required fields
                if (!empty($first_name) && !empty($last_name) && !empty($date_of_birth) && !empty($sex)) {
                    $stmt_patient = $conn->prepare("INSERT INTO patients (student_id, first_name, middle_name, last_name, date_of_birth, sex, contact_number, address, program, year_level) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt_patient->bind_param("ssssssssss", $sr_code, $first_name, $middle_name, $last_name, $date_of_birth, $sex, $contact_number, $address, $program, $year_level);
                    
                    if (!$stmt_patient->execute()) {
                        // Log error but don't fail registration
                        error_log("Failed to insert patient record: " . $stmt_patient->error);
                    }
                    $stmt_patient->close();
                }
                
                $success = "Student registration successful! You can now log in.";
                $show_student_modal = false;
            } else {
                $error = "Registration failed. Please try again.";
                $show_student_modal = true;
            }
        }
    }
}

// Process EMPLOYEE registration form
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['register_employee'])) {
    $employee_id = trim($_POST['employee_id']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $first_name = isset($_POST['first_name']) ? trim($_POST['first_name']) : '';
    $middle_name = isset($_POST['middle_name']) ? trim($_POST['middle_name']) : '';
    $last_name = isset($_POST['last_name']) ? trim($_POST['last_name']) : '';
    $sex = isset($_POST['sex']) ? $_POST['sex'] : '';
    $birthdate = isset($_POST['birthdate']) ? $_POST['birthdate'] : '';
    $contact_number = isset($_POST['contact_number']) ? trim($_POST['contact_number']) : '';
    $address = isset($_POST['address']) ? trim($_POST['address']) : '';
    $department = isset($_POST['department']) ? trim($_POST['department']) : '';
    $position = isset($_POST['position']) ? trim($_POST['position']) : '';
    $employee_type = isset($_POST['employee_type']) ? $_POST['employee_type'] : '';

    if (empty($employee_id) || empty($password) || empty($confirm_password)) {
        $error = "All fields are required.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } else {
        $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->bind_param("s", $employee_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $error = "Employee ID already registered.";
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $role = 'staff'; // Default role for employees
            // Combine first name, middle name (if provided), and last name
            $name_parts = array_filter([$first_name, $middle_name, $last_name]);
            $full_name = trim(implode(' ', $name_parts));
            $email = '';

            $stmt = $conn->prepare("INSERT INTO users (username, password, full_name, email, role) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssss", $employee_id, $hashed_password, $full_name, $email, $role);

            if ($stmt->execute()) {
                // Also insert into employees table if it exists
                $date_of_birth = $birthdate;
                
                // Check if employees table exists and insert
                $check_table = $conn->query("SHOW TABLES LIKE 'employees'");
                if ($check_table->num_rows > 0) {
                    $stmt_employee = $conn->prepare("INSERT INTO employees (employee_id, first_name, middle_name, last_name, date_of_birth, sex, contact_number, address, department, position, employee_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt_employee->bind_param("sssssssssss", $employee_id, $first_name, $middle_name, $last_name, $date_of_birth, $sex, $contact_number, $address, $department, $position, $employee_type);
                    
                    if (!$stmt_employee->execute()) {
                        // Log error but don't fail registration
                        error_log("Failed to insert employee record: " . $stmt_employee->error);
                    }
                    $stmt_employee->close();
                }
                
                $success = "Employee registration successful! You can now log in.";
                $show_employee_modal = false;
            } else {
                $error = "Registration failed. Please try again.";
                $show_employee_modal = true;
            }
        }
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BSU Clinic Record Management System</title>
    <link rel="icon" type="image/png" href="assets/css/images/logo-bsu.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">

    <style>
        /* CSS Reset */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            overflow-x: hidden;
        }

        /* BSU Brand Colors */
        :root {
            --red-800: #dc2626;
            --red-900: #b91c1c;
            --yellow-500: #eab308;
            --yellow-600: #ca8a04;
            --gray-100: #f3f4f6;
            --gray-600: #4b5563;
            --gray-900: #111827;
            --red-50: #fef2f2;
            --blue-50: #eff6ff;
            --blue-100: #dbeafe;
            --blue-600: #2563eb;
            --blue-800: #1e40af;
            --green-100: #dcfce7;
            --green-500: #22c55e;
            --green-800: #166534;
        }

        /* Utility Classes */
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        .flex {
            display: flex;
        }

        .items-center {
            align-items: center;
        }

        .justify-between {
            justify-content: space-between;
        }

        .text-center {
            text-align: center;
        }

        .hidden {
            display: none;
        }

        /* Header Styles */
        .header {
            background: white;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
            z-index: 50;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .header.scrolled {
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
        }

        .nav {
            height: 64px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: all 0.3s ease;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
            transition: transform 0.3s ease;
        }

        .logo:hover {
            transform: translateY(-2px);
        }

        .logo-icon img {
            width: 40px;
            height: 40px;
            object-fit: contain;
            border-radius: 8px;
            transition: transform 0.3s ease;
        }

        .logo:hover .logo-icon img {
            transform: scale(1.1) rotate(5deg);
        }

        .logo-text h1 {
            margin: 0;
            font-size: 1.4rem;
            font-weight: 700;
            color: #c70f0fff;
            line-height: 1.2;
        }

        .logo-text p {
            margin: 0;
            font-size: 0.9rem;
            color: #6c757d;
            line-height: 1.1;
        }

        .nav-links {
            display: none;
            gap: 32px;
        }

        .nav-links a {
            text-decoration: none;
            color: var(--gray-600);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            padding: 8px 0;
        }

        .nav-links a:hover {
            color: var(--red-800);
            transform: translateY(-2px);
        }

        .nav-links a::after {
            content: '';
            position: absolute;
            width: 0;
            height: 2px;
            bottom: 0;
            left: 50%;
            background: linear-gradient(to right, var(--red-800), var(--yellow-500));
            transition: all 0.3s ease;
            transform: translateX(-50%);
        }

        .nav-links a:hover::after {
            width: 100%;
        }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            text-decoration: none;
            display: inline-block;
            position: relative;
            overflow: hidden;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: left 0.5s;
        }

        .btn:hover::before {
            left: 100%;
        }

        .btn-primary {
            background: var(--red-800);
            color: white;
        }

        .btn-primary:hover {
            background: var(--red-900);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(220, 38, 38, 0.4);
        }

        .btn-secondary {
            background: var(--yellow-500);
            color: var(--red-900);
        }

        .btn-secondary:hover {
            background: var(--yellow-600);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(234, 179, 8, 0.4);
        }

        .btn-outline {
            background: transparent;
            color: white;
            border: 1px solid white;
        }

        .btn-outline:hover {
            background: rgba(255,255,255,0.1);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(255, 255, 255, 0.2);
        }

        /* Hero Section */
        .hero {
            position: relative;
            min-height: 600px;
            background-image: url('assets/css/images/bsu1-bg.jpg');
            background-size: cover;
            background-position: center;
            display: flex;
            align-items: center;
            overflow: hidden;
        }

        .hero::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(to bottom right, rgba(220, 38, 38, 0.75), rgba(234, 179, 8, 0.75));
            animation: gradientShift 8s ease-in-out infinite;
        }

        .hero-content {
            position: relative;
            z-index: 1;
            color: white;
            padding: 80px 0;
        }

        .hero-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 48px;
            align-items: center;
        }

        .hero-text h1 {
            font-size: 48px;
            line-height: 1.1;
            margin-bottom: 24px;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }

        .hero-text .highlight {
            color: #fcd34d;
            position: relative;
            display: inline-block;
        }

        .hero-text .highlight::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: #fcd34d;
            border-radius: 2px;
            transform: scaleX(0);
            transform-origin: left;
            animation: expandLine 1s ease-out 0.5s forwards;
        }

        .hero-text p {
            font-size: 20px;
            margin-bottom: 32px;
            text-shadow: 0 1px 2px rgba(0,0,0,0.3);
        }

        .hero-buttons {
            display: flex;
            gap: 16px;
            margin-bottom: 32px;
            flex-wrap: wrap;
        }

        .hero-features {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }

        .feature-item {
            display: flex;
            align-items: center;
            gap: 12px;
            transition: all 0.3s ease;
            padding: 8px;
            border-radius: 8px;
        }

        .feature-item:hover {
            transform: translateX(8px);
            background: rgba(255, 255, 255, 0.1);
        }

        .feature-icon {
            width: 32px;
            height: 32px;
            color: #fcd34d;
            transition: transform 0.3s ease;
        }

        .feature-item:hover .feature-icon {
            transform: scale(1.2) rotate(10deg);
        }

        .qr-card {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            padding: 32px;
            box-shadow: 0 20px 25px rgba(0,0,0,0.15);
            color: #333;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .qr-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 30px 50px rgba(0,0,0,0.25);
        }

        .qr-icon {
            width: 128px;
            height: 128px;
            background: linear-gradient(to bottom right, var(--red-800), var(--yellow-500));
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
            color: white;
            transition: all 0.3s ease;
            animation: pulse 2s infinite;
        }

        .qr-card:hover .qr-icon {
            transform: scale(1.1) rotate(5deg);
            animation: none;
        }

        /* Features Section */
        .features {
            padding: 80px 0;
            background: white;
        }

        .section-header {
            text-align: center;
            margin-bottom: 64px;
        }

        .section-header h2 {
            font-size: 36px;
            color: var(--gray-900);
            margin-bottom: 16px;
            position: relative;
            display: inline-block;
        }

        .section-header h2::after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 50%;
            transform: translateX(-50%);
            width: 80px;
            height: 4px;
            background: linear-gradient(to right, var(--red-800), var(--yellow-500));
            border-radius: 2px;
            transition: width 0.3s ease;
        }

        .section-header:hover h2::after {
            width: 120px;
        }

        .section-header p {
            font-size: 20px;
            color: var(--gray-600);
            max-width: 600px;
            margin: 0 auto;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 24px;
            margin-bottom: 64px;
        }

        .feature-card {
            background: white;
            border: 1px solid #fee2e2;
            border-radius: 12px;
            padding: 24px;
            text-align: center;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .feature-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(to right, var(--red-800), var(--yellow-500));
            transform: scaleX(0);
            transform-origin: left;
            transition: transform 0.4s ease;
        }

        .feature-card:hover {
            box-shadow: 0 15px 30px rgba(0,0,0,0.15);
            transform: translateY(-8px);
            border-color: #fecaca;
        }

        .feature-card:hover::before {
            transform: scaleX(1);
        }

        .feature-card-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(to bottom right, var(--red-800), var(--yellow-500));
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            color: white;
            transition: all 0.4s ease;
        }

        .feature-card:hover .feature-card-icon {
            transform: scale(1.1) rotate(5deg);
            box-shadow: 0 8px 20px rgba(220, 38, 38, 0.3);
        }

        .feature-card h3 {
            font-size: 18px;
            margin-bottom: 12px;
            color: var(--gray-900);
            transition: color 0.3s ease;
        }

        .feature-card:hover h3 {
            color: var(--red-800);
        }

        .feature-card p {
            color: var(--gray-600);
            font-size: 14px;
            transition: color 0.3s ease;
        }

        /* About Section */
        .about {
            padding: 80px 0;
            background: var(--gray-100);
        }

        .about-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 48px;
            align-items: center;
            margin-bottom: 64px;
        }

        .about-content h3 {
            font-size: 32px;
            color: var(--gray-900);
            margin-bottom: 24px;
        }

        .about-content p {
            color: var(--gray-600);
            margin-bottom: 24px;
            line-height: 1.7;
        }

        .about-services {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }

        .service-list h4 {
            color: var(--red-800);
            margin-bottom: 12px;
            position: relative;
            display: inline-block;
        }

        .service-list h4::after {
            content: '';
            position: absolute;
            bottom: -5px;
            left: 0;
            width: 30px;
            height: 2px;
            background: var(--red-800);
            transition: width 0.3s ease;
        }

        .service-list:hover h4::after {
            width: 50px;
        }

        .service-list ul {
            list-style: none;
        }

        .service-list li {
            color: var(--gray-600);
            font-size: 14px;
            margin-bottom: 4px;
            transition: all 0.3s ease;
            padding: 4px 0;
            padding-left: 15px;
            position: relative;
        }

        .service-list li::before {
            content: '•';
            position: absolute;
            left: 0;
            color: var(--red-800);
            transition: transform 0.3s ease;
        }

        .service-list li:hover {
            color: var(--red-800);
            transform: translateX(8px);
        }

        .service-list li:hover::before {
            transform: scale(1.5);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 24px;
        }

        .stat-card {
            background: white;
            border: 1px solid #fee2e2;
            border-radius: 12px;
            padding: 24px;
            text-align: center;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            border-color: #fecaca;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(to bottom right, var(--red-800), var(--yellow-500));
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 12px;
            color: white;
            transition: all 0.3s ease;
        }

        .stat-card:hover .stat-icon {
            transform: scale(1.1) rotate(5deg);
        }

        .stat-number {
            font-size: 24px;
            font-weight: 600;
            color: var(--red-800);
            margin-bottom: 8px;
            transition: color 0.3s ease;
        }

        .stat-label {
            color: var(--gray-600);
            font-size: 14px;
        }

        /* Footer */
        .footer {
            background: var(--gray-900);
            color: white;
            padding: 48px 0 24px;
        }

        .footer-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr;
            gap: 32px;
            margin-bottom: 32px;
        }

        .footer-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
            transition: transform 0.3s ease;
        }

        .footer-brand:hover {
            transform: translateY(-2px);
        }

        .footer-brand-icon {
            background: linear-gradient(to right, var(--red-800), var(--yellow-500));
            padding: 8px;
            border-radius: 8px;
            color: white;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform 0.3s ease;
        }

        .footer-brand:hover .footer-brand-icon {
            transform: rotate(10deg);
        }

        .footer h4 {
            font-size: 18px;
            margin-bottom: 16px;
            position: relative;
            display: inline-block;
        }

        .footer h4::after {
            content: '';
            position: absolute;
            bottom: -5px;
            left: 0;
            width: 30px;
            height: 2px;
            background: var(--red-800);
            transition: width 0.3s ease;
        }

        .footer h4:hover::after {
            width: 50px;
        }

        .footer ul {
            list-style: none;
        }

        .footer li {
            margin-bottom: 8px;
            transition: transform 0.3s ease;
        }

        .footer li:hover {
            transform: translateX(5px);
        }

        .footer a {
            color: #9ca3af;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .footer a:hover {
            color: white;
        }

        .footer-bottom {
            border-top: 1px solid #374151;
            padding-top: 24px;
            text-align: center;
            color: #9ca3af;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            animation: fadeIn 0.3s;
        }

        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: white;
            border-radius: 12px;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            animation: slideIn 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            transform-origin: center;
            box-shadow: 0 25px 50px rgba(0,0,0,0.25);
        }

        .modal-header {
            background: linear-gradient(to right, var(--red-800), var(--yellow-500));
            padding: 24px 24px 24px 24px;
            text-align: center;
            border-radius: 12px 12px 0 0;
        }

        .modal-icon {
            width: 64px;
            height: 64px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 12px;
            color: var(--red-800);
            transition: transform 0.3s ease;
        }

        .modal:hover .modal-icon {
            transform: rotate(5deg);
        }

        .modal-title {
            color: white;
            font-size: 20px;
            font-weight: 600;
        }

        .modal-body {
            padding: 24px;
        }

        .form-group {
            margin-bottom: 16px;
            position: relative;
        }

        .form-label {
            display: block;
            margin-bottom: 4px;
            font-weight: 500;
            color: var(--gray-900);
            transition: color 0.3s ease;
        }

        .form-input {
            width: 100%;
            padding: 12px 45px 12px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .form-input:focus {
            outline: none;
            border-color: var(--red-800);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
            transform: translateY(-2px);
        }

        /* Registration Options Styles */
        .registration-options {
            display: grid;
            gap: 16px;
            margin-bottom: 24px;
        }

        .registration-option-card {
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            padding: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
        }

        .registration-option-card:hover {
            border-color: var(--red-800);
            transform: translateY(-4px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }

        .registration-option-card.active {
            border-color: var(--red-800);
            background: var(--red-50);
        }

        /* Password Toggle Styles */
        .password-input-container {
            position: relative;
            width: 100%;
        }

        .password-toggle-btn {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: var(--gray-600);
            padding: 4px;
            border-radius: 4px;
            transition: all 0.3s ease;
            z-index: 10;
        }

        .password-toggle-btn:hover {
            color: var(--red-800);
            background: rgba(220, 38, 38, 0.1);
        }

        .password-toggle-btn:focus {
            outline: none;
            box-shadow: 0 0 0 2px rgba(220, 38, 38, 0.2);
        }

        .form-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .alert {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 16px;
            font-size: 14px;
            animation: shake 0.5s ease;
        }

        .success {
            background: #f0fdf4;
            border-color: #bbf7d0;
            color: #16a34a;
            animation: slideIn 0.5s ease;
        }

        /* Floating Login Button */
        .floating-btn {
            position: fixed;
            bottom: 24px;
            right: 24px;
            width: 56px;
            height: 56px;
            background: linear-gradient(to right, var(--red-800), var(--yellow-500));
            color: white;
            border: none;
            border-radius: 50%;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 100;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: float 3s ease-in-out infinite;
        }

        .floating-btn:hover {
            box-shadow: 0 8px 25px rgba(0,0,0,0.3);
            transform: scale(1.1) rotate(10deg);
            animation: none;
        }

        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideIn {
            from { 
                transform: translateY(-50px) scale(0.9); 
                opacity: 0; 
            }
            to { 
                transform: translateY(0) scale(1); 
                opacity: 1; 
            }
        }

        @keyframes expandLine {
            from { transform: scaleX(0); }
            to { transform: scaleX(1); }
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
            20%, 40%, 60%, 80% { transform: translateX(5px); }
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }

        @keyframes gradientShift {
            0%, 100% { 
                background: linear-gradient(to bottom right, rgba(220, 38, 38, 0.75), rgba(234, 179, 8, 0.75));
            }
            50% { 
                background: linear-gradient(to bottom right, rgba(234, 179, 8, 0.75), rgba(220, 38, 38, 0.75));
            }
        }

        /* Icons using CSS */
        .icon-heart::before { content: "\f004"; font-family: "Font Awesome 6 Free"; font-weight: 900; }
        .icon-qr::before { content: "\f029"; font-family: "Font Awesome 6 Free"; font-weight: 900; }
        .icon-file::before { content: "\f15b"; font-family: "Font Awesome 6 Free"; font-weight: 900; }
        .icon-users::before { content: "\f0c0"; font-family: "Font Awesome 6 Free"; font-weight: 900; }
        .icon-calendar::before { content: "\f133"; font-family: "Font Awesome 6 Free"; font-weight: 900; }
        .icon-shield::before { content: "\f132"; font-family: "Font Awesome 6 Free"; font-weight: 900; }
        .icon-smartphone::before { content: "\f3cd"; font-family: "Font Awesome 6 Free"; font-weight: 900; }
        .icon-database::before { content: "\f1c0"; font-family: "Font Awesome 6 Free"; font-weight: 900; }
        .icon-clock::before { content: "\f017"; font-family: "Font Awesome 6 Free"; font-weight: 900; }
        .icon-eye::before { content: "\f06e"; font-family: "Font Awesome 6 Free"; font-weight: 900; }
        .icon-eye-off::before { content: "\f070"; font-family: "Font Awesome 6 Free"; font-weight: 900; }
        .icon-phone::before { content: "\f095"; font-family: "Font Awesome 6 Free"; font-weight: 900; }
        .icon-mail::before { content: "\f0e0"; font-family: "Font Awesome 6 Free"; font-weight: 900; }
        .icon-map::before { content: "\f3c5"; font-family: "Font Awesome 6 Free"; font-weight: 900; }
        .icon-login::before { content: "\f2f6"; font-family: "Font Awesome 6 Free"; font-weight: 900; }
        .icon-arrow::before { content: "\f061"; font-family: "Font Awesome 6 Free"; font-weight: 900; }
        .icon-award::before { content: "\f559"; font-family: "Font Awesome 6 Free"; font-weight: 900; }
        .icon-book::before { content: "\f02d"; font-family: "Font Awesome 6 Free"; font-weight: 900; }
        .icon-person-plus::before { content: "\f234"; font-family: "Font Awesome 6 Free"; font-weight: 900; }
        .icon-person-badge::before { content: "\f0c0"; font-family: "Font Awesome 6 Free"; font-weight: 900; }
        .icon-lock::before { content: "\f023"; font-family: "Font Awesome 6 Free"; font-weight: 900; }
        .icon-check-circle::before { content: "\f058"; font-family: "Font Awesome 6 Free"; font-weight: 900; }
        .icon-graduation-cap::before { content: "\f19d"; font-family: "Font Awesome 6 Free"; font-weight: 900; }
        .icon-briefcase::before { content: "\f0b1"; font-family: "Font Awesome 6 Free"; font-weight: 900; }

        [class^="icon-"]::before {
            display: inline-block;
            margin-right: 8px;
            font-size: 1.1em;
            color: #fcf1f5ff;
            vertical-align: middle;
        }

        /* Form Styles */
        .form-section {
            margin-bottom: 24px;
        }
        
        .form-row {
            display: flex;
            gap: 16px;
        }
        
        .form-row .form-group {
            flex: 1;
        }
        
        textarea.form-input {
            resize: vertical;
            min-height: 80px;
        }
        
        select.form-input {
            appearance: none;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e");
            background-position: right 0.5rem center;
            background-repeat: no-repeat;
            background-size: 1.5em 1.5em;
            padding-right: 2.5rem;
        }

        /* ========== RESPONSIVE DESIGN ========== */
        
        /* Mobile First - Default styles are for mobile */
        
        /* Small Mobile Devices (320px - 480px) */
        @media (max-width: 480px) {
            .container {
                padding: 0 15px;
            }
            
            .nav {
                height: 60px;
                padding: 0 10px;
            }
            
            .logo-text h1 {
                font-size: 1.2rem;
            }
            
            .logo-text p {
                font-size: 0.8rem;
            }
            
            .hero {
                min-height: 500px;
            }
            
            .hero-content {
                padding: 40px 0;
            }
            
            .hero-text h1 {
                font-size: 2rem;
                line-height: 1.2;
            }
            
            .hero-text p {
                font-size: 1rem;
                margin-bottom: 24px;
            }
            
            .hero-buttons {
                flex-direction: column;
                gap: 12px;
                margin-bottom: 24px;
            }
            
            .hero-features {
                grid-template-columns: 1fr;
                gap: 16px;
            }
            
            .feature-item {
                padding: 6px;
            }
            
            .qr-card {
                padding: 20px;
            }
            
            .features {
                padding: 40px 0;
            }
            
            .section-header {
                margin-bottom: 40px;
            }
            
            .section-header h2 {
                font-size: 1.8rem;
            }
            
            .section-header p {
                font-size: 1rem;
            }
            
            .features-grid {
                grid-template-columns: 1fr;
                gap: 16px;
            }
            
            .feature-card {
                padding: 20px;
            }
            
            .about {
                padding: 40px 0;
            }
            
            .about-grid {
                gap: 30px;
                margin-bottom: 40px;
            }
            
            .about-content h3 {
                font-size: 1.5rem;
            }
            
            .about-services {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
                gap: 16px;
            }
            
            .stat-card {
                padding: 20px;
            }
            
            .footer {
                padding: 30px 0 20px;
            }
            
            .footer-grid {
                grid-template-columns: 1fr;
                gap: 25px;
            }
            
            .floating-btn {
                bottom: 15px;
                right: 15px;
                width: 50px;
                height: 50px;
            }
            
            .modal-content {
                width: 95%;
                margin: 10px;
            }
            
            .modal-body {
                padding: 20px;
            }
            
            .form-row {
                flex-direction: column;
                gap: 0;
            }
        }
        
        /* Mobile Devices (481px - 767px) */
        @media (min-width: 481px) and (max-width: 767px) {
            .hero-text h1 {
                font-size: 2.5rem;
            }
            
            .features-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .footer-grid {
                grid-template-columns: 2fr 1fr;
            }
        }
        
        /* Tablet Devices (768px - 1023px) */
        @media (min-width: 768px) and (max-width: 1023px) {
            .nav-links {
                display: flex;
                gap: 24px;
            }
            
            .hero-text h1 {
                font-size: 3rem;
            }
            
            .hero-grid {
                grid-template-columns: 1fr 1fr;
            }
            
            .features-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .about-grid {
                grid-template-columns: 1fr 1fr;
            }
            
            .footer-grid {
                grid-template-columns: 2fr 1fr 1fr;
            }
        }
        
        /* Desktop (1024px and above) */
        @media (min-width: 1024px) {
            .nav-links {
                display: flex;
            }
            
            .hero-grid {
                grid-template-columns: 1fr 1fr;
            }
            
            .hero-text h1 {
                font-size: 64px;
            }
            
            .features-grid {
                grid-template-columns: repeat(4, 1fr);
            }
            
            .about-grid {
                grid-template-columns: 1fr 1fr;
            }
        }
        
        /* Large Desktop (1200px and above) */
        @media (min-width: 1200px) {
            .container {
                padding: 0;
            }
        }
        
        /* Mobile Menu Styles */
        .mobile-menu-btn {
            display: none;
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--gray-600);
            cursor: pointer;
            padding: 8px;
        }
        
        @media (max-width: 767px) {
            .mobile-menu-btn {
                display: block;
            }
            
            .nav-links {
                display: none;
                position: fixed;
                top: 60px;
                left: 0;
                width: 100%;
                background: white;
                flex-direction: column;
                padding: 20px;
                box-shadow: 0 4px 6px rgba(0,0,0,0.1);
                gap: 15px;
                z-index: 1000;
            }
            
            .nav-links.active {
                display: flex;
            }
            
            .btn-primary {
                font-size: 12px;
                padding: 6px 12px;
            }
        }
        
        /* Landscape Mobile */
        @media (max-height: 500px) and (orientation: landscape) {
            .hero {
                min-height: 400px;
            }
            
            .hero-content {
                padding: 20px 0;
            }
            
            .modal-content {
                max-height: 80vh;
            }
        }
        
        /* Print Styles */
        @media print {
            .header,
            .floating-btn,
            .hero-buttons,
            .modal {
                display: none !important;
            }
            
            body {
                font-size: 12pt;
                line-height: 1.4;
            }
            
            .container {
                max-width: none;
            }
        }

    </style>
</head>
<body>
    <!-- Header -->
    <header class="header" id="header">
        <nav class="container">
            <div class="nav">
                <div class="logo">
                    <div class="logo-icon">
                         <img src="assets/css/images/logo-bsu.png" alt="BSU Logo">
                    </div>
                    <div class="logo-text">
                        <h1>BSU Clinic Record System</h1>
                        <p>Digital Health Records</p>
                    </div>
                </div>
                
                <div class="nav-links" id="navLinks">
                    <a href="#features">Features</a>
                    <a href="#about">About</a>
                    <a href="#contact">Contact</a>
                </div>
                
                <button class="btn btn-primary" onclick="openLoginModal()">Login</button>
                
                <!-- Mobile Menu Button -->
                <button class="mobile-menu-btn" id="mobileMenuBtn">
                    <i class="fas fa-bars"></i>
                </button>
            </div>
        </nav>
    </header>

    <!-- Hero Section -->
    <section class="hero">
        <div class="container">
            <div class="hero-content">
                <div class="hero-grid">
                    <div class="hero-text">
                        <div style="display: flex; align-items: center; gap: 8px; color: #fcd34d; margin-bottom: 16px;" data-aos="fade-right">
                            <span class="icon-file"></span>
                            <span style="font-size: 14px; font-weight: 500;">Digital Clinic Records</span>
                        </div>
                        <h1 data-aos="fade-up" data-aos-delay="200">
                            Batangas State University
                            <span class="highlight" style="display: block;">Clinic Record System </span>
                        </h1>
                        <p data-aos="fade-up" data-aos-delay="400">
                            Streamline healthcare management with our modern digital clinic record system. 
                            Secure, efficient, and designed for the BSU community.
                        </p>
                        
                        <div class="hero-buttons" data-aos="fade-up" data-aos-delay="600">
                            <a href="#features" class="btn btn-secondary">
                                Get Started <span class="icon-arrow"></span>
                            </a>
                            <a href="#about" class="btn btn-outline">Learn More</a>
                        </div>
                        
                        <div class="hero-features" data-aos="fade-up" data-aos-delay="800">
                            <div class="feature-item">
                                <span class="feature-icon icon-shield"></span>
                                <div>
                                    <p style="font-size: 14px; margin-bottom: 4px;">Secure & HIPAA Compliant</p>
                                    <p style="font-size: 12px; color: #e5e7eb;">Protected patient data</p>
                                </div>
                            </div>
                            <div class="feature-item">
                                <span class="feature-icon icon-clock"></span>
                                <div>
                                    <p style="font-size: 14px; margin-bottom: 4px;">24/7 Access</p>
                                    <p style="font-size: 12px; color: #e5e7eb;">Available anytime</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Replaced QR-focused card with general system overview (no QR dependency on homepage) -->
                    <div class="qr-card" data-aos="zoom-in" data-aos-delay="1000">
                        <div class="qr-icon">
                            <span class="icon-file" style="font-size: 64px;"></span>
                        </div>
                        <h3 style="font-size: 20px; margin-bottom: 16px;">Unified Clinic Records</h3>
                        <p style="color: var(--gray-600); margin-bottom: 24px;">
                            Access student information, medical examinations, and clinic history from a single, easy-to-use platform.
                        </p>
                        <button class="btn btn-primary" style="width: 100%;">Explore Features</button>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section id="features" class="features">
        <div class="container">
            <div class="section-header" data-aos="fade-up">
                <h2>Powerful Features for Modern Healthcare</h2>
                <p>
                    Our integrated clinic record system revolutionizes how BSU manages health information, 
                    making healthcare more accessible and efficient for everyone.
                </p>
            </div>
            
            <div class="features-grid">
                <div class="feature-card" data-aos="fade-up" data-aos-delay="100">
                    <div class="feature-card-icon">
                        <span class="icon-database"></span>
                    </div>
                    <h3>Centralized Records</h3>
                    <p>Instant access to patient forms, diagnoses, and examinations in one secure system.</p>
                </div>
                
                <div class="feature-card" data-aos="fade-up" data-aos-delay="200">
                    <div class="feature-card-icon">
                        <span class="icon-file"></span>
                    </div>
                    <h3>Digital Records</h3>
                    <p>Comprehensive electronic health records with easy search and organization.</p>
                </div>
                
                <div class="feature-card" data-aos="fade-up" data-aos-delay="300">
                    <div class="feature-card-icon">
                        <span class="icon-users"></span>
                    </div>
                    <h3>Multi-User Access</h3>
                    <p>Role-based access for doctors, nurses, students, and administrative staff.</p>
                </div>
                
                <div class="feature-card" data-aos="fade-up" data-aos-delay="400">
                    <div class="feature-card-icon">
                        <span class="icon-calendar"></span>
                    </div>
                    <h3>Appointment Management</h3>
                    <p>Schedule and track appointments with automated reminders and notifications.</p>
                </div>
                
                <div class="feature-card" data-aos="fade-up" data-aos-delay="100">
                    <div class="feature-card-icon">
                        <span class="icon-shield"></span>
                    </div>
                    <h3>Security & Privacy</h3>
                    <p>HIPAA-compliant data protection with encrypted storage and secure access.</p>
                </div>
                
                <div class="feature-card" data-aos="fade-up" data-aos-delay="200">
                    <div class="feature-card-icon">
                        <span class="icon-smartphone"></span>
                    </div>
                    <h3>Mobile Friendly</h3>
                    <p>Access records and forms from any device, anywhere on campus.</p>
                </div>
                
                <div class="feature-card" data-aos="fade-up" data-aos-delay="300">
                    <div class="feature-card-icon">
                        <span class="icon-database"></span>
                    </div>
                    <h3>Data Analytics</h3>
                    <p>Generate reports and insights to improve healthcare delivery and outcomes.</p>
                </div>
                
                <div class="feature-card" data-aos="fade-up" data-aos-delay="400">
                    <div class="feature-card-icon">
                        <span class="icon-clock"></span>
                    </div>
                    <h3>Real-time Updates</h3>
                    <p>Instant synchronization across all devices and user accounts.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- About Section -->
    <section id="about" class="about">
        <div class="container">
            <div class="section-header" data-aos="fade-up">
                <div style="background: var(--red-800); color: white; padding: 4px 12px; border-radius: 16px; display: inline-block; margin-bottom: 16px; font-size: 12px; text-align: center; width: 50%;">About BSU Clinic</div>
                <h2>Committed to Student Health & Wellness</h2>
                <p>
                    The Batangas State University Clinic has been serving our academic community 
                    for over a decade, providing comprehensive healthcare services with cutting-edge technology.
                </p>
            </div>
            
            <div class="about-grid">
                <div class="about-content" data-aos="fade-right">
                    <h3>Leading Healthcare Innovation in Education</h3>
                    <p>
                        Our clinic serves as a cornerstone of health and wellness for the BSU community. 
                        We combine traditional healthcare excellence with modern digital solutions to 
                        provide accessible, efficient, and comprehensive medical services.
                    </p>
                    <p>
                        The introduction of our integrated digital record system represents our commitment 
                        to innovation, making healthcare more accessible while maintaining the highest 
                        standards of privacy and security.
                    </p>
                    <div class="about-services">
                        <div class="service-list">
                            <h4>Our Services</h4>
                            <ul>
                                <li> General Medical Care</li>
                                <li> Emergency Response</li>
                                <li> Health Screenings</li>
                                <li> Preventive Care</li>
                            </ul>
                        </div>
                        <div class="service-list">
                            <h4>Digital Features</h4>
                            <ul>
                                <li> Electronic Forms</li>
                                <li> Electronic Records</li>
                                <li> Online Appointments</li>
                                <li> Mobile Access</li>
                            </ul>
                        </div>
                    </div>
                </div>
                
                <div data-aos="fade-left" data-aos-delay="300">
                    <img src="assets/css/images/bsu background.jpg" 
                         alt="BSU Campus" 
                         style="width: 100%; border-radius: 16px; box-shadow: 0 20px 25px rgba(0,0,0,0.15);">
                </div>
            </div>
            
            <div class="stats-grid">
                <div class="stat-card" data-aos="zoom-in" data-aos-delay="100">
                    <div class="stat-icon">
                        <span class="icon-users"></span>
                    </div>
                    <div class="stat-number">10,000+</div>
                    <div class="stat-label">Students Served</div>
                </div>
              
                <div class="stat-card" data-aos="zoom-in" data-aos-delay="200">
                    <div class="stat-icon">
                        <span class="icon-award"></span>
                    </div>
                    <div class="stat-number">15</div>
                    <div class="stat-label">Years of Service</div>
                </div>
                <div class="stat-card" data-aos="zoom-in" data-aos-delay="300">
                    <div class="stat-icon">
                        <span class="icon-clock"></span>
                    </div>
                    <div class="stat-number">24/7</div>
                    <div class="stat-label">System Availability</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer id="contact" class="footer">
        <div class="container">
            <div class="footer-grid">
                <div data-aos="fade-up">
                    <div class="footer-brand">
                        <div class="footer-brand-icon">
                            <span class="icon-heart"></span>
                        </div>
                        <div>
                            <h3>BSU Clinic Record System</h3>
                            <p style="color: #9ca3af; font-size: 14px;">Digital Clinic Record Management</p>
                        </div>
                    </div>
                    <p style="color: #9ca3af; margin-bottom: 16px; max-width: 400px;">
                        Batangas State University's comprehensive clinic record management system, 
                        designed to provide efficient and secure healthcare services for our academic community.
                    </p>
                    <div style="display: flex; align-items: center; gap: 8px; color: #ef4444;">
                        <span class="icon-file"></span>
                        <span style="font-size: 14px;">Secure. Accessible. Organized.</span>
                    </div>
                </div>
                
                <div data-aos="fade-up" data-aos-delay="100">
                    <h4>Quick Links</h4>
                    <ul>
                        <li><a href="#features">Features</a></li>
                        <li><a href="#about">About Us</a></li>
                        <li><a href="#">Student Portal</a></li>
                        <li><a href="#">Staff Login</a></li>
                        <li><a href="#">Help & Support</a></li>
                    </ul>
                </div>
                
                <div data-aos="fade-up" data-aos-delay="200">
                    <h4>Contact Info</h4>
                    <ul>
                        <li style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
                            <span class="icon-map" style="color: #ef4444;"></span>
                            <span style="font-size: 14px;">BSU Lipa Campus, Batangas City</span>
                        </li>
                        <li style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
                            <span class="icon-phone" style="color: #ef4444;"></span>
                            <span style="font-size: 14px;">(043) 425-3001</span>
                        </li>
                        <li style="display: flex; align-items: center; gap: 8px;">
                            <span class="icon-mail" style="color: #ef4444;"></span>
                            <span style="font-size: 14px;">clinic@g.batstate-u.edu.ph</span>
                        </li>
                    </ul>
                </div>
            </div>
            
            <div class="footer-bottom">
                <p>© <?php echo date('Y'); ?> Batangas State University. All rights reserved.</p>
                <p style="font-size: 12px; margin-top: 4px; color: #6b7280;">
                    Clinic Record Management System for the BSU Community
                </p>
            </div>
        </div>
    </footer>

    <!-- Login Modal -->
    <div id="loginModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <img src="assets/css/images/logo-bsu.png" alt="BSU Logo" class="modal-icon" style="width: 80px; height: 80px; border-radius: 50%; object-fit: cover; box-shadow: 0 4px 6px rgba(0,0,0,0.1); border: 4px solid white; background: white;">
                <h2 class="modal-title">BSU Clinic Record Management System</h2>
            </div>
            
            <div class="modal-body">
                <div class="text-center" style="margin-bottom: 24px;">
                    <h3 style="color: var(--red-800); margin-bottom: 4px; font-weight: 600;">Batangas State University</h3>
                    <p style="color: var(--gray-600); font-size: 14px;">Clinic Record Management System</p>
                </div>

                <?php if (!empty($error) && !isset($_POST['register_student']) && !isset($_POST['register_employee'])): ?>
                    <div class="alert">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <?php if (isset($login_success)): ?>
                    <div class="alert success">
                        Login successful! Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?>!
                    </div>
                    <script>
                        setTimeout(function() {
                            closeLoginModal();
                            window.location.href = 'dashboard.php';
                        }, 2000);
                    </script>
                <?php endif; ?>

                <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                    <div class="form-group">
                        <label class="form-label" for="username">Username</label>
                        <input type="text" 
                               id="username" 
                               name="username" 
                               class="form-input" 
                               required
                               autofocus
                               autocomplete="username">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="password">Password</label>
                        <div class="password-input-container">
                            <input type="password" 
                                   id="password" 
                                   name="password" 
                                   class="form-input" 
                                   required
                                   autocomplete="current-password">
                            <button type="button" class="password-toggle-btn" id="loginPasswordToggle" title="Show password">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="checkbox-group">
                            <input type="checkbox" id="remember" name="remember" style="border-radius: 4px; border-color: #d1d5db; color: var(--red-800);">
                            <label for="remember" style="font-size: 14px; color: var(--gray-600);">Remember Me</label>
                        </div>
                        <a href="forgot_password.php" style="font-size: 14px; color: var(--red-800); text-decoration: none;">Forgot Password?</a>
                    </div>
                    
                    <button type="submit" name="login" class="btn btn-primary" style="width: 100%; margin-bottom: 16px; font-size: 18px; padding: 12px;">
                        Login
                    </button>
                </form>
                
                <div class="text-center">
                    <span style="color: var(--gray-600);">Don't have an account yet?</span>
                    <a href="#" style="color: #2563eb; font-weight: 600; text-decoration: none; margin-left: 4px;" onclick="closeLoginModal(); openRegisterOptionsModal();">Register</a>
                </div>
                
                <div style="border-top: 1px solid #e5e7eb; margin-top: 24px; padding-top: 16px; text-align: center; background: var(--gray-100); margin-left: -24px; margin-right: -24px; margin-bottom: -24px; padding: 16px; border-radius: 0 0 12px 12px;">
                    <small style="font-size: 14px; color: var(--gray-600);">
                        © <?php echo date('Y'); ?> Batangas State University
                    </small>
                </div>
            </div>
        </div>
    </div>

    <!-- Registration Options Modal -->
    <div id="registerOptionsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <img src="assets/css/images/logo-bsu.png" alt="BSU Logo" class="modal-icon" style="width: 80px; height: 80px; border-radius: 50%; object-fit: cover; box-shadow: 0 4px 6px rgba(0,0,0,0.1); border: 4px solid white; background: white;">
                <h2 class="modal-title">BSU Clinic Record Management System</h2>
            </div>
            
            <div class="modal-body">
                <div class="text-center" style="margin-bottom: 32px;">
                    <h3 style="color: var(--red-800); margin-bottom: 8px; font-weight: 600;">Select Account Type</h3>
                    <p style="color: var(--gray-600); font-size: 14px;">Choose your account type to proceed with registration</p>
                </div>

                <div class="registration-options" style="display: grid; gap: 16px; margin-bottom: 24px;">
                    <!-- Student Registration Option -->
                    <div class="registration-option-card" 
                         onclick="openStudentRegistrationModal()" 
                         style="border: 2px solid #e5e7eb; border-radius: 12px; padding: 20px; cursor: pointer; transition: all 0.3s ease; text-align: center;">
                        <div style="width: 60px; height: 60px; background: linear-gradient(to right, var(--red-800), var(--yellow-500)); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px;">
                            <span class="icon-graduation-cap" style="color: white; font-size: 24px;"></span>
                        </div>
                        <h4 style="color: var(--gray-900); margin-bottom: 8px; font-weight: 600;">Student Registration</h4>
                        <p style="color: var(--gray-600); font-size: 14px; margin-bottom: 12px;">
                            For enrolled students of Batangas State University
                        </p>
                        <div style="display: flex; justify-content: center; gap: 8px;">
                            <span style="background: var(--red-100); color: var(--red-800); padding: 4px 8px; border-radius: 4px; font-size: 12px;">SR Code Required</span>
                            <span style="background: var(--blue-100); color: var(--blue-800); padding: 4px 8px; border-radius: 4px; font-size: 12px;">Clinic Access</span>
                        </div>
                    </div>
                    
                    <!-- Employee Registration Option -->
                    <div class="registration-option-card" 
                         onclick="openEmployeeRegistrationModal()" 
                         style="border: 2px solid #e5e7eb; border-radius: 12px; padding: 20px; cursor: pointer; transition: all 0.3s ease; text-align: center;">
                        <div style="width: 60px; height: 60px; background: linear-gradient(to right, var(--blue-600), var(--green-500)); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px;">
                            <span class="icon-briefcase" style="color: white; font-size: 24px;"></span>
                        </div>
                        <h4 style="color: var(--gray-900); margin-bottom: 8px; font-weight: 600;">Employee Registration</h4>
                        <p style="color: var(--gray-600); font-size: 14px; margin-bottom: 12px;">
                            For faculty, staff, and administrative personnel
                        </p>
                        <div style="display: flex; justify-content: center; gap: 8px;">
                            <span style="background: var(--blue-100); color: var(--blue-800); padding: 4px 8px; border-radius: 4px; font-size: 12px;">Employee ID</span>
                            <span style="background: var(--green-100); color: var(--green-800); padding: 4px 8px; border-radius: 4px; font-size: 12px;">Staff Access</span>
                        </div>
                    </div>
                </div>
                
                <div class="text-center" style="margin-top: 24px;">
                    <span style="color: var(--gray-600);">Already have an account?</span>
                    <a href="#" style="color: #2563eb; font-weight: 600; text-decoration: none; margin-left: 4px;" onclick="closeRegisterOptionsModal(); openLoginModal();">Back to Login</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Student Registration Modal -->
    <div id="studentRegistrationModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <img src="assets/css/images/logo-bsu.png" alt="BSU Logo" class="modal-icon" style="width: 80px; height: 80px; border-radius: 50%; object-fit: cover; box-shadow: 0 4px 6px rgba(0,0,0,0.1); border: 4px solid white; background: white;">
                <h2 class="modal-title">Student Registration</h2>
            </div>
            
            <div class="modal-body">
                <div class="text-center" style="margin-bottom: 24px;">
                    <h3 style="color: var(--red-800); margin-bottom: 4px; font-weight: 600;">Student Account Registration</h3>
                    <p style="color: var(--gray-600); font-size: 14px;">Secure your clinic portal access with your official SR Code</p>
                </div>

                <?php if (!empty($error) && isset($_POST['register_student'])): ?>
                    <div class="alert">
                        <span class="icon-exclamation-circle"></span> <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($success) && isset($_POST['register_student'])): ?>
                    <div class="alert success">
                        <span class="icon-check-circle"></span> <?php echo htmlspecialchars($success); ?>
                    </div>
                    <script>
                        setTimeout(function() {
                            closeStudentRegistrationModal();
                            openLoginModal();
                        }, 2000);
                    </script>
                <?php endif; ?>

                <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                    <!-- Personal Information Section -->
                    <div class="form-section">
                        <h4 style="color: var(--red-800); margin-bottom: 16px; font-weight: 600; border-bottom: 1px solid var(--gray-300); padding-bottom: 8px;">
                            <span class="icon-person"></span> Personal Information
                        </h4>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label" for="student_first_name">
                                    <span class="icon-person"></span> First Name
                                </label>
                                <input type="text" 
                                       name="first_name" 
                                       id="student_first_name" 
                                       placeholder="Enter your first name"
                                       class="form-input" 
                                       required
                                       value="<?php echo isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : ''; ?>">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="student_middle_name">
                                    <span class="icon-person"></span> Middle Name
                                </label>
                                <input type="text" 
                                       name="middle_name" 
                                       id="student_middle_name" 
                                       placeholder="Enter your middle name"
                                       class="form-input"
                                       value="<?php echo isset($_POST['middle_name']) ? htmlspecialchars($_POST['middle_name']) : ''; ?>">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="student_last_name">
                                <span class="icon-person"></span> Last Name
                            </label>
                            <input type="text" 
                                   name="last_name" 
                                   id="student_last_name" 
                                   placeholder="Enter your last name"
                                   class="form-input" 
                                   required
                                   value="<?php echo isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : ''; ?>">
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label" for="student_sex">
                                    <span class="icon-gender"></span> Sex
                                </label>
                                <select name="sex" id="student_sex" class="form-input" required>
                                    <option value="">Select Sex</option>
                                    <option value="Male" <?php echo (isset($_POST['sex']) && $_POST['sex'] == 'Male') ? 'selected' : ''; ?>>Male</option>
                                    <option value="Female" <?php echo (isset($_POST['sex']) && $_POST['sex'] == 'Female') ? 'selected' : ''; ?>>Female</option>
                                    <option value="Other" <?php echo (isset($_POST['sex']) && $_POST['sex'] == 'Other') ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="student_birthdate">
                                    <span class="icon-calendar"></span> Birthdate
                                </label>
                                <input type="date" 
                                       name="birthdate" 
                                       id="student_birthdate" 
                                       class="form-input" 
                                       required
                                       value="<?php echo isset($_POST['birthdate']) ? htmlspecialchars($_POST['birthdate']) : ''; ?>">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="student_contact_number">
                                <span class="icon-phone"></span> Contact Number
                            </label>
                            <input type="tel" 
                                   name="contact_number" 
                                   id="student_contact_number" 
                                   placeholder="Enter your contact number"
                                   class="form-input"
                                   value="<?php echo isset($_POST['contact_number']) ? htmlspecialchars($_POST['contact_number']) : ''; ?>">
                        </div>
                        
                        <!-- Address Section -->
                        <div class="form-group">
                            <label class="form-label" for="student_address">
                                <span class="icon-home"></span> Complete Address
                            </label>
                            <textarea name="address" 
                                      id="student_address" 
                                      placeholder="Enter your complete address (House No., Street, Barangay, City/Municipality, Province)"
                                      class="form-input"
                                      rows="3"><?php echo isset($_POST['address']) ? htmlspecialchars($_POST['address']) : ''; ?></textarea>
                        </div>
                        
                        <!-- Academic Information -->
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label" for="program">
                                    <span class="icon-book"></span> Program/Course
                                </label>
                                <input type="text" 
                                       name="program" 
                                       id="program" 
                                       placeholder="e.g., BS Computer Science"
                                       class="form-input"
                                       value="<?php echo isset($_POST['program']) ? htmlspecialchars($_POST['program']) : ''; ?>">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="year_level">
                                    <span class="icon-award"></span> Year Level
                                </label>
                                <select name="year_level" id="year_level" class="form-input">
                                    <option value="">Select Year Level</option>
                                    <option value="1st Year" <?php echo (isset($_POST['year_level']) && $_POST['year_level'] == '1st Year') ? 'selected' : ''; ?>>1st Year</option>
                                    <option value="2nd Year" <?php echo (isset($_POST['year_level']) && $_POST['year_level'] == '2nd Year') ? 'selected' : ''; ?>>2nd Year</option>
                                    <option value="3rd Year" <?php echo (isset($_POST['year_level']) && $_POST['year_level'] == '3rd Year') ? 'selected' : ''; ?>>3rd Year</option>
                                    <option value="4th Year" <?php echo (isset($_POST['year_level']) && $_POST['year_level'] == '4th Year') ? 'selected' : ''; ?>>4th Year</option>
                                    <option value="5th Year" <?php echo (isset($_POST['year_level']) && $_POST['year_level'] == '5th Year') ? 'selected' : ''; ?>>5th Year</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Account Information Section -->
                    <div class="form-section">
                        <h4 style="color: var(--red-800); margin-bottom: 16px; font-weight: 600; border-bottom: 1px solid var(--gray-300); padding-bottom: 8px;">
                            <span class="icon-person-badge"></span> Account Information
                        </h4>
                        
                        <div class="form-group">
                            <label class="form-label" for="student_sr_code">
                                <span class="icon-person-badge"></span> SR Code
                            </label>
                            <input type="text" 
                                   name="sr_code" 
                                   id="student_sr_code" 
                                   placeholder="Enter your SR Code (e.g., 2021-00001)"
                                   class="form-input" 
                                   required
                                   value="<?php echo isset($_POST['sr_code']) ? htmlspecialchars($_POST['sr_code']) : ''; ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="student_password">
                                <span class="icon-lock"></span> Password
                            </label>
                            <div class="password-input-container">
                                <input type="password" 
                                       name="password" 
                                       id="student_password" 
                                       placeholder="Create a strong password"
                                       class="form-input" 
                                       required>
                                <button type="button" class="password-toggle-btn" id="studentPasswordToggle" title="Show password">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="student_confirm_password">
                                <span class="icon-check-circle"></span> Confirm Password
                            </label>
                            <div class="password-input-container">
                                <input type="password" 
                                       name="confirm_password" 
                                       id="student_confirm_password" 
                                       placeholder="Re-enter password"
                                       class="form-input" 
                                       required>
                                <button type="button" class="password-toggle-btn" id="studentConfirmPasswordToggle" title="Show password">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <button type="submit" name="register_student" class="btn btn-primary" style="width: 100%; margin-bottom: 16px; font-size: 18px; padding: 12px;">
                        <span class="icon-person-check"></span> Register as Student
                    </button>
                </form>
                
                <div class="text-center">
                    <span style="color: var(--gray-600);">Already have an account?</span>
                    <a href="#" style="color: #2563eb; font-weight: 600; text-decoration: none; margin-left: 4px;" onclick="closeStudentRegistrationModal(); openLoginModal();">Back to Login</a>
                    <span style="color: var(--gray-600); margin: 0 8px;">or</span>
                    <a href="#" style="color: var(--red-800); font-weight: 600; text-decoration: none;" onclick="closeStudentRegistrationModal(); openRegisterOptionsModal();">Choose Different Account Type</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Employee Registration Modal -->
    <div id="employeeRegistrationModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <img src="assets/css/images/logo-bsu.png" alt="BSU Logo" class="modal-icon" style="width: 80px; height: 80px; border-radius: 50%; object-fit: cover; box-shadow: 0 4px 6px rgba(0,0,0,0.1); border: 4px solid white; background: white;">
                <h2 class="modal-title">Employee Registration</h2>
            </div>
            
            <div class="modal-body">
                <div class="text-center" style="margin-bottom: 24px;">
                    <h3 style="color: var(--red-800); margin-bottom: 4px; font-weight: 600;">Employee Account Registration</h3>
                    <p style="color: var(--gray-600); font-size: 14px;">Secure your clinic portal access with your official Employee ID</p>
                </div>

                <?php if (!empty($error) && isset($_POST['register_employee'])): ?>
                    <div class="alert">
                        <span class="icon-exclamation-circle"></span> <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($success) && isset($_POST['register_employee'])): ?>
                    <div class="alert success">
                        <span class="icon-check-circle"></span> <?php echo htmlspecialchars($success); ?>
                    </div>
                    <script>
                        setTimeout(function() {
                            closeEmployeeRegistrationModal();
                            openLoginModal();
                        }, 2000);
                    </script>
                <?php endif; ?>

                <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                    <!-- Personal Information Section -->
                    <div class="form-section">
                        <h4 style="color: var(--red-800); margin-bottom: 16px; font-weight: 600; border-bottom: 1px solid var(--gray-300); padding-bottom: 8px;">
                            <span class="icon-person"></span> Personal Information
                        </h4>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label" for="employee_first_name">
                                    <span class="icon-person"></span> First Name
                                </label>
                                <input type="text" 
                                       name="first_name" 
                                       id="employee_first_name" 
                                       placeholder="Enter your first name"
                                       class="form-input" 
                                       required
                                       value="<?php echo isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : ''; ?>">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="employee_middle_name">
                                    <span class="icon-person"></span> Middle Name
                                </label>
                                <input type="text" 
                                       name="middle_name" 
                                       id="employee_middle_name" 
                                       placeholder="Enter your middle name"
                                       class="form-input"
                                       value="<?php echo isset($_POST['middle_name']) ? htmlspecialchars($_POST['middle_name']) : ''; ?>">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="employee_last_name">
                                <span class="icon-person"></span> Last Name
                            </label>
                            <input type="text" 
                                   name="last_name" 
                                   id="employee_last_name" 
                                   placeholder="Enter your last name"
                                   class="form-input" 
                                   required
                                   value="<?php echo isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : ''; ?>">
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label" for="employee_sex">
                                    <span class="icon-gender"></span> Sex
                                </label>
                                <select name="sex" id="employee_sex" class="form-input" required>
                                    <option value="">Select Sex</option>
                                    <option value="Male" <?php echo (isset($_POST['sex']) && $_POST['sex'] == 'Male') ? 'selected' : ''; ?>>Male</option>
                                    <option value="Female" <?php echo (isset($_POST['sex']) && $_POST['sex'] == 'Female') ? 'selected' : ''; ?>>Female</option>
                                    <option value="Other" <?php echo (isset($_POST['sex']) && $_POST['sex'] == 'Other') ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="employee_birthdate">
                                    <span class="icon-calendar"></span> Birthdate
                                </label>
                                <input type="date" 
                                       name="birthdate" 
                                       id="employee_birthdate" 
                                       class="form-input" 
                                       required
                                       value="<?php echo isset($_POST['birthdate']) ? htmlspecialchars($_POST['birthdate']) : ''; ?>">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="employee_contact_number">
                                <span class="icon-phone"></span> Contact Number
                            </label>
                            <input type="tel" 
                                   name="contact_number" 
                                   id="employee_contact_number" 
                                   placeholder="Enter your contact number"
                                   class="form-input"
                                   value="<?php echo isset($_POST['contact_number']) ? htmlspecialchars($_POST['contact_number']) : ''; ?>">
                        </div>
                        
                        <!-- Address Section -->
                        <div class="form-group">
                            <label class="form-label" for="employee_address">
                                <span class="icon-home"></span> Complete Address
                            </label>
                            <textarea name="address" 
                                      id="employee_address" 
                                      placeholder="Enter your complete address (House No., Street, Barangay, City/Municipality, Province)"
                                      class="form-input"
                                      rows="3"><?php echo isset($_POST['address']) ? htmlspecialchars($_POST['address']) : ''; ?></textarea>
                        </div>
                    </div>
                    
                    <!-- Employment Information Section -->
                    <div class="form-section">
                        <h4 style="color: var(--red-800); margin-bottom: 16px; font-weight: 600; border-bottom: 1px solid var(--gray-300); padding-bottom: 8px;">
                            <span class="icon-briefcase"></span> Employment Information
                        </h4>
                        
                        <div class="form-group">
                            <label class="form-label" for="employee_id">
                                <span class="icon-person-badge"></span> Employee ID
                            </label>
                            <input type="text" 
                                   name="employee_id" 
                                   id="employee_id" 
                                   placeholder="Enter your Employee ID"
                                   class="form-input" 
                                   required
                                   value="<?php echo isset($_POST['employee_id']) ? htmlspecialchars($_POST['employee_id']) : ''; ?>">
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label" for="department">
                                    <span class="icon-building"></span> Department
                                </label>
                                <input type="text" 
                                       name="department" 
                                       id="department" 
                                       placeholder="e.g., College of Engineering"
                                       class="form-input"
                                       value="<?php echo isset($_POST['department']) ? htmlspecialchars($_POST['department']) : ''; ?>">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="position">
                                    <span class="icon-user-tie"></span> Position
                                </label>
                                <input type="text" 
                                       name="position" 
                                       id="position" 
                                       placeholder="e.g., Professor, Staff, etc."
                                       class="form-input"
                                       value="<?php echo isset($_POST['position']) ? htmlspecialchars($_POST['position']) : ''; ?>">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="employee_type">
                                <span class="icon-users"></span> Employee Type
                            </label>
                            <select name="employee_type" id="employee_type" class="form-input">
                                <option value="">Select Employee Type</option>
                                <option value="Faculty" <?php echo (isset($_POST['employee_type']) && $_POST['employee_type'] == 'Faculty') ? 'selected' : ''; ?>>Faculty</option>
                                <option value="Staff" <?php echo (isset($_POST['employee_type']) && $_POST['employee_type'] == 'Staff') ? 'selected' : ''; ?>>Staff</option>
                                <option value="Administrative" <?php echo (isset($_POST['employee_type']) && $_POST['employee_type'] == 'Administrative') ? 'selected' : ''; ?>>Administrative</option>
                                <option value="Maintenance" <?php echo (isset($_POST['employee_type']) && $_POST['employee_type'] == 'Maintenance') ? 'selected' : ''; ?>>Maintenance</option>
                                <option value="Other" <?php echo (isset($_POST['employee_type']) && $_POST['employee_type'] == 'Other') ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Account Information Section -->
                    <div class="form-section">
                        <h4 style="color: var(--red-800); margin-bottom: 16px; font-weight: 600; border-bottom: 1px solid var(--gray-300); padding-bottom: 8px;">
                            <span class="icon-lock"></span> Account Information
                        </h4>

                        <div class="form-group">
                            <label class="form-label" for="employee_password">
                                <span class="icon-lock"></span> Password
                            </label>
                            <div class="password-input-container">
                                <input type="password" 
                                       name="password" 
                                       id="employee_password" 
                                       placeholder="Create a strong password"
                                       class="form-input" 
                                       required>
                                <button type="button" class="password-toggle-btn" id="employeePasswordToggle" title="Show password">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="employee_confirm_password">
                                <span class="icon-check-circle"></span> Confirm Password
                            </label>
                            <div class="password-input-container">
                                <input type="password" 
                                       name="confirm_password" 
                                       id="employee_confirm_password" 
                                       placeholder="Re-enter password"
                                       class="form-input" 
                                       required>
                                <button type="button" class="password-toggle-btn" id="employeeConfirmPasswordToggle" title="Show password">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <button type="submit" name="register_employee" class="btn btn-primary" style="width: 100%; margin-bottom: 16px; font-size: 18px; padding: 12px;">
                        <span class="icon-person-check"></span> Register as Employee
                    </button>
                </form>
                
                <div class="text-center">
                    <span style="color: var(--gray-600);">Already have an account?</span>
                    <a href="#" style="color: #2563eb; font-weight: 600; text-decoration: none; margin-left: 4px;" onclick="closeEmployeeRegistrationModal(); openLoginModal();">Back to Login</a>
                    <span style="color: var(--gray-600); margin: 0 8px;">or</span>
                    <a href="#" style="color: var(--red-800); font-weight: 600; text-decoration: none;" onclick="closeEmployeeRegistrationModal(); openRegisterOptionsModal();">Choose Different Account Type</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Floating Login Button -->
    <button class="floating-btn" onclick="openLoginModal()" title="Login">
        <span class="icon-login"></span>
    </button>

    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script>
        // Initialize AOS (Animate On Scroll)
        AOS.init({
            duration: 800,
            easing: 'ease-in-out',
            once: true,
            offset: 100,
            disable: window.innerWidth < 768
        });

        // Mobile menu functionality
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const navLinks = document.getElementById('navLinks');

        function toggleMobileMenu() {
            navLinks.classList.toggle('active');
        }

        // Handle responsive menu
        function handleResponsiveMenu() {
            if (window.innerWidth < 768) {
                mobileMenuBtn.style.display = 'block';
                mobileMenuBtn.addEventListener('click', toggleMobileMenu);
            } else {
                mobileMenuBtn.style.display = 'none';
                navLinks.classList.remove('active');
            }
        }

        // Modal functionality
        function openLoginModal() {
            document.getElementById('loginModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeLoginModal() {
            document.getElementById('loginModal').classList.remove('show');
            document.body.style.overflow = '';
        }

        // Registration Options Modal Functions
        function openRegisterOptionsModal() {
            document.getElementById('registerOptionsModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeRegisterOptionsModal() {
            document.getElementById('registerOptionsModal').classList.remove('show');
            document.body.style.overflow = '';
        }

        // Student Registration Modal Functions
        function openStudentRegistrationModal() {
            closeRegisterOptionsModal();
            document.getElementById('studentRegistrationModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeStudentRegistrationModal() {
            document.getElementById('studentRegistrationModal').classList.remove('show');
            document.body.style.overflow = '';
        }

        // Employee Registration Modal Functions
        function openEmployeeRegistrationModal() {
            closeRegisterOptionsModal();
            document.getElementById('employeeRegistrationModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeEmployeeRegistrationModal() {
            document.getElementById('employeeRegistrationModal').classList.remove('show');
            document.body.style.overflow = '';
        }

        // Password visibility toggle functionality
        function togglePasswordVisibility(inputId, toggleBtn) {
            const passwordInput = document.getElementById(inputId);
            const icon = toggleBtn.querySelector('i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
                toggleBtn.setAttribute('title', 'Hide password');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
                toggleBtn.setAttribute('title', 'Show password');
            }
        }

        // Initialize password toggle buttons
        function initializePasswordToggles() {
            // Login modal password toggle
            const loginPasswordToggle = document.getElementById('loginPasswordToggle');
            if (loginPasswordToggle) {
                loginPasswordToggle.addEventListener('click', function() {
                    togglePasswordVisibility('password', this);
                });
            }

            // Student registration password toggles
            const studentPasswordToggle = document.getElementById('studentPasswordToggle');
            if (studentPasswordToggle) {
                studentPasswordToggle.addEventListener('click', function() {
                    togglePasswordVisibility('student_password', this);
                });
            }

            const studentConfirmPasswordToggle = document.getElementById('studentConfirmPasswordToggle');
            if (studentConfirmPasswordToggle) {
                studentConfirmPasswordToggle.addEventListener('click', function() {
                    togglePasswordVisibility('student_confirm_password', this);
                });
            }

            // Employee registration password toggles
            const employeePasswordToggle = document.getElementById('employeePasswordToggle');
            if (employeePasswordToggle) {
                employeePasswordToggle.addEventListener('click', function() {
                    togglePasswordVisibility('employee_password', this);
                });
            }

            const employeeConfirmPasswordToggle = document.getElementById('employeeConfirmPasswordToggle');
            if (employeeConfirmPasswordToggle) {
                employeeConfirmPasswordToggle.addEventListener('click', function() {
                    togglePasswordVisibility('employee_confirm_password', this);
                });
            }
        }

        // Close modal when clicking outside
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal')) {
                e.target.classList.remove('show');
                document.body.style.overflow = '';
            }
        });

        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeLoginModal();
                closeRegisterOptionsModal();
                closeStudentRegistrationModal();
                closeEmployeeRegistrationModal();
            }
        });

        // Smooth scrolling for navigation links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        // Header scroll effect
        window.addEventListener('scroll', function() {
            const header = document.getElementById('header');
            if (window.scrollY > 50) {
                header.classList.add('scrolled');
            } else {
                header.classList.remove('scrolled');
            }
        });

        // Viewport height fix for mobile
        function setVH() {
            let vh = window.innerHeight * 0.01;
            document.documentElement.style.setProperty('--vh', `${vh}px`);
        }

        // Show appropriate modal based on PHP conditions
        <?php if ((!empty($error) && !isset($_POST['register_student']) && !isset($_POST['register_employee'])) || isset($login_success)): ?>
            openLoginModal();
        <?php elseif ((!empty($error) && isset($_POST['register_student'])) || (!empty($success) && isset($_POST['register_student']))): ?>
            openStudentRegistrationModal();
        <?php elseif ((!empty($error) && isset($_POST['register_employee'])) || (!empty($success) && isset($_POST['register_employee']))): ?>
            openEmployeeRegistrationModal();
        <?php endif; ?>

        // Auto-hide success messages
        document.addEventListener('DOMContentLoaded', function() {
            const successAlerts = document.querySelectorAll('.alert.success');
            successAlerts.forEach(function(alert) {
                setTimeout(function() {
                    alert.style.opacity = '0';
                    setTimeout(function() {
                        alert.remove();
                    }, 300);
                }, 3000);
            });
            
            // Initialize responsive features
            handleResponsiveMenu();
            setVH();
            
            // Initialize password toggle functionality
            initializePasswordToggles();
        });

        // Window resize handlers
        window.addEventListener('resize', function() {
            handleResponsiveMenu();
            setVH();
        });

        // Orientation change handler
        window.addEventListener('orientationchange', function() {
            setTimeout(setVH, 100);
        });
    </script>
    
</body>
</html>