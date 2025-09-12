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

// Get system settings for navmenu display
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Check if user has permission to view analytics
$hasAccess = hasPermission('view_analytics', $permissions) || 
             hasPermission('manage_sales', $permissions) || 
             hasPermission('manage_users', $permissions) ||
             hasPermission('view_finance', $permissions);

if (!$hasAccess) {
    header('Location: ../dashboard/dashboard.php');
    exit();
}

// Get analytics statistics
$stats = [];

// Total Sales Today
$stmt = $conn->prepare("SELECT COUNT(*) as count, COALESCE(SUM(final_amount), 0) as total FROM sales WHERE DATE(sale_date) = CURDATE()");
$stmt->execute();
$today_sales = $stmt->fetch(PDO::FETCH_ASSOC);
$stats['today_sales_count'] = $today_sales['count'];
$stats['today_sales_total'] = $today_sales['total'];

// Total Sales This Week
$stmt = $conn->prepare("SELECT COUNT(*) as count, COALESCE(SUM(final_amount), 0) as total FROM sales WHERE YEARWEEK(sale_date) = YEARWEEK(CURDATE())");
$stmt->execute();
$week_sales = $stmt->fetch(PDO::FETCH_ASSOC);
$stats['week_sales_count'] = $week_sales['count'];
$stats['week_sales_total'] = $week_sales['total'];

// Total Sales This Month
$stmt = $conn->prepare("SELECT COUNT(*) as count, COALESCE(SUM(final_amount), 0) as total FROM sales WHERE MONTH(sale_date) = MONTH(CURDATE()) AND YEAR(sale_date) = YEAR(CURDATE())");
$stmt->execute();
$month_sales = $stmt->fetch(PDO::FETCH_ASSOC);
$stats['month_sales_count'] = $month_sales['count'];
$stats['month_sales_total'] = $month_sales['total'];

