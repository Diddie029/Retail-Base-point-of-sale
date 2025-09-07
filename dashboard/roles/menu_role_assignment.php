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

if (!$isAdmin && !hasPermission('manage_roles', $permissions) && !hasPermission('assign_menu_roles', $permissions)) {
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

// Handle form submission
if ($_POST) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'assign_menu') {
        $target_role_id = intval($_POST['role_id']);
        $menu_assignments = $_POST['menu_assignments'] ?? [];
        
        try {
            $conn->beginTransaction();
            
            // Clear existing assignments for this role
            $stmt = $conn->prepare("DELETE FROM role_menu_access WHERE role_id = :role_id");
            $stmt->bindParam(':role_id', $target_role_id);
            $stmt->execute();
            
            // Insert new assignments
            $stmt = $conn->prepare("
                INSERT INTO role_menu_access (role_id, menu_section_id, is_visible, is_priority) 
                VALUES (:role_id, :menu_section_id, :is_visible, :is_priority)
            ");
            
            foreach ($menu_assignments as $section_id => $assignment) {
                $is_visible = isset($assignment['visible']) ? 1 : 0;
                $is_priority = isset($assignment['priority']) ? 1 : 0;
                
                $stmt->bindParam(':role_id', $target_role_id);
                $stmt->bindParam(':menu_section_id', $section_id);
                $stmt->bindParam(':is_visible', $is_visible);
                $stmt->bindParam(':is_priority', $is_priority);
                $stmt->execute();
            }
            
            $conn->commit();
            $success_message = "Menu assignments updated successfully!";
            
        } catch (PDOException $e) {
            $conn->rollBack();
            $error_message = "Error updating menu assignments: " . $e->getMessage();
        }
    }
}

// Get all roles (excluding admin for security)
$stmt = $conn->query("
    SELECT id, name, description 
    FROM roles 
    WHERE name != 'Admin' 
    ORDER BY name
");
$roles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all menu sections
$stmt = $conn->query("
    SELECT id, section_key, section_name, section_icon, section_description, sort_order
    FROM menu_sections 
    WHERE is_active = 1 
    ORDER BY sort_order, section_name
");
$menu_sections = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get current assignments for selected role
$current_assignments = [];
$selected_role_id = $_GET['role_id'] ?? 0;

if ($selected_role_id) {
    $stmt = $conn->prepare("
        SELECT menu_section_id, is_visible, is_priority 
        FROM role_menu_access 
        WHERE role_id = :role_id
    ");
    $stmt->bindParam(':role_id', $selected_role_id);
    $stmt->execute();
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($assignments as $assignment) {
        $current_assignments[$assignment['menu_section_id']] = [
            'visible' => $assignment['is_visible'],
            'priority' => $assignment['is_priority']
        ];
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Menu Role Assignment - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
        
        .assignment-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            margin-bottom: 1rem;
        }
        
        .assignment-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .menu-section-item {
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 0.75rem;
            transition: all 0.3s ease;
        }
        
        .menu-section-item:hover {
            border-color: var(--primary-color);
            box-shadow: 0 2px 8px rgba(99, 102, 241, 0.1);
        }
        
        .menu-section-item.selected {
            border-color: var(--primary-color);
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.05), rgba(139, 92, 246, 0.05));
        }
        
        .form-control, .form-select {
            border-radius: 8px;
            border: 2px solid #e2e8f0;
            transition: all 0.3s ease;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
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
                    <h1 class="h2">
                        <i class="bi bi-shield-check me-2"></i>Menu Role Assignment
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <a href="index.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left me-1"></i>Back to Roles
                        </a>
                    </div>
                </div>

                <?php if ($success_message): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle me-2"></i><?php echo $success_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i><?php echo $error_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <div class="row">
                    <!-- Role Selection -->
                    <div class="col-12 mb-4">
                        <div class="assignment-card">
                            <h5 class="mb-3">
                                <i class="bi bi-person-gear me-2"></i>Select Role to Assign Menus
                            </h5>
                            <form method="GET" class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Select Role</label>
                                    <select class="form-select" name="role_id" onchange="this.form.submit()">
                                        <option value="">Choose a role...</option>
                                        <?php foreach ($roles as $role): ?>
                                        <option value="<?php echo $role['id']; ?>" 
                                                <?php echo $selected_role_id == $role['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($role['name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Role Description</label>
                                    <input type="text" class="form-control" 
                                           value="<?php echo $selected_role_id ? htmlspecialchars($roles[array_search($selected_role_id, array_column($roles, 'id'))]['description'] ?? '') : ''; ?>" 
                                           readonly>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Menu Assignment -->
                    <?php if ($selected_role_id): ?>
                    <div class="col-12">
                        <div class="assignment-card">
                            <h5 class="mb-3">
                                <i class="bi bi-list-ul me-2"></i>Assign Menu Sections to Role
                            </h5>
                            <p class="text-muted mb-4">Select which menu sections this role can see and which should be priority sections.</p>
                            
                            <form method="POST">
                                <input type="hidden" name="action" value="assign_menu">
                                <input type="hidden" name="role_id" value="<?php echo $selected_role_id; ?>">
                                
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
                                                                   name="menu_assignments[<?php echo $section['id']; ?>][visible]" 
                                                                   id="visible_<?php echo $section['id']; ?>"
                                                                   <?php echo isset($current_assignments[$section['id']]) && $current_assignments[$section['id']]['visible'] ? 'checked' : ''; ?>
                                                                   onchange="updateSectionSelection(<?php echo $section['id']; ?>)">
                                                            <label class="form-check-label" for="visible_<?php echo $section['id']; ?>">
                                                                <i class="bi bi-eye me-1"></i>Visible
                                                            </label>
                                                        </div>
                                                        <div class="form-check form-check-inline">
                                                            <input class="form-check-input" type="checkbox" 
                                                                   name="menu_assignments[<?php echo $section['id']; ?>][priority]" 
                                                                   id="priority_<?php echo $section['id']; ?>"
                                                                   <?php echo isset($current_assignments[$section['id']]) && $current_assignments[$section['id']]['priority'] ? 'checked' : ''; ?>
                                                                   onchange="updateSectionSelection(<?php echo $section['id']; ?>)">
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
                                
                                <div class="d-flex justify-content-between align-items-center mt-4">
                                    <div class="text-muted">
                                        <i class="bi bi-info-circle me-1"></i>
                                        <strong>Admin Note:</strong> Admins automatically have access to all menu sections regardless of role assignment.
                                    </div>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-check-circle me-1"></i>Save Menu Assignments
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function updateSectionSelection(sectionId) {
            const sectionItem = document.querySelector(`[data-section="${sectionId}"]`);
            const visibleCheckbox = sectionItem.querySelector('input[name*="[visible]"]');
            const priorityCheckbox = sectionItem.querySelector('input[name*="[priority]"]');
            
            if (visibleCheckbox && visibleCheckbox.checked) {
                sectionItem.classList.add('selected');
            } else {
                sectionItem.classList.remove('selected');
                // Uncheck priority if not visible
                if (priorityCheckbox) {
                    priorityCheckbox.checked = false;
                }
            }
        }
        
        // Initialize section selection on page load
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.menu-section-item').forEach(item => {
                const sectionId = item.dataset.section;
                updateSectionSelection(sectionId);
            });
        });
    </script>
</body>
</html>
