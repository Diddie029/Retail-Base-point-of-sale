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

// Check if user has permission to view sales analytics
$hasAccess = hasPermission('view_analytics', $permissions) || 
             hasPermission('manage_sales', $permissions) || 
             hasPermission('view_sales', $permissions) ||
             hasPermission('manage_users', $permissions);

if (!$hasAccess) {
    header('Location: ../dashboard/dashboard.php');
    exit();
}

// Get sales analytics data
$analytics = [];

// Total Sales - All Time
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_transactions,
        COALESCE(SUM(final_amount), 0) as total_revenue,
        COALESCE(AVG(final_amount), 0) as average_transaction,
        COALESCE(MIN(final_amount), 0) as min_transaction,
        COALESCE(MAX(final_amount), 0) as max_transaction
    FROM sales
");
$stmt->execute();
$analytics['all_time'] = $stmt->fetch(PDO::FETCH_ASSOC);

// Today's Sales
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_transactions,
        COALESCE(SUM(final_amount), 0) as total_revenue,
        COALESCE(AVG(final_amount), 0) as average_transaction
    FROM sales 
    WHERE DATE(sale_date) = CURDATE()
");
$stmt->execute();
$analytics['today'] = $stmt->fetch(PDO::FETCH_ASSOC);

// This Week's Sales
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_transactions,
        COALESCE(SUM(final_amount), 0) as total_revenue,
        COALESCE(AVG(final_amount), 0) as average_transaction
    FROM sales 
    WHERE YEARWEEK(sale_date) = YEARWEEK(CURDATE())
");
$stmt->execute();
$analytics['this_week'] = $stmt->fetch(PDO::FETCH_ASSOC);

// This Month's Sales
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_transactions,
        COALESCE(SUM(final_amount), 0) as total_revenue,
        COALESCE(AVG(final_amount), 0) as average_transaction
    FROM sales 
    WHERE MONTH(sale_date) = MONTH(CURDATE()) AND YEAR(sale_date) = YEAR(CURDATE())
");
$stmt->execute();
$analytics['this_month'] = $stmt->fetch(PDO::FETCH_ASSOC);

// This Year's Sales
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_transactions,
        COALESCE(SUM(final_amount), 0) as total_revenue,
        COALESCE(AVG(final_amount), 0) as average_transaction
    FROM sales 
    WHERE YEAR(sale_date) = YEAR(CURDATE())
");
$stmt->execute();
$analytics['this_year'] = $stmt->fetch(PDO::FETCH_ASSOC);

