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

// Get financial summary for dashboard
$currentMonth = date('Y-m');
$currentYear = date('Y');
$lastMonth = date('Y-m', strtotime('-1 month'));
$lastYear = date('Y', strtotime('-1 year'));

// Current month sales
$stmt = $conn->prepare("SELECT COUNT(*) as count, COALESCE(SUM(final_amount), 0) as total FROM sales WHERE DATE_FORMAT(sale_date, '%Y-%m') = ?");
$stmt->execute([$currentMonth]);
$currentMonthSales = $stmt->fetch(PDO::FETCH_ASSOC);

// Last month sales for comparison
$stmt = $conn->prepare("SELECT COUNT(*) as count, COALESCE(SUM(final_amount), 0) as total FROM sales WHERE DATE_FORMAT(sale_date, '%Y-%m') = ?");
$stmt->execute([$lastMonth]);
$lastMonthSales = $stmt->fetch(PDO::FETCH_ASSOC);

// Current month expenses
$currentMonthExpenses = 0;
try {
    $stmt = $conn->prepare("SELECT COALESCE(SUM(total_amount), 0) as total FROM expenses WHERE DATE_FORMAT(expense_date, '%Y-%m') = ? AND approval_status = 'approved'");
    $stmt->execute([$currentMonth]);
    $currentMonthExpenses = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
} catch (Exception $e) {
    $currentMonthExpenses = 0;
}

// Calculate growth percentages
$salesGrowth = 0;
if ($lastMonthSales['total'] > 0) {
    $salesGrowth = (($currentMonthSales['total'] - $lastMonthSales['total']) / $lastMonthSales['total']) * 100;
}

