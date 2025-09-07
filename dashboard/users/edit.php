<?php
session_start();
require_once __DIR__ . '/../../include/db.php';
require_once __DIR__ . '/../../include/functions.php';
require_once __DIR__ . '/../../include/classes/SecurityManager.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
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

// Check permissions
if (!hasPermission('manage_users', $permissions)) {
    header("Location: ../../dashboard/dashboard.php");
    exit();
}

// Initialize security manager
$security = new SecurityManager($conn);

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Generate CSRF token
$csrf_token = $security->generateCSRFToken();

// Get user ID from URL
$edit_user_id = intval($_GET['id'] ?? 0);

if (!$edit_user_id) {
    header("Location: index.php");
    exit();
}

// Get current user details
$stmt = $conn->prepare("
    SELECT u.*, r.name as role_name
    FROM users u
    LEFT JOIN roles r ON u.role_id = r.id
    WHERE u.id = :id
");
$stmt->bindParam(':id', $edit_user_id);
$stmt->execute();
$edit_user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$edit_user) {
    header("Location: index.php");
    exit();
}

$success_message = '';
$error_message = '';

// Handle form submission
if ($_POST) {
    // Check CSRF token
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error_message = "Invalid security token. Please refresh the page and try again.";
        $security->logSecurityEvent('csrf_token_invalid', ['ip' => $_SERVER['REMOTE_ADDR']], 'high');
    } else {
        // Check rate limiting
        if (!$security->checkRateLimit('edit_user', $_SESSION['user_id'], 10, 300)) {
            $error_message = "Too many attempts. Please wait 5 minutes before trying again.";
            $security->logSecurityEvent('rate_limit_exceeded', ['action' => 'edit_user', 'user_id' => $_SESSION['user_id']], 'medium');
        } else {
            // Check for suspicious activity
            if ($security->checkSuspiciousActivity($_POST)) {
                $error_message = "Suspicious activity detected. Your request has been blocked.";
                $security->logSecurityEvent('suspicious_activity_blocked', ['user_id' => $_SESSION['user_id'], 'data' => $_POST], 'high');
            } else {
                // Sanitize and validate input
                $sanitized_data = $security->sanitizeUserInput($_POST);
                $validation_rules = $security->getUserValidationRules();
                
                // Make password optional for edit
                $validation_rules['password']['required'] = false;
                
                $validation_errors = $security->validateInput($sanitized_data, $validation_rules);
                
                if (!empty($validation_errors)) {
                    $error_message = "Validation errors:<br>" . implode('<br>', array_merge(...array_values($validation_errors)));
                } else {
                    // Use sanitized data
                    $username = $sanitized_data['username'];
                    $email = $sanitized_data['email'];
                    $password = $sanitized_data['password'];
                    $confirm_password = $_POST['confirm_password']; // Don't sanitize password confirmation
                    $first_name = $sanitized_data['first_name'];
                    $last_name = $sanitized_data['last_name'];
                    $phone = $sanitized_data['phone'];
                    $address = $sanitized_data['address'];
                    $role_id_new = $sanitized_data['role_id'];
                    $status = $sanitized_data['status'];
                    $department = $sanitized_data['department'];
                    $employee_id = $sanitized_data['employee_id'];
                    $user_id = $sanitized_data['user_id'];
                    $manager_id = $sanitized_data['manager_id'];
                    $date_of_birth = $sanitized_data['date_of_birth'];
                    $hire_date = $sanitized_data['hire_date'];

                    // Additional validation
                    $errors = [];

                    if (!empty($password) && $password !== $confirm_password) {
                        $errors[] = "Passwords do not match";
                    }

                    // Check if username exists (excluding current user)
                    if (empty($errors)) {
                        $stmt = $conn->prepare("SELECT id FROM users WHERE username = :username AND id != :current_id");
                        $stmt->bindParam(':username', $username);
                        $stmt->bindParam(':current_id', $edit_user_id);
                        $stmt->execute();
                        if ($stmt->rowCount() > 0) {
                            $errors[] = "Username already exists";
                        }
                    }

                    // Check if email exists (excluding current user)
                    if (empty($errors)) {
                        $stmt = $conn->prepare("SELECT id FROM users WHERE email = :email AND id != :current_id");
                        $stmt->bindParam(':email', $email);
                        $stmt->bindParam(':current_id', $edit_user_id);
                        $stmt->execute();
                        if ($stmt->rowCount() > 0) {
                            $errors[] = "Email already exists";
                        }
                    }

                    // Check if employee ID exists (excluding current user)
                    if (!empty($employee_id) && empty($errors)) {
                        $stmt = $conn->prepare("SELECT id FROM users WHERE employee_id = :employee_id AND id != :current_id");
                        $stmt->bindParam(':employee_id', $employee_id);
                        $stmt->bindParam(':current_id', $edit_user_id);
                        $stmt->execute();
                        if ($stmt->rowCount() > 0) {
                            $errors[] = "Employee ID already exists";
                        }
                    }

                    // Check if role exists
                    if (empty($errors)) {
                        $stmt = $conn->prepare("SELECT id FROM roles WHERE id = :role_id");
                        $stmt->bindParam(':role_id', $role_id_new);
                        $stmt->execute();
                        if ($stmt->rowCount() === 0) {
                            $errors[] = "Invalid role selected";
                        }
                    }

                    // Prevent user from changing their own status to inactive/suspended
                    if ($edit_user_id == $user_id && $status !== 'active') {
                        $errors[] = "You cannot change your own status to inactive or suspended";
                    }

                    if (empty($errors)) {
                        try {
                            // Track changes for logging
                            $changes = [];
            
                            // Check for User ID changes
                            if ($edit_user['user_id'] !== $user_id) {
                                $changes['user_id'] = [
                                    'old' => $edit_user['user_id'],
                                    'new' => $user_id
                                ];
                            }
                            
                            // Check for Employee ID changes
                            if ($edit_user['employee_id'] !== $employee_id) {
                                $changes['employee_id'] = [
                                    'old' => $edit_user['employee_id'],
                                    'new' => $employee_id
                                ];
                            }
            
                            // Prepare the update query
                            if (!empty($password)) {
                                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                                $sql = "
                                    UPDATE users SET 
                                        username = :username, email = :email, password = :password,
                                        first_name = :first_name, last_name = :last_name, phone = :phone, 
                                        address = :address, role_id = :role_id, status = :status, 
                                        department = :department, employee_id = :employee_id, user_id = :user_id, manager_id = :manager_id,
                                        date_of_birth = :date_of_birth, hire_date = :hire_date, updated_at = NOW()
                                    WHERE id = :id
                                ";
                            } else {
                                $sql = "
                                    UPDATE users SET 
                                        username = :username, email = :email,
                                        first_name = :first_name, last_name = :last_name, phone = :phone, 
                                        address = :address, role_id = :role_id, status = :status, 
                                        department = :department, employee_id = :employee_id, user_id = :user_id, manager_id = :manager_id,
                                        date_of_birth = :date_of_birth, hire_date = :hire_date, updated_at = NOW()
                                    WHERE id = :id
                                ";
                            }
            
                            $stmt = $conn->prepare($sql);

                            $stmt->bindParam(':username', $username);
                            $stmt->bindParam(':email', $email);
                            if (!empty($password)) {
                                $stmt->bindParam(':password', $hashed_password);
                            }
                            $stmt->bindParam(':first_name', $first_name);
                            $stmt->bindParam(':last_name', $last_name);
                            $stmt->bindParam(':phone', $phone);
                            $stmt->bindParam(':address', $address);
                            $stmt->bindParam(':role_id', $role_id_new);
                            $stmt->bindParam(':status', $status);
                            $stmt->bindParam(':department', $department);
                            $stmt->bindParam(':employee_id', $employee_id);
                            $stmt->bindParam(':user_id', $user_id);
                            $stmt->bindParam(':manager_id', $manager_id);
                            $stmt->bindParam(':date_of_birth', $date_of_birth);
                            $stmt->bindParam(':hire_date', $hire_date);
                            $stmt->bindParam(':id', $edit_user_id);
                            
                            $stmt->execute();

                            // Log activity
                            $changes = [];
                            if ($edit_user['username'] !== $username) $changes[] = "username: {$edit_user['username']} → $username";
                            if ($edit_user['email'] !== $email) $changes[] = "email: {$edit_user['email']} → $email";
                            if ($edit_user['first_name'] !== $first_name) $changes[] = "first_name: {$edit_user['first_name']} → $first_name";
                            if ($edit_user['status'] !== $status) $changes[] = "status: {$edit_user['status']} → $status";
                            if ($edit_user['role_id'] != $role_id_new) $changes[] = "role changed";
                            if (!empty($password)) $changes[] = "password updated";
                            
                            // Track User ID and Employee ID changes separately for detailed logging
                            $id_changes = [];
                            if ($edit_user['user_id'] !== $user_id) {
                                $changes[] = "user_id: {$edit_user['user_id']} → $user_id";
                                $id_changes['user_id'] = [
                                    'old' => $edit_user['user_id'],
                                    'new' => $user_id
                                ];
                            }
                            if ($edit_user['employee_id'] !== $employee_id) {
                                $changes[] = "employee_id: {$edit_user['employee_id']} → $employee_id";
                                $id_changes['employee_id'] = [
                                    'old' => $edit_user['employee_id'],
                                    'new' => $employee_id
                                ];
                            }

                            if (!empty($changes)) {
                                $action = "Updated user: $username (" . implode(", ", $changes) . ")";
                                $details = json_encode([
                                    'target_user_id' => $edit_user_id,
                                    'changes' => $changes,
                                    'id_changes' => $id_changes
                                ]);
                                $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, created_at) VALUES (:user_id, :action, :details, NOW())");
                                $log_stmt->bindParam(':user_id', $user_id);
                                $log_stmt->bindParam(':action', $action);
                                $log_stmt->bindParam(':details', $details);
                                $log_stmt->execute();
                            }
                            
                            // Log specific ID changes for better tracking
                            if (!empty($id_changes)) {
                                foreach ($id_changes as $field => $change) {
                                    $field_name = $field === 'user_id' ? 'User ID' : 'Employee ID';
                                    $id_action = "Changed {$field_name} for user {$username}";
                                    $id_details = json_encode([
                                        'target_user_id' => $edit_user_id,
                                        'field' => $field,
                                        'old_value' => $change['old'],
                                        'new_value' => $change['new'],
                                        'username' => $username,
                                        'change_type' => 'profile_update'
                                    ]);
                                    
                                    $id_log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, created_at) VALUES (:user_id, :action, :details, NOW())");
                                    $id_log_stmt->bindParam(':user_id', $user_id);
                                    $id_log_stmt->bindParam(':action', $id_action);
                                    $id_log_stmt->bindParam(':details', $id_details);
                                    $id_log_stmt->execute();
                                }
                            }

                            $success_message = "User updated successfully!";
                            
                            // Log successful user update
                            $security->logSecurityEvent('user_updated', [
                                'target_user_id' => $edit_user_id,
                                'username' => $username,
                                'updated_by' => $_SESSION['user_id']
                            ], 'low');
                            
                            // Refresh user data
                            $stmt = $conn->prepare("
                                SELECT u.*, r.name as role_name
                                FROM users u
                                LEFT JOIN roles r ON u.role_id = r.id
                                WHERE u.id = :id
                            ");
                            $stmt->bindParam(':id', $edit_user_id);
                            $stmt->execute();
                            $edit_user = $stmt->fetch(PDO::FETCH_ASSOC);

                        } catch (PDOException $e) {
                            $error_message = "Error updating user: " . $e->getMessage();
                            $security->logSecurityEvent('user_update_failed', [
                                'error' => $e->getMessage(),
                                'target_user_id' => $edit_user_id,
                                'updated_by' => $_SESSION['user_id']
                            ], 'medium');
                        }
                    } else {
                        $error_message = implode('<br>', $errors);
                    }
                }
            }
        }
    }
}

// Get roles for dropdown
$roles = [];
$stmt = $conn->query("SELECT id, name, description FROM roles ORDER BY name");
$roles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get potential managers (all active users except current user)
$managers = [];
$stmt = $conn->prepare("
    SELECT u.id, u.username, u.first_name, u.last_name, r.name as role_name
    FROM users u 
    LEFT JOIN roles r ON u.role_id = r.id
    WHERE u.status = 'active' AND u.id != :current_user_id
    ORDER BY u.first_name, u.last_name, u.username
");
$stmt->bindParam(':current_user_id', $edit_user_id);
$stmt->execute();
$managers = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit User - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <?php
    $current_page = 'users';
    include __DIR__ . '/../../include/navmenu.php';
    ?>

    <div class="main-content">
        <!-- Header -->
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1><i class="bi bi-pencil-square me-2"></i>Edit User</h1>
                    <p class="header-subtitle">Update user information and settings</p>
                </div>
                <div class="header-actions">
                    <a href="index.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-1"></i>Back to Users
                    </a>
                    <a href="view.php?id=<?php echo $edit_user['id']; ?>" class="btn btn-outline-primary">
                        <i class="bi bi-eye me-1"></i>View Profile
                    </a>
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

            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-body">
                            <form method="POST" action="">
                                <input type="hidden" name="csrf_token" value="<?php echo $security->escapeOutput($csrf_token); ?>">
                                <div class="row">
                                    <!-- Basic Information -->
                                    <div class="col-12">
                                        <h5 class="border-bottom pb-2 mb-4">Basic Information</h5>
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label for="username" class="form-label">Username <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="username" name="username" 
                                               value="<?php echo $security->escapeOutput($edit_user['username']); ?>" required>
                                        <div class="form-text">Username can only contain letters, numbers, and underscores</div>
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label for="email" class="form-label">Email Address <span class="text-danger">*</span></label>
                                        <input type="email" class="form-control" id="email" name="email" 
                                               value="<?php echo $security->escapeOutput($edit_user['email']); ?>" required>
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label for="first_name" class="form-label">First Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="first_name" name="first_name" 
                                               value="<?php echo $security->escapeOutput($edit_user['first_name'] ?? ''); ?>" required>
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label for="last_name" class="form-label">Last Name</label>
                                        <input type="text" class="form-control" id="last_name" name="last_name" 
                                               value="<?php echo $security->escapeOutput($edit_user['last_name'] ?? ''); ?>">
                                    </div>

                                    <!-- Password (Optional) -->
                                    <div class="col-12">
                                        <h5 class="border-bottom pb-2 mb-4 mt-4">Password <small class="text-muted">(leave blank to keep current password)</small></h5>
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label for="password" class="form-label">New Password</label>
                                        <input type="password" class="form-control" id="password" name="password">
                                        <div class="form-text">Minimum 6 characters, leave blank to keep current password</div>
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label for="confirm_password" class="form-label">Confirm New Password</label>
                                        <input type="password" class="form-control" id="confirm_password" name="confirm_password">
                                    </div>

                                    <!-- Contact Information -->
                                    <div class="col-12">
                                        <h5 class="border-bottom pb-2 mb-4 mt-4">Contact Information</h5>
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label for="phone" class="form-label">Phone Number</label>
                                        <input type="tel" class="form-control" id="phone" name="phone" 
                                               value="<?php echo $security->escapeOutput($edit_user['phone'] ?? ''); ?>">
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label for="date_of_birth" class="form-label">Date of Birth</label>
                                        <input type="date" class="form-control" id="date_of_birth" name="date_of_birth" 
                                               value="<?php echo $security->escapeOutput($edit_user['date_of_birth'] ?? ''); ?>">
                                    </div>

                                    <div class="col-12 mb-3">
                                        <label for="address" class="form-label">Address</label>
                                        <textarea class="form-control" id="address" name="address" rows="3"><?php echo $security->escapeOutput($edit_user['address'] ?? ''); ?></textarea>
                                    </div>

                                    <!-- Role & Status -->
                                    <div class="col-12">
                                        <h5 class="border-bottom pb-2 mb-4 mt-4">Role & Status</h5>
                                    </div>

                                    <div class="col-md-4 mb-3">
                                        <label for="role_id" class="form-label">Role <span class="text-danger">*</span></label>
                                        <select class="form-select" id="role_id" name="role_id" required>
                                            <option value="">Select Role</option>
                                            <?php foreach ($roles as $role): ?>
                                                <option value="<?php echo $role['id']; ?>" <?php echo $edit_user['role_id'] == $role['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($role['name']); ?>
                                                    <?php if ($role['description']): ?>
                                                        - <?php echo htmlspecialchars($role['description']); ?>
                                                    <?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-md-4 mb-3">
                                        <label for="status" class="form-label">Status <span class="text-danger">*</span></label>
                                        <select class="form-select" id="status" name="status" required <?php echo $edit_user_id == $user_id ? 'disabled' : ''; ?>>
                                            <option value="active" <?php echo $edit_user['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                            <option value="inactive" <?php echo $edit_user['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                            <option value="suspended" <?php echo $edit_user['status'] === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                                        </select>
                                        <?php if ($edit_user_id == $user_id): ?>
                                            <input type="hidden" name="status" value="<?php echo $edit_user['status']; ?>">
                                            <div class="form-text text-muted">You cannot change your own status</div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="col-md-4 mb-3">
                                        <label for="employee_id" class="form-label">Employee ID</label>
                                        <input type="text" class="form-control" id="employee_id" name="employee_id" 
                                               value="<?php echo $security->escapeOutput($edit_user['employee_id'] ?? ''); ?>">
                                    </div>

                                    <div class="col-md-4 mb-3">
                                        <label for="user_id" class="form-label">User ID</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control" id="user_id" name="user_id" 
                                                   value="<?php echo $security->escapeOutput($edit_user['user_id'] ?? ''); ?>"
                                                   placeholder="Auto-generated" readonly>
                                            <button type="button" class="btn btn-outline-secondary" id="generateUserIdBtn" title="Generate New ID">
                                                <i class="bi bi-shuffle"></i>
                                            </button>
                                        </div>
                                        <div class="form-text">User ID can be regenerated if needed</div>
                                    </div>

                                    <!-- Work Information -->
                                    <div class="col-12">
                                        <h5 class="border-bottom pb-2 mb-4 mt-4">Work Information</h5>
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label for="department" class="form-label">Department</label>
                                        <input type="text" class="form-control" id="department" name="department" 
                                               value="<?php echo $security->escapeOutput($edit_user['department'] ?? ''); ?>"
                                               placeholder="e.g., Sales, Marketing, IT">
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label for="hire_date" class="form-label">Hire Date</label>
                                        <input type="date" class="form-control" id="hire_date" name="hire_date" 
                                               value="<?php echo $security->escapeOutput($edit_user['hire_date'] ?? ''); ?>">
                                    </div>

                                    <div class="col-12 mb-3">
                                        <label for="manager_id" class="form-label">Manager</label>
                                        <select class="form-select" id="manager_id" name="manager_id">
                                            <option value="">No Manager</option>
                                            <?php foreach ($managers as $manager): ?>
                                                <option value="<?php echo $manager['id']; ?>" <?php echo $edit_user['manager_id'] == $manager['id'] ? 'selected' : ''; ?>>
                                                    <?php 
                                                    $manager_name = trim($manager['first_name'] . ' ' . $manager['last_name']);
                                                    echo htmlspecialchars(!empty($manager_name) ? $manager_name : $manager['username']); 
                                                    ?>
                                                    (<?php echo htmlspecialchars($manager['role_name'] ?? 'No Role'); ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="d-flex justify-content-between mt-4">
                                    <div>
                                        <a href="index.php" class="btn btn-outline-secondary">
                                            <i class="bi bi-arrow-left me-1"></i>Cancel
                                        </a>
                                        <a href="view.php?id=<?php echo $edit_user['id']; ?>" class="btn btn-outline-primary">
                                            <i class="bi bi-eye me-1"></i>View Profile
                                        </a>
                                    </div>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-check-circle me-1"></i>Update User
                                    </button>
                                </div>
                            </form>
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
            const password = document.getElementById('password').value;
            const confirmPassword = this.value;
            
            if (password && confirmPassword && password !== confirmPassword) {
                this.setCustomValidity('Passwords do not match');
            } else {
                this.setCustomValidity('');
            }
        });

        // Username validation
        document.getElementById('username').addEventListener('input', function() {
            const username = this.value;
            const regex = /^[a-zA-Z0-9_]+$/;
            
            if (username && !regex.test(username)) {
                this.setCustomValidity('Username can only contain letters, numbers, and underscores');
            } else {
                this.setCustomValidity('');
            }
        });

        // User ID generation (regenerate when button is clicked)
        function generateUserId() {
            const user_id_input = document.getElementById('user_id');
            
            // Forbidden patterns
            const forbidden = ['1234', '4321', '1001', '2002', '3003', '4004', '5005', '6006', '7007', '8008', '9009'];
            
            let user_id;
            let attempts = 0;
            const maxAttempts = 100;
            
            do {
                // Generate random 4-digit number (1000-9999)
                user_id = Math.floor(Math.random() * 9000) + 1000;
                user_id = user_id.toString().padStart(4, '0');
                attempts++;
            } while (forbidden.includes(user_id) && attempts < maxAttempts);
            
            if (attempts >= maxAttempts) {
                alert('Unable to generate a valid user ID. Please try again.');
                return;
            }
            
            user_id_input.value = user_id;
        }

        // Generate new user ID with password verification
        function generateUserIdWithVerification() {
            const password = prompt('For security reasons, please enter your password to generate a new User ID:');
            
            if (!password) {
                return; // User cancelled
            }
            
            // Verify password and generate new ID
            fetch('../../api/users/verify_password_and_generate_single_user_id.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ password: password })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('user_id').value = data.user_id;
                    alert('New User ID generated successfully!');
                } else {
                    alert('Error: ' + (data.message || 'Failed to generate User ID'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while generating User ID.');
            });
        }

        // Generate new user ID when button is clicked (with password verification)
        document.getElementById('generateUserIdBtn').addEventListener('click', generateUserIdWithVerification);
    </script>
</body>
</html>
