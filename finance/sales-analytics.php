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

// Check if user has permission to view financial reports
if (!hasPermission('view_financial_reports', $permissions)) {
    header('Location: ../dashboard/dashboard.php?error=access_denied');
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get selected period
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');
$period_type = $_GET['period'] ?? 'monthly';
$analysis_type = $_GET['analysis'] ?? 'overview';

// Auto-set dates based on period
if (isset($_GET['period']) && !isset($_GET['start_date'])) {
    switch ($period_type) {
        case 'weekly':
            $start_date = date('Y-m-d', strtotime('monday this week'));
            $end_date = date('Y-m-d', strtotime('sunday this week'));
            break;
        case 'monthly':
            $start_date = date('Y-m-01');
            $end_date = date('Y-m-t');
            break;
        case 'quarterly':
            $quarter_start = date('Y-m-01', strtotime('first day of -2 month'));
            $start_date = $quarter_start;
            $end_date = date('Y-m-t');
            break;
        case 'yearly':
            $start_date = date('Y-01-01');
            $end_date = date('Y-12-31');
            break;
    }
}

// Initialize analytics data
$analytics = [
    'summary' => [],
    'trends' => [],
    'products' => [],
    'categories' => [],
    'payment_methods' => [],
    'daily_sales' => [],
    'hourly_sales' => []
];

// Sales Summary - using existing columns
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_transactions,
        COALESCE(SUM(final_amount), 0) as total_revenue,
        COALESCE(AVG(final_amount), 0) as avg_transaction,
        COALESCE(SUM(discount), 0) as total_discounts,
        COALESCE(SUM(tax_amount), 0) as total_taxes
    FROM sales 
    WHERE sale_date BETWEEN ? AND ?
");
$stmt->execute([$start_date, $end_date]);
$analytics['summary'] = $stmt->fetch(PDO::FETCH_ASSOC);

// Calculate COGS and Gross Profit
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(si.quantity * p.cost_price), 0) as total_cogs
    FROM sale_items si
    JOIN sales s ON si.sale_id = s.id
    JOIN products p ON si.product_id = p.id
    WHERE s.sale_date BETWEEN ? AND ?
");
$stmt->execute([$start_date, $end_date]);
$analytics['summary']['total_cogs'] = $stmt->fetch(PDO::FETCH_ASSOC)['total_cogs'];
$analytics['summary']['gross_profit'] = $analytics['summary']['total_revenue'] - $analytics['summary']['total_cogs'];
$analytics['summary']['gross_margin'] = $analytics['summary']['total_revenue'] > 0 ? 
    ($analytics['summary']['gross_profit'] / $analytics['summary']['total_revenue']) * 100 : 0;

// Previous period comparison
$prev_start = date('Y-m-d', strtotime($start_date . ' -1 month'));
$prev_end = date('Y-m-d', strtotime($end_date . ' -1 month'));

$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as prev_transactions,
        COALESCE(SUM(final_amount), 0) as prev_revenue
    FROM sales 
    WHERE sale_date BETWEEN ? AND ?
");
$stmt->execute([$prev_start, $prev_end]);
$prev_data = $stmt->fetch(PDO::FETCH_ASSOC);

$analytics['summary']['revenue_growth'] = $prev_data['prev_revenue'] > 0 ? 
    (($analytics['summary']['total_revenue'] - $prev_data['prev_revenue']) / $prev_data['prev_revenue']) * 100 : 0;

$analytics['summary']['transaction_growth'] = $prev_data['prev_transactions'] > 0 ? 
    (($analytics['summary']['total_transactions'] - $prev_data['prev_transactions']) / $prev_data['prev_transactions']) * 100 : 0;

