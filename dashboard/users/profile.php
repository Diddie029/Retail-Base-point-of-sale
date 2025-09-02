<?php
session_start();
require_once __DIR__ . '/../../include/db.php';
require_once __DIR__ . '/../../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../auth/login.php");
    exit();
}

$current_user_id = $_SESSION['user_id'];
$role_id = $_SESSION['role_id'] ?? 0;

// Get user permissions
$permissions = [];
if ($role_id) {
    $stmt = $conn->prepare("
        SELECT p.name 
        FROM permissions p 
        JOIN role_permissions rp ON p.id = rp.permission_id 
        WHERE rp.role_id = :role_id
    ");
    $stmt->bindParam(':role_id', $role_id);
    $stmt->execute();
    $permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$success_message = '';
$error_message = '';

// Handle form submission
if ($_POST) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_profile') {
        $first_name = trim($_POST['first_name']);
        $last_name = trim($_POST['last_name']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        $address = trim($_POST['address']);
        $date_of_birth = !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null;

        // Validation
        $errors = [];

        if (empty($first_name)) {
            $errors[] = "First name is required";
        }

        if (empty($email)) {
            $errors[] = "Email is required";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid email format";
        }

        // Check if email is already taken by another user
        if (empty($errors)) {
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = :email AND id != :user_id");
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':user_id', $current_user_id);
            $stmt->execute();
            if ($stmt->rowCount() > 0) {
                $errors[] = "Email address is already in use by another user";
            }
        }

        if (empty($errors)) {
            try {
                $stmt = $conn->prepare("
                    UPDATE users SET 
                        first_name = :first_name,
                        last_name = :last_name,
                        email = :email,
                        phone = :phone,
                        address = :address,
                        date_of_birth = :date_of_birth,
                        updated_at = NOW()
                    WHERE id = :user_id
                ");

                $stmt->bindParam(':first_name', $first_name);
                $stmt->bindParam(':last_name', $last_name);
                $stmt->bindParam(':email', $email);
                $stmt->bindParam(':phone', $phone);
                $stmt->bindParam(':address', $address);
                $stmt->bindParam(':date_of_birth', $date_of_birth);
                $stmt->bindParam(':user_id', $current_user_id);
                
                $stmt->execute();

                // Update session email if changed
                if ($_SESSION['email'] !== $email) {
                    $_SESSION['email'] = $email;
                }

                // Log activity
                $action_log = "Updated profile information";
                $details = json_encode([
                    'fields_updated' => ['first_name', 'last_name', 'email', 'phone', 'address', 'date_of_birth']
                ]);
                $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, created_at) VALUES (:user_id, :action, :details, NOW())");
                $log_stmt->bindParam(':user_id', $current_user_id);
                $log_stmt->bindParam(':action', $action_log);
                $log_stmt->bindParam(':details', $details);
                $log_stmt->execute();

                $success_message = "Profile updated successfully!";
                
            } catch (PDOException $e) {
                $error_message = "Error updating profile: " . $e->getMessage();
            }
        } else {
            $error_message = implode('<br>', $errors);
        }
    } 
    elseif ($action === 'change_password') {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];

        // Validation
        $errors = [];

        if (empty($current_password)) {
            $errors[] = "Current password is required";
        }

        if (empty($new_password)) {
            $errors[] = "New password is required";
        } elseif (strlen($new_password) < 6) {
            $errors[] = "New password must be at least 6 characters long";
        }

        if ($new_password !== $confirm_password) {
            $errors[] = "New passwords do not match";
        }

        // Verify current password
        if (empty($errors)) {
            $stmt = $conn->prepare("SELECT password FROM users WHERE id = :user_id");
            $stmt->bindParam(':user_id', $current_user_id);
            $stmt->execute();
            $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!password_verify($current_password, $user_data['password'])) {
                $errors[] = "Current password is incorrect";
            }
        }

        if (empty($errors)) {
            try {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                
                $stmt = $conn->prepare("
                    UPDATE users SET 
                        password = :password,
                        updated_at = NOW()
                    WHERE id = :user_id
                ");
                $stmt->bindParam(':password', $hashed_password);
                $stmt->bindParam(':user_id', $current_user_id);
                $stmt->execute();

                // Log activity
                $action_log = "Changed password";
                $details = json_encode(['action' => 'password_change']);
                $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, created_at) VALUES (:user_id, :action, :details, NOW())");
                $log_stmt->bindParam(':user_id', $current_user_id);
                $log_stmt->bindParam(':action', $action_log);
                $log_stmt->bindParam(':details', $details);
                $log_stmt->execute();

                $success_message = "Password changed successfully!";
                
            } catch (PDOException $e) {
                $error_message = "Error changing password: " . $e->getMessage();
            }
        } else {
            $error_message = implode('<br>', $errors);
        }
    }
}

