<?php
session_start();
require_once __DIR__ . '/../../include/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../auth/login.php');
    exit();
}

// Check if user has permission to manage backups
$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("
    SELECT p.name as permission_name
    FROM users u
    JOIN role_permissions rp ON u.role_id = rp.role_id
    JOIN permissions p ON rp.permission_id = p.id
    WHERE u.id = ?
");
$stmt->execute([$user_id]);
$permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (!in_array('manage_settings', $permissions)) {
    header('Location: ../../dashboard/dashboard.php');
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $action = $_POST['action'] ?? '';
    $redirect = $_POST['redirect'] ?? '';
    
    if (empty($password)) {
        $error = 'Password is required';
    } else {
        // Verify password
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user_data && password_verify($password, $user_data['password'])) {
            // Set verification session
            $_SESSION['backup_verified'] = true;
            $_SESSION['backup_verified_time'] = time();
            
            if ($redirect) {
                header("Location: {$redirect}");
            } else {
                header('Location: manage_backups.php');
            }
            exit();
        } else {
            $error = 'Invalid password';
        }
    }
}

// Get the action and redirect from URL
$action = $_GET['action'] ?? 'access';
$redirect = $_GET['redirect'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backup Verification - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            padding: 20px;
        }
        
        .verification-card {
            background: white;
            border-radius: 25px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.15);
            padding: 3rem;
            max-width: 450px;
            width: 100%;
            position: relative;
            overflow: hidden;
        }
        
        .verification-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, #667eea, #764ba2);
        }
        
        .verification-icon {
            font-size: 4rem;
            color: #667eea;
            text-align: center;
            margin-bottom: 1.5rem;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        
        .verification-card h3 {
            color: #1f2937;
            font-weight: 700;
            margin-bottom: 1.5rem;
            text-align: center;
        }
        
        .form-control {
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            padding: 1rem 1.25rem;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: #f9fafb;
        }
        
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
            background: white;
            outline: none;
        }
        
        .form-label {
            font-weight: 600;
            color: #374151;
            margin-bottom: 0.75rem;
        }
        
        .form-text {
            color: #6b7280;
            font-size: 0.875rem;
            margin-top: 0.5rem;
        }
        
        .btn {
            border-radius: 12px;
            padding: 1rem 1.5rem;
            font-weight: 600;
            font-size: 1rem;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.6);
        }
        
        .btn-outline-secondary {
            border: 2px solid #e5e7eb;
            color: #6b7280;
            background: transparent;
        }
        
        .btn-outline-secondary:hover {
            background: #f3f4f6;
            border-color: #d1d5db;
            color: #374151;
        }
        
        .alert {
            border-radius: 12px;
            border: none;
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
            font-weight: 500;
        }
        
        .alert-info {
            background: linear-gradient(135deg, #dbeafe, #bfdbfe);
            color: #1e40af;
            border-left: 4px solid #3b82f6;
        }
        
        .alert-danger {
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            color: #dc2626;
            border-left: 4px solid #ef4444;
        }
        
        .alert-success {
            background: linear-gradient(135deg, #d1fae5, #a7f3d0);
            color: #065f46;
            border-left: 4px solid #10b981;
        }
        
        .d-grid {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        
        .text-muted {
            color: #6b7280 !important;
        }
        
        .mt-4 {
            margin-top: 1.5rem !important;
        }
        
        .text-center {
            text-align: center;
        }
        
        .small {
            font-size: 0.875rem;
        }
        
        /* Responsive design */
        @media (max-width: 480px) {
            .verification-card {
                padding: 2rem 1.5rem;
                margin: 10px;
            }
            
            .verification-icon {
                font-size: 3rem;
            }
            
            .verification-card h3 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="verification-card">
        <div class="verification-icon">
            <i class="bi bi-shield-lock"></i>
        </div>
        
        <h3 class="text-center mb-4">Backup Verification Required</h3>
        
        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="bi bi-check-circle me-2"></i>
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>
        
        <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i>
            <strong>Action:</strong> <?php echo htmlspecialchars(ucfirst($action)); ?> backup
        </div>
        
        <form method="POST">
            <input type="hidden" name="action" value="<?php echo htmlspecialchars($action); ?>">
            <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirect); ?>">
            
            <div class="mb-3">
                <label for="password" class="form-label">Enter your password to continue</label>
                <input type="password" class="form-control" id="password" name="password" 
                       placeholder="Your password" required>
                <div class="form-text">
                    This verification is required for security-sensitive backup operations.
                </div>
            </div>
            
            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-circle me-2"></i>
                    Verify & Continue
                </button>
                
                <a href="../../dashboard/dashboard.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-2"></i>
                    Back to Dashboard
                </a>
            </div>
        </form>
        
        <div class="mt-4 text-center text-muted">
            <small>
                <i class="bi bi-clock me-1"></i>
                Verification expires after 30 minutes
            </small>
        </div>
    </div>
</body>
</html>
