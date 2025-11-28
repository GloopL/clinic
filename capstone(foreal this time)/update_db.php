<?php
include 'config/database.php';

// Add missing columns to medical_exams table
$conn->query("ALTER TABLE medical_exams ADD COLUMN temperature FLOAT AFTER pulse_rate");
$conn->query("ALTER TABLE medical_exams ADD COLUMN verification_status ENUM('Pending', 'Verified', 'Rejected') DEFAULT 'Pending' AFTER license_no");

// Add picture column to patients table
$conn->query("ALTER TABLE patients ADD COLUMN picture VARCHAR(255) AFTER year_level");

// Create user_activities table if it doesn't exist
$conn->query("CREATE TABLE IF NOT EXISTS user_activities (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    user_id INT(11) NOT NULL,
    activity_type VARCHAR(50) NOT NULL,
    activity_description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

echo "Database updated successfully!";
?>
