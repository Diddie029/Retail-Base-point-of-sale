<?php
session_start();
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';

// Helper function to format numbers with k notation
function formatNumber($number) {
    $number = (int)$number; // Ensure it's an integer
    
    if ($number >= 1000000) {
        $formatted = $number / 1000000;
        return ($formatted == (int)$formatted) ? (int)$formatted . 'M' : number_format($formatted, 1) . 'M';
    } elseif ($number >= 1000) {
        $formatted = $number / 1000;
        return ($formatted == (int)$formatted) ? (int)$formatted . 'k' : number_format($formatted, 1) . 'k';
    } else {
        return (string)$number;
    }
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Get user information
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

// Check if user has permission to manage categories
if (!hasPermission('manage_categories', $permissions)) {
    header("Location: ../dashboard/dashboard.php");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Handle search and sorting
$search = $_GET['search'] ?? '';
$sort_by = $_GET['sort'] ?? 'name';
$sort_order = $_GET['order'] ?? 'ASC';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Validate sort parameters
$allowed_sorts = ['name', 'created_at', 'updated_at', 'sku_count'];
$allowed_orders = ['ASC', 'DESC'];

if (!in_array($sort_by, $allowed_sorts)) {
    $sort_by = 'name';
}
if (!in_array($sort_order, $allowed_orders)) {
    $sort_order = 'ASC';
}

// Build WHERE clause
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(c.name LIKE :search OR c.description LIKE :search)";
    $params[':search'] = "%$search%";
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get total count for pagination
$count_sql = "SELECT COUNT(*) as total FROM categories c $where_clause";
$count_stmt = $conn->prepare($count_sql);
foreach ($params as $key => $value) {
    $count_stmt->bindValue($key, $value);
}
$count_stmt->execute();
$total_categories = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_categories / $per_page);

// Build ORDER BY clause
$order_clause = '';
if ($sort_by === 'sku_count') {
    $order_clause = "ORDER BY sku_count $sort_order, c.name ASC";
} else {
    $order_clause = "ORDER BY c.$sort_by $sort_order";
}

// Get categories with SKU count
$sql = "
    SELECT c.*, 
           COUNT(DISTINCT p.sku) as sku_count,
           COUNT(p.id) as product_count
    FROM categories c 
    LEFT JOIN products p ON c.id = p.category_id 
    $where_clause 
    GROUP BY c.id, c.name, c.description, c.created_at, c.updated_at
    $order_clause 
    LIMIT :limit OFFSET :offset
";

$stmt = $conn->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats = [];

// Total categories
$stats['total_categories'] = $total_categories;

// Categories with products
$stmt = $conn->query("SELECT COUNT(*) as count FROM categories c WHERE EXISTS (SELECT 1 FROM products p WHERE p.category_id = c.id)");
$stats['categories_with_products'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Empty categories
$stats['empty_categories'] = $stats['total_categories'] - $stats['categories_with_products'];

// Total products across all categories
$stmt = $conn->query("SELECT COUNT(*) as count FROM products");
$stats['total_products'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Total unique SKUs across all categories
$stmt = $conn->query("SELECT COUNT(DISTINCT sku) as count FROM products WHERE sku IS NOT NULL AND sku != ''");
$stats['total_skus'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Handle bulk actions
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $bulk_action = $_POST['bulk_action'];
    $selected_categories_raw = $_POST['selected_categories'] ?? [];
    
    
    // Handle both array and JSON string formats
    if (is_string($selected_categories_raw)) {
        $selected_categories = json_decode($selected_categories_raw, true) ?: [];
    } else {
        $selected_categories = $selected_categories_raw;
    }
    
    $force_delete = isset($_POST['force_delete']) && $_POST['force_delete'] === '1';
    
    if (($bulk_action === 'delete' || $bulk_action === 'force_delete') && !empty($selected_categories)) {
        $deleted_count = 0;
        $error_count = 0;
        $errors_list = [];
        $warnings_list = [];
        $moved_products = 0;
        
        
        foreach ($selected_categories as $category_id) {
            $category_id = (int)$category_id;
            
            // Get category info
            $stmt = $conn->prepare("SELECT name FROM categories WHERE id = ?");
            $stmt->execute([$category_id]);
            $category_name = $stmt->fetch(PDO::FETCH_ASSOC)['name'] ?? 'Unknown';
            
            // Check if category has products
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM products WHERE category_id = ?");
            $stmt->execute([$category_id]);
            $product_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            if ($product_count > 0) {
                if ($force_delete) {
                    // Force delete: Move products to "Uncategorized" or delete them
                    try {
                        $conn->beginTransaction();
                        
                        // Option 1: Move products to a default category (recommended)
                        $default_category_id = null;
                        $stmt = $conn->prepare("SELECT id FROM categories WHERE name = 'Uncategorized' LIMIT 1");
                        $stmt->execute();
                        $default_category = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($default_category) {
                            $default_category_id = $default_category['id'];
                        } else {
                            // Create "Uncategorized" category if it doesn't exist
                            $stmt = $conn->prepare("
                                INSERT INTO categories (name, description, created_at, updated_at) 
                                VALUES ('Uncategorized', 'Default category for products without a specific category', NOW(), NOW())
                            ");
                            $stmt->execute();
                            $default_category_id = $conn->lastInsertId();
                        }
                        
                        // Move products to default category
                        $stmt = $conn->prepare("UPDATE products SET category_id = ? WHERE category_id = ?");
                        $stmt->execute([$default_category_id, $category_id]);
                        $moved_products += $product_count;
                        
                        // Now delete the category
                        $stmt = $conn->prepare("DELETE FROM categories WHERE id = ?");
                        if ($stmt->execute([$category_id])) {
                            $deleted_count++;
                            
                            // Log the activity
                            $activity_message = "Force deleted category: {$category_name} (ID: {$category_id}) - {$product_count} products moved to 'Uncategorized'";
                            $stmt = $conn->prepare("
                                INSERT INTO activity_logs (user_id, action, details, created_at) 
                                VALUES (?, 'bulk_force_delete_categories', ?, NOW())
                            ");
                            $stmt->execute([$user_id, $activity_message]);
                            
                            $warnings_list[] = "Category '{$category_name}' deleted - {$product_count} products moved to 'Uncategorized'";
                        } else {
                            $conn->rollBack();
                            $error_count++;
                            $errors_list[] = "Failed to delete category '{$category_name}' after moving products";
                        }
                        
                        $conn->commit();
                        
                    } catch (Exception $e) {
                        $conn->rollBack();
                        $error_count++;
                        $errors_list[] = "Error deleting '{$category_name}': " . $e->getMessage();
                    }
                } else {
                    // Regular delete: Skip categories with products
                    $error_count++;
                    $errors_list[] = "Cannot delete '{$category_name}' - it has {$product_count} product(s). Use 'Force Delete' to move products to 'Uncategorized'.";
                }
                continue;
            }
            
            // Delete the category (no products)
            $stmt = $conn->prepare("DELETE FROM categories WHERE id = ?");
            if ($stmt->execute([$category_id])) {
                $deleted_count++;
                
                // Log the activity
                $activity_message = "Bulk deleted category: {$category_name} (ID: {$category_id})";
                $stmt = $conn->prepare("
                    INSERT INTO activity_logs (user_id, action, details, created_at) 
                    VALUES (?, 'bulk_delete_categories', ?, NOW())
                ");
                $stmt->execute([$user_id, $activity_message]);
            } else {
                $error_count++;
                $errors_list[] = "Failed to delete category '{$category_name}'";
            }
        }
        
        // Build success message
        $success_parts = [];
        if ($deleted_count > 0) {
            $success_parts[] = "Successfully deleted {$deleted_count} categor" . ($deleted_count > 1 ? 'ies' : 'y');
        }
        if ($moved_products > 0) {
            $success_parts[] = "Moved {$moved_products} products to 'Uncategorized'";
        }
        if (!empty($success_parts)) {
            $success_message = implode('. ', $success_parts);
            
            // Store success message in session and redirect to avoid page refresh issues
            $_SESSION['success'] = $success_message;
            
            // Build redirect URL with current search and sort parameters
            $redirect_params = [];
            if (!empty($search)) {
                $redirect_params['search'] = $search;
            }
            if ($sort_by !== 'name') {
                $redirect_params['sort'] = $sort_by;
            }
            if ($sort_order !== 'ASC') {
                $redirect_params['order'] = $sort_order;
            }
            if ($page > 1) {
                $redirect_params['page'] = $page;
            }
            
            $redirect_url = 'categories.php';
            $redirect_params['bulk_delete_completed'] = '1';
            $redirect_url .= '?' . http_build_query($redirect_params);
            
            header("Location: $redirect_url");
            exit();
        }
        
        // Build error messages
        if ($error_count > 0) {
            $errors['bulk_delete'] = implode('<br>', $errors_list);
        }
        if (!empty($warnings_list)) {
            $errors['bulk_warnings'] = implode('<br>', $warnings_list);
        }
    }
}

// Handle success/error messages
$success = $success ?: ($_SESSION['success'] ?? '');
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// Function to generate sort URL
function getSortUrl($column, $current_sort, $current_order, $search) {
    $new_order = ($column === $current_sort && $current_order === 'ASC') ? 'DESC' : 'ASC';
    $params = [
        'sort' => $column,
        'order' => $new_order
    ];
    if (!empty($search)) {
        $params['search'] = $search;
    }
    return '?' . http_build_query($params);
}

// Function to get sort icon
function getSortIcon($column, $current_sort, $current_order) {
    if ($column !== $current_sort) {
        return '<i class="bi bi-arrow-down-up text-muted"></i>';
    }
    return $current_order === 'ASC' ? '<i class="bi bi-arrow-up"></i>' : '<i class="bi bi-arrow-down"></i>';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Categories - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/categories.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <?php
    $current_page = 'categories';
    include __DIR__ . '/../include/navmenu.php';
    ?>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1>Categories</h1>
                    <div class="header-subtitle">Manage product categories and organization</div>
                </div>
                <div class="header-actions">
                    <div class="user-info">
                        <div class="user-avatar"><?php echo strtoupper(substr($username, 0, 1)); ?></div>
                        <span><?php echo htmlspecialchars($username); ?></span>
                    </div>
                </div>
            </div>
        </header>

        <!-- Content -->
        <div class="content">
            <?php if (!empty($success)): ?>
            <div class="alert alert-success">
                <i class="bi bi-check-circle me-2"></i>
                <?php echo htmlspecialchars($success); ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-circle me-2"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($errors['bulk_delete'])): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <strong>Bulk Delete Errors:</strong><br>
                <?php echo $errors['bulk_delete']; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($errors['bulk_warnings'])): ?>
            <div class="alert alert-warning">
                <i class="bi bi-info-circle me-2"></i>
                <strong>Bulk Delete Warnings:</strong><br>
                <?php echo $errors['bulk_warnings']; ?>
            </div>
            <?php endif; ?>

            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-primary">
                            <i class="bi bi-tags"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo formatNumber($stats['total_categories']); ?></div>
                    <div class="stat-label">Total Categories</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-success">
                            <i class="bi bi-check-circle"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo formatNumber($stats['categories_with_products']); ?></div>
                    <div class="stat-label">With Products</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-warning">
                            <i class="bi bi-circle"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo formatNumber($stats['empty_categories']); ?></div>
                    <div class="stat-label">Empty Categories</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-info">
                            <i class="bi bi-upc"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo formatNumber($stats['total_skus']); ?></div>
                    <div class="stat-label">Total SKUs</div>
                </div>
            </div>

            <!-- Category Header -->
            <div class="category-header">
                <h2 class="category-title">Category Management</h2>
                <div class="category-actions">
                    <a href="add.php" class="btn btn-primary">
                        <i class="bi bi-plus"></i>
                        Add Category
                    </a>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <form method="GET" id="filterForm">
                    <div class="filter-row">
                        <div class="form-group">
                            <label for="searchInput" class="form-label">Search Categories</label>
                            <input type="text" class="form-control" id="searchInput" name="search" 
                                   value="<?php echo htmlspecialchars($search); ?>" placeholder="Search by name or description...">
                        </div>
                        <div class="form-group">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-search"></i>
                                Search
                            </button>
                            <?php if (!empty($search)): ?>
                            <a href="categories.php" class="btn btn-outline-secondary">
                                <i class="bi bi-x"></i>
                                Clear
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Preserve sort parameters -->
                    <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort_by); ?>">
                    <input type="hidden" name="order" value="<?php echo htmlspecialchars($sort_order); ?>">
                </form>
            </div>

            <!-- Bulk Actions -->
            <div class="bulk-actions-section" id="bulkActionsSection" style="display: none;">
                <div class="bulk-actions-bar">
                    <div class="bulk-actions-left">
                        <span class="selected-count" id="selectedCount">0 selected</span>
                    </div>
                    <div class="bulk-actions-right">
                        <select class="form-select form-select-sm" id="bulkActionSelect">
                            <option value="">Choose Action</option>
                            <option value="delete">Delete Selected (Skip categories with products)</option>
                            <option value="force_delete">Force Delete (Move products to 'Uncategorized')</option>
                        </select>
                        <button type="button" class="btn btn-danger btn-sm" id="executeBulkAction" disabled>
                            <i class="bi bi-trash"></i>
                            Execute
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="clearSelection">
                            <i class="bi bi-x"></i>
                            Clear
                        </button>
                    </div>
                </div>
            </div>

            <!-- Categories Table -->
            <div class="data-section">
                <div class="table-responsive">
                    <table class="table" id="categoriesTable">
                        <thead>
                            <tr>
                                <th width="50">
                                    <input type="checkbox" id="selectAll" class="form-check-input">
                                </th>
                                <th>
                                    <a href="<?php echo getSortUrl('name', $sort_by, $sort_order, $search); ?>" class="sort-link">
                                        Category Name <?php echo getSortIcon('name', $sort_by, $sort_order); ?>
                                    </a>
                                </th>
                                <th>Description</th>
                                <th>
                                    <a href="<?php echo getSortUrl('sku_count', $sort_by, $sort_order, $search); ?>" class="sort-link">
                                        No. of SKUs <?php echo getSortIcon('sku_count', $sort_by, $sort_order); ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="<?php echo getSortUrl('created_at', $sort_by, $sort_order, $search); ?>" class="sort-link">
                                        Created <?php echo getSortIcon('created_at', $sort_by, $sort_order); ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="<?php echo getSortUrl('updated_at', $sort_by, $sort_order, $search); ?>" class="sort-link">
                                        Updated <?php echo getSortIcon('updated_at', $sort_by, $sort_order); ?>
                                    </a>
                                </th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($categories)): ?>
                            <tr>
                                <td colspan="7" class="text-center">
                                    <div class="py-4">
                                        <i class="bi bi-tags" style="font-size: 3rem; color: #9ca3af;"></i>
                                        <p class="text-muted mt-2">No categories found</p>
                                        <a href="add.php" class="btn btn-primary">Add Your First Category</a>
                                    </div>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($categories as $category): ?>
                            <tr>
                                <td>
                                    <input type="checkbox" class="form-check-input category-checkbox" 
                                           value="<?php echo $category['id']; ?>"
                                           data-category-name="<?php echo htmlspecialchars($category['name']); ?>"
                                           data-sku-count="<?php echo $category['sku_count']; ?>"
                                           <?php echo $category['sku_count'] > 0 ? 'disabled title="Cannot select - category has products"' : ''; ?>>
                                    <?php if ($category['sku_count'] > 0): ?>
                                        <small class="text-muted d-block mt-1">
                                            <i class="bi bi-lock-fill"></i> Has products
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="category-icon me-3">
                                            <i class="bi bi-tag"></i>
                                        </div>
                                        <div>
                                            <div class="font-weight-bold"><?php echo htmlspecialchars($category['name']); ?></div>
                                            <small class="text-muted">ID: <?php echo $category['id']; ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="category-description">
                                        <?php 
                                        $description = $category['description'] ?? '';
                                        echo !empty($description) ? htmlspecialchars($description) : '<span class="text-muted">No description</span>';
                                        ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="sku-count">
                                        <span class="sku-number"><?php echo formatNumber($category['sku_count']); ?></span>
                                        <span class="sku-label">SKU<?php echo $category['sku_count'] != 1 ? 's' : ''; ?></span>
                                        <?php if ($category['sku_count'] == 0): ?>
                                            <span class="badge badge-warning ms-1">Empty</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <small><?php echo date('M j, Y g:i A', strtotime($category['created_at'])); ?></small>
                                </td>
                                <td>
                                    <small><?php echo date('M j, Y g:i A', strtotime($category['updated_at'])); ?></small>
                                </td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <a href="edit.php?id=<?php echo $category['id']; ?>" class="btn btn-warning btn-sm" title="Edit">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <a href="delete.php?id=<?php echo $category['id']; ?>" 
                                           class="btn btn-danger btn-sm btn-delete" 
                                           data-category-name="<?php echo htmlspecialchars($category['name']); ?>"
                                           data-sku-count="<?php echo $category['sku_count']; ?>"
                                           title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <div class="pagination-wrapper">
                        <div class="pagination-info">
                            Showing <?php echo formatNumber($offset + 1); ?> to <?php echo formatNumber(min($offset + $per_page, $total_categories)); ?> 
                            of <?php echo formatNumber($total_categories); ?> categories
                        </div>
                        <nav aria-label="Category pagination">
                            <ul class="pagination">
                                <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                        <i class="bi bi-chevron-left"></i>
                                    </a>
                                </li>
                                <?php endif; ?>

                                <?php
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $page + 2);
                                
                                for ($i = $start_page; $i <= $end_page; $i++):
                                ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                                <?php endfor; ?>

                                <?php if ($page < $total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                        <i class="bi bi-chevron-right"></i>
                                    </a>
                                </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Bulk Delete Confirmation Modal -->
    <div class="modal fade" id="bulkDeleteModal" tabindex="-1" aria-labelledby="bulkDeleteModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="bulkDeleteModalLabel">
                        <i class="bi bi-exclamation-triangle text-warning me-2"></i>
                        Confirm Bulk Delete
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p id="modalMessage">Are you sure you want to delete the selected categories?</p>
                    <div id="selectedCategoriesList" class="mt-3">
                        <!-- Selected categories will be listed here -->
                    </div>
                    <div id="modalWarning" class="alert alert-warning mt-3" style="display: none;">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Note:</strong> Categories with products cannot be deleted. They will be skipped automatically.
                    </div>
                    <div id="modalForceWarning" class="alert alert-danger mt-3" style="display: none;">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong>Warning:</strong> This will move all products from these categories to the "Uncategorized" category. This action cannot be undone.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmBulkDelete">
                        <i class="bi bi-trash me-1"></i>
                        Delete Selected
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bulk Actions Form (Hidden) -->
    <form id="bulkActionForm" method="POST" style="display: none;">
        <input type="hidden" name="bulk_action" id="bulkActionInput">
        <input type="hidden" name="selected_categories" id="selectedCategoriesInput">
    </form>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/categories.js"></script>
    
    <script>
    // Check if we just completed a bulk delete operation
    if (window.location.search.includes('bulk_delete_completed=1')) {
        // Remove the parameter from URL without page reload
        const url = new URL(window.location);
        url.searchParams.delete('bulk_delete_completed');
        window.history.replaceState({}, '', url);
        
        // Show success message if any
        const successMessage = document.querySelector('.alert-success');
        if (successMessage) {
            successMessage.scrollIntoView({ behavior: 'smooth' });
        }
    }
    
    // Bulk Actions JavaScript
    document.addEventListener('DOMContentLoaded', function() {
        const selectAllCheckbox = document.getElementById('selectAll');
        const categoryCheckboxes = document.querySelectorAll('.category-checkbox');
        const bulkActionsSection = document.getElementById('bulkActionsSection');
        const selectedCount = document.getElementById('selectedCount');
        const bulkActionSelect = document.getElementById('bulkActionSelect');
        const executeBulkAction = document.getElementById('executeBulkAction');
        const clearSelection = document.getElementById('clearSelection');
        const bulkDeleteModal = new bootstrap.Modal(document.getElementById('bulkDeleteModal'));
        const selectedCategoriesList = document.getElementById('selectedCategoriesList');
        const confirmBulkDelete = document.getElementById('confirmBulkDelete');
        const bulkActionForm = document.getElementById('bulkActionForm');
        const bulkActionInput = document.getElementById('bulkActionInput');
        const selectedCategoriesInput = document.getElementById('selectedCategoriesInput');

        // Select All functionality
        selectAllCheckbox.addEventListener('change', function() {
            categoryCheckboxes.forEach(checkbox => {
                if (!checkbox.disabled) {
                    checkbox.checked = this.checked;
                }
            });
            updateBulkActions();
        });

        // Individual checkbox functionality
        categoryCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                updateBulkActions();
                updateSelectAllState();
            });
        });

        // Update bulk actions visibility and count
        function updateBulkActions() {
            const checkedBoxes = document.querySelectorAll('.category-checkbox:checked');
            const count = checkedBoxes.length;
            
            if (count > 0) {
                bulkActionsSection.style.display = 'block';
                selectedCount.textContent = `${count} selected`;
                executeBulkAction.disabled = false;
            } else {
                bulkActionsSection.style.display = 'none';
                executeBulkAction.disabled = true;
            }
        }

        // Update select all checkbox state
        function updateSelectAllState() {
            const checkedBoxes = document.querySelectorAll('.category-checkbox:checked');
            const enabledBoxes = document.querySelectorAll('.category-checkbox:not(:disabled)');
            
            if (checkedBoxes.length === 0) {
                selectAllCheckbox.indeterminate = false;
                selectAllCheckbox.checked = false;
            } else if (checkedBoxes.length === enabledBoxes.length) {
                selectAllCheckbox.indeterminate = false;
                selectAllCheckbox.checked = true;
            } else {
                selectAllCheckbox.indeterminate = true;
                selectAllCheckbox.checked = false;
            }
        }

        // Clear selection
        clearSelection.addEventListener('click', function() {
            categoryCheckboxes.forEach(checkbox => {
                checkbox.checked = false;
            });
            selectAllCheckbox.checked = false;
            selectAllCheckbox.indeterminate = false;
            bulkActionSelect.value = '';
            updateBulkActions();
        });

        // Execute bulk action
        executeBulkAction.addEventListener('click', function() {
            const action = bulkActionSelect.value;
            if (action === 'delete' || action === 'force_delete') {
                showBulkDeleteModal(action);
            }
        });

        // Show bulk delete confirmation modal
        function showBulkDeleteModal(action) {
            const checkedBoxes = document.querySelectorAll('.category-checkbox:checked');
            const categories = Array.from(checkedBoxes).map(checkbox => ({
                id: checkbox.value,
                name: checkbox.dataset.categoryName,
                skuCount: parseInt(checkbox.dataset.skuCount)
            }));

            // Update modal title and message based on action
            const modalTitle = document.getElementById('bulkDeleteModalLabel');
            const modalMessage = document.getElementById('modalMessage');
            const modalWarning = document.getElementById('modalWarning');
            const modalForceWarning = document.getElementById('modalForceWarning');
            const confirmButton = document.getElementById('confirmBulkDelete');

            if (action === 'force_delete') {
                modalTitle.innerHTML = '<i class="bi bi-exclamation-triangle text-danger me-2"></i>Confirm Force Delete';
                modalMessage.textContent = 'Are you sure you want to force delete the selected categories?';
                modalWarning.style.display = 'none';
                modalForceWarning.style.display = 'block';
                confirmButton.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i>Force Delete';
                confirmButton.className = 'btn btn-danger';
            } else {
                modalTitle.innerHTML = '<i class="bi bi-exclamation-triangle text-warning me-2"></i>Confirm Bulk Delete';
                modalMessage.textContent = 'Are you sure you want to delete the selected categories?';
                modalWarning.style.display = 'block';
                modalForceWarning.style.display = 'none';
                confirmButton.innerHTML = '<i class="bi bi-trash me-1"></i>Delete Selected';
                confirmButton.className = 'btn btn-danger';
            }

            // Build categories list for modal
            let categoriesHtml = '<ul class="list-group">';
            categories.forEach(category => {
                const skuText = category.skuCount === 0 ? 'Empty' : `${category.skuCount} SKU${category.skuCount !== 1 ? 's' : ''}`;
                const badgeClass = category.skuCount === 0 ? 'bg-warning' : 'bg-info';
                const lockIcon = category.skuCount > 0 ? '<i class="bi bi-lock-fill me-1"></i>' : '';
                
                categoriesHtml += `
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span>${lockIcon}${category.name}</span>
                        <span class="badge ${badgeClass}">${skuText}</span>
                    </li>
                `;
            });
            categoriesHtml += '</ul>';

            selectedCategoriesList.innerHTML = categoriesHtml;
            bulkDeleteModal.show();
        }

        // Confirm bulk delete
        confirmBulkDelete.addEventListener('click', function() {
            const checkedBoxes = document.querySelectorAll('.category-checkbox:checked');
            const selectedIds = Array.from(checkedBoxes).map(checkbox => checkbox.value);
            const action = bulkActionSelect.value;
            
            
            bulkActionInput.value = action;
            selectedCategoriesInput.value = JSON.stringify(selectedIds);
            
            // Remove any existing force_delete input
            const existingForceDelete = bulkActionForm.querySelector('input[name="force_delete"]');
            if (existingForceDelete) {
                existingForceDelete.remove();
            }
            
            // Add force_delete flag if needed
            if (action === 'force_delete') {
                const forceDeleteInput = document.createElement('input');
                forceDeleteInput.type = 'hidden';
                forceDeleteInput.name = 'force_delete';
                forceDeleteInput.value = '1';
                bulkActionForm.appendChild(forceDeleteInput);
            }
            
            
            // Submit the form
            bulkActionForm.submit();
            
            // Show loading state
            executeBulkAction.disabled = true;
            executeBulkAction.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Processing...';
        });
    });
    </script>
</body>
</html>