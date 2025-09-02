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

// Handle search and filter
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$role_filter = $_GET['role'] ?? '';
$department_filter = $_GET['department'] ?? '';
$sort = $_GET['sort'] ?? 'created_at';
$order = $_GET['order'] ?? 'DESC';

// Build WHERE clause
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(u.first_name LIKE :search OR u.last_name LIKE :search OR u.username LIKE :search OR u.email LIKE :search OR u.employee_id LIKE :search)";
    $params['search'] = "%$search%";
}

if (!empty($status_filter)) {
    $where_conditions[] = "u.status = :status";
    $params['status'] = $status_filter;
}

if (!empty($role_filter)) {
    $where_conditions[] = "r.id = :role_id";
    $params['role_id'] = $role_filter;
}

if (!empty($department_filter)) {
    $where_conditions[] = "u.department = :department";
    $params['department'] = $department_filter;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Pagination
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Get total count
$count_sql = "
    SELECT COUNT(*) as total
    FROM users u
    LEFT JOIN roles r ON u.role_id = r.id
    $where_clause
";

$count_stmt = $conn->prepare($count_sql);
foreach ($params as $key => $value) {
    $count_stmt->bindValue(":$key", $value);
}
$count_stmt->execute();
$total_users = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_users / $per_page);

// Get users
$sql = "
    SELECT u.*, r.name as role_name,
           CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) as full_name,
           m.username as manager_name
    FROM users u
    LEFT JOIN roles r ON u.role_id = r.id
    LEFT JOIN users m ON u.manager_id = m.id
    $where_clause
    ORDER BY $sort $order
    LIMIT :limit OFFSET :offset
";

