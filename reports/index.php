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

// Check if user has permission to view reports
$hasAccess = isAdmin($role_name) || 
             hasPermission('view_analytics', $permissions) || 
             hasPermission('manage_sales', $permissions) || 
             hasPermission('manage_users', $permissions) ||
             hasPermission('view_finance', $permissions);

if (!$hasAccess) {
    header('Location: ../dashboard/dashboard.php');
    exit();
}

// Get basic statistics for dashboard
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports Dashboard - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
        
        .reports-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1rem;
        }
        
        .reports-card.sales {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
        }
        
        .reports-card.products {
            background: linear-gradient(135deg, #fc466b 0%, #3f5efb 100%);
        }
        
        .reports-card.customers {
            background: linear-gradient(135deg, #fdbb2d 0%, #22c1c3 100%);
        }
        
        .reports-card.finance {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
        
        /* Report Module Card Styles */
        .report-module-card {
            border: none;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            border-radius: 12px;
            height: 100%;
            min-height: 300px;
            display: flex;
            flex-direction: column;
        }
        
        .report-module-card .card-body {
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        
        .report-module-card .card-footer {
            flex-shrink: 0;
        }
        
        .report-module-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }
        
        .report-module-icon {
            transition: transform 0.3s ease;
        }
        
        .report-module-card:hover .report-module-icon {
            transform: scale(1.1);
        }
        
        .report-module-stats {
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
        
        .report-category {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <?php include '../include/navmenu.php'; ?>

    <div class="main-content">
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1><i class="bi bi-file-earmark-bar-graph"></i> Reports Dashboard</h1>
                    <p class="header-subtitle">Comprehensive business reports and analytics</p>
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
                <!-- Key Metrics Overview -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="reports-card">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Today's Sales</h6>
                                <i class="bi bi-cash-coin fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($stats['today_sales_total'], 2); ?></h3>
                            <small class="opacity-75"><?php echo $stats['today_sales_count']; ?> transactions</small>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="reports-card sales">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Monthly Sales</h6>
                                <i class="bi bi-graph-up-arrow fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($stats['month_sales_total'], 2); ?></h3>
                            <small class="opacity-75"><?php echo $stats['month_sales_count']; ?> transactions</small>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="reports-card products">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Total Products</h6>
                                <i class="bi bi-box fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo number_format($stats['total_products']); ?></h3>
                            <small class="opacity-75">Active products</small>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="reports-card customers">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Total Customers</h6>
                                <i class="bi bi-people fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo number_format($stats['total_customers']); ?></h3>
                            <small class="opacity-75">Registered customers</small>
                        </div>
                    </div>
                </div>

                <!-- Sales & Revenue Reports Section -->
                <div class="row mb-4">
                    <div class="col-12 mb-3">
                        <div class="report-category">
                            <h4><i class="bi bi-graph-up"></i> Sales & Revenue Reports</h4>
                            <p class="mb-0">Comprehensive sales analysis and revenue tracking reports</p>
                        </div>
                    </div>
                </div>

                <div class="row mb-4">
                    <!-- Daily/Weekly/Monthly Sales Summary -->
                    <div class="col-xl-4 col-lg-6 col-md-6 mb-4">
                        <div class="card report-module-card" onclick="location.href='sales_summary.php'" style="cursor: pointer;">
                            <div class="card-body text-center p-4">
                                <div class="report-module-icon mb-3">
                                    <i class="bi bi-calendar-range fs-1 text-primary"></i>
                                </div>
                                <h5 class="card-title">Sales Summary Reports</h5>
                                <p class="card-text text-muted">Daily, weekly, and monthly sales summaries with revenue trends and transaction counts</p>
                                <div class="report-module-stats">
                                    <small class="text-success"><i class="bi bi-graph-up"></i> Revenue: <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($stats['month_sales_total'], 0); ?></small>
                                </div>
                            </div>
                            <div class="card-footer bg-transparent border-0">
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">View Report</small>
                                    <i class="bi bi-arrow-right text-primary"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Sales by Product Category -->
                    <div class="col-xl-4 col-lg-6 col-md-6 mb-4">
                        <div class="card report-module-card" onclick="location.href='sales_by_category.php'" style="cursor: pointer;">
                            <div class="card-body text-center p-4">
                                <div class="report-module-icon mb-3">
                                    <i class="bi bi-tags fs-1 text-success"></i>
                                </div>
                                <h5 class="card-title">Sales by Category</h5>
                                <p class="card-text text-muted">Performance analysis of different product categories with revenue breakdown</p>
                                <div class="report-module-stats">
                                    <small class="text-info"><i class="bi bi-tags"></i> Category Analysis</small>
                                </div>
                            </div>
                            <div class="card-footer bg-transparent border-0">
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">View Report</small>
                                    <i class="bi bi-arrow-right text-success"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Top/Bottom Selling Products -->
                    <div class="col-xl-4 col-lg-6 col-md-6 mb-4">
                        <div class="card report-module-card" onclick="location.href='product_performance.php'" style="cursor: pointer;">
                            <div class="card-body text-center p-4">
                                <div class="report-module-icon mb-3">
                                    <i class="bi bi-trophy fs-1 text-warning"></i>
                                </div>
                                <h5 class="card-title">Product Performance</h5>
                                <p class="card-text text-muted">Best and worst performing products with detailed metrics and analysis</p>
                                <div class="report-module-stats">
                                    <small class="text-warning"><i class="bi bi-trophy"></i> Performance Metrics</small>
                                </div>
                            </div>
                            <div class="card-footer bg-transparent border-0">
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">View Report</small>
                                    <i class="bi bi-arrow-right text-warning"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Sales by Payment Method -->
                    <div class="col-xl-4 col-lg-6 col-md-6 mb-4">
                        <div class="card report-module-card" onclick="location.href='payment_methods.php'" style="cursor: pointer;">
                            <div class="card-body text-center p-4">
                                <div class="report-module-icon mb-3">
                                    <i class="bi bi-credit-card fs-1 text-info"></i>
                                </div>
                                <h5 class="card-title">Payment Methods</h5>
                                <p class="card-text text-muted">Analysis of cash, card, mobile payments, and other payment types</p>
                                <div class="report-module-stats">
                                    <small class="text-info"><i class="bi bi-credit-card"></i> Payment Analysis</small>
                                </div>
                            </div>
                            <div class="card-footer bg-transparent border-0">
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">View Report</small>
                                    <i class="bi bi-arrow-right text-info"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Sales by Cashier/Staff -->
                    <div class="col-xl-4 col-lg-6 col-md-6 mb-4">
                        <div class="card report-module-card" onclick="location.href='staff_performance.php'" style="cursor: pointer;">
                            <div class="card-body text-center p-4">
                                <div class="report-module-icon mb-3">
                                    <i class="bi bi-person-badge fs-1 text-secondary"></i>
                                </div>
                                <h5 class="card-title">Staff Performance</h5>
                                <p class="card-text text-muted">Individual staff performance reports and productivity metrics</p>
                                <div class="report-module-stats">
                                    <small class="text-secondary"><i class="bi bi-person-badge"></i> Staff Analytics</small>
                                </div>
                            </div>
                            <div class="card-footer bg-transparent border-0">
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">View Report</small>
                                    <i class="bi bi-arrow-right text-secondary"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>

                 <!-- Cashier Reports Section -->
                <div class="row mb-4">
                    <div class="col-12 mb-3">
                        <div class="report-category">
                            <h4><i class="bi bi-person-badge"></i> Cashier Reports</h4>
                            <p class="mb-0">Detailed cashier performance and transaction reports</p>
                        </div>
                    </div>
                </div>

                <div class="row mb-4">
                    <!-- Cashier Performance Report -->
                    <div class="col-xl-4 col-lg-6 col-md-6 mb-4">
                        <div class="card report-module-card" onclick="location.href='cashier_reports.php'" style="cursor: pointer;">
                            <div class="card-body text-center p-4">
                                <div class="report-module-icon mb-3">
                                    <i class="bi bi-person-check fs-1 text-primary"></i>
                                </div>
                                <h5 class="card-title">Cashier Performance</h5>
                                <p class="card-text text-muted">Individual cashier performance metrics, void transactions, and productivity analysis</p>
                                <div class="report-module-stats">
                                    <small class="text-primary"><i class="bi bi-graph-up"></i> Performance Metrics</small>
                                </div>
                            </div>
                            <div class="card-footer bg-transparent border-0">
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">View Report</small>
                                    <i class="bi bi-arrow-right text-primary"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Void Transactions Report -->
                    <div class="col-xl-4 col-lg-6 col-md-6 mb-4">
                        <div class="card report-module-card" onclick="location.href='void_transactions_report.php'" style="cursor: pointer;">
                            <div class="card-body text-center p-4">
                                <div class="report-module-icon mb-3">
                                    <i class="bi bi-x-circle fs-1 text-danger"></i>
                                </div>
                                <h5 class="card-title">Void Transactions</h5>
                                <p class="card-text text-muted">Detailed report of all voided transactions, reasons, and cashier accountability</p>
                                <div class="report-module-stats">
                                    <small class="text-danger"><i class="bi bi-x-circle"></i> Void Analysis</small>
                                </div>
                            </div>
                            <div class="card-footer bg-transparent border-0">
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">View Report</small>
                                    <i class="bi bi-arrow-right text-danger"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Held Transactions Report -->
                    <div class="col-xl-4 col-lg-6 col-md-6 mb-4">
                        <div class="card report-module-card" onclick="location.href='held_transactions_report.php'" style="cursor: pointer;">
                            <div class="card-body text-center p-4">
                                <div class="report-module-icon mb-3">
                                    <i class="bi bi-clock-history fs-1 text-warning"></i>
                                </div>
                                <h5 class="card-title">Held Transactions</h5>
                                <p class="card-text text-muted">Report on held/suspended transactions and their resolution status</p>
                                <div class="report-module-stats">
                                    <small class="text-warning"><i class="bi bi-clock-history"></i> Hold Analysis</small>
                                </div>
                            </div>
                            <div class="card-footer bg-transparent border-0">
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">View Report</small>
                                    <i class="bi bi-arrow-right text-warning"></i>
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
