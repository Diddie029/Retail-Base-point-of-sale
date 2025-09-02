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

$success_message = '';
$error_message = '';

// Handle form submissions
if ($_POST) {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'add_permission':
                $name = trim($_POST['name']);
                $description = trim($_POST['description']);
                $category = trim($_POST['category']) ?: 'General';
                
                // Validation
                if (empty($name)) {
                    throw new Exception('Permission name is required');
                }
                
                if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
                    throw new Exception('Permission name can only contain letters, numbers, and underscores');
                }
                
                // Check if permission already exists
                $stmt = $conn->prepare("SELECT id FROM permissions WHERE name = :name");
                $stmt->bindParam(':name', $name);
                $stmt->execute();
                if ($stmt->rowCount() > 0) {
                    throw new Exception('A permission with this name already exists');
                }
                
                // Insert new permission
                $stmt = $conn->prepare("
                    INSERT INTO permissions (name, description, category, created_at, updated_at) 
                    VALUES (:name, :description, :category, NOW(), NOW())
                ");
                $stmt->bindParam(':name', $name);
                $stmt->bindParam(':description', $description);
                $stmt->bindParam(':category', $category);
                $stmt->execute();
                
                // Log activity
                $log_stmt = $conn->prepare("
                    INSERT INTO activity_logs (user_id, action, details, created_at) 
                    VALUES (:user_id, :action, :details, NOW())
                ");
                $log_stmt->execute([
                    ':user_id' => $user_id,
                    ':action' => "Created permission: $name",
                    ':details' => json_encode(['permission_name' => $name, 'category' => $category])
                ]);
                
                $success_message = "Permission created successfully!";
                break;
                
            case 'edit_permission':
                $permission_id = intval($_POST['permission_id']);
                $name = trim($_POST['name']);
                $description = trim($_POST['description']);
                $category = trim($_POST['category']) ?: 'General';
                
                if (empty($name)) {
                    throw new Exception('Permission name is required');
                }
                
                if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
                    throw new Exception('Permission name can only contain letters, numbers, and underscores');
                }
                
                // Check if permission exists
                $stmt = $conn->prepare("SELECT name FROM permissions WHERE id = :id");
                $stmt->bindParam(':id', $permission_id);
                $stmt->execute();
                $old_permission = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$old_permission) {
                    throw new Exception('Permission not found');
                }
                
                // Check if new name conflicts (excluding current permission)
                $stmt = $conn->prepare("SELECT id FROM permissions WHERE name = :name AND id != :id");
                $stmt->bindParam(':name', $name);
                $stmt->bindParam(':id', $permission_id);
                $stmt->execute();
                if ($stmt->rowCount() > 0) {
                    throw new Exception('A permission with this name already exists');
                }
                
                // Update permission
                $stmt = $conn->prepare("
                    UPDATE permissions SET 
                        name = :name, 
                        description = :description, 
                        category = :category, 
                        updated_at = NOW() 
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':name' => $name,
                    ':description' => $description,
                    ':category' => $category,
                    ':id' => $permission_id
                ]);
                
                // Log activity
                $log_stmt = $conn->prepare("
                    INSERT INTO activity_logs (user_id, action, details, created_at) 
                    VALUES (:user_id, :action, :details, NOW())
                ");
                $log_stmt->execute([
                    ':user_id' => $user_id,
                    ':action' => "Updated permission: {$old_permission['name']} → $name",
                    ':details' => json_encode([
                        'old_name' => $old_permission['name'],
                        'new_name' => $name,
                        'category' => $category
                    ])
                ]);
                
                $success_message = "Permission updated successfully!";
                break;
                
            case 'delete_permission':
                $permission_id = intval($_POST['permission_id']);
                
                // Check if permission is in use
                $stmt = $conn->prepare("SELECT COUNT(*) as count FROM role_permissions WHERE permission_id = :id");
                $stmt->bindParam(':id', $permission_id);
                $stmt->execute();
                $usage_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                
                if ($usage_count > 0) {
                    throw new Exception("Cannot delete permission - it's currently assigned to $usage_count role(s)");
                }
                
                // Get permission name for logging
                $stmt = $conn->prepare("SELECT name FROM permissions WHERE id = :id");
                $stmt->bindParam(':id', $permission_id);
                $stmt->execute();
                $permission_name = $stmt->fetchColumn();
                
                if (!$permission_name) {
                    throw new Exception('Permission not found');
                }
                
                // Delete permission
                $stmt = $conn->prepare("DELETE FROM permissions WHERE id = :id");
                $stmt->bindParam(':id', $permission_id);
                $stmt->execute();
                
                // Log activity
                $log_stmt = $conn->prepare("
                    INSERT INTO activity_logs (user_id, action, details, created_at) 
                    VALUES (:user_id, :action, :details, NOW())
                ");
                $log_stmt->execute([
                    ':user_id' => $user_id,
                    ':action' => "Deleted permission: $permission_name",
                    ':details' => json_encode(['permission_name' => $permission_name])
                ]);
                
                $success_message = "Permission deleted successfully!";
                break;
        }
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// Get all permissions with usage statistics
$stmt = $conn->query("
    SELECT p.*, 
           COUNT(DISTINCT rp.role_id) as roles_using_count,
           GROUP_CONCAT(DISTINCT r.name ORDER BY r.name SEPARATOR ', ') as roles_using
    FROM permissions p
    LEFT JOIN role_permissions rp ON p.id = rp.permission_id
    LEFT JOIN roles r ON rp.role_id = r.id
    GROUP BY p.id
    ORDER BY COALESCE(p.category, 'General'), p.name
");
$all_permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group permissions by category
$grouped_permissions = [];
$category_stats = [];
foreach ($all_permissions as $permission) {
    $category = $permission['category'] ?: 'General';
    if (!isset($grouped_permissions[$category])) {
        $grouped_permissions[$category] = [];
        $category_stats[$category] = ['total' => 0, 'used' => 0];
    }
    $grouped_permissions[$category][] = $permission;
    $category_stats[$category]['total']++;
    if ($permission['roles_using_count'] > 0) {
        $category_stats[$category]['used']++;
    }
}

// Get unique categories for dropdown
$categories = array_keys($grouped_permissions);
sort($categories);

// Get statistics
$stats = [
    'total_permissions' => count($all_permissions),
    'used_permissions' => count(array_filter($all_permissions, function($p) { return $p['roles_using_count'] > 0; })),
    'categories' => count($categories)
];

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Permissions Management - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
        
        .permissions-header {
            background: linear-gradient(135deg, var(--primary-color), #8b5cf6);
            color: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
        }
        
        .permissions-header::before {
            content: '';
            position: absolute;
            top: -50px;
            right: -50px;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
        }
        
        .stats-card {
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
            backdrop-filter: blur(10px);
        }
        
        .stats-number {
            font-size: 2rem;
            font-weight: 700;
            color: white;
            margin-bottom: 0.5rem;
        }
        
        .stats-label {
            color: rgba(255, 255, 255, 0.8);
            font-weight: 500;
        }
        
        .permission-category-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 1rem;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        
        .permission-category-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.12);
        }
        
        .category-header {
            background: linear-gradient(135deg, #f8fafc, #e2e8f0);
            padding: 1.2rem 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            cursor: pointer;
            transition: all 0.3s ease;
            user-select: none;
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
        
        .category-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            color: white;
            margin-right: 1rem;
            transition: all 0.3s ease;
        }
        
        .category-header.expanded .category-icon {
            background: rgba(255, 255, 255, 0.2) !important;
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
        
        .permissions-grid {
            padding: 2rem;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 1.5rem;
            background: #f8fafc;
            border-top: 2px solid var(--primary-color);
        }
        
        .category-collapsed .permissions-grid {
            display: none;
        }
        
        .permission-item {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 1.5rem;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .permission-item:hover {
            border-color: var(--primary-color);
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.15);
            transform: translateY(-2px);
        }
        
        .permission-name {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.5rem;
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
        }
        
        .permission-description {
            color: #64748b;
            font-size: 0.875rem;
            line-height: 1.4;
            margin-bottom: 1rem;
        }
        
        .permission-usage {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        
        .usage-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.75rem;
            border-radius: 50px;
            font-weight: 500;
        }
        
        .usage-active {
            background: #dcfce7;
            color: #166534;
        }
        
        .usage-unused {
            background: #f3f4f6;
            color: #6b7280;
        }
        
        .permission-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .btn-sm {
            padding: 0.375rem 0.75rem;
            font-size: 0.8rem;
        }
        
        .btn {
            transition: all 0.3s ease;
            font-weight: 500;
            border-radius: 6px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), #8b5cf6);
            border: none;
            box-shadow: 0 2px 8px rgba(99, 102, 241, 0.3);
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4);
        }
        
        .modal-content {
            border-radius: 12px;
            border: none;
        }
        
        .modal-header {
            background: linear-gradient(135deg, var(--primary-color), #8b5cf6);
            color: white;
            border-radius: 12px 12px 0 0;
        }
        
        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(99, 102, 241, 0.25);
        }
        
        .alert {
            border-radius: 10px;
            padding: 1rem 1.5rem;
            margin-bottom: 2rem;
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
                    <nav aria-label="breadcrumb" class="mb-2">
                        <ol class="breadcrumb mb-0">
                            <li class="breadcrumb-item"><a href="../dashboard.php">Dashboard</a></li>
                            <li class="breadcrumb-item"><a href="index.php">Roles</a></li>
                            <li class="breadcrumb-item active">Permissions</li>
                        </ol>
                    </nav>
                    <h1><i class="bi bi-key me-2"></i>Permissions Management</h1>
                    <p class="header-subtitle">Manage system-wide permissions and categories</p>
                </div>
                <div class="header-actions">
                    <div class="btn-group me-2" role="group">
                        <button type="button" class="btn btn-outline-light" onclick="expandAllCategories()" title="Expand All">
                            <i class="bi bi-arrows-expand"></i>
                        </button>
                        <button type="button" class="btn btn-outline-light" onclick="collapseAllCategories()" title="Collapse All">
                            <i class="bi bi-arrows-collapse"></i>
                        </button>
                    </div>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addPermissionModal">
                        <i class="bi bi-plus-circle me-1"></i>Add Permission
                    </button>
                    <a href="index.php" class="btn btn-outline-secondary ms-2">
                        <i class="bi bi-arrow-left me-1"></i>Back to Roles
                    </a>
                </div>
            </div>
        </header>

        <!-- Content -->
        <main class="content">
            <!-- Success/Error Messages -->
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

            <!-- Statistics Header -->
            <div class="permissions-header">
                <div class="row align-items-center">
                    <div class="col">
                        <h2 class="mb-1">
                            <i class="bi bi-key me-2"></i>
                            System Permissions
                        </h2>
                        <p class="mb-3 opacity-75">Manage all permissions available in the system</p>
                    </div>
                    <div class="col-auto">
                        <div class="row g-3">
                            <div class="col">
                                <div class="stats-card">
                                    <div class="stats-number"><?php echo $stats['total_permissions']; ?></div>
                                    <div class="stats-label">Total</div>
                                </div>
                            </div>
                            <div class="col">
                                <div class="stats-card">
                                    <div class="stats-number"><?php echo $stats['used_permissions']; ?></div>
                                    <div class="stats-label">In Use</div>
                                </div>
                            </div>
                            <div class="col">
                                <div class="stats-card">
                                    <div class="stats-number"><?php echo $stats['categories']; ?></div>
                                    <div class="stats-label">Categories</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Permission Categories -->
            <?php $category_index = 0; ?>
            <?php foreach ($grouped_permissions as $category => $category_permissions): ?>
                <?php $category_id = 'category-' . $category_index; ?>
                <div class="permission-category-card category-collapsed" id="<?php echo $category_id; ?>">
                    <div class="category-header" onclick="toggleCategory('<?php echo $category_id; ?>')">
                        <div class="d-flex align-items-center w-100">
                            <div class="category-icon" style="background: <?php echo '#' . substr(md5($category), 0, 6); ?>;">
                                <?php echo strtoupper(substr($category, 0, 1)); ?>
                            </div>
                            <div class="flex-grow-1">
                                <h5 class="category-title"><?php echo htmlspecialchars($category); ?></h5>
                                <small class="category-stats">
                                    <?php echo count($category_permissions); ?> permissions 
                                    (<?php echo $category_stats[$category]['used']; ?> in use)
                                </small>
                            </div>
                            <div class="category-toggle">
                                <i class="bi bi-chevron-down"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="permissions-grid">
                        <?php foreach ($category_permissions as $permission): ?>
                            <div class="permission-item">
                                <div class="permission-name">
                                    <?php echo htmlspecialchars($permission['name']); ?>
                                </div>
                                <?php if ($permission['description']): ?>
                                    <div class="permission-description">
                                        <?php echo htmlspecialchars($permission['description']); ?>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="permission-usage">
                                    <?php if ($permission['roles_using_count'] > 0): ?>
                                        <span class="usage-badge usage-active">
                                            Used by <?php echo $permission['roles_using_count']; ?> role(s)
                                        </span>
                                    <?php else: ?>
                                        <span class="usage-badge usage-unused">
                                            Not used
                                        </span>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if ($permission['roles_using_count'] > 0): ?>
                                    <div class="mb-3">
                                        <small class="text-muted">
                                            <strong>Used by:</strong> <?php echo htmlspecialchars($permission['roles_using']); ?>
                                        </small>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="permission-actions">
                                    <button class="btn btn-sm btn-outline-primary" 
                                            onclick="editPermission(<?php echo $permission['id']; ?>, '<?php echo addslashes($permission['name']); ?>', '<?php echo addslashes($permission['description'] ?? ''); ?>', '<?php echo addslashes($permission['category'] ?? 'General'); ?>')">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <?php if ($permission['roles_using_count'] == 0): ?>
                                        <button class="btn btn-sm btn-outline-danger" 
                                                onclick="deletePermission(<?php echo $permission['id']; ?>, '<?php echo addslashes($permission['name']); ?>')">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-outline-secondary" disabled 
                                                title="Cannot delete - permission is in use">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php $category_index++; ?>
            <?php endforeach; ?>
        </main>
    </div>

    <!-- Add Permission Modal -->
    <div class="modal fade" id="addPermissionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-plus-circle me-2"></i>Add New Permission
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="add_permission">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="addName" class="form-label">Permission Name *</label>
                            <input type="text" class="form-control" id="addName" name="name" required
                                   placeholder="e.g., manage_inventory" pattern="[a-zA-Z0-9_]+"
                                   title="Only letters, numbers, and underscores allowed">
                            <div class="form-text">Use lowercase letters, numbers, and underscores only</div>
                        </div>
                        <div class="mb-3">
                            <label for="addDescription" class="form-label">Description</label>
                            <textarea class="form-control" id="addDescription" name="description" rows="3"
                                      placeholder="Describe what this permission allows"></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="addCategory" class="form-label">Category</label>
                            <input list="categories" class="form-control" id="addCategory" name="category"
                                   placeholder="e.g., General, User Management">
                            <datalist id="categories">
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat); ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-plus-circle me-1"></i>Add Permission
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Permission Modal -->
    <div class="modal fade" id="editPermissionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-pencil me-2"></i>Edit Permission
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="edit_permission">
                    <input type="hidden" name="permission_id" id="editPermissionId">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="editName" class="form-label">Permission Name *</label>
                            <input type="text" class="form-control" id="editName" name="name" required
                                   pattern="[a-zA-Z0-9_]+" title="Only letters, numbers, and underscores allowed">
                            <div class="form-text">Use lowercase letters, numbers, and underscores only</div>
                        </div>
                        <div class="mb-3">
                            <label for="editDescription" class="form-label">Description</label>
                            <textarea class="form-control" id="editDescription" name="description" rows="3"></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="editCategory" class="form-label">Category</label>
                            <input list="categories" class="form-control" id="editCategory" name="category">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle me-1"></i>Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Permission Modal -->
    <div class="modal fade" id="deletePermissionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">
                        <i class="bi bi-exclamation-triangle me-2"></i>Confirm Delete
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="delete_permission">
                    <input type="hidden" name="permission_id" id="deletePermissionId">
                    <div class="modal-body">
                        <p>Are you sure you want to delete the permission <strong id="deletePermissionName"></strong>?</p>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            <strong>Warning:</strong> This action cannot be undone. Make sure no roles are using this permission.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">
                            <i class="bi bi-trash me-1"></i>Delete Permission
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function editPermission(id, name, description, category) {
            document.getElementById('editPermissionId').value = id;
            document.getElementById('editName').value = name;
            document.getElementById('editDescription').value = description;
            document.getElementById('editCategory').value = category;
            
            new bootstrap.Modal(document.getElementById('editPermissionModal')).show();
        }
        
        function deletePermission(id, name) {
            document.getElementById('deletePermissionId').value = id;
            document.getElementById('deletePermissionName').textContent = name;
            
            new bootstrap.Modal(document.getElementById('deletePermissionModal')).show();
        }
        
        // Toggle category expansion/collapse
        function toggleCategory(categoryId) {
            const categoryCard = document.getElementById(categoryId);
            const header = categoryCard.querySelector('.category-header');
            
            if (categoryCard.classList.contains('category-collapsed')) {
                // Expand category
                categoryCard.classList.remove('category-collapsed');
                header.classList.add('expanded');
            } else {
                // Collapse category
                categoryCard.classList.add('category-collapsed');
                header.classList.remove('expanded');
            }
        }
        
        // Expand all categories
        function expandAllCategories() {
            const categoryCards = document.querySelectorAll('.permission-category-card');
            const headers = document.querySelectorAll('.category-header');
            
            categoryCards.forEach(card => card.classList.remove('category-collapsed'));
            headers.forEach(header => header.classList.add('expanded'));
        }
        
        // Collapse all categories
        function collapseAllCategories() {
            const categoryCards = document.querySelectorAll('.permission-category-card');
            const headers = document.querySelectorAll('.category-header');
            
            categoryCards.forEach(card => card.classList.add('category-collapsed'));
            headers.forEach(header => header.classList.remove('expanded'));
        }
        
        // Auto-hide alerts
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
                const alerts = document.querySelectorAll('.alert');
                alerts.forEach(function(alert) {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                });
            }, 5000);
        });
    </script>
</body>
</html>