// Total Products
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM products WHERE status = 'active'");
$stmt->execute();
$stats['total_products'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Total Customers
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM customers");
$stmt->execute();
$stats['total_customers'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Low Stock Products
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM products WHERE quantity <= minimum_stock AND status = 'active'");
$stmt->execute();
$stats['low_stock_products'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Get recent transactions
$recent_sales = [];
if (hasPermission('manage_sales', $permissions) || hasPermission('process_sales', $permissions)) {
    $stmt = $conn->prepare("
        SELECT s.*, u.username as cashier_name 
        FROM sales s 
        LEFT JOIN users u ON s.user_id = u.id 
        ORDER BY s.sale_date DESC 
        LIMIT 10
    ");
    $stmt->execute();
    $recent_sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics Dashboard - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
        
        .analytics-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1rem;
        }
        
        .analytics-card.sales {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
        }
        
        .analytics-card.products {
            background: linear-gradient(135deg, #fc466b 0%, #3f5efb 100%);
        }
        
        .analytics-card.customers {
            background: linear-gradient(135deg, #fdbb2d 0%, #22c1c3 100%);
        }
        
        .analytics-card.inventory {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
        
        /* Module Card Styles */
        .module-card {
            border: none;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            border-radius: 12px;
        }
        
        .module-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }
        
        .module-icon {
            transition: transform 0.3s ease;
        }
        
        .module-card:hover .module-icon {
            transform: scale(1.1);
        }
        
        .module-stats {
            background: rgba(0, 0, 0, 0.05);
            border-radius: 6px;
            padding: 8px 12px;
            margin-top: 10px;
        }
        
        /* Quick Action Cards */
        .quick-action-card {
            border: none;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            border-radius: 8px;
        }
        
        .quick-action-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }
        
        .quick-icon {
            transition: transform 0.3s ease;
        }
        
        .quick-action-card:hover .quick-icon {
            transform: scale(1.1);
        }
        
        /* Card hover effects */
        .card {
            transition: all 0.3s ease;
        }
        
        .card:hover {
            border-color: var(--primary-color);
        }
    </style>
</head>
<body>
    <?php include '../include/navmenu.php'; ?>

    <div class="main-content">
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1><i class="bi bi-graph-up"></i> Analytics Dashboard</h1>
                    <p class="header-subtitle">Comprehensive business analytics and insights</p>
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

        <main class="content">
            <div class="container-fluid">
                <!-- Key Analytics Metrics -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="analytics-card">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Today's Sales</h6>
                                <i class="bi bi-cash-coin fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($stats['today_sales_total'], 2); ?></h3>
                            <small class="opacity-75"><?php echo $stats['today_sales_count']; ?> transactions</small>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="analytics-card sales">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Monthly Sales</h6>
                                <i class="bi bi-graph-up-arrow fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($stats['month_sales_total'], 2); ?></h3>
                            <small class="opacity-75"><?php echo $stats['month_sales_count']; ?> transactions</small>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="analytics-card products">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Total Products</h6>
                                <i class="bi bi-box fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo number_format($stats['total_products']); ?></h3>
                            <small class="opacity-75">Active products</small>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="analytics-card customers">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Total Customers</h6>
                                <i class="bi bi-people fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo number_format($stats['total_customers']); ?></h3>
                            <small class="opacity-75">Registered customers</small>
                        </div>
                    </div>
                </div>

                <!-- Analytics Modules Navigation Cards -->
                <div class="row mb-4">
                    <div class="col-12 mb-3">
                        <h4><i class="bi bi-grid-3x3-gap"></i> Analytics Modules</h4>
                        <p class="text-muted">Select a module to access detailed analytics and insights</p>
                    </div>
                </div>

                <!-- Main Analytics Navigation Cards -->
                <div class="row mb-4">
                    <!-- Sales Analytics -->
                    <div class="col-xl-3 col-lg-4 col-md-6 mb-4">
                        <div class="card module-card h-100" onclick="location.href='../sales/analytics.php'" style="cursor: pointer;">
                            <div class="card-body text-center p-4">
                                <div class="module-icon mb-3">
                                    <i class="bi bi-bar-chart fs-1 text-primary"></i>
                                </div>
                                <h5 class="card-title">Sales Analytics</h5>
                                <p class="card-text text-muted">Sales trends, performance metrics, and revenue analysis</p>
                                <div class="module-stats">
                                    <small class="text-success"><i class="bi bi-graph-up"></i> Monthly Revenue: <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($stats['month_sales_total'], 0); ?></small>
                                </div>
                            </div>
                            <div class="card-footer bg-transparent border-0">
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">View Analytics</small>
                                    <i class="bi bi-arrow-right text-primary"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Inventory Analytics -->
                    <div class="col-xl-3 col-lg-4 col-md-6 mb-4">
                        <div class="card module-card h-100" onclick="location.href='inventory.php'" style="cursor: pointer;">
                            <div class="card-body text-center p-4">
                                <div class="module-icon mb-3">
                                    <i class="bi bi-boxes fs-1 text-success"></i>
                                </div>
                                <h5 class="card-title">Inventory Analytics</h5>
                                <p class="card-text text-muted">Stock levels, turnover rates, and inventory optimization</p>
                                <div class="module-stats">
                                    <small class="text-info"><i class="bi bi-box"></i> Total Products: <?php echo number_format($stats['total_products']); ?></small>
                                </div>
                            </div>
                            <div class="card-footer bg-transparent border-0">
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">View Analytics</small>
                                    <i class="bi bi-arrow-right text-success"></i>
                                </div>
                            </div>
                        </div>
                    </div>


                    <!-- Financial Analytics -->
                    <div class="col-xl-3 col-lg-4 col-md-6 mb-4">
                        <div class="card module-card h-100" onclick="location.href='../finance/sales-analytics.php'" style="cursor: pointer;">
                            <div class="card-body text-center p-4">
                                <div class="module-icon mb-3">
                                    <i class="bi bi-calculator fs-1 text-warning"></i>
                                </div>
                                <h5 class="card-title">Financial Analytics</h5>
                                <p class="card-text text-muted">Profit margins, ROI analysis, and financial performance</p>
                                <div class="module-stats">
                                    <small class="text-warning"><i class="bi bi-graph-up"></i> Financial Insights</small>
                                </div>
                            </div>
                            <div class="card-footer bg-transparent border-0">
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">View Analytics</small>
                                    <i class="bi bi-arrow-right text-warning"></i>
                                </div>
                            </div>
                        </div>
                    </div>


                    <!-- Reports & Insights -->
                    <div class="col-xl-3 col-lg-4 col-md-6 mb-4">
                        <div class="card module-card h-100" onclick="location.href='../reports/index.php'" style="cursor: pointer;">
                            <div class="card-body text-center p-4">
                                <div class="module-icon mb-3">
                                    <i class="bi bi-file-earmark-bar-graph fs-1 text-secondary"></i>
                                </div>
                                <h5 class="card-title">Reports & Insights</h5>
                                <p class="card-text text-muted">Comprehensive reports, data exports, and business insights</p>
                                <div class="module-stats">
                                    <small class="text-secondary"><i class="bi bi-file-text"></i> Detailed Reports</small>
                                </div>
                            </div>
                            <div class="card-footer bg-transparent border-0">
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">View Reports</small>
                                    <i class="bi bi-arrow-right text-secondary"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions Row -->
                <div class="row mb-4">
                    <div class="col-12 mb-3">
                        <h5><i class="bi bi-lightning"></i> Quick Actions</h5>
                    </div>
                </div>

                <div class="row">
                    <!-- Quick Links -->
                    <div class="col-xl-4 col-md-6 mb-4">
                        <div class="card quick-action-card" onclick="location.href='../sales/index.php'" style="cursor: pointer;">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="quick-icon me-3">
                                        <i class="bi bi-receipt text-primary fs-3"></i>
                                    </div>
                                    <div>
                                        <h6 class="mb-1">View All Sales</h6>
                                        <small class="text-muted">Access complete sales history</small>
                                    </div>
                                    <div class="ms-auto">
                                        <i class="bi bi-arrow-right text-primary"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-4 col-md-6 mb-4">
                        <div class="card quick-action-card" onclick="location.href='../products/products.php'" style="cursor: pointer;">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="quick-icon me-3">
                                        <i class="bi bi-box text-success fs-3"></i>
                                    </div>
                                    <div>
                                        <h6 class="mb-1">View Products</h6>
                                        <small class="text-muted">Manage product inventory</small>
                                    </div>
                                    <div class="ms-auto">
                                        <i class="bi bi-arrow-right text-success"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-4 col-md-6 mb-4">
                        <div class="card quick-action-card" onclick="location.href='../customers/index.php'" style="cursor: pointer;">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="quick-icon me-3">
                                        <i class="bi bi-people text-info fs-3"></i>
                                    </div>
                                    <div>
                                        <h6 class="mb-1">View Customers</h6>
                                        <small class="text-muted">Manage customer database</small>
                                    </div>
                                    <div class="ms-auto">
                                        <i class="bi bi-arrow-right text-info"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