// Get top selling products this month
$topProducts = [];
try {
    $stmt = $conn->prepare("
        SELECT p.name, SUM(si.quantity) as total_sold, SUM(si.quantity * si.price) as total_revenue
        FROM products p
        JOIN sale_items si ON p.id = si.product_id
        JOIN sales s ON si.sale_id = s.id
        WHERE DATE_FORMAT(s.sale_date, '%Y-%m') = ?
        GROUP BY p.id, p.name
        ORDER BY total_sold DESC
        LIMIT 5
    ");
    $stmt->execute([$currentMonth]);
    $topProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $topProducts = [];
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
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
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
                            <li class="breadcrumb-item active">Financial Reports</li>
                        </ol>
                    </nav>
                    <h1><i class="bi bi-file-earmark-bar-graph"></i> Financial Reports</h1>
                    <p class="header-subtitle">Comprehensive financial statements and analysis</p>
                </div>
            </div>
        </header>

        <main class="content">
            <div class="container-fluid">
                <!-- Financial Overview Cards -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="card bg-primary text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="card-title mb-1">Current Month Sales</h6>
                                        <h3 class="mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($currentMonthSales['total'], 2); ?></h3>
                                        <small><?php echo $currentMonthSales['count']; ?> transactions</small>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="bi bi-graph-up fs-1"></i>
                                    </div>
                                </div>
                                <div class="mt-2">
                                    <span class="badge bg-light text-primary">
                                        <?php echo $salesGrowth >= 0 ? '+' : ''; ?><?php echo number_format($salesGrowth, 1); ?>% vs last month
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="card bg-success text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="card-title mb-1">Monthly Profit</h6>
                                        <h3 class="mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($currentMonthSales['total'] - $currentMonthExpenses, 2); ?></h3>
                                        <small>Revenue - Expenses</small>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="bi bi-trophy fs-1"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="card bg-danger text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="card-title mb-1">Monthly Expenses</h6>
                                        <h3 class="mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($currentMonthExpenses, 2); ?></h3>
                                        <small>Approved expenses</small>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="bi bi-cash-stack fs-1"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="card bg-info text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="card-title mb-1">Profit Margin</h6>
                                        <h3 class="mb-0"><?php echo $currentMonthSales['total'] > 0 ? number_format((($currentMonthSales['total'] - $currentMonthExpenses) / $currentMonthSales['total']) * 100, 1) : '0'; ?>%</h3>
                                        <small>Net margin</small>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="bi bi-percent fs-1"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Report Categories -->
                <div class="row mb-4">
                    <div class="col-12">
                        <h4><i class="bi bi-folder"></i> Report Categories</h4>
                        <p class="text-muted">Select a report type to view detailed financial information</p>
                    </div>
                </div>

                <!-- Financial Reports Grid -->
                <div class="row mb-4">
                    <!-- Profit & Loss Statement -->
                    <div class="col-xl-4 col-lg-6 mb-4">
                        <div class="card h-100 shadow-sm" style="cursor: pointer;" onclick="location.href='profit-loss.php'">
                            <div class="card-header bg-success text-white">
                                <h5 class="mb-0"><i class="bi bi-graph-up me-2"></i>Profit & Loss Statement</h5>
                            </div>
                            <div class="card-body">
                                <p class="card-text">Comprehensive income statement showing revenues, expenses, and net profit over specific periods.</p>
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">Monthly & Annual Views</small>
                                    <span class="badge bg-success">Available</span>
                                </div>
                            </div>
                            <div class="card-footer bg-light">
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">View P&L Reports</small>
                                    <i class="bi bi-arrow-right text-success"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Balance Sheet -->
                    <div class="col-xl-4 col-lg-6 mb-4">
                        <div class="card h-100 shadow-sm" style="cursor: pointer;" onclick="location.href='balance-sheet.php'">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0"><i class="bi bi-bar-chart me-2"></i>Balance Sheet</h5>
                            </div>
                            <div class="card-body">
                                <p class="card-text">Financial position statement showing assets, liabilities, and owner's equity at a specific point in time.</p>
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">Assets vs Liabilities</small>
                                    <span class="badge bg-primary">Available</span>
                                </div>
                            </div>
                            <div class="card-footer bg-light">
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">View Balance Sheet</small>
                                    <i class="bi bi-arrow-right text-primary"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Cash Flow Statement -->
                    <div class="col-xl-4 col-lg-6 mb-4">
                        <div class="card h-100 shadow-sm" style="cursor: pointer;" onclick="location.href='cash-flow.php'">
                            <div class="card-header bg-info text-white">
                                <h5 class="mb-0"><i class="bi bi-currency-exchange me-2"></i>Cash Flow Statement</h5>
                            </div>
                            <div class="card-body">
                                <p class="card-text">Track cash inflows and outflows from operating, investing, and financing activities.</p>
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">Operating Activities</small>
                                    <span class="badge bg-info">Available</span>
                                </div>
                            </div>
                            <div class="card-footer bg-light">
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">View Cash Flow</small>
                                    <i class="bi bi-arrow-right text-info"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Sales Analytics -->
                    <div class="col-xl-4 col-lg-6 mb-4">
                        <div class="card h-100 shadow-sm" style="cursor: pointer;" onclick="location.href='sales-analytics.php'">
                            <div class="card-header bg-warning text-dark">
                                <h5 class="mb-0"><i class="bi bi-graph-down me-2"></i>Sales Analytics</h5>
                            </div>
                            <div class="card-body">
                                <p class="card-text">Detailed analysis of sales performance, trends, and product profitability.</p>
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">Product Performance</small>
                                    <span class="badge bg-warning text-dark">Available</span>
                                </div>
                            </div>
                            <div class="card-footer bg-light">
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">View Analytics</small>
                                    <i class="bi bi-arrow-right text-warning"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Expense Analysis -->
                    <div class="col-xl-4 col-lg-6 mb-4">
                        <div class="card h-100 shadow-sm" style="cursor: pointer;" onclick="location.href='expense-analysis.php'">
                            <div class="card-header bg-secondary text-white">
                                <h5 class="mb-0"><i class="bi bi-pie-chart me-2"></i>Expense Analysis</h5>
                            </div>
                            <div class="card-body">
                                <p class="card-text">Comprehensive breakdown of expenses by category, department, and time period.</p>
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">Category Breakdown</small>
                                    <span class="badge bg-secondary">Available</span>
                                </div>
                            </div>
                            <div class="card-footer bg-light">
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">View Analysis</small>
                                    <i class="bi bi-arrow-right text-secondary"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Custom Reports -->
                    <div class="col-xl-4 col-lg-6 mb-4">
                        <div class="card h-100 shadow-sm" style="cursor: pointer;" onclick="location.href='custom-reports.php'">
                            <div class="card-header bg-dark text-white">
                                <h5 class="mb-0"><i class="bi bi-file-earmark-text me-2"></i>Custom Reports</h5>
                            </div>
                            <div class="card-body">
                                <p class="card-text">Build your own custom financial reports with flexible filters and data visualization.</p>
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">Custom Filters</small>
                                    <span class="badge bg-dark">Available</span>
                                </div>
                            </div>
                            <div class="card-footer bg-light">
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">Create Reports</small>
                                    <i class="bi bi-arrow-right text-dark"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Insights -->
                <?php if (!empty($topProducts)): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-star me-2"></i>Top Performing Products This Month</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Product Name</th>
                                                <th>Units Sold</th>
                                                <th>Total Revenue</th>
                                                <th>Performance</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($topProducts as $product): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($product['name']); ?></td>
                                                <td><span class="badge bg-info"><?php echo number_format($product['total_sold']); ?></span></td>
                                                <td class="text-success fw-bold"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($product['total_revenue'], 2); ?></td>
                                                <td><i class="bi bi-graph-up text-success"></i></td>
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
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
