<?php
session_start();
require_once '../include/db.php';
require_once '../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$role_name = $_SESSION['role_name'] ?? 'User';
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

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get budget settings
$budget_settings = [];
try {
    $stmt = $conn->query("SELECT setting_key, setting_value FROM budget_settings");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $budget_settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    $budget_settings = [
        'budget_alert_threshold_warning' => '75',
        'budget_alert_threshold_critical' => '90',
        'default_currency' => 'KES'
    ];
}

// Handle category operations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'create_category') {
        try {
            $name = trim($_POST['name']);
            $description = trim($_POST['description'] ?? '');
            $parent_id = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
            $color = $_POST['color'] ?? '#6366f1';
            $icon = $_POST['icon'] ?? 'bi-tag';
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            $stmt = $conn->prepare("
                INSERT INTO budget_categories (name, description, parent_id, color, icon, is_active) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$name, $description, $parent_id, $color, $icon, $is_active]);
            
            $success_message = "Budget category created successfully!";
        } catch (Exception $e) {
            $error_message = "Error creating category: " . $e->getMessage();
        }
    }
    
    if ($_POST['action'] === 'update_category') {
        try {
            $category_id = (int)$_POST['category_id'];
            $name = trim($_POST['name']);
            $description = trim($_POST['description'] ?? '');
            $parent_id = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
            $color = $_POST['color'] ?? '#6366f1';
            $icon = $_POST['icon'] ?? 'bi-tag';
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            // Check if parent_id is not the same as category_id (prevent self-reference)
            if ($parent_id == $category_id) {
                throw new Exception("Category cannot be its own parent");
            }
            
            $stmt = $conn->prepare("
                UPDATE budget_categories 
                SET name = ?, description = ?, parent_id = ?, color = ?, icon = ?, is_active = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$name, $description, $parent_id, $color, $icon, $is_active, $category_id]);
            
            $success_message = "Budget category updated successfully!";
        } catch (Exception $e) {
            $error_message = "Error updating category: " . $e->getMessage();
        }
    }
    
    if ($_POST['action'] === 'delete_category') {
        try {
            $category_id = (int)$_POST['category_id'];
            
            // Check if category has children
            $stmt = $conn->prepare("SELECT COUNT(*) FROM budget_categories WHERE parent_id = ?");
            $stmt->execute([$category_id]);
            $child_count = $stmt->fetchColumn();
            
            if ($child_count > 0) {
                throw new Exception("Cannot delete category with subcategories. Please delete subcategories first.");
            }
            
            // Check if category is used in budget items
            $stmt = $conn->prepare("SELECT COUNT(*) FROM budget_items WHERE category_id = ?");
            $stmt->execute([$category_id]);
            $usage_count = $stmt->fetchColumn();
            
            if ($usage_count > 0) {
                // Soft delete instead
                $stmt = $conn->prepare("UPDATE budget_categories SET is_active = 0 WHERE id = ?");
                $stmt->execute([$category_id]);
                $success_message = "Category deactivated successfully (in use by budget items).";
            } else {
                // Hard delete
                $stmt = $conn->prepare("DELETE FROM budget_categories WHERE id = ?");
                $stmt->execute([$category_id]);
                $success_message = "Category deleted successfully!";
            }
        } catch (Exception $e) {
            $error_message = "Error deleting category: " . $e->getMessage();
        }
    }
    
    if ($_POST['action'] === 'bulk_update') {
        try {
            $action_type = $_POST['bulk_action'];
            $category_ids = $_POST['category_ids'] ?? [];
            
            if (empty($category_ids)) {
                throw new Exception("No categories selected");
            }
            
            $placeholders = str_repeat('?,', count($category_ids) - 1) . '?';
            
            switch ($action_type) {
                case 'activate':
                    $stmt = $conn->prepare("UPDATE budget_categories SET is_active = 1 WHERE id IN ($placeholders)");
                    $stmt->execute($category_ids);
                    $success_message = count($category_ids) . " categories activated successfully!";
                    break;
                case 'deactivate':
                    $stmt = $conn->prepare("UPDATE budget_categories SET is_active = 0 WHERE id IN ($placeholders)");
                    $stmt->execute($category_ids);
                    $success_message = count($category_ids) . " categories deactivated successfully!";
                    break;
                case 'delete':
                    // Check for dependencies first
                    $stmt = $conn->prepare("
                        SELECT COUNT(*) FROM budget_items bi 
                        WHERE bi.category_id IN ($placeholders)
                    ");
                    $stmt->execute($category_ids);
                    $usage_count = $stmt->fetchColumn();
                    
                    if ($usage_count > 0) {
                        // Soft delete
                        $stmt = $conn->prepare("UPDATE budget_categories SET is_active = 0 WHERE id IN ($placeholders)");
                        $stmt->execute($category_ids);
                        $success_message = count($category_ids) . " categories deactivated successfully (in use by budget items).";
                    } else {
                        // Hard delete
                        $stmt = $conn->prepare("DELETE FROM budget_categories WHERE id IN ($placeholders)");
                        $stmt->execute($category_ids);
                        $success_message = count($category_ids) . " categories deleted successfully!";
                    }
                    break;
            }
        } catch (Exception $e) {
            $error_message = "Error performing bulk operation: " . $e->getMessage();
        }
    }
}

