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

// Get role ID from URL
$edit_role_id = intval($_GET['id'] ?? 0);
if (!$edit_role_id) {
    header("Location: index.php");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get existing role details
$stmt = $conn->prepare("SELECT * FROM roles WHERE id = :role_id");
$stmt->bindParam(':role_id', $edit_role_id);
$stmt->execute();
$existing_role = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$existing_role) {
    header("Location: index.php");
    exit();
}

// Get existing role permissions
$stmt = $conn->prepare("
    SELECT permission_id 
    FROM role_permissions 
    WHERE role_id = :role_id
");
$stmt->bindParam(':role_id', $edit_role_id);
$stmt->execute();
$existing_permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);

$success_message = '';
$error_message = '';

// Handle form submission
if ($_POST) {
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    $selected_permissions = $_POST['permissions'] ?? [];

    // Validation
    $errors = [];

    if (empty($name)) {
        $errors[] = "Role name is required";
    } elseif (strlen($name) < 2) {
        $errors[] = "Role name must be at least 2 characters long";
    } elseif (!preg_match('/^[a-zA-Z0-9\s\-_]+$/', $name)) {
        $errors[] = "Role name can only contain letters, numbers, spaces, hyphens, and underscores";
    }

    // Check if role name already exists (excluding current role)
    if (empty($errors)) {
        $stmt = $conn->prepare("SELECT id FROM roles WHERE name = :name AND id != :role_id");
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':role_id', $edit_role_id);
        $stmt->execute();
        if ($stmt->rowCount() > 0) {
            $errors[] = "A role with this name already exists";
        }
    }

    if (empty($selected_permissions)) {
        $errors[] = "At least one permission must be selected";
    } else {
        // Validate permission IDs
        $placeholders = str_repeat('?,', count($selected_permissions) - 1) . '?';
        $stmt = $conn->prepare("SELECT id FROM permissions WHERE id IN ($placeholders)");
        $stmt->execute($selected_permissions);
        $valid_permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (count($valid_permissions) !== count($selected_permissions)) {
            $errors[] = "Some selected permissions are invalid";
        }
    }

    if (empty($errors)) {
        try {
            $conn->beginTransaction();

            // Update the role
            $stmt = $conn->prepare("
                UPDATE roles SET 
                    name = :name, 
                    description = :description, 
                    updated_at = NOW() 
                WHERE id = :role_id
            ");
            $stmt->bindParam(':name', $name);
            $stmt->bindParam(':description', $description);
            $stmt->bindParam(':role_id', $edit_role_id);
            $stmt->execute();

            // Delete existing permissions
            $stmt = $conn->prepare("DELETE FROM role_permissions WHERE role_id = :role_id");
            $stmt->bindParam(':role_id', $edit_role_id);
            $stmt->execute();

            // Insert new permissions
            $stmt = $conn->prepare("
                INSERT INTO role_permissions (role_id, permission_id) 
                VALUES (:role_id, :permission_id)
            ");
            
            foreach ($selected_permissions as $permission_id) {
                $stmt->bindParam(':role_id', $edit_role_id);
                $stmt->bindParam(':permission_id', $permission_id);
                $stmt->execute();
            }

            // Log activity
            $action = "Updated role: $name";
            $details = json_encode([
                'role_id' => $edit_role_id,
                'role_name' => $name,
                'old_name' => $existing_role['name'],
                'permissions_count' => count($selected_permissions),
                'permissions' => $selected_permissions
            ]);
            
            $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, created_at) VALUES (:user_id, :action, :details, NOW())");
            $log_stmt->bindParam(':user_id', $user_id);
            $log_stmt->bindParam(':action', $action);
            $log_stmt->bindParam(':details', $details);
            $log_stmt->execute();

            $conn->commit();
            
            $success_message = "Role updated successfully!";
            
            // Redirect to view the updated role
            header("Location: view.php?id=$edit_role_id&updated=1");
            exit();

        } catch (PDOException $e) {
            $conn->rollBack();
            $error_message = "Error updating role: " . $e->getMessage();
        }
    } else {
        $error_message = implode('<br>', $errors);
    }
} else {
    // Pre-populate form with existing data
    $_POST['name'] = $existing_role['name'];
    $_POST['description'] = $existing_role['description'];
    $_POST['permissions'] = $existing_permissions;
}