// Get current user details
$stmt = $conn->prepare("
    SELECT u.*, r.name as role_name, r.description as role_description,
           m.username as manager_username,
           CONCAT(COALESCE(m.first_name, ''), ' ', COALESCE(m.last_name, '')) as manager_name
    FROM users u
    LEFT JOIN roles r ON u.role_id = r.id
    LEFT JOIN users m ON u.manager_id = m.id
    WHERE u.id = :user_id
");
$stmt->bindParam(':user_id', $current_user_id);
$stmt->execute();
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_destroy();
    header("Location: ../../auth/login.php");
    exit();
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
        
        .user-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color), #8b5cf6);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 3rem;
            font-weight: 600;
            margin: 0 auto 1rem auto;
        }
        
        .profile-section {
            background: linear-gradient(135deg, var(--primary-color), #7c3aed);
            color: white;
            border-radius: 8px;
            padding: 2rem;
            margin-bottom: 2rem;
        }
        
        .nav-pills .nav-link {
            color: #64748b;
            border-radius: 8px;
            padding: 0.75rem 1.5rem;
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
            transition: all 0.3s ease;
            border: 1px solid transparent;
            font-weight: 500;
        }
        
        .nav-pills .nav-link:hover {
            color: var(--primary-color);
            background-color: rgba(99, 102, 241, 0.08);
            border-color: rgba(99, 102, 241, 0.2);
            transform: translateY(-1px);
        }
        
        .nav-pills .nav-link.active {
            background-color: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
        }
        
        .nav-pills .nav-link.active:hover {
            background-color: #4f46e5;
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(99, 102, 241, 0.4);
        }
        
        .form-section {
            background: white;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: box-shadow 0.3s ease;
        }
        
        .form-section:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .btn {
            transition: all 0.3s ease;
            font-weight: 500;
            border-radius: 8px;
            padding: 0.75rem 1.5rem;
            position: relative;
            overflow: hidden;
        }
        
        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }
        
        .btn:hover::before {
            left: 100%;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), #8b5cf6);
            border: none;
            box-shadow: 0 2px 8px rgba(99, 102, 241, 0.3);
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4);
        }
        
        .btn-primary:active {
            transform: translateY(0);
            box-shadow: 0 2px 8px rgba(99, 102, 241, 0.3);
        }
        
        .btn-outline-secondary {
            background: transparent;
            border: 2px solid #e2e8f0;
            color: #64748b;
        }
        
        .btn-outline-secondary:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
            color: #475569;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .btn-outline-secondary:active {
            transform: translateY(0);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .form-control {
            transition: all 0.3s ease;
            border-radius: 8px;
            border: 2px solid #e2e8f0;
            padding: 0.75rem 1rem;
        }
        
        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
            transform: translateY(-1px);
        }
        
        .form-control:hover:not(:focus) {
            border-color: #cbd5e1;
        }
        
        .alert {
            border-radius: 10px;
            border: none;
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
            animation: slideIn 0.3s ease;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .alert-success {
            background: linear-gradient(135deg, #ecfdf5, #d1fae5);
            color: #065f46;
            border-left: 4px solid #10b981;
        }
        
        .alert-danger {
            background: linear-gradient(135deg, #fef2f2, #fecaca);
            color: #991b1b;
            border-left: 4px solid #ef4444;
        }
        
        .form-label {
            font-weight: 600;
            color: #374151;
            margin-bottom: 0.5rem;
        }
        
        .text-danger {
            color: #ef4444 !important;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <?php
    $current_page = 'profile';
    include __DIR__ . '/../../include/navmenu.php';
    ?>

    <div class="main-content">
        <!-- Header -->
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1><i class="bi bi-person-gear me-2"></i>My Profile</h1>
                    <p class="header-subtitle">Manage your personal information and account settings</p>
                </div>
            </div>
        </header>

        <!-- Content -->
        <main class="content">
            <?php if ($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle me-2"></i><?php echo $success_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle me-2"></i><?php echo $error_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Profile Header -->
            <div class="profile-section">
                <div class="row align-items-center">
                    <div class="col-auto">
                        <div class="user-avatar">
                            <?php 
                            $full_name = trim($user['first_name'] . ' ' . $user['last_name']);
                            $display_name = $full_name ?: $user['username'];
                            echo strtoupper(substr($display_name, 0, 1));
                            ?>
                        </div>
                    </div>
                    <div class="col">
                        <h2 class="mb-1">
                            <?php echo htmlspecialchars($full_name ?: $user['username']); ?>
                        </h2>
                        <p class="mb-1 opacity-75">@<?php echo htmlspecialchars($user['username']); ?></p>
                        <div class="d-flex gap-3 align-items-center">
                            <span class="badge bg-light text-dark"><?php echo htmlspecialchars($user['role_name'] ?? 'No Role'); ?></span>
                            <?php if ($user['department']): ?>
                                <span class="opacity-75"><?php echo htmlspecialchars($user['department']); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Profile Tabs -->
            <ul class="nav nav-pills mb-4" id="profileTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="personal-tab" data-bs-toggle="pill" data-bs-target="#personal" type="button" role="tab">
                        <i class="bi bi-person me-2"></i>Personal Information
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="security-tab" data-bs-toggle="pill" data-bs-target="#security" type="button" role="tab">
                        <i class="bi bi-shield-lock me-2"></i>Security
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="account-tab" data-bs-toggle="pill" data-bs-target="#account" type="button" role="tab">
                        <i class="bi bi-gear me-2"></i>Account Info
                    </button>
                </li>
            </ul>

            <!-- Tab Content -->
            <div class="tab-content" id="profileTabsContent">
                <!-- Personal Information Tab -->
                <div class="tab-pane fade show active" id="personal" role="tabpanel">
                    <div class="form-section p-4">
                        <h5 class="border-bottom pb-3 mb-4">Personal Information</h5>
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="update_profile">
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="first_name" class="form-label">First Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="first_name" name="first_name" 
                                           value="<?php echo htmlspecialchars($user['first_name'] ?? ''); ?>" required>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label for="last_name" class="form-label">Last Name</label>
                                    <input type="text" class="form-control" id="last_name" name="last_name" 
                                           value="<?php echo htmlspecialchars($user['last_name'] ?? ''); ?>">
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label for="email" class="form-label">Email Address <span class="text-danger">*</span></label>
                                    <input type="email" class="form-control" id="email" name="email" 
                                           value="<?php echo htmlspecialchars($user['email']); ?>" required>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label for="phone" class="form-label">Phone Number</label>
                                    <input type="tel" class="form-control" id="phone" name="phone" 
                                           value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label for="date_of_birth" class="form-label">Date of Birth</label>
                                    <input type="date" class="form-control" id="date_of_birth" name="date_of_birth" 
                                           value="<?php echo htmlspecialchars($user['date_of_birth'] ?? ''); ?>">
                                </div>

                                <div class="col-12 mb-3">
                                    <label for="address" class="form-label">Address</label>
                                    <textarea class="form-control" id="address" name="address" rows="3"><?php echo htmlspecialchars($user['address'] ?? ''); ?></textarea>
                                </div>
                            </div>

                            <div class="d-flex justify-content-end">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-check-circle me-1"></i>Update Profile
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Security Tab -->
                <div class="tab-pane fade" id="security" role="tabpanel">
                    <div class="form-section p-4">
                        <h5 class="border-bottom pb-3 mb-4">Change Password</h5>
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="change_password">
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="current_password" class="form-label">Current Password <span class="text-danger">*</span></label>
                                    <input type="password" class="form-control" id="current_password" name="current_password" required>
                                </div>

                                <div class="col-md-6"></div>

                                <div class="col-md-6 mb-3">
                                    <label for="new_password" class="form-label">New Password <span class="text-danger">*</span></label>
                                    <input type="password" class="form-control" id="new_password" name="new_password" required>
                                    <div class="form-text">Minimum 6 characters</div>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label for="confirm_password" class="form-label">Confirm New Password <span class="text-danger">*</span></label>
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                </div>
                            </div>

                            <div class="d-flex justify-content-end">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-shield-check me-1"></i>Change Password
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Account Info Tab -->
                <div class="tab-pane fade" id="account" role="tabpanel">
                    <div class="form-section p-4">
                        <h5 class="border-bottom pb-3 mb-4">Account Information</h5>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Username</label>
                                <div class="form-control-plaintext"><?php echo htmlspecialchars($user['username']); ?></div>
                                <small class="text-muted">Username cannot be changed</small>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Role</label>
                                <div class="form-control-plaintext">
                                    <span class="badge bg-primary"><?php echo htmlspecialchars($user['role_name'] ?? 'No Role'); ?></span>
                                </div>
                                <small class="text-muted">Role is managed by administrators</small>
                            </div>

                            <?php if ($user['employee_id']): ?>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Employee ID</label>
                                <div class="form-control-plaintext"><?php echo htmlspecialchars($user['employee_id']); ?></div>
                            </div>
                            <?php endif; ?>

                            <?php if ($user['department']): ?>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Department</label>
                                <div class="form-control-plaintext"><?php echo htmlspecialchars($user['department']); ?></div>
                            </div>
                            <?php endif; ?>

                            <?php if ($user['manager_id']): ?>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Manager</label>
                                <div class="form-control-plaintext">
                                    <?php echo !empty(trim($user['manager_name'])) 
                                        ? htmlspecialchars(trim($user['manager_name']))
                                        : htmlspecialchars($user['manager_username']); ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if ($user['hire_date']): ?>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Hire Date</label>
                                <div class="form-control-plaintext"><?php echo date('F j, Y', strtotime($user['hire_date'])); ?></div>
                            </div>
                            <?php endif; ?>

                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Account Created</label>
                                <div class="form-control-plaintext"><?php echo date('F j, Y', strtotime($user['created_at'])); ?></div>
                            </div>

                            <?php if ($user['updated_at']): ?>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Last Updated</label>
                                <div class="form-control-plaintext"><?php echo date('F j, Y g:i A', strtotime($user['updated_at'])); ?></div>
                            </div>
                            <?php endif; ?>

                            <?php if ($user['last_login']): ?>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Last Login</label>
                                <div class="form-control-plaintext"><?php echo date('F j, Y g:i A', strtotime($user['last_login'])); ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Password confirmation validation
        document.getElementById('confirm_password').addEventListener('input', function() {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = this.value;
            
            if (newPassword !== confirmPassword) {
                this.setCustomValidity('Passwords do not match');
            } else {
                this.setCustomValidity('');
            }
        });

        // Clear form after successful submission (for password form)
        <?php if ($success_message && $_POST['action'] === 'change_password'): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const passwordForm = document.querySelector('form[action=""][method="POST"]');
            if (passwordForm && passwordForm.querySelector('input[name="action"][value="change_password"]')) {
                passwordForm.reset();
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>
