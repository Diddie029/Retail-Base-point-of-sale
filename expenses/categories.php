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
$user_role = $_SESSION['role'] ?? $_SESSION['role_name'] ?? 'User';
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

// Get system settings for navmenu display
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Check permissions
if (!hasPermission('manage_expense_categories', $permissions)) {
    header('Location: index.php');
    exit();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action == 'add_category') {
        $name = trim($_POST['name']);
        $description = trim($_POST['description']);
        $parent_id = $_POST['parent_id'] ?: null;
        $color_code = $_POST['color_code'];
        $is_tax_deductible = isset($_POST['is_tax_deductible']) ? 1 : 0;
        $sort_order = intval($_POST['sort_order']);
        
        if (empty($name)) {
            $_SESSION['error_message'] = "Category name is required";
        } else {
            try {
                // Check if is_tax_deductible column exists
                $stmt = $conn->prepare("
                    SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
                    WHERE TABLE_NAME = 'expense_categories' AND COLUMN_NAME = 'is_tax_deductible'
                ");
                $stmt->execute();
                $column_exists = $stmt->fetchColumn();
                
                if ($column_exists) {
                    $stmt = $conn->prepare("
                        INSERT INTO expense_categories (name, description, parent_id, color_code, is_tax_deductible, sort_order)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$name, $description, $parent_id, $color_code, $is_tax_deductible, $sort_order]);
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO expense_categories (name, description, parent_id, color_code, sort_order)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$name, $description, $parent_id, $color_code, $sort_order]);
                }
                
                $_SESSION['success_message'] = "Category added successfully";
                header('Location: categories.php');
                exit();
            } catch (PDOException $e) {
                $_SESSION['error_message'] = "Error adding category: " . $e->getMessage();
            }
        }
    } elseif ($action == 'edit_category') {
        $id = $_POST['id'];
        $name = trim($_POST['name']);
        $description = trim($_POST['description']);
        $parent_id = $_POST['parent_id'] ?: null;
        $color_code = $_POST['color_code'];
        $is_tax_deductible = isset($_POST['is_tax_deductible']) ? 1 : 0;
        $sort_order = intval($_POST['sort_order']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        if (empty($name)) {
            $_SESSION['error_message'] = "Category name is required";
        } else {
            try {
                // Check if is_tax_deductible column exists
                $stmt = $conn->prepare("
                    SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
                    WHERE TABLE_NAME = 'expense_categories' AND COLUMN_NAME = 'is_tax_deductible'
                ");
                $stmt->execute();
                $column_exists = $stmt->fetchColumn();
                
                if ($column_exists) {
                    $stmt = $conn->prepare("
                        UPDATE expense_categories 
                        SET name = ?, description = ?, parent_id = ?, color_code = ?, 
                            is_tax_deductible = ?, sort_order = ?, is_active = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$name, $description, $parent_id, $color_code, $is_tax_deductible, $sort_order, $is_active, $id]);
                } else {
                    $stmt = $conn->prepare("
                        UPDATE expense_categories 
                        SET name = ?, description = ?, parent_id = ?, color_code = ?, 
                            sort_order = ?, is_active = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$name, $description, $parent_id, $color_code, $sort_order, $is_active, $id]);
                }
                
                $_SESSION['success_message'] = "Category updated successfully";
                header('Location: categories.php');
                exit();
            } catch (PDOException $e) {
                $_SESSION['error_message'] = "Error updating category: " . $e->getMessage();
            }
        }
    } elseif ($action == 'delete_category') {
        $id = $_POST['id'];
        
        try {
            // Check if category has subcategories
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM expense_categories WHERE parent_id = ?");
            $stmt->execute([$id]);
            $subcategory_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            if ($subcategory_count > 0) {
                $_SESSION['error_message'] = "Cannot delete category with subcategories. Please delete subcategories first.";
            } else {
                // Check if category is used in expenses
                $stmt = $conn->prepare("SELECT COUNT(*) as count FROM expenses WHERE category_id = ? OR subcategory_id = ?");
                $stmt->execute([$id, $id]);
                $expense_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                
                if ($expense_count > 0) {
                    $_SESSION['error_message'] = "Cannot delete category that is used in expenses.";
                } else {
                    $stmt = $conn->prepare("DELETE FROM expense_categories WHERE id = ?");
                    $stmt->execute([$id]);
                    
                    $_SESSION['success_message'] = "Category deleted successfully";
                }
            }
            header('Location: categories.php');
            exit();
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error deleting category: " . $e->getMessage();
        }
    } elseif ($action == 'bulk_delete') {
        $category_ids = $_POST['category_ids'] ?? [];
        
        if (empty($category_ids)) {
            $_SESSION['error_message'] = "No categories selected for deletion";
        } else {
            try {
                $conn->beginTransaction();
                
                $deleted_count = 0;
                $skipped_count = 0;
                $skipped_names = [];
                
                foreach ($category_ids as $id) {
                    // Check if category has subcategories
                    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM expense_categories WHERE parent_id = ?");
                    $stmt->execute([$id]);
                    $subcategory_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                    
                    // Check if category is used in expenses
                    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM expenses WHERE category_id = ? OR subcategory_id = ?");
                    $stmt->execute([$id, $id]);
                    $expense_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                    
                    // Get category name for feedback
                    $stmt = $conn->prepare("SELECT name FROM expense_categories WHERE id = ?");
                    $stmt->execute([$id]);
                    $category_name = $stmt->fetchColumn();
                    
                    if ($subcategory_count > 0 || $expense_count > 0) {
                        $skipped_count++;
                        $skipped_names[] = $category_name;
                    } else {
                        $stmt = $conn->prepare("DELETE FROM expense_categories WHERE id = ?");
                        $stmt->execute([$id]);
                        $deleted_count++;
                    }
                }
                
                $conn->commit();
                
                $message = "";
                if ($deleted_count > 0) {
                    $message .= "Successfully deleted {$deleted_count} categories.";
                }
                if ($skipped_count > 0) {
                    $message .= ($message ? " " : "") . "Skipped {$skipped_count} categories (" . implode(', ', array_slice($skipped_names, 0, 3)) . ($skipped_count > 3 ? '...' : '') . ") because they have subcategories or are used in expenses.";
                }
                
                if ($deleted_count > 0) {
                    $_SESSION['success_message'] = $message;
                } else {
                    $_SESSION['error_message'] = $message ?: "No categories could be deleted.";
                }
                
            } catch (PDOException $e) {
                $conn->rollback();
                $_SESSION['error_message'] = "Error during bulk delete: " . $e->getMessage();
            }
        }
        header('Location: categories.php');
        exit();
    } elseif ($action == 'bulk_activate') {
        $category_ids = $_POST['category_ids'] ?? [];
        
        if (empty($category_ids)) {
            $_SESSION['error_message'] = "No categories selected for activation";
        } else {
            try {
                $placeholders = str_repeat('?,', count($category_ids) - 1) . '?';
                $stmt = $conn->prepare("UPDATE expense_categories SET is_active = 1 WHERE id IN ({$placeholders})");
                $stmt->execute($category_ids);
                
                $_SESSION['success_message'] = "Successfully activated " . count($category_ids) . " categories.";
                
            } catch (PDOException $e) {
                $_SESSION['error_message'] = "Error during bulk activation: " . $e->getMessage();
            }
        }
        header('Location: categories.php');
        exit();
    } elseif ($action == 'bulk_deactivate') {
        $category_ids = $_POST['category_ids'] ?? [];
        
        if (empty($category_ids)) {
            $_SESSION['error_message'] = "No categories selected for deactivation";
        } else {
            try {
                $placeholders = str_repeat('?,', count($category_ids) - 1) . '?';
                $stmt = $conn->prepare("UPDATE expense_categories SET is_active = 0 WHERE id IN ({$placeholders})");
                $stmt->execute($category_ids);
                
                $_SESSION['success_message'] = "Successfully deactivated " . count($category_ids) . " categories.";
                
            } catch (PDOException $e) {
                $_SESSION['error_message'] = "Error during bulk deactivation: " . $e->getMessage();
            }
        }
        header('Location: categories.php');
        exit();
    }
}

