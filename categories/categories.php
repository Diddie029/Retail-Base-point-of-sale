<?php
session_start();
require_once __DIR__ . '/../include/db.php';

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

// Helper function to check permissions
function hasPermission($permission, $userPermissions) {
    return in_array($permission, $userPermissions);
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
$allowed_sorts = ['name', 'created_at', 'updated_at', 'product_count'];
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
if ($sort_by === 'product_count') {
    $order_clause = "ORDER BY product_count $sort_order, c.name ASC";
} else {
    $order_clause = "ORDER BY c.$sort_by $sort_order";
}

// Get categories with product count
$sql = "
    SELECT c.*, 
           COUNT(p.id) as product_count,
           COALESCE(SUM(p.quantity), 0) as total_inventory
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

// Handle success/error messages
$success = $_SESSION['success'] ?? '';
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
    <!-- Debug: Current settings - Theme: <?php echo $settings['theme_color'] ?? 'not set'; ?>, Sidebar: <?php echo $settings['sidebar_color'] ?? 'not set'; ?> -->
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <nav class="sidebar">
        <div class="sidebar-header">
            <h4><i class="bi bi-shop me-2"></i><?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></h4>
            <small>Point of Sale System</small>
        </div>
        <div class="sidebar-nav">
            <div class="nav-item">
                <a href="../dashboard/dashboard.php" class="nav-link">
                    <i class="bi bi-speedometer2"></i>
                    Dashboard
                </a>
            </div>
            
            <?php if (hasPermission('process_sales', $permissions)): ?>
            <div class="nav-item">
                <a href="../pos/index.php" class="nav-link">
                    <i class="bi bi-cart-plus"></i>
                    Point of Sale
                </a>
            </div>
            <?php endif; ?>

            <?php if (hasPermission('manage_products', $permissions)): ?>
            <div class="nav-item">
                <a href="../products/products.php" class="nav-link">
                    <i class="bi bi-box"></i>
                    Products
                </a>
            </div>
            <div class="nav-item">
                <a href="categories.php" class="nav-link active">
                    <i class="bi bi-tags"></i>
                    Categories
                </a>
            </div>
            <div class="nav-item">
                <a href="../inventory/index.php" class="nav-link">
                    <i class="bi bi-boxes"></i>
                    Inventory
                </a>
            </div>
            <?php endif; ?>

            <?php if (hasPermission('manage_sales', $permissions)): ?>
            <div class="nav-item">
                <a href="../sales/index.php" class="nav-link">
                    <i class="bi bi-receipt"></i>
                    Sales History
                </a>
            </div>
            <?php endif; ?>

            <div class="nav-item">
                <a href="../customers/index.php" class="nav-link">
                    <i class="bi bi-people"></i>
                    Customers
                </a>
            </div>

            <div class="nav-item">
                <a href="../reports/index.php" class="nav-link">
                    <i class="bi bi-graph-up"></i>
                    Reports
                </a>
            </div>

            <?php if (hasPermission('manage_users', $permissions)): ?>
            <div class="nav-item">
                <a href="../admin/users/index.php" class="nav-link">
                    <i class="bi bi-person-gear"></i>
                    User Management
                </a>
            </div>
            <?php endif; ?>

            <?php if (hasPermission('manage_settings', $permissions)): ?>
            <div class="nav-item">
                <a href="../admin/settings/adminsetting.php" class="nav-link">
                    <i class="bi bi-gear"></i>
                    Settings
                </a>
            </div>
            <?php endif; ?>

            <div class="nav-item">
                <a href="../auth/logout.php" class="nav-link">
                    <i class="bi bi-box-arrow-right"></i>
                    Logout
                </a>
            </div>
        </div>
    </nav>

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

            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-primary">
                            <i class="bi bi-tags"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['total_categories']); ?></div>
                    <div class="stat-label">Total Categories</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-success">
                            <i class="bi bi-check-circle"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['categories_with_products']); ?></div>
                    <div class="stat-label">With Products</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-warning">
                            <i class="bi bi-circle"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['empty_categories']); ?></div>
                    <div class="stat-label">Empty Categories</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-info">
                            <i class="bi bi-box"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['total_products']); ?></div>
                    <div class="stat-label">Total Products</div>
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

            <!-- Categories Table -->
            <div class="data-section">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>
                                    <a href="<?php echo getSortUrl('name', $sort_by, $sort_order, $search); ?>" class="sort-link">
                                        Category Name <?php echo getSortIcon('name', $sort_by, $sort_order); ?>
                                    </a>
                                </th>
                                <th>Description</th>
                                <th>
                                    <a href="<?php echo getSortUrl('product_count', $sort_by, $sort_order, $search); ?>" class="sort-link">
                                        Products <?php echo getSortIcon('product_count', $sort_by, $sort_order); ?>
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
                                <td colspan="6" class="text-center">
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
                                    <div class="product-count">
                                        <?php echo number_format($category['product_count']); ?>
                                        <?php if ($category['product_count'] == 0): ?>
                                            <span class="badge badge-warning ms-1">Empty</span>
                                        <?php else: ?>
                                            <span class="badge badge-success ms-1"><?php echo number_format($category['total_inventory']); ?> items</span>
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
                                           data-product-count="<?php echo $category['product_count']; ?>"
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
                            Showing <?php echo number_format($offset + 1); ?> to <?php echo number_format(min($offset + $per_page, $total_categories)); ?> 
                            of <?php echo number_format($total_categories); ?> categories
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/categories.js"></script>
</body>
</html>