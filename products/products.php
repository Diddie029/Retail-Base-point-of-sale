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

// Check if user has permission to manage products
if (!hasPermission('manage_products', $permissions)) {
    header("Location: ../dashboard/dashboard.php");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Handle search and filters
$search = $_GET['search'] ?? '';
$category_filter = $_GET['category'] ?? '';
$status_filter = $_GET['status'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Build WHERE clause
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(p.name LIKE :search OR p.barcode LIKE :search)";
    $params[':search'] = "%$search%";
}

if (!empty($category_filter)) {
    $where_conditions[] = "p.category_id = :category_id";
    $params[':category_id'] = $category_filter;
}

if ($status_filter === 'low_stock') {
    $where_conditions[] = "p.quantity <= 10";
} elseif ($status_filter === 'out_of_stock') {
    $where_conditions[] = "p.quantity = 0";
} elseif ($status_filter === 'in_stock') {
    $where_conditions[] = "p.quantity > 10";
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get total count for pagination
$count_sql = "SELECT COUNT(*) as total FROM products p $where_clause";
$count_stmt = $conn->prepare($count_sql);
foreach ($params as $key => $value) {
    $count_stmt->bindValue($key, $value);
}
$count_stmt->execute();
$total_products = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_products / $per_page);

// Get products
$sql = "
    SELECT p.*, c.name as category_name 
    FROM products p 
    LEFT JOIN categories c ON p.category_id = c.id 
    $where_clause 
    ORDER BY p.created_at DESC 
    LIMIT :limit OFFSET :offset
";

$stmt = $conn->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get categories for filter
$categories_stmt = $conn->query("SELECT * FROM categories ORDER BY name");
$categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle direct export requests (GET parameters)
if (isset($_GET['action']) && $_GET['action'] === 'export') {
    $export_type = $_GET['type'] ?? 'all';
    $format = $_GET['format'] ?? 'csv';
    
    // Build query based on export type
    $export_where_clause = '';
    $export_params = [];
    
    switch ($export_type) {
        case 'low_stock':
            $export_where_clause = 'WHERE p.quantity <= 10 AND p.quantity > 0';
            break;
        case 'out_of_stock':
            $export_where_clause = 'WHERE p.quantity = 0';
            break;
        case 'in_stock':
            $export_where_clause = 'WHERE p.quantity > 10';
            break;
        case 'category':
            if (isset($_GET['category_id'])) {
                $export_where_clause = 'WHERE p.category_id = :category_id';
                $export_params[':category_id'] = $_GET['category_id'];
            }
            break;
        default:
            $export_where_clause = '';
            break;
    }
    
    if ($format === 'csv') {
        // Set headers for CSV download
        $filename = 'products_' . $export_type . '_' . date('Y-m-d_H-i-s') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        
        // Get products for export
        $export_sql = "
            SELECT p.*, c.name as category_name 
            FROM products p 
            LEFT JOIN categories c ON p.category_id = c.id 
            $export_where_clause 
            ORDER BY p.name
        ";
        
        $export_stmt = $conn->prepare($export_sql);
        foreach ($export_params as $key => $value) {
            $export_stmt->bindValue($key, $value);
        }
        $export_stmt->execute();
        $export_products = $export_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $output = fopen('php://output', 'w');
        
        // Add BOM for proper UTF-8 handling in Excel
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // CSV Headers
        $headers = [
            'ID',
            'Name',
            'Category',
            'Price',
            'Quantity',
            'Barcode',
            'Description',
            'Created Date',
            'Updated Date',
            'Stock Status'
        ];
        
        fputcsv($output, $headers);
        
        // CSV Data
        foreach ($export_products as $product) {
            // Determine stock status
            $stock_status = 'In Stock';
            if ($product['quantity'] == 0) {
                $stock_status = 'Out of Stock';
            } elseif ($product['quantity'] <= 10) {
                $stock_status = 'Low Stock';
            }
            
            $row = [
                $product['id'],
                $product['name'],
                $product['category_name'] ?? 'Uncategorized',
                number_format($product['price'], 2),
                $product['quantity'],
                $product['barcode'],
                $product['description'] ?? '',
                date('Y-m-d H:i:s', strtotime($product['created_at'])),
                date('Y-m-d H:i:s', strtotime($product['updated_at'])),
                $stock_status
            ];
            
            fputcsv($output, $row);
        }
        
        fclose($output);
        exit();
    }
}

// Handle export request
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    // Set headers for CSV download
    $filename = 'products_export_' . date('Y-m-d_H-i-s') . '.csv';
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    
    $output = fopen('php://output', 'w');
    
    // CSV Headers
    $headers = ['ID', 'Name', 'Category', 'Price', 'Quantity', 'Barcode', 'Description', 'Created Date', 'Updated Date'];
    fputcsv($output, $headers);
    
    // Get all products for export
    $export_sql = "
        SELECT p.*, c.name as category_name 
        FROM products p 
        LEFT JOIN categories c ON p.category_id = c.id 
        ORDER BY p.name
    ";
    
    $export_stmt = $conn->prepare($export_sql);
    $export_stmt->execute();
    $export_products = $export_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // CSV Data
    foreach ($export_products as $product) {
        $row = [
            $product['id'],
            $product['name'],
            $product['category_name'] ?? 'Uncategorized',
            $product['price'],
            $product['quantity'],
            $product['barcode'],
            $product['description'] ?? '',
            $product['created_at'],
            $product['updated_at']
        ];
        
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit();
}

// Get statistics
$stats = [];

// Total products
$stmt = $conn->query("SELECT COUNT(*) as count FROM products");
$stats['total_products'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Low stock products
$stmt = $conn->query("SELECT COUNT(*) as count FROM products WHERE quantity <= 10 AND quantity > 0");
$stats['low_stock'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Out of stock products
$stmt = $conn->query("SELECT COUNT(*) as count FROM products WHERE quantity = 0");
$stats['out_of_stock'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Total inventory value
$stmt = $conn->query("SELECT SUM(price * quantity) as total FROM products");
$stats['inventory_value'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/products.css">
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
                <a href="products.php" class="nav-link active">
                    <i class="bi bi-box"></i>
                    Products
                </a>
            </div>
            <div class="nav-item">
                <a href="../categories/categories.php" class="nav-link">
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
                    <h1>Products</h1>
                    <div class="header-subtitle">Manage your product inventory</div>
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
            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-primary">
                            <i class="bi bi-box"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['total_products']); ?></div>
                    <div class="stat-label">Total Products</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-warning">
                            <i class="bi bi-exclamation-triangle"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['low_stock']); ?></div>
                    <div class="stat-label">Low Stock</div>
                    <?php if ($stats['low_stock'] > 0): ?>
                    <div class="mt-2">
                        <button class="btn btn-warning btn-sm export-low-stock">
                            <i class="bi bi-download"></i> Export
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-danger">
                            <i class="bi bi-x-circle"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['out_of_stock']); ?></div>
                    <div class="stat-label">Out of Stock</div>
                    <?php if ($stats['out_of_stock'] > 0): ?>
                    <div class="mt-2">
                        <button class="btn btn-danger btn-sm export-out-stock">
                            <i class="bi bi-download"></i> Export
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-success">
                            <i class="bi bi-currency-dollar"></i>
                        </div>
                    </div>
                    <div class="stat-value currency"><?php echo $settings['currency_symbol']; ?> <?php echo number_format($stats['inventory_value'], 2); ?></div>
                    <div class="stat-label">Inventory Value</div>
                </div>
            </div>

            <!-- Product Header -->
            <div class="product-header">
                <h2 class="product-title">Product Management</h2>
                <div class="product-actions">
                    <a href="add.php" class="btn btn-primary">
                        <i class="bi bi-plus"></i>
                        Add Product
                    </a>
                    <a href="import.php" class="btn btn-success">
                        <i class="bi bi-upload"></i>
                        Import
                    </a>
                    <div class="btn-group">
                        <button type="button" class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-download"></i>
                            Export
                        </button>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item export-all" href="#">
                                <i class="bi bi-file-spreadsheet me-2"></i>Export All Products
                            </a></li>
                            <li><a class="dropdown-item export-in-stock" href="#">
                                <i class="bi bi-check-circle me-2"></i>Export In Stock
                            </a></li>
                            <li><a class="dropdown-item export-low-stock" href="#">
                                <i class="bi bi-exclamation-triangle me-2"></i>Export Low Stock
                            </a></li>
                            <li><a class="dropdown-item export-out-stock" href="#">
                                <i class="bi bi-x-circle me-2"></i>Export Out of Stock
                            </a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="export.php">
                                <i class="bi bi-gear me-2"></i>Advanced Export
                            </a></li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <form method="GET" id="filterForm">
                    <div class="filter-row">
                        <div class="form-group">
                            <label for="searchInput" class="form-label">Search Products</label>
                            <input type="text" class="form-control" id="searchInput" name="search" 
                                   value="<?php echo htmlspecialchars($search); ?>" placeholder="Search by name or barcode...">
                        </div>
                        <div class="form-group">
                            <label for="categoryFilter" class="form-label">Category</label>
                            <select class="form-control" id="categoryFilter" name="category">
                                <option value="">All Categories</option>
                                <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>" <?php echo $category_filter == $category['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($category['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="statusFilter" class="form-label">Stock Status</label>
                            <select class="form-control" id="statusFilter" name="status">
                                <option value="">All Products</option>
                                <option value="in_stock" <?php echo $status_filter == 'in_stock' ? 'selected' : ''; ?>>In Stock</option>
                                <option value="low_stock" <?php echo $status_filter == 'low_stock' ? 'selected' : ''; ?>>Low Stock</option>
                                <option value="out_of_stock" <?php echo $status_filter == 'out_of_stock' ? 'selected' : ''; ?>>Out of Stock</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-search"></i>
                                Filter
                            </button>
                            <?php if (!empty($search) || !empty($category_filter) || !empty($status_filter)): ?>
                            <a href="index.php" class="btn btn-outline-secondary">
                                <i class="bi bi-x"></i>
                                Clear
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Bulk Actions (hidden by default) -->
            <div id="bulkActions" class="filter-section" style="display: none;">
                <div class="d-flex align-items-center justify-content-between">
                    <span><span class="selected-count">0</span> products selected</span>
                    <div>
                        <button type="button" id="bulkDelete" class="btn btn-danger btn-sm">
                            <i class="bi bi-trash"></i>
                            Delete Selected
                        </button>
                    </div>
                </div>
            </div>

            <!-- Products Table -->
            <div class="product-table">
                <table class="table">
                    <thead>
                        <tr>
                            <th>
                                <input type="checkbox" id="selectAll">
                            </th>
                            <th>Product</th>
                            <th>Category</th>
                            <th>Price</th>
                            <th>Quantity</th>
                            <th>Status</th>
                            <th>Barcode</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($products)): ?>
                        <tr>
                            <td colspan="9" class="text-center">
                                <div class="py-4">
                                    <i class="bi bi-box" style="font-size: 3rem; color: #9ca3af;"></i>
                                    <p class="text-muted mt-2">No products found</p>
                                    <a href="add.php" class="btn btn-primary">Add Your First Product</a>
                                </div>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($products as $product): ?>
                        <tr>
                            <td>
                                <input type="checkbox" class="product-checkbox" value="<?php echo $product['id']; ?>">
                            </td>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="product-image-placeholder me-3">
                                        <i class="bi bi-image"></i>
                                    </div>
                                    <div>
                                        <div class="font-weight-bold"><?php echo htmlspecialchars($product['name']); ?></div>
                                        <small class="text-muted">ID: <?php echo $product['id']; ?></small>
                                    </div>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($product['category_name'] ?? 'Uncategorized'); ?></td>
                            <td class="currency"><?php echo $settings['currency_symbol']; ?> <?php echo number_format($product['price'], 2); ?></td>
                            <td class="stock-quantity">
                                <?php echo number_format($product['quantity']); ?>
                                <?php if ($product['quantity'] == 0): ?>
                                    <span class="badge badge-danger ms-1">Out of Stock</span>
                                <?php elseif ($product['quantity'] <= 10): ?>
                                    <span class="badge badge-warning ms-1">Low Stock</span>
                                <?php else: ?>
                                    <span class="badge badge-success ms-1">In Stock</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($product['quantity'] == 0): ?>
                                    <span class="badge badge-danger">Out of Stock</span>
                                <?php elseif ($product['quantity'] <= 10): ?>
                                    <span class="badge badge-warning">Low Stock</span>
                                <?php else: ?>
                                    <span class="badge badge-success">In Stock</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <code><?php echo htmlspecialchars($product['barcode']); ?></code>
                            </td>
                            <td>
                                <small class="text-muted">
                                    <?php echo date('M j, Y', strtotime($product['created_at'])); ?>
                                </small>
                            </td>
                            <td>
                                <div class="d-flex gap-1">
                                    <a href="view.php?id=<?php echo $product['id']; ?>" class="btn btn-outline-secondary btn-sm" title="View">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <a href="edit.php?id=<?php echo $product['id']; ?>" class="btn btn-warning btn-sm" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <a href="delete.php?id=<?php echo $product['id']; ?>" 
                                       class="btn btn-danger btn-sm btn-delete" 
                                       data-product-name="<?php echo htmlspecialchars($product['name']); ?>"
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
                        Showing <?php echo number_format($offset + 1); ?> to <?php echo number_format(min($offset + $per_page, $total_products)); ?> 
                        of <?php echo number_format($total_products); ?> products
                    </div>
                    <nav aria-label="Product pagination">
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/products.js"></script>
</body>
</html>