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

// Rate limiting for signup attempts
$max_attempts = 5;
$time_window = 3600; // 1 hour
$ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

// Check rate limiting
$stmt = $conn->prepare("SELECT COUNT(*) as attempts FROM signup_attempts WHERE ip_address = :ip AND created_at > DATE_SUB(NOW(), INTERVAL :window SECOND)");
$stmt->bindParam(':ip', $ip_address);
$stmt->bindParam(':window', $time_window);
$stmt->execute();
$attempts = $stmt->fetch(PDO::FETCH_ASSOC)['attempts'];

if ($attempts >= $max_attempts) {
    $error = "Too many signup attempts. Please try again later.";
    $show_form = false;
} else {
    $show_form = true;
}

$message = '';
$messageType = '';

if($_SERVER["REQUEST_METHOD"] == "POST") {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $errors[] = "Security validation failed. Please try again.";
    }

    // Record signup attempt
    $stmt = $conn->prepare("INSERT INTO signup_attempts (ip_address) VALUES (:ip)");
    $stmt->bindParam(':ip', $ip_address);
    $stmt->execute();

    // Check if rate limit exceeded after recording attempt
    if ($attempts >= $max_attempts) {
        $errors[] = "Too many signup attempts. Please try again later.";
    }

    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    // Enhanced input validation
    $errors = [];

    if(empty($username)) {
        $errors[] = "Username is required";
    } elseif(strlen($username) < 3) {
        $errors[] = "Username must be at least 3 characters long";
    } elseif(strlen($username) > 50) {
        $errors[] = "Username must be less than 50 characters";
    } elseif(!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        $errors[] = "Username can only contain letters, numbers, and underscores";
    }

    if(empty($email)) {
        $errors[] = "Email is required";
    } elseif(!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address";
    } elseif(strlen($email) > 100) {
        $errors[] = "Email address is too long";
    } elseif(emailExists($conn, $email)) {
        $errors[] = "Email is already registered";
    }

    if(empty($password)) {
        $errors[] = "Password is required";
    } elseif(strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters long";
    } elseif(!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]/', $password)) {
        $errors[] = "Password must contain at least one uppercase letter, one lowercase letter, one number, and one special character";
    }

    if($password !== $confirm_password) {
        $errors[] = "Passwords do not match";
    }

    if(empty($errors)) {
        try {
            // Hash password
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            // Insert user
            $stmt = $conn->prepare("INSERT INTO users (username, email, password, role_id) VALUES (:username, :email, :password, 2)");
            $stmt->bindParam(':username', $username);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':password', $hashedPassword);
            $stmt->execute();

            $userId = $conn->lastInsertId();

            // Generate verification token and OTP
            $verificationToken = createVerificationToken($conn, $userId);
            $otpCode = createOTP($conn, $userId);

            // Send verification email
            $verificationLink = "http://" . $_SERVER['HTTP_HOST'] . "/auth/verify_email.php?token=" . $verificationToken;
            $subject = "Verify Your Email - POS System";
            $message = "Welcome to POS System!

Thank you for registering. Please verify your email address to complete your registration.

Your OTP Code: {$otpCode}
This OTP will expire in 10 minutes.

Or verify your email by clicking the link below:
{$verificationLink}

If you didn't create an account, please ignore this email.

Best regards,
POS System";

            if(sendEmail($email, $subject, $message)) {
                $_SESSION['temp_user_id'] = $userId;
                $_SESSION['temp_email'] = $email;
                header("Location: verify_otp.php");
                exit();
            } else {
                $message = "Account created but verification email could not be sent. Please contact support.";
                $messageType = "warning";
            }

        } catch(PDOException $e) {
            $message = "Registration failed: " . $e->getMessage();
            $messageType = "danger";
        }
    } else {
        $message = implode("<br>", $errors);
        $messageType = "danger";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up - POS System</title>
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

    <div class="auth-container signup-container">
        <div class="signup-layout">
            <!-- Main Signup Form -->
            <div class="signup-main">
                <div class="auth-card signup-card">
                    <div class="auth-header signup-header">
                        <h3><i class="bi bi-person-plus me-3"></i>Sign Up</h3>
                        <p>Create your POS System account</p>
                    </div>
                    <div class="auth-body signup-body">
                        <?php if(!empty($message)): ?>
                            <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                                <i class="bi bi-info-circle me-2"></i><?php echo $message; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="" id="signupForm">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                            <!-- Username Field with Enhanced Icon -->
                            <div class="mb-3">
                                <label for="username" class="form-label">
                                    <span class="input-icon">
                                        <i class="bi bi-person-circle"></i>
                                    </span>
                                    Username<span class="text-danger">*</span>
                                </label>
                                <div class="input-with-icon">
                                    <input type="text" class="form-control" id="username" name="username" required
                                           maxlength="50" pattern="[a-zA-Z0-9_]+"
                                           value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                                    <i class="bi bi-person input-field-icon"></i>
                                </div>
                                <div class="form-text">3-50 characters, letters, numbers, and underscores only</div>
                            </div>

                            <!-- Email Field with Enhanced Icon -->
                            <div class="mb-3">
                                <label for="email" class="form-label">
                                    <span class="input-icon">
                                        <i class="bi bi-envelope-at"></i>
                                    </span>
                                    Email Address<span class="text-danger">*</span>
                                </label>
                                <div class="input-with-icon">
                                    <input type="email" class="form-control" id="email" name="email" required
                                           value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                                    <i class="bi bi-envelope input-field-icon"></i>
                                </div>
                            </div>

                            <!-- Password Field with Enhanced Icon -->
                            <div class="mb-3">
                                <label for="password" class="form-label">
                                    <span class="input-icon">
                                        <i class="bi bi-shield-lock-fill"></i>
                                    </span>
                                    Password<span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <div class="input-with-icon">
                                        <input type="password" class="form-control" id="password" name="password" required
                                               minlength="8" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]+">
                                        <i class="bi bi-lock input-field-icon"></i>
                                    </div>
                                    <button class="btn btn-outline-info" type="button" id="generatePassword" title="Generate Strong Password">
                                        <i class="bi bi-shield-lock"></i>
                                    </button>
                                    <button class="btn btn-outline-secondary password-toggle" type="button" id="passwordToggle">
                                        <i class="bi bi-eye" id="passwordIcon"></i>
                                    </button>
                                </div>
                                <div class="progress mt-2" style="height: 6px;">
                                    <div class="progress-bar" id="passwordStrengthBar" role="progressbar" style="width: 0%"></div>
                                </div>
                                <div id="passwordStrength" class="password-strength mt-2"></div>
                                <div class="form-text">Minimum 8 characters with uppercase, lowercase, number, and special character</div>
                            </div>

                            <!-- Confirm Password Field with Enhanced Icon -->
                            <div class="mb-4">
                                <label for="confirm_password" class="form-label">
                                    <span class="input-icon">
                                        <i class="bi bi-shield-check"></i>
                                    </span>
                                    Confirm Password<span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <div class="input-with-icon">
                                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                        <i class="bi bi-lock-fill input-field-icon"></i>
                                    </div>
                                    <button class="btn btn-outline-secondary password-toggle" type="button" id="confirmPasswordToggle">
                                        <i class="bi bi-eye" id="confirmPasswordIcon"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="d-grid mb-3">
                                <button type="submit" class="btn btn-success auth-btn btn-signup">
                                    <i class="bi bi-person-plus me-2"></i>Create Account
                                </button>
                            </div>
                        </form>

                        <div class="login-link">
                            <p class="mb-0">Already have an account?
                                <a href="login.php" class="text-decoration-none">
                                    <i class="bi bi-box-arrow-in-right me-1"></i>Sign In
                                </a>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Security Tips Sidebar -->
            <div class="signup-sidebar">
                <div class="security-tips-card">
                    <h6 class="security-tips-title">
                        <i class="bi bi-shield-check me-2"></i>Security Best Practices
                    </h6>
                    <div class="security-tips-content">
                        <div class="tips-section">
                            <h6 class="tips-subtitle"><i class="bi bi-key me-2"></i>Strong Password Tips</h6>
                            <ul class="tips-list">
                                <li>Use at least 12 characters</li>
                                <li>Mix uppercase and lowercase letters</li>
                                <li>Include numbers and special characters</li>
                                <li>Avoid common words or personal info</li>
                                <li>Use our password generator for maximum security</li>
                            </ul>
                        </div>
                        <div class="tips-section">
                            <h6 class="tips-subtitle"><i class="bi bi-person-lock me-2"></i>Account Security</h6>
                            <ul class="tips-list">
                                <li>Use a unique email address</li>
                                <li>Enable two-factor authentication when available</li>
                                <li>Never share your password</li>
                                <li>Change password regularly</li>
                                <li>Log out from public computers</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/auth.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/auth.js'); ?>"></script>
</body>
</html>
