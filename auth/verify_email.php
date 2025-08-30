<?php
session_start();
require_once __DIR__ . '/../include/functions.php';
include '../include/db.php';

$message = '';
$messageType = '';
$showLoginButton = false;

if(isset($_GET['token'])) {
    $token = $_GET['token'];

    $userId = verifyEmailToken($conn, $token);

    if($userId) {
        // Email verified successfully
        $message = "Email verified successfully! You can now log in to your account.";
        $messageType = "success";
        $showLoginButton = true;

        // Send welcome email
        $stmt = $conn->prepare("SELECT username, email FROM users WHERE id = :user_id");
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if($user) {
            $welcomeSubject = "Email Verified - POS System";
            $welcomeMessage = "Email Verified Successfully!

Hi {$user['username']},

Your email address has been successfully verified. You can now log in to your POS System account.

If you have any questions, please contact our support team.

Best regards,
POS System";

            sendEmail($user['email'], $welcomeSubject, $welcomeMessage);
        }
    } else {
        $message = "Invalid or expired verification link. Please try registering again or contact support.";
        $messageType = "danger";
    }
} else {
    $message = "No verification token provided.";
    $messageType = "warning";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification - POS System</title>
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
                <h3><i class="bi bi-envelope-check me-3"></i>Email Verification</h3>
                <p>Verifying your email address</p>
            </div>
            <div class="auth-body verify-body">
                <div class="icon-container">
                    <?php if($messageType === 'success'): ?>
                        <i class="bi bi-check-circle-fill success-icon"></i>
                    <?php elseif($messageType === 'danger'): ?>
                        <i class="bi bi-x-circle-fill error-icon"></i>
                    <?php else: ?>
                        <i class="bi bi-exclamation-triangle-fill warning-icon"></i>
                    <?php endif; ?>
                </div>

                <div class="alert alert-<?php echo $messageType; ?>" role="alert">
                    <i class="bi bi-info-circle me-2"></i><?php echo $message; ?>
                </div>

                <?php if($showLoginButton): ?>
                    <div>
                        <a href="login.php" class="btn btn-primary auth-btn btn-login">
                            <i class="bi bi-box-arrow-in-right me-2"></i>Go to Login
                        </a>
                    </div>
                <?php endif; ?>

                <div class="mt-4">
                    <small class="text-muted">
                        <i class="bi bi-info-circle me-1"></i>
                        Need help? <a href="mailto:support@possystem.com" class="text-decoration-none">Contact Support</a>
                    </small>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/auth.js"></script>

    <?php if($messageType === 'success' && $showLoginButton): ?>
    <script>
        // Auto-redirect to login after 5 seconds
        setTimeout(function() {
            window.location.href = 'login.php';
        }, 5000);

        // Show countdown
        let countdown = 5;
        const countdownElement = document.createElement('div');
        countdownElement.className = 'mt-3 text-muted';
        countdownElement.innerHTML = '<small>Redirecting to login in <span id="countdown">5</span> seconds...</small>';

        document.querySelector('.auth-body').appendChild(countdownElement);

        const countdownInterval = setInterval(function() {
            countdown--;
            document.getElementById('countdown').textContent = countdown;

            if (countdown <= 0) {
                clearInterval(countdownInterval);
            }
        }, 1000);
    </script>
    <?php endif; ?>
</body>
</html>
