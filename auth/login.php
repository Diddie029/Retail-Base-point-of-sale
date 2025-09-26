<!-- File: login.php (Updated) -->
<?php
session_start();
// require_once __DIR__ . '/../includes/bootstrap.php';
// pos_guard_redirect_if_not_installed();

require_once '../include/db.php';
require_once '../include/functions.php';

if(isset($_SESSION['user_id'])) {
    // Get user's role redirect URL
    $user_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("
        SELECT r.redirect_url
        FROM users u
        LEFT JOIN roles r ON u.role_id = r.id
        WHERE u.id = :user_id
    ");
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $user_data = $stmt->fetch(PDO::FETCH_ASSOC);

    $redirect_url = $user_data['redirect_url'] ?? '../dashboard/dashboard.php';

    // Ensure the redirect URL is safe
    if (empty($redirect_url) || !preg_match('/^[a-zA-Z0-9\/\.\-_]+$/', $redirect_url)) {
        $redirect_url = '../dashboard/dashboard.php';
    }

    header("Location: " . $redirect_url);
    exit();
}

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Rate limiting for login attempts
$max_attempts = 5;
$time_window = 900; // 15 minutes
$ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

// Check rate limiting
$stmt = $conn->prepare("SELECT COUNT(*) as attempts FROM login_attempts WHERE ip_address = :ip AND created_at > DATE_SUB(NOW(), INTERVAL :window SECOND)");
$stmt->bindParam(':ip', $ip_address);
$stmt->bindParam(':window', $time_window);
$stmt->execute();
$attempts = $stmt->fetch(PDO::FETCH_ASSOC)['attempts'];

if ($attempts >= $max_attempts) {
    $error = "Too many login attempts. Please try again later.";
    $show_form = false;
} else {
    $show_form = true;
}

$message = '';
$messageType = '';

// Check for authentication message (e.g., from till closure)
if (isset($_SESSION['auth_message'])) {
    $message = $_SESSION['auth_message'];
    $messageType = 'warning'; // Use warning style for till closure message
    unset($_SESSION['auth_message']); // Clear the message after displaying
}

if($_SERVER["REQUEST_METHOD"] == "POST" && $show_form) {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $message = "Security validation failed. Please try again.";
        $messageType = "danger";
    } else {
        // Sanitize and validate inputs
        $identifier = trim($_POST['identifier'] ?? '');
        $password = trim($_POST['password'] ?? '');

        // Enhanced input validation
        if(empty($identifier)) {
            $message = "Please enter your User ID, username, or email address";
            $messageType = "danger";
        } elseif(strlen($identifier) > 100) {
            $message = "Identifier is too long";
            $messageType = "danger";
        } elseif(empty($password)) {
            $message = "Please enter your password";
            $messageType = "danger";
        } elseif(strlen($password) > 255) {
            $message = "Password is too long";
            $messageType = "danger";
        } else {
            // Determine if identifier is email, username, or user_id
            $is_email = filter_var($identifier, FILTER_VALIDATE_EMAIL);
            $is_user_id = is_numeric($identifier) && strlen($identifier) >= 3 && strlen($identifier) <= 6; // 3-6 digit User ID
            $attempt_type = 'username'; // default
            
            // Prepare query based on identifier type
            if($is_email) {
                $attempt_type = 'email';
                $stmt = $conn->prepare("
                    SELECT u.*, r.name as role_name, r.redirect_url
                    FROM users u
                    LEFT JOIN roles r ON u.role_id = r.id
                    WHERE u.email = :identifier
                ");
            } elseif($is_user_id) {
                $attempt_type = 'user_id';
                $stmt = $conn->prepare("
                    SELECT u.*, r.name as role_name, r.redirect_url
                    FROM users u
                    LEFT JOIN roles r ON u.role_id = r.id
                    WHERE u.user_id = :identifier
                ");
            } else {
                $attempt_type = 'username';
                $stmt = $conn->prepare("
                    SELECT u.*, r.name as role_name, r.redirect_url
                    FROM users u
                    LEFT JOIN roles r ON u.role_id = r.id
                    WHERE u.username = :identifier
                ");
            }

            $stmt->bindParam(':identifier', $identifier);
            $stmt->execute();

            if($stmt->rowCount() > 0) {
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                // Check if account is locked
                if($user['account_locked'] == 1) {
                    if($user['locked_until'] && strtotime($user['locked_until']) > time()) {
                        $message = "Your account is temporarily locked due to multiple failed login attempts. Please try again later.";
                        $messageType = "warning";
                    } else {
                        // Unlock account if lockout period has expired
                        $stmt = $conn->prepare("UPDATE users SET account_locked = 0, locked_until = NULL, failed_login_attempts = 0 WHERE id = :user_id");
                        $stmt->bindParam(':user_id', $user['id']);
                        $stmt->execute();
                        $user['account_locked'] = 0;
                    }
                }

                if($user['account_locked'] == 0) {
                    // Check password using proper verification
                    if(password_verify($password, $user['password'])) {
                        // Regenerate session ID to prevent session fixation
                        session_regenerate_id(true);

                        // Successful login
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['role_id'] = $user['role_id'];
                        $_SESSION['role_name'] = $user['role_name'];
                        $_SESSION['login_success'] = true;
                        $_SESSION['last_activity'] = time();
                        $_SESSION['login_time'] = time();
                        $_SESSION['ip_address'] = $ip_address;
                        $_SESSION['user_agent'] = $user_agent;

                        // Update user login information
                        $stmt = $conn->prepare("
                            UPDATE users SET
                                failed_login_attempts = 0,
                                last_login = NOW(),
                                login_count = login_count + 1,
                                last_failed_login = NULL
                            WHERE id = :user_id
                        ");
                        $stmt->bindParam(':user_id', $user['id']);
                        $stmt->execute();

                        // Log successful login attempt
                        logLoginAttempt($conn, $identifier, $ip_address, $user_agent, $attempt_type, true);

                        // Check for redirect after authentication (e.g., after till closure)
                        if (isset($_SESSION['redirect_after_auth'])) {
                            $redirect_url = $_SESSION['redirect_after_auth'];
                            unset($_SESSION['redirect_after_auth']);
                        } else {
                            // Determine redirect URL based on role
                            $redirect_url = $user['redirect_url'] ?? '../dashboard/dashboard.php';
                        }

                        // Ensure the redirect URL is safe and exists
                        if (empty($redirect_url) || !preg_match('/^[a-zA-Z0-9\/\.\-_]+$/', $redirect_url)) {
                            $redirect_url = '../dashboard/dashboard.php';
                        }

                        // Redirect to role-specific page
                        header("Location: " . $redirect_url);
                        exit();
                    } else {
                        // Failed login - password incorrect
                        $message = "Invalid username/email or password";
                        $messageType = "danger";

                        // Increment failed attempts
                        $new_attempts = $user['failed_login_attempts'] + 1;
                        $lock_account = false;

                        if($new_attempts >= 5) {
                            // Lock account for 30 minutes
                            $locked_until = date('Y-m-d H:i:s', strtotime('+30 minutes'));
                            $lock_account = true;
                            $message = "Account locked due to multiple failed attempts. Try again in 30 minutes.";
                        }

                        $stmt = $conn->prepare("
                            UPDATE users SET
                                failed_login_attempts = :attempts,
                                last_failed_login = NOW()
                                " . ($lock_account ? ", account_locked = 1, locked_until = :locked_until" : "") . "
                            WHERE id = :user_id
                        ");
                        $stmt->bindParam(':attempts', $new_attempts);
                        $stmt->bindParam(':user_id', $user['id']);
                        if($lock_account) {
                            $stmt->bindParam(':locked_until', $locked_until);
                        }
                        $stmt->execute();
                    }
                }
            } else {
                // User not found
                $message = "Invalid username/email or password";
                $messageType = "danger";
            }

            // Log login attempt (failed or successful)
            logLoginAttempt($conn, $identifier, $ip_address, $user_agent, $attempt_type, false);
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - POS System</title>
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
    
    <div class="floating-icons">
        <i class="bi bi-shop floating-icon"></i>
        <i class="bi bi-cart-plus floating-icon"></i>
        <i class="bi bi-receipt floating-icon"></i>
    </div>
    
    <div class="auth-container login-container">
        <div class="auth-card login-card">
            <div class="auth-header login-header">
                <h3><i class="bi bi-shop me-3"></i>POS System</h3>
                <p>Welcome back! Please sign in to your account</p>
            </div>
            <div class="auth-body login-body">
                <?php if(!empty($message)): ?>
                    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                        <i class="bi bi-<?php echo $messageType === 'success' ? 'check-circle' : ($messageType === 'warning' ? 'exclamation-triangle' : 'exclamation-triangle'); ?> me-2"></i>
                        <?php echo htmlspecialchars($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if(isset($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <?php if(isset($GLOBALS['db_connected']) && !$GLOBALS['db_connected']): ?>
                    <div class="alert alert-warning alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        Database connection failed. Please check your configuration.
                        <br><small class="text-muted">Error: <?php echo isset($GLOBALS['db_error']) ? $GLOBALS['db_error'] : 'Unknown error'; ?></small>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="" id="loginForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <div class="mb-4">
                        <label for="identifier" class="form-label">
                            <i class="bi bi-person me-2"></i>User ID, Username, or Email
                        </label>
                        <input type="text" class="form-control" id="identifier" name="identifier" required
                               maxlength="100" autocomplete="username"
                               value="<?php echo isset($_POST['identifier']) ? htmlspecialchars($_POST['identifier']) : ''; ?>"
                               placeholder="Enter your User ID, username, or email address">
                        <div class="form-text">You can login with your User ID, username, or email address</div>
                    </div>
                    <div class="mb-4">
                        <label for="password" class="form-label">
                            <i class="bi bi-lock me-2"></i>Password
                        </label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="password" name="password" required
                                   autocomplete="current-password" maxlength="255">
                            <span class="password-toggle" onclick="togglePassword()">
                                <i class="bi bi-eye" id="toggleIcon"></i>
                            </span>
                        </div>
                    </div>
                    <div class="d-grid mb-3">
                        <button type="submit" class="btn btn-primary auth-btn btn-login" <?php echo !$show_form ? 'disabled' : ''; ?>>
                            <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
                        </button>
                        <?php if (!$show_form): ?>
                            <div class="text-center mt-2">
                                <small class="text-muted">
                                    <i class="bi bi-clock me-1"></i>
                                    Too many login attempts. Please wait before trying again.
                                </small>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="text-center">
                        <div class="mb-2">
                            <a href="forgot_password.php" class="text-decoration-none">
                                <small class="text-muted">
                                    <i class="bi bi-key me-1"></i>Forgot Password?
                                </small>
                            </a>
                        </div>
                        <small class="text-muted">
                            <i class="bi bi-info-circle me-1"></i>
                            Don't have an account?
                            <a href="signup.php" class="text-decoration-none fw-bold">
                                <i class="bi bi-person-plus me-1"></i>Sign Up
                            </a>
                        </small>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/auth.js"></script>
    <script>
    // Login method detection and visual feedback
    document.addEventListener('DOMContentLoaded', function() {
        const identifierInput = document.getElementById('identifier');
        const formText = document.querySelector('.form-text');
        
        function updateLoginMethodFeedback() {
            const value = identifierInput.value.trim();
            
            if (value === '') {
                formText.innerHTML = 'You can login with your User ID, username, or email address';
                formText.className = 'form-text text-muted';
                return;
            }
            
            // Check if it's an email
            if (value.includes('@') && value.includes('.')) {
                formText.innerHTML = '<i class="bi bi-envelope me-1"></i>Logging in with email address';
                formText.className = 'form-text text-info';
            }
            // Check if it's a User ID (3-6 digits)
            else if (/^\d{3,6}$/.test(value)) {
                formText.innerHTML = '<i class="bi bi-person-badge me-1"></i>Logging in with User ID';
                formText.className = 'form-text text-primary';
            }
            // Check if it's a username (contains letters/numbers, no @)
            else if (/^[a-zA-Z0-9_]+$/.test(value)) {
                formText.innerHTML = '<i class="bi bi-person me-1"></i>Logging in with username';
                formText.className = 'form-text text-success';
            }
            // Invalid format
            else {
                formText.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i>Please enter a valid User ID, username, or email';
                formText.className = 'form-text text-warning';
            }
        }
        
        // Add event listener for real-time feedback
        identifierInput.addEventListener('input', updateLoginMethodFeedback);
        identifierInput.addEventListener('blur', updateLoginMethodFeedback);
        
        // Initial check if there's already a value
        updateLoginMethodFeedback();
    });
    </script>
</body>
</html>