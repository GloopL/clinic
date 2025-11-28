<?php
// Ultra-simple test file
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>PHP is Working!</h1>";
echo "<p>PHP Version: " . phpversion() . "</p>";

// Test database connection
echo "<h2>Testing Database Connection...</h2>";
try {
    include 'config/database.php';
    if (isset($conn)) {
        echo "<p style='color:green;'>✅ Database connection object created</p>";
        if ($conn->connect_error) {
            echo "<p style='color:red;'>❌ Connection Error: " . $conn->connect_error . "</p>";
        } else {
            echo "<p style='color:green;'>✅ Database connected successfully!</p>";
        }
    } else {
        echo "<p style='color:red;'>❌ Database connection object not found</p>";
    }
} catch (Exception $e) {
    echo "<p style='color:red;'>❌ Error: " . $e->getMessage() . "</p>";
} catch (Error $e) {
    echo "<p style='color:red;'>❌ Fatal Error: " . $e->getMessage() . "</p>";
    echo "<p>File: " . $e->getFile() . "</p>";
    echo "<p>Line: " . $e->getLine() . "</p>";
}

echo "<h2>PHP Extensions:</h2>";
$extensions = ['mysqli', 'gd', 'json', 'mbstring'];
foreach ($extensions as $ext) {
    $loaded = extension_loaded($ext) ? '✅' : '❌';
    echo "<p>$loaded $ext</p>";
}
?>