// Last 30 Days Sales (for trend analysis)
$stmt = $conn->prepare("
    SELECT 
        DATE(sale_date) as sale_date,
        COUNT(*) as daily_transactions,
        COALESCE(SUM(final_amount), 0) as daily_revenue
    FROM sales 
    WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY DATE(sale_date)
    ORDER BY sale_date DESC
");
$stmt->execute();
$analytics['last_30_days'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Top Selling Products (Last 30 Days)
$stmt = $conn->prepare("
    SELECT 
        p.name as product_name,
        p.sku,
        SUM(si.quantity) as total_quantity_sold,
        SUM(si.total_price) as total_revenue,
        COUNT(DISTINCT s.id) as times_sold
    FROM sales s
    JOIN sale_items si ON s.id = si.sale_id
    JOIN products p ON si.product_id = p.id
    WHERE s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY p.id, p.name, p.sku
    ORDER BY total_quantity_sold DESC
    LIMIT 5
");
$stmt->execute();
$analytics['top_products'] = $stmt->fetchAll(PDO::FETCH_ASSOC);


// Category Performance (Last 30 Days)
$stmt = $conn->prepare("
    SELECT 
        c.name as category_name,
        SUM(si.quantity) as total_quantity,
        SUM(si.total_price) as total_revenue,
        ROUND((SUM(si.total_price) * 100.0 / (
            SELECT SUM(si2.total_price) 
            FROM sales s2 
            JOIN sale_items si2 ON s2.id = si2.sale_id 
            JOIN products p2 ON si2.product_id = p2.id 
            WHERE s2.sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        )), 2) as percentage
    FROM sales s
    JOIN sale_items si ON s.id = si.sale_id
    JOIN products p ON si.product_id = p.id
    JOIN categories c ON p.category_id = c.id
    WHERE s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY c.id, c.name
    ORDER BY total_revenue DESC
    LIMIT 5
");
$stmt->execute();
$analytics['top_categories'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Sales by Payment Method (Last 30 Days)
$stmt = $conn->prepare("
    SELECT 
        payment_method,
        COUNT(*) as transaction_count,
        COALESCE(SUM(final_amount), 0) as total_amount
    FROM sales 
    WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY payment_method
    ORDER BY total_amount DESC
");
$stmt->execute();
$analytics['payment_methods'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Sales by Cashier (Last 30 Days)
$stmt = $conn->prepare("
    SELECT 
        u.username as cashier_name,
        COUNT(*) as transaction_count,
        COALESCE(SUM(s.final_amount), 0) as total_amount
    FROM sales s
    LEFT JOIN users u ON s.user_id = u.id
    WHERE s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY s.user_id, u.username
    ORDER BY total_amount DESC
");
$stmt->execute();
$analytics['cashiers'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent Sales
$stmt = $conn->prepare("
    SELECT 
        s.*,
        u.username as cashier_name,
        COUNT(si.id) as item_count
    FROM sales s
    LEFT JOIN users u ON s.user_id = u.id
    LEFT JOIN sale_items si ON s.id = si.sale_id
    GROUP BY s.id
    ORDER BY s.sale_date DESC
    LIMIT 5
");
$stmt->execute();
$analytics['recent_sales'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate growth rates
$analytics['growth_rates'] = [];

// Week over week growth
$stmt = $conn->prepare("
    SELECT 
        COALESCE(SUM(final_amount), 0) as current_week
    FROM sales 
    WHERE YEARWEEK(sale_date) = YEARWEEK(CURDATE())
");
$stmt->execute();
$current_week = $stmt->fetch(PDO::FETCH_ASSOC)['current_week'];

$stmt = $conn->prepare("
    SELECT 
        COALESCE(SUM(final_amount), 0) as previous_week
    FROM sales 
    WHERE YEARWEEK(sale_date) = YEARWEEK(CURDATE()) - 1
");
$stmt->execute();
$previous_week = $stmt->fetch(PDO::FETCH_ASSOC)['previous_week'];

$analytics['growth_rates']['week_over_week'] = $previous_week > 0 ? 
    (($current_week - $previous_week) / $previous_week) * 100 : 0;

// Month over month growth
$stmt = $conn->prepare("
    SELECT 
        COALESCE(SUM(final_amount), 0) as current_month
    FROM sales 
    WHERE MONTH(sale_date) = MONTH(CURDATE()) AND YEAR(sale_date) = YEAR(CURDATE())
");
$stmt->execute();
$current_month = $stmt->fetch(PDO::FETCH_ASSOC)['current_month'];

$stmt = $conn->prepare("
    SELECT 
        COALESCE(SUM(final_amount), 0) as previous_month
    FROM sales 
    WHERE MONTH(sale_date) = MONTH(CURDATE()) - 1 AND YEAR(sale_date) = YEAR(CURDATE())
");
$stmt->execute();
$previous_month = $stmt->fetch(PDO::FETCH_ASSOC)['previous_month'];

$analytics['growth_rates']['month_over_month'] = $previous_month > 0 ? 
    (($current_month - $previous_month) / $previous_month) * 100 : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Analytics - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        
        .analytics-card.revenue {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
        }
        
        .analytics-card.transactions {
            background: linear-gradient(135deg, #fc466b 0%, #3f5efb 100%);
        }
        
        .analytics-card.average {
            background: linear-gradient(135deg, #fdbb2d 0%, #22c1c3 100%);
        }
        
        .analytics-card.growth {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
        
        .chart-container {
            position: relative;
            height: 400px;
            margin: 20px 0;
        }
        
        .table-responsive {
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .growth-positive {
            color: #28a745;
        }
        
        .growth-negative {
            color: #dc3545;
        }
        
        .cashier-avatar {
            width: 60px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            color: white;
        }
        
        .category-item {
            padding: 0.75rem;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .category-item:hover {
            background-color: #f8f9fa;
            border-color: #dee2e6;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .category-rank .badge {
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
        }
        
        .stat-card {
            border: none;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            border-radius: 12px;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }
    </style>
</head>
<body>
    <?php include '../include/navmenu.php'; ?>
    
    <div class="main-content">
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1><i class="bi bi-bar-chart"></i> Sales Analytics</h1>
                    <p class="header-subtitle">Comprehensive sales performance and insights</p>
                    </div>
                <div class="header-actions">
                    <a href="../analytics/index.php" class="btn btn-outline-secondary me-3">
                        <i class="bi bi-arrow-left"></i> Back to Analytics
                    </a>
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
                <!-- Key Performance Indicators -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="analytics-card">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Total Revenue</h6>
                                <i class="bi bi-cash-coin fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($analytics['all_time']['total_revenue'], 2); ?></h3>
                            <small class="opacity-75"><?php echo number_format($analytics['all_time']['total_transactions']); ?> transactions</small>
                    </div>
                </div>

                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="analytics-card revenue">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">This Month</h6>
                                <i class="bi bi-graph-up-arrow fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($analytics['this_month']['total_revenue'], 2); ?></h3>
                            <small class="opacity-75"><?php echo number_format($analytics['this_month']['total_transactions']); ?> transactions</small>
                            </div>
                        </div>
                    
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="analytics-card average">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Average Transaction</h6>
                                <i class="bi bi-calculator fs-4"></i>
                    </div>
                            <h3 class="mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($analytics['all_time']['average_transaction'], 2); ?></h3>
                            <small class="opacity-75">Per transaction</small>
                            </div>
                        </div>

                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="analytics-card growth">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Month Growth</h6>
                                <i class="bi bi-trending-up fs-4"></i>
                    </div>
                            <h3 class="mb-0 <?php echo $analytics['growth_rates']['month_over_month'] >= 0 ? 'growth-positive' : 'growth-negative'; ?>">
                                <?php echo $analytics['growth_rates']['month_over_month'] >= 0 ? '+' : ''; ?><?php echo number_format($analytics['growth_rates']['month_over_month'], 1); ?>%
                            </h3>
                            <small class="opacity-75">vs last month</small>
                            </div>
                        </div>
                    </div>

                <!-- Sales Overview Row -->
                <div class="row mb-4">
                    <!-- Sales Trend Chart -->
                    <div class="col-xl-6 col-lg-12 mb-4">
                        <div class="card stat-card">
                            <div class="card-header">
                                <h5 class="card-title mb-0"><i class="bi bi-graph-up"></i> Sales Trend - Last 30 Days</h5>
                            </div>
                            <div class="card-body">
                                <div class="chart-container" style="height: 300px;">
                                    <canvas id="salesTrendChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                    <!-- Weekly Sales Summary -->
                    <div class="col-xl-3 col-lg-6 mb-4">
                        <div class="card stat-card">
                            <div class="card-header">
                                <h5 class="card-title mb-0"><i class="bi bi-calendar-week"></i> This Week's Sales</h5>
                            </div>
                            <div class="card-body text-center">
                                <div class="analytics-card revenue mb-3">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <h6 class="mb-0">Weekly Revenue</h6>
                                        <i class="bi bi-graph-up-arrow fs-4"></i>
                                    </div>
                                    <h3 class="mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($analytics['this_week']['total_revenue'], 2); ?></h3>
                                    <small class="opacity-75"><?php echo number_format($analytics['this_week']['total_transactions']); ?> transactions</small>
                                </div>
                                
                                <div class="row text-center">
                                    <div class="col-6">
                                        <div class="border-end">
                                            <h6 class="text-muted mb-1">Avg per Day</h6>
                                            <h5 class="text-primary"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($analytics['this_week']['total_revenue'] / 7, 2); ?></h5>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <h6 class="text-muted mb-1">Avg Transaction</h6>
                                        <h5 class="text-success"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($analytics['this_week']['average_transaction'], 2); ?></h5>
                            </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Today's Sales Summary -->
                    <div class="col-xl-3 col-lg-6 mb-4">
                        <div class="card stat-card">
                            <div class="card-header">
                                <h5 class="card-title mb-0"><i class="bi bi-calendar-day"></i> Today's Sales</h5>
                            </div>
                            <div class="card-body text-center">
                                <div class="analytics-card mb-3">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <h6 class="mb-0">Today's Revenue</h6>
                                        <i class="bi bi-cash-coin fs-4"></i>
                                    </div>
                                    <h3 class="mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($analytics['today']['total_revenue'], 2); ?></h3>
                                    <small class="opacity-75"><?php echo number_format($analytics['today']['total_transactions']); ?> transactions</small>
                                </div>
                                
                                <div class="row text-center">
                                    <div class="col-6">
                                        <div class="border-end">
                                            <h6 class="text-muted mb-1">Hourly Avg</h6>
                                            <h5 class="text-info"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($analytics['today']['total_revenue'] / max(1, date('H')), 2); ?></h5>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <h6 class="text-muted mb-1">Avg Transaction</h6>
                                        <h5 class="text-warning"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($analytics['today']['average_transaction'], 2); ?></h5>
                            </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Performance Metrics Row -->
                <div class="row mb-4">
                    <!-- Category Performance -->
                    <div class="col-xl-12 col-lg-12 mb-4">
                        <div class="card stat-card">
                            <div class="card-header">
                                <h5 class="card-title mb-0"><i class="bi bi-tags"></i> Category Performance</h5>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($analytics['top_categories'])): ?>
                                <div class="category-list">
                                    <?php foreach ($analytics['top_categories'] as $index => $category): ?>
                                    <div class="category-item d-flex justify-content-between align-items-center mb-3">
                                        <div class="d-flex align-items-center">
                                            <div class="category-rank me-3">
                                                <span class="badge bg-primary rounded-circle"><?php echo $index + 1; ?></span>
                                            </div>
                                            <div>
                                                <h6 class="mb-0"><?php echo htmlspecialchars($category['category_name']); ?></h6>
                                                <small class="text-muted"><?php echo number_format($category['total_quantity']); ?> items sold</small>
                                            </div>
                                        </div>
                                        <div class="text-end">
                                            <h6 class="mb-0 text-success"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($category['total_revenue'], 2); ?></h6>
                                            <small class="text-muted"><?php echo number_format($category['percentage'], 1); ?>% of total</small>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php else: ?>
                                <div class="text-center py-4">
                                    <i class="bi bi-tags fs-1 text-muted"></i>
                                    <p class="text-muted mt-2">No category data available</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Top Products and Payment Methods -->
                <div class="row mb-4">
                    <!-- Top Selling Products -->
                    <div class="col-xl-6 mb-4">
                        <div class="card stat-card">
                            <div class="card-header">
                                <h5 class="card-title mb-0"><i class="bi bi-trophy"></i> Top Selling Products (30 Days)</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Product</th>
                                                <th>SKU</th>
                                                <th>Qty Sold</th>
                                                <th>Revenue</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($analytics['top_products'] as $product): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($product['product_name']); ?></strong>
                                                </td>
                                                <td>
                                                    <span class="badge bg-secondary"><?php echo htmlspecialchars($product['sku']); ?></span>
                                                </td>
                                                <td>
                                                    <span class="fw-bold"><?php echo number_format($product['total_quantity_sold']); ?></span>
                                                </td>
                                                <td>
                                                    <span class="text-success fw-bold"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($product['total_revenue'], 2); ?></span>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Methods -->
                    <div class="col-xl-6 mb-4">
                        <div class="card stat-card">
                            <div class="card-header">
                                <h5 class="card-title mb-0"><i class="bi bi-credit-card"></i> Sales by Payment Method (30 Days)</h5>
                            </div>
                            <div class="card-body">
                                <div class="chart-container" style="height: 300px;">
                                    <canvas id="paymentMethodChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions Section -->
                <div class="row mb-4">
                    <div class="col-12 mb-3">
                        <h5><i class="bi bi-lightning"></i> Quick Actions</h5>
                        <p class="text-muted">Quick access to frequently used features</p>
                    </div>
                </div>

                <div class="row mb-4">
                    <!-- View All Sales -->
                    <div class="col-xl-4 col-md-6 mb-4">
                        <div class="card quick-action-card" onclick="location.href='index.php'" style="cursor: pointer;">
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

                    <!-- View Products -->
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

                    <!-- View Customers -->
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

                    <!-- Export Sales Data -->
                    <div class="col-xl-4 col-md-6 mb-4">
                        <div class="card quick-action-card" onclick="location.href='export_sales.php'" style="cursor: pointer;">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="quick-icon me-3">
                                        <i class="bi bi-download text-warning fs-3"></i>
                                    </div>
                                    <div>
                                        <h6 class="mb-1">Export Sales Data</h6>
                                        <small class="text-muted">Download sales reports</small>
                                    </div>
                                    <div class="ms-auto">
                                        <i class="bi bi-arrow-right text-warning"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- POS Terminal -->
                    <div class="col-xl-4 col-md-6 mb-4">
                        <div class="card quick-action-card" onclick="location.href='../pos/sale.php'" style="cursor: pointer;">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="quick-icon me-3">
                                        <i class="bi bi-cart-plus text-danger fs-3"></i>
                                    </div>
                                    <div>
                                        <h6 class="mb-1">POS Terminal</h6>
                                        <small class="text-muted">Process new sales</small>
                                    </div>
                                    <div class="ms-auto">
                                        <i class="bi bi-arrow-right text-danger"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Financial Reports -->
                    <div class="col-xl-4 col-md-6 mb-4">
                        <div class="card quick-action-card" onclick="location.href='../finance/index.php'" style="cursor: pointer;">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="quick-icon me-3">
                                        <i class="bi bi-calculator text-secondary fs-3"></i>
                                    </div>
                                    <div>
                                        <h6 class="mb-1">Financial Reports</h6>
                                        <small class="text-muted">View financial analytics</small>
                                    </div>
                                    <div class="ms-auto">
                                        <i class="bi bi-arrow-right text-secondary"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Sales -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card stat-card">
                            <div class="card-header">
                                <h5 class="card-title mb-0"><i class="bi bi-clock-history"></i> Recent Sales</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Date & Time</th>
                                                <th>Sale ID</th>
                                                <th>Cashier</th>
                                                <th>Items</th>
                                                <th>Payment Method</th>
                                                <th>Total Amount</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($analytics['recent_sales'] as $sale): ?>
                                            <tr>
                                                <td>
                                                    <small class="text-muted"><?php echo date('M d, Y', strtotime($sale['sale_date'])); ?></small><br>
                                                    <small><?php echo date('H:i:s', strtotime($sale['sale_date'])); ?></small>
                                                </td>
                                                <td>
                                                    <span class="badge bg-primary">#<?php echo htmlspecialchars($sale['sale_number'] ?? $sale['id']); ?></span>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($sale['cashier_name'] ?? 'Unknown'); ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-info"><?php echo $sale['item_count']; ?> items</span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-secondary"><?php echo htmlspecialchars($sale['payment_method'] ?? 'Unknown'); ?></span>
                                                </td>
                                                <td>
                                                    <span class="fw-bold text-success"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($sale['final_amount'], 2); ?></span>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Sales Trend Chart
        const salesTrendCtx = document.getElementById('salesTrendChart').getContext('2d');
        const salesTrendData = <?php echo json_encode($analytics['last_30_days']); ?>;
        
        const salesTrendChart = new Chart(salesTrendCtx, {
            type: 'line',
            data: {
                labels: salesTrendData.map(item => new Date(item.sale_date).toLocaleDateString()).reverse(),
                datasets: [{
                    label: 'Daily Revenue',
                    data: salesTrendData.map(item => parseFloat(item.daily_revenue)).reverse(),
                    borderColor: 'rgb(99, 102, 241)',
                    backgroundColor: 'rgba(99, 102, 241, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '<?php echo $settings['currency_symbol'] ?? 'KES'; ?> ' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });

        // Payment Method Chart
        const paymentMethodCtx = document.getElementById('paymentMethodChart').getContext('2d');
        const paymentMethodData = <?php echo json_encode($analytics['payment_methods']); ?>;
        
        const paymentMethodChart = new Chart(paymentMethodCtx, {
            type: 'doughnut',
            data: {
                labels: paymentMethodData.map(item => item.payment_method),
                datasets: [{
                    data: paymentMethodData.map(item => parseFloat(item.total_amount)),
                    backgroundColor: [
                        '#667eea',
                        '#764ba2',
                        '#11998e',
                        '#38ef7d',
                        '#fc466b',
                        '#3f5efb'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
    </script>
</body>
</html>
