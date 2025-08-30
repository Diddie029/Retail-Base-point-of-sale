<?php
session_start();
require_once __DIR__ . '/../include/functions.php';
include '../include/db.php';

if(isset($_SESSION['user_id'])) {
    header("Location: ../dashboard/dashboard.php");
    exit();
}

if(!isset($_SESSION['temp_user_id']) || !isset($_SESSION['otp_verified'])) {
    header("Location: signup.php");
    exit();
}

$message = '';
$messageType = '';

if($_SERVER["REQUEST_METHOD"] == "POST") {
    $final_otp = trim($_POST['final_otp']);

    if(empty($final_otp)) {
        $message = "Please enter the final OTP code";
        $messageType = "danger";
    } elseif(strlen($final_otp) !== 6 || !is_numeric($final_otp)) {
        $message = "Please enter a valid 6-digit OTP code";
        $messageType = "danger";
    } else {
        // Verify final OTP
        if(verifyOTP($conn, $_SESSION['temp_user_id'], $final_otp)) {
            // Mark email as verified and complete registration
            $stmt = $conn->prepare("UPDATE users SET email_verified = 1 WHERE id = :user_id");
            $stmt->bindParam(':user_id', $_SESSION['temp_user_id']);
            $stmt->execute();

            // Get user data for session
            $stmt = $conn->prepare("SELECT u.*, r.name as role_name FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.id = :user_id");
            $stmt->bindParam(':user_id', $_SESSION['temp_user_id']);
            $stmt->execute();
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // Set session variables
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role_id'] = $user['role_id'];
            $_SESSION['role_name'] = $user['role_name'];
            $_SESSION['login_success'] = true;

            // Clear temporary session variables
            unset($_SESSION['temp_user_id']);
            unset($_SESSION['temp_email']);
            unset($_SESSION['otp_verified']);

            // Send welcome email
            $welcomeSubject = "Welcome to POS System!";
            $welcomeMessage = "Welcome to POS System, {$user['username']}!

Your account has been successfully verified and activated.

You can now:
- Log in to your dashboard
- Manage products and categories
- Process sales transactions
- View reports and analytics

If you have any questions, please contact our support team.

Happy selling!

Best regards,
POS System";

            sendEmail($user['email'], $welcomeSubject, $welcomeMessage);

            header("Location: ../dashboard/dashboard.php");
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
    <title>Complete Registration - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/auth.css">
    <style>
        /* Page-specific overrides if needed */
    </style>
</head>
<body>
    <div class="auth-container complete-container">
        <div class="auth-card complete-card">
            <div class="auth-header complete-header">
                <h3><i class="bi bi-check-circle me-3"></i>Complete Registration</h3>
                <p>Enter your final verification code</p>
            </div>

            <div class="step-indicator">
                <div class="step completed">1</div>
                <div class="step completed">2</div>
                <div class="step active">3</div>
            </div>

            <div class="progress-container">
                <div class="progress">
                    <div class="progress-bar bg-success" role="progressbar" style="width: 100%" aria-valuenow="100" aria-valuemin="0" aria-valuemax="100"></div>
                </div>
            </div>

            <div class="auth-body complete-body">
                <?php if(!empty($message)): ?>
                    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                        <i class="bi bi-info-circle me-2"></i><?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" id="completeForm">
                    <div class="mb-4">
                        <label for="final_otp" class="form-label">
                            <i class="bi bi-key me-2"></i>Final OTP Code
                        </label>
                        <input type="text" class="form-control otp-input" id="final_otp" name="final_otp"
                               maxlength="6" pattern="[0-9]{6}" required
                               placeholder="000000" autocomplete="off">
                        <div class="form-text text-center">
                            Check your email for the final verification code
                        </div>
                    </div>

                    <div class="d-grid mb-3">
                        <button type="submit" class="btn btn-success auth-btn btn-complete">
                            <i class="bi bi-check-circle me-2"></i>Complete Registration
                        </button>
                    </div>
                </form>

                <div class="text-center">
                    <small class="text-muted">
                        <i class="bi bi-info-circle me-1"></i>
                        This is the final step to activate your account
                    </small>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/auth.js"></script>
</body>
</html>
