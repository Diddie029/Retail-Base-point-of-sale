<?php
session_start();
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';

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

// Check if user has permission to manage products
if (!hasPermission('manage_products', $permissions)) {
    header("Location: ../dashboard/dashboard.php");
    exit();
}

// Get product ID
$product_id = (int)($_GET['id'] ?? 0);
if ($product_id <= 0) {
    header("Location: index.php");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get product data with category, brand, and supplier
$stmt = $conn->prepare("
    SELECT p.*, c.name as category_name, b.name as brand_name, s.name as supplier_name
    FROM products p 
    LEFT JOIN categories c ON p.category_id = c.id 
    LEFT JOIN brands b ON p.brand_id = b.id
    LEFT JOIN suppliers s ON p.supplier_id = s.id
    WHERE p.id = :id
");
$stmt->bindParam(':id', $product_id);
$stmt->execute();
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    $_SESSION['error'] = 'Product not found.';
    header("Location: index.php");
    exit();
}

// Get sales history for this product
$sales_stmt = $conn->prepare("
    SELECT s.id, s.sale_date, s.customer_name, si.quantity, si.price, 
           (si.quantity * si.price) as total, u.username as cashier
    FROM sale_items si
    JOIN sales s ON si.sale_id = s.id
    LEFT JOIN users u ON s.user_id = u.id
    WHERE si.product_id = :product_id
    ORDER BY s.sale_date DESC
    LIMIT 10
");
$sales_stmt->bindParam(':product_id', $product_id);
$sales_stmt->execute();
$sales_history = $sales_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get sales statistics
$stats_stmt = $conn->prepare("
    SELECT 
        COUNT(si.id) as total_sales,
        SUM(si.quantity) as total_quantity_sold,
        SUM(si.quantity * si.price) as total_revenue,
        AVG(si.price) as avg_selling_price,
        MIN(si.price) as min_price,
        MAX(si.price) as max_price
    FROM sale_items si
    JOIN sales s ON si.sale_id = s.id
    WHERE si.product_id = :product_id
");
$stats_stmt->bindParam(':product_id', $product_id);
$stats_stmt->execute();
$sales_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Handle success message
$success = $_SESSION['success'] ?? '';
unset($_SESSION['success']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($product['name']); ?> - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
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
    <?php
    $current_page = 'products';
    include __DIR__ . '/../include/navmenu.php';
    ?>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1><?php echo htmlspecialchars($product['name']); ?></h1>
                    <div class="header-subtitle">Product details and sales information</div>
                </div>
                <div class="header-actions">
                    <a href="edit.php?id=<?php echo $product_id; ?>" class="btn btn-warning">
                        <i class="bi bi-pencil"></i>
                        Edit Product
                    </a>
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
            <?php if (!empty($success)): ?>
            <div class="alert alert-success">
                <i class="bi bi-check-circle me-2"></i>
                <?php echo htmlspecialchars($success); ?>
            </div>
            <?php endif; ?>

            <!-- Product Information Card -->
            <div class="product-form mb-4">
                <div class="form-row">
                    <div class="col-md-3">
                        <div class="product-image-placeholder" style="width: 200px; height: 200px; font-size: 3rem;">
                            <i class="bi bi-image"></i>
                        </div>
                    </div>
                    <div class="col-md-9">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <h3 class="mb-2"><?php echo htmlspecialchars($product['name']); ?></h3>
                                <p class="text-muted mb-3"><?php echo htmlspecialchars($product['description'] ?? 'No description available'); ?></p>
                                
                                <div class="mb-2">
                                    <strong>Category:</strong> 
                                    <span class="badge badge-secondary"><?php echo htmlspecialchars($product['category_name'] ?? 'Uncategorized'); ?></span>
                                </div>
                                
                                <div class="mb-2">
                                    <strong>Barcode:</strong> 
                                    <code><?php echo htmlspecialchars($product['barcode']); ?></code>
                                </div>
                                
                                <div class="mb-2">
                                    <strong>Product ID:</strong> 
                                    <span class="text-muted">#<?php echo $product['id']; ?></span>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="stat-card">
                                    <?php if (isProductOnSale($product)): ?>
                                        <?php $sale_info = getProductSaleInfo($product); ?>
                                        <div class="stat-value currency text-danger">
                                            <?php echo $settings['currency_symbol']; ?> <?php echo number_format($sale_info['sale_price'], 2); ?>
                                            <span class="badge badge-danger ms-2">ON SALE</span>
                                        </div>
                                        <div class="stat-label">
                                            <del class="text-muted"><?php echo $settings['currency_symbol']; ?> <?php echo number_format($product['price'], 2); ?></del>
                                            <span class="text-success fw-bold ms-2">-<?php echo $sale_info['discount_percentage']; ?>%</span>
                                        </div>
                                        <div class="mt-1">
                                            <small class="text-muted">
                                                Save <?php echo $settings['currency_symbol']; ?> <?php echo number_format($sale_info['savings'], 2); ?>
                                                <?php if (!empty($sale_info['end_date'])): ?>
                                                    <br>Ends: <?php echo formatSaleDate($sale_info['end_date']); ?>
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                    <?php else: ?>
                                        <div class="stat-value currency"><?php echo $settings['currency_symbol']; ?> <?php echo number_format($product['price'], 2); ?></div>
                                        <div class="stat-label">Current Price</div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="stat-card mt-3">
                                    <div class="stat-value">
                                        <?php echo number_format($product['quantity']); ?>
                                        <?php if ($product['quantity'] == 0): ?>
                                            <span class="badge badge-danger ms-2">Out of Stock</span>
                                        <?php elseif ($product['quantity'] <= 10): ?>
                                            <span class="badge badge-warning ms-2">Low Stock</span>
                                        <?php else: ?>
                                            <span class="badge badge-success ms-2">In Stock</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="stat-label">Current Stock</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="d-flex gap-2">
                            <a href="edit.php?id=<?php echo $product_id; ?>" class="btn btn-warning">
                                <i class="bi bi-pencil"></i>
                                Edit Product
                            </a>
                            <a href="delete.php?id=<?php echo $product_id; ?>" 
                               class="btn btn-danger btn-delete" 
                               data-product-name="<?php echo htmlspecialchars($product['name']); ?>">
                                <i class="bi bi-trash"></i>
                                Delete Product
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sale Information -->
            <?php if (!empty($product['sale_price'])): ?>
            <div class="data-section">
                <div class="section-header">
                    <h3 class="section-title">
                        <i class="bi bi-tag me-2"></i>
                        Sale Information
                    </h3>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="info-list">
                            <div class="info-item">
                                <div class="info-label">Sale Price:</div>
                                <div class="info-value currency text-danger fw-bold">
                                    <?php echo $settings['currency_symbol']; ?> <?php echo number_format($product['sale_price'], 2); ?>
                                </div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Regular Price:</div>
                                <div class="info-value currency text-muted">
                                    <del><?php echo $settings['currency_symbol']; ?> <?php echo number_format($product['price'], 2); ?></del>
                                </div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Discount:</div>
                                <div class="info-value text-success fw-bold">
                                    -<?php echo number_format((($product['price'] - $product['sale_price']) / $product['price']) * 100, 1); ?>%
                                    (Save <?php echo $settings['currency_symbol']; ?> <?php echo number_format($product['price'] - $product['sale_price'], 2); ?>)
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="info-list">
                            <div class="info-item">
                                <div class="info-label">Sale Status:</div>
                                <div class="info-value">
                                    <?php if (isProductOnSale($product)): ?>
                                        <span class="badge badge-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary">
                                            <?php if (!empty($product['sale_start_date']) && strtotime($product['sale_start_date']) > time()): ?>
                                                Scheduled - Starts <?php echo formatSaleDate($product['sale_start_date']); ?>
                                            <?php elseif (!empty($product['sale_end_date']) && strtotime($product['sale_end_date']) < time()): ?>
                                                Expired - Ended <?php echo formatSaleDate($product['sale_end_date']); ?>
                                            <?php else: ?>
                                                Inactive
                                            <?php endif; ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if (!empty($product['sale_start_date'])): ?>
                            <div class="info-item">
                                <div class="info-label">Sale Start:</div>
                                <div class="info-value"><?php echo formatSaleDate($product['sale_start_date']); ?></div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($product['sale_end_date'])): ?>
                            <div class="info-item">
                                <div class="info-label">Sale End:</div>
                                <div class="info-value"><?php echo formatSaleDate($product['sale_end_date']); ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Sales Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-primary">
                            <i class="bi bi-graph-up"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($sales_stats['total_sales'] ?? 0); ?></div>
                    <div class="stat-label">Total Sales</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-success">
                            <i class="bi bi-box-seam"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($sales_stats['total_quantity_sold'] ?? 0); ?></div>
                    <div class="stat-label">Units Sold</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-warning">
                            <i class="bi bi-currency-dollar"></i>
                        </div>
                    </div>
                    <div class="stat-value currency"><?php echo $settings['currency_symbol']; ?> <?php echo number_format($sales_stats['total_revenue'] ?? 0, 2); ?></div>
                    <div class="stat-label">Total Revenue</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-info">
                            <i class="bi bi-calculator"></i>
                        </div>
                    </div>
                    <div class="stat-value currency"><?php echo $settings['currency_symbol']; ?> <?php echo number_format($sales_stats['avg_selling_price'] ?? 0, 2); ?></div>
                    <div class="stat-label">Avg. Selling Price</div>
                </div>
            </div>

            <!-- Product Details -->
            <div class="data-section">
                <div class="section-header">
                    <h3 class="section-title">
                        <i class="bi bi-info-circle me-2"></i>
                        Product Details
                    </h3>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <table class="table">
                            <tr>
                                <td><strong>Category:</strong></td>
                                <td><?php echo htmlspecialchars($product['category_name'] ?? 'Uncategorized'); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Brand:</strong></td>
                                <td><?php echo htmlspecialchars($product['brand_name'] ?? 'Not specified'); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Supplier:</strong></td>
                                <td><?php echo htmlspecialchars($product['supplier_name'] ?? 'Not specified'); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Created Date:</strong></td>
                                <td><?php echo date('F j, Y \a\t g:i A', strtotime($product['created_at'])); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Last Updated:</strong></td>
                                <td><?php echo date('F j, Y \a\t g:i A', strtotime($product['updated_at'])); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Current Value:</strong></td>
                                <td class="currency"><?php echo $settings['currency_symbol']; ?> <?php echo number_format($product['price'] * $product['quantity'], 2); ?></td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table">
                            <tr>
                                <td><strong>Price Range:</strong></td>
                                <td>
                                    <?php if ($sales_stats['min_price'] && $sales_stats['max_price']): ?>
                                        <?php echo $settings['currency_symbol']; ?> <?php echo number_format($sales_stats['min_price'], 2); ?> - 
                                        <?php echo $settings['currency_symbol']; ?> <?php echo number_format($sales_stats['max_price'], 2); ?>
                                    <?php else: ?>
                                        <span class="text-muted">No sales data</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Stock Status:</strong></td>
                                <td>
                                    <?php if ($product['quantity'] == 0): ?>
                                        <span class="badge badge-danger">Out of Stock</span>
                                    <?php elseif ($product['quantity'] <= 10): ?>
                                        <span class="badge badge-warning">Low Stock Alert</span>
                                    <?php else: ?>
                                        <span class="badge badge-success">Well Stocked</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Reorder Status:</strong></td>
                                <td>
                                    <?php if ($product['quantity'] <= 5): ?>
                                        <span class="text-danger">Reorder Needed</span>
                                    <?php elseif ($product['quantity'] <= 15): ?>
                                        <span class="text-warning">Monitor Stock</span>
                                    <?php else: ?>
                                        <span class="text-success">Stock OK</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Recent Sales History -->
            <div class="data-section">
                <div class="section-header">
                    <h3 class="section-title">
                        <i class="bi bi-clock-history me-2"></i>
                        Recent Sales History
                    </h3>
                    <?php if (hasPermission('manage_sales', $permissions)): ?>
                    <a href="../sales/index.php?product_id=<?php echo $product_id; ?>" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-eye"></i>
                        View All Sales
                    </a>
                    <?php endif; ?>
                </div>
                
                <?php if (empty($sales_history)): ?>
                <div class="text-center py-4">
                    <i class="bi bi-receipt" style="font-size: 3rem; color: #9ca3af;"></i>
                    <p class="text-muted mt-2">No sales recorded for this product yet</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Sale Date</th>
                                <th>Customer</th>
                                <th>Quantity</th>
                                <th>Unit Price</th>
                                <th>Total</th>
                                <th>Cashier</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sales_history as $sale): ?>
                            <tr>
                                <td>
                                    <small><?php echo date('M j, Y g:i A', strtotime($sale['sale_date'])); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($sale['customer_name']); ?></td>
                                <td><?php echo number_format($sale['quantity']); ?></td>
                                <td class="currency"><?php echo $settings['currency_symbol']; ?> <?php echo number_format($sale['price'], 2); ?></td>
                                <td class="currency font-weight-bold"><?php echo $settings['currency_symbol']; ?> <?php echo number_format($sale['total'], 2); ?></td>
                                <td><?php echo htmlspecialchars($sale['cashier'] ?? 'Unknown'); ?></td>
                                <td>
                                    <?php if (hasPermission('manage_sales', $permissions)): ?>
                                    <a href="../sales/view.php?id=<?php echo $sale['id']; ?>" class="btn btn-outline-secondary btn-sm">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <?php endif; ?>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/products.js"></script>
</body>
</html>