<?php
/**
 * Debug Script for Hostinger Deployment
 * This file helps identify common deployment issues
 * DELETE THIS FILE AFTER FIXING ISSUES FOR SECURITY
 */

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

echo "<h1>BSU Clinic - Deployment Debug</h1>";
echo "<style>body{font-family:Arial;padding:20px;} .success{color:green;} .error{color:red;} .warning{color:orange;} pre{background:#f5f5f5;padding:10px;border:1px solid #ddd;}</pre>";

// Check PHP Version
echo "<h2>1. PHP Version</h2>";
echo "<p class='success'>PHP Version: " . phpversion() . "</p>";

// Check Database Connection
echo "<h2>2. Database Connection</h2>";
try {
    include 'config/database.php';
    if (isset($conn) && $conn instanceof mysqli) {
        if ($conn->connect_error) {
            echo "<p class='error'>❌ Database Connection Failed: " . $conn->connect_error . "</p>";
        } else {
            echo "<p class='success'>✅ Database Connection Successful</p>";
            echo "<p>Database: " . $database . "</p>";
            echo "<p>Host: " . $host . "</p>";
        }
    } else {
        echo "<p class='error'>❌ Database connection object not found</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ Database Error: " . $e->getMessage() . "</p>";
}

// Check File Permissions
echo "<h2>3. File Permissions</h2>";
$directories = [
    'uploads' => 'Uploads directory',
    'uploads/pictures' => 'Pictures upload directory',
    'assets' => 'Assets directory',
    'assets/qrcodes' => 'QR Codes directory'
];

foreach ($directories as $dir => $name) {
    if (file_exists($dir)) {
        $writable = is_writable($dir) ? '✅ Writable' : '❌ Not Writable';
        $readable = is_readable($dir) ? '✅ Readable' : '❌ Not Readable';
        echo "<p>$name: $readable, $writable</p>";
    } else {
        echo "<p class='warning'>⚠️ $name: Directory does not exist</p>";
    }
}

// Check Required PHP Extensions
echo "<h2>4. PHP Extensions</h2>";
$required_extensions = ['mysqli', 'gd', 'json', 'mbstring', 'curl'];
foreach ($required_extensions as $ext) {
    if (extension_loaded($ext)) {
        echo "<p class='success'>✅ $ext extension is loaded</p>";
    } else {
        echo "<p class='error'>❌ $ext extension is NOT loaded</p>";
    }
}

// Check File Paths
echo "<h2>5. File Paths</h2>";
$files_to_check = [
    'config/database.php' => 'Database config',
    'index.php' => 'Index file',
    'includes/header.php' => 'Header file',
    'includes/navigation.php' => 'Navigation file'
];

foreach ($files_to_check as $file => $name) {
    if (file_exists($file)) {
        echo "<p class='success'>✅ $name exists</p>";
    } else {
        echo "<p class='error'>❌ $name NOT found</p>";
    }
}

// Check Server Information
echo "<h2>6. Server Information</h2>";
echo "<pre>";
echo "Document Root: " . $_SERVER['DOCUMENT_ROOT'] . "\n";
echo "Script Name: " . $_SERVER['SCRIPT_NAME'] . "\n";
echo "PHP Self: " . $_SERVER['PHP_SELF'] . "\n";
echo "Request URI: " . $_SERVER['REQUEST_URI'] . "\n";
echo "</pre>";

// Test Database Query
echo "<h2>7. Database Query Test</h2>";
if (isset($conn) && !$conn->connect_error) {
    try {
        $result = $conn->query("SHOW TABLES");
        if ($result) {
            echo "<p class='success'>✅ Can query database</p>";
            echo "<p>Tables found: " . $result->num_rows . "</p>";
        } else {
            echo "<p class='error'>❌ Cannot query database: " . $conn->error . "</p>";
        }
    } catch (Exception $e) {
        echo "<p class='error'>❌ Query Error: " . $e->getMessage() . "</p>";
    }
}

// Check for Hardcoded Paths
echo "<h2>8. Hardcoded Path Check</h2>";
$files_to_scan = ['includes/header.php'];
foreach ($files_to_scan as $file) {
    if (file_exists($file)) {
        $content = file_get_contents($file);
        if (strpos($content, '/capstone(foreal this time)/') !== false) {
            echo "<p class='error'>❌ $file contains hardcoded paths</p>";
        } else {
            echo "<p class='success'>✅ $file looks good</p>";
        }
    }
}

echo "<hr>";
echo "<p><strong>⚠️ IMPORTANT: Delete this file (debug.php) after fixing issues for security!</strong></p>";
?>

