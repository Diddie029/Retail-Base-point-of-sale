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

// For now, let's allow access to users with sales or admin permissions until we set up the proper permission
$hasAccess = hasPermission('manage_sales', $permissions) || 
             hasPermission('view_analytics', $permissions) || 
             hasPermission('manage_users', $permissions) ||
             hasPermission('view_finance', $permissions);

if (!$hasAccess) {
    header('Location: ../dashboard/dashboard.php');
    exit();
}

// Get financial statistics
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

// Total Sales This Year
$stmt = $conn->prepare("SELECT COUNT(*) as count, COALESCE(SUM(final_amount), 0) as total FROM sales WHERE YEAR(sale_date) = YEAR(CURDATE())");
$stmt->execute();
$year_sales = $stmt->fetch(PDO::FETCH_ASSOC);
$stats['year_sales_count'] = $year_sales['count'];
$stats['year_sales_total'] = $year_sales['total'];

// Total Expenses This Month (if expenses table exists)
$stats['month_expenses'] = 0;
try {
    $stmt = $conn->prepare("SELECT COALESCE(SUM(total_amount), 0) as total FROM expenses WHERE MONTH(expense_date) = MONTH(CURDATE()) AND YEAR(expense_date) = YEAR(CURDATE()) AND approval_status = 'approved'");
    $stmt->execute();
    $stats['month_expenses'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
} catch (Exception $e) {
    // Expenses table might not exist
    $stats['month_expenses'] = 0;
}

// Calculate profit (simplified)
$stats['month_profit'] = $stats['month_sales_total'] - $stats['month_expenses'];

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
    <title>Finance Dashboard - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
        
        .finance-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1rem;
        }
        
        .finance-card.profit {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
        }
        
        .finance-card.expense {
            background: linear-gradient(135deg, #fc466b 0%, #3f5efb 100%);
        }
        
        .finance-card.revenue {
            background: linear-gradient(135deg, #fdbb2d 0%, #22c1c3 100%);
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
                    <h1><i class="bi bi-calculator"></i> Finance Dashboard</h1>
                    <p class="header-subtitle">Monitor your financial performance and key metrics</p>
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
                <!-- Key Financial Metrics -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="finance-card">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Today's Revenue</h6>
                                <i class="bi bi-cash-coin fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($stats['today_sales_total'], 2); ?></h3>
                            <small class="opacity-75"><?php echo $stats['today_sales_count']; ?> transactions</small>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="finance-card revenue">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Monthly Revenue</h6>
                                <i class="bi bi-graph-up-arrow fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($stats['month_sales_total'], 2); ?></h3>
                            <small class="opacity-75"><?php echo $stats['month_sales_count']; ?> transactions</small>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="finance-card expense">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Monthly Expenses</h6>
                                <i class="bi bi-cash-stack fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($stats['month_expenses'], 2); ?></h3>
                            <small class="opacity-75">Approved expenses</small>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="finance-card profit">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Monthly Profit</h6>
                                <i class="bi bi-trophy fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($stats['month_profit'], 2); ?></h3>
                            <small class="opacity-75">Revenue - Expenses</small>
                        </div>
                    </div>
                </div>

                <!-- Finance Modules Navigation Cards -->
                <div class="row mb-4">
                    <div class="col-12 mb-3">
                        <h4><i class="bi bi-grid-3x3-gap"></i> Finance Modules</h4>
                        <p class="text-muted">Select a module to access detailed financial information and tools</p>
                    </div>
                </div>

                <!-- Main Finance Navigation Cards -->
                <div class="row mb-4">
                    <!-- Financial Reports -->
                    <div class="col-xl-3 col-lg-4 col-md-6 mb-4">
                        <div class="card module-card h-100" onclick="location.href='reports.php'" style="cursor: pointer;">
                            <div class="card-body text-center p-4">
                                <div class="module-icon mb-3">
                                    <i class="bi bi-file-earmark-bar-graph fs-1 text-primary"></i>
                                </div>
                                <h5 class="card-title">Financial Reports</h5>
                                <p class="card-text text-muted">Comprehensive P&L statements, balance sheets, and financial summaries</p>
                                <div class="module-stats">
                                    <small class="text-success"><i class="bi bi-graph-up"></i> Monthly Revenue: <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($stats['month_sales_total'], 0); ?></small>
                                </div>
                            </div>
                            <div class="card-footer bg-transparent border-0">
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">View Reports</small>
                                    <i class="bi bi-arrow-right text-primary"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Budget Management -->
                    <div class="col-xl-3 col-lg-4 col-md-6 mb-4">
                        <div class="card module-card h-100" onclick="location.href='budget.php'" style="cursor: pointer;">
                            <div class="card-body text-center p-4">
                                <div class="module-icon mb-3">
                                    <i class="bi bi-calculator fs-1 text-success"></i>
                                </div>
                                <h5 class="card-title">Budget Management</h5>
                                <p class="card-text text-muted">Create, monitor and manage departmental and project budgets</p>
                                <div class="module-stats">
                                    <small class="text-info"><i class="bi bi-piggy-bank"></i> Current Month Profit: <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($stats['month_profit'], 0); ?></small>
                                </div>
                            </div>
                            <div class="card-footer bg-transparent border-0">
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">Manage Budget</small>
                                    <i class="bi bi-arrow-right text-success"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Cash Flow Analysis -->
                    <div class="col-xl-3 col-lg-4 col-md-6 mb-4">
                        <div class="card module-card h-100" onclick="location.href='cashflow.php'" style="cursor: pointer;">
                            <div class="card-body text-center p-4">
                                <div class="module-icon mb-3">
                                    <i class="bi bi-currency-exchange fs-1 text-info"></i>
                                </div>
                                <h5 class="card-title">Cash Flow Analysis</h5>
                                <p class="card-text text-muted">Monitor cash inflows, outflows and liquidity positions</p>
                                <div class="module-stats">
                                    <small class="text-primary"><i class="bi bi-cash"></i> Today's Sales: <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($stats['today_sales_total'], 0); ?></small>
                                </div>
                            </div>
                            <div class="card-footer bg-transparent border-0">
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">View Cash Flow</small>
                                    <i class="bi bi-arrow-right text-info"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Expense Analytics -->
                    <div class="col-xl-3 col-lg-4 col-md-6 mb-4">
                        <div class="card module-card h-100" onclick="location.href='expense-analytics.php'" style="cursor: pointer;">
                            <div class="card-body text-center p-4">
                                <div class="module-icon mb-3">
                                    <i class="bi bi-pie-chart fs-1 text-warning"></i>
                                </div>
                                <h5 class="card-title">Expense Analytics</h5>
                                <p class="card-text text-muted">Detailed analysis of spending patterns and cost optimization</p>
                                <div class="module-stats">
                                    <small class="text-danger"><i class="bi bi-credit-card"></i> Monthly Expenses: <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($stats['month_expenses'], 0); ?></small>
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

                    <!-- Tax Management -->
                    <div class="col-xl-3 col-lg-4 col-md-6 mb-4">
                        <div class="card module-card h-100" onclick="location.href='tax-management.php'" style="cursor: pointer;">
                            <div class="card-body text-center p-4">
                                <div class="module-icon mb-3">
                                    <i class="bi bi-receipt fs-1 text-secondary"></i>
                                </div>
                                <h5 class="card-title">Tax Management</h5>
                                <p class="card-text text-muted">VAT calculations, tax reports and compliance management</p>
                                <div class="module-stats">
                                    <small class="text-secondary"><i class="bi bi-percent"></i> Tax Reports & Compliance</small>
                                </div>
                            </div>
                            <div class="card-footer bg-transparent border-0">
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">Manage Tax</small>
                                    <i class="bi bi-arrow-right text-secondary"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Profit Analysis -->
                    <div class="col-xl-3 col-lg-4 col-md-6 mb-4">
                        <div class="card module-card h-100" onclick="location.href='profit-analysis.php'" style="cursor: pointer;">
                            <div class="card-body text-center p-4">
                                <div class="module-icon mb-3">
                                    <i class="bi bi-graph-up-arrow fs-1 text-success"></i>
                                </div>
                                <h5 class="card-title">Profit Analysis</h5>
                                <p class="card-text text-muted">Profit margins, ROI analysis and profitability tracking</p>
                                <div class="module-stats">
                                    <small class="text-success"><i class="bi bi-trophy"></i> Gross Margin Analysis</small>
                                </div>
                            </div>
                            <div class="card-footer bg-transparent border-0">
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">View Analysis</small>
                                    <i class="bi bi-arrow-right text-success"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Financial Forecasting -->
                    <div class="col-xl-3 col-lg-4 col-md-6 mb-4">
                        <div class="card module-card h-100" onclick="location.href='forecasting.php'" style="cursor: pointer;">
                            <div class="card-body text-center p-4">
                                <div class="module-icon mb-3">
                                    <i class="bi bi-graph-down fs-1 text-primary"></i>
                                </div>
                                <h5 class="card-title">Financial Forecasting</h5>
                                <p class="card-text text-muted">Revenue projections, trend analysis and future planning</p>
                                <div class="module-stats">
                                    <small class="text-primary"><i class="bi bi-trending-up"></i> Growth Projections</small>
                                </div>
                            </div>
                            <div class="card-footer bg-transparent border-0">
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">View Forecasts</small>
                                    <i class="bi bi-arrow-right text-primary"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Account Reconciliation -->
                    <div class="col-xl-3 col-lg-4 col-md-6 mb-4">
                        <div class="card module-card h-100" onclick="location.href='reconciliation.php'" style="cursor: pointer;">
                            <div class="card-body text-center p-4">
                                <div class="module-icon mb-3">
                                    <i class="bi bi-check2-square fs-1 text-success"></i>
                                </div>
                                <h5 class="card-title">Account Reconciliation</h5>
                                <p class="card-text text-muted">Bank reconciliation, account matching and balance verification</p>
                                <div class="module-stats">
                                    <small class="text-success"><i class="bi bi-bank"></i> Balance Verification</small>
                                </div>
                            </div>
                            <div class="card-footer bg-transparent border-0">
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">Reconcile Accounts</small>
                                    <i class="bi bi-arrow-right text-success"></i>
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
                        <div class="card quick-action-card" onclick="location.href='../expenses/index.php'" style="cursor: pointer;">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="quick-icon me-3">
                                        <i class="bi bi-cash-stack text-danger fs-3"></i>
                                    </div>
                                    <div>
                                        <h6 class="mb-1">Manage Expenses</h6>
                                        <small class="text-muted">Add and track business expenses</small>
                                    </div>
                                    <div class="ms-auto">
                                        <i class="bi bi-arrow-right text-danger"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-4 col-md-6 mb-4">
                        <div class="card quick-action-card" onclick="location.href='../analytics/index.php'" style="cursor: pointer;">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="quick-icon me-3">
                                        <i class="bi bi-graph-up text-success fs-3"></i>
                                    </div>
                                    <div>
                                        <h6 class="mb-1">Analytics Dashboard</h6>
                                        <small class="text-muted">View business analytics</small>
                                    </div>
                                    <div class="ms-auto">
                                        <i class="bi bi-arrow-right text-success"></i>
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
