<?php
// Simple password reset request page
$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    if (empty($username)) {
        $error = 'Please enter your username or email.';
    } else {
        // In a real app, you would send a reset link or code here
        $success = 'If your account exists, a password reset link will be sent to your email.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - BSU Clinic Record Management System</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-red-50 to-yellow-50 min-h-screen flex items-center justify-center">
    <div class="w-full max-w-md mx-auto">
        <div class="bg-white rounded-2xl shadow-xl overflow-hidden">
            <div class="px-8 pt-8 pb-4 text-center bg-gradient-to-r from-red-800 to-red-500">
                <h2 class="text-2xl font-bold text-white mb-1">Forgot Password</h2>
            </div>
            <div class="px-8 py-6">
                <?php if ($error): ?>
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-2 rounded mb-4 text-sm">
                        <?php echo $error; ?>
                    </div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-2 rounded mb-4 text-sm">
                        <?php echo $success; ?>
                    </div>
                <?php endif; ?>
                <form method="POST" class="space-y-4">
                    <div>
                        <label for="username" class="block text-sm font-medium text-gray-700 mb-1">Username or Email</label>
                        <input type="text" class="block w-full rounded-lg border border-gray-300 px-4 py-2 focus:outline-none focus:ring-2 focus:ring-red-800" id="username" name="username" required autofocus>
                    </div>
                    <div>
                        <button type="submit" class="w-full py-2 px-4 rounded-lg bg-red-800 text-white font-semibold text-lg shadow hover:bg-red-900 transition">Send Reset Link</button>
                    </div>
                </form>
                <div class="mt-4 text-center">
                    <a href="index.php" class="text-sm text-red-800 hover:underline">Back to Login</a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
