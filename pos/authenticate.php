<?php
session_start();
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';

// Check if user is logged in (basic login)
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Check if user is already authenticated for POS
if (isset($_SESSION['pos_authenticated']) && $_SESSION['pos_authenticated'] === true) {
    // Redirect to till selection or POS based on till status
    if (isset($_SESSION['selected_till_id'])) {
        header("Location: sale.php");
    } else {
        header("Location: sale.php");
    }
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$user_role = $_SESSION['role_name'] ?? 'User';

// Get system settings for company details
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Generate CSRF token
if (!isset($_SESSION['pos_csrf_token'])) {
    $_SESSION['pos_csrf_token'] = bin2hex(random_bytes(32));
}

// Rate limiting for POS authentication attempts
$max_attempts = 3;
$time_window = 300; // 5 minutes
$ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

// Check rate limiting
$stmt = $conn->prepare("SELECT COUNT(*) as attempts FROM login_attempts WHERE ip_address = :ip AND created_at > DATE_SUB(NOW(), INTERVAL :window SECOND) AND attempt_type = 'pos_auth'");
$stmt->bindParam(':ip', $ip_address);
$stmt->bindParam(':window', $time_window);
$stmt->execute();
$attempts = $stmt->fetch(PDO::FETCH_ASSOC)['attempts'];

if ($attempts >= $max_attempts) {
    $error = "Too many authentication attempts. Please try again in 5 minutes.";
    $show_form = false;
} else {
    $show_form = true;
}

$message = '';
$messageType = '';

if ($_SERVER["REQUEST_METHOD"] == "POST" && $show_form) {
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['pos_csrf_token']) ||
        !hash_equals($_SESSION['pos_csrf_token'], $_POST['csrf_token'])) {
        $message = "Security validation failed. Please try again.";
        $messageType = "danger";
    } else {
        $identifier = trim($_POST['identifier'] ?? '');
        $password = trim($_POST['password'] ?? '');
        
        if (empty($identifier)) {
            $message = "Please enter your User ID, Username, or Employee ID";
            $messageType = "danger";
        } elseif (empty($password)) {
            $message = "Please enter your password";
            $messageType = "danger";
        } else {
            // Determine if identifier is user_id or employee_id (no username, no email)
            $is_user_id = is_numeric($identifier) && strlen($identifier) >= 3 && strlen($identifier) <= 6; // 3-6 digit User ID
            $attempt_type = 'employee_id'; // default
            
            // Prepare query based on identifier type
            if ($is_user_id) {
                $attempt_type = 'user_id';
                $stmt = $conn->prepare("
                    SELECT id, username, password, role_id, first_name, last_name
                    FROM users 
                    WHERE user_id = :identifier
                ");
            } else {
                // Check if it's an employee_id
                $stmt = $conn->prepare("
                    SELECT id, username, password, role_id, first_name, last_name
                    FROM users 
                    WHERE employee_id = :identifier
                ");
                $attempt_type = 'employee_id';
            }
            
            $stmt->bindParam(':identifier', $identifier);
            $stmt->execute();
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && password_verify($password, $user['password'])) {
                // Verify this is the same user who is logged in (additional security)
                if ($user['id'] != $user_id) {
                    $message = "Authentication failed. The credentials don't match your current session.";
                    $messageType = "danger";
                    
                    // Log failed attempt
                    if (function_exists('logLoginAttempt')) {
                        logLoginAttempt($conn, $identifier, $ip_address, $_SERVER['HTTP_USER_AGENT'] ?? '', $attempt_type, false);
                    }
                } else {
                    // Successful POS authentication
                    $_SESSION['pos_authenticated'] = true;
                    $_SESSION['pos_auth_time'] = time();
                    
                    // Log successful authentication
                    if (function_exists('logLoginAttempt')) {
                        logLoginAttempt($conn, $identifier, $ip_address, $_SERVER['HTTP_USER_AGENT'] ?? '', $attempt_type, true);
                    }
                    
                    // Set success message for display
                    $_SESSION['pos_auth_success'] = "Successfully authenticated for POS as " . htmlspecialchars($user['first_name'] . ' ' . $user['last_name']);
                    
                    // Redirect to till selection
                    header("Location: sale.php");
                    exit();
                }
            } else {
                $message = "Invalid credentials. Please check your User ID or Employee ID and password.";
                $messageType = "danger";
                
                // Log failed attempt
                if (function_exists('logLoginAttempt')) {
                    logLoginAttempt($conn, $identifier, $ip_address, $_SERVER['HTTP_USER_AGENT'] ?? '', $attempt_type, false);
                }
            }
        }
    }
}

