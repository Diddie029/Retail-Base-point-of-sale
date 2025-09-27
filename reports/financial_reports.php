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

// Check if user has permission to view reports
$hasAccess = isAdmin($role_name) || 
             hasPermission('view_analytics', $permissions) || 
             hasPermission('manage_sales', $permissions) || 
             hasPermission('view_finance', $permissions);

if (!$hasAccess) {
    header('Location: ../dashboard/dashboard.php');
    exit();
}

// Get date range parameters
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$report_type = $_GET['report_type'] ?? 'summary';

// Get financial statistics based on date range
$stats = [];

// Total Revenue (Sales)
$stmt = $conn->prepare("
    SELECT
        COUNT(*) as transaction_count,
        COALESCE(SUM(final_amount), 0) as total_revenue,
        COALESCE(SUM(tax_amount), 0) as total_tax,
        COALESCE(SUM(discount), 0) as total_discount
    FROM sales
    WHERE DATE(sale_date) BETWEEN ? AND ?
");
$stmt->execute([$date_from, $date_to]);
$sales_data = $stmt->fetch(PDO::FETCH_ASSOC);
$stats['revenue'] = $sales_data['total_revenue'];
$stats['transaction_count'] = $sales_data['transaction_count'];
$stats['total_tax'] = $sales_data['total_tax'];
$stats['total_discount'] = $sales_data['total_discount'];

// Total Expenses
$stats['expenses'] = 0;
try {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(total_amount), 0) as total_expenses
        FROM expenses
        WHERE DATE(expense_date) BETWEEN ? AND ?
        AND approval_status = 'approved'
    ");
    $stmt->execute([$date_from, $date_to]);
    $stats['expenses'] = $stmt->fetch(PDO::FETCH_ASSOC)['total_expenses'];
} catch (Exception $e) {
    $stats['expenses'] = 0;
}

// Calculate profit
$stats['profit'] = $stats['revenue'] - $stats['expenses'];

// Cash flow data
$stats['cash_inflows'] = $stats['revenue'];
$stats['cash_outflows'] = $stats['expenses'];

