<?php
session_start();
include 'config/database.php';

// Set timezone to Philippines
date_default_timezone_set('Asia/Manila');

// Check if this is a modal request
$isModal = isset($_GET['modal']) && $_GET['modal'] === 'true';

// Redirect if not logged in (only if not modal)
if (!isset($_SESSION['user_id'])) {
    if ($isModal) {
        // For modal, send a message to parent to redirect
        echo '<script>window.parent.postMessage("redirectToLogin", "*");</script>';
        exit();
    } else {
        header("Location: index.php");
        exit();
    }
}

$user_id = $_SESSION['user_id'];
$message = '';
$message_type = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Validate inputs
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $message = "All fields are required.";
        $message_type = "error";
    } elseif ($new_password !== $confirm_password) {
        $message = "New password and confirmation do not match.";
        $message_type = "error";
    } elseif (strlen($new_password) < 8) {
        $message = "New password must be at least 8 characters long.";
        $message_type = "error";
    } else {
        // Get current user data
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        if ($user) {
            // Verify current password
            if (password_verify($current_password, $user['password'])) {
                // Update password
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $update_stmt->bind_param("si", $hashed_password, $user_id);
                
                if ($update_stmt->execute()) {
                    $message = "Password changed successfully!";
                    $message_type = "success";
                    
                    // Check if this is an AJAX request
                    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                        // Return JSON response for AJAX
                        echo json_encode([
                            'success' => true,
                            'message' => $message,
                            'message_type' => $message_type
                        ]);
                        exit();
                    }
                } else {
                    $message = "Error updating password. Please try again.";
                    $message_type = "error";
                }
                $update_stmt->close();
            } else {
                $message = "Current password is incorrect.";
                $message_type = "error";
            }
        } else {
            $message = "User not found.";
            $message_type = "error";
        }
    }
    
    // If AJAX request and we haven't exited yet (error case)
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        echo json_encode([
            'success' => false,
            'message' => $message,
            'message_type' => $message_type
        ]);
        exit();
    }
}

// If this is an AJAX request for modal content, output ONLY the form (no full HTML page)
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest' && !isset($_POST['current_password'])) {
    ob_start();
?>
<!-- Modal header -->


<!-- Error message -->
<?php if ($message && $message_type == 'error'): ?>
    <div class="bg-red-100 border-l-4 border-red-400 text-red-700 p-4 mx-6 mt-4" id="errorMessage">
        <div class="flex items-center">
            <i class="bi bi-exclamation-circle mr-2"></i>
            <?php echo htmlspecialchars($message); ?>
        </div>
    </div>
<?php endif; ?>

<!-- Success message -->
<?php if ($message && $message_type == 'success'): ?>
    <div class="bg-green-100 border-l-4 border-green-400 text-green-700 p-4 mx-6 mt-4" id="successMessage">
        <div class="flex items-center">
            <i class="bi bi-check-circle mr-2"></i>
            <?php echo htmlspecialchars($message); ?>
        </div>
    </div>
<?php endif; ?>

<form method="POST" class="p-6 space-y-6" id="passwordForm">
    <div class="space-y-6">
        <!-- Current Password -->
        <div>
            <label for="current_password" class="block text-sm font-medium text-gray-700 mb-1">
                Current Password <span class="text-red-500">*</span>
            </label>
            <div class="relative">
                <input type="password" 
                       id="current_password" 
                       name="current_password" 
                       required
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-maroon focus:border-maroon focus-maroon pr-10"
                       placeholder="Enter your current password">
                <button type="button" 
                        class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-500 hover:text-gray-700"
                        onclick="togglePassword('current_password')"
                        data-target="current_password">
                    <i class="bi bi-eye"></i>
                </button>
            </div>
        </div>

        <!-- New Password -->
        <div>
            <label for="new_password" class="block text-sm font-medium text-gray-700 mb-1">
                New Password <span class="text-red-500">*</span>
            </label>
            <div class="relative">
                <input type="password" 
                       id="new_password" 
                       name="new_password" 
                       required
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-maroon focus:border-maroon focus-maroon pr-10"
                       placeholder="Enter new password (min. 8 characters)"
                       minlength="8">
                <button type="button" 
                        class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-500 hover:text-gray-700"
                        onclick="togglePassword('new_password')"
                        data-target="new_password">
                    <i class="bi bi-eye"></i>
                </button>
            </div>
        </div>

        <!-- Confirm Password -->
        <div>
            <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-1">
                Confirm New Password <span class="text-red-500">*</span>
            </label>
            <div class="relative">
                <input type="password" 
                       id="confirm_password" 
                       name="confirm_password" 
                       required
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-maroon focus:border-maroon focus-maroon pr-10"
                       placeholder="Confirm your new password">
                <button type="button" 
                        class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-500 hover:text-gray-700"
                        onclick="togglePassword('confirm_password')"
                        data-target="confirm_password">
                    <i class="bi bi-eye"></i>
                </button>
            </div>
        </div>

        <!-- Password Guidelines -->
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
            <h3 class="text-sm font-semibold text-blue-800 mb-2 flex items-center">
                <i class="bi bi-info-circle mr-2"></i> Password Requirements
            </h3>
            <ul class="text-xs text-blue-700 space-y-1">
                <li>• Minimum 8 characters</li>
                <li>• Use a combination of letters and numbers</li>
                <li>• Avoid using common passwords</li>
                <li>• Don't reuse old passwords</li>
            </ul>
        </div>
    </div>

    <div class="flex gap-4 pt-6 border-t">
        <button type="submit" class="maroon-gradient-button text-white px-6 py-2 rounded-lg font-medium hover:shadow-lg transition-all flex items-center gap-2">
            <i class="bi bi-key-fill"></i> Change Password
        </button>
        <button type="button" onclick="window.parent.closePasswordModal()" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-6 py-2 rounded-lg font-medium transition duration-300 flex items-center gap-2">
            <i class="bi bi-x-lg"></i> Cancel
        </button>
    </div>