// Get user's full name for display
$stmt = $conn->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user_info = $stmt->fetch(PDO::FETCH_ASSOC);
$full_name = trim(($user_info['first_name'] ?? '') . ' ' . ($user_info['last_name'] ?? ''));
if (empty($full_name)) {
    $full_name = $username;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS Authentication - Point of Sale System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.8) 0%, rgba(118, 75, 162, 0.8) 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            backdrop-filter: blur(5px);
        }
        
        .auth-container {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            overflow: hidden;
            max-width: 400px;
            width: 100%;
            margin: 20px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .auth-header {
            background: rgba(248, 249, 250, 0.9);
            color: #333;
            padding: 1.5rem;
            text-align: center;
            border-bottom: 1px solid rgba(233, 236, 239, 0.5);
        }
        
        .company-branding {
            text-align: center;
            padding-bottom: 1rem;
            border-bottom: 2px solid rgba(0, 123, 255, 0.2);
            margin-bottom: 1rem;
        }
        
        .company-name {
            font-size: 1.8rem;
            font-weight: 700;
            color: #007bff;
            margin: 0;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
        }
        
        .company-tagline {
            font-size: 0.9rem;
            color: #6c757d;
            margin: 0.25rem 0 0 0;
            font-weight: 500;
        }
        
        .company-address,
        .company-phone {
            font-size: 0.8rem;
            color: #6c757d;
            margin: 0.15rem 0 0 0;
            font-weight: 400;
        }
        
        .company-address i,
        .company-phone i {
            margin-right: 0.25rem;
            color: #007bff;
        }
        
        .auth-header h2 {
            margin: 0;
            font-weight: 600;
            font-size: 1.5rem;
            color: #333;
        }
        
        #currentDateTime {
            background: rgba(0, 0, 0, 0.05);
            padding: 0.5rem;
            border-radius: 6px;
            font-weight: 500;
            border: 1px solid rgba(0, 0, 0, 0.1);
        }
        
        #currentTime {
            color: #007bff;
            font-weight: 600;
        }
        
        #currentDate {
            color: #6c757d;
        }
        
        .auth-body {
            padding: 2rem;
        }
        
        .auth-footer {
            background: rgba(248, 249, 250, 0.8);
            padding: 1rem;
            border-top: 1px solid rgba(233, 236, 239, 0.5);
            border-radius: 0 0 10px 10px;
        }
        
        
        .form-floating {
            margin-bottom: 1.5rem;
        }
        
        .form-control {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 0.75rem 1rem;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        
        .btn-auth {
            background: #ffc107;
            border: 1px solid #ffc107;
            border-radius: 6px;
            padding: 0.75rem 1.5rem;
            font-weight: 500;
            font-size: 1rem;
            color: #000;
            transition: all 0.3s ease;
        }
        
        .btn-auth:hover {
            background: #e0a800;
            border-color: #d39e00;
            color: #000;
        }
        
        .btn-auth:disabled {
            opacity: 0.6;
            transform: none;
            box-shadow: none;
        }
        
        
        .alert {
            border-radius: 10px;
            border: none;
            margin-bottom: 1.5rem;
        }
        
        
        .loading {
            display: none;
        }
        
        .loading.show {
            display: inline-block;
        }
        
        @media (max-width: 480px) {
            .auth-container {
                margin: 10px;
                border-radius: 15px;
            }
            
            .auth-header, .auth-body {
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="auth-header">
            <div class="company-branding mb-3">
                <h1 class="company-name"><?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></h1>
                <p class="company-tagline">Secure POS Authentication</p>
                <?php if (!empty($settings['company_address'])): ?>
                <p class="company-address">
                    <i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($settings['company_address']); ?>
                </p>
                <?php endif; ?>
                <?php if (!empty($settings['company_phone'])): ?>
                <p class="company-phone">
                    <i class="bi bi-telephone"></i> <?php echo htmlspecialchars($settings['company_phone']); ?>
                </p>
                <?php endif; ?>
            </div>
            
            <h2>POS Authentication Required</h2>
            <div class="text-muted small mb-3">
                <i class="bi bi-person-check"></i> 
                Logged in as: <strong><?php echo htmlspecialchars($username); ?></strong> 
                (<?php echo htmlspecialchars($user_role); ?>)
            </div>
            <div class="text-muted small mb-2">
                <i class="bi bi-info-circle"></i> 
                Use your User ID or Employee ID to authenticate
            </div>
            <div class="text-muted small" id="currentDateTime">
                <i class="bi bi-clock"></i> 
                <span id="currentTime"></span> - <span id="currentDate"></span>
            </div>
        </div>
        
        <div class="auth-body">
            <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show">
                <i class="bi bi-<?php echo $messageType === 'danger' ? 'exclamation-triangle' : 'info-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <?php if (!$show_form): ?>
            <div class="alert alert-warning">
                <i class="bi bi-clock"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
            <?php else: ?>
            
            <form method="POST" id="authForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['pos_csrf_token']); ?>">
                
                <div class="mb-3">
                    <label for="identifier" class="form-label">User ID or Employee ID</label>
                    <input type="text" 
                           class="form-control" 
                           id="identifier" 
                           name="identifier" 
                           placeholder="Enter your User ID or Employee ID"
                           required
                           autocomplete="username"
                           value="<?php echo isset($_POST['identifier']) ? htmlspecialchars($_POST['identifier']) : ''; ?>">
                    <div class="form-text">You can use your User ID (e.g., USR1) or Employee ID to authenticate.</div>
                </div>
                
                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" 
                           class="form-control" 
                           id="password" 
                           name="password" 
                           placeholder="Enter your password"
                           required
                           autocomplete="current-password">
                </div>
                
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-secondary" onclick="window.location.href='../dashboard/dashboard.php'">
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-warning btn-auth" id="authBtn">
                        <span class="loading spinner-border spinner-border-sm me-2" role="status"></span>
                        <i class="bi bi-shield-check me-2"></i>
                        Authenticate
                    </button>
                </div>
            </form>
            
            <?php endif; ?>
            
        </div>
        
        <!-- Footer with additional info -->
        <div class="auth-footer">
            <div class="row text-center">
                <div class="col-6">
                    <small class="text-muted">
                        <i class="bi bi-shield-check"></i> Secure Session
                    </small>
                </div>
                <div class="col-6">
                    <small class="text-muted">
                        <i class="bi bi-lock"></i> Encrypted
                    </small>
                </div>
            </div>
            <div class="text-center mt-2">
                <small class="text-muted">
                    <i class="bi bi-info-circle"></i> 
                    Session will expire in 8 hours
                </small>
            </div>
            <div class="text-center mt-1">
                <small class="text-muted">
                    <i class="bi bi-gear"></i> 
                    POS System v2.0 | <?php echo date('Y'); ?> &copy; All Rights Reserved
                </small>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('authForm').addEventListener('submit', function(e) {
            const identifier = document.getElementById('identifier').value.trim();
            const password = document.getElementById('password').value.trim();
            const btn = document.getElementById('authBtn');
            const loading = btn.querySelector('.loading');
            
            // Validate inputs
            if (!identifier) {
                e.preventDefault();
                alert('Please enter your User ID or Employee ID.');
                document.getElementById('identifier').focus();
                return;
            }
            
            if (!password) {
                e.preventDefault();
                alert('Please enter your password.');
                document.getElementById('password').focus();
                return;
            }
            
            btn.disabled = true;
            loading.classList.add('show');
            btn.innerHTML = '<span class="loading spinner-border spinner-border-sm me-2 show" role="status"></span>Authenticating...';
        });
        
        // Auto-focus identifier field
        document.getElementById('identifier').focus();
        
        // Update time and date display
        function updateDateTime() {
            const now = new Date();
            
            // Format time (HH:MM:SS)
            const time = now.toLocaleTimeString('en-US', {
                hour12: false,
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
            
            // Format date (Day, DD MMM YYYY)
            const date = now.toLocaleDateString('en-US', {
                weekday: 'short',
                day: 'numeric',
                month: 'short',
                year: 'numeric'
            });
            
            document.getElementById('currentTime').textContent = time;
            document.getElementById('currentDate').textContent = date;
        }
        
        // Update immediately and then every second
        updateDateTime();
        setInterval(updateDateTime, 1000);
        
        // Clear any existing error messages after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                if (alert.classList.contains('alert-danger')) {
                    alert.remove();
                }
            });
        }, 5000);
    </script>
</body>
</html>
