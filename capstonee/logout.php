<?php
session_start();

// Log the logout action in analytics if user is logged in
// Wrap in try-catch to ensure logout always works even if user was deleted from database
if (isset($_SESSION['user_id'])) {
    try {
        include 'config/database.php';
        
        $action = "User logged out";
        $stmt = $conn->prepare("
            INSERT INTO analytics_data (user_id, action, timestamp)
            VALUES (?, ?, NOW())
        ");
        $stmt->bind_param("is", $_SESSION['user_id'], $action);
        $stmt->execute();
        $stmt->close();
    } catch (Exception $e) {
        // Silently fail - user might have been deleted from database
        // Logout should still proceed regardless
        error_log("Logout analytics logging failed: " . $e->getMessage());
    }
}

// Destroy the session
session_unset();
session_destroy();

// Clear the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Redirect to login page
header("Location: index.php");
exit();
?>