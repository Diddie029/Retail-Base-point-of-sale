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
if (!hasPermission('manage_roles', $permissions) && !hasPermission('view_roles', $permissions)) {
    header("Location: ../../dashboard/dashboard.php");
    exit();
}

// Get role ID from URL
$view_role_id = intval($_GET['id'] ?? 0);
if (!$view_role_id) {
    header("Location: index.php");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get role details
$stmt = $conn->prepare("
    SELECT r.*, 
           COUNT(DISTINCT rp.permission_id) as permission_count,
           COUNT(DISTINCT u.id) as user_count
    FROM roles r
    LEFT JOIN role_permissions rp ON r.id = rp.role_id
    LEFT JOIN users u ON r.id = u.role_id
    WHERE r.id = :role_id
    GROUP BY r.id
");
$stmt->bindParam(':role_id', $view_role_id);
$stmt->execute();
$role = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$role) {
    header("Location: index.php");
    exit();
}

// Get role permissions grouped by category
$stmt = $conn->prepare("
    SELECT p.id, p.name, p.description, 
           COALESCE(p.category, 'General') as category
    FROM permissions p
    JOIN role_permissions rp ON p.id = rp.permission_id
    WHERE rp.role_id = :role_id
    ORDER BY COALESCE(p.category, 'General'), p.name
");
$stmt->bindParam(':role_id', $view_role_id);
$stmt->execute();
$role_permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group permissions by category
$grouped_permissions = [];
foreach ($role_permissions as $permission) {
    $category = $permission['category'] ?: 'General';
    $grouped_permissions[$category][] = $permission;
}

// Get users with this role
$stmt = $conn->prepare("
    SELECT id, username, first_name, last_name, email, status, created_at, last_login
    FROM users 
    WHERE role_id = :role_id 
    ORDER BY first_name, last_name, username
");
$stmt->bindParam(':role_id', $view_role_id);
$stmt->execute();
$role_users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Check for success messages
$success_message = '';
if (isset($_GET['created']) && $_GET['created'] == '1') {
    $success_message = 'Role has been created successfully!';
}
if (isset($_GET['updated']) && $_GET['updated'] == '1') {
    $success_message = 'Role has been updated successfully!';
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($role['name']); ?> - Role Details - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
        
        .role-header {
            background: linear-gradient(135deg, var(--primary-color), #8b5cf6);
            color: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
        }
        
        .role-header::before {
            content: '';
            position: absolute;
            top: 0;
            right: -50px;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
        }
        
        .role-icon {
            width: 80px;
            height: 80px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            margin-bottom: 1rem;
        }
        
        .info-card {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            margin-bottom: 2rem;
            border: 1px solid #e2e8f0;
        }
        
        .info-card:hover {
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        
        .stat-box {
            text-align: center;
            padding: 1.5rem;
            background: linear-gradient(135deg, #f8fafc, #e2e8f0);
            border-radius: 12px;
            border: 1px solid #cbd5e1;
        }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            color: #64748b;
            font-weight: 500;
        }
        
        .permission-category {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            margin-bottom: 1rem;
            overflow: hidden;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        
        .permission-category:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.12);
        }
        
        .category-header {
            background: linear-gradient(135deg, #f8fafc, #e2e8f0);
            padding: 1.2rem 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            cursor: pointer;
            transition: all 0.3s ease;
            user-select: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .category-header:hover {
            background: linear-gradient(135deg, #f1f5f9, #e2e8f0);
        }
        
        .category-header.expanded {
            background: linear-gradient(135deg, var(--primary-color), #8b5cf6);
            color: white;
        }
        
        .category-header.expanded:hover {
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
        }
        
        .category-title {
            font-weight: 600;
            color: #1e293b;
            margin: 0;
            flex-grow: 1;
            transition: color 0.3s ease;
        }
        
        .category-header.expanded .category-title {
            color: white;
        }
        
        .category-stats {
            font-size: 0.875rem;
            color: #64748b;
            transition: color 0.3s ease;
        }
        
        .category-header.expanded .category-stats {
            color: rgba(255, 255, 255, 0.8);
        }
        
        .category-toggle {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-left: auto;
            transition: all 0.3s ease;
        }
        
        .category-header.expanded .category-toggle {
            background: rgba(255, 255, 255, 0.2);
            transform: rotate(180deg);
        }
        
        .category-toggle i {
            font-size: 0.875rem;
            color: #64748b;
            transition: color 0.3s ease;
        }
        
        .category-header.expanded .category-toggle i {
            color: white;
        }
        
        .category-icon {
            width: 24px;
            height: 24px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.875rem;
            color: white;
        }
        
        .permission-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
        }
        
        .permission-item {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 1rem;
            transition: all 0.3s ease;
        }
        
        .permission-item:hover {
            border-color: var(--primary-color);
            box-shadow: 0 2px 8px rgba(99, 102, 241, 0.1);
            transform: translateY(-1px);
        }
        
        .permission-name {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .permission-desc {
            color: #64748b;
            font-size: 0.875rem;
            line-height: 1.4;
        }
        
        .user-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 1rem;
            transition: all 0.3s ease;
        }
        
        .user-card:hover {
            border-color: var(--primary-color);
            box-shadow: 0 2px 8px rgba(99, 102, 241, 0.1);
            transform: translateY(-1px);
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            background: var(--primary-color);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            margin-right: 1rem;
        }
        
        .status-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.75rem;
            border-radius: 50px;
            font-weight: 500;
        }
        
        .status-active { background-color: #dcfce7; color: #166534; }
        .status-inactive { background-color: #f3f4f6; color: #374151; }
        .status-suspended { background-color: #fee2e2; color: #dc2626; }
        
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
                    <h1><i class="bi bi-shield-check me-2"></i>Role Details</h1>
                    <p class="header-subtitle">View and manage role information</p>
                </div>
                <div class="header-actions">
                    <?php if (hasPermission('manage_roles', $permissions)): ?>
                        <a href="edit.php?id=<?php echo $role['id']; ?>" class="btn btn-primary me-2">
                            <i class="bi bi-pencil me-1"></i>Edit Role
                        </a>
                    <?php endif; ?>
                    <a href="index.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-1"></i>Back to Roles
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

            <!-- Role Header -->
            <div class="role-header">
                <div class="row align-items-center">
                    <div class="col-auto">
                        <div class="role-icon">
                            <i class="bi bi-shield-check"></i>
                        </div>
                    </div>
                    <div class="col">
                        <h2 class="mb-1"><?php echo htmlspecialchars($role['name']); ?></h2>
                        <?php if ($role['description']): ?>
                        <p class="mb-3 opacity-75"><?php echo htmlspecialchars($role['description']); ?></p>
                        <?php endif; ?>
                        
                        <div class="row">
                            <div class="col-auto">
                                <div class="stat-box" style="background: rgba(255, 255, 255, 0.15); border: 1px solid rgba(255, 255, 255, 0.2);">
                                    <div class="stat-number" style="color: white;"><?php echo $role['permission_count']; ?></div>
                                    <div class="stat-label" style="color: rgba(255, 255, 255, 0.8);">Permissions</div>
                                </div>
                            </div>
                            <div class="col-auto">
                                <div class="stat-box" style="background: rgba(255, 255, 255, 0.15); border: 1px solid rgba(255, 255, 255, 0.2);">
                                    <div class="stat-number" style="color: white;"><?php echo $role['user_count']; ?></div>
                                    <div class="stat-label" style="color: rgba(255, 255, 255, 0.8);">Users</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-auto">
                        <small class="opacity-75">
                            Created: <?php echo date('M j, Y', strtotime($role['created_at'])); ?>
                            <?php if ($role['updated_at'] && $role['updated_at'] !== $role['created_at']): ?>
                                <br>Updated: <?php echo date('M j, Y', strtotime($role['updated_at'])); ?>
                            <?php endif; ?>
                        </small>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-lg-8">
                    <!-- Permissions Section -->
                    <div class="info-card">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h5 class="mb-0">
                                <i class="bi bi-key me-2"></i>Permissions
                                <span class="badge bg-primary ms-2"><?php echo count($role_permissions); ?></span>
                            </h5>
                            <?php if (hasPermission('manage_roles', $permissions)): ?>
                            <a href="edit.php?id=<?php echo $role['id']; ?>" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-pencil me-1"></i>Edit Permissions
                            </a>
                            <?php endif; ?>
                        </div>

                        <?php if (empty($role_permissions)): ?>
                            <div class="text-center py-4">
                                <i class="bi bi-key-fill display-4 text-muted"></i>
                                <h5 class="mt-3 text-muted">No Permissions Assigned</h5>
                                <p class="text-muted">This role doesn't have any permissions yet.</p>
                                <?php if (hasPermission('manage_roles', $permissions)): ?>
                                <a href="edit.php?id=<?php echo $role['id']; ?>" class="btn btn-primary">
                                    <i class="bi bi-plus-circle me-1"></i>Add Permissions
                                </a>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="d-flex justify-content-end mb-3">
                                <div class="btn-group btn-group-sm" role="group">
                                    <button type="button" class="btn btn-outline-primary" onclick="expandAllCategories()" title="Expand All">
                                        <i class="bi bi-arrows-expand"></i>
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" onclick="collapseAllCategories()" title="Collapse All">
                                        <i class="bi bi-arrows-collapse"></i>
                                    </button>
                                </div>
                            </div>
                            <?php $category_index = 0; ?>
                            <?php foreach ($grouped_permissions as $category => $category_permissions): ?>
                                <?php $category_id = 'view-category-' . $category_index; ?>
                                <div class="permission-category category-collapsed" id="<?php echo $category_id; ?>">
                                    <div class="category-header" onclick="toggleCategory('<?php echo $category_id; ?>')">
                                        <div class="d-flex align-items-center w-100">
                                            <span class="category-icon" style="background: <?php echo '#' . substr(md5($category), 0, 6); ?>;">
                                                <?php echo strtoupper(substr($category, 0, 1)); ?>
                                            </span>
                                            <div class="flex-grow-1">
                                                <h6 class="category-title"><?php echo htmlspecialchars($category); ?></h6>
                                                <small class="category-stats">
                                                    <?php echo count($category_permissions); ?> permissions assigned
                                                </small>
                                            </div>
                                            <div class="category-toggle">
                                                <i class="bi bi-chevron-down"></i>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="permission-grid" style="padding: 2rem; background: #f8fafc; border-top: 2px solid var(--primary-color); display: none;">
                                        <?php foreach ($category_permissions as $permission): ?>
                                        <div class="permission-item">
                                            <div class="permission-name">
                                                <i class="bi bi-check-circle text-success"></i>
                                                <?php echo htmlspecialchars($permission['name']); ?>
                                            </div>
                                            <?php if ($permission['description']): ?>
                                            <div class="permission-desc"><?php echo htmlspecialchars($permission['description']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php $category_index++; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="col-lg-4">
                    <!-- Users with this Role -->
                    <div class="info-card">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h5 class="mb-0">
                                <i class="bi bi-people me-2"></i>Users
                                <span class="badge bg-primary ms-2"><?php echo count($role_users); ?></span>
                            </h5>
                        </div>

                        <?php if (empty($role_users)): ?>
                            <div class="text-center py-3">
                                <i class="bi bi-people display-6 text-muted"></i>
                                <p class="mt-2 text-muted mb-0">No users assigned to this role</p>
                            </div>
                        <?php else: ?>
                            <div class="d-flex flex-column gap-3">
                                <?php foreach ($role_users as $role_user): ?>
                                <div class="user-card">
                                    <div class="d-flex align-items-center">
                                        <div class="user-avatar">
                                            <?php 
                                            $user_name = trim($role_user['first_name'] . ' ' . $role_user['last_name']);
                                            echo strtoupper(substr($user_name ?: $role_user['username'], 0, 1));
                                            ?>
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="fw-semibold">
                                                <?php echo htmlspecialchars($user_name ?: $role_user['username']); ?>
                                            </div>
                                            <div class="small text-muted">@<?php echo htmlspecialchars($role_user['username']); ?></div>
                                        </div>
                                        <div class="text-end">
                                            <span class="status-badge status-<?php echo $role_user['status']; ?>">
                                                <?php echo ucfirst($role_user['status']); ?>
                                            </span>
                                            <?php if (hasPermission('manage_users', $permissions)): ?>
                                            <div class="mt-1">
                                                <a href="../users/view.php?id=<?php echo $role_user['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>

                            <?php if (count($role_users) > 5): ?>
                            <div class="text-center mt-3">
                                <a href="../users/index.php?role=<?php echo $role['id']; ?>" class="btn btn-sm btn-outline-primary">
                                    View All Users with this Role
                                </a>
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <!-- Role Actions -->
                    <?php if (hasPermission('manage_roles', $permissions)): ?>
                    <div class="info-card">
                        <h6><i class="bi bi-gear me-2"></i>Role Actions</h6>
                        <div class="d-grid gap-2">
                            <a href="edit.php?id=<?php echo $role['id']; ?>" class="btn btn-outline-primary">
                                <i class="bi bi-pencil me-2"></i>Edit Role
                            </a>
                            <?php if ($role['user_count'] == 0): ?>
                            <button type="button" class="btn btn-outline-danger" onclick="deleteRole(<?php echo $role['id']; ?>, '<?php echo addslashes($role['name']); ?>')">
                                <i class="bi bi-trash me-2"></i>Delete Role
                            </button>
                            <?php else: ?>
                            <button type="button" class="btn btn-outline-secondary" disabled title="Cannot delete role with assigned users">
                                <i class="bi bi-trash me-2"></i>Delete Role
                            </button>
                            <small class="text-muted">Remove all users from this role before deleting</small>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
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
                        window.location.href = 'index.php';
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

        // Collapsible categories functionality
        function toggleCategory(categoryId) {
            const category = document.getElementById(categoryId);
            const header = category.querySelector('.category-header');
            const content = category.querySelector('.permission-grid');
            const toggle = header.querySelector('.category-toggle');
            
            if (content.style.display === 'none' || content.style.display === '') {
                // Expand category
                content.style.display = 'grid';
                header.classList.add('expanded');
                category.classList.remove('category-collapsed');
                category.classList.add('category-expanded');
            } else {
                // Collapse category
                content.style.display = 'none';
                header.classList.remove('expanded');
                category.classList.remove('category-expanded');
                category.classList.add('category-collapsed');
            }
        }

        function expandAllCategories() {
            const categories = document.querySelectorAll('.permission-category');
            categories.forEach(category => {
                const header = category.querySelector('.category-header');
                const content = category.querySelector('.permission-grid');
                
                content.style.display = 'grid';
                header.classList.add('expanded');
                category.classList.remove('category-collapsed');
                category.classList.add('category-expanded');
            });
        }

        function collapseAllCategories() {
            const categories = document.querySelectorAll('.permission-category');
            categories.forEach(category => {
                const header = category.querySelector('.category-header');
                const content = category.querySelector('.permission-grid');
                
                content.style.display = 'none';
                header.classList.remove('expanded');
                category.classList.remove('category-expanded');
                category.classList.add('category-collapsed');
            });
        }
    </script>
</body>
</html>
