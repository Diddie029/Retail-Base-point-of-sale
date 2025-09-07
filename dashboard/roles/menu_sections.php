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

if (!$isAdmin && !hasPermission('manage_menu_sections', $permissions) && !hasPermission('create_menu_sections', $permissions) && !hasPermission('edit_menu_sections', $permissions) && !hasPermission('delete_menu_sections', $permissions)) {
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
    
    if ($action === 'add_section') {
        if (!$isAdmin && !hasPermission('create_menu_sections', $permissions) && !hasPermission('manage_menu_sections', $permissions)) {
            $error_message = "You don't have permission to create menu sections";
        } else {
            $section_key = trim($_POST['section_key']);
            $section_name = trim($_POST['section_name']);
            $section_description = trim($_POST['section_description']);
            $section_icon = trim($_POST['section_icon']);
            $sort_order = intval($_POST['sort_order']);
            
            // Validation
            $errors = [];
            if (empty($section_key)) {
                $errors[] = "Section key is required";
            } elseif (!preg_match('/^[a-z_]+$/', $section_key)) {
                $errors[] = "Section key must contain only lowercase letters and underscores";
            }
            
            if (empty($section_name)) {
                $errors[] = "Section name is required";
            }
            
            if (empty($errors)) {
                // Check if section key already exists
                $stmt = $conn->prepare("SELECT id FROM menu_sections WHERE section_key = :key");
                $stmt->bindParam(':key', $section_key);
                $stmt->execute();
                
                if ($stmt->rowCount() > 0) {
                    $errors[] = "A section with this key already exists";
                } else {
                    try {
                        $stmt = $conn->prepare("
                            INSERT INTO menu_sections (section_key, section_name, section_description, section_icon, sort_order, is_active) 
                            VALUES (:key, :name, :description, :icon, :sort_order, 1)
                        ");
                        $stmt->bindParam(':key', $section_key);
                        $stmt->bindParam(':name', $section_name);
                        $stmt->bindParam(':description', $section_description);
                        $stmt->bindParam(':icon', $section_icon);
                        $stmt->bindParam(':sort_order', $sort_order);
                        $stmt->execute();
                        
                        $success_message = "New menu section created successfully!";
                    } catch (PDOException $e) {
                        $error_message = "Error creating menu section: " . $e->getMessage();
                    }
                }
            }
            
            if (!empty($errors)) {
                $error_message = implode('<br>', $errors);
            }
        }
    }
    
    if ($action === 'edit_section') {
        if (!$isAdmin && !hasPermission('edit_menu_sections', $permissions) && !hasPermission('manage_menu_sections', $permissions)) {
            $error_message = "You don't have permission to edit menu sections";
        } else {
            $section_id = intval($_POST['section_id']);
            $section_key = trim($_POST['section_key']);
            $section_name = trim($_POST['section_name']);
            $section_description = trim($_POST['section_description']);
            $section_icon = trim($_POST['section_icon']);
            $sort_order = intval($_POST['sort_order']);
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            // Validation
            $errors = [];
            if (empty($section_key)) {
                $errors[] = "Section key is required";
            } elseif (!preg_match('/^[a-z_]+$/', $section_key)) {
                $errors[] = "Section key must contain only lowercase letters and underscores";
            }
            
            if (empty($section_name)) {
                $errors[] = "Section name is required";
            }
            
            if (empty($errors)) {
                // Check if section key already exists (excluding current section)
                $stmt = $conn->prepare("SELECT id FROM menu_sections WHERE section_key = :key AND id != :id");
                $stmt->bindParam(':key', $section_key);
                $stmt->bindParam(':id', $section_id);
                $stmt->execute();
                
                if ($stmt->rowCount() > 0) {
                    $errors[] = "A section with this key already exists";
                } else {
                    try {
                        $stmt = $conn->prepare("
                            UPDATE menu_sections 
                            SET section_key = :key, section_name = :name, section_description = :description, 
                                section_icon = :icon, sort_order = :sort_order, is_active = :is_active,
                                updated_at = NOW()
                            WHERE id = :id
                        ");
                        $stmt->bindParam(':key', $section_key);
                        $stmt->bindParam(':name', $section_name);
                        $stmt->bindParam(':description', $section_description);
                        $stmt->bindParam(':icon', $section_icon);
                        $stmt->bindParam(':sort_order', $sort_order);
                        $stmt->bindParam(':is_active', $is_active);
                        $stmt->bindParam(':id', $section_id);
                        $stmt->execute();
                        
                        $success_message = "Menu section updated successfully!";
                    } catch (PDOException $e) {
                        $error_message = "Error updating menu section: " . $e->getMessage();
                    }
                }
            }
            
            if (!empty($errors)) {
                $error_message = implode('<br>', $errors);
            }
        }
    }
    
    if ($action === 'delete_section') {
        if (!$isAdmin && !hasPermission('delete_menu_sections', $permissions) && !hasPermission('manage_menu_sections', $permissions)) {
            $error_message = "You don't have permission to delete menu sections";
        } else {
            $section_id = intval($_POST['section_id']);
            
            try {
                $conn->beginTransaction();
                
                // Delete role menu access first
                $stmt = $conn->prepare("DELETE FROM role_menu_access WHERE menu_section_id = :section_id");
                $stmt->bindParam(':section_id', $section_id);
                $stmt->execute();
                
                // Delete the menu section
                $stmt = $conn->prepare("DELETE FROM menu_sections WHERE id = :section_id");
                $stmt->bindParam(':section_id', $section_id);
                $stmt->execute();
                
                $conn->commit();
                $success_message = "Menu section deleted successfully!";
            } catch (PDOException $e) {
                $conn->rollBack();
                $error_message = "Error deleting menu section: " . $e->getMessage();
            }
        }
    }
    
    if ($action === 'update_sections') {
        $sections = $_POST['sections'] ?? [];
        
        try {
            $conn->beginTransaction();
            
            foreach ($sections as $section_id => $data) {
                $stmt = $conn->prepare("
                    UPDATE menu_sections 
                    SET section_name = :name, section_description = :description, 
                        section_icon = :icon, sort_order = :sort_order, is_active = :is_active
                    WHERE id = :id
                ");
                $stmt->bindParam(':id', $section_id);
                $stmt->bindParam(':name', $data['name']);
                $stmt->bindParam(':description', $data['description']);
                $stmt->bindParam(':icon', $data['icon']);
                $stmt->bindParam(':sort_order', $data['sort_order']);
                $stmt->bindParam(':is_active', $data['is_active']);
                $stmt->execute();
            }
            
            $conn->commit();
            $success_message = "Menu sections updated successfully!";
            
        } catch (PDOException $e) {
            $conn->rollBack();
            $error_message = "Error updating menu sections: " . $e->getMessage();
        }
    }
}

// Get all menu sections
$stmt = $conn->query("
    SELECT * FROM menu_sections 
    ORDER BY sort_order, section_name
");
$menu_sections = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Menu Sections Management - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
        
        .section-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            margin-bottom: 1rem;
        }
        
        .section-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .section-icon {
            font-size: 1.5rem;
            color: var(--primary-color);
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
                        <i class="bi bi-list-ul me-2"></i>Menu Sections Management
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <a href="menu_content.php" class="btn btn-outline-primary me-2">
                            <i class="bi bi-gear me-1"></i>Manage Section Content
                        </a>
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
                    <!-- Add New Section -->
                    <div class="col-12 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">
                                    <i class="bi bi-plus-circle me-2"></i>Add New Menu Section
                                </h5>
                                <p class="text-muted mb-0">Create a new section that can be assigned to roles.</p>
                            </div>
                            <div class="card-body">
                                <form method="POST" class="row g-3">
                                    <input type="hidden" name="action" value="add_section">
                                    
                                    <div class="col-md-3">
                                        <label class="form-label">Section Key <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="section_key" 
                                               placeholder="e.g., reports" required
                                               pattern="[a-z_]+" title="Only lowercase letters and underscores allowed">
                                        <div class="form-text">Unique identifier (lowercase, underscores only)</div>
                                    </div>
                                    
                                    <div class="col-md-3">
                                        <label class="form-label">Section Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="section_name" 
                                               placeholder="e.g., Reports & Analytics" required>
                                    </div>
                                    
                                    <div class="col-md-3">
                                        <label class="form-label">Icon</label>
                                        <input type="text" class="form-control" name="section_icon" 
                                               placeholder="e.g., bi-graph-up" value="bi-circle">
                                        <div class="form-text">Bootstrap Icons class name</div>
                                    </div>
                                    
                                    <div class="col-md-3">
                                        <label class="form-label">Sort Order</label>
                                        <input type="number" class="form-control" name="sort_order" 
                                               value="10" min="1" max="999">
                                    </div>
                                    
                                    <div class="col-12">
                                        <label class="form-label">Description</label>
                                        <textarea class="form-control" name="section_description" rows="2"
                                                  placeholder="Brief description of what this section contains"></textarea>
                                    </div>
                                    
                                    <div class="col-12">
                                        <?php if ($isAdmin || hasPermission('create_menu_sections', $permissions) || hasPermission('manage_menu_sections', $permissions)): ?>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-plus-circle me-1"></i>Add Menu Section
                                        </button>
                                        <?php if ($isAdmin): ?>
                                        <small class="text-muted d-block mt-2">
                                            <i class="bi bi-shield-check me-1"></i>Admin: Full access to all menu operations
                                        </small>
                                        <?php endif; ?>
                                        <?php else: ?>
                                        <div class="alert alert-warning">
                                            <i class="bi bi-exclamation-triangle me-2"></i>You don't have permission to create menu sections.
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Existing Sections -->
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">
                                    <i class="bi bi-gear me-2"></i>Configure Menu Sections
                                </h5>
                                <p class="text-muted mb-0">Manage which sections appear in the navigation menu and their properties.</p>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <input type="hidden" name="action" value="update_sections">
                                    
                                    <div class="row">
                                        <?php foreach ($menu_sections as $section): ?>
                                        <div class="col-md-6 col-lg-4 mb-4">
                                            <div class="section-card">
                                                <div class="d-flex align-items-center justify-content-between mb-3">
                                                    <div class="d-flex align-items-center">
                                                        <i class="bi <?php echo htmlspecialchars($section['section_icon']); ?> section-icon me-3"></i>
                                                        <div>
                                                            <h6 class="mb-0"><?php echo htmlspecialchars($section['section_name']); ?></h6>
                                                            <small class="text-muted"><?php echo htmlspecialchars($section['section_key']); ?></small>
                                                        </div>
                                                    </div>
                                                    <div class="dropdown">
                                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                                            <i class="bi bi-three-dots-vertical"></i>
                                                        </button>
                                                        <ul class="dropdown-menu">
                                                            <?php if ($isAdmin || hasPermission('edit_menu_sections', $permissions) || hasPermission('manage_menu_sections', $permissions)): ?>
                                                            <li>
                                                                <button class="dropdown-item" onclick="editSection(<?php echo htmlspecialchars(json_encode($section)); ?>)">
                                                                    <i class="bi bi-pencil me-2"></i>Edit
                                                                </button>
                                                            </li>
                                                            <?php endif; ?>
                                                            <?php if ($isAdmin || hasPermission('delete_menu_sections', $permissions) || hasPermission('manage_menu_sections', $permissions)): ?>
                                                            <li>
                                                                <button class="dropdown-item text-danger" onclick="deleteSection(<?php echo $section['id']; ?>, '<?php echo htmlspecialchars($section['section_name']); ?>')">
                                                                    <i class="bi bi-trash me-2"></i>Delete
                                                                </button>
                                                            </li>
                                                            <?php endif; ?>
                                                        </ul>
                                                    </div>
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <label class="form-label">Section Name</label>
                                                    <input type="text" class="form-control" 
                                                           name="sections[<?php echo $section['id']; ?>][name]"
                                                           value="<?php echo htmlspecialchars($section['section_name']); ?>">
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <label class="form-label">Description</label>
                                                    <textarea class="form-control" rows="2"
                                                              name="sections[<?php echo $section['id']; ?>][description]"><?php echo htmlspecialchars($section['section_description']); ?></textarea>
                                                </div>
                                                
                                                <div class="row">
                                                    <div class="col-6">
                                                        <label class="form-label">Icon</label>
                                                        <input type="text" class="form-control" 
                                                               name="sections[<?php echo $section['id']; ?>][icon]"
                                                               value="<?php echo htmlspecialchars($section['section_icon']); ?>"
                                                               placeholder="bi-boxes">
                                                    </div>
                                                    <div class="col-6">
                                                        <label class="form-label">Sort Order</label>
                                                        <input type="number" class="form-control" 
                                                               name="sections[<?php echo $section['id']; ?>][sort_order]"
                                                               value="<?php echo $section['sort_order']; ?>">
                                                    </div>
                                                </div>
                                                
                                                <div class="form-check mt-3">
                                                    <input class="form-check-input" type="checkbox" 
                                                           name="sections[<?php echo $section['id']; ?>][is_active]"
                                                           value="1" <?php echo $section['is_active'] ? 'checked' : ''; ?>>
                                                    <label class="form-check-label">
                                                        Active (available for role assignment)
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    
                                    <div class="d-flex justify-content-end">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-check-circle me-1"></i>Update Menu Sections
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Edit Section Modal -->
    <div class="modal fade" id="editSectionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Menu Section</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="editSectionForm">
                    <input type="hidden" name="action" value="edit_section">
                    <input type="hidden" name="section_id" id="edit_section_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Section Key <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="section_key" id="edit_section_key" required
                                   pattern="[a-z_]+" title="Only lowercase letters and underscores allowed">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Section Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="section_name" id="edit_section_name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="section_description" id="edit_section_description" rows="2"></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <label class="form-label">Icon</label>
                                <input type="text" class="form-control" name="section_icon" id="edit_section_icon">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Sort Order</label>
                                <input type="number" class="form-control" name="sort_order" id="edit_sort_order">
                            </div>
                        </div>
                        <div class="form-check mt-3">
                            <input class="form-check-input" type="checkbox" name="is_active" id="edit_is_active" value="1">
                            <label class="form-check-label">Active (available for role assignment)</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Section</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteSectionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm Delete</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="deleteSectionForm">
                    <input type="hidden" name="action" value="delete_section">
                    <input type="hidden" name="section_id" id="delete_section_id">
                    <div class="modal-body">
                        <p>Are you sure you want to delete the menu section <strong id="delete_section_name"></strong>?</p>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            <strong>Warning:</strong> This action will also remove all role assignments for this section and cannot be undone.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete Section</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function editSection(section) {
            document.getElementById('edit_section_id').value = section.id;
            document.getElementById('edit_section_key').value = section.section_key;
            document.getElementById('edit_section_name').value = section.section_name;
            document.getElementById('edit_section_description').value = section.section_description;
            document.getElementById('edit_section_icon').value = section.section_icon;
            document.getElementById('edit_sort_order').value = section.sort_order;
            document.getElementById('edit_is_active').checked = section.is_active == 1;
            
            new bootstrap.Modal(document.getElementById('editSectionModal')).show();
        }
        
        function deleteSection(sectionId, sectionName) {
            document.getElementById('delete_section_id').value = sectionId;
            document.getElementById('delete_section_name').textContent = sectionName;
            
            new bootstrap.Modal(document.getElementById('deleteSectionModal')).show();
        }
    </script>
</body>
</html>
