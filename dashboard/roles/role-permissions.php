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
$manage_role_id = intval($_GET['id'] ?? 0);
if (!$manage_role_id) {
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
$stmt = $conn->prepare("SELECT * FROM roles WHERE id = :role_id");
$stmt->bindParam(':role_id', $manage_role_id);
$stmt->execute();
$role = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$role) {
    header("Location: index.php");
    exit();
}

$success_message = '';
$error_message = '';

// Handle AJAX requests for permission updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    
    try {
        $action = $_POST['action'] ?? '';
        $permission_id = intval($_POST['permission_id'] ?? 0);
        $category = $_POST['category'] ?? '';
        
        switch ($action) {
            case 'toggle_permission':
                if (!$permission_id) {
                    throw new Exception('Invalid permission ID');
                }
                
                // Check if permission exists for this role
                $stmt = $conn->prepare("
                    SELECT id FROM role_permissions 
                    WHERE role_id = :role_id AND permission_id = :permission_id
                ");
                $stmt->bindParam(':role_id', $manage_role_id);
                $stmt->bindParam(':permission_id', $permission_id);
                $stmt->execute();
                $exists = $stmt->fetch();
                
                if ($exists) {
                    // Remove permission
                    $stmt = $conn->prepare("
                        DELETE FROM role_permissions 
                        WHERE role_id = :role_id AND permission_id = :permission_id
                    ");
                    $stmt->bindParam(':role_id', $manage_role_id);
                    $stmt->bindParam(':permission_id', $permission_id);
                    $stmt->execute();
                    $granted = false;
                } else {
                    // Add permission
                    $stmt = $conn->prepare("
                        INSERT INTO role_permissions (role_id, permission_id) 
                        VALUES (:role_id, :permission_id)
                    ");
                    $stmt->bindParam(':role_id', $manage_role_id);
                    $stmt->bindParam(':permission_id', $permission_id);
                    $stmt->execute();
                    $granted = true;
                }
                
                // Log activity
                $permission_name = '';
                $stmt = $conn->prepare("SELECT name FROM permissions WHERE id = :permission_id");
                $stmt->bindParam(':permission_id', $permission_id);
                $stmt->execute();
                $permission_name = $stmt->fetchColumn();
                
                $action_text = $granted ? "Granted" : "Revoked";
                $log_action = "$action_text permission '$permission_name' for role '{$role['name']}'";
                $log_details = json_encode([
                    'role_id' => $manage_role_id,
                    'role_name' => $role['name'],
                    'permission_id' => $permission_id,
                    'permission_name' => $permission_name,
                    'action' => $granted ? 'granted' : 'revoked'
                ]);
                
                $log_stmt = $conn->prepare("
                    INSERT INTO activity_logs (user_id, action, details, created_at) 
                    VALUES (:user_id, :action, :details, NOW())
                ");
                $log_stmt->bindParam(':user_id', $user_id);
                $log_stmt->bindParam(':action', $log_action);
                $log_stmt->bindParam(':details', $log_details);
                $log_stmt->execute();
                
                echo json_encode([
                    'success' => true,
                    'granted' => $granted,
                    'message' => $granted ? 'Permission granted' : 'Permission revoked'
                ]);
                break;
                
            case 'toggle_category':
                if (empty($category)) {
                    throw new Exception('Invalid category');
                }
                
                // Get all permissions in this category
                $stmt = $conn->prepare("
                    SELECT id FROM permissions 
                    WHERE COALESCE(category, 'General') = :category
                ");
                $stmt->bindParam(':category', $category);
                $stmt->execute();
                $category_permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                if (empty($category_permissions)) {
                    throw new Exception('No permissions found in this category');
                }
                
                // Check if any permissions in this category are already granted
                $placeholders = str_repeat('?,', count($category_permissions) - 1) . '?';
                $stmt = $conn->prepare("
                    SELECT permission_id FROM role_permissions 
                    WHERE role_id = ? AND permission_id IN ($placeholders)
                ");
                $params = array_merge([$manage_role_id], $category_permissions);
                $stmt->execute($params);
                $existing_permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                $conn->beginTransaction();
                
                if (count($existing_permissions) === count($category_permissions)) {
                    // All permissions are granted, revoke all
                    $stmt = $conn->prepare("
                        DELETE FROM role_permissions 
                        WHERE role_id = ? AND permission_id IN ($placeholders)
                    ");
                    $stmt->execute($params);
                    $granted = false;
                    $action_text = "Revoked all permissions in category '$category'";
                } else {
                    // Not all permissions are granted, grant all
                    $stmt = $conn->prepare("
                        DELETE FROM role_permissions 
                        WHERE role_id = ? AND permission_id IN ($placeholders)
                    ");
                    $stmt->execute($params);
                    
                    $stmt = $conn->prepare("
                        INSERT INTO role_permissions (role_id, permission_id) 
                        VALUES (?, ?)
                    ");
                    foreach ($category_permissions as $perm_id) {
                        $stmt->execute([$manage_role_id, $perm_id]);
                    }
                    $granted = true;
                    $action_text = "Granted all permissions in category '$category'";
                }
                
                // Log activity
                $log_details = json_encode([
                    'role_id' => $manage_role_id,
                    'role_name' => $role['name'],
                    'category' => $category,
                    'permissions_count' => count($category_permissions),
                    'action' => $granted ? 'granted_all' : 'revoked_all'
                ]);
                
                $log_stmt = $conn->prepare("
                    INSERT INTO activity_logs (user_id, action, details, created_at) 
                    VALUES (:user_id, :action, :details, NOW())
                ");
                $log_stmt->bindParam(':user_id', $user_id);
                $log_stmt->bindParam(':action', $action_text);
                $log_stmt->bindParam(':details', $log_details);
                $log_stmt->execute();
                
                $conn->commit();
                
                echo json_encode([
                    'success' => true,
                    'granted' => $granted,
                    'message' => $granted ? 'All permissions in category granted' : 'All permissions in category revoked'
                ]);
                break;
                
            default:
                throw new Exception('Invalid action');
        }
        
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    
    exit();
}

// Get all permissions with their current status for this role
$stmt = $conn->prepare("
    SELECT p.id, p.name, p.description, 
           COALESCE(p.category, 'General') as category,
           CASE WHEN rp.permission_id IS NOT NULL THEN 1 ELSE 0 END as is_granted
    FROM permissions p
    LEFT JOIN role_permissions rp ON p.id = rp.permission_id AND rp.role_id = :role_id
    ORDER BY COALESCE(p.category, 'General'), p.name
");
$stmt->bindParam(':role_id', $manage_role_id);
$stmt->execute();
$all_permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group permissions by category
$grouped_permissions = [];
$category_stats = [];
foreach ($all_permissions as $permission) {
    $category = $permission['category'];
    if (!isset($grouped_permissions[$category])) {
        $grouped_permissions[$category] = [];
        $category_stats[$category] = ['total' => 0, 'granted' => 0];
    }
    $grouped_permissions[$category][] = $permission;
    $category_stats[$category]['total']++;
    if ($permission['is_granted']) {
        $category_stats[$category]['granted']++;
    }
}

// Get total permissions statistics
$total_permissions = count($all_permissions);
$granted_permissions = array_sum(array_column($all_permissions, 'is_granted'));

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Permissions - <?php echo htmlspecialchars($role['name']); ?> - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
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
            margin-bottom: 2rem;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        
        .permission-category-card:hover {
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
            transform: translateY(-2px);
        }
        
        .category-header {
            background: linear-gradient(135deg, #f8fafc, #e2e8f0);
            padding: 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: between;
            align-items: center;
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
        }
        
        .category-title {
            font-weight: 600;
            color: #1e293b;
            margin: 0;
            flex-grow: 1;
        }
        
        .category-stats {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .category-progress {
            width: 100px;
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .category-progress-bar {
            height: 100%;
            background: linear-gradient(135deg, var(--primary-color), #8b5cf6);
            border-radius: 4px;
            transition: width 0.3s ease;
        }
        
        .toggle-all-btn {
            background: transparent;
            border: 2px solid var(--primary-color);
            color: var(--primary-color);
            border-radius: 8px;
            padding: 0.5rem 1rem;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .toggle-all-btn:hover {
            background: var(--primary-color);
            color: white;
            transform: translateY(-1px);
        }
        
        .permissions-grid {
            padding: 2rem;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 1.5rem;
        }
        
        .permission-item {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 1.5rem;
            transition: all 0.3s ease;
            cursor: pointer;
            position: relative;
        }
        
        .permission-item:hover {
            border-color: var(--primary-color);
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.15);
            transform: translateY(-2px);
        }
        
        .permission-item.granted {
            background: linear-gradient(135deg, #f0fdf4, #dcfce7);
            border-color: #16a34a;
        }
        
        .permission-item.granted:hover {
            border-color: #15803d;
            box-shadow: 0 4px 12px rgba(22, 163, 74, 0.15);
        }
        
        .permission-toggle {
            position: absolute;
            top: 1rem;
            right: 1rem;
            width: 50px;
            height: 26px;
            background: #e2e8f0;
            border-radius: 13px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .permission-toggle.active {
            background: #16a34a;
        }
        
        .permission-toggle::after {
            content: '';
            position: absolute;
            top: 3px;
            left: 3px;
            width: 20px;
            height: 20px;
            background: white;
            border-radius: 50%;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        
        .permission-toggle.active::after {
            transform: translateX(24px);
        }
        
        .permission-name {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.5rem;
            padding-right: 60px;
        }
        
        .permission-description {
            color: #64748b;
            font-size: 0.875rem;
            line-height: 1.4;
        }
        
        .loading-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.8);
            display: none;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
        }
        
        .loading-overlay.active {
            display: flex;
        }
        
        .spinner {
            width: 24px;
            height: 24px;
            border: 2px solid #e2e8f0;
            border-top: 2px solid var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .alert {
            border-radius: 10px;
            padding: 1rem 1.5rem;
            margin-bottom: 2rem;
        }
        
        .btn {
            transition: all 0.3s ease;
            font-weight: 500;
            border-radius: 8px;
            padding: 0.75rem 1.5rem;
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
                            <li class="breadcrumb-item"><a href="view.php?id=<?php echo $role['id']; ?>"><?php echo htmlspecialchars($role['name']); ?></a></li>
                            <li class="breadcrumb-item active">Permissions</li>
                        </ol>
                    </nav>
                    <h1><i class="bi bi-shield-lock me-2"></i>Manage Permissions</h1>
                    <p class="header-subtitle">Configure permissions for the <?php echo htmlspecialchars($role['name']); ?> role</p>
                </div>
                <div class="header-actions">
                    <a href="view.php?id=<?php echo $role['id']; ?>" class="btn btn-outline-secondary me-2">
                        <i class="bi bi-arrow-left me-1"></i>Back to Role
                    </a>
                    <a href="edit.php?id=<?php echo $role['id']; ?>" class="btn btn-primary">
                        <i class="bi bi-pencil me-1"></i>Edit Role
                    </a>
                </div>
            </div>
        </header>

        <!-- Content -->
        <main class="content">
            <!-- Success/Error Messages -->
            <div id="alertContainer"></div>

            <!-- Permissions Header -->
            <div class="permissions-header">
                <div class="row align-items-center">
                    <div class="col">
                        <h2 class="mb-1">
                            <i class="bi bi-shield-lock me-2"></i>
                            <?php echo htmlspecialchars($role['name']); ?> Permissions
                        </h2>
                        <?php if ($role['description']): ?>
                            <p class="mb-3 opacity-75"><?php echo htmlspecialchars($role['description']); ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="col-auto">
                        <div class="row g-3">
                            <div class="col">
                                <div class="stats-card">
                                    <div class="stats-number" id="grantedCount"><?php echo $granted_permissions; ?></div>
                                    <div class="stats-label">Granted</div>
                                </div>
                            </div>
                            <div class="col">
                                <div class="stats-card">
                                    <div class="stats-number"><?php echo $total_permissions; ?></div>
                                    <div class="stats-label">Total</div>
                                </div>
                            </div>
                            <div class="col">
                                <div class="stats-card">
                                    <div class="stats-number" id="progressPercent"><?php echo $total_permissions > 0 ? round(($granted_permissions / $total_permissions) * 100) : 0; ?>%</div>
                                    <div class="stats-label">Complete</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Permission Categories -->
            <?php foreach ($grouped_permissions as $category => $category_permissions): ?>
                <div class="permission-category-card" id="category-<?php echo md5($category); ?>">
                    <div class="category-header">
                        <div class="d-flex align-items-center">
                            <div class="category-icon" style="background: <?php echo '#' . substr(md5($category), 0, 6); ?>;">
                                <?php echo strtoupper(substr($category, 0, 1)); ?>
                            </div>
                            <div>
                                <h5 class="category-title"><?php echo htmlspecialchars($category); ?></h5>
                                <small class="text-muted">
                                    <?php echo $category_stats[$category]['granted']; ?> of <?php echo $category_stats[$category]['total']; ?> permissions granted
                                </small>
                            </div>
                        </div>
                        <div class="category-stats">
                            <div class="category-progress">
                                <div class="category-progress-bar" 
                                     style="width: <?php echo $category_stats[$category]['total'] > 0 ? round(($category_stats[$category]['granted'] / $category_stats[$category]['total']) * 100) : 0; ?>%">
                                </div>
                            </div>
                            <button class="toggle-all-btn" data-category="<?php echo htmlspecialchars($category); ?>" 
                                    data-all-granted="<?php echo $category_stats[$category]['granted'] === $category_stats[$category]['total'] ? 'true' : 'false'; ?>">
                                <i class="bi bi-toggles me-1"></i>
                                <?php echo $category_stats[$category]['granted'] === $category_stats[$category]['total'] ? 'Revoke All' : 'Grant All'; ?>
                            </button>
                        </div>
                    </div>
                    
                    <div class="permissions-grid">
                        <?php foreach ($category_permissions as $permission): ?>
                            <div class="permission-item <?php echo $permission['is_granted'] ? 'granted' : ''; ?>" 
                                 data-permission-id="<?php echo $permission['id']; ?>">
                                <div class="loading-overlay">
                                    <div class="spinner"></div>
                                </div>
                                
                                <div class="permission-toggle <?php echo $permission['is_granted'] ? 'active' : ''; ?>"></div>
                                
                                <div class="permission-name">
                                    <?php echo htmlspecialchars($permission['name']); ?>
                                </div>
                                <?php if ($permission['description']): ?>
                                    <div class="permission-description">
                                        <?php echo htmlspecialchars($permission['description']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Global variables
        let isUpdating = false;
        
        // Show alert message
        function showAlert(message, type = 'success') {
            const alertContainer = document.getElementById('alertContainer');
            const alertHtml = `
                <div class="alert alert-${type} alert-dismissible fade show">
                    <i class="bi bi-${type === 'success' ? 'check-circle' : 'exclamation-triangle'} me-2"></i>
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;
            alertContainer.innerHTML = alertHtml;
            
            // Auto-hide after 5 seconds
            setTimeout(() => {
                const alert = alertContainer.querySelector('.alert');
                if (alert) {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }
            }, 5000);
        }
        
        // Update statistics
        function updateStats() {
            const grantedItems = document.querySelectorAll('.permission-item.granted');
            const totalItems = document.querySelectorAll('.permission-item');
            const grantedCount = grantedItems.length;
            const totalCount = totalItems.length;
            
            document.getElementById('grantedCount').textContent = grantedCount;
            document.getElementById('progressPercent').textContent = totalCount > 0 ? Math.round((grantedCount / totalCount) * 100) + '%' : '0%';
        }
        
        // Update category stats
        function updateCategoryStats(categoryElement) {
            const grantedItems = categoryElement.querySelectorAll('.permission-item.granted');
            const totalItems = categoryElement.querySelectorAll('.permission-item');
            const grantedCount = grantedItems.length;
            const totalCount = totalItems.length;
            
            // Update progress bar
            const progressBar = categoryElement.querySelector('.category-progress-bar');
            const progressPercent = totalCount > 0 ? Math.round((grantedCount / totalCount) * 100) : 0;
            progressBar.style.width = progressPercent + '%';
            
            // Update text
            const statsText = categoryElement.querySelector('.category-header small');
            statsText.textContent = `${grantedCount} of ${totalCount} permissions granted`;
            
            // Update toggle all button
            const toggleBtn = categoryElement.querySelector('.toggle-all-btn');
            const allGranted = grantedCount === totalCount;
            toggleBtn.setAttribute('data-all-granted', allGranted ? 'true' : 'false');
            toggleBtn.innerHTML = `<i class="bi bi-toggles me-1"></i>${allGranted ? 'Revoke All' : 'Grant All'}`;
        }
        
        // Toggle single permission
        function togglePermission(permissionId, permissionElement) {
            if (isUpdating) return;
            isUpdating = true;
            
            const loadingOverlay = permissionElement.querySelector('.loading-overlay');
            loadingOverlay.classList.add('active');
            
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `ajax=1&action=toggle_permission&permission_id=${permissionId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const toggle = permissionElement.querySelector('.permission-toggle');
                    
                    if (data.granted) {
                        permissionElement.classList.add('granted');
                        toggle.classList.add('active');
                    } else {
                        permissionElement.classList.remove('granted');
                        toggle.classList.remove('active');
                    }
                    
                    // Update statistics
                    updateStats();
                    updateCategoryStats(permissionElement.closest('.permission-category-card'));
                    
                    showAlert(data.message, 'success');
                } else {
                    showAlert(data.message, 'danger');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('An error occurred while updating the permission.', 'danger');
            })
            .finally(() => {
                loadingOverlay.classList.remove('active');
                isUpdating = false;
            });
        }
        
        // Toggle category permissions
        function toggleCategory(category, button) {
            if (isUpdating) return;
            isUpdating = true;
            
            button.disabled = true;
            const originalText = button.innerHTML;
            button.innerHTML = '<div class="spinner-border spinner-border-sm me-2"></div>Updating...';
            
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `ajax=1&action=toggle_category&category=${encodeURIComponent(category)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const categoryElement = button.closest('.permission-category-card');
                    const permissionItems = categoryElement.querySelectorAll('.permission-item');
                    const toggles = categoryElement.querySelectorAll('.permission-toggle');
                    
                    permissionItems.forEach((item, index) => {
                        if (data.granted) {
                            item.classList.add('granted');
                            toggles[index].classList.add('active');
                        } else {
                            item.classList.remove('granted');
                            toggles[index].classList.remove('active');
                        }
                    });
                    
                    // Update statistics
                    updateStats();
                    updateCategoryStats(categoryElement);
                    
                    showAlert(data.message, 'success');
                } else {
                    showAlert(data.message, 'danger');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('An error occurred while updating the category permissions.', 'danger');
            })
            .finally(() => {
                button.disabled = false;
                button.innerHTML = originalText;
                isUpdating = false;
            });
        }
        
        // Event listeners
        document.addEventListener('DOMContentLoaded', function() {
            // Permission item click handlers
            document.querySelectorAll('.permission-item').forEach(item => {
                item.addEventListener('click', function(e) {
                    if (e.target.closest('.permission-toggle')) return;
                    
                    const permissionId = this.getAttribute('data-permission-id');
                    togglePermission(permissionId, this);
                });
            });
            
            // Permission toggle click handlers
            document.querySelectorAll('.permission-toggle').forEach(toggle => {
                toggle.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const permissionItem = this.closest('.permission-item');
                    const permissionId = permissionItem.getAttribute('data-permission-id');
                    togglePermission(permissionId, permissionItem);
                });
            });
            
            // Toggle all button handlers
            document.querySelectorAll('.toggle-all-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const category = this.getAttribute('data-category');
                    toggleCategory(category, this);
                });
            });
        });
    </script>
</body>
</html>
