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

// Check permissions - Only admins can manage pages
$role_name = $_SESSION['role_name'] ?? 'User';
$isAdmin = (
    $role_name === 'Admin' || 
    $role_name === 'admin' || 
    $role_name === 'Administrator' || 
    $role_name === 'administrator' ||
    hasPermission('manage_roles', $permissions) ||
    hasPermission('manage_users', $permissions)
);

if (!$isAdmin) {
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
    
    if ($action === 'sync_pages') {
        try {
            syncPagesWithDatabase($conn);
            $success_message = "Pages synchronized successfully! New pages have been discovered and added.";
        } catch (Exception $e) {
            $error_message = "Error synchronizing pages: " . $e->getMessage();
        }
    } elseif ($action === 'toggle_page') {
        $page_id = (int)$_POST['page_id'];
        $is_active = (int)$_POST['is_active'];
        
        try {
            $stmt = $conn->prepare("UPDATE available_pages SET is_active = ? WHERE id = ?");
            $stmt->execute([$is_active, $page_id]);
            $success_message = "Page status updated successfully!";
        } catch (Exception $e) {
            $error_message = "Error updating page status: " . $e->getMessage();
        }
    } elseif ($action === 'delete_page') {
        $page_id = (int)$_POST['page_id'];
        
        try {
            $stmt = $conn->prepare("DELETE FROM available_pages WHERE id = ?");
            $stmt->execute([$page_id]);
            $success_message = "Page removed successfully!";
        } catch (Exception $e) {
            $error_message = "Error removing page: " . $e->getMessage();
        }
    } elseif ($action === 'update_page') {
        $page_id = (int)$_POST['page_id'];
        $page_name = trim($_POST['page_name']);
        $page_description = trim($_POST['page_description']);
        $is_admin_only = isset($_POST['is_admin_only']) ? 1 : 0;
        $required_permission = trim($_POST['required_permission']) ?: null;
        
        try {
            $stmt = $conn->prepare("
                UPDATE available_pages 
                SET page_name = ?, page_description = ?, is_admin_only = ?, required_permission = ?
                WHERE id = ?
            ");
            $stmt->execute([$page_name, $page_description, $is_admin_only, $required_permission, $page_id]);
            $success_message = "Page updated successfully!";
        } catch (Exception $e) {
            $error_message = "Error updating page: " . $e->getMessage();
        }
    } elseif ($action === 'bulk_action') {
        $bulk_action = $_POST['bulk_action'] ?? '';
        $selected_pages = $_POST['selected_pages'] ?? [];
        
        if (empty($selected_pages)) {
            $error_message = "Please select at least one page.";
        } else {
            $page_ids = array_map('intval', $selected_pages);
            $placeholders = str_repeat('?,', count($page_ids) - 1) . '?';
            
            try {
                switch ($bulk_action) {
                    case 'activate':
                        $stmt = $conn->prepare("UPDATE available_pages SET is_active = 1 WHERE id IN ($placeholders)");
                        $stmt->execute($page_ids);
                        $success_message = count($page_ids) . " page(s) activated successfully!";
                        break;
                        
                    case 'deactivate':
                        $stmt = $conn->prepare("UPDATE available_pages SET is_active = 0 WHERE id IN ($placeholders)");
                        $stmt->execute($page_ids);
                        $success_message = count($page_ids) . " page(s) deactivated successfully!";
                        break;
                        
                    case 'delete':
                        $stmt = $conn->prepare("DELETE FROM available_pages WHERE id IN ($placeholders)");
                        $stmt->execute($page_ids);
                        $success_message = count($page_ids) . " page(s) deleted successfully!";
                        break;
                        
                    case 'set_admin_only':
                        $stmt = $conn->prepare("UPDATE available_pages SET is_admin_only = 1 WHERE id IN ($placeholders)");
                        $stmt->execute($page_ids);
                        $success_message = count($page_ids) . " page(s) set as admin-only successfully!";
                        break;
                        
                    case 'remove_admin_only':
                        $stmt = $conn->prepare("UPDATE available_pages SET is_admin_only = 0 WHERE id IN ($placeholders)");
                        $stmt->execute($page_ids);
                        $success_message = count($page_ids) . " page(s) removed from admin-only successfully!";
                        break;
                        
                    case 'set_permission':
                        $permission = trim($_POST['bulk_permission']) ?: null;
                        $stmt = $conn->prepare("UPDATE available_pages SET required_permission = ? WHERE id IN ($placeholders)");
                        $stmt->execute(array_merge([$permission], $page_ids));
                        $success_message = count($page_ids) . " page(s) permission updated successfully!";
                        break;
                        
                    default:
                        $error_message = "Invalid bulk action selected.";
                }
            } catch (Exception $e) {
                $error_message = "Error performing bulk action: " . $e->getMessage();
            }
        }
    }
}

// Get all available pages
$stmt = $conn->query("
    SELECT * FROM available_pages 
    ORDER BY page_category, sort_order, page_name
");
$all_pages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group pages by category
$pages_by_category = [];
foreach ($all_pages as $page) {
    $category = $page['page_category'];
    if (!isset($pages_by_category[$category])) {
        $pages_by_category[$category] = [];
    }
    $pages_by_category[$category][] = $page;
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Available Pages - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
        
        .content-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            margin-bottom: 1rem;
        }
        
        .content-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .page-item {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 0.5rem;
            transition: all 0.3s ease;
        }
        
        .page-item:hover {
            border-color: var(--primary-color);
            box-shadow: 0 2px 8px rgba(99, 102, 241, 0.1);
        }
        
        .page-item.inactive {
            opacity: 0.6;
            background-color: #f8f9fa;
        }
        
        .category-header {
            background: linear-gradient(135deg, var(--primary-color), #8b5cf6);
            color: white;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }
        
        .stats-card {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .stats-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
        }
        
        .page-checkbox {
            transform: scale(1.1);
        }
        
        .page-item.selected {
            border-color: var(--primary-color);
            background-color: rgba(99, 102, 241, 0.05);
        }
        
        .bulk-actions-card {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border: 1px solid #dee2e6;
        }
        
        .bulk-actions-card .form-check-input:checked {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .bulk-actions-card .form-check-input:indeterminate {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .selected-count {
            font-weight: 600;
            color: var(--primary-color);
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
                        <i class="bi bi-gear me-2"></i>Manage Available Pages
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <a href="menu_content.php" class="btn btn-outline-secondary me-2">
                            <i class="bi bi-arrow-left me-1"></i>Back to Menu Content
                        </a>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="action" value="sync_pages">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-arrow-clockwise me-1"></i>Sync Pages
                            </button>
                        </form>
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

                <!-- Statistics -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="stats-card">
                            <div class="stats-number"><?php echo count($all_pages); ?></div>
                            <div class="text-muted">Total Pages</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card">
                            <div class="stats-number"><?php echo count(array_filter($all_pages, fn($p) => $p['is_active'])); ?></div>
                            <div class="text-muted">Active Pages</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card">
                            <div class="stats-number"><?php echo count(array_filter($all_pages, fn($p) => $p['is_admin_only'])); ?></div>
                            <div class="text-muted">Admin Only</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card">
                            <div class="stats-number"><?php echo count($pages_by_category); ?></div>
                            <div class="text-muted">Categories</div>
                        </div>
                    </div>
                </div>

                <!-- Bulk Actions -->
                <div class="content-card bulk-actions-card mb-4">
                    <h5 class="mb-3">
                        <i class="bi bi-check2-square me-2"></i>Bulk Actions
                    </h5>
                    <form method="POST" id="bulkActionForm">
                        <input type="hidden" name="action" value="bulk_action">
                        <div class="row align-items-end">
                            <div class="col-md-3">
                                <label class="form-label">Select All</label>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="selectAll">
                                    <label class="form-check-label" for="selectAll">
                                        Select All Pages
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Bulk Action</label>
                                <select class="form-select" name="bulk_action" id="bulkActionSelect" required>
                                    <option value="">Choose Action</option>
                                    <option value="activate">Activate Selected</option>
                                    <option value="deactivate">Deactivate Selected</option>
                                    <option value="set_admin_only">Set as Admin Only</option>
                                    <option value="remove_admin_only">Remove Admin Only</option>
                                    <option value="set_permission">Set Permission</option>
                                    <option value="delete" class="text-danger">Delete Selected</option>
                                </select>
                            </div>
                            <div class="col-md-3" id="permissionField" style="display: none;">
                                <label class="form-label">Permission</label>
                                <input type="text" class="form-control" name="bulk_permission" 
                                       placeholder="e.g., manage_inventory">
                            </div>
                            <div class="col-md-3">
                                <button type="submit" class="btn btn-primary" id="bulkActionBtn" disabled>
                                    <i class="bi bi-play me-1"></i>Execute
                                </button>
                                <span class="selected-count ms-2" id="selectedCount">0 selected</span>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Pages by Category -->
                <?php foreach ($pages_by_category as $category => $pages): ?>
                <div class="content-card">
                    <div class="category-header">
                        <h5 class="mb-0">
                            <i class="bi bi-folder me-2"></i><?php echo htmlspecialchars($category); ?>
                            <span class="badge bg-light text-dark ms-2"><?php echo count($pages); ?> pages</span>
                        </h5>
                    </div>
                    
                    <div class="row">
                        <?php foreach ($pages as $page): ?>
                        <div class="col-md-6 col-lg-4 mb-3">
                            <div class="page-item <?php echo $page['is_active'] ? '' : 'inactive'; ?>">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div class="d-flex align-items-center">
                                        <input type="checkbox" class="form-check-input me-2 page-checkbox" 
                                               name="selected_pages[]" value="<?php echo $page['id']; ?>" 
                                               id="page_<?php echo $page['id']; ?>">
                                        <h6 class="mb-1"><?php echo htmlspecialchars($page['page_name']); ?></h6>
                                    </div>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="dropdown">
                                            <i class="bi bi-three-dots-vertical"></i>
                                        </button>
                                        <ul class="dropdown-menu">
                                            <li>
                                                <button class="dropdown-item" onclick="editPage(<?php echo htmlspecialchars(json_encode($page)); ?>)">
                                                    <i class="bi bi-pencil me-2"></i>Edit
                                                </button>
                                            </li>
                                            <li>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="action" value="toggle_page">
                                                    <input type="hidden" name="page_id" value="<?php echo $page['id']; ?>">
                                                    <input type="hidden" name="is_active" value="<?php echo $page['is_active'] ? 0 : 1; ?>">
                                                    <button type="submit" class="dropdown-item">
                                                        <i class="bi bi-<?php echo $page['is_active'] ? 'eye-slash' : 'eye'; ?> me-2"></i>
                                                        <?php echo $page['is_active'] ? 'Hide' : 'Show'; ?>
                                                    </button>
                                                </form>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to remove this page?')">
                                                    <input type="hidden" name="action" value="delete_page">
                                                    <input type="hidden" name="page_id" value="<?php echo $page['id']; ?>">
                                                    <button type="submit" class="dropdown-item text-danger">
                                                        <i class="bi bi-trash me-2"></i>Remove
                                                    </button>
                                                </form>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                                
                                <p class="text-muted small mb-2">
                                    <code><?php echo htmlspecialchars($page['page_url']); ?></code>
                                </p>
                                
                                <?php if ($page['page_description']): ?>
                                <p class="small mb-2"><?php echo htmlspecialchars($page['page_description']); ?></p>
                                <?php endif; ?>
                                
                                <div class="d-flex gap-2 flex-wrap">
                                    <?php if ($page['is_active']): ?>
                                    <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                    <span class="badge bg-secondary">Inactive</span>
                                    <?php endif; ?>
                                    
                                    <?php if ($page['is_admin_only']): ?>
                                    <span class="badge bg-warning">Admin Only</span>
                                    <?php endif; ?>
                                    
                                    <?php if ($page['required_permission']): ?>
                                    <span class="badge bg-info"><?php echo htmlspecialchars($page['required_permission']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </main>
        </div>
    </div>

    <!-- Edit Page Modal -->
    <div class="modal fade" id="editPageModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" id="editPageForm">
                    <input type="hidden" name="action" value="update_page">
                    <input type="hidden" name="page_id" id="edit_page_id">
                    
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Page</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Page Name</label>
                            <input type="text" class="form-control" name="page_name" id="edit_page_name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="page_description" id="edit_page_description" rows="3"></textarea>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_admin_only" id="edit_is_admin_only">
                                <label class="form-check-label" for="edit_is_admin_only">
                                    Admin Only
                                </label>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Required Permission</label>
                            <input type="text" class="form-control" name="required_permission" id="edit_required_permission" 
                                   placeholder="e.g., manage_inventory">
                            <div class="form-text">Leave empty for pages accessible to all users</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Page</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function editPage(page) {
            document.getElementById('edit_page_id').value = page.id;
            document.getElementById('edit_page_name').value = page.page_name;
            document.getElementById('edit_page_description').value = page.page_description || '';
            document.getElementById('edit_is_admin_only').checked = page.is_admin_only == 1;
            document.getElementById('edit_required_permission').value = page.required_permission || '';
            
            new bootstrap.Modal(document.getElementById('editPageModal')).show();
        }

        // Bulk operations functionality
        document.addEventListener('DOMContentLoaded', function() {
            const selectAllCheckbox = document.getElementById('selectAll');
            const pageCheckboxes = document.querySelectorAll('.page-checkbox');
            const bulkActionSelect = document.getElementById('bulkActionSelect');
            const permissionField = document.getElementById('permissionField');
            const bulkActionBtn = document.getElementById('bulkActionBtn');
            const selectedCount = document.getElementById('selectedCount');
            const bulkActionForm = document.getElementById('bulkActionForm');

            // Select all functionality
            selectAllCheckbox.addEventListener('change', function() {
                pageCheckboxes.forEach(checkbox => {
                    checkbox.checked = this.checked;
                    updatePageItemSelection(checkbox);
                });
                updateSelectedCount();
                updateBulkActionButton();
            });

            // Individual checkbox change
            pageCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    updateSelectedCount();
                    updateBulkActionButton();
                    updateSelectAllState();
                    updatePageItemSelection(this);
                });
            });

            // Bulk action select change
            bulkActionSelect.addEventListener('change', function() {
                if (this.value === 'set_permission') {
                    permissionField.style.display = 'block';
                } else {
                    permissionField.style.display = 'none';
                }
                updateBulkActionButton();
            });

            // Form submission confirmation
            bulkActionForm.addEventListener('submit', function(e) {
                const selectedPages = document.querySelectorAll('.page-checkbox:checked');
                const action = bulkActionSelect.value;
                
                if (selectedPages.length === 0) {
                    e.preventDefault();
                    alert('Please select at least one page.');
                    return;
                }

                let confirmMessage = '';
                switch (action) {
                    case 'activate':
                        confirmMessage = `Are you sure you want to activate ${selectedPages.length} page(s)?`;
                        break;
                    case 'deactivate':
                        confirmMessage = `Are you sure you want to deactivate ${selectedPages.length} page(s)?`;
                        break;
                    case 'set_admin_only':
                        confirmMessage = `Are you sure you want to set ${selectedPages.length} page(s) as admin-only?`;
                        break;
                    case 'remove_admin_only':
                        confirmMessage = `Are you sure you want to remove admin-only status from ${selectedPages.length} page(s)?`;
                        break;
                    case 'set_permission':
                        const permission = document.querySelector('input[name="bulk_permission"]').value;
                        if (!permission) {
                            e.preventDefault();
                            alert('Please enter a permission for the selected pages.');
                            return;
                        }
                        confirmMessage = `Are you sure you want to set permission "${permission}" for ${selectedPages.length} page(s)?`;
                        break;
                    case 'delete':
                        confirmMessage = `Are you sure you want to DELETE ${selectedPages.length} page(s)? This action cannot be undone!`;
                        break;
                }

                if (!confirm(confirmMessage)) {
                    e.preventDefault();
                }
            });

            function updateSelectedCount() {
                const selectedPages = document.querySelectorAll('.page-checkbox:checked');
                selectedCount.textContent = `${selectedPages.length} selected`;
            }

            function updateBulkActionButton() {
                const selectedPages = document.querySelectorAll('.page-checkbox:checked');
                const action = bulkActionSelect.value;
                
                if (selectedPages.length > 0 && action) {
                    bulkActionBtn.disabled = false;
                    if (action === 'delete') {
                        bulkActionBtn.className = 'btn btn-danger';
                        bulkActionBtn.innerHTML = '<i class="bi bi-trash me-1"></i>Delete Selected';
                    } else {
                        bulkActionBtn.className = 'btn btn-primary';
                        bulkActionBtn.innerHTML = '<i class="bi bi-play me-1"></i>Execute';
                    }
                } else {
                    bulkActionBtn.disabled = true;
                    bulkActionBtn.className = 'btn btn-primary';
                    bulkActionBtn.innerHTML = '<i class="bi bi-play me-1"></i>Execute';
                }
            }

            function updateSelectAllState() {
                const selectedPages = document.querySelectorAll('.page-checkbox:checked');
                const totalPages = pageCheckboxes.length;
                
                if (selectedPages.length === 0) {
                    selectAllCheckbox.indeterminate = false;
                    selectAllCheckbox.checked = false;
                } else if (selectedPages.length === totalPages) {
                    selectAllCheckbox.indeterminate = false;
                    selectAllCheckbox.checked = true;
                } else {
                    selectAllCheckbox.indeterminate = true;
                    selectAllCheckbox.checked = false;
                }
            }

            function updatePageItemSelection(checkbox) {
                const pageItem = checkbox.closest('.page-item');
                if (checkbox.checked) {
                    pageItem.classList.add('selected');
                } else {
                    pageItem.classList.remove('selected');
                }
            }

            // Initialize
            updateSelectedCount();
            updateBulkActionButton();
        });
    </script>
</body>
</html>