// Payment method breakdown
$stmt = $conn->prepare("
    SELECT payment_method, COUNT(*) as count, COALESCE(SUM(final_amount), 0) as amount
    FROM sales
    WHERE DATE(sale_date) BETWEEN ? AND ?
    GROUP BY payment_method
    ORDER BY amount DESC
");
$stmt->execute([$date_from, $date_to]);
$stats['payment_methods'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Daily sales trend for the period
$stmt = $conn->prepare("
    SELECT DATE(sale_date) as date, COUNT(*) as count, COALESCE(SUM(final_amount), 0) as amount
    FROM sales
    WHERE DATE(sale_date) BETWEEN ? AND ?
    GROUP BY DATE(sale_date)
    ORDER BY date
");
$stmt->execute([$date_from, $date_to]);
$stats['daily_sales'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Top selling products
$stmt = $conn->prepare("
    SELECT p.name, SUM(si.quantity) as total_quantity, SUM(si.total_price) as total_revenue
    FROM sale_items si
    JOIN sales s ON si.sale_id = s.id
    JOIN products p ON si.product_id = p.id
    WHERE DATE(s.sale_date) BETWEEN ? AND ?
    GROUP BY p.id, p.name
    ORDER BY total_revenue DESC
    LIMIT 10
");
$stmt->execute([$date_from, $date_to]);
$stats['top_products'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Expense categories
$stats['expense_categories'] = [];
try {
    $stmt = $conn->prepare("
        SELECT ec.name as category_name, COUNT(*) as count, COALESCE(SUM(e.total_amount), 0) as amount
        FROM expenses e
        JOIN expense_categories ec ON e.category_id = ec.id
        WHERE DATE(e.expense_date) BETWEEN ? AND ?
        AND e.approval_status = 'approved'
        GROUP BY ec.id, ec.name
        ORDER BY amount DESC
    ");
    $stmt->execute([$date_from, $date_to]);
    $stats['expense_categories'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $stats['expense_categories'] = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Reports - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
        
        .report-header {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
        }
        
        .finance-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            transition: transform 0.3s ease;
        }

        .finance-card:hover {
            transform: translateY(-5px);
        }

        .finance-card.profit {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
        }

        .finance-card.expense {
            background: linear-gradient(135deg, #fc466b 0%, #3f5efb 100%);
        }

        .finance-card.tax {
            background: linear-gradient(135deg, #fdbb2d 0%, #22c1c3 100%);
        }

        .report-card {
            border: none;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            border-radius: 12px;
        }

        .report-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .report-header {
            border-bottom: 1px solid #e9ecef;
            padding-bottom: 1rem;
            margin-bottom: 1rem;
        }

        .chart-container {
            position: relative;
            height: 300px;
        }

        .filter-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .btn-export {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
        }

        .btn-export:hover {
            background: linear-gradient(135deg, #5a67d8 0%, #6b46c1 100%);
            color: white;
        }

        .table-responsive {
            border-radius: 8px;
            overflow: hidden;
        }

        .table thead th {
            background: var(--primary-color);
            color: white;
            border: none;
        }

        .metric-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
        }

        .metric-card:hover {
            transform: translateY(-2px);
        }
    </style>
</head>
<body>
    <?php include '../include/navmenu.php'; ?>

    <div class="main-content">
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1><i class="bi bi-calculator"></i> Financial Reports</h1>
                    <p class="header-subtitle">Profit & loss, balance sheet, and comprehensive financial analysis</p>
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
                <!-- Report Header -->
                <div class="report-header">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h2><i class="bi bi-calculator"></i> Financial Reports</h2>
                            <p class="mb-0">Comprehensive financial analysis and reporting tools</p>
                        </div>
                    </div>
                </div>

                <!-- Date Filter Section -->
                <div class="filter-section">
                    <div class="row align-items-end">
                        <div class="col-md-3">
                            <label for="date_from" class="form-label fw-semibold">From Date</label>
                            <input type="date" class="form-control" id="date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                        </div>
                        <div class="col-md-3">
                            <label for="date_to" class="form-label fw-semibold">To Date</label>
                            <input type="date" class="form-control" id="date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                        </div>
                        <div class="col-md-3">
                            <label for="report_type" class="form-label fw-semibold">Report Type</label>
                            <select class="form-select" id="report_type" name="report_type">
                                <option value="summary" <?php echo $report_type === 'summary' ? 'selected' : ''; ?>>Financial Summary</option>
                                <option value="profit_loss" <?php echo $report_type === 'profit_loss' ? 'selected' : ''; ?>>Profit & Loss</option>
                                <option value="cash_flow" <?php echo $report_type === 'cash_flow' ? 'selected' : ''; ?>>Cash Flow</option>
                                <option value="sales_analysis" <?php echo $report_type === 'sales_analysis' ? 'selected' : ''; ?>>Sales Analysis</option>
                                <option value="expense_analysis" <?php echo $report_type === 'expense_analysis' ? 'selected' : ''; ?>>Expense Analysis</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <button type="button" class="btn btn-primary me-2" onclick="applyFilters()">
                                <i class="bi bi-search"></i> Apply Filters
                            </button>
                            <button type="button" class="btn btn-export" onclick="exportReport()">
                                <i class="bi bi-download"></i> Export
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Financial Summary Cards -->
                <div class="row mb-4" id="summary-cards">
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="finance-card">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Total Revenue</h6>
                                <i class="bi bi-cash-coin fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($stats['revenue'], 2); ?></h3>
                            <small class="opacity-75"><?php echo $stats['transaction_count']; ?> transactions</small>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="finance-card expense">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Total Expenses</h6>
                                <i class="bi bi-cash-stack fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($stats['expenses'], 2); ?></h3>
                            <small class="opacity-75">Approved expenses</small>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="finance-card profit">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Net Profit</h6>
                                <i class="bi bi-trophy fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($stats['profit'], 2); ?></h3>
                            <small class="opacity-75">Revenue - Expenses</small>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="finance-card tax">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Total Tax</h6>
                                <i class="bi bi-percent fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($stats['total_tax'], 2); ?></h3>
                            <small class="opacity-75">VAT collected</small>
                        </div>
                    </div>
                </div>

                <!-- Report Content Based on Type -->
                <div id="report-content">
                    <?php if ($report_type === 'summary'): ?>
                        <!-- Financial Summary Report -->
                        <div class="report-card">
                            <div class="card-body">
                                <div class="report-header">
                                    <h4><i class="bi bi-graph-up-arrow"></i> Sales Trend & Payment Methods</h4>
                                    <p class="text-muted mb-0">Overview of sales performance and payment method distribution</p>
                                </div>

                                <div class="row">
                                    <div class="col-lg-8 mb-4">
                                        <div class="chart-container">
                                            <canvas id="salesChart"></canvas>
                                        </div>
                                    </div>
                                    <div class="col-lg-4 mb-4">
                                        <h5><i class="bi bi-credit-card"></i> Payment Methods</h5>
                                        <div class="mb-3">
                                            <?php foreach ($stats['payment_methods'] as $method): ?>
                                                <div class="d-flex justify-content-between align-items-center mb-2">
                                                    <span><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $method['payment_method']))); ?></span>
                                                    <span class="fw-semibold"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($method['amount'], 2); ?></span>
                                                </div>
                                                <div class="progress mb-3" style="height: 6px;">
                                                    <div class="progress-bar" role="progressbar" style="width: <?php echo $stats['revenue'] > 0 ? ($method['amount'] / $stats['revenue']) * 100 : 0; ?>%"></div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-lg-6 mb-4">
                                        <h5><i class="bi bi-star"></i> Top Selling Products</h5>
                                        <div class="table-responsive">
                                            <table class="table table-hover">
                                                <thead>
                                                    <tr>
                                                        <th>Product</th>
                                                        <th>Quantity</th>
                                                        <th>Revenue</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                <?php foreach (array_slice($stats['top_products'], 0, 5) as $product): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($product['name']); ?></td>
                                                        <td><?php echo number_format($product['total_quantity']); ?></td>
                                                        <td><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($product['total_revenue'], 2); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                    <div class="col-lg-6 mb-4">
                                        <h5><i class="bi bi-pie-chart"></i> Expense Categories</h5>
                                        <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                                            <table class="table table-hover">
                                                <thead class="table-light" style="position: sticky; top: 0;">
                                                    <tr>
                                                        <th>Category</th>
                                                        <th>Transactions</th>
                                                        <th>Amount</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach (array_slice($stats['expense_categories'], 0, 5) as $category): ?>
                                                        <tr>
                                                            <td><?php echo htmlspecialchars($category['category_name']); ?></td>
                                                            <td><?php echo number_format($category['count']); ?></td>
                                                            <td><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($category['amount'], 2); ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                    <?php elseif ($report_type === 'profit_loss'): ?>
                        <!-- Profit & Loss Statement -->
                        <div class="report-card">
                            <div class="card-body">
                                <div class="report-header">
                                    <h4><i class="bi bi-file-earmark-bar-graph"></i> Profit & Loss Statement</h4>
                                    <p class="text-muted mb-0">For the period: <?php echo date('M d, Y', strtotime($date_from)); ?> - <?php echo date('M d, Y', strtotime($date_to)); ?></p>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <h5 class="text-success mb-3">Revenue</h5>
                                        <div class="metric-card p-3 mb-3">
                                            <div class="d-flex justify-content-between">
                                                <span>Sales Revenue</span>
                                                <span class="fw-semibold"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($stats['revenue'], 2); ?></span>
                                            </div>
                                        </div>
                                        <div class="metric-card p-3 mb-3">
                                            <div class="d-flex justify-content-between">
                                                <span>Less: Discounts</span>
                                                <span class="text-danger">-<?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($stats['total_discount'], 2); ?></span>
                                            </div>
                                        </div>
                                        <div class="metric-card p-3 mb-3 bg-light">
                                            <div class="d-flex justify-content-between fw-semibold">
                                                <span>Net Revenue</span>
                                                <span><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($stats['revenue'] - $stats['total_discount'], 2); ?></span>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <h5 class="text-danger mb-3">Expenses</h5>
                                        <div class="metric-card p-3 mb-3">
                                            <div class="d-flex justify-content-between">
                                                <span>Operating Expenses</span>
                                                <span class="fw-semibold"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($stats['expenses'], 2); ?></span>
                                            </div>
                                        </div>
                                        <div class="metric-card p-3 mb-3 bg-light">
                                            <div class="d-flex justify-content-between fw-semibold">
                                                <span>Total Expenses</span>
                                                <span><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($stats['expenses'], 2); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="row mt-4">
                                    <div class="col-12">
                                        <div class="metric-card p-4 bg-primary text-white">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <h4 class="mb-0">Net Profit/Loss</h4>
                                                <h3 class="mb-0 <?php echo $stats['profit'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                    <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($stats['profit'], 2); ?>
                                                </h3>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                    <?php elseif ($report_type === 'cash_flow'): ?>
                        <!-- Cash Flow Statement -->
                        <div class="report-card">
                            <div class="card-body">
                                <div class="report-header">
                                    <h4><i class="bi bi-currency-exchange"></i> Cash Flow Statement</h4>
                                    <p class="text-muted mb-0">Cash inflows and outflows for the selected period</p>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <h5 class="text-success mb-3"><i class="bi bi-arrow-up-circle"></i> Cash Inflows</h5>
                                        <div class="metric-card p-3 mb-3">
                                            <div class="d-flex justify-content-between">
                                                <span>Sales Revenue</span>
                                                <span class="fw-semibold text-success">+<?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($stats['revenue'], 2); ?></span>
                                            </div>
                                        </div>
                                        <div class="metric-card p-3 bg-success text-white">
                                            <div class="d-flex justify-content-between fw-semibold">
                                                <span>Total Cash Inflows</span>
                                                <span>+<?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($stats['cash_inflows'], 2); ?></span>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <h5 class="text-danger mb-3"><i class="bi bi-arrow-down-circle"></i> Cash Outflows</h5>
                                        <div class="metric-card p-3 mb-3">
                                            <div class="d-flex justify-content-between">
                                                <span>Operating Expenses</span>
                                                <span class="fw-semibold text-danger">-<?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($stats['expenses'], 2); ?></span>
                                            </div>
                                        </div>
                                        <div class="metric-card p-3 bg-danger text-white">
                                            <div class="d-flex justify-content-between fw-semibold">
                                                <span>Total Cash Outflows</span>
                                                <span>-<?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($stats['cash_outflows'], 2); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="row mt-4">
                                    <div class="col-12">
                                        <div class="metric-card p-4 <?php echo ($stats['cash_inflows'] - $stats['cash_outflows']) >= 0 ? 'bg-info' : 'bg-warning'; ?> text-white">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <h4 class="mb-0">
                                                    <i class="bi bi-cash"></i> Net Cash Flow
                                                </h4>
                                                <h3 class="mb-0">
                                                    <?php echo ($stats['cash_inflows'] - $stats['cash_outflows']) >= 0 ? '+' : ''; ?>
                                                    <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($stats['cash_inflows'] - $stats['cash_outflows'], 2); ?>
                                                </h3>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                    <?php elseif ($report_type === 'sales_analysis'): ?>
                        <!-- Sales Analysis -->
                        <div class="row">
                            <div class="col-lg-6 mb-4">
                                <div class="report-card">
                                    <div class="card-body">
                                        <div class="report-header">
                                            <h5><i class="bi bi-bar-chart"></i> Sales by Payment Method</h5>
                                        </div>
                                        <div class="chart-container">
                                            <canvas id="paymentMethodChart"></canvas>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-6 mb-4">
                                <div class="report-card">
                                    <div class="card-body">
                                        <div class="report-header">
                                            <h5><i class="bi bi-calendar-event"></i> Daily Sales Performance</h5>
                                        </div>
                                        <div class="table-responsive">
                                            <table class="table table-hover">
                                                <thead>
                                                    <tr>
                                                        <th>Date</th>
                                                        <th>Transactions</th>
                                                        <th>Revenue</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach (array_slice($stats['daily_sales'], -10) as $day): ?>
                                                        <tr>
                                                            <td><?php echo date('M d, Y', strtotime($day['date'])); ?></td>
                                                            <td><?php echo number_format($day['count']); ?></td>
                                                            <td><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($day['amount'], 2); ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                    <?php elseif ($report_type === 'expense_analysis'): ?>
                        <!-- Expense Analysis -->
                        <div class="row">
                            <div class="col-lg-6 mb-4">
                                <div class="report-card">
                                    <div class="card-body">
                                        <div class="report-header">
                                            <h5><i class="bi bi-pie-chart-fill"></i> Expenses by Category</h5>
                                        </div>
                                        <div class="chart-container">
                                            <canvas id="expenseChart"></canvas>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-6 mb-4">
                                <div class="report-card">
                                    <div class="card-body">
                                        <div class="report-header">
                                            <h5><i class="bi bi-receipt"></i> Expense Breakdown</h5>
                                        </div>
                                        <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                                            <table class="table table-hover">
                                                <thead class="table-light" style="position: sticky; top: 0;">
                                                    <tr>
                                                        <th>Category</th>
                                                        <th>Transactions</th>
                                                        <th>Amount</th>
                                                        <th>% of Total</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach (array_slice($stats['expense_categories'], 0, 5) as $category):
                                                        $percentage = $stats['expenses'] > 0 ? ($category['amount'] / $stats['expenses']) * 100 : 0;
                                                    ?>
                                                        <tr>
                                                            <td><?php echo htmlspecialchars($category['category_name']); ?></td>
                                                            <td><?php echo number_format($category['count']); ?></td>
                                                            <td><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($category['amount'], 2); ?></td>
                                                            <td><?php echo number_format($percentage, 1); ?>%</td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Initialize charts based on report type
        function initializeCharts() {
            // Sales Chart (for summary and sales analysis)
            if (document.getElementById('salesChart')) {
                const salesData = <?php echo json_encode($stats['daily_sales']); ?>;
                const salesLabels = salesData.map(item => {
                    const date = new Date(item.date);
                    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                });
                const salesValues = salesData.map(item => parseFloat(item.amount));

                const salesCtx = document.getElementById('salesChart').getContext('2d');
                new Chart(salesCtx, {
                    type: 'line',
                    data: {
                        labels: salesLabels,
                        datasets: [{
                            label: 'Daily Sales',
                            data: salesValues,
                            borderColor: '#6366f1',
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
                                display: false
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function(value) {
                                        return '<?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> ' + value.toLocaleString();
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // Payment Method Chart (for sales analysis)
            if (document.getElementById('paymentMethodChart')) {
                const paymentData = <?php echo json_encode($stats['payment_methods']); ?>;
                const paymentLabels = paymentData.map(item => ucfirst(item.payment_method.replace(/_/g, ' ')));
                const paymentValues = paymentData.map(item => parseFloat(item.amount));

                const paymentCtx = document.getElementById('paymentMethodChart').getContext('2d');
                new Chart(paymentCtx, {
                    type: 'bar',
                    data: {
                        labels: paymentLabels,
                        datasets: [{
                            label: 'Revenue by Payment Method',
                            data: paymentValues,
                            backgroundColor: [
                                'rgba(255, 99, 132, 0.8)',
                                'rgba(54, 162, 235, 0.8)',
                                'rgba(255, 205, 86, 0.8)',
                                'rgba(75, 192, 192, 0.8)',
                                'rgba(153, 102, 255, 0.8)'
                            ],
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
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function(value) {
                                        return '<?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> ' + value.toLocaleString();
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // Expense Chart (for expense analysis)
            if (document.getElementById('expenseChart')) {
                const expenseData = <?php echo json_encode($stats['expense_categories']); ?>;
                const expenseLabels = expenseData.map(item => item.category_name);
                const expenseValues = expenseData.map(item => parseFloat(item.amount));

                const expenseCtx = document.getElementById('expenseChart').getContext('2d');
                new Chart(expenseCtx, {
                    type: 'doughnut',
                    data: {
                        labels: expenseLabels,
                        datasets: [{
                            data: expenseValues,
                            backgroundColor: [
                                'rgba(255, 99, 132, 0.8)',
                                'rgba(54, 162, 235, 0.8)',
                                'rgba(255, 205, 86, 0.8)',
                                'rgba(75, 192, 192, 0.8)',
                                'rgba(153, 102, 255, 0.8)',
                                'rgba(255, 159, 64, 0.8)'
                            ],
                            borderWidth: 1
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
            }
        }

        // Initialize charts on page load
        document.addEventListener('DOMContentLoaded', function() {
            initializeCharts();
        });

        // Apply Filters Function
        function applyFilters() {
            const dateFrom = document.getElementById('date_from').value;
            const dateTo = document.getElementById('date_to').value;
            const reportType = document.getElementById('report_type').value;

            const params = new URLSearchParams(window.location.search);
            params.set('date_from', dateFrom);
            params.set('date_to', dateTo);
            params.set('report_type', reportType);

            window.location.href = window.location.pathname + '?' + params.toString();
        }

        // Export Report Function
        function exportReport() {
            const dateFrom = document.getElementById('date_from').value;
            const dateTo = document.getElementById('date_to').value;
            const reportType = document.getElementById('report_type').value;

            // Create a simple CSV export
            let csvContent = "data:text/csv;charset=utf-8,";

            // Add header
            csvContent += "Financial Report\n";
            csvContent += `Period: ${dateFrom} to ${dateTo}\n`;
            csvContent += `Report Type: ${reportType}\n\n`;

            // Add summary data
            csvContent += "Summary\n";
            csvContent += `Total Revenue,<?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($stats['revenue'], 2); ?>\n`;
            csvContent += `Total Expenses,<?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($stats['expenses'], 2); ?>\n`;
            csvContent += `Net Profit,<?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($stats['profit'], 2); ?>\n`;
            csvContent += `Total Tax,<?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($stats['total_tax'], 2); ?>\n\n`;

            // Add payment methods
            csvContent += "Payment Methods\n";
            csvContent += "Method,Transactions,Amount\n";
            <?php foreach ($stats['payment_methods'] as $method): ?>
                csvContent += "<?php echo addslashes(ucfirst(str_replace('_', ' ', $method['payment_method']))); ?>,<?php echo $method['count']; ?>,<?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($method['amount'], 2); ?>\n";
            <?php endforeach; ?>

            csvContent += "\nTop Products\n";
            csvContent += "Product,Quantity,Revenue\n";
            <?php foreach ($stats['top_products'] as $product): ?>
                csvContent += "<?php echo addslashes($product['name']); ?>,<?php echo $product['total_quantity']; ?>,<?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($product['total_revenue'], 2); ?>\n";
            <?php endforeach; ?>

            const encodedUri = encodeURI(csvContent);
            const link = document.createElement("a");
            link.setAttribute("href", encodedUri);
            link.setAttribute("download", `financial_report_${dateFrom}_${dateTo}.csv`);
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        // Auto-submit form when report type changes
        document.getElementById('report_type').addEventListener('change', function() {
            applyFilters();
        });
    </script>
</body>
</html>