// Get categories with DISTINCT to prevent duplicates
$categories = $conn->query("
    SELECT DISTINCT c.id, c.name, c.description, c.parent_id, c.color_code, 
           c.is_tax_deductible, c.is_active, c.sort_order, c.created_at, c.updated_at,
           p.name as parent_name,
           (SELECT COUNT(*) FROM expense_categories WHERE parent_id = c.id) as subcategory_count,
           (SELECT COUNT(*) FROM expenses WHERE category_id = c.id OR subcategory_id = c.id) as expense_count
    FROM expense_categories c
    LEFT JOIN expense_categories p ON c.parent_id = p.id
    ORDER BY c.parent_id IS NULL DESC, c.sort_order, c.name
")->fetchAll(PDO::FETCH_ASSOC);

// Get parent categories for dropdown
$parent_categories = $conn->query("
    SELECT id, name FROM expense_categories 
    WHERE parent_id IS NULL AND is_active = 1 
    ORDER BY sort_order, name
")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expense Categories - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
    </style>
</head>
<body>
    <?php include '../include/navmenu.php'; ?>

    <div class="main-content">
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1><i class="bi bi-tags"></i> Expense Categories</h1>
                    <p class="header-subtitle">Manage and organize your expense categories</p>
                </div>
                <div class="header-actions">
                    <div class="btn-group me-2" role="group">
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                            <i class="bi bi-plus-circle"></i> Add Category
                        </button>
                        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addSubcategoryModal">
                            <i class="bi bi-diagram-3"></i> Add Subcategory
                        </button>
                    </div>
                    <a href="index.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Back to Expenses
                    </a>
                </div>
            </div>
        </header>

        <main class="content">
            <div class="container-fluid">

                <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <?= htmlspecialchars($_SESSION['success_message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['success_message']); endif; ?>

                <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <?= htmlspecialchars($_SESSION['error_message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['error_message']); endif; ?>

                <div class="card">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="bi bi-list-ul"></i> Categories List</h5>
                            <div class="btn-group" role="group">
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="toggleCategorySelection()">
                                    <i class="bi bi-check-all"></i> Select All
                                </button>
                                <div class="btn-group" role="group">
                                    <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" disabled id="bulkActionsBtn">
                                        <i class="bi bi-gear"></i> Bulk Actions
                                    </button>
                                    <ul class="dropdown-menu">
                                        <li><a class="dropdown-item text-danger" href="#" onclick="bulkDeleteCategories()"><i class="bi bi-trash"></i> Delete Selected</a></li>
                                        <li><a class="dropdown-item" href="#" onclick="bulkActivateCategories()"><i class="bi bi-check-circle"></i> Activate Selected</a></li>
                                        <li><a class="dropdown-item" href="#" onclick="bulkDeactivateCategories()"><i class="bi bi-x-circle"></i> Deactivate Selected</a></li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><a class="dropdown-item" href="#" onclick="bulkExportCategories()"><i class="bi bi-download"></i> Export Selected</a></li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($categories)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-tags fs-1 text-muted"></i>
                            <h5 class="mt-3">No categories found</h5>
                            <p class="text-muted">Create your first expense category to get started.</p>
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                                <i class="bi bi-plus-circle"></i> Add Category
                            </button>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th><input type="checkbox" class="form-check-input" id="selectAllCategories" onchange="toggleAllCategories()"></th>
                                        <th>Name</th>
                                        <th>Type</th>
                                        <th>Description</th>
                                        <th>Color</th>
                                        <th>Tax Deductible</th>
                                        <th>Usage</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($categories as $category): ?>
                                    <tr class="category-row" 
                                        data-category-id="<?= $category['id'] ?>" 
                                        data-category-name="<?= htmlspecialchars($category['name']) ?>"
                                        data-has-subcategories="<?= $category['subcategory_count'] ?>"
                                        data-has-expenses="<?= $category['expense_count'] ?>">
                                        <td>
                                            <input type="checkbox" class="form-check-input category-checkbox" 
                                                   value="<?= $category['id'] ?>" 
                                                   onchange="updateBulkActions()"
                                                   <?= ($category['expense_count'] > 0 || $category['subcategory_count'] > 0) ? 'data-has-dependencies="true"' : '' ?>>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <?php if ($category['parent_id']): ?>
                                                <span class="me-2 text-muted">├── </span>
                                                <?php endif; ?>
                                                <span class="badge me-2" style="background-color: <?= $category['color_code'] ?>; width: 20px; height: 20px;"></span>
                                                <div>
                                                    <strong><?= htmlspecialchars($category['name']) ?></strong>
                                                    <?php if ($category['parent_name']): ?>
                                                    <br><small class="text-muted"><i class="bi bi-arrow-return-right"></i> Under: <?= htmlspecialchars($category['parent_name']) ?></small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($category['parent_id']): ?>
                                            <span class="badge bg-info">Subcategory</span>
                                            <?php else: ?>
                                            <span class="badge bg-primary">Category</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($category['description'] ?: 'No description') ?>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div style="width: 30px; height: 20px; background-color: <?= $category['color_code'] ?>; border-radius: 4px; margin-right: 8px;"></div>
                                                <code><?= $category['color_code'] ?></code>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if (isset($category['is_tax_deductible']) && $category['is_tax_deductible']): ?>
                                            <span class="badge bg-success">Yes</span>
                                            <?php else: ?>
                                            <span class="badge bg-secondary">No</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div>
                                                <small class="text-muted">
                                                    <?php if ($category['parent_id']): ?>
                                                    <?= $category['expense_count'] ?> expenses
                                                    <?php else: ?>
                                                    <?= $category['subcategory_count'] ?> subcategories, <?= $category['expense_count'] ?> expenses
                                                    <?php endif; ?>
                                                </small>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($category['is_active']): ?>
                                            <span class="badge bg-success">Active</span>
                                            <?php else: ?>
                                            <span class="badge bg-danger">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <button type="button" class="btn btn-sm btn-outline-primary" 
                                                        onclick="editCategory(<?= htmlspecialchars(json_encode($category)) ?>)" title="Edit">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <?php if ($category['expense_count'] == 0 && $category['subcategory_count'] == 0): ?>
                                                <button type="button" class="btn btn-sm btn-outline-danger" 
                                                        onclick="deleteCategory(<?= $category['id'] ?>, '<?= htmlspecialchars($category['name']) ?>')" title="Delete">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Add Category Modal -->
    <div class="modal fade" id="addCategoryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_category">
                        
                        <div class="mb-3">
                            <label class="form-label">Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="3"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Parent Category</label>
                            <select class="form-select" name="parent_id">
                                <option value="">Main Category</option>
                                <?php foreach ($parent_categories as $parent): ?>
                                <option value="<?= $parent['id'] ?>"><?= htmlspecialchars($parent['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Color</label>
                                <input type="color" class="form-control form-control-color" name="color_code" value="#6366f1">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Sort Order</label>
                                <input type="number" class="form-control" name="sort_order" value="0" min="0">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_tax_deductible" value="1">
                                <label class="form-check-label">Tax Deductible</label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Category</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Category Modal -->
    <div class="modal fade" id="editCategoryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit_category">
                        <input type="hidden" name="id" id="edit_id">
                        
                        <div class="mb-3">
                            <label class="form-label">Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" id="edit_name" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" id="edit_description" rows="3"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Parent Category</label>
                            <select class="form-select" name="parent_id" id="edit_parent_id">
                                <option value="">Main Category</option>
                                <?php foreach ($parent_categories as $parent): ?>
                                <option value="<?= $parent['id'] ?>"><?= htmlspecialchars($parent['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Color</label>
                                <input type="color" class="form-control form-control-color" name="color_code" id="edit_color_code">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Sort Order</label>
                                <input type="number" class="form-control" name="sort_order" id="edit_sort_order" min="0">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_tax_deductible" id="edit_is_tax_deductible" value="1">
                                <label class="form-check-label">Tax Deductible</label>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_active" id="edit_is_active" value="1">
                                <label class="form-check-label">Active</label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Category</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Category Modal -->
    <div class="modal fade" id="deleteCategoryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Delete Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="delete_category">
                        <input type="hidden" name="id" id="delete_id">
                        
                        <p>Are you sure you want to delete the category "<strong id="delete_name"></strong>"?</p>
                        <p class="text-danger">This action cannot be undone.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete Category</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Subcategory Modal -->
    <div class="modal fade" id="addSubcategoryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-diagram-3"></i> Add New Subcategory</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_category">
                        
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> 
                            <strong>Creating a Subcategory</strong><br>
                            Subcategories help organize your expenses under main categories for better reporting and management.
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Parent Category <span class="text-danger">*</span></label>
                            <select class="form-select" name="parent_id" id="subcategory_parent_id" required>
                                <option value="">Select Parent Category</option>
                                <?php foreach ($parent_categories as $parent): ?>
                                <option value="<?= $parent['id'] ?>"><?= htmlspecialchars($parent['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Choose the main category this subcategory belongs to.</div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Subcategory Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" placeholder="e.g., Office Rent, Equipment Repair" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="3" placeholder="Describe what expenses this subcategory covers..."></textarea>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Color</label>
                                <input type="color" class="form-control form-control-color" name="color_code" value="#6366f1">
                                <div class="form-text">Color will help identify this subcategory visually.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Sort Order</label>
                                <input type="number" class="form-control" name="sort_order" value="0" min="0">
                                <div class="form-text">Lower numbers appear first in lists.</div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_tax_deductible" value="1" id="subcategory_tax_deductible">
                                <label class="form-check-label" for="subcategory_tax_deductible">
                                    Tax Deductible
                                </label>
                                <div class="form-text">Check if expenses in this subcategory are tax deductible.</div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Add Subcategory</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bulk Actions Confirmation Modal -->
    <div class="modal fade" id="bulkActionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="bulkActionTitle">Bulk Action</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" id="bulkAction">
                        <div id="categoryIdsContainer"></div>
                        
                        <p id="bulkActionMessage">Are you sure you want to perform this action?</p>
                        <div id="bulkActionDetails"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="bulkActionConfirmBtn">Confirm</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Form submission protection to prevent duplicates
        let formSubmitting = false;
        
        // Add event listeners to all forms
        document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    if (formSubmitting) {
                        e.preventDefault();
                        return false;
                    }
                    formSubmitting = true;
                    
                    // Disable submit button
                    const submitBtn = form.querySelector('button[type="submit"]');
                    if (submitBtn) {
                        submitBtn.disabled = true;
                        submitBtn.innerHTML = '<i class="spinner-border spinner-border-sm" role="status"></i> Processing...';
                    }
                    
                    // Reset after 3 seconds in case something goes wrong
                    setTimeout(() => {
                        formSubmitting = false;
                        if (submitBtn) {
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = submitBtn.textContent.includes('Add') ? 'Add Category' : 
                                                 submitBtn.textContent.includes('Update') ? 'Update Category' : 
                                                 submitBtn.textContent.includes('Delete') ? 'Delete Category' : 'Confirm';
                        }
                    }, 3000);
                });
            });
        });
        function editCategory(category) {
            document.getElementById('edit_id').value = category.id;
            document.getElementById('edit_name').value = category.name;
            document.getElementById('edit_description').value = category.description || '';
            document.getElementById('edit_parent_id').value = category.parent_id || '';
            document.getElementById('edit_color_code').value = category.color_code;
            document.getElementById('edit_sort_order').value = category.sort_order;
            document.getElementById('edit_is_tax_deductible').checked = category.is_tax_deductible == 1;
            document.getElementById('edit_is_active').checked = category.is_active == 1;
            
            new bootstrap.Modal(document.getElementById('editCategoryModal')).show();
        }
        
        function deleteCategory(id, name) {
            document.getElementById('delete_id').value = id;
            document.getElementById('delete_name').textContent = name;
            
            new bootstrap.Modal(document.getElementById('deleteCategoryModal')).show();
        }
        
        // Bulk Actions Functions
        function toggleAllCategories() {
            const selectAll = document.getElementById('selectAllCategories');
            const checkboxes = document.querySelectorAll('.category-checkbox');
            
            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAll.checked;
            });
            
            updateBulkActions();
        }

        function toggleCategorySelection() {
            const selectAll = document.getElementById('selectAllCategories');
            selectAll.checked = !selectAll.checked;
            toggleAllCategories();
        }

        function updateBulkActions() {
            const checkboxes = document.querySelectorAll('.category-checkbox:checked');
            const bulkActionsBtn = document.getElementById('bulkActionsBtn');
            const selectAllBtn = document.getElementById('selectAllCategories');
            
            if (checkboxes.length > 0) {
                bulkActionsBtn.disabled = false;
                bulkActionsBtn.innerHTML = `<i class="bi bi-gear"></i> Bulk Actions (${checkboxes.length})`;
            } else {
                bulkActionsBtn.disabled = true;
                bulkActionsBtn.innerHTML = '<i class="bi bi-gear"></i> Bulk Actions';
            }
            
            // Update select all checkbox state
            const allCheckboxes = document.querySelectorAll('.category-checkbox');
            if (checkboxes.length === 0) {
                selectAllBtn.indeterminate = false;
                selectAllBtn.checked = false;
            } else if (checkboxes.length === allCheckboxes.length) {
                selectAllBtn.indeterminate = false;
                selectAllBtn.checked = true;
            } else {
                selectAllBtn.indeterminate = true;
            }
        }
        
        function getSelectedCategories() {
            const checkboxes = document.querySelectorAll('.category-checkbox:checked');
            return Array.from(checkboxes).map(cb => ({
                id: cb.value,
                name: cb.closest('tr').dataset.categoryName,
                hasSubcategories: cb.closest('tr').dataset.hasSubcategories > 0,
                hasExpenses: cb.closest('tr').dataset.hasExpenses > 0,
                hasDependencies: cb.hasAttribute('data-has-dependencies')
            }));
        }
        
        function bulkDeleteCategories() {
            const selectedCategories = getSelectedCategories();
            if (selectedCategories.length === 0) {
                alert('Please select at least one category to delete.');
                return;
            }
            
            const deletableCategories = selectedCategories.filter(cat => !cat.hasDependencies);
            const nonDeletableCategories = selectedCategories.filter(cat => cat.hasDependencies);
            
            let message = `Delete ${selectedCategories.length} selected categories?`;
            let details = '';
            
            if (deletableCategories.length > 0) {
                details += `<div class="alert alert-success"><strong>Can be deleted (${deletableCategories.length}):</strong><br>`;
                details += deletableCategories.slice(0, 3).map(cat => cat.name).join(', ');
                if (deletableCategories.length > 3) details += ` and ${deletableCategories.length - 3} more`;
                details += '</div>';
            }
            
            if (nonDeletableCategories.length > 0) {
                details += `<div class="alert alert-warning"><strong>Will be skipped (${nonDeletableCategories.length}):</strong><br>`;
                details += nonDeletableCategories.slice(0, 3).map(cat => cat.name).join(', ');
                if (nonDeletableCategories.length > 3) details += ` and ${nonDeletableCategories.length - 3} more`;
                details += '<br><small>These categories have subcategories or are used in expenses.</small></div>';
            }
            
            showBulkActionModal('bulk_delete', 'Delete Categories', message, details, selectedCategories, 'danger');
        }
        
        function bulkActivateCategories() {
            const selectedCategories = getSelectedCategories();
            if (selectedCategories.length === 0) {
                alert('Please select at least one category to activate.');
                return;
            }
            
            const message = `Activate ${selectedCategories.length} selected categories?`;
            const details = `<div class="alert alert-info">Categories: ${selectedCategories.slice(0, 5).map(cat => cat.name).join(', ')}${selectedCategories.length > 5 ? ` and ${selectedCategories.length - 5} more` : ''}</div>`;
            
            showBulkActionModal('bulk_activate', 'Activate Categories', message, details, selectedCategories, 'success');
        }
        
        function bulkDeactivateCategories() {
            const selectedCategories = getSelectedCategories();
            if (selectedCategories.length === 0) {
                alert('Please select at least one category to deactivate.');
                return;
            }
            
            const message = `Deactivate ${selectedCategories.length} selected categories?`;
            const details = `<div class="alert alert-warning">Categories: ${selectedCategories.slice(0, 5).map(cat => cat.name).join(', ')}${selectedCategories.length > 5 ? ` and ${selectedCategories.length - 5} more` : ''}</div>`;
            
            showBulkActionModal('bulk_deactivate', 'Deactivate Categories', message, details, selectedCategories, 'warning');
        }
        
        function bulkExportCategories() {
            const selectedCategories = getSelectedCategories();
            if (selectedCategories.length === 0) {
                alert('Please select at least one category to export.');
                return;
            }

            // Create CSV content
            let csvContent = "Category Name,Type,Description,Color Code,Tax Deductible,Usage,Status\n";
            
            selectedCategories.forEach(category => {
                const row = document.querySelector(`tr[data-category-id="${category.id}"]`);
                const type = row.querySelector('td:nth-child(3)').textContent.trim();
                const description = row.querySelector('td:nth-child(4)').textContent.trim();
                const colorCode = row.querySelector('td:nth-child(5) code').textContent.trim();
                const taxDeductible = row.querySelector('td:nth-child(6)').textContent.trim();
                const usage = row.querySelector('td:nth-child(7)').textContent.trim().replace(/\s+/g, ' ');
                const status = row.querySelector('td:nth-child(8)').textContent.trim();
                
                csvContent += `"${category.name}","${type}","${description}","${colorCode}","${taxDeductible}","${usage}","${status}"\n`;
            });

            // Download CSV
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            if (link.download !== undefined) {
                const url = URL.createObjectURL(blob);
                link.setAttribute('href', url);
                link.setAttribute('download', `categories_export_${new Date().toISOString().split('T')[0]}.csv`);
                link.style.visibility = 'hidden';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }
        }
        
        function showBulkActionModal(action, title, message, details, categories, btnClass = 'primary') {
            document.getElementById('bulkAction').value = action;
            document.getElementById('bulkActionTitle').textContent = title;
            document.getElementById('bulkActionMessage').textContent = message;
            document.getElementById('bulkActionDetails').innerHTML = details;
            
            // Clear previous category IDs
            const container = document.getElementById('categoryIdsContainer');
            container.innerHTML = '';
            
            // Add hidden inputs for category IDs
            categories.forEach(category => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'category_ids[]';
                input.value = category.id;
                container.appendChild(input);
            });
            
            // Set button class
            const confirmBtn = document.getElementById('bulkActionConfirmBtn');
            confirmBtn.className = `btn btn-${btnClass}`;
            
            // Show modal
            new bootstrap.Modal(document.getElementById('bulkActionModal')).show();
        }
    </script>
</body>
</html>
