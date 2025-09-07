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

            <div class="row pb-5">
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
                                    <div class="info-label">User ID</div>
                                    <div class="info-value">
                                        <?php if (!empty($view_user['user_id'])): ?>
                                            <span class="badge bg-primary fs-6"><?php echo htmlspecialchars($view_user['user_id']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">Not assigned</span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="info-label">Username</div>
                                    <div class="info-value"><?php echo htmlspecialchars($view_user['username']); ?></div>

                                    <div class="info-label">Email Address</div>
                                    <div class="info-value">
                                        <?php echo htmlspecialchars($view_user['email']); ?>
                                        <?php if ($view_user['email_verified']): ?>
                                            <i class="bi bi-check-circle text-success" title="Email Verified"></i>
                                        <?php else: ?>
                                            <i class="bi bi-exclamation-triangle text-warning" title="Email Not Verified"></i>
                                            <button type="button" class="btn btn-sm btn-outline-primary ms-2" onclick="sendEmailVerification()">
                                                <i class="bi bi-envelope-check me-1"></i>Verify Email
                                            </button>
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
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-key me-2"></i>Permissions
                                    <span class="badge bg-info ms-2"><?php echo count($user_permissions); ?> permissions</span>
                                </h5>
                                <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#permissionsCollapse" aria-expanded="false" aria-controls="permissionsCollapse">
                                    <i class="bi bi-chevron-down" id="permissionsChevron"></i>
                                </button>
                            </div>
                        </div>
                        <div class="collapse" id="permissionsCollapse">
                            <div class="card-body">
                                <?php if (empty($user_permissions)): ?>
                                    <p class="text-muted fst-italic">No permissions assigned to this role</p>
                                <?php else: ?>
                                    <div class="permissions-grid" style="max-height: 300px; overflow-y: auto;">
                                        <?php foreach ($user_permissions as $permission): ?>
                                            <span class="permission-badge" title="<?php echo htmlspecialchars($permission['description']); ?>">
                                                <?php echo htmlspecialchars($permission['name']); ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    
                    <!-- Activities by User -->
                    <?php if (hasPermission('manage_users', $permissions) || hasPermission('view_users', $permissions) || hasPermission('view_user_activity_reports', $permissions) || hasPermission('audit_user_activities', $permissions) || $current_user_id == $view_user_id): ?>
                    <div class="card mt-4">
                        <div class="card-header">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-person-activity me-2"></i>Activities by User
                                    <span class="badge bg-primary ms-2"><?php echo $view_user['username']; ?></span>
                                </h5>
                                <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#userActivitiesCollapse" aria-expanded="false">
                                    <i class="bi bi-chevron-down" id="userActivitiesChevron"></i>
                                </button>
                            </div>
                        </div>
                        <div class="collapse" id="userActivitiesCollapse">
                            <div class="card-body">
                                <?php
                                // Get activities specifically by this user (not about this user)
                                $user_activities_stmt = $conn->prepare("
                                    SELECT al.action, al.details, al.created_at, al.user_id as actor_id,
                                           u.username as actor_username, u.first_name as actor_first_name, u.last_name as actor_last_name
                                    FROM activity_logs al
                                    LEFT JOIN users u ON al.user_id = u.id
                                    WHERE al.user_id = :user_id
                                    ORDER BY al.created_at DESC 
                                    LIMIT 50
                                ");
                                $user_activities_stmt->bindParam(':user_id', $view_user_id);
                                $user_activities_stmt->execute();
                                $user_activities = $user_activities_stmt->fetchAll(PDO::FETCH_ASSOC);
                                ?>
                                
                                <?php if (empty($user_activities)): ?>
                                    <p class="text-muted fst-italic">No activities performed by this user</p>
                                <?php else: ?>
                                    <div class="user-activities-timeline" style="max-height: 400px; overflow-y: auto;">
                                        <?php foreach ($user_activities as $activity): ?>
                                            <?php
                                            $details = json_decode($activity['details'] ?? '{}', true);
                                            $action = $activity['action'] ?? '';
                                            $is_id_change = strpos($action, 'User ID') !== false || strpos($action, 'Employee ID') !== false;
                                            
                                            // Safely get actor name with null checks
                                            $actor_first_name = $activity['actor_first_name'] ?? '';
                                            $actor_last_name = $activity['actor_last_name'] ?? '';
                                            $actor_username = $activity['actor_username'] ?? '';
                                            
                                            $actor_name = trim($actor_first_name . ' ' . $actor_last_name);
                                            if (empty($actor_name)) {
                                                $actor_name = $actor_username;
                                            }
                                            if (empty($actor_name)) {
                                                $actor_name = 'System';
                                            }
                                            ?>
                                            <div class="user-activity-item <?php echo $is_id_change ? 'border-start border-warning border-3' : ''; ?>">
                                                <div class="d-flex justify-content-between">
                                                    <div class="flex-grow-1">
                                                        <div class="d-flex align-items-center mb-1">
                                                            <strong class="<?php echo $is_id_change ? 'text-warning' : ''; ?>">
                                                                <?php echo htmlspecialchars($action ?: 'Unknown Action'); ?>
                                                            </strong>
                                                            <?php if ($is_id_change): ?>
                                                                <span class="badge bg-warning text-dark ms-2">ID Change</span>
                                                            <?php endif; ?>
                                                        </div>
                                                        
                                                        <?php if ($details && is_array($details)): ?>
                                                            <?php if (isset($details['old_value']) && isset($details['new_value'])): ?>
                                                                <div class="alert alert-info py-2 mb-2">
                                                                    <small>
                                                                        <strong>Changed:</strong> 
                                                                        <span class="text-danger"><?php echo htmlspecialchars($details['old_value'] ?? 'None'); ?></span> 
                                                                        → 
                                                                        <span class="text-success"><?php echo htmlspecialchars($details['new_value'] ?? ''); ?></span>
                                                                    </small>
                                                                </div>
                                                            <?php elseif (isset($details['generated_user_id']) || isset($details['generated_employee_id'])): ?>
                                                                <div class="alert alert-success py-2 mb-2">
                                                                    <small>
                                                                        <strong>Generated:</strong>
                                                                        <?php if (isset($details['generated_user_id'])): ?>
                                                                            User ID: <span class="text-success"><?php echo htmlspecialchars($details['generated_user_id'] ?? ''); ?></span>
                                                                        <?php endif; ?>
                                                                        <?php if (isset($details['generated_employee_id'])): ?>
                                                                            Employee ID: <span class="text-success"><?php echo htmlspecialchars($details['generated_employee_id'] ?? ''); ?></span>
                                                                        <?php endif; ?>
                                                                    </small>
                                                                </div>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                        
                                                        <div class="d-flex justify-content-between align-items-center">
                                                            <small class="text-muted">
                                                                <i class="bi bi-clock me-1"></i>
                                                                <?php echo $activity['created_at'] ? date('M j, Y g:i A', strtotime($activity['created_at'])) : 'Unknown Date'; ?>
                                                            </small>
                                                            <small class="text-muted">
                                                                <i class="bi bi-person me-1"></i>
                                                                <?php echo htmlspecialchars($actor_name ?? 'Unknown'); ?>
                                                            </small>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    
                                    <!-- User Activities Summary -->
                                    <div class="mt-3 p-3 bg-light rounded">
                                        <div class="row text-center">
                                            <div class="col-md-3">
                                                <div class="h5 mb-0 text-primary"><?php echo count($user_activities); ?></div>
                                                <small class="text-muted">Total Activities</small>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="h5 mb-0 text-warning">
                                                    <?php 
                                                    $id_changes = 0;
                                                    foreach ($user_activities as $activity) {
                                                        if (strpos($activity['action'], 'User ID') !== false || strpos($activity['action'], 'Employee ID') !== false) {
                                                            $id_changes++;
                                                        }
                                                    }
                                                    echo $id_changes;
                                                    ?>
                                                </div>
                                                <small class="text-muted">ID Changes</small>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="h5 mb-0 text-success">
                                                    <?php 
                                                    $generations = 0;
                                                    foreach ($user_activities as $activity) {
                                                        if (strpos($activity['action'], 'Generated') !== false) {
                                                            $generations++;
                                                        }
                                                    }
                                                    echo $generations;
                                                    ?>
                                                </div>
                                                <small class="text-muted">Generations</small>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="h5 mb-0 text-info">
                                                    <?php 
                                                    $updates = 0;
                                                    foreach ($user_activities as $activity) {
                                                        if (strpos($activity['action'], 'Updated') !== false) {
                                                            $updates++;
                                                        }
                                                    }
                                                    echo $updates;
                                                    ?>
                                                </div>
                                                <small class="text-muted">Updates</small>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
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

    <!-- Email Verification Modal -->
    <div class="modal fade" id="emailVerificationModal" tabindex="-1" aria-labelledby="emailVerificationModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="emailVerificationModalLabel">
                        <i class="bi bi-envelope-check me-2"></i>Email Verification
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-success" id="otpSentAlert" style="display: none;">
                        <i class="bi bi-check-circle me-2"></i>
                        <strong>Verification code sent successfully!</strong> Check your email for the 6-digit code.
                    </div>
                    <div class="alert alert-info" id="otpInfoAlert">
                        <i class="bi bi-info-circle me-2"></i>
                        We've sent a 6-digit verification code to <strong><?php echo htmlspecialchars($view_user['email']); ?></strong>. 
                        Please check your email and enter the code below.
                    </div>
                    <form id="emailVerificationForm">
                        <div class="mb-3">
                            <label for="otpCode" class="form-label">Verification Code</label>
                            <input type="text" class="form-control text-center" id="otpCode" name="otp_code" 
                                   maxlength="6" placeholder="000000" required>
                            <div class="form-text">Enter the 6-digit code sent to your email</div>
                        </div>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-muted small">Didn't receive the code?</span>
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="resendVerificationCode()">
                                    <i class="bi bi-arrow-clockwise me-1"></i>Resend Code
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="verifyEmailCode()">
                        <i class="bi bi-check-circle me-1"></i>Verify Email
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <style>
    
    .permissions-grid {
        max-height: 300px;
        overflow-y: auto;
        scroll-behavior: smooth;
    }
    
    .permissions-grid::-webkit-scrollbar {
        width: 6px;
    }
    
    .permissions-grid::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 3px;
    }
    
    .permissions-grid::-webkit-scrollbar-thumb {
        background: #c1c1c1;
        border-radius: 3px;
    }
    
    .permissions-grid::-webkit-scrollbar-thumb:hover {
        background: #a8a8a8;
    }
    
    .user-activities-timeline {
        max-height: 400px;
        overflow-y: auto;
        scroll-behavior: smooth;
    }
    
    .user-activities-timeline::-webkit-scrollbar {
        width: 6px;
    }
    
    .user-activities-timeline::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 3px;
    }
    
    .user-activities-timeline::-webkit-scrollbar-thumb {
        background: #c1c1c1;
        border-radius: 3px;
    }
    
    .user-activities-timeline::-webkit-scrollbar-thumb:hover {
        background: #a8a8a8;
    }
    
    .user-activity-item {
        border-left: 3px solid transparent;
        padding-left: 15px;
        margin-bottom: 15px;
        border-bottom: 1px solid #f0f0f0;
        padding-bottom: 10px;
    }
    
    .user-activity-item:last-child {
        border-bottom: none;
        margin-bottom: 0;
    }
    </style>
    
    <script>
    // Handle collapse chevron rotation
    document.addEventListener('DOMContentLoaded', function() {
        // Permissions collapse
        const permissionsCollapse = document.getElementById('permissionsCollapse');
        const permissionsChevron = document.getElementById('permissionsChevron');
        
        if (permissionsCollapse && permissionsChevron) {
            permissionsCollapse.addEventListener('show.bs.collapse', function() {
                permissionsChevron.classList.remove('bi-chevron-down');
                permissionsChevron.classList.add('bi-chevron-up');
            });
            
            permissionsCollapse.addEventListener('hide.bs.collapse', function() {
                permissionsChevron.classList.remove('bi-chevron-up');
                permissionsChevron.classList.add('bi-chevron-down');
            });
        }
        
        // User Activities collapse
        const userActivitiesCollapse = document.getElementById('userActivitiesCollapse');
        const userActivitiesChevron = document.getElementById('userActivitiesChevron');
        
        if (userActivitiesCollapse && userActivitiesChevron) {
            userActivitiesCollapse.addEventListener('show.bs.collapse', function() {
                userActivitiesChevron.classList.remove('bi-chevron-down');
                userActivitiesChevron.classList.add('bi-chevron-up');
            });
            
            userActivitiesCollapse.addEventListener('hide.bs.collapse', function() {
                userActivitiesChevron.classList.remove('bi-chevron-up');
                userActivitiesChevron.classList.add('bi-chevron-down');
            });
        }

        // Email verification functions
        function sendEmailVerification() {
            const button = document.querySelector('button[onclick="sendEmailVerification()"]');
            const originalText = button.innerHTML;
            button.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Sending...';
            button.disabled = true;

            fetch('../../api/users/send_email_verification.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    user_id: <?php echo $view_user_id; ?>
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Show verification modal
                    const modal = new bootstrap.Modal(document.getElementById('emailVerificationModal'));
                    modal.show();
                    
                    // Show success alert and hide info alert
                    document.getElementById('otpSentAlert').style.display = 'block';
                    document.getElementById('otpInfoAlert').style.display = 'none';
                    
                    // Clear any previous OTP input
                    document.getElementById('otpCode').value = '';
                } else {
                    if (data.error_type === 'smtp_not_configured') {
                        // Show detailed SMTP configuration notice
                        showSMTPNotice(data.message, data.missing_fields);
                    } else {
                        alert('Error: ' + (data.message || 'Failed to send verification email'));
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while sending verification email');
            })
            .finally(() => {
                button.innerHTML = originalText;
                button.disabled = false;
            });
        }

        function showSMTPNotice(message, missingFields) {
            // Create a more detailed notice for SMTP configuration
            const noticeHtml = `
                <div class="alert alert-warning alert-dismissible fade show" role="alert">
                    <div class="d-flex align-items-start">
                        <i class="bi bi-exclamation-triangle-fill me-3 mt-1" style="font-size: 1.2rem;"></i>
                        <div class="flex-grow-1">
                            <h6 class="alert-heading mb-2">Email Configuration Required</h6>
                            <p class="mb-2">${message}</p>
                            <div class="mb-2">
                                <strong>Missing Configuration:</strong>
                                <ul class="mb-0 mt-1">
                                    ${missingFields.map(field => `<li>${field.replace('smtp_', '').replace('_', ' ').toUpperCase()}</li>`).join('')}
                                </ul>
                            </div>
                            <div class="d-flex gap-2">
                                <a href="../../admin/settings/adminsetting.php?tab=email" class="btn btn-sm btn-warning">
                                    <i class="bi bi-gear me-1"></i>Configure Email Settings
                                </a>
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="alert">
                                    <i class="bi bi-x me-1"></i>Close
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Insert notice at the top of the content area
            const contentArea = document.querySelector('.content');
            if (contentArea) {
                contentArea.insertAdjacentHTML('afterbegin', noticeHtml);
                
                // Auto-dismiss after 10 seconds
                setTimeout(() => {
                    const alert = contentArea.querySelector('.alert-warning');
                    if (alert) {
                        const bsAlert = new bootstrap.Alert(alert);
                        bsAlert.close();
                    }
                }, 10000);
            } else {
                // Fallback to alert if content area not found
                alert(message + '\n\nPlease configure email settings in Admin → Settings → Email Settings.');
            }
        }

        function verifyEmailCode() {
            const otpCode = document.getElementById('otpCode').value;
            
            if (!otpCode || otpCode.length !== 6) {
                alert('Please enter a valid 6-digit verification code');
                return;
            }

            const button = document.querySelector('button[onclick="verifyEmailCode()"]');
            const originalText = button.innerHTML;
            button.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Verifying...';
            button.disabled = true;

            fetch('../../api/users/verify_email_otp.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    user_id: <?php echo $view_user_id; ?>,
                    otp_code: otpCode
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Email verified successfully!');
                    // Close modal and reload page
                    const modal = bootstrap.Modal.getInstance(document.getElementById('emailVerificationModal'));
                    modal.hide();
                    location.reload();
                } else {
                    alert('Error: ' + (data.message || 'Invalid verification code'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while verifying email');
            })
            .finally(() => {
                button.innerHTML = originalText;
                button.disabled = false;
            });
        }

        function resendVerificationCode() {
            const button = document.querySelector('button[onclick="resendVerificationCode()"]');
            const originalText = button.innerHTML;
            button.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Sending...';
            button.disabled = true;

            fetch('../../api/users/send_email_verification.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    user_id: <?php echo $view_user_id; ?>
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Show success alert
                    document.getElementById('otpSentAlert').style.display = 'block';
                    document.getElementById('otpInfoAlert').style.display = 'none';
                    
                    // Clear OTP input
                    document.getElementById('otpCode').value = '';
                } else {
                    if (data.error_type === 'smtp_not_configured') {
                        showSMTPNotice(data.message, data.missing_fields);
                    } else {
                        alert('Error: ' + (data.message || 'Failed to resend verification code'));
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while resending verification code');
            })
            .finally(() => {
                button.innerHTML = originalText;
                button.disabled = false;
            });
        }

        // Auto-format OTP input
        document.getElementById('otpCode').addEventListener('input', function(e) {
            // Only allow numbers
            e.target.value = e.target.value.replace(/[^0-9]/g, '');
        });

        // Check SMTP configuration status on page load
        checkSMTPStatus();

        // Reset modal alerts when modal is shown
        const emailModal = document.getElementById('emailVerificationModal');
        if (emailModal) {
            emailModal.addEventListener('show.bs.modal', function() {
                document.getElementById('otpSentAlert').style.display = 'none';
                document.getElementById('otpInfoAlert').style.display = 'block';
                document.getElementById('otpCode').value = '';
            });
        }
    });

    function checkSMTPStatus() {
        fetch('../../api/users/check_smtp_status.php', {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
            }
        })
        .then(response => response.json())
        .then(data => {
            const verifyButton = document.querySelector('button[onclick="sendEmailVerification()"]');
            if (verifyButton) {
                if (data.smtp_configured) {
                    verifyButton.innerHTML = '<i class="bi bi-envelope-check me-1"></i>Verify Email';
                    verifyButton.classList.remove('btn-outline-secondary');
                    verifyButton.classList.add('btn-outline-primary');
                    verifyButton.title = 'Click to send verification email';
                } else {
                    verifyButton.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i>Configure Email First';
                    verifyButton.classList.remove('btn-outline-primary');
                    verifyButton.classList.add('btn-outline-secondary');
                    verifyButton.title = 'SMTP not configured. Click to see configuration details.';
                }
            }
        })
        .catch(error => {
            console.error('Error checking SMTP status:', error);
        });
    }
    
    </script>
</body>
</html>
