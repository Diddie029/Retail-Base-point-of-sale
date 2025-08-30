<?php
session_start();
require_once __DIR__ . '/../include/functions.php';
include '../include/db.php';

if(isset($_SESSION['user_id'])) {
    header("Location: ../dashboard/dashboard.php");
    exit();
}

if(!isset($_SESSION['temp_user_id'])) {
    header("Location: signup.php");
    exit();
}

$message = '';
$messageType = '';

if($_SERVER["REQUEST_METHOD"] == "POST") {
    $otp = trim($_POST['otp']);

    if(empty($otp)) {
        $message = "Please enter the OTP code";
        $messageType = "danger";
    } elseif(strlen($otp) !== 6 || !is_numeric($otp)) {
        $message = "Please enter a valid 6-digit OTP code";
        $messageType = "danger";
    } else {
        // Verify OTP
        if(verifyOTP($conn, $_SESSION['temp_user_id'], $otp)) {
            // Generate new OTP for email verification step
            $newOtp = createOTP($conn, $_SESSION['temp_user_id']);

            // Send email with new OTP
            $subject = "Complete Your Registration - POS System";
            $emailMessage = "Almost Done!

Your OTP verification was successful. Here is your final verification code:

Final OTP Code: {$newOtp}

This code will expire in 10 minutes.

Please use this code to complete your registration.

Best regards,
POS System";

            sendEmail($_SESSION['temp_email'], $subject, $emailMessage);

            $_SESSION['otp_verified'] = true;
            header("Location: complete_registration.php");
            exit();
        } else {
            $message = "Invalid or expired OTP code. Please try again.";
            $messageType = "danger";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify OTP - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/auth.css">
    <style>
        /* Page-specific overrides if needed */
    </style>
</head>
<body>
    <div class="auth-container verify-container">
        <div class="auth-card verify-card">
            <div class="auth-header verify-header">
                <h3><i class="bi bi-shield-check me-3"></i>Verify OTP</h3>
                <p>Enter the 6-digit code sent to your email</p>
            </div>
            <div class="auth-body verify-body">
                <?php if(!empty($message)): ?>
                    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                        <i class="bi bi-info-circle me-2"></i><?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" id="otpForm">
                    <div class="mb-4">
                        <label for="otp" class="form-label">
                            <i class="bi bi-key me-2"></i>OTP Code
                        </label>
                        <input type="text" class="form-control otp-input" id="otp" name="otp"
                               maxlength="6" pattern="[0-9]{6}" required
                               placeholder="000000" autocomplete="off">
                        <div class="form-text text-center">
                            Check your email for the verification code
                        </div>
                    </div>

                    <div class="d-grid mb-3">
                        <button type="submit" class="btn btn-info auth-btn btn-verify">
                            <i class="bi bi-check-circle me-2"></i>Verify Code
                        </button>
                    </div>
                </form>

                <div class="resend-link">
                    <a href="#" onclick="resendOTP()" class="text-decoration-none" id="resendLink">
                        <i class="bi bi-arrow-repeat me-1"></i>Resend OTP
                    </a>
                    <div class="countdown" id="countdown"></div>
                </div>

                <div class="text-center mt-3">
                    <a href="signup.php" class="text-muted">
                        <i class="bi bi-arrow-left me-1"></i>Back to Sign Up
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/auth.js"></script>
</body>
</html>