// Get categories with hierarchy
$categories = [];
$category_stats = [];

try {
    // Get all categories with parent information
    $stmt = $conn->query("
        SELECT 
            c.*,
            p.name as parent_name,
            (SELECT COUNT(*) FROM budget_categories WHERE parent_id = c.id) as child_count,
            (SELECT COUNT(*) FROM budget_items WHERE category_id = c.id) as usage_count
        FROM budget_categories c
        LEFT JOIN budget_categories p ON c.parent_id = p.id
        ORDER BY c.parent_id IS NULL DESC, c.name
    ");
    $all_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Build hierarchy
    $categories = buildCategoryHierarchy($all_categories);
    
    // Get category statistics
    $stmt = $conn->query("
        SELECT 
            c.id,
            c.name,
            c.color,
            COUNT(bi.id) as budget_items_count,
            COALESCE(SUM(bi.budgeted_amount), 0) as total_budgeted,
            COALESCE(SUM(bi.actual_amount), 0) as total_spent
        FROM budget_categories c
        LEFT JOIN budget_items bi ON c.id = bi.category_id
        WHERE c.is_active = 1
        GROUP BY c.id, c.name, c.color
        ORDER BY total_budgeted DESC
    ");
    $category_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $error_message = "Error loading categories: " . $e->getMessage();
}

// Get category for editing
$edit_category = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    try {
        $stmt = $conn->prepare("
            SELECT c.*, p.name as parent_name
            FROM budget_categories c
            LEFT JOIN budget_categories p ON c.parent_id = p.id
            WHERE c.id = ?
        ");
        $stmt->execute([$_GET['edit']]);
        $edit_category = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $error_message = "Error loading category for editing: " . $e->getMessage();
    }
}

// Function to build category hierarchy
function buildCategoryHierarchy($categories, $parent_id = null) {
    $hierarchy = [];
    foreach ($categories as $category) {
        if ($category['parent_id'] == $parent_id) {
            $category['children'] = buildCategoryHierarchy($categories, $category['id']);
            $hierarchy[] = $category;
        }
    }
    return $hierarchy;
}

// Function to render category tree
function renderCategoryTree($categories, $level = 0) {
    $html = '';
    foreach ($categories as $category) {
        $indent = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $level);
        $html .= '<tr data-category-id="' . $category['id'] . '">';
        $html .= '<td>';
        $html .= $indent;
        if ($level > 0) {
            $html .= '<i class="bi bi-arrow-return-right text-muted me-1"></i>';
        }
        $html .= '<i class="' . $category['icon'] . ' me-2" style="color: ' . $category['color'] . '"></i>';
        $html .= '<strong>' . htmlspecialchars($category['name']) . '</strong>';
        if ($category['child_count'] > 0) {
            $html .= ' <span class="badge bg-secondary ms-1">' . $category['child_count'] . ' subcategories</span>';
        }
        $html .= '</td>';
        $html .= '<td>' . htmlspecialchars($category['description']) . '</td>';
        $html .= '<td>';
        $html .= '<span class="badge bg-' . ($category['is_active'] ? 'success' : 'secondary') . '">';
        $html .= $category['is_active'] ? 'Active' : 'Inactive';
        $html .= '</span>';
        $html .= '</td>';
        $html .= '<td class="text-center">' . $category['usage_count'] . '</td>';
        $html .= '<td class="text-center">';
        $html .= '<div class="btn-group btn-group-sm">';
        $html .= '<button class="btn btn-outline-primary" onclick="editCategory(' . $category['id'] . ')" title="Edit">';
        $html .= '<i class="bi bi-pencil"></i>';
        $html .= '</button>';
        if ($category['usage_count'] == 0) {
            $html .= '<button class="btn btn-outline-danger" onclick="deleteCategory(' . $category['id'] . ')" title="Delete">';
            $html .= '<i class="bi bi-trash"></i>';
            $html .= '</button>';
        } else {
            $html .= '<button class="btn btn-outline-warning" onclick="deactivateCategory(' . $category['id'] . ')" title="Deactivate">';
            $html .= '<i class="bi bi-eye-slash"></i>';
            $html .= '</button>';
        }
        $html .= '</div>';
        $html .= '</td>';
        $html .= '</tr>';
        
        // Render children
        if (!empty($category['children'])) {
            $html .= renderCategoryTree($category['children'], $level + 1);
        }
    }
    return $html;
}

// Available icons for categories
$available_icons = [
    'bi-tag', 'bi-gear', 'bi-people', 'bi-box', 'bi-laptop', 'bi-lightning',
    'bi-building', 'bi-car-front', 'bi-briefcase', 'bi-book', 'bi-shield-check',
    'bi-tools', 'bi-megaphone', 'bi-graph-up', 'bi-cash-stack', 'bi-cart',
    'bi-phone', 'bi-envelope', 'bi-calendar', 'bi-clock', 'bi-star',
    'bi-heart', 'bi-flag', 'bi-award', 'bi-trophy', 'bi-gem'
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Budget Categories - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        .category-tree {
            max-height: 600px;
            overflow-y: auto;
        }
        .category-color-preview {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 8px;
            border: 2px solid #dee2e6;
        }
        .icon-preview {
            font-size: 1.2em;
            margin-right: 8px;
        }
        .stats-card {
            transition: transform 0.2s;
        }
        .stats-card:hover {
            transform: translateY(-2px);
        }
    </style>
</head>
<body>
    <?php include '../include/navmenu.php'; ?>

    <div class="main-content">
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="index.php">Finance Dashboard</a></li>
                            <li class="breadcrumb-item"><a href="budget.php">Budget Management</a></li>
                            <li class="breadcrumb-item active">Budget Categories</li>
                        </ol>
                    </nav>
                    <h1><i class="bi bi-tags"></i> Budget Categories</h1>
                    <p class="header-subtitle">Organize and manage budget categories and subcategories</p>
                </div>
            </div>
        </header>

        <main class="content">
            <div class="container-fluid">
                <?php if (isset($success_message)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle me-2"></i><?php echo $success_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <?php if (isset($error_message)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i><?php echo $error_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <!-- Action Buttons -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="btn-group" role="group">
                                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createCategoryModal">
                                    <i class="bi bi-plus-circle me-1"></i> Create Category
                                </button>
                                <button type="button" class="btn btn-outline-secondary" onclick="toggleBulkActions()">
                                    <i class="bi bi-check2-square me-1"></i> Bulk Actions
                                </button>
                                <a href="budget.php" class="btn btn-outline-primary">
                                    <i class="bi bi-arrow-left me-1"></i> Back to Budgets
                                </a>
                            </div>
                            <div class="btn-group" role="group">
                                <button type="button" class="btn btn-outline-secondary" onclick="exportCategories()">
                                    <i class="bi bi-download me-1"></i> Export
                                </button>
                                <button type="button" class="btn btn-outline-info" onclick="refreshPage()">
                                    <i class="bi bi-arrow-clockwise me-1"></i> Refresh
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Bulk Actions Panel -->
                <div class="row mb-4" id="bulkActionsPanel" style="display: none;">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <form method="POST" action="" id="bulkForm">
                                    <input type="hidden" name="action" value="bulk_update">
                                    <div class="row align-items-center">
                                        <div class="col-md-4">
                                            <label class="form-label">Bulk Action:</label>
                                            <select class="form-select" name="bulk_action" required>
                                                <option value="">Select action...</option>
                                                <option value="activate">Activate Selected</option>
                                                <option value="deactivate">Deactivate Selected</option>
                                                <option value="delete">Delete Selected</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Selected Categories: <span id="selectedCount">0</span></label>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="selectAll" onchange="toggleAllCategories()">
                                                <label class="form-check-label" for="selectAll">
                                                    Select All Categories
                                                </label>
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <button type="submit" class="btn btn-warning" onclick="return confirmBulkAction()">
                                                <i class="bi bi-check2 me-1"></i> Execute
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Category Statistics -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="card stats-card bg-primary text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="card-title mb-1">Total Categories</h6>
                                        <h3 class="mb-0"><?php echo count($all_categories ?? []); ?></h3>
                                        <small class="opacity-75">All categories</small>
                                    </div>
                                    <div>
                                        <i class="bi bi-tags fs-1 opacity-75"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="card stats-card bg-success text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="card-title mb-1">Active Categories</h6>
                                        <h3 class="mb-0"><?php echo count(array_filter($all_categories ?? [], function($cat) { return $cat['is_active']; })); ?></h3>
                                        <small class="opacity-75">Currently active</small>
                                    </div>
                                    <div>
                                        <i class="bi bi-check-circle fs-1 opacity-75"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="card stats-card bg-info text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="card-title mb-1">Subcategories</h6>
                                        <h3 class="mb-0"><?php echo count(array_filter($all_categories ?? [], function($cat) { return $cat['parent_id'] !== null; })); ?></h3>
                                        <small class="opacity-75">Child categories</small>
                                    </div>
                                    <div>
                                        <i class="bi bi-diagram-2 fs-1 opacity-75"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="card stats-card bg-warning text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="card-title mb-1">In Use</h6>
                                        <h3 class="mb-0"><?php echo count(array_filter($all_categories ?? [], function($cat) { return $cat['usage_count'] > 0; })); ?></h3>
                                        <small class="opacity-75">Used in budgets</small>
                                    </div>
                                    <div>
                                        <i class="bi bi-link-45deg fs-1 opacity-75"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Main Content Row -->
                <div class="row">
                    <!-- Categories Tree -->
                    <div class="col-xl-8 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="bi bi-diagram-2 me-2"></i>Category Hierarchy</h6>
                            </div>
                            <div class="card-body">
                                <?php if (empty($categories)): ?>
                                <div class="text-center py-4">
                                    <i class="bi bi-tags fs-1 text-muted mb-3"></i>
                                    <h5 class="text-muted">No Categories Found</h5>
                                    <p class="text-muted mb-3">Create your first budget category to start organizing your budgets</p>
                                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createCategoryModal">
                                        <i class="bi bi-plus-circle me-1"></i> Create Category
                                    </button>
                                </div>
                                <?php else: ?>
                                <div class="table-responsive category-tree">
                                    <table class="table table-hover">
                                        <thead class="table-light">
                                            <tr>
                                                <th width="30">
                                                    <input type="checkbox" id="selectAllTable" onchange="toggleAllCategories()">
                                                </th>
                                                <th>Category Name</th>
                                                <th>Description</th>
                                                <th>Status</th>
                                                <th class="text-center">Usage</th>
                                                <th class="text-center">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php echo renderCategoryTree($categories); ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Category Statistics & Usage -->
                    <div class="col-xl-4 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="bi bi-graph-up me-2"></i>Category Usage Statistics</h6>
                            </div>
                            <div class="card-body">
                                <?php if (empty($category_stats)): ?>
                                <div class="text-center text-muted py-3">
                                    <i class="bi bi-bar-chart fs-3"></i>
                                    <p class="mb-0 mt-2">No usage data available</p>
                                    <small>Categories will appear here once used in budgets</small>
                                </div>
                                <?php else: ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach (array_slice($category_stats, 0, 10) as $stat): ?>
                                    <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                        <div class="d-flex align-items-center">
                                            <div class="category-color-preview" style="background-color: <?php echo $stat['color']; ?>"></div>
                                            <div>
                                                <h6 class="mb-0"><?php echo htmlspecialchars($stat['name']); ?></h6>
                                                <small class="text-muted"><?php echo $stat['budget_items_count']; ?> items</small>
                                            </div>
                                        </div>
                                        <div class="text-end">
                                            <div class="fw-bold"><?php echo htmlspecialchars($budget_settings['default_currency'] ?? 'KES'); ?> <?php echo number_format($stat['total_budgeted'], 0); ?></div>
                                            <small class="text-muted">budgeted</small>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php if (count($category_stats) > 10): ?>
                                <div class="text-center mt-3">
                                    <small class="text-muted">Showing top 10 categories by budget amount</small>
                                </div>
                                <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Create/Edit Category Modal -->
                <div class="modal fade" id="createCategoryModal" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="POST" action="">
                                <input type="hidden" name="action" value="<?php echo $edit_category ? 'update_category' : 'create_category'; ?>">
                                <?php if ($edit_category): ?>
                                <input type="hidden" name="category_id" value="<?php echo $edit_category['id']; ?>">
                                <?php endif; ?>
                                <div class="modal-header">
                                    <h5 class="modal-title">
                                        <i class="bi bi-<?php echo $edit_category ? 'pencil' : 'plus-circle'; ?> me-2"></i>
                                        <?php echo $edit_category ? 'Edit Category' : 'Create New Category'; ?>
                                    </h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label for="name" class="form-label">Category Name *</label>
                                        <input type="text" class="form-control" id="name" name="name" 
                                               value="<?php echo htmlspecialchars($edit_category['name'] ?? ''); ?>" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="description" class="form-label">Description</label>
                                        <textarea class="form-control" id="description" name="description" rows="2"><?php echo htmlspecialchars($edit_category['description'] ?? ''); ?></textarea>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="parent_id" class="form-label">Parent Category</label>
                                        <select class="form-select" id="parent_id" name="parent_id">
                                            <option value="">No parent (Top-level category)</option>
                                            <?php foreach ($all_categories as $cat): ?>
                                            <?php if (!$edit_category || $cat['id'] != $edit_category['id']): ?>
                                            <option value="<?php echo $cat['id']; ?>" 
                                                    <?php echo ($edit_category['parent_id'] ?? '') == $cat['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($cat['name']); ?>
                                            </option>
                                            <?php endif; ?>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="color" class="form-label">Color</label>
                                            <div class="input-group">
                                                <input type="color" class="form-control form-control-color" id="color" name="color" 
                                                       value="<?php echo $edit_category['color'] ?? '#6366f1'; ?>">
                                                <span class="input-group-text" id="colorPreview" 
                                                      style="background-color: <?php echo $edit_category['color'] ?? '#6366f1'; ?>"></span>
                                            </div>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label for="icon" class="form-label">Icon</label>
                                            <select class="form-select" id="icon" name="icon">
                                                <?php foreach ($available_icons as $icon): ?>
                                                <option value="<?php echo $icon; ?>" 
                                                        <?php echo ($edit_category['icon'] ?? 'bi-tag') == $icon ? 'selected' : ''; ?>>
                                                    <?php echo $icon; ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="is_active" name="is_active" 
                                                   <?php echo ($edit_category['is_active'] ?? 1) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="is_active">
                                                Active (can be used in budgets)
                                            </label>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Preview:</label>
                                        <div class="border rounded p-3">
                                            <div class="d-flex align-items-center">
                                                <i id="iconPreview" class="<?php echo $edit_category['icon'] ?? 'bi-tag'; ?> icon-preview" 
                                                   style="color: <?php echo $edit_category['color'] ?? '#6366f1'; ?>"></i>
                                                <div>
                                                    <div id="namePreview" class="fw-bold"><?php echo htmlspecialchars($edit_category['name'] ?? 'Category Name'); ?></div>
                                                    <div id="descriptionPreview" class="text-muted small"><?php echo htmlspecialchars($edit_category['description'] ?? 'Category description'); ?></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-<?php echo $edit_category ? 'check' : 'plus-circle'; ?> me-1"></i>
                                        <?php echo $edit_category ? 'Update Category' : 'Create Category'; ?>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let selectedCategories = new Set();
        
        function toggleBulkActions() {
            const panel = document.getElementById('bulkActionsPanel');
            panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
        }
        
        function toggleAllCategories() {
            const selectAll = document.getElementById('selectAll');
            const selectAllTable = document.getElementById('selectAllTable');
            const checkboxes = document.querySelectorAll('input[type="checkbox"][data-category-id]');
            
            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAll.checked || selectAllTable.checked;
                if (checkbox.checked) {
                    selectedCategories.add(checkbox.dataset.categoryId);
                } else {
                    selectedCategories.delete(checkbox.dataset.categoryId);
                }
            });
            
            updateSelectedCount();
        }
        
        function toggleCategory(categoryId) {
            const checkbox = document.querySelector(`input[data-category-id="${categoryId}"]`);
            if (checkbox.checked) {
                selectedCategories.add(categoryId);
            } else {
                selectedCategories.delete(categoryId);
            }
            updateSelectedCount();
        }
        
        function updateSelectedCount() {
            document.getElementById('selectedCount').textContent = selectedCategories.size;
        }
        
        function confirmBulkAction() {
            const action = document.querySelector('select[name="bulk_action"]').value;
            const count = selectedCategories.size;
            
            if (count === 0) {
                alert('Please select at least one category.');
                return false;
            }
            
            if (!action) {
                alert('Please select an action.');
                return false;
            }
            
            const actionText = action === 'activate' ? 'activate' : action === 'deactivate' ? 'deactivate' : 'delete';
            return confirm(`Are you sure you want to ${actionText} ${count} selected categories?`);
        }
        
        function editCategory(categoryId) {
            window.location.href = `?edit=${categoryId}`;
        }
        
        function deleteCategory(categoryId) {
            if (confirm('Are you sure you want to delete this category? This action cannot be undone.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_category">
                    <input type="hidden" name="category_id" value="${categoryId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function deactivateCategory(categoryId) {
            if (confirm('Are you sure you want to deactivate this category? It will no longer be available for new budgets.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="bulk_update">
                    <input type="hidden" name="bulk_action" value="deactivate">
                    <input type="hidden" name="category_ids[]" value="${categoryId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function exportCategories() {
            let csv = 'Budget Categories Export\n';
            csv += 'Generated: <?php echo date('Y-m-d H:i:s'); ?>\n\n';
            
            csv += 'CATEGORY DETAILS\n';
            csv += 'Name,Description,Parent Category,Status,Usage Count,Color,Icon\n';
            <?php foreach ($all_categories as $category): ?>
            csv += '<?php echo addslashes($category['name']); ?>,<?php echo addslashes($category['description']); ?>,<?php echo addslashes($category['parent_name'] ?? ''); ?>,<?php echo $category['is_active'] ? 'Active' : 'Inactive'; ?>,<?php echo $category['usage_count']; ?>,<?php echo $category['color']; ?>,<?php echo $category['icon']; ?>\n';
            <?php endforeach; ?>
            
            // Download
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.setAttribute('hidden', '');
            a.setAttribute('href', url);
            a.setAttribute('download', 'budget-categories-export-<?php echo date('Y-m-d'); ?>.csv');
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        }
        
        function refreshPage() {
            window.location.reload();
        }
        
        // Update preview when form fields change
        document.getElementById('name')?.addEventListener('input', function() {
            document.getElementById('namePreview').textContent = this.value || 'Category Name';
        });
        
        document.getElementById('description')?.addEventListener('input', function() {
            document.getElementById('descriptionPreview').textContent = this.value || 'Category description';
        });
        
        document.getElementById('color')?.addEventListener('input', function() {
            const color = this.value;
            document.getElementById('colorPreview').style.backgroundColor = color;
            document.getElementById('iconPreview').style.color = color;
        });
        
        document.getElementById('icon')?.addEventListener('change', function() {
            document.getElementById('iconPreview').className = this.value + ' icon-preview';
        });
        
        // Add checkboxes to table rows
        document.addEventListener('DOMContentLoaded', function() {
            const rows = document.querySelectorAll('tr[data-category-id]');
            rows.forEach(row => {
                const categoryId = row.dataset.categoryId;
                const firstCell = row.querySelector('td:first-child');
                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.className = 'form-check-input me-2';
                checkbox.dataset.categoryId = categoryId;
                checkbox.onchange = () => toggleCategory(categoryId);
                firstCell.insertBefore(checkbox, firstCell.firstChild);
            });
        });
    </script>
</body>
</html>