// Get all permissions grouped by category
$stmt = $conn->query("
    SELECT id, name, description, category 
    FROM permissions 
    ORDER BY category, name
");
$all_permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group permissions by category
$grouped_permissions = [];
foreach ($all_permissions as $permission) {
    $category = $permission['category'] ?: 'General';
    $grouped_permissions[$category][] = $permission;
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Role - <?php echo htmlspecialchars($existing_role['name']); ?> - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
        
        .form-section {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: box-shadow 0.3s ease;
            margin-bottom: 2rem;
        }
        
        .form-section:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .permission-category {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            transition: all 0.3s ease;
        }
        
        .permission-category:hover {
            border-color: var(--primary-color);
            box-shadow: 0 2px 8px rgba(99, 102, 241, 0.1);
        }
        
        .category-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .category-title {
            font-weight: 600;
            color: #1e293b;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .category-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .btn-category {
            font-size: 0.75rem;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            border: none;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        
        .btn-select-all {
            background: linear-gradient(135deg, var(--primary-color), #8b5cf6);
            color: white;
        }
        
        .btn-select-all:hover {
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            transform: translateY(-1px);
        }
        
        .btn-select-none {
            background: #e2e8f0;
            color: #64748b;
        }
        
        .btn-select-none:hover {
            background: #cbd5e1;
            color: #475569;
        }
        
        .permission-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
        }
        
        .permission-item {
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: 1rem;
            transition: all 0.3s ease;
            cursor: pointer;
            position: relative;
        }
        
        .permission-item:hover {
            border-color: var(--primary-color);
            box-shadow: 0 2px 8px rgba(99, 102, 241, 0.1);
            transform: translateY(-1px);
        }
        
        .permission-item.selected {
            border-color: var(--primary-color);
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.05), rgba(139, 92, 246, 0.05));
        }
        
        .permission-checkbox {
            position: absolute;
            top: 0.75rem;
            right: 0.75rem;
        }
        
        .permission-name {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.5rem;
            padding-right: 2rem;
        }
        
        .permission-desc {
            color: #64748b;
            font-size: 0.875rem;
            line-height: 1.4;
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
        
        .selection-summary {
            position: sticky;
            top: 2rem;
            background: linear-gradient(135deg, var(--primary-color), #8b5cf6);
            color: white;
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
        }
        
        .selection-count {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
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
            margin-right: 0.5rem;
        }
        
        .edit-header {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
            border-radius: 12px;
            padding: 1.5rem 2rem;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .edit-icon {
            width: 60px;
            height: 60px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
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
                    <h1><i class="bi bi-pencil-square me-2"></i>Edit Role</h1>
                    <p class="header-subtitle">Modify role permissions and details</p>
                </div>
                <div class="header-actions">
                    <a href="view.php?id=<?php echo $edit_role_id; ?>" class="btn btn-outline-secondary me-2">
                        <i class="bi bi-eye me-1"></i>View Role
                    </a>
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

            <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle me-2"></i><?php echo $error_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Edit Header -->
            <div class="edit-header">
                <div class="edit-icon">
                    <i class="bi bi-shield-check"></i>
                </div>
                <div>
                    <h4 class="mb-1">Editing: <?php echo htmlspecialchars($existing_role['name']); ?></h4>
                    <p class="mb-0 opacity-75">
                        <?php echo $existing_role['description'] ?: 'No description provided'; ?>
                    </p>
                </div>
            </div>

            <form method="POST" action="" id="roleForm">
                <div class="row">
                    <div class="col-lg-8">
                        <!-- Role Details -->
                        <div class="form-section">
                            <h5 class="border-bottom pb-3 mb-4">
                                <i class="bi bi-info-circle me-2"></i>Role Information
                            </h5>
                            
                            <div class="row">
                                <div class="col-12 mb-3">
                                    <label for="name" class="form-label fw-semibold">Role Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="name" name="name" 
                                           value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" 
                                           placeholder="e.g., Store Manager, Cashier, Admin" required>
                                    <div class="form-text">Choose a descriptive name for this role</div>
                                </div>

                                <div class="col-12 mb-3">
                                    <label for="description" class="form-label fw-semibold">Description</label>
                                    <textarea class="form-control" id="description" name="description" rows="3" 
                                              placeholder="Describe what this role is responsible for..."><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                                    <div class="form-text">Optional description to help identify the role's purpose</div>
                                </div>
                            </div>
                        </div>

                        <!-- Permissions -->
                        <div class="form-section">
                            <div class="d-flex justify-content-between align-items-center border-bottom pb-3 mb-4">
                                <h5 class="mb-0">
                                    <i class="bi bi-key me-2"></i>Permissions <span class="text-danger">*</span>
                                </h5>
                                <div class="d-flex gap-2">
                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAllPermissions()">
                                        <i class="bi bi-check-all me-1"></i>Select All
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearAllPermissions()">
                                        <i class="bi bi-x-circle me-1"></i>Clear All
                                    </button>
                                </div>
                            </div>

                            <?php foreach ($grouped_permissions as $category => $category_permissions): ?>
                            <div class="permission-category" data-category="<?php echo htmlspecialchars($category); ?>">
                                <div class="category-header">
                                    <div class="category-title">
                                        <span class="category-icon" style="background: <?php echo '#' . substr(md5($category), 0, 6); ?>;">
                                            <?php echo strtoupper(substr($category, 0, 1)); ?>
                                        </span>
                                        <?php echo htmlspecialchars($category); ?>
                                        <span class="badge bg-secondary ms-2"><?php echo count($category_permissions); ?></span>
                                    </div>
                                    <div class="category-actions">
                                        <button type="button" class="btn-category btn-select-all" 
                                                onclick="selectCategoryPermissions('<?php echo htmlspecialchars($category); ?>')">
                                            Select All
                                        </button>
                                        <button type="button" class="btn-category btn-select-none" 
                                                onclick="clearCategoryPermissions('<?php echo htmlspecialchars($category); ?>')">
                                            Clear
                                        </button>
                                    </div>
                                </div>

                                <div class="permission-grid">
                                    <?php foreach ($category_permissions as $permission): ?>
                                    <div class="permission-item" onclick="togglePermission(<?php echo $permission['id']; ?>)">
                                        <input type="checkbox" class="form-check-input permission-checkbox" 
                                               name="permissions[]" value="<?php echo $permission['id']; ?>" 
                                               id="perm_<?php echo $permission['id']; ?>"
                                               <?php echo (in_array($permission['id'], $_POST['permissions'] ?? [])) ? 'checked' : ''; ?>>
                                        <div class="permission-name"><?php echo htmlspecialchars($permission['name']); ?></div>
                                        <?php if ($permission['description']): ?>
                                        <div class="permission-desc"><?php echo htmlspecialchars($permission['description']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Form Actions -->
                        <div class="d-flex justify-content-between">
                            <a href="view.php?id=<?php echo $edit_role_id; ?>" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-left me-1"></i>Cancel
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-circle me-1"></i>Update Role
                            </button>
                        </div>
                    </div>

                    <div class="col-lg-4">
                        <!-- Selection Summary -->
                        <div class="selection-summary">
                            <div class="selection-count" id="selectedCount">0</div>
                            <div>Permissions Selected</div>
                            <hr class="my-3 opacity-25">
                            <div class="text-start">
                                <small class="opacity-75">
                                    <i class="bi bi-info-circle me-1"></i>
                                    Changes will affect all users assigned to this role.
                                </small>
                            </div>
                        </div>

                        <!-- Role Info -->
                        <div class="form-section mt-3">
                            <h6><i class="bi bi-info-circle me-2"></i>Role Information</h6>
                            <div class="small text-muted mb-2">
                                <strong>Created:</strong> <?php echo date('M j, Y', strtotime($existing_role['created_at'])); ?>
                            </div>
                            <?php if ($existing_role['updated_at'] && $existing_role['updated_at'] !== $existing_role['created_at']): ?>
                            <div class="small text-muted mb-2">
                                <strong>Last Updated:</strong> <?php echo date('M j, Y', strtotime($existing_role['updated_at'])); ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Warning -->
                        <div class="form-section mt-3">
                            <div class="alert alert-warning mb-0">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                <small><strong>Important:</strong> Changes to permissions will immediately affect all users assigned to this role.</small>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Update selected permissions count
        function updateSelectedCount() {
            const checkboxes = document.querySelectorAll('input[name="permissions[]"]:checked');
            document.getElementById('selectedCount').textContent = checkboxes.length;
            
            // Update permission items visual state
            document.querySelectorAll('.permission-item').forEach(item => {
                const checkbox = item.querySelector('.permission-checkbox');
                if (checkbox.checked) {
                    item.classList.add('selected');
                } else {
                    item.classList.remove('selected');
                }
            });
        }

        // Toggle individual permission
        function togglePermission(permissionId) {
            const checkbox = document.getElementById('perm_' + permissionId);
            checkbox.checked = !checkbox.checked;
            updateSelectedCount();
        }

        // Select all permissions
        function selectAllPermissions() {
            document.querySelectorAll('input[name="permissions[]"]').forEach(checkbox => {
                checkbox.checked = true;
            });
            updateSelectedCount();
        }

        // Clear all permissions
        function clearAllPermissions() {
            document.querySelectorAll('input[name="permissions[]"]').forEach(checkbox => {
                checkbox.checked = false;
            });
            updateSelectedCount();
        }

        // Select category permissions
        function selectCategoryPermissions(category) {
            const categoryDiv = document.querySelector(`[data-category="${category}"]`);
            categoryDiv.querySelectorAll('input[name="permissions[]"]').forEach(checkbox => {
                checkbox.checked = true;
            });
            updateSelectedCount();
        }

        // Clear category permissions
        function clearCategoryPermissions(category) {
            const categoryDiv = document.querySelector(`[data-category="${category}"]`);
            categoryDiv.querySelectorAll('input[name="permissions[]"]').forEach(checkbox => {
                checkbox.checked = false;
            });
            updateSelectedCount();
        }

        // Form validation
        document.getElementById('roleForm').addEventListener('submit', function(e) {
            const selectedPermissions = document.querySelectorAll('input[name="permissions[]"]:checked');
            if (selectedPermissions.length === 0) {
                e.preventDefault();
                alert('Please select at least one permission for this role.');
                return;
            }

            const roleName = document.getElementById('name').value.trim();
            if (!roleName) {
                e.preventDefault();
                alert('Please enter a role name.');
                return;
            }
        });

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateSelectedCount();
            
            // Add click handlers to checkboxes to prevent double-toggle
            document.querySelectorAll('.permission-checkbox').forEach(checkbox => {
                checkbox.addEventListener('click', function(e) {
                    e.stopPropagation();
                    setTimeout(updateSelectedCount, 10);
                });
            });
        });
    </script>
</body>
</html>