</form>

<script>
// Simple password toggle function for inline onclick
function togglePassword(inputId) {
    const input = document.getElementById(inputId);
    const button = event.target.closest('button');
    const icon = button.querySelector('i');
    
    if (input && icon) {
        if (input.type === 'password') {
            input.type = 'text';
            icon.className = 'bi bi-eye-slash';
            button.setAttribute('title', 'Hide password');
        } else {
            input.type = 'password';
            icon.className = 'bi bi-eye';
            button.setAttribute('title', 'Show password');
        }
    }
}

// Handle form submission
document.getElementById('passwordForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    
    // Validate passwords match
    const newPassword = document.getElementById('new_password').value;
    const confirmPassword = document.getElementById('confirm_password').value;
    
    if (newPassword !== confirmPassword) {
        alert('Passwords do not match. Please check and try again.');
        return;
    }
    
    if (newPassword.length < 8) {
        alert('Password must be at least 8 characters long.');
        return;
    }
    
    // Show loading state
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Changing...';
    submitBtn.disabled = true;
    
    // Submit via AJAX
    const formData = new FormData(this);
    
    fetch('change_password.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            this.reset();
            setTimeout(() => {
                window.parent.closePasswordModal();
            }, 2000);
        } else {
            alert('Error: ' + data.message);
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred. Please try again.');
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    });
});
</script>
<?php
    $content = ob_get_clean();
    echo $content;
    exit();
}
// If not an AJAX request, output the full HTML page (for direct access)
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
    /* Custom maroon theme (#800000) */
    :root {
        --maroon-primary: #800000;
        --maroon-dark: #660000;
        --maroon-light: #a00000;
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
    
    /* Hide scrollbar for iframe */
    body {
        overflow: hidden;
        background: white;
    }
    
    /* Password field with eye icon */
    .password-container {
        position: relative;
    }
    
    .password-input {
        padding-right: 40px !important;
    }
    
    .password-toggle {
        position: absolute;
        right: 12px;
        top: 34px;
        background: none;
        border: none;
        color: #6b7280;
        cursor: pointer;
        padding: 0;
        width: 20px;
        height: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    .password-toggle:hover {
        color: #374151;
    }
    
    .password-toggle i {
        font-size: 16px;
    }
</style>
</head>
<body class="bg-white">
    <div class="max-h-screen overflow-y-auto p-0">
        <!-- Modal header -->
        <div class="sticky top-0 z-10 bg-white border-b px-6 py-4">
            <div class="flex justify-between items-center">
                <h2 class="text-xl font-bold text-gray-800">Change Password</h2>
                <button onclick="window.close()" class="text-gray-500 hover:text-gray-700">
                    <i class="bi bi-x-lg text-2xl"></i>
                </button>
            </div>
        </div>

        <!-- Error message -->
        <?php if ($message && $message_type == 'error'): ?>
            <div class="bg-red-100 border-l-4 border-red-400 text-red-700 p-4 mx-6 mt-4" id="errorMessage">
                <div class="flex items-center">
                    <i class="bi bi-exclamation-circle mr-2"></i>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Success message -->
        <?php if ($message && $message_type == 'success'): ?>
            <div class="bg-green-100 border-l-4 border-green-400 text-green-700 p-4 mx-6 mt-4" id="successMessage">
                <div class="flex items-center">
                    <i class="bi bi-check-circle mr-2"></i>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            </div>
        <?php endif; ?>

        <form method="POST" class="p-6 space-y-6" id="passwordForm">
            <div class="space-y-6">
                <!-- Current Password -->
                <div>
                    <label for="current_password" class="block text-sm font-medium text-gray-700 mb-1">
                        Current Password <span class="text-red-500">*</span>
                    </label>
                    <div class="relative">
                        <input type="password" 
                               id="current_password" 
                               name="current_password" 
                               required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-maroon focus:border-maroon focus-maroon pr-10"
                               placeholder="Enter your current password">
                        <button type="button" 
                                class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-500 hover:text-gray-700"
                                onclick="togglePassword('current_password')"
                                data-target="current_password">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>

                <!-- New Password -->
                <div>
                    <label for="new_password" class="block text-sm font-medium text-gray-700 mb-1">
                        New Password <span class="text-red-500">*</span>
                    </label>
                    <div class="relative">
                        <input type="password" 
                               id="new_password" 
                               name="new_password" 
                               required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-maroon focus:border-maroon focus-maroon pr-10"
                               placeholder="Enter new password (min. 8 characters)"
                               minlength="8">
                        <button type="button" 
                                class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-500 hover:text-gray-700"
                                onclick="togglePassword('new_password')"
                                data-target="new_password">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>

                <!-- Confirm Password -->
                <div>
                    <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-1">
                        Confirm New Password <span class="text-red-500">*</span>
                    </label>
                    <div class="relative">
                        <input type="password" 
                               id="confirm_password" 
                               name="confirm_password" 
                               required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-maroon focus:border-maroon focus-maroon pr-10"
                               placeholder="Confirm your new password">
                        <button type="button" 
                                class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-500 hover:text-gray-700"
                                onclick="togglePassword('confirm_password')"
                                data-target="confirm_password">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>

                <!-- Password Guidelines -->
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                    <h3 class="text-sm font-semibold text-blue-800 mb-2 flex items-center">
                        <i class="bi bi-info-circle mr-2"></i> Password Requirements
                    </h3>
                    <ul class="text-xs text-blue-700 space-y-1">
                        <li>• Minimum 8 characters</li>
                        <li>• Use a combination of letters and numbers</li>
                        <li>• Avoid using common passwords</li>
                        <li>• Don't reuse old passwords</li>
                    </ul>
                </div>
            </div>

            <div class="flex gap-4 pt-6 border-t">
                <button type="submit" class="maroon-gradient-button text-white px-6 py-2 rounded-lg font-medium hover:shadow-lg transition-all flex items-center gap-2">
                    <i class="bi bi-key-fill"></i> Change Password
                </button>
                <button type="button" onclick="window.close()" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-6 py-2 rounded-lg font-medium transition duration-300 flex items-center gap-2">
                    <i class="bi bi-x-lg"></i> Cancel
                </button>
            </div>
        </form>
    </div>

    <script>
    // Simple password toggle function for inline onclick
    function togglePassword(inputId) {
        const input = document.getElementById(inputId);
        const button = event.target.closest('button');
        const icon = button.querySelector('i');
        
        if (input && icon) {
            if (input.type === 'password') {
                input.type = 'text';
                icon.className = 'bi bi-eye-slash';
                button.setAttribute('title', 'Hide password');
            } else {
                input.type = 'password';
                icon.className = 'bi bi-eye';
                button.setAttribute('title', 'Show password');
            }
        }
    }
    
    // Handle form submission
    document.getElementById('passwordForm')?.addEventListener('submit', function(e) {
        e.preventDefault();
        
        // Validate passwords match
        const newPassword = document.getElementById('new_password').value;
        const confirmPassword = document.getElementById('confirm_password').value;
        
        if (newPassword !== confirmPassword) {
            alert('Passwords do not match. Please check and try again.');
            return;
        }
        
        if (newPassword.length < 8) {
            alert('Password must be at least 8 characters long.');
            return;
        }
        
        // Show loading state
        const submitBtn = this.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Changing...';
        submitBtn.disabled = true;
        
        // Submit via AJAX
        const formData = new FormData(this);
        
        fetch('change_password.php', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(data.message);
                this.reset();
            } else {
                alert('Error: ' + data.message);
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred. Please try again.');
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        });
    });
    </script>
</body>
</html>