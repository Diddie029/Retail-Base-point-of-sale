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

$success_message = '';
$error_message = '';

// Generate CSRF token
$csrf_token = $security->generateCSRFToken();

// Handle form submission
if ($_POST) {
    // Check CSRF token
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error_message = "Invalid security token. Please refresh the page and try again.";
        $security->logSecurityEvent('csrf_token_invalid', ['ip' => $_SERVER['REMOTE_ADDR']], 'high');
    } else {
        // Check rate limiting
        if (!$security->checkRateLimit('add_user', $_SESSION['user_id'], 5, 300)) {
            $error_message = "Too many attempts. Please wait 5 minutes before trying again.";
            $security->logSecurityEvent('rate_limit_exceeded', ['action' => 'add_user', 'user_id' => $_SESSION['user_id']], 'medium');
        } else {
            // Check for suspicious activity
            if ($security->checkSuspiciousActivity($_POST)) {
                $error_message = "Suspicious activity detected. Your request has been blocked.";
                $security->logSecurityEvent('suspicious_activity_blocked', ['user_id' => $_SESSION['user_id'], 'data' => $_POST], 'high');
            } else {
                // Sanitize and validate input
                $sanitized_data = $security->sanitizeUserInput($_POST);
                $validation_rules = $security->getUserValidationRules();
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

                    if ($password !== $confirm_password) {
                        $errors[] = "Passwords do not match";
                    }

                    // Check if username exists
                    if (empty($errors)) {
                        $stmt = $conn->prepare("SELECT id FROM users WHERE username = :username");
                        $stmt->bindParam(':username', $username);
                        $stmt->execute();
                        if ($stmt->rowCount() > 0) {
                            $errors[] = "Username already exists";
                        }
                    }

                    // Check if email exists
                    if (empty($errors)) {
                        $stmt = $conn->prepare("SELECT id FROM users WHERE email = :email");
                        $stmt->bindParam(':email', $email);
                        $stmt->execute();
                        if ($stmt->rowCount() > 0) {
                            $errors[] = "Email already exists";
                        }
                    }

                    // Check if employee ID exists (if provided)
                    if (!empty($employee_id) && empty($errors)) {
                        $stmt = $conn->prepare("SELECT id FROM users WHERE employee_id = :employee_id");
                        $stmt->bindParam(':employee_id', $employee_id);
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

                    if (empty($errors)) {
                        try {
                            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
                            // Generate user_id if not provided
                            if (empty($user_id)) {
                                $user_id = generateUniqueUserID($conn);
                            }
                            
                            // Generate employee_id if auto-generation is enabled and not provided
                            if (empty($employee_id)) {
                                $employee_id = generateEmployeeId($conn);
                            }
                            
                            $stmt = $conn->prepare("
                                INSERT INTO users (
                                    username, email, password, first_name, last_name, phone, address, 
                                    role_id, status, department, employee_id, user_id, manager_id, 
                                    date_of_birth, hire_date, created_at
                                ) VALUES (
                                    :username, :email, :password, :first_name, :last_name, :phone, :address,
                                    :role_id, :status, :department, :employee_id, :user_id, :manager_id,
                                    :date_of_birth, :hire_date, NOW()
                                )
                            ");

                            $stmt->bindParam(':username', $username);
                            $stmt->bindParam(':email', $email);
                            $stmt->bindParam(':password', $hashed_password);
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
                            
                            $stmt->execute();
                            $new_user_id = $conn->lastInsertId();

                            // Log activity
                            $action = "Created new user: $username ($first_name $last_name)";
                            $details = [
                                'new_user_id' => $new_user_id,
                                'username' => $username,
                                'email' => $email,
                                'role_id' => $role_id_new,
                                'status' => $status,
                                'generated_user_id' => $user_id,
                                'generated_employee_id' => $employee_id
                            ];
                            $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, created_at) VALUES (:user_id, :action, :details, NOW())");
                            $log_stmt->bindParam(':user_id', $user_id);
                            $log_stmt->bindParam(':action', $action);
                            $log_stmt->bindParam(':details', json_encode($details));
                            $log_stmt->execute();
                            
                            // Log specific ID generation
                            if (!empty($user_id)) {
                                $id_action = "Generated User ID for new user {$username}";
                                $id_details = json_encode([
                                    'target_user_id' => $new_user_id,
                                    'generated_user_id' => $user_id,
                                    'username' => $username,
                                    'change_type' => 'user_creation'
                                ]);
                                
                                $id_log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, created_at) VALUES (:user_id, :action, :details, NOW())");
                                $id_log_stmt->bindParam(':user_id', $user_id);
                                $id_log_stmt->bindParam(':action', $id_action);
                                $id_log_stmt->bindParam(':details', $id_details);
                                $id_log_stmt->execute();
                            }
                            
                            if (!empty($employee_id)) {
                                $emp_action = "Generated Employee ID for new user {$username}";
                                $emp_details = json_encode([
                                    'target_user_id' => $new_user_id,
                                    'generated_employee_id' => $employee_id,
                                    'username' => $username,
                                    'change_type' => 'user_creation'
                                ]);
                                
                                $emp_log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, created_at) VALUES (:user_id, :action, :details, NOW())");
                                $emp_log_stmt->bindParam(':user_id', $user_id);
                                $emp_log_stmt->bindParam(':action', $emp_action);
                                $emp_log_stmt->bindParam(':details', $emp_details);
                                $emp_log_stmt->execute();
                            }

                            $success_message = "User created successfully!";
                            
                            // Log successful user creation
                            $security->logSecurityEvent('user_created', [
                                'new_user_id' => $new_user_id,
                                'username' => $username,
                                'created_by' => $_SESSION['user_id']
                            ], 'low');
                            
                            // Redirect after successful creation
                            header("Location: view.php?id=$new_user_id&created=1");
                            exit();

                        } catch (PDOException $e) {
                            $error_message = "Error creating user: " . $e->getMessage();
                            $security->logSecurityEvent('user_creation_failed', [
                                'error' => $e->getMessage(),
                                'username' => $username,
                                'created_by' => $_SESSION['user_id']
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

// Get potential managers (all active users except admin roles)
$managers = [];
$stmt = $conn->query("
    SELECT u.id, u.username, u.first_name, u.last_name, r.name as role_name
    FROM users u 
    LEFT JOIN roles r ON u.role_id = r.id
    WHERE u.status = 'active' 
    ORDER BY u.first_name, u.last_name, u.username
");
$managers = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add User - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
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
                    <h1><i class="bi bi-person-plus-fill me-2"></i>Add New User</h1>
                    <p class="header-subtitle">Create a new system user account</p>
                </div>
                <div class="header-actions">
                    <a href="index.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-1"></i>Back to Users
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
                                               value="<?php echo $security->escapeOutput($_POST['username'] ?? ''); ?>" required>
                                        <div class="form-text">Username can only contain letters, numbers, and underscores</div>
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label for="email" class="form-label">Email Address <span class="text-danger">*</span></label>
                                        <input type="email" class="form-control" id="email" name="email" 
                                               value="<?php echo $security->escapeOutput($_POST['email'] ?? ''); ?>" required>
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label for="first_name" class="form-label">First Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="first_name" name="first_name" 
                                               value="<?php echo $security->escapeOutput($_POST['first_name'] ?? ''); ?>" required>
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label for="last_name" class="form-label">Last Name</label>
                                        <input type="text" class="form-control" id="last_name" name="last_name" 
                                               value="<?php echo $security->escapeOutput($_POST['last_name'] ?? ''); ?>">
                                    </div>

                                    <!-- Password -->
                                    <div class="col-12">
                                        <h5 class="border-bottom pb-2 mb-4 mt-4">Password</h5>
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label for="password" class="form-label">Password <span class="text-danger">*</span></label>
                                        <input type="password" class="form-control" id="password" name="password" required>
                                        <div class="form-text">Minimum 6 characters</div>
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label for="confirm_password" class="form-label">Confirm Password <span class="text-danger">*</span></label>
                                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                    </div>

                                    <!-- Contact Information -->
                                    <div class="col-12">
                                        <h5 class="border-bottom pb-2 mb-4 mt-4">Contact Information</h5>
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label for="phone" class="form-label">Phone Number</label>
                                        <input type="tel" class="form-control" id="phone" name="phone" 
                                               value="<?php echo $security->escapeOutput($_POST['phone'] ?? ''); ?>">
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label for="date_of_birth" class="form-label">Date of Birth</label>
                                        <input type="date" class="form-control" id="date_of_birth" name="date_of_birth" 
                                               value="<?php echo $security->escapeOutput($_POST['date_of_birth'] ?? ''); ?>">
                                    </div>

                                    <div class="col-12 mb-3">
                                        <label for="address" class="form-label">Address</label>
                                        <textarea class="form-control" id="address" name="address" rows="3"><?php echo $security->escapeOutput($_POST['address'] ?? ''); ?></textarea>
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
                                                <option value="<?php echo $role['id']; ?>" <?php echo ($_POST['role_id'] ?? '') == $role['id'] ? 'selected' : ''; ?>>
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
                                        <select class="form-select" id="status" name="status" required>
                                            <option value="active" <?php echo ($_POST['status'] ?? 'active') === 'active' ? 'selected' : ''; ?>>Active</option>
                                            <option value="inactive" <?php echo ($_POST['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                            <option value="suspended" <?php echo ($_POST['status'] ?? '') === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                                        </select>
                                    </div>

                                    <div class="col-md-4 mb-3">
                                        <label for="employee_id" class="form-label">Employee ID</label>
                                        <input type="text" class="form-control" id="employee_id" name="employee_id" 
                                               value="<?php echo $security->escapeOutput($_POST['employee_id'] ?? ''); ?>"
                                               placeholder="<?php 
                                                   $empSettings = getEmployeeIdSettings($conn);
                                                   echo $empSettings['auto_generate'] ? 'Auto-generated' : 'Enter Employee ID';
                                               ?>">
                                        <?php if ($empSettings['auto_generate']): ?>
                                            <div class="form-text">Employee ID will be auto-generated based on your settings</div>
                                        <?php else: ?>
                                            <div class="form-text">Enter Employee ID manually or enable auto-generation in settings</div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="col-md-4 mb-3">
                                        <label for="user_id" class="form-label">User ID</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control" id="user_id" name="user_id" 
                                                   value="<?php echo $security->escapeOutput($_POST['user_id'] ?? ''); ?>"
                                                   placeholder="Auto-generated" readonly>
                                            <button type="button" class="btn btn-outline-secondary" id="generateUserIdBtn" title="Generate Random ID">
                                                <i class="bi bi-shuffle"></i>
                                            </button>
                                        </div>
                                        <div class="form-text">User ID will be auto-generated when you create the user</div>
                                    </div>

                                    <!-- Work Information -->
                                    <div class="col-12">
                                        <h5 class="border-bottom pb-2 mb-4 mt-4">Work Information</h5>
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label for="department" class="form-label">Department</label>
                                        <input type="text" class="form-control" id="department" name="department" 
                                               value="<?php echo $security->escapeOutput($_POST['department'] ?? ''); ?>"
                                               placeholder="e.g., Sales, Marketing, IT">
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label for="hire_date" class="form-label">Hire Date</label>
                                        <input type="date" class="form-control" id="hire_date" name="hire_date" 
                                               value="<?php echo $security->escapeOutput($_POST['hire_date'] ?? ''); ?>">
                                    </div>

                                    <div class="col-12 mb-3">
                                        <label for="manager_id" class="form-label">Manager</label>
                                        <select class="form-select" id="manager_id" name="manager_id">
                                            <option value="">No Manager</option>
                                            <?php foreach ($managers as $manager): ?>
                                                <option value="<?php echo $manager['id']; ?>" <?php echo ($_POST['manager_id'] ?? '') == $manager['id'] ? 'selected' : ''; ?>>
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
                                    <a href="index.php" class="btn btn-outline-secondary">
                                        <i class="bi bi-arrow-left me-1"></i>Cancel
                                    </a>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-check-circle me-1"></i>Create User
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
            
            if (password !== confirmPassword) {
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

        // User ID generation (auto-generate on page load and button click)
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
                alert('Unable to generate a valid user ID. Please refresh the page and try again.');
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

        // Auto-generate user ID on page load
        generateUserId();

        // Generate new user ID when button is clicked (with password verification)
        document.getElementById('generateUserIdBtn').addEventListener('click', generateUserIdWithVerification);
    </script>
</body>
</html>
