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

// Handle export request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['format'])) {
    $format = $_POST['format'] ?? 'csv';
    $category_filter = $_POST['category_filter'] ?? '';
    $status_filter = $_POST['status_filter'] ?? '';
    $include_description = isset($_POST['include_description']);
    $include_dates = isset($_POST['include_dates']);
    
    // Build WHERE clause for filters
    $where_conditions = [];
    $params = [];
    
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
    
    // Get products
    $sql = "
        SELECT p.*, c.name as category_name 
        FROM products p 
        LEFT JOIN categories c ON p.category_id = c.id 
        $where_clause 
        ORDER BY p.name
    ";
    
    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($format === 'csv') {
        // Set headers for CSV download
        $filename = 'products_export_' . date('Y-m-d_H-i-s') . '.csv';
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        
        $output = fopen('php://output', 'w');
        
        // CSV Headers
        $headers = ['ID', 'Name', 'Category', 'Price', 'Quantity', 'Barcode'];
        
        if ($include_description) {
            $headers[] = 'Description';
        }
        
        if ($include_dates) {
            $headers[] = 'Created Date';
            $headers[] = 'Updated Date';
        }
        
        fputcsv($output, $headers);
        
        // CSV Data
        foreach ($products as $product) {
            $row = [
                $product['id'],
                $product['name'],
                $product['category_name'] ?? 'Uncategorized',
                $product['price'],
                $product['quantity'],
                $product['barcode']
            ];
            
            if ($include_description) {
                $row[] = $product['description'] ?? '';
            }
            
            if ($include_dates) {
                $row[] = $product['created_at'];
                $row[] = $product['updated_at'];
            }
            
            fputcsv($output, $row);
        }
        
        fclose($output);
        exit();
    }
}

// Get categories for filter
$categories_stmt = $conn->query("SELECT * FROM categories ORDER BY name");
$categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get export statistics
$stats = [];

