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

// Check permissions - Admin has full access, others need specific permissions
$role_name = $_SESSION['role_name'] ?? 'User';
$isAdmin = (
    $role_name === 'Admin' || 
    $role_name === 'admin' || 
    $role_name === 'Administrator' || 
    $role_name === 'administrator' ||
    hasPermission('manage_roles', $permissions) ||
    hasPermission('manage_users', $permissions)
);

if (!$isAdmin && !hasPermission('manage_roles', $permissions) && !hasPermission('manage_menu_sections', $permissions) && !hasPermission('create_menu_sections', $permissions) && !hasPermission('edit_menu_sections', $permissions) && !hasPermission('delete_menu_sections', $permissions)) {
    header("Location: ../../dashboard/dashboard.php");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get statistics
$stats = [];

// Total menu sections
$stmt = $conn->query("SELECT COUNT(*) as total FROM menu_sections");
$stats['total_sections'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Active sections
$stmt = $conn->query("SELECT COUNT(*) as total FROM menu_sections WHERE is_active = 1");
$stats['active_sections'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total roles
$stmt = $conn->query("SELECT COUNT(*) as total FROM roles");
$stats['total_roles'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Roles with menu assignments
$stmt = $conn->query("SELECT COUNT(DISTINCT role_id) as total FROM role_menu_access");
$stats['roles_with_menus'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Menu Management Dashboard - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
        
        .stats-card {
            background: linear-gradient(135deg, var(--primary-color), #7c3aed);
            color: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            transition: transform 0.3s ease;
        }
        
        .stats-card:hover {
            transform: translateY(-2px);
        }
        
        .management-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            margin-bottom: 1rem;
            height: 100%;
        }
        
        .management-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .permission-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
        }
        
        .permission-granted {
            background: #d1fae5;
            color: #065f46;
        }
        
        .permission-denied {
            background: #fee2e2;
            color: #991b1b;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Include Sidebar -->
            <?php include '../../include/navmenu.php'; ?>
            
            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <div>
                        <h1 class="h2">
                            <i class="bi bi-gear me-2"></i>Menu Management Dashboard
                        </h1>
                        <?php if ($isAdmin): ?>
                        <div class="alert alert-info d-inline-block mb-0">
                            <i class="bi bi-shield-check me-2"></i>
                            <strong>Admin Status:</strong> You have full control over all menu operations and can assign permissions to other roles.
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <a href="index.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left me-1"></i>Back to Roles
                        </a>
                    </div>
                </div>

                <!-- Statistics -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="stats-card">
                            <div class="d-flex align-items-center">
                                <div class="flex-grow-1">
                                    <h3 class="mb-0"><?php echo $stats['total_sections']; ?></h3>
                                    <p class="mb-0">Total Sections</p>
                                </div>
                                <i class="bi bi-list-ul display-4 opacity-50"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card">
                            <div class="d-flex align-items-center">
                                <div class="flex-grow-1">
                                    <h3 class="mb-0"><?php echo $stats['active_sections']; ?></h3>
                                    <p class="mb-0">Active Sections</p>
                                </div>
                                <i class="bi bi-check-circle display-4 opacity-50"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card">
                            <div class="d-flex align-items-center">
                                <div class="flex-grow-1">
                                    <h3 class="mb-0"><?php echo $stats['total_roles']; ?></h3>
                                    <p class="mb-0">Total Roles</p>
                                </div>
                                <i class="bi bi-people display-4 opacity-50"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card">
                            <div class="d-flex align-items-center">
                                <div class="flex-grow-1">
                                    <h3 class="mb-0"><?php echo $stats['roles_with_menus']; ?></h3>
                                    <p class="mb-0">Roles with Menus</p>
                                </div>
                                <i class="bi bi-shield-check display-4 opacity-50"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Management Options -->
                <div class="row">
                    <!-- Menu Sections Management -->
                    <div class="col-md-6 col-lg-4 mb-4">
                        <div class="management-card">
                            <div class="d-flex align-items-center mb-3">
                                <i class="bi bi-list-ul text-primary me-3" style="font-size: 2rem;"></i>
                                <div>
                                    <h5 class="mb-0">Menu Sections</h5>
                                    <p class="text-muted mb-0">Manage menu sections</p>
                                </div>
                            </div>
                            <p class="text-muted mb-3">Create, edit, and delete menu sections that appear in the navigation.</p>
                            
                            <div class="mb-3">
                                <h6>Your Permissions:</h6>
                                <div class="d-flex flex-wrap gap-1">
                                    <span class="permission-badge <?php echo hasPermission('create_menu_sections', $permissions) || hasPermission('manage_menu_sections', $permissions) ? 'permission-granted' : 'permission-denied'; ?>">
                                        <i class="bi bi-plus-circle me-1"></i>Create
                                    </span>
                                    <span class="permission-badge <?php echo hasPermission('edit_menu_sections', $permissions) || hasPermission('manage_menu_sections', $permissions) ? 'permission-granted' : 'permission-denied'; ?>">
                                        <i class="bi bi-pencil me-1"></i>Edit
                                    </span>
                                    <span class="permission-badge <?php echo hasPermission('delete_menu_sections', $permissions) || hasPermission('manage_menu_sections', $permissions) ? 'permission-granted' : 'permission-denied'; ?>">
                                        <i class="bi bi-trash me-1"></i>Delete
                                    </span>
                                </div>
                            </div>
                            
                            <a href="menu_sections.php" class="btn btn-primary w-100">
                                <i class="bi bi-gear me-2"></i>Manage Sections
                            </a>
                        </div>
                    </div>

                    <!-- Menu Content Management -->
                    <div class="col-md-6 col-lg-4 mb-4">
                        <div class="management-card">
                            <div class="d-flex align-items-center mb-3">
                                <i class="bi bi-file-text text-success me-3" style="font-size: 2rem;"></i>
                                <div>
                                    <h5 class="mb-0">Menu Content</h5>
                                    <p class="text-muted mb-0">Manage menu items</p>
                                </div>
                            </div>
                            <p class="text-muted mb-3">Add and modify menu items within each section.</p>
                            
                            <div class="mb-3">
                                <h6>Your Permissions:</h6>
                                <div class="d-flex flex-wrap gap-1">
                                    <span class="permission-badge <?php echo hasPermission('manage_menu_content', $permissions) ? 'permission-granted' : 'permission-denied'; ?>">
                                        <i class="bi bi-file-text me-1"></i>Manage Content
                                    </span>
                                </div>
                            </div>
                            
                            <a href="menu_content.php" class="btn btn-success w-100">
                                <i class="bi bi-file-text me-2"></i>Manage Content
                            </a>
                        </div>
                    </div>

                    <!-- Role Assignment -->
                    <div class="col-md-6 col-lg-4 mb-4">
                        <div class="management-card">
                            <div class="d-flex align-items-center mb-3">
                                <i class="bi bi-shield-check text-warning me-3" style="font-size: 2rem;"></i>
                                <div>
                                    <h5 class="mb-0">Role Assignment</h5>
                                    <p class="text-muted mb-0">Assign menus to roles</p>
                                </div>
                            </div>
                            <p class="text-muted mb-3">Assign menu sections to roles and set visibility preferences.</p>
                            
                            <div class="mb-3">
                                <h6>Your Permissions:</h6>
                                <div class="d-flex flex-wrap gap-1">
                                    <span class="permission-badge <?php echo hasPermission('assign_menu_roles', $permissions) || hasPermission('manage_roles', $permissions) ? 'permission-granted' : 'permission-denied'; ?>">
                                        <i class="bi bi-shield-check me-1"></i>Assign Menus
                                    </span>
                                </div>
                            </div>
                            
                            <a href="menu_role_assignment.php" class="btn btn-warning w-100">
                                <i class="bi bi-shield-check me-2"></i>Assign to Roles
                            </a>
                        </div>
                    </div>

                    <!-- Role Management -->
                    <div class="col-md-6 col-lg-4 mb-4">
                        <div class="management-card">
                            <div class="d-flex align-items-center mb-3">
                                <i class="bi bi-people text-info me-3" style="font-size: 2rem;"></i>
                                <div>
                                    <h5 class="mb-0">Role Management</h5>
                                    <p class="text-muted mb-0">Manage user roles</p>
                                </div>
                            </div>
                            <p class="text-muted mb-3">Create and manage user roles with menu permissions.</p>
                            
                            <div class="mb-3">
                                <h6>Your Permissions:</h6>
                                <div class="d-flex flex-wrap gap-1">
                                    <span class="permission-badge <?php echo hasPermission('manage_roles', $permissions) ? 'permission-granted' : 'permission-denied'; ?>">
                                        <i class="bi bi-people me-1"></i>Manage Roles
                                    </span>
                                </div>
                            </div>
                            
                            <a href="index.php" class="btn btn-info w-100">
                                <i class="bi bi-people me-2"></i>Manage Roles
                            </a>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="col-md-6 col-lg-4 mb-4">
                        <div class="management-card">
                            <div class="d-flex align-items-center mb-3">
                                <i class="bi bi-lightning text-danger me-3" style="font-size: 2rem;"></i>
                                <div>
                                    <h5 class="mb-0">Quick Actions</h5>
                                    <p class="text-muted mb-0">Common tasks</p>
                                </div>
                            </div>
                            <p class="text-muted mb-3">Quick access to common menu management tasks.</p>
                            
                            <div class="d-grid gap-2">
                                <?php if (hasPermission('create_menu_sections', $permissions) || hasPermission('manage_menu_sections', $permissions)): ?>
                                <a href="menu_sections.php#add-section" class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-plus-circle me-1"></i>Add New Section
                                </a>
                                <?php endif; ?>
                                
                                <?php if (hasPermission('assign_menu_roles', $permissions) || hasPermission('manage_roles', $permissions)): ?>
                                <a href="menu_role_assignment.php" class="btn btn-outline-warning btn-sm">
                                    <i class="bi bi-shield-check me-1"></i>Assign Menus
                                </a>
                                <?php endif; ?>
                                
                                <a href="menu_content.php" class="btn btn-outline-success btn-sm">
                                    <i class="bi bi-file-text me-1"></i>Add Menu Items
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="row">
                    <div class="col-12">
                        <div class="management-card">
                            <h5 class="mb-3">
                                <i class="bi bi-clock-history me-2"></i>Menu Management Guide
                            </h5>
                            <div class="row">
                                <div class="col-md-6">
                                    <h6>Getting Started:</h6>
                                    <ol>
                                        <li><strong>Create Menu Sections:</strong> Define the main navigation categories</li>
                                        <li><strong>Add Menu Content:</strong> Add specific menu items to each section</li>
                                        <li><strong>Assign to Roles:</strong> Determine which roles can see which sections</li>
                                        <li><strong>Set Permissions:</strong> Control who can manage menus</li>
                                    </ol>
                                </div>
                                <div class="col-md-6">
                                    <h6>Best Practices:</h6>
                                    <ul>
                                        <li>Use descriptive section names and keys</li>
                                        <li>Set appropriate sort orders for logical grouping</li>
                                        <li>Mark important sections as priority</li>
                                        <li>Regularly review and update menu assignments</li>
                                        <li>Test menu visibility with different roles</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