$stmt = $conn->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue(":$key", $value);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get roles for filter
$roles = [];
$stmt = $conn->query("SELECT id, name FROM roles ORDER BY name");
$roles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get departments for filter
$departments = [];
$stmt = $conn->query("SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department != '' ORDER BY department");
$departments = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Get statistics
$stats = [];
$stmt = $conn->query("SELECT COUNT(*) as total FROM users");
$stats['total'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $conn->query("SELECT COUNT(*) as active FROM users WHERE status = 'active'");
$stats['active'] = $stmt->fetch(PDO::FETCH_ASSOC)['active'];

$stmt = $conn->query("SELECT COUNT(*) as inactive FROM users WHERE status = 'inactive'");
$stats['inactive'] = $stmt->fetch(PDO::FETCH_ASSOC)['inactive'];

$stmt = $conn->query("SELECT COUNT(*) as suspended FROM users WHERE status = 'suspended'");
$stats['suspended'] = $stmt->fetch(PDO::FETCH_ASSOC)['suspended'];

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
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
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 14px;
            color: white;
        }
        
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
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
        
        .user-card {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            transition: all 0.2s;
        }
        
        .user-card:hover {
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            border-color: var(--primary-color);
        }
        
        .filter-section {
            background-color: #f8fafc;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
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
                    <h1><i class="bi bi-people-fill me-2"></i>User Management</h1>
                    <p class="header-subtitle">Manage system users, roles, and permissions</p>
                </div>
                <div class="header-actions">
                    <a href="add.php" class="btn btn-primary">
                        <i class="bi bi-plus-circle me-1"></i>Add New User
                    </a>
                </div>
            </div>
        </header>

        <!-- Content -->
        <main class="content">
            <!-- Statistics Cards -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <div class="display-6 text-primary mb-2"><?php echo $stats['total']; ?></div>
                            <h6 class="card-title">Total Users</h6>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <div class="display-6 text-success mb-2"><?php echo $stats['active']; ?></div>
                            <h6 class="card-title">Active Users</h6>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <div class="display-6 text-warning mb-2"><?php echo $stats['inactive']; ?></div>
                            <h6 class="card-title">Inactive Users</h6>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <div class="display-6 text-danger mb-2"><?php echo $stats['suspended']; ?></div>
                            <h6 class="card-title">Suspended Users</h6>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filter-section">
                <form method="GET" action="" class="row g-3">
                    <div class="col-md-3">
                        <input type="text" class="form-control" name="search" placeholder="Search users..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="col-md-2">
                        <select class="form-select" name="status">
                            <option value="">All Status</option>
                            <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            <option value="suspended" <?php echo $status_filter === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select class="form-select" name="role">
                            <option value="">All Roles</option>
                            <?php foreach ($roles as $role): ?>
                                <option value="<?php echo $role['id']; ?>" <?php echo $role_filter == $role['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($role['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select class="form-select" name="department">
                            <option value="">All Departments</option>
                            <?php foreach ($departments as $department): ?>
                                <option value="<?php echo htmlspecialchars($department); ?>" <?php echo $department_filter === $department ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($department); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select class="form-select" name="sort">
                            <option value="created_at" <?php echo $sort === 'created_at' ? 'selected' : ''; ?>>Created Date</option>
                            <option value="first_name" <?php echo $sort === 'first_name' ? 'selected' : ''; ?>>Name</option>
                            <option value="username" <?php echo $sort === 'username' ? 'selected' : ''; ?>>Username</option>
                            <option value="email" <?php echo $sort === 'email' ? 'selected' : ''; ?>>Email</option>
                            <option value="status" <?php echo $sort === 'status' ? 'selected' : ''; ?>>Status</option>
                            <option value="last_login" <?php echo $sort === 'last_login' ? 'selected' : ''; ?>>Last Login</option>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-search"></i>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Users Table -->
            <div class="card">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>User</th>
                                    <th>Contact Info</th>
                                    <th>Role</th>
                                    <th>Department</th>
                                    <th>Status</th>
                                    <th>Last Login</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($users)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">
                                        <i class="bi bi-people display-1 d-block mb-2"></i>
                                        No users found
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($users as $user): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="user-avatar me-3" style="background-color: <?php echo '#' . substr(md5($user['username']), 0, 6); ?>">
                                                <?php echo strtoupper(substr($user['first_name'] ?? $user['username'], 0, 1) . substr($user['last_name'] ?? $user['username'], -1, 1)); ?>
                                            </div>
                                            <div>
                                                <div class="fw-semibold">
                                                    <?php echo !empty(trim($user['full_name'])) ? htmlspecialchars(trim($user['full_name'])) : htmlspecialchars($user['username']); ?>
                                                </div>
                                                <small class="text-muted">@<?php echo htmlspecialchars($user['username']); ?></small>
                                                <?php if ($user['employee_id']): ?>
                                                    <small class="text-muted d-block">ID: <?php echo htmlspecialchars($user['employee_id']); ?></small>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div><?php echo htmlspecialchars($user['email']); ?></div>
                                        <?php if ($user['phone']): ?>
                                            <small class="text-muted"><?php echo htmlspecialchars($user['phone']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary"><?php echo htmlspecialchars($user['role_name'] ?? 'No Role'); ?></span>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($user['department'] ?? '-'); ?>
                                        <?php if ($user['manager_name']): ?>
                                            <small class="text-muted d-block">Manager: <?php echo htmlspecialchars($user['manager_name']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $user['status']; ?>">
                                            <?php echo ucfirst($user['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($user['last_login']): ?>
                                            <div><?php echo date('M j, Y', strtotime($user['last_login'])); ?></div>
                                            <small class="text-muted"><?php echo date('g:i A', strtotime($user['last_login'])); ?></small>
                                        <?php else: ?>
                                            <small class="text-muted">Never</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="view.php?id=<?php echo $user['id']; ?>" class="btn btn-outline-primary" title="View">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <a href="edit.php?id=<?php echo $user['id']; ?>" class="btn btn-outline-secondary" title="Edit">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                            <button type="button" class="btn btn-outline-warning" title="Toggle Status" 
                                                    onclick="toggleUserStatus(<?php echo $user['id']; ?>, '<?php echo $user['status']; ?>')">
                                                <i class="bi bi-power"></i>
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <nav aria-label="Users pagination" class="mt-4">
                <ul class="pagination justify-content-center">
                    <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">Previous</a>
                        </li>
                    <?php endif; ?>
                    
                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                        <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                        </li>
                    <?php endfor; ?>
                    
                    <?php if ($page < $total_pages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">Next</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
            <?php endif; ?>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleUserStatus(userId, currentStatus) {
            const newStatus = currentStatus === 'active' ? 'inactive' : 'active';
            
            if (confirm(`Are you sure you want to ${newStatus === 'active' ? 'activate' : 'deactivate'} this user?`)) {
                fetch('../../api/users/toggle_status.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        user_id: userId,
                        status: newStatus
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while updating user status.');
                });
            }
        }
    </script>
</body>
</html>
