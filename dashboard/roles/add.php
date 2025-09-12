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
if (!hasPermission('manage_roles', $permissions) && !hasPermission('assign_menu_roles', $permissions)) {
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

// Handle AJAX requests
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'suggest_permissions') {
    $menu_access = json_decode($_POST['menu_access'], true);
    $suggested_permissions = getSuggestedPermissionsFromMenuAccess($menu_access);
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'suggested_permissions' => $suggested_permissions
    ]);
    exit;
}

// Handle form submission
if ($_POST) {
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    $redirect_url = trim($_POST['redirect_url']);
    $custom_redirect_url = trim($_POST['custom_redirect_url'] ?? '');
    $selected_permissions = $_POST['permissions'] ?? [];
    $menu_access = $_POST['menu_access'] ?? [];
    
    // Handle custom redirect URL
    if ($redirect_url === 'custom') {
        $redirect_url = $custom_redirect_url;
    }

    // Validation
    $errors = [];

    if (empty($name)) {
        $errors[] = "Role name is required";
    } elseif (strlen($name) < 2) {
        $errors[] = "Role name must be at least 2 characters long";
    } elseif (!preg_match('/^[a-zA-Z0-9\s\-_]+$/', $name)) {
        $errors[] = "Role name can only contain letters, numbers, spaces, hyphens, and underscores";
    }

    // Check if role name already exists
    if (empty($errors)) {
        $stmt = $conn->prepare("SELECT id FROM roles WHERE name = :name");
        $stmt->bindParam(':name', $name);
        $stmt->execute();
        if ($stmt->rowCount() > 0) {
            $errors[] = "A role with this name already exists";
        }
    }

    if (empty($redirect_url)) {
        $errors[] = "Redirect URL is required";
    } elseif (!preg_match('/^[a-zA-Z0-9\/\.\-_]+$/', $redirect_url)) {
        $errors[] = "Redirect URL contains invalid characters";
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

            // Insert the role
            $stmt = $conn->prepare("
                INSERT INTO roles (name, description, redirect_url, created_at, updated_at) 
                VALUES (:name, :description, :redirect_url, NOW(), NOW())
            ");
            $stmt->bindParam(':name', $name);
            $stmt->bindParam(':description', $description);
            $stmt->bindParam(':redirect_url', $redirect_url);
            $stmt->execute();
            
            $new_role_id = $conn->lastInsertId();

            // Insert role permissions
            $stmt = $conn->prepare("
                INSERT INTO role_permissions (role_id, permission_id) 
                VALUES (:role_id, :permission_id)
            ");
            
            foreach ($selected_permissions as $permission_id) {
                $stmt->bindParam(':role_id', $new_role_id);
                $stmt->bindParam(':permission_id', $permission_id);
                $stmt->execute();
            }
            
            // Insert menu access settings
            $stmt = $conn->prepare("
                INSERT INTO role_menu_access (role_id, menu_section_id, is_visible, is_priority) 
                VALUES (:role_id, :menu_section_id, :is_visible, :is_priority)
            ");
            
            foreach ($menu_access as $section_id => $access) {
                $is_visible = isset($access['visible']) ? 1 : 0;
                $is_priority = isset($access['priority']) ? 1 : 0;
                
                $stmt->bindParam(':role_id', $new_role_id);
                $stmt->bindParam(':menu_section_id', $section_id);
                $stmt->bindParam(':is_visible', $is_visible);
                $stmt->bindParam(':is_priority', $is_priority);
                $stmt->execute();
            }

            // Log activity
            $action = "Created new role: $name";
            $details = json_encode([
                'role_id' => $new_role_id,
                'role_name' => $name,
                'permissions_count' => count($selected_permissions),
                'permissions' => $selected_permissions
            ]);
            
            $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, created_at) VALUES (:user_id, :action, :details, NOW())");
            $log_stmt->bindParam(':user_id', $user_id);
            $log_stmt->bindParam(':action', $action);
            $log_stmt->bindParam(':details', $details);
            $log_stmt->execute();

            $conn->commit();
            
            $success_message = "Role created successfully!";
            
            // Redirect to view the new role
            header("Location: view.php?id=$new_role_id&created=1");
            exit();

        } catch (PDOException $e) {
            $conn->rollBack();
            $error_message = "Error creating role: " . $e->getMessage();
        }
    } else {
        $error_message = implode('<br>', $errors);
    }
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

// Get all menu sections
$stmt = $conn->query("
    SELECT id, section_key, section_name, section_icon, section_description, sort_order
    FROM menu_sections 
    WHERE is_active = 1 
    ORDER BY sort_order, section_name
");
$menu_sections = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Role - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
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
        
        .menu-section-item {
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 1.25rem;
            transition: all 0.3s ease;
            cursor: pointer;
            position: relative;
        }
        
        .menu-section-item:hover {
            border-color: var(--primary-color);
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.1);
            transform: translateY(-2px);
        }
        
        .menu-section-item.selected {
            border-color: var(--primary-color);
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.05), rgba(139, 92, 246, 0.05));
        }
        
        .menu-section-name {
            color: #1e293b;
            font-size: 1rem;
        }
        
        .menu-section-desc {
            color: #64748b;
            font-size: 0.875rem;
            line-height: 1.4;
        }
        
        .menu-section-controls {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .menu-section-controls .form-check {
            margin-bottom: 0;
        }
        
        .menu-section-controls .form-check-label {
            font-size: 0.875rem;
            color: #475569;
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
                    <h1><i class="bi bi-shield-plus me-2"></i>Add New Role</h1>
                    <p class="header-subtitle">Create a new role and assign permissions</p>
                </div>
                <div class="header-actions">
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

                                <div class="col-12 mb-3">
                                    <label for="redirect_url" class="form-label fw-semibold">Redirect URL After Login <span class="text-danger">*</span></label>
                                    <select class="form-select" id="redirect_url" name="redirect_url" required>
                                        <option value="">Select redirect page...</option>
                                        <option value="../dashboard/dashboard.php" <?php echo (($_POST['redirect_url'] ?? '') === '../dashboard/dashboard.php') ? 'selected' : ''; ?>>Main Dashboard</option>
                                        <option value="../sales/salesdashboard.php" <?php echo (($_POST['redirect_url'] ?? '') === '../sales/salesdashboard.php') ? 'selected' : ''; ?>>Sales Dashboard</option>
                                        <option value="../sales/pos.php" <?php echo (($_POST['redirect_url'] ?? '') === '../sales/pos.php') ? 'selected' : ''; ?>>Point of Sale</option>
                                        <option value="../sales/tills.php" <?php echo (($_POST['redirect_url'] ?? '') === '../sales/tills.php') ? 'selected' : ''; ?>>Register Tills</option>
                                        <option value="../products/products.php" <?php echo (($_POST['redirect_url'] ?? '') === '../products/products.php') ? 'selected' : ''; ?>>Products Management</option>
                                        <option value="../categories/categories.php" <?php echo (($_POST['redirect_url'] ?? '') === '../categories/categories.php') ? 'selected' : ''; ?>>Categories Management</option>
                                        <option value="../brands/brands.php" <?php echo (($_POST['redirect_url'] ?? '') === '../brands/brands.php') ? 'selected' : ''; ?>>Brands Management</option>
                                        <option value="../suppliers/suppliers.php" <?php echo (($_POST['redirect_url'] ?? '') === '../suppliers/suppliers.php') ? 'selected' : ''; ?>>Suppliers Management</option>
                                        <option value="../customers/customers.php" <?php echo (($_POST['redirect_url'] ?? '') === '../customers/customers.php') ? 'selected' : ''; ?>>Customers Management</option>
                                        <option value="../inventory/inventory.php" <?php echo (($_POST['redirect_url'] ?? '') === '../inventory/inventory.php') ? 'selected' : ''; ?>>Inventory Management</option>
                                        <option value="../reports/reports.php" <?php echo (($_POST['redirect_url'] ?? '') === '../reports/reports.php') ? 'selected' : ''; ?>>Reports</option>
                                        <option value="../users/users.php" <?php echo (($_POST['redirect_url'] ?? '') === '../users/users.php') ? 'selected' : ''; ?>>User Management</option>
                                        <option value="../settings/settings.php" <?php echo (($_POST['redirect_url'] ?? '') === '../settings/settings.php') ? 'selected' : ''; ?>>System Settings</option>
                                        <option value="custom">Custom URL...</option>
                                    </select>
                                    <div class="form-text">Choose which page users with this role will be redirected to after login</div>
                                    
                                    <!-- Custom URL input (hidden by default) -->
                                    <div id="custom_url_div" class="mt-2" style="display: none;">
                                        <input type="text" class="form-control" id="custom_redirect_url" name="custom_redirect_url" 
                                               placeholder="Enter custom redirect URL (e.g., ../custom/page.php)"
                                               value="<?php echo htmlspecialchars($_POST['custom_redirect_url'] ?? ''); ?>">
                                        <div class="form-text">Enter a relative path from the root directory</div>
                                    </div>
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
                                    <button type="button" class="btn btn-sm btn-outline-warning" onclick="suggestPermissionsFromMenu()">
                                        <i class="bi bi-magic me-1"></i>Suggest from Menu
                                    </button>
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
                            <a href="index.php" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-left me-1"></i>Cancel
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-circle me-1"></i>Create Role
                            </button>
                        </div>
                        
                        <!-- Menu Access Control -->
                        <div class="form-section">
                            <div class="d-flex justify-content-between align-items-center border-bottom pb-3 mb-4">
                                <h5 class="mb-0">
                                    <i class="bi bi-list-ul me-2"></i>Menu Access Control
                                </h5>
                                <div class="d-flex gap-2">
                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAllMenuSections()">
                                        <i class="bi bi-check-all me-1"></i>Select All
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearAllMenuSections()">
                                        <i class="bi bi-x-circle me-1"></i>Clear All
                                    </button>
                                </div>
                            </div>
                            
                            <div class="row">
                                <?php foreach ($menu_sections as $section): ?>
                                <div class="col-md-6 col-lg-4 mb-3">
                                    <div class="menu-section-item" data-section="<?php echo $section['id']; ?>">
                                        <div class="d-flex align-items-start">
                                            <div class="flex-grow-1">
                                                <div class="d-flex align-items-center mb-2">
                                                    <i class="bi <?php echo htmlspecialchars($section['section_icon']); ?> me-2 text-primary"></i>
                                                    <strong class="menu-section-name"><?php echo htmlspecialchars($section['section_name']); ?></strong>
                                                </div>
                                                <p class="menu-section-desc text-muted small mb-2">
                                                    <?php echo htmlspecialchars($section['section_description']); ?>
                                                </p>
                                                <div class="menu-section-controls">
                                                    <div class="form-check form-check-inline">
                                                        <input class="form-check-input" type="checkbox" 
                                                               name="menu_access[<?php echo $section['id']; ?>][visible]" 
                                                               id="visible_<?php echo $section['id']; ?>"
                                                               onchange="updateMenuSectionCount()">
                                                        <label class="form-check-label" for="visible_<?php echo $section['id']; ?>">
                                                            <i class="bi bi-eye me-1"></i>Visible
                                                        </label>
                                                    </div>
                                                    <div class="form-check form-check-inline">
                                                        <input class="form-check-input" type="checkbox" 
                                                               name="menu_access[<?php echo $section['id']; ?>][priority]" 
                                                               id="priority_<?php echo $section['id']; ?>"
                                                               onchange="updateMenuSectionCount()">
                                                        <label class="form-check-label" for="priority_<?php echo $section['id']; ?>">
                                                            <i class="bi bi-star me-1"></i>Priority
                                                        </label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i>
                                <strong>Menu Access Control:</strong>
                                <ul class="mb-0 mt-2">
                                    <li><strong>Visible:</strong> Shows this section in the navigation menu</li>
                                    <li><strong>Priority:</strong> Auto-expands this section and highlights it as primary</li>
                                    <li>Users will only see sections marked as "Visible"</li>
                                    <li><strong>Admin Note:</strong> Admins automatically see all sections regardless of assignment</li>
                                </ul>
                            </div>
                            
                            <div class="alert alert-warning">
                                <i class="bi bi-shield-check me-2"></i>
                                <strong>Menu Management Permissions:</strong>
                                <p class="mb-0 mt-2">
                                    To allow this role to manage menus for other roles, ensure they have the 
                                    "Menu Management" permissions in the permissions section above:
                                </p>
                                <ul class="mb-0 mt-2">
                                    <li><strong>Create Menu Sections:</strong> Create new menu sections</li>
                                    <li><strong>Edit Menu Sections:</strong> Edit existing menu sections</li>
                                    <li><strong>Delete Menu Sections:</strong> Delete menu sections</li>
                                    <li><strong>Manage Menu Content:</strong> Add/edit menu content items</li>
                                    <li><strong>Assign Menu to Roles:</strong> Assign menu sections to roles</li>
                                </ul>
                            </div>
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
                                    Choose permissions carefully. Users with this role will have access to all selected features.
                                </small>
                            </div>
                        </div>

                        <!-- Quick Tips -->
                        <div class="form-section mt-3">
                            <h6><i class="bi bi-lightbulb me-2"></i>Quick Tips</h6>
                            <ul class="list-unstyled mb-0">
                                <li class="mb-2">
                                    <i class="bi bi-arrow-right text-primary me-2"></i>
                                    <small>Start with basic permissions and add more as needed</small>
                                </li>
                                <li class="mb-2">
                                    <i class="bi bi-arrow-right text-primary me-2"></i>
                                    <small>Group related permissions by category</small>
                                </li>
                                <li class="mb-0">
                                    <i class="bi bi-arrow-right text-primary me-2"></i>
                                    <small>Test the role with a test user account</small>
                                </li>
                            </ul>
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
        
        // Menu section management functions
        function selectAllMenuSections() {
            document.querySelectorAll('input[name*="[visible]"]').forEach(checkbox => {
                checkbox.checked = true;
            });
            updateMenuSectionCount();
        }
        
        function clearAllMenuSections() {
            document.querySelectorAll('input[name*="[visible]"], input[name*="[priority]"]').forEach(checkbox => {
                checkbox.checked = false;
            });
            updateMenuSectionCount();
        }
        
        function updateMenuSectionCount() {
            const visibleCount = document.querySelectorAll('input[name*="[visible]"]:checked').length;
            const priorityCount = document.querySelectorAll('input[name*="[priority]"]:checked').length;
            
            // Update visual feedback
            document.querySelectorAll('.menu-section-item').forEach(item => {
                const visibleCheckbox = item.querySelector('input[name*="[visible]"]');
                const priorityCheckbox = item.querySelector('input[name*="[priority]"]');
                
                if (visibleCheckbox && visibleCheckbox.checked) {
                    item.classList.add('selected');
                } else {
                    item.classList.remove('selected');
                }
            });
            
            // Update summary if element exists
            const menuSummary = document.getElementById('menuSummary');
            if (menuSummary) {
                menuSummary.innerHTML = `
                    <div class="text-center">
                        <div class="h4 mb-1">${visibleCount}</div>
                        <div class="small">Sections Visible</div>
                        <div class="h6 mb-1 text-warning">${priorityCount}</div>
                        <div class="small">Priority Sections</div>
                    </div>
                `;
            }
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

        // Handle redirect URL selection
        function handleRedirectUrlChange() {
            const redirectSelect = document.getElementById('redirect_url');
            const customUrlDiv = document.getElementById('custom_url_div');
            const customUrlInput = document.getElementById('custom_redirect_url');
            
            if (redirectSelect.value === 'custom') {
                customUrlDiv.style.display = 'block';
                customUrlInput.required = true;
            } else {
                customUrlDiv.style.display = 'none';
                customUrlInput.required = false;
            }
        }

        // Suggest permissions based on menu access
        function suggestPermissionsFromMenu() {
            const menuAccess = {};
            
            // Get selected menu sections
            document.querySelectorAll('input[name*="[visible]"]:checked').forEach(checkbox => {
                const sectionId = checkbox.name.match(/\[(\d+)\]/)[1];
                menuAccess[sectionId] = { visible: true };
            });
            
            if (Object.keys(menuAccess).length === 0) {
                alert('Please select at least one menu section first.');
                return;
            }
            
            // Send AJAX request to get suggested permissions
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=suggest_permissions&menu_access=' + encodeURIComponent(JSON.stringify(menuAccess))
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Clear existing selections
                    document.querySelectorAll('input[name="permissions[]"]').forEach(checkbox => {
                        checkbox.checked = false;
                    });
                    
                    // Select suggested permissions
                    data.suggested_permissions.forEach(permissionName => {
                        const checkbox = document.querySelector(`input[name="permissions[]"][value="${permissionName}"]`);
                        if (checkbox) {
                            checkbox.checked = true;
                        }
                    });
                    
                    updateSelectedCount();
                    
                    // Show detailed information
                    const permissionList = data.suggested_permissions.join(', ');
                    alert(`Suggested ${data.suggested_permissions.length} permissions based on selected menu access:\n\n${permissionList}`);
                } else {
                    alert('Error getting permission suggestions: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error getting permission suggestions. Please try again.');
            });
        }

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
            
            // Handle redirect URL change
            document.getElementById('redirect_url').addEventListener('change', handleRedirectUrlChange);
            
            // Initialize redirect URL handler
            handleRedirectUrlChange();
        });
    </script>
</body>
</html>