// Top Products Analysis
try {
    $stmt = $conn->prepare("
        SELECT 
            p.name,
            p.price as selling_price,
            p.cost_price,
            SUM(si.quantity) as total_sold,
            SUM(si.quantity * si.price) as total_revenue,
            SUM(si.quantity * p.cost_price) as total_cost,
            AVG(si.price) as avg_selling_price,
            COUNT(DISTINCT s.id) as total_sales
        FROM products p
        JOIN sale_items si ON p.id = si.product_id
        JOIN sales s ON si.sale_id = s.id
        WHERE s.sale_date BETWEEN ? AND ?
        GROUP BY p.id, p.name, p.price, p.cost_price
        ORDER BY total_revenue DESC
        LIMIT 10
    ");
    $stmt->execute([$start_date, $end_date]);
    $analytics['products'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $analytics['products'] = [];
}

// Category Analysis
try {
    $stmt = $conn->prepare("
        SELECT 
            c.name as category_name,
            COUNT(DISTINCT p.id) as products_count,
            SUM(si.quantity) as total_sold,
            SUM(si.quantity * si.price) as total_revenue,
            AVG(si.price) as avg_price,
            COUNT(DISTINCT s.id) as total_sales
        FROM categories c
        LEFT JOIN products p ON c.id = p.category_id
        LEFT JOIN sale_items si ON p.id = si.product_id
        LEFT JOIN sales s ON si.sale_id = s.id
        WHERE s.sale_date BETWEEN ? AND ?
        GROUP BY c.id, c.name
        HAVING total_revenue > 0
        ORDER BY total_revenue DESC
        LIMIT 10
    ");
    $stmt->execute([$start_date, $end_date]);
    $analytics['categories'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $analytics['categories'] = [];
}

// Payment Methods Analysis
$stmt = $conn->prepare("
    SELECT 
        payment_method,
        COUNT(*) as transaction_count,
        SUM(final_amount) as total_amount,
        AVG(final_amount) as avg_amount
    FROM sales 
    WHERE sale_date BETWEEN ? AND ?
    GROUP BY payment_method
    ORDER BY total_amount DESC
");
$stmt->execute([$start_date, $end_date]);
$analytics['payment_methods'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Daily Sales Trend
$stmt = $conn->prepare("
    SELECT 
        DATE(sale_date) as sale_day,
        COUNT(*) as daily_transactions,
        SUM(final_amount) as daily_revenue
    FROM sales 
    WHERE sale_date BETWEEN ? AND ?
    GROUP BY DATE(sale_date)
    ORDER BY sale_day ASC
");
$stmt->execute([$start_date, $end_date]);
$analytics['daily_sales'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Hourly Sales Pattern
$stmt = $conn->prepare("
    SELECT 
        HOUR(sale_date) as sale_hour,
        COUNT(*) as hourly_transactions,
        SUM(final_amount) as hourly_revenue,
        AVG(final_amount) as avg_transaction_value
    FROM sales 
    WHERE sale_date BETWEEN ? AND ?
    GROUP BY HOUR(sale_date)
    ORDER BY sale_hour ASC
");
$stmt->execute([$start_date, $end_date]);
$analytics['hourly_sales'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Customer Analysis (if customer data exists)
try {
    $stmt = $conn->prepare("
        SELECT 
            customer_name,
            COUNT(*) as purchase_count,
            SUM(final_amount) as total_spent,
            AVG(final_amount) as avg_purchase
        FROM sales 
        WHERE sale_date BETWEEN ? AND ? 
        AND customer_name IS NOT NULL 
        AND customer_name != ''
        GROUP BY customer_name
        ORDER BY total_spent DESC
        LIMIT 10
    ");
    $stmt->execute([$start_date, $end_date]);
    $top_customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $top_customers = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Analytics - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
        .analytics-card {
            transition: all 0.3s ease;
        }
        .analytics-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        .metric-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
        }
        .metric-card.revenue {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
        }
        .metric-card.transactions {
            background: linear-gradient(135deg, #fc466b 0%, #3f5efb 100%);
        }
        .metric-card.profit {
            background: linear-gradient(135deg, #fdbb2d 0%, #22c1c3 100%);
        }
        .growth-positive { color: #28a745; }
        .growth-negative { color: #dc3545; }
        .chart-container { height: 400px; }
        .small-chart { height: 200px; }
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
                            <li class="breadcrumb-item"><a href="reports.php">Financial Reports</a></li>
                            <li class="breadcrumb-item active">Sales Analytics</li>
                        </ol>
                    </nav>
                    <h1><i class="bi bi-graph-down"></i> Sales Analytics</h1>
                    <p class="header-subtitle">
                        Detailed sales analysis for <?php echo date('M d, Y', strtotime($start_date)); ?> to <?php echo date('M d, Y', strtotime($end_date)); ?>
                    </p>
                </div>
                <div class="header-actions">
                    <button class="btn btn-primary me-2" onclick="window.print()">
                        <i class="bi bi-printer me-1"></i> Print
                    </button>
                    <button class="btn btn-outline-success" onclick="exportToCSV()">
                        <i class="bi bi-download me-1"></i> Export
                    </button>
                </div>
            </div>
        </header>

        <main class="content">
            <div class="container-fluid">
                <!-- Filters -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <form method="GET" class="row g-3 align-items-end">
                                    <div class="col-md-2">
                                        <label for="period" class="form-label">Period</label>
                                        <select class="form-select" id="period" name="period">
                                            <option value="weekly" <?php echo $period_type == 'weekly' ? 'selected' : ''; ?>>This Week</option>
                                            <option value="monthly" <?php echo $period_type == 'monthly' ? 'selected' : ''; ?>>This Month</option>
                                            <option value="quarterly" <?php echo $period_type == 'quarterly' ? 'selected' : ''; ?>>This Quarter</option>
                                            <option value="yearly" <?php echo $period_type == 'yearly' ? 'selected' : ''; ?>>This Year</option>
                                            <option value="custom" <?php echo $period_type == 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label for="start_date" class="form-label">Start Date</label>
                                        <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo $start_date; ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label for="end_date" class="form-label">End Date</label>
                                        <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo $end_date; ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label for="analysis" class="form-label">Analysis Type</label>
                                        <select class="form-select" id="analysis" name="analysis">
                                            <option value="overview" <?php echo $analysis_type == 'overview' ? 'selected' : ''; ?>>Overview</option>
                                            <option value="products" <?php echo $analysis_type == 'products' ? 'selected' : ''; ?>>Products</option>
                                            <option value="categories" <?php echo $analysis_type == 'categories' ? 'selected' : ''; ?>>Categories</option>
                                            <option value="trends" <?php echo $analysis_type == 'trends' ? 'selected' : ''; ?>>Trends</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <button type="submit" class="btn btn-primary w-100">
                                            <i class="bi bi-search"></i> Update
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Summary Cards -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="card metric-card revenue">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="mb-1">Total Revenue</h6>
                                        <h3 class="mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($analytics['summary']['total_revenue'], 2); ?></h3>
                                        <small class="opacity-75 <?php echo $analytics['summary']['revenue_growth'] >= 0 ? 'growth-positive' : 'growth-negative'; ?>">
                                            <i class="bi bi-arrow-<?php echo $analytics['summary']['revenue_growth'] >= 0 ? 'up' : 'down'; ?>"></i>
                                            <?php echo number_format(abs($analytics['summary']['revenue_growth']), 1); ?>% vs previous period
                                        </small>
                                    </div>
                                    <div>
                                        <i class="bi bi-currency-dollar fs-1"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="card metric-card transactions">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="mb-1">Total Transactions</h6>
                                        <h3 class="mb-0"><?php echo number_format($analytics['summary']['total_transactions']); ?></h3>
                                        <small class="opacity-75 <?php echo $analytics['summary']['transaction_growth'] >= 0 ? 'growth-positive' : 'growth-negative'; ?>">
                                            <i class="bi bi-arrow-<?php echo $analytics['summary']['transaction_growth'] >= 0 ? 'up' : 'down'; ?>"></i>
                                            <?php echo number_format(abs($analytics['summary']['transaction_growth']), 1); ?>% vs previous period
                                        </small>
                                    </div>
                                    <div>
                                        <i class="bi bi-receipt fs-1"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="card metric-card profit">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="mb-1">Gross Profit</h6>
                                        <h3 class="mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($analytics['summary']['gross_profit'], 2); ?></h3>
                                        <small class="opacity-75">
                                            Margin: <?php echo number_format($analytics['summary']['gross_margin'], 1); ?>%
                                        </small>
                                    </div>
                                    <div>
                                        <i class="bi bi-graph-up fs-1"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="card metric-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="mb-1">Avg Transaction</h6>
                                        <h3 class="mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($analytics['summary']['avg_transaction'], 2); ?></h3>
                                        <small class="opacity-75">
                                            <?php echo $analytics['summary']['total_transactions']; ?> transactions
                                        </small>
                                    </div>
                                    <div>
                                        <i class="bi bi-calculator fs-1"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Charts Row -->
                <div class="row mb-4">
                    <!-- Daily Sales Trend -->
                    <div class="col-lg-8 mb-4">
                        <div class="card analytics-card">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="bi bi-graph-up me-2"></i>Daily Sales Trend</h6>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="dailySalesChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Hourly Pattern -->
                    <div class="col-lg-4 mb-4">
                        <div class="card analytics-card">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="bi bi-clock me-2"></i>Hourly Sales Pattern</h6>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="hourlySalesChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Analysis Tables Row -->
                <div class="row mb-4">
                    <!-- Top Products -->
                    <div class="col-lg-8 mb-4">
                        <div class="card analytics-card">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="bi bi-star me-2"></i>Top Performing Products</h6>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead class="table-light">
                                            <tr>
                                                <th width="5%">#</th>
                                                <th>Product Name</th>
                                                <th class="text-end">Qty Sold</th>
                                                <th class="text-end">Revenue</th>
                                                <th class="text-end">Avg Price</th>
                                                <th class="text-end">Margin</th>
                                                <th class="text-end">Sales</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($analytics['products'])): ?>
                                                <?php foreach (array_slice($analytics['products'], 0, 10) as $index => $product): ?>
                                                <tr>
                                                    <td class="text-center">
                                                        <span class="badge bg-primary"><?php echo $index + 1; ?></span>
                                                    </td>
                                                    <td>
                                                        <div class="d-flex align-items-center">
                                                            <i class="bi bi-box me-2 text-primary"></i>
                                                            <strong><?php echo htmlspecialchars($product['name']); ?></strong>
                                                        </div>
                                                    </td>
                                                    <td class="text-end">
                                                        <span class="badge bg-info fs-6"><?php echo number_format($product['total_sold']); ?></span>
                                                    </td>
                                                    <td class="text-end text-success fw-bold">
                                                        <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($product['total_revenue'], 2); ?>
                                                    </td>
                                                    <td class="text-end">
                                                        <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($product['avg_selling_price'], 2); ?>
                                                    </td>
                                                    <td class="text-end">
                                                        <?php 
                                                        $margin = $product['total_revenue'] > 0 ? 
                                                            (($product['total_revenue'] - $product['total_cost']) / $product['total_revenue']) * 100 : 0;
                                                        ?>
                                                        <span class="badge bg-<?php echo $margin > 20 ? 'success' : ($margin > 10 ? 'warning' : 'danger'); ?> fs-6">
                                                            <?php echo number_format($margin, 1); ?>%
                                                        </span>
                                                    </td>
                                                    <td class="text-end">
                                                        <span class="badge bg-secondary"><?php echo number_format($product['total_sales'] ?? 0); ?></span>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="7" class="text-center text-muted py-4">
                                                        <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                                        No product sales data available for the selected period.
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Payment Methods & Categories -->
                    <div class="col-lg-4 mb-4">
                        <div class="card analytics-card mb-3">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="bi bi-credit-card me-2"></i>Payment Methods</h6>
                            </div>
                            <div class="card-body">
                                <?php foreach ($analytics['payment_methods'] as $method): ?>
                                        <?php
                                            // Ensure payment_method is a string and map loyalty-like methods to a friendly label
                                            $rawMethod = $method['payment_method'] ?? '';
                                            $pm = is_null($rawMethod) ? '' : (string)$rawMethod;
                                            $pmLower = strtolower(trim($pm));

                                            $loyaltyKeys = [
                                                'loyalty', 'loyalty_points', 'loyalty points', 'points', 'points_payment', 'loyalty_point'
                                            ];

                                            if (in_array($pmLower, $loyaltyKeys, true)) {
                                                $displayMethod = 'Loyalty Points';
                                            } elseif ($pmLower === '') {
                                                // Payment method empty — treat as loyalty point redemption
                                                $displayMethod = 'Loyalty Point';
                                            } else {
                                                // Make a readable label from the raw method
                                                $displayMethod = ucwords(str_replace(['_', '-'], ' ', $pmLower));
                                            }
                                        ?>
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <span class="text-capitalize"><?php echo htmlspecialchars($displayMethod, ENT_QUOTES, 'UTF-8'); ?></span>
                                            <div class="text-end">
                                                <div class="fw-bold"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES', ENT_QUOTES, 'UTF-8'); ?> <?php echo number_format($method['total_amount'], 0); ?></div>
                                                <small class="text-muted"><?php echo (int)$method['transaction_count']; ?> transactions</small>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <?php if (!empty($top_customers)): ?>
                        <div class="card analytics-card">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="bi bi-people me-2"></i>Top Customers</h6>
                            </div>
                            <div class="card-body">
                                <?php foreach (array_slice($top_customers, 0, 5) as $customer): ?>
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span><?php echo htmlspecialchars($customer['customer_name']); ?></span>
                                    <div class="text-end">
                                        <div class="fw-bold"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($customer['total_spent'], 0); ?></div>
                                        <small class="text-muted"><?php echo $customer['purchase_count']; ?> purchases</small>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Category Analysis -->
                <div class="row">
                    <div class="col-12">
                        <div class="card analytics-card">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="bi bi-tags me-2"></i>Category Performance</h6>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead class="table-light">
                                            <tr>
                                                <th width="5%">#</th>
                                                <th>Category Name</th>
                                                <th class="text-end">Products</th>
                                                <th class="text-end">Units Sold</th>
                                                <th class="text-end">Revenue</th>
                                                <th class="text-end">Avg Price</th>
                                                <th class="text-end">Sales</th>
                                                <th class="text-end">Performance</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($analytics['categories'])): ?>
                                                <?php foreach (array_slice($analytics['categories'], 0, 10) as $index => $category): ?>
                                                <tr>
                                                    <td class="text-center">
                                                        <span class="badge bg-success"><?php echo $index + 1; ?></span>
                                                    </td>
                                                    <td>
                                                        <div class="d-flex align-items-center">
                                                            <i class="bi bi-tag me-2 text-success"></i>
                                                            <strong><?php echo htmlspecialchars($category['category_name']); ?></strong>
                                                        </div>
                                                    </td>
                                                    <td class="text-end">
                                                        <span class="badge bg-info"><?php echo $category['products_count']; ?></span>
                                                    </td>
                                                    <td class="text-end">
                                                        <span class="badge bg-primary fs-6"><?php echo number_format($category['total_sold']); ?></span>
                                                    </td>
                                                    <td class="text-end text-success fw-bold">
                                                        <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($category['total_revenue'], 2); ?>
                                                    </td>
                                                    <td class="text-end">
                                                        <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($category['avg_price'], 2); ?>
                                                    </td>
                                                    <td class="text-end">
                                                        <span class="badge bg-secondary"><?php echo number_format($category['total_sales'] ?? 0); ?></span>
                                                    </td>
                                                    <td class="text-end">
                                                        <?php 
                                                        $performance_percentage = $analytics['summary']['total_revenue'] > 0 ? 
                                                            ($category['total_revenue'] / $analytics['summary']['total_revenue']) * 100 : 0;
                                                        ?>
                                                        <div class="d-flex align-items-center justify-content-end">
                                                            <div class="progress me-2" style="width: 60px; height: 8px;">
                                                                <div class="progress-bar bg-<?php echo $performance_percentage > 20 ? 'success' : ($performance_percentage > 10 ? 'warning' : 'danger'); ?>" 
                                                                     style="width: <?php echo min($performance_percentage, 100); ?>%"></div>
                                                            </div>
                                                            <small class="fw-bold"><?php echo number_format($performance_percentage, 1); ?>%</small>
                                                        </div>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="8" class="text-center text-muted py-4">
                                                        <i class="bi bi-tags fs-1 d-block mb-2"></i>
                                                        No category sales data available for the selected period.
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
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
        // Daily Sales Chart
        const dailySalesCtx = document.getElementById('dailySalesChart').getContext('2d');
        const dailySalesChart = new Chart(dailySalesCtx, {
            type: 'line',
            data: {
                labels: [<?php foreach ($analytics['daily_sales'] as $day): ?>'<?php echo date('M d', strtotime($day['sale_day'])); ?>',<?php endforeach; ?>],
                datasets: [{
                    label: 'Daily Revenue',
                    data: [<?php foreach ($analytics['daily_sales'] as $day): ?><?php echo $day['daily_revenue']; ?>,<?php endforeach; ?>],
                    borderColor: 'rgb(75, 192, 192)',
                    backgroundColor: 'rgba(75, 192, 192, 0.2)',
                    tension: 0.1
                }, {
                    label: 'Daily Transactions',
                    data: [<?php foreach ($analytics['daily_sales'] as $day): ?><?php echo $day['daily_transactions']; ?>,<?php endforeach; ?>],
                    borderColor: 'rgb(255, 99, 132)',
                    backgroundColor: 'rgba(255, 99, 132, 0.2)',
                    tension: 0.1,
                    yAxisID: 'y1'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        grid: {
                            drawOnChartArea: false,
                        },
                    }
                }
            }
        });

        // Hourly Sales Chart
        const hourlySalesCtx = document.getElementById('hourlySalesChart').getContext('2d');
        const hourlySalesChart = new Chart(hourlySalesCtx, {
            type: 'bar',
            data: {
                labels: [<?php foreach ($analytics['hourly_sales'] as $hour): ?>'<?php echo sprintf('%02d:00', $hour['sale_hour']); ?>',<?php endforeach; ?>],
                datasets: [{
                    label: 'Hourly Revenue',
                    data: [<?php foreach ($analytics['hourly_sales'] as $hour): ?><?php echo $hour['hourly_revenue']; ?>,<?php endforeach; ?>],
                    backgroundColor: 'rgba(54, 162, 235, 0.6)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });

        function exportToCSV() {
            let csv = 'Sales Analytics Report\n';
            csv += 'Period: <?php echo $start_date; ?> to <?php echo $end_date; ?>\n\n';
            
            csv += 'SUMMARY\n';
            csv += 'Total Revenue,<?php echo $analytics['summary']['total_revenue']; ?>\n';
            csv += 'Total Transactions,<?php echo $analytics['summary']['total_transactions']; ?>\n';
            csv += 'Gross Profit,<?php echo $analytics['summary']['gross_profit']; ?>\n';
            csv += 'Gross Margin,<?php echo $analytics['summary']['gross_margin']; ?>%\n\n';
            
            csv += 'TOP PRODUCTS\n';
            csv += 'Product,Quantity Sold,Revenue,Average Price\n';
            <?php foreach (array_slice($analytics['products'], 0, 10) as $product): ?>
            csv += '<?php echo addslashes($product['name']); ?>,<?php echo $product['total_sold']; ?>,<?php echo $product['total_revenue']; ?>,<?php echo $product['avg_selling_price']; ?>\n';
            <?php endforeach; ?>
            
            csv += '\nCATEGORY PERFORMANCE\n';
            csv += 'Category,Products,Units Sold,Revenue\n';
            <?php foreach ($analytics['categories'] as $category): ?>
            csv += '<?php echo addslashes($category['category_name']); ?>,<?php echo $category['products_count']; ?>,<?php echo $category['total_sold']; ?>,<?php echo $category['total_revenue']; ?>\n';
            <?php endforeach; ?>
            
            // Download
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.setAttribute('hidden', '');
            a.setAttribute('href', url);
            a.setAttribute('download', 'sales-analytics-<?php echo $start_date; ?>-to-<?php echo $end_date; ?>.csv');
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        }
    </script>
</body>
</html>
