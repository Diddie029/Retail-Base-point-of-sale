<?php
session_start();
require_once __DIR__ . '/../include/functions.php';
include '../include/db.php';

if(isset($_SESSION['user_id'])) {
    header("Location: ../dashboard/dashboard.php");
    exit();
}

$message = '';
$messageType = '';
$token = '';
$userId = null;
$showForm = false;

if(isset($_GET['token'])) {
    $token = $_GET['token'];
    $userId = verifyResetToken($conn, $token);

    if($userId) {
        $showForm = true;
    } else {
        $message = "Invalid or expired reset link. Please request a new password reset.";
        $messageType = "danger";
    }
} elseif($_SERVER["REQUEST_METHOD"] == "POST") {
    $token = $_POST['token'];
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    $userId = verifyResetToken($conn, $token);

    if(!$userId) {
        $message = "Invalid or expired reset token.";
        $messageType = "danger";
    } elseif(empty($password)) {
        $message = "Please enter a new password";
        $messageType = "danger";
        $showForm = true;
    } elseif(strlen($password) < 6) {
        $message = "Password must be at least 6 characters long";
        $messageType = "danger";
        $showForm = true;
    } elseif($password !== $confirm_password) {
        $message = "Passwords do not match";
        $messageType = "danger";
        $showForm = true;
    } else {
        // Reset password
        if(resetPassword($conn, $userId, $password)) {
            // Get user email for notification
            $stmt = $conn->prepare("SELECT email, username FROM users WHERE id = :user_id");
            $stmt->bindParam(':user_id', $userId);
            $stmt->execute();
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if($user) {
                // Send password changed notification
                $subject = "Password Changed - POS System";
                $emailMessage = "
                <html>
                <head>
                    <title>Password Changed</title>
                </head>
                <body>
                    <h2>Password Successfully Changed</h2>
                    <p>Hi {$user['username']},</p>
                    <p>Your password has been successfully changed for your POS System account.</p>
                    <p>If you didn't make this change, please contact our support team immediately.</p>
                    <p>For security reasons, we recommend:</p>
                    <ul>
                        <li>Using a strong, unique password</li>
                        <li>Enabling two-factor authentication if available</li>
                        <li>Regularly monitoring your account activity</li>
                    </ul>
                    <p>You can now log in with your new password.</p>
                </body>
                </html>
                ";

                sendEmail($user['email'], $subject, $emailMessage);
            }

            $message = "Password reset successfully! You can now log in with your new password.";
            $messageType = "success";
        } else {
            $message = "Failed to reset password. Please try again.";
            $messageType = "danger";
            $showForm = true;
        }
    }
} else {
    $message = "No reset token provided. Please use the link from your email.";
    $messageType = "warning";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/auth.css">
    <style>
        /* Page-specific overrides if needed */
    </style>
</head>
<body>
    <div class="auth-container reset-container">
        <div class="auth-card reset-card">
            <div class="auth-header reset-header">
                <h3><i class="bi bi-shield-check me-3"></i>Reset Password</h3>
                <p>Create a new password for your account</p>
            </div>
            <div class="auth-body reset-body">
                <?php if($messageType === 'success' && !$showForm): ?>
                    <div class="text-center">
                        <i class="bi bi-check-circle-fill success-icon"></i>
                        <div class="alert alert-success" role="alert">
                            <i class="bi bi-info-circle me-2"></i><?php echo $message; ?>
                        </div>
                        <div class="mt-4">
                            <a href="login.php" class="btn btn-success btn-reset">
                                <i class="bi bi-box-arrow-in-right me-2"></i>Go to Login
                            </a>
                        </div>
                    </div>
                <?php elseif(!empty($message)): ?>
                    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                        <i class="bi bi-info-circle me-2"></i><?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>

                    <?php if($messageType === 'danger' && !$showForm): ?>
                        <div class="text-center mt-4">
                            <a href="forgot_password.php" class="btn btn-outline-primary">
                                <i class="bi bi-arrow-left me-2"></i>Request New Reset Link
                            </a>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if($showForm): ?>
                    <form method="POST" action="" id="resetForm">
                        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

                        <div class="mb-3">
                            <label for="password" class="form-label">
                                <i class="bi bi-lock me-2"></i>New Password
                            </label>
                            <input type="password" class="form-control" id="password" name="password" required>
                            <div id="passwordStrength" class="password-strength"></div>
                        </div>

                        <div class="mb-4">
                            <label for="confirm_password" class="form-label">
                                <i class="bi bi-lock-fill me-2"></i>Confirm New Password
                            </label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                        </div>

                        <div class="d-grid mb-3">
                            <button type="submit" class="btn btn-success auth-btn btn-reset">
                                <i class="bi bi-check-circle me-2"></i>Reset Password
                            </button>
                        </div>
                    </form>
                <?php endif; ?>

                <div class="login-link">
                    <p class="mb-0">Remember your password?
                        <a href="login.php" class="text-decoration-none">
                            <i class="bi bi-box-arrow-in-right me-1"></i>Back to Login
                        </a>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/auth.js"></script>
</body>
</html>