// Total products
$stmt = $conn->query("SELECT COUNT(*) as count FROM products");
$stats['total_products'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// By category
$stmt = $conn->query("
    SELECT c.name, COUNT(p.id) as count 
    FROM categories c 
    LEFT JOIN products p ON c.id = p.category_id 
    GROUP BY c.id, c.name 
    ORDER BY count DESC
");
$stats['by_category'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// By stock status
$stmt = $conn->query("SELECT 
    SUM(CASE WHEN quantity = 0 THEN 1 ELSE 0 END) as out_of_stock,
    SUM(CASE WHEN quantity > 0 AND quantity <= 10 THEN 1 ELSE 0 END) as low_stock,
    SUM(CASE WHEN quantity > 10 THEN 1 ELSE 0 END) as in_stock
    FROM products
");
$stats['by_status'] = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Export Products - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/products.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
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
                <a href="index.php" class="nav-link active">
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
                    <h1>Export Products</h1>
                    <div class="header-subtitle">Download your product data</div>
                </div>
                <div class="header-actions">
                    <a href="index.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i>
                        Back to Products
                    </a>
                    <div class="user-info">
                        <div class="user-avatar"><?php echo strtoupper(substr($username, 0, 1)); ?></div>
                        <span><?php echo htmlspecialchars($username); ?></span>
                    </div>
                </div>
            </div>
        </header>

        <!-- Content -->
        <div class="content">
            <!-- Export Statistics -->
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
                        <div class="stat-icon stat-success">
                            <i class="bi bi-check-circle"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['by_status']['in_stock'] ?? 0); ?></div>
                    <div class="stat-label">In Stock</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-warning">
                            <i class="bi bi-exclamation-triangle"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['by_status']['low_stock'] ?? 0); ?></div>
                    <div class="stat-label">Low Stock</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-danger">
                            <i class="bi bi-x-circle"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['by_status']['out_of_stock'] ?? 0); ?></div>
                    <div class="stat-label">Out of Stock</div>
                </div>
            </div>

            <!-- Export Form -->
            <div class="product-form">
                <div class="section-header">
                    <h3 class="section-title">
                        <i class="bi bi-download me-2"></i>
                        Export Options
                    </h3>
                </div>
                
                <form method="POST" id="exportForm">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="format" class="form-label">Export Format</label>
                            <select class="form-control" id="format" name="format">
                                <option value="csv">CSV (Comma Separated Values)</option>
                            </select>
                            <div class="form-text">CSV format is compatible with Excel and other spreadsheet applications</div>
                        </div>
                        
                        <div class="form-group">
                            <label for="category_filter" class="form-label">Filter by Category</label>
                            <select class="form-control" id="category_filter" name="category_filter">
                                <option value="">All Categories</option>
                                <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>">
                                    <?php echo htmlspecialchars($category['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="status_filter" class="form-label">Filter by Stock Status</label>
                            <select class="form-control" id="status_filter" name="status_filter">
                                <option value="">All Products</option>
                                <option value="in_stock">In Stock Only</option>
                                <option value="low_stock">Low Stock Only</option>
                                <option value="out_of_stock">Out of Stock Only</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Additional Fields</label>
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="include_description" name="include_description" checked>
                            <label class="form-check-label" for="include_description">
                                Include Product Descriptions
                            </label>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="include_dates" name="include_dates">
                            <label class="form-check-label" for="include_dates">
                                Include Created/Updated Dates
                            </label>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary btn-export">
                            <i class="bi bi-download"></i>
                            Download CSV Export
                        </button>
                    </div>
                </form>
            </div>

            <!-- Products by Category -->
            <div class="data-section">
                <div class="section-header">
                    <h3 class="section-title">
                        <i class="bi bi-pie-chart me-2"></i>
                        Products by Category
                    </h3>
                </div>
                
                <?php if (empty($stats['by_category'])): ?>
                <div class="text-center py-4">
                    <i class="bi bi-tags" style="font-size: 3rem; color: #9ca3af;"></i>
                    <p class="text-muted mt-2">No categories found</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Product Count</th>
                                <th>Percentage</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stats['by_category'] as $category): ?>
                            <tr>
                                <td>
                                    <span class="badge badge-secondary"><?php echo htmlspecialchars($category['name']); ?></span>
                                </td>
                                <td><?php echo number_format($category['count']); ?></td>
                                <td>
                                    <?php 
                                    $percentage = $stats['total_products'] > 0 ? ($category['count'] / $stats['total_products']) * 100 : 0;
                                    echo number_format($percentage, 1) . '%';
                                    ?>
                                </td>
                                <td>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="format" value="csv">
                                        <input type="hidden" name="category_filter" value="<?php echo $category['id'] ?? ''; ?>">
                                        <button type="submit" class="btn btn-outline-secondary btn-sm">
                                            <i class="bi bi-download"></i>
                                            Export
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- Quick Export Actions -->
            <div class="data-section">
                <div class="section-header">
                    <h3 class="section-title">
                        <i class="bi bi-lightning me-2"></i>
                        Quick Export Actions
                    </h3>
                </div>
                
                <div class="import-export-actions">
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="format" value="csv">
                        <input type="hidden" name="status_filter" value="low_stock">
                        <button type="submit" class="btn btn-warning">
                            <i class="bi bi-exclamation-triangle"></i>
                            Export Low Stock Products
                        </button>
                    </form>
                    
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="format" value="csv">
                        <input type="hidden" name="status_filter" value="out_of_stock">
                        <button type="submit" class="btn btn-danger">
                            <i class="bi bi-x-circle"></i>
                            Export Out of Stock Products
                        </button>
                    </form>
                    
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="format" value="csv">
                        <input type="hidden" name="include_description" value="1">
                        <input type="hidden" name="include_dates" value="1">
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-file-earmark-spreadsheet"></i>
                            Export Complete Database
                        </button>
                    </form>
                </div>
            </div>

            <!-- Export Information -->
            <div class="data-section">
                <div class="section-header">
                    <h3 class="section-title">
                        <i class="bi bi-info-circle me-2"></i>
                        Export Information
                    </h3>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <h5>What's Included in Export:</h5>
                        <ul>
                            <li>Product ID</li>
                            <li>Product Name</li>
                            <li>Category Name</li>
                            <li>Current Price</li>
                            <li>Current Stock Quantity</li>
                            <li>Barcode</li>
                            <li>Description (if selected)</li>
                            <li>Created/Updated Dates (if selected)</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <h5>Export Uses:</h5>
                        <ul>
                            <li>Backup your product data</li>
                            <li>Import to other systems</li>

                            <li>Share data with suppliers</li>
                            <li>Compliance and auditing</li>
                            <li>Data analysis and insights</li>
                        </ul>
                    </div>
                </div>
                
                <div class="alert alert-info mt-3">
                    <i class="bi bi-info-circle me-2"></i>
                    <strong>Note:</strong> The exported CSV file will include all current product information at the time of export. 

                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/products.js"></script>
</body>
</html>