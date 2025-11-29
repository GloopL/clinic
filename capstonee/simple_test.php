<?php
// Simple test - no database connection
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

echo "<h1>✅ PHP is Working!</h1>";
echo "<p>PHP Version: " . phpversion() . "</p>";
echo "<p>Current Directory: " . __DIR__ . "</p>";
echo "<p>Document Root: " . ($_SERVER['DOCUMENT_ROOT'] ?? 'Not set') . "</p>";

// Test if config file exists
echo "<h2>File Check:</h2>";
$files = ['config/database.php', 'index.php', '.htaccess'];
foreach ($files as $file) {
    $exists = file_exists($file) ? '✅' : '❌';
    echo "<p>$exists $file</p>";
}

// Test database config file (without executing)
echo "<h2>Database Config Test:</h2>";
if (file_exists('config/database.php')) {
    echo "<p>✅ config/database.php exists</p>";
    $content = file_get_contents('config/database.php');
    if (strpos($content, 'localhost') !== false && strpos($content, 'root') !== false) {
        echo "<p style='color:orange;'>⚠️ Database still using localhost/root - needs Hostinger credentials!</p>";
    } else {
        echo "<p style='color:green;'>✅ Database config looks updated</p>";
    }
} else {
    echo "<p style='color:red;'>❌ config/database.php not found</p>";
}
?>

