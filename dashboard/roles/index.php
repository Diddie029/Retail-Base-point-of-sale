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
if (!hasPermission('manage_roles', $permissions)) {
    header("Location: ../../dashboard/dashboard.php");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get roles with permission count and user count
$sql = "
    SELECT r.*,
           COUNT(DISTINCT rp.permission_id) as permission_count,
           COUNT(DISTINCT u.id) as user_count
    FROM roles r
    LEFT JOIN role_permissions rp ON r.id = rp.role_id
    LEFT JOIN users u ON r.id = u.role_id
    GROUP BY r.id
    ORDER BY r.name
";

$stmt = $conn->query($sql);
$roles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total statistics
$stats = [];
$stmt = $conn->query("SELECT COUNT(*) as total FROM roles");
$stats['total_roles'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $conn->query("SELECT COUNT(*) as total FROM permissions");
$stats['total_permissions'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $conn->query("SELECT COUNT(*) as total FROM users WHERE role_id IS NOT NULL");
$stats['users_with_roles'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Role Management - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }

        .role-card {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            transition: all 0.2s;
            height: 100%;
        }

        .role-card:hover {
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            border-color: var(--primary-color);
        }

        .role-header {
            background: linear-gradient(135deg, var(--primary-color), #7c3aed);
            color: white;
            padding: 1.5rem;
            border-radius: 8px 8px 0 0;
        }

        .role-stats {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }

        .role-stat {
            background: rgba(255, 255, 255, 0.2);
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.875rem;
        }

        .permission-tags {
            max-height: 100px;
            overflow-y: auto;
        }

        .permission-tag {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
            margin: 0.125rem;
            background-color: #f1f5f9;
            color: #475569;
            border-radius: 12px;
            display: inline-block;
        }

        .stat-card {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border-radius: 8px;
            padding: 1.5rem;
            text-align: center;
        }

        .stat-icon {
            font-size: 2rem;
            margin-bottom: 1rem;
            opacity: 0.9;
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            line-height: 1;
        }

        .stat-label {
            font-size: 0.875rem;
            opacity: 0.9;
            margin-top: 0.5rem;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <?php
    $current_page = 'roles';
    include __DIR__ . '/../../include/navmenu.php';
    ?>

    <div class="main-content">
        <!-- Header -->
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1><i class="bi bi-shield-check me-2"></i>Role Management</h1>
                    <p class="header-subtitle">Manage user roles and permissions</p>
                </div>
                <div class="header-actions">
                    <a href="add.php" class="btn btn-primary">
                        <i class="bi bi-plus-circle me-1"></i>Add New Role
                    </a>
                    <a href="permissions.php" class="btn btn-outline-secondary">
                        <i class="bi bi-key me-1"></i>Manage Permissions
                    </a>
                </div>
            </div>
        </header>

        <!-- Content -->
        <main class="content">
            <!-- Statistics Row -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="bi bi-shield-check"></i>
                        </div>
                        <div class="stat-number"><?php echo $stats['total_roles']; ?></div>
                        <div class="stat-label">Total Roles</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card" style="background: linear-gradient(135deg, #f093fb, #f5576c);">
                        <div class="stat-icon">
                            <i class="bi bi-key"></i>
                        </div>
                        <div class="stat-number"><?php echo $stats['total_permissions']; ?></div>
                        <div class="stat-label">Total Permissions</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card" style="background: linear-gradient(135deg, #4facfe, #00f2fe);">
                        <div class="stat-icon">
                            <i class="bi bi-people"></i>
                        </div>
                        <div class="stat-number"><?php echo $stats['users_with_roles']; ?></div>
                        <div class="stat-label">Users with Roles</div>
                    </div>
                </div>
            </div>

            <!-- Roles Grid -->
            <div class="row">
                <?php if (empty($roles)): ?>
                <div class="col-12">
                    <div class="text-center py-5">
                        <i class="bi bi-shield-exclamation display-1 text-muted"></i>
                        <h4 class="mt-3 text-muted">No Roles Found</h4>
                        <p class="text-muted">Get started by creating your first role.</p>
                        <a href="add.php" class="btn btn-primary">
                            <i class="bi bi-plus-circle me-1"></i>Add New Role
                        </a>
                    </div>
                </div>
                <?php else: ?>
                <?php foreach ($roles as $role): ?>
                <div class="col-lg-4 col-md-6 mb-4">
                    <div class="role-card">
                        <div class="role-header">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="flex-grow-1">
                                    <h5 class="mb-0"><?php echo htmlspecialchars($role['name']); ?></h5>
                                    <?php if ($role['description']): ?>
                                        <small class="opacity-75"><?php echo htmlspecialchars($role['description']); ?></small>
                                    <?php endif; ?>
                                </div>
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-outline-light border-0" type="button" data-bs-toggle="dropdown">
                                        <i class="bi bi-three-dots"></i>
                                    </button>
                                    <ul class="dropdown-menu">
                                        <li><a class="dropdown-item" href="view.php?id=<?php echo $role['id']; ?>">
                                            <i class="bi bi-eye me-2"></i>View Details
                                        </a></li>
                                        <li><a class="dropdown-item" href="edit.php?id=<?php echo $role['id']; ?>">
                                            <i class="bi bi-pencil me-2"></i>Edit Role
                                        </a></li>
                                        <li><a class="dropdown-item" href="role-permissions.php?id=<?php echo $role['id']; ?>">
                                            <i class="bi bi-key me-2"></i>Manage Permissions
                                        </a></li>
                                        <?php if ($role['user_count'] == 0): ?>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><a class="dropdown-item text-danger" href="#" onclick="deleteRole(<?php echo $role['id']; ?>, '<?php echo addslashes($role['name']); ?>')">
                                            <i class="bi bi-trash me-2"></i>Delete Role
                                        </a></li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                            </div>

                            <div class="role-stats">
                                <div class="role-stat">
                                    <i class="bi bi-key me-1"></i>
                                    <?php echo $role['permission_count']; ?> Permissions
                                </div>
                                <div class="role-stat">
                                    <i class="bi bi-people me-1"></i>
                                    <?php echo $role['user_count']; ?> Users
                                </div>
                            </div>
                        </div>

                        <div class="card-body">
                            <div class="mb-3">
                                <small class="text-muted fw-semibold">RECENT PERMISSIONS</small>
                                <div class="permission-tags mt-2">
                                    <?php
                                    // Get some permissions for this role
                                    $perm_stmt = $conn->prepare("
                                        SELECT p.name 
                                        FROM permissions p 
                                        JOIN role_permissions rp ON p.id = rp.permission_id 
                                        WHERE rp.role_id = :role_id 
                                        ORDER BY p.name 
                                        LIMIT 6
                                    ");
                                    $perm_stmt->bindParam(':role_id', $role['id']);
                                    $perm_stmt->execute();
                                    $role_permissions = $perm_stmt->fetchAll(PDO::FETCH_COLUMN);
                                    
                                    if (empty($role_permissions)): ?>
                                        <span class="text-muted fst-italic">No permissions assigned</span>
                                    <?php else: ?>
                                        <?php foreach ($role_permissions as $permission): ?>
                                            <span class="permission-tag"><?php echo htmlspecialchars($permission); ?></span>
                                        <?php endforeach; ?>
                                        <?php if ($role['permission_count'] > 6): ?>
                                            <span class="permission-tag">+<?php echo ($role['permission_count'] - 6); ?> more</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="d-flex justify-content-between align-items-center">
                                <small class="text-muted">
                                    Created: <?php echo date('M j, Y', strtotime($role['created_at'])); ?>
                                </small>
                                <div class="btn-group btn-group-sm">
                                    <a href="view.php?id=<?php echo $role['id']; ?>" class="btn btn-outline-primary">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <a href="edit.php?id=<?php echo $role['id']; ?>" class="btn btn-outline-secondary">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteRoleModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-exclamation-triangle text-warning me-2"></i>
                        Confirm Deletion
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete the role <strong id="roleNameToDelete"></strong>?</p>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        This action cannot be undone. All permissions associated with this role will also be removed.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmDeleteBtn">
                        <i class="bi bi-trash me-1"></i>Delete Role
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let roleToDelete = null;

        function deleteRole(roleId, roleName) {
            roleToDelete = roleId;
            document.getElementById('roleNameToDelete').textContent = roleName;
            new bootstrap.Modal(document.getElementById('deleteRoleModal')).show();
        }

        document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
            if (roleToDelete) {
                fetch('../../api/roles/delete.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        role_id: roleToDelete
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
                    alert('An error occurred while deleting the role.');
                });
            }
        });
    </script>
</body>
</html>
