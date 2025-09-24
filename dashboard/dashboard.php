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

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get dashboard statistics
$stats = [];

// Total Sales Today
$stmt = $conn->prepare("SELECT COUNT(*) as count, COALESCE(SUM(final_amount), 0) as total FROM sales WHERE DATE(sale_date) = CURDATE()");
$stmt->execute();
$today_sales = $stmt->fetch(PDO::FETCH_ASSOC);
$stats['today_sales_count'] = $today_sales['count'];
$stats['today_sales_total'] = $today_sales['total'];

// Total Sales This Month
$stmt = $conn->prepare("SELECT COUNT(*) as count, COALESCE(SUM(final_amount), 0) as total FROM sales WHERE MONTH(sale_date) = MONTH(CURDATE()) AND YEAR(sale_date) = YEAR(CURDATE())");
$stmt->execute();
$month_sales = $stmt->fetch(PDO::FETCH_ASSOC);
$stats['month_sales_count'] = $month_sales['count'];
$stats['month_sales_total'] = $month_sales['total'];

// Total Products
$stmt = $conn->query("SELECT COUNT(*) as count FROM products");
$stats['total_products'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Low Stock Products (quantity < 10)
$stmt = $conn->query("SELECT COUNT(*) as count FROM products WHERE quantity < 10");
$stats['low_stock'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Total Customers (unique from sales)
$stmt = $conn->query("SELECT COUNT(DISTINCT customer_name) as count FROM sales WHERE customer_name != 'Walking Customer'");
$stats['total_customers'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Recent Sales
$recent_sales = [];
if (hasPermission('manage_sales', $permissions) || hasPermission('process_sales', $permissions)) {
    $stmt = $conn->prepare("
        SELECT s.*, u.username as cashier_name 
        FROM sales s 
        LEFT JOIN users u ON s.user_id = u.id 
        ORDER BY s.sale_date DESC 
        LIMIT 5
    ");
    $stmt->execute();
    $recent_sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Top Selling Products
$top_products = [];
if (hasPermission('manage_products', $permissions)) {
    $stmt = $conn->prepare("
        SELECT p.name, SUM(si.quantity) as total_sold, SUM(si.quantity * si.unit_price) as total_revenue
        FROM products p
        JOIN sale_items si ON p.id = si.product_id
        JOIN sales s ON si.sale_id = s.id
        WHERE DATE(s.sale_date) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY p.id, p.name
        ORDER BY total_sold DESC
        LIMIT 5
    ");
    $stmt->execute();
    $top_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
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
    <!-- Sidebar -->
    <?php
    $current_page = 'dashboard';
    include __DIR__ . '/../include/navmenu.php';
    ?>

    <div class="main-content">
        <!-- Header -->
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1>Dashboard</h1>
                    <p class="header-subtitle">Welcome back, <?php echo htmlspecialchars($username); ?>!</p>
                </div>
                <div class="header-actions">
                    <div class="user-info">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($username, 0, 2)); ?>
                        </div>
                        <div>
                            <div class="fw-semibold"><?php echo htmlspecialchars($username); ?></div>
                            <small class="text-muted"><?php echo htmlspecialchars($role_name); ?></small>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- Content -->
        <main class="content">
            <!-- Error Messages -->
            <?php if (isset($_SESSION['logout_error'])): ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <?php echo htmlspecialchars($_SESSION['logout_error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['logout_error']); ?>
            <?php endif; ?>
            
            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-primary">
                            <i class="bi bi-cash-coin"></i>
                        </div>
                    </div>
                    <div class="stat-value currency"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($stats['today_sales_total'], 2); ?></div>
                    <div class="stat-label">Today's Sales</div>
                    <div class="stat-change positive">
                        <i class="bi bi-arrow-up"></i> <?php echo $stats['today_sales_count']; ?> transactions
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-success">
                            <i class="bi bi-calendar-month"></i>
                        </div>
                    </div>
                    <div class="stat-value currency"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($stats['month_sales_total'], 2); ?></div>
                    <div class="stat-label">This Month</div>
                    <div class="stat-change positive">
                        <i class="bi bi-arrow-up"></i> <?php echo $stats['month_sales_count']; ?> transactions
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-info">
                            <i class="bi bi-box"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['total_products']); ?></div>
                    <div class="stat-label">Total Products</div>
                    <?php if ($stats['low_stock'] > 0): ?>
                    <div class="stat-change negative">
                        <i class="bi bi-exclamation-triangle"></i> <?php echo $stats['low_stock']; ?> low stock
                    </div>
                    <?php else: ?>
                    <div class="stat-change positive">
                        <i class="bi bi-check-circle"></i> Stock levels good
                    </div>
                    <?php endif; ?>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-warning">
                            <i class="bi bi-people"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['total_customers']); ?></div>
                    <div class="stat-label">Registered Customers</div>
                    <div class="stat-change positive">
                        <i class="bi bi-arrow-up"></i> Growing customer base
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <?php if (hasPermission('process_sales', $permissions)): ?>
            <div class="quick-actions">
                <a href="<?php echo url('pos/index.php'); ?>" class="action-btn">
                    <i class="bi bi-cart-plus"></i>
                    New Sale
                </a>
                <?php if (hasPermission('manage_products', $permissions)): ?>
                <a href="<?php echo url('products/add.php'); ?>" class="action-btn">
                    <i class="bi bi-plus-circle"></i>
                    Add Product
                </a>
                <?php endif; ?>
                <a href="<?php echo url('customers/add.php'); ?>" class="action-btn">
                    <i class="bi bi-person-plus"></i>
                    Add Customer
                </a>

            </div>
            <?php endif; ?>

            <!-- Recent Sales -->
            <?php if (!empty($recent_sales)): ?>
            <div class="data-section">
                <div class="section-header">
                    <h3 class="section-title">Recent Sales</h3>
                    <a href="<?php echo url('sales/index.php'); ?>" class="btn btn-outline-primary btn-sm">View All</a>
                </div>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Sale ID</th>
                                <th>Customer</th>
                                <th>Amount</th>
                                <th>Cashier</th>
                                <th>Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_sales as $sale): ?>
                            <tr>
                                <td>#<?php echo str_pad($sale['id'], 6, '0', STR_PAD_LEFT); ?></td>
                                <td><?php echo htmlspecialchars($sale['customer_name']); ?></td>
                                <td class="currency"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($sale['final_amount'], 2); ?></td>
                                <td><?php echo htmlspecialchars($sale['cashier_name'] ?? 'Unknown'); ?></td>
                                <td><?php echo date('M j, Y g:i A', strtotime($sale['sale_date'])); ?></td>
                                <td><span class="badge bg-success">Completed</span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- Top Products -->
            <?php if (!empty($top_products)): ?>
            <div class="data-section">
                <div class="section-header">
                    <h3 class="section-title">Top Selling Products (Last 30 Days)</h3>

                </div>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Product Name</th>
                                <th>Units Sold</th>
                                <th>Revenue</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($top_products as $product): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($product['name']); ?></td>
                                <td><?php echo number_format($product['total_sold']); ?></td>
                                <td class="currency"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($product['total_revenue'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/dashboard.js"></script>
</body>
</html>