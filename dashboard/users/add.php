<?php
session_start();
require_once __DIR__ . '/../../include/db.php';
require_once __DIR__ . '/../../include/functions.php';

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
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $phone = trim($_POST['phone']);
    $address = trim($_POST['address']);
    $role_id_new = intval($_POST['role_id']);
    $status = $_POST['status'];
    $department = trim($_POST['department']);
    $employee_id = trim($_POST['employee_id']);
    $manager_id = !empty($_POST['manager_id']) ? intval($_POST['manager_id']) : null;
    $date_of_birth = !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null;
    $hire_date = !empty($_POST['hire_date']) ? $_POST['hire_date'] : null;

    // Validation
    $errors = [];

    if (empty($username)) {
        $errors[] = "Username is required";
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        $errors[] = "Username can only contain letters, numbers, and underscores";
    }

    if (empty($email)) {
        $errors[] = "Email is required";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format";
    }

    if (empty($password)) {
        $errors[] = "Password is required";
    } elseif (strlen($password) < 6) {
        $errors[] = "Password must be at least 6 characters long";
    }

    if ($password !== $confirm_password) {
        $errors[] = "Passwords do not match";
    }

    if (empty($first_name)) {
        $errors[] = "First name is required";
    }

    if (empty($role_id_new)) {
        $errors[] = "Role is required";
    }

    if (!in_array($status, ['active', 'inactive', 'suspended'])) {
        $errors[] = "Invalid status";
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
            
            $stmt = $conn->prepare("
                INSERT INTO users (
                    username, email, password, first_name, last_name, phone, address, 
                    role_id, status, department, employee_id, manager_id, 
                    date_of_birth, hire_date, created_at
                ) VALUES (
                    :username, :email, :password, :first_name, :last_name, :phone, :address,
                    :role_id, :status, :department, :employee_id, :manager_id,
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
            $stmt->bindParam(':manager_id', $manager_id);
            $stmt->bindParam(':date_of_birth', $date_of_birth);
            $stmt->bindParam(':hire_date', $hire_date);
            
            $stmt->execute();
            $new_user_id = $conn->lastInsertId();

            // Log activity
            $action = "Created new user: $username ($first_name $last_name)";
            $details = json_encode([
                'new_user_id' => $new_user_id,
                'username' => $username,
                'email' => $email,
                'role_id' => $role_id_new,
                'status' => $status
            ]);
            $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, created_at) VALUES (:user_id, :action, :details, NOW())");
            $log_stmt->bindParam(':user_id', $user_id);
            $log_stmt->bindParam(':action', $action);
            $log_stmt->bindParam(':details', $details);
            $log_stmt->execute();

            $success_message = "User created successfully!";
            
            // Redirect after successful creation
            header("Location: view.php?id=$new_user_id&created=1");
            exit();

        } catch (PDOException $e) {
            $error_message = "Error creating user: " . $e->getMessage();
        }
    } else {
        $error_message = implode('<br>', $errors);
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
                                <div class="row">
                                    <!-- Basic Information -->
                                    <div class="col-12">
                                        <h5 class="border-bottom pb-2 mb-4">Basic Information</h5>
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label for="username" class="form-label">Username <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="username" name="username" 
                                               value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required>
                                        <div class="form-text">Username can only contain letters, numbers, and underscores</div>
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label for="email" class="form-label">Email Address <span class="text-danger">*</span></label>
                                        <input type="email" class="form-control" id="email" name="email" 
                                               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label for="first_name" class="form-label">First Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="first_name" name="first_name" 
                                               value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>" required>
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label for="last_name" class="form-label">Last Name</label>
                                        <input type="text" class="form-control" id="last_name" name="last_name" 
                                               value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>">
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
                                               value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label for="date_of_birth" class="form-label">Date of Birth</label>
                                        <input type="date" class="form-control" id="date_of_birth" name="date_of_birth" 
                                               value="<?php echo htmlspecialchars($_POST['date_of_birth'] ?? ''); ?>">
                                    </div>

                                    <div class="col-12 mb-3">
                                        <label for="address" class="form-label">Address</label>
                                        <textarea class="form-control" id="address" name="address" rows="3"><?php echo htmlspecialchars($_POST['address'] ?? ''); ?></textarea>
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
                                               value="<?php echo htmlspecialchars($_POST['employee_id'] ?? ''); ?>">
                                    </div>

                                    <!-- Work Information -->
                                    <div class="col-12">
                                        <h5 class="border-bottom pb-2 mb-4 mt-4">Work Information</h5>
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label for="department" class="form-label">Department</label>
                                        <input type="text" class="form-control" id="department" name="department" 
                                               value="<?php echo htmlspecialchars($_POST['department'] ?? ''); ?>"
                                               placeholder="e.g., Sales, Marketing, IT">
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label for="hire_date" class="form-label">Hire Date</label>
                                        <input type="date" class="form-control" id="hire_date" name="hire_date" 
                                               value="<?php echo htmlspecialchars($_POST['hire_date'] ?? ''); ?>">
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
    </script>
</body>
</html>
