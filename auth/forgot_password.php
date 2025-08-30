<?php
session_start();
require_once __DIR__ . '/../include/functions.php';
include '../include/db.php';

if(isset($_SESSION['user_id'])) {
    header("Location: ../dashboard/dashboard.php");
    exit();
}

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Rate limiting for password reset attempts
$max_attempts = 3;
$time_window = 3600; // 1 hour
$ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

$message = '';
$messageType = '';

if($_SERVER["REQUEST_METHOD"] == "POST") {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $message = "Security validation failed. Please try again.";
        $messageType = "danger";
    }

    $email = trim($_POST['email']);

    // Check rate limiting
    $stmt = $conn->prepare("SELECT COUNT(*) as attempts FROM password_reset_attempts WHERE ip_address = :ip AND created_at > DATE_SUB(NOW(), INTERVAL :window SECOND)");
    $stmt->bindParam(':ip', $ip_address);
    $stmt->bindParam(':window', $time_window);
    $stmt->execute();
    $attempts = $stmt->fetch(PDO::FETCH_ASSOC)['attempts'];

    if ($attempts >= $max_attempts) {
        $message = "Too many password reset attempts. Please try again later.";
        $messageType = "danger";
    } elseif(empty($email)) {
        $message = "Please enter your email address";
        $messageType = "danger";
    } elseif(!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Please enter a valid email address";
        $messageType = "danger";
    } elseif(strlen($email) > 100) {
        $message = "Email address is too long";
        $messageType = "danger";
    } else {
        // Record password reset attempt
        $stmt = $conn->prepare("INSERT INTO password_reset_attempts (email, ip_address) VALUES (:email, :ip)");
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':ip', $ip_address);
        $stmt->execute();

        // Check if email exists
        if(emailExists($conn, $email)) {
            // Generate password reset token
            $resetToken = createPasswordResetToken($conn, $email);

            if($resetToken) {
                // Get user info
                $user = getUserByEmail($conn, $email);

                // Send password reset email
                $resetLink = "http://" . $_SERVER['HTTP_HOST'] . "/auth/reset_password.php?token=" . $resetToken;
                $subject = "Password Reset - POS System";
                $message = "Password Reset Request

Hi {$user['username']},

You have requested to reset your password for your POS System account.

Click the link below to reset your password:
{$resetLink}

This link will expire in 1 hour.

If you didn't request this password reset, please ignore this email.

For security reasons, we recommend changing your password regularly.

Best regards,
POS System";

                if(sendEmail($email, $subject, $message)) {
                    $message = "Password reset instructions have been sent to your email address.";
                    $messageType = "success";
                } else {
                    $message = "Failed to send reset email. Please try again later.";
                    $messageType = "danger";
                }
            } else {
                $message = "Failed to generate reset token. Please try again.";
                $messageType = "danger";
            }
        } else {
            // Don't reveal if email exists or not for security
            $message = "If an account with that email exists, we've sent password reset instructions.";
            $messageType = "info";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/auth.css">
    <style>
        /* Page-specific overrides if needed */
    </style>
</head>
<body>
    <!-- Database Connection Status -->
    <?php if(isset($GLOBALS['db_connected']) && $GLOBALS['db_connected']): ?>
        <div class="connection-status status-connected">
            <span class="status-dot dot-connected"></span>
            <i class="bi bi-database-check me-1"></i>Connected
        </div>
    <?php else: ?>
        <div class="connection-status status-disconnected">
            <span class="status-dot dot-disconnected"></span>
            <i class="bi bi-database-x me-1"></i>Connection Failed
        </div>
    <?php endif; ?>

    <div class="auth-container forgot-container">
        <div class="auth-card forgot-card">
            <div class="auth-header forgot-header">
                <h3><i class="bi bi-key me-3"></i>Forgot Password</h3>
                <p>Reset your account password</p>
            </div>
            <div class="auth-body forgot-body">
                <div class="info-text">
                    <i class="bi bi-info-circle me-2"></i>
                    <strong>How it works:</strong> Enter your email address and we'll send you a link to reset your password.
                </div>

                <?php if(!empty($message)): ?>
                    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                        <i class="bi bi-info-circle me-2"></i><?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" id="forgotForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <div class="mb-4">
                        <label for="email" class="form-label">
                            <i class="bi bi-envelope me-2"></i>Email Address
                        </label>
                        <input type="email" class="form-control" id="email" name="email" required
                               maxlength="100"
                               placeholder="Enter your registered email"
                               value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                        <div class="form-text">
                            We'll send a password reset link to this email address.
                        </div>
                    </div>

                    <div class="d-grid mb-3">
                        <button type="submit" class="btn btn-danger auth-btn btn-reset">
                            <i class="bi bi-send me-2"></i>Send Reset Link
                        </button>
                    </div>
                </form>

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
