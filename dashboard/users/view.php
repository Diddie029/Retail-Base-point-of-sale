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

// Get current user permissions
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

// Get user ID from URL
$view_user_id = intval($_GET['id'] ?? 0);

if (!$view_user_id) {
    header("Location: index.php");
    exit();
}

// Check permissions - users can view their own profile or need manage_users/view_users permission
$can_view = ($current_user_id == $view_user_id) || hasPermission('manage_users', $permissions) || hasPermission('view_users', $permissions);
if (!$can_view) {
    header("Location: ../../dashboard/dashboard.php");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get user details
$stmt = $conn->prepare("
    SELECT u.*, r.name as role_name, r.description as role_description,
           m.username as manager_username,
           CONCAT(COALESCE(m.first_name, ''), ' ', COALESCE(m.last_name, '')) as manager_name
    FROM users u
    LEFT JOIN roles r ON u.role_id = r.id
    LEFT JOIN users m ON u.manager_id = m.id
    WHERE u.id = :id
");
$stmt->bindParam(':id', $view_user_id);
$stmt->execute();
$view_user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$view_user) {
    header("Location: index.php");
    exit();
}

// Get user's role permissions
$user_permissions = [];
if ($view_user['role_id']) {
    $stmt = $conn->prepare("
        SELECT p.name, p.description
        FROM permissions p 
        JOIN role_permissions rp ON p.id = rp.permission_id 
        WHERE rp.role_id = :role_id
        ORDER BY p.name
    ");
    $stmt->bindParam(':role_id', $view_user['role_id']);
    $stmt->execute();
    $user_permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get recent activities
$stmt = $conn->prepare("
    SELECT action, details, created_at
    FROM activity_logs 
    WHERE user_id = :user_id 
    ORDER BY created_at DESC 
    LIMIT 10
");
$stmt->bindParam(':user_id', $view_user_id);
$stmt->execute();
$activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get login history
$stmt = $conn->prepare("
    SELECT * FROM login_attempts 
    WHERE identifier IN (:username, :email) AND success = 1
    ORDER BY created_at DESC 
    LIMIT 10
");
$stmt->bindParam(':username', $view_user['username']);
$stmt->bindParam(':email', $view_user['email']);
$stmt->execute();
$login_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Check for success messages
$success_message = '';
if (isset($_GET['created']) && $_GET['created'] == '1') {
    $success_message = 'User has been created successfully!';
}
if (isset($_GET['updated']) && $_GET['updated'] == '1') {
    $success_message = 'User has been updated successfully!';
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View User - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
        
        .user-avatar-large {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 32px;
            color: white;
        }
        
        .status-badge {
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 500;
            text-transform: uppercase;
        }
        
        .status-active {
            background-color: #dcfce7;
            color: #166534;
        }
        
        .status-inactive {
            background-color: #fef3c7;
            color: #92400e;
        }
        
        .status-suspended {
            background-color: #fecaca;
            color: #991b1b;
        }
        
        .info-label {
            font-weight: 600;
            color: #64748b;
            margin-bottom: 0.25rem;
        }
        
        .info-value {
            color: #1e293b;
            margin-bottom: 1rem;
        }
        
        .permission-badge {
            background-color: #f1f5f9;
            color: #475569;
            padding: 0.375rem 0.75rem;
            border-radius: 12px;
            font-size: 0.875rem;
            margin: 0.125rem;
            display: inline-block;
        }
        
        .activity-item {
            border-left: 3px solid #e2e8f0;
            padding-left: 1rem;
            margin-bottom: 1rem;
            position: relative;
        }
        
        .activity-item::before {
            content: '';
            position: absolute;
            left: -6px;
            top: 0.5rem;
            width: 8px;
            height: 8px;
            background-color: var(--primary-color);
            border-radius: 50%;
        }
        
        .profile-header {
            background: linear-gradient(135deg, var(--primary-color), #7c3aed);
            color: white;
            border-radius: 8px;
            padding: 2rem;
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
                    <h1><i class="bi bi-person-circle me-2"></i>User Profile</h1>
                    <p class="header-subtitle">View user details and activity history</p>
                </div>
                <div class="header-actions">
                    <?php if (hasPermission('manage_users', $permissions) || $current_user_id == $view_user['id']): ?>
                        <a href="edit.php?id=<?php echo $view_user['id']; ?>" class="btn btn-primary me-2">
                            <i class="bi bi-pencil me-1"></i>Edit Profile
                        </a>
                    <?php endif; ?>
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

            <!-- Profile Header -->
            <div class="profile-header mb-4">
                <div class="row align-items-center">
                    <div class="col-auto">
                        <div class="user-avatar-large" style="background-color: <?php echo '#' . substr(md5($view_user['username']), 0, 6); ?>">
                            <?php echo strtoupper(substr($view_user['first_name'] ?? $view_user['username'], 0, 1) . substr($view_user['last_name'] ?? $view_user['username'], -1, 1)); ?>
                        </div>
                    </div>
                    <div class="col">
                        <h2 class="mb-1">
                            <?php echo !empty(trim($view_user['first_name'] . ' ' . $view_user['last_name'])) 
                                ? htmlspecialchars(trim($view_user['first_name'] . ' ' . $view_user['last_name'])) 
                                : htmlspecialchars($view_user['username']); ?>
                        </h2>
                        <p class="mb-1 opacity-75">@<?php echo htmlspecialchars($view_user['username']); ?></p>
                        <div class="d-flex gap-3 align-items-center">
                            <span class="status-badge status-<?php echo $view_user['status']; ?>">
                                <?php echo ucfirst($view_user['status']); ?>
                            </span>
                            <span class="badge bg-light text-dark"><?php echo htmlspecialchars($view_user['role_name'] ?? 'No Role'); ?></span>
                            <?php if ($view_user['department']): ?>
                                <span class="opacity-75"><?php echo htmlspecialchars($view_user['department']); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <!-- Personal Information -->
                <div class="col-lg-8">
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-person-vcard me-2"></i>Personal Information
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="info-label">Username</div>
                                    <div class="info-value"><?php echo htmlspecialchars($view_user['username']); ?></div>

                                    <div class="info-label">Email Address</div>
                                    <div class="info-value">
                                        <?php echo htmlspecialchars($view_user['email']); ?>
                                        <?php if ($view_user['email_verified']): ?>
                                            <i class="bi bi-check-circle text-success" title="Email Verified"></i>
                                        <?php else: ?>
                                            <i class="bi bi-exclamation-triangle text-warning" title="Email Not Verified"></i>
                                        <?php endif; ?>
                                    </div>

                                    <div class="info-label">Phone Number</div>
                                    <div class="info-value"><?php echo htmlspecialchars($view_user['phone'] ?? 'Not provided'); ?></div>

                                    <div class="info-label">Date of Birth</div>
                                    <div class="info-value">
                                        <?php echo $view_user['date_of_birth'] ? date('M j, Y', strtotime($view_user['date_of_birth'])) : 'Not provided'; ?>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="info-label">First Name</div>
                                    <div class="info-value"><?php echo htmlspecialchars($view_user['first_name'] ?? 'Not provided'); ?></div>

                                    <div class="info-label">Last Name</div>
                                    <div class="info-value"><?php echo htmlspecialchars($view_user['last_name'] ?? 'Not provided'); ?></div>

                                    <div class="info-label">Address</div>
                                    <div class="info-value"><?php echo htmlspecialchars($view_user['address'] ?? 'Not provided'); ?></div>

                                    <div class="info-label">Member Since</div>
                                    <div class="info-value"><?php echo date('M j, Y', strtotime($view_user['created_at'])); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Work Information -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-briefcase me-2"></i>Work Information
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="info-label">Role</div>
                                    <div class="info-value">
                                        <?php echo htmlspecialchars($view_user['role_name'] ?? 'No Role'); ?>
                                        <?php if ($view_user['role_description']): ?>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($view_user['role_description']); ?></small>
                                        <?php endif; ?>
                                    </div>

                                    <div class="info-label">Department</div>
                                    <div class="info-value"><?php echo htmlspecialchars($view_user['department'] ?? 'Not assigned'); ?></div>

                                    <div class="info-label">Employee ID</div>
                                    <div class="info-value"><?php echo htmlspecialchars($view_user['employee_id'] ?? 'Not assigned'); ?></div>
                                </div>

                                <div class="col-md-6">
                                    <div class="info-label">Manager</div>
                                    <div class="info-value">
                                        <?php if ($view_user['manager_id']): ?>
                                            <?php echo !empty(trim($view_user['manager_name'])) 
                                                ? htmlspecialchars(trim($view_user['manager_name']))
                                                : htmlspecialchars($view_user['manager_username']); ?>
                                        <?php else: ?>
                                            No manager assigned
                                        <?php endif; ?>
                                    </div>

                                    <div class="info-label">Hire Date</div>
                                    <div class="info-value">
                                        <?php echo $view_user['hire_date'] ? date('M j, Y', strtotime($view_user['hire_date'])) : 'Not provided'; ?>
                                    </div>

                                    <div class="info-label">Account Status</div>
                                    <div class="info-value">
                                        <span class="status-badge status-<?php echo $view_user['status']; ?>">
                                            <?php echo ucfirst($view_user['status']); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Permissions -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-key me-2"></i>Permissions
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($user_permissions)): ?>
                                <p class="text-muted fst-italic">No permissions assigned to this role</p>
                            <?php else: ?>
                                <div>
                                    <?php foreach ($user_permissions as $permission): ?>
                                        <span class="permission-badge" title="<?php echo htmlspecialchars($permission['description']); ?>">
                                            <?php echo htmlspecialchars($permission['name']); ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Recent Activity -->
                    <?php if (hasPermission('manage_users', $permissions) || $current_user_id == $view_user_id): ?>
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-activity me-2"></i>Recent Activity
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($activities)): ?>
                                <p class="text-muted fst-italic">No recent activity</p>
                            <?php else: ?>
                                <div class="activity-timeline">
                                    <?php foreach ($activities as $activity): ?>
                                        <div class="activity-item">
                                            <div class="d-flex justify-content-between">
                                                <div>
                                                    <strong><?php echo htmlspecialchars($activity['action']); ?></strong>
                                                    <?php if ($activity['details']): ?>
                                                        <br><small class="text-muted"><?php echo htmlspecialchars($activity['details']); ?></small>
                                                    <?php endif; ?>
                                                </div>
                                                <small class="text-muted">
                                                    <?php echo date('M j, Y g:i A', strtotime($activity['created_at'])); ?>
                                                </small>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Sidebar -->
                <div class="col-lg-4">
                    <!-- Account Security -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-shield-lock me-2"></i>Account Security
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="info-label">Account Locked</div>
                            <div class="info-value">
                                <?php if ($view_user['account_locked']): ?>
                                    <span class="text-danger">
                                        <i class="bi bi-lock me-1"></i>Yes
                                        <?php if ($view_user['locked_until']): ?>
                                            <br><small>Until: <?php echo date('M j, Y g:i A', strtotime($view_user['locked_until'])); ?></small>
                                        <?php endif; ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-success">
                                        <i class="bi bi-unlock me-1"></i>No
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div class="info-label">Failed Login Attempts</div>
                            <div class="info-value"><?php echo $view_user['failed_login_attempts'] ?? 0; ?></div>

                            <div class="info-label">Last Login</div>
                            <div class="info-value">
                                <?php if ($view_user['last_login']): ?>
                                    <?php echo date('M j, Y g:i A', strtotime($view_user['last_login'])); ?>
                                <?php else: ?>
                                    Never
                                <?php endif; ?>
                            </div>

                            <div class="info-label">Total Logins</div>
                            <div class="info-value"><?php echo $view_user['login_count'] ?? 0; ?></div>
                        </div>
                    </div>

                    <!-- Login History -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-clock-history me-2"></i>Login History
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($login_history)): ?>
                                <p class="text-muted fst-italic">No login history</p>
                            <?php else: ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach (array_slice($login_history, 0, 5) as $login): ?>
                                        <div class="list-group-item border-0 px-0">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div>
                                                    <small class="fw-semibold">Successful Login</small>
                                                    <br>
                                                    <small class="text-muted">
                                                        IP: <?php echo htmlspecialchars($login['ip_address']); ?>
                                                    </small>
                                                </div>
                                                <small class="text-muted">
                                                    <?php echo date('M j, g:i A', strtotime($login['created_at'])); ?>
                                                </small>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
