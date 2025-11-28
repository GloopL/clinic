<?php
session_start();
include '../../config/database.php';

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    header("Location: $protocol://$host/index.php");
    exit();
}

$success_message = '';
$error_message = '';
$form_type = '';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['qr_data'])) {
    $qr_data = $_POST['qr_data'];
    try {
        $decoded_data = json_decode(base64_decode($qr_data), true);
        if ($decoded_data && isset($decoded_data['form_type'])) {
            $form_type = $decoded_data['form_type'];
            $success_message = "QR code scanned successfully!";
        } else {
            $error_message = "Invalid QR code data.";
        }
    } catch (Exception $e) {
        $error_message = "Error processing QR code: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scan Form QR Code</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/style.css">
</head>
<body>

    <?php include '../../includes/header.php'; ?>
    <div class="min-h-screen bg-gray-50 py-8">
        <div class="max-w-xl mx-auto">
            <div class="bg-white shadow-lg rounded-lg p-8">
                <h2 class="text-2xl font-bold text-blue-700 mb-6 text-center">Scan QR Code for Forms</h2>
                <?php if (!empty($success_message)): ?>
                    <div class="mb-4 p-3 rounded bg-green-100 text-green-800 text-center text-sm font-semibold"><?php echo $success_message; ?></div>
                <?php endif; ?>
                <?php if (!empty($error_message)): ?>
                    <div class="mb-4 p-3 rounded bg-red-100 text-red-800 text-center text-sm font-semibold"><?php echo $error_message; ?></div>
                <?php endif; ?>
                <div class="bg-gray-100 rounded-lg p-6 mb-6">
                    <h3 class="text-lg font-semibold mb-4 text-gray-700">Scan QR Code</h3>
                    <div class="flex justify-center mb-4">
                        <div id="qr-reader" class="w-full"></div>
                    </div>
                    <p class="text-center text-gray-500 mb-4">or</p>
                    <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" class="space-y-4">
                        <div>
                            <label for="qr_data" class="block mb-2 font-medium text-gray-700">Enter QR Code Data</label>
                            <textarea class="block w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-400 focus:outline-none" id="qr_data" name="qr_data" rows="3" required></textarea>
                        </div>
                        <div class="flex justify-end">
                            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold px-6 py-2 rounded-lg shadow">Process QR Code</button>
                        </div>
                    </form>
                </div>
                <?php if (!empty($success_message) && $form_type): ?>
                    <div class="bg-green-50 rounded-lg p-6 mt-6">
                        <h3 class="text-lg font-semibold mb-4 text-green-700">Fill Up Form</h3>
                        <div class="flex justify-center mt-3">
                            <?php 
                            $form_url = '';
                            switch ($form_type) {
                                case 'history_form':
                                    $form_url = '../../modules/forms/history_form.php';
                                    break;
                                case 'dental_form':
                                    $form_url = '../../modules/forms/dental_form.php';
                                    break;
                                case 'medical_form':
                                    $form_url = '../../modules/forms/medical_form.php';
                                    break;
                            }
                            if ($form_url): ?>
                                <a href="<?php echo $form_url; ?>" class="bg-green-600 hover:bg-green-700 text-white font-semibold px-6 py-2 rounded-lg shadow">Go to Form</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php include '../../includes/footer.php'; ?>
    <script src="../../assets/js/jquery.min.js"></script>
    <script src="../../assets/js/html5-qrcode.min.js"></script>
    <script>
        $(document).ready(function() {
            const html5QrCode = new Html5Qrcode("qr-reader");
            const qrConfig = { fps: 10, qrbox: { width: 250, height: 250 } };
            html5QrCode.start(
                { facingMode: "environment" },
                qrConfig,
                onScanSuccess,
                onScanFailure
            ).catch(err => {
                $("#qr-reader").html('<div class="alert alert-warning">Camera access not available or denied. Please enter QR code data manually.</div>');
            });
            function onScanSuccess(decodedText, decodedResult) {
                html5QrCode.stop();
                $("#qr_data").val(decodedText);
                $("form").submit();
            }
            function onScanFailure(error) {
                // Ignore and keep scanning
            }
        });
    </script>
</body>
</html>
