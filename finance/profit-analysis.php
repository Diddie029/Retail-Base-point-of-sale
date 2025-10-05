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
$period_type = $_GET['period'] ?? 'yearly';

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
            $current_month = date('n');
            if ($current_month <= 3) {
                $quarter_start = date('Y-01-01');
                $quarter_end = date('Y-03-31');
            } elseif ($current_month <= 6) {
                $quarter_start = date('Y-04-01');
                $quarter_end = date('Y-06-30');
            } elseif ($current_month <= 9) {
                $quarter_start = date('Y-07-01');
                $quarter_end = date('Y-09-30');
            } else {
                $quarter_start = date('Y-10-01');
                $quarter_end = date('Y-12-31');
            }
            $start_date = $quarter_start;
            $end_date = $quarter_end;
            break;
        case 'yearly':
            $start_date = date('Y-01-01');
            $end_date = date('Y-12-31');
            break;
    }
}

// Get comprehensive profit analysis data using the database function
$profit_analysis = getProfitAnalysisData($conn, $start_date, $end_date);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profit Analysis - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --danger-color: #ef4444;
            --info-color: #3b82f6;
            --gradient-primary: linear-gradient(135deg, var(--primary-color) 0%, #4f46e5 100%);
            --gradient-success: linear-gradient(135deg, #10b981 0%, #059669 100%);
            --gradient-danger: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        }
        
        .profit-card {
            border-radius: 16px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            border: 1px solid #e5e7eb;
            transition: all 0.3s ease;
            overflow: hidden;
        }
        
        .profit-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(0,0,0,0.1);
        }
        
        .profit-card .card-header {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            border-bottom: 2px solid var(--primary-color);
            border-radius: 16px 16px 0 0 !important;
            position: relative;
        }
        
        .profit-card .card-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--gradient-primary);
        }
        
        .metric-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 8px 16px rgba(0,0,0,0.1);
            position: relative;
            overflow: hidden;
        }
        
        .metric-card::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 100px;
            height: 100px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
            transform: translate(30px, -30px);
        }
        
        .metric-value {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .metric-label {
            font-size: 1rem;
            opacity: 0.9;
        }
        
        .profit-icon {
            width: 60px;
            height: 60px;
            background: var(--gradient-primary);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
        }
        
        .fade-in {
            animation: fadeIn 0.5s ease-in;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .table-hover tbody tr:hover {
            background-color: rgba(99, 102, 241, 0.05);
            transform: scale(1.01);
            transition: all 0.2s ease;
        }
        
        .breadcrumb {
            background: none;
            padding: 0;
            margin-bottom: 1rem;
        }
        
        .breadcrumb-item a {
            color: var(--primary-color);
            transition: color 0.3s ease;
            text-decoration: none;
        }
        
        .breadcrumb-item a:hover {
            color: #4f46e5;
        }
        
        .breadcrumb-item.active {
            color: #6b7280;
        }
        
        .header-subtitle {
            font-size: 1rem;
            color: #6b7280;
        }
        
        .form-control, .form-select {
            border-radius: 8px;
            border: 1px solid #d1d5db;
            transition: all 0.3s ease;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(99, 102, 241, 0.25);
        }
        
        .btn {
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn:hover {
            transform: translateY(-1px);
        }
        
        .profit-positive {
            color: var(--success-color);
        }
        
        .profit-negative {
            color: var(--danger-color);
        }
        
        .profit-neutral {
            color: #6b7280;
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
                            <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none"><i class="bi bi-house me-1"></i>Finance Dashboard</a></li>
                            <li class="breadcrumb-item active"><i class="bi bi-graph-up-arrow me-1"></i>Profit Analysis</li>
                        </ol>
                    </nav>
                    <div class="d-flex align-items-center mb-3">
                        <div class="profit-icon me-3">
                            <i class="bi bi-graph-up-arrow"></i>
                        </div>
                        <div>
                            <h1 class="mb-1">Profit Analysis</h1>
                            <p class="header-subtitle mb-0">
                                <i class="bi bi-calendar3 me-2"></i>
                                For the period <strong><?php echo date('M d, Y', strtotime($start_date)); ?> to <?php echo date('M d, Y', strtotime($end_date)); ?></strong>
                            </p>
                        </div>
                    </div>
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
                <!-- Period Selection -->
                <div class="row mb-4 fade-in">
                    <div class="col-lg-6">
                        <div class="card profit-card">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="bi bi-calendar-range me-2"></i>Report Period</h6>
                            </div>
                            <div class="card-body">
                                <form method="GET" class="row g-3 align-items-end">
                                    <div class="col-md-4">
                                        <label for="period" class="form-label">Period</label>
                                        <select class="form-select" id="period" name="period" onchange="updateDateFields()">
                                            <option value="weekly" <?php echo $period_type == 'weekly' ? 'selected' : ''; ?>>This Week</option>
                                            <option value="monthly" <?php echo $period_type == 'monthly' ? 'selected' : ''; ?>>This Month</option>
                                            <option value="quarterly" <?php echo $period_type == 'quarterly' ? 'selected' : ''; ?>>This Quarter</option>
                                            <option value="yearly" <?php echo $period_type == 'yearly' ? 'selected' : ''; ?>>This Year</option>
                                            <option value="custom" <?php echo $period_type == 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label for="start_date" class="form-label">Start Date</label>
                                        <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo $start_date; ?>">
                                    </div>
                                    <div class="col-md-3">
                                        <label for="end_date" class="form-label">End Date</label>
                                        <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo $end_date; ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-search"></i> Update
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="metric-card">
                            <div class="row">
                                <div class="col-6">
                                    <div class="text-center">
                                        <div class="metric-value profit-<?php echo ($profit_analysis['profit']['net_profit'] ?? 0) >= 0 ? 'positive' : 'negative'; ?>">
                                            <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($profit_analysis['profit']['net_profit'] ?? 0, 2); ?>
                                        </div>
                                        <div class="metric-label">Net Profit</div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="text-center">
                                        <div class="metric-value profit-<?php echo ($profit_analysis['profit']['net_margin'] ?? 0) >= 0 ? 'positive' : 'negative'; ?>">
                                            <?php echo number_format($profit_analysis['profit']['net_margin'] ?? 0, 1); ?>%
                                        </div>
                                        <div class="metric-label">Net Margin</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Key Metrics -->
                <div class="row mb-4 fade-in">
                    <div class="col-md-3">
                        <div class="card profit-card">
                            <div class="card-body text-center">
                                <div class="profit-icon mx-auto mb-3">
                                    <i class="bi bi-currency-dollar"></i>
                                </div>
                                <h5 class="text-success fw-bold"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($profit_analysis['revenue']['total_sales'] ?? 0, 2); ?></h5>
                                <p class="text-muted mb-0">Total Revenue</p>
                                <small class="text-muted"><?php echo number_format($profit_analysis['revenue']['total_transactions'] ?? 0); ?> transactions</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card profit-card">
                            <div class="card-body text-center">
                                <div class="profit-icon mx-auto mb-3">
                                    <i class="bi bi-cart"></i>
                                </div>
                                <h5 class="text-danger fw-bold"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($profit_analysis['costs']['cost_of_goods_sold'] ?? 0, 2); ?></h5>
                                <p class="text-muted mb-0">Cost of Goods Sold</p>
                                <small class="text-muted">Direct costs</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card profit-card">
                            <div class="card-body text-center">
                                <div class="profit-icon mx-auto mb-3">
                                    <i class="bi bi-graph-up"></i>
                                </div>
                                <h5 class="text-info fw-bold"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($profit_analysis['profit']['gross_profit'] ?? 0, 2); ?></h5>
                                <p class="text-muted mb-0">Gross Profit</p>
                                <small class="text-muted"><?php echo number_format($profit_analysis['profit']['gross_margin'] ?? 0, 1); ?>% margin</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card profit-card">
                            <div class="card-body text-center">
                                <div class="profit-icon mx-auto mb-3">
                                    <i class="bi bi-pie-chart"></i>
                                </div>
                                <h5 class="text-warning fw-bold"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($profit_analysis['costs']['operating_expenses'] ?? 0, 2); ?></h5>
                                <p class="text-muted mb-0">Operating Expenses</p>
                                <small class="text-muted">Indirect costs</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Revenue Breakdown -->
                <div class="row mb-4 fade-in">
                    <div class="col-lg-6">
                        <div class="card profit-card">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="bi bi-pie-chart me-2"></i>Revenue Breakdown</h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-6">
                                        <div class="d-flex justify-content-between align-items-center mb-3">
                                            <span>Cash Sales</span>
                                            <span class="fw-bold text-success"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($profit_analysis['revenue']['cash_sales'] ?? 0, 2); ?></span>
                                        </div>
                                        <div class="progress mb-3" style="height: 8px;">
                                            <div class="progress-bar bg-success" style="width: <?php echo $profit_analysis['revenue']['total_sales'] > 0 ? ($profit_analysis['revenue']['cash_sales'] / $profit_analysis['revenue']['total_sales']) * 100 : 0; ?>%"></div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="d-flex justify-content-between align-items-center mb-3">
                                            <span>Credit Sales</span>
                                            <span class="fw-bold text-info"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($profit_analysis['revenue']['credit_sales'] ?? 0, 2); ?></span>
                                        </div>
                                        <div class="progress mb-3" style="height: 8px;">
                                            <div class="progress-bar bg-info" style="width: <?php echo $profit_analysis['revenue']['total_sales'] > 0 ? ($profit_analysis['revenue']['credit_sales'] / $profit_analysis['revenue']['total_sales']) * 100 : 0; ?>%"></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="text-center">
                                    <small class="text-muted">Average Transaction: <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($profit_analysis['revenue']['average_transaction'] ?? 0, 2); ?></small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="card profit-card">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="bi bi-graph-up me-2"></i>Profit Margins</h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-6">
                                        <div class="text-center">
                                            <div class="h4 text-info"><?php echo number_format($profit_analysis['profit']['gross_margin'] ?? 0, 1); ?>%</div>
                                            <small class="text-muted">Gross Margin</small>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="text-center">
                                            <div class="h4 profit-<?php echo ($profit_analysis['profit']['net_margin'] ?? 0) >= 0 ? 'positive' : 'negative'; ?>"><?php echo number_format($profit_analysis['profit']['net_margin'] ?? 0, 1); ?>%</div>
                                            <small class="text-muted">Net Margin</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Top Products by Profit -->
                <div class="row mb-4 fade-in">
                    <div class="col-12">
                        <div class="card profit-card">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="bi bi-star me-2"></i>Top Products by Profit</h6>
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
                                                <th class="text-end">Cost</th>
                                                <th class="text-end">Profit</th>
                                                <th class="text-end">Margin</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($profit_analysis['top_products'])): ?>
                                                <?php foreach ($profit_analysis['top_products'] as $index => $product): ?>
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
                                                        <span class="badge bg-info"><?php echo number_format($product['total_sold'] ?? 0); ?></span>
                                                    </td>
                                                    <td class="text-end text-success fw-bold">
                                                        <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($product['total_revenue'] ?? 0, 2); ?>
                                                    </td>
                                                    <td class="text-end text-danger">
                                                        <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($product['total_cost'] ?? 0, 2); ?>
                                                    </td>
                                                    <td class="text-end profit-<?php echo $product['profit'] >= 0 ? 'positive' : 'negative'; ?> fw-bold">
                                                        <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($product['profit'] ?? 0, 2); ?>
                                                    </td>
                                                    <td class="text-end">
                                                        <span class="badge bg-<?php echo ($product['profit_margin'] ?? 0) > 20 ? 'success' : (($product['profit_margin'] ?? 0) > 10 ? 'warning' : 'danger'); ?>">
                                                            <?php echo number_format($product['profit_margin'] ?? 0, 1); ?>%
                                                        </span>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="7" class="text-center text-muted py-4">
                                                        <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                                        No product profit data available for the selected period.
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

                <!-- Category Performance -->
                <div class="row fade-in">
                    <div class="col-12">
                        <div class="card profit-card">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="bi bi-tags me-2"></i>Category Performance by Profit</h6>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead class="table-light">
                                            <tr>
                                                <th width="5%">#</th>
                                                <th>Category Name</th>
                                                <th class="text-end">Units Sold</th>
                                                <th class="text-end">Revenue</th>
                                                <th class="text-end">Cost</th>
                                                <th class="text-end">Profit</th>
                                                <th class="text-end">Margin</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($profit_analysis['category_performance'])): ?>
                                                <?php foreach ($profit_analysis['category_performance'] as $index => $category): ?>
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
                                                        <span class="badge bg-primary"><?php echo number_format($category['total_sold'] ?? 0); ?></span>
                                                    </td>
                                                    <td class="text-end text-success fw-bold">
                                                        <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($category['total_revenue'] ?? 0, 2); ?>
                                                    </td>
                                                    <td class="text-end text-danger">
                                                        <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($category['total_cost'] ?? 0, 2); ?>
                                                    </td>
                                                    <td class="text-end profit-<?php echo $category['profit'] >= 0 ? 'positive' : 'negative'; ?> fw-bold">
                                                        <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($category['profit'] ?? 0, 2); ?>
                                                    </td>
                                                    <td class="text-end">
                                                        <span class="badge bg-<?php echo ($category['profit_margin'] ?? 0) > 20 ? 'success' : (($category['profit_margin'] ?? 0) > 10 ? 'warning' : 'danger'); ?>">
                                                            <?php echo number_format($category['profit_margin'] ?? 0, 1); ?>%
                                                        </span>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="7" class="text-center text-muted py-4">
                                                        <i class="bi bi-tags fs-1 d-block mb-2"></i>
                                                        No category profit data available for the selected period.
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
        function exportToCSV() {
            let csv = 'Profit Analysis Report\n';
            csv += 'Period,<?php echo $start_date; ?> to <?php echo $end_date; ?>\n\n';
            csv += 'Metric,Value\n';
            csv += 'Total Revenue,<?php echo $profit_analysis['revenue']['total_sales']; ?>\n';
            csv += 'Cost of Goods Sold,<?php echo $profit_analysis['costs']['cost_of_goods_sold']; ?>\n';
            csv += 'Operating Expenses,<?php echo $profit_analysis['costs']['operating_expenses']; ?>\n';
            csv += 'Gross Profit,<?php echo $profit_analysis['profit']['gross_profit']; ?>\n';
            csv += 'Net Profit,<?php echo $profit_analysis['profit']['net_profit']; ?>\n';
            csv += 'Gross Margin,<?php echo $profit_analysis['profit']['gross_margin']; ?>%\n';
            csv += 'Net Margin,<?php echo $profit_analysis['profit']['net_margin']; ?>%\n\n';
            
            csv += 'Top Products by Profit\n';
            csv += 'Product,Quantity Sold,Revenue,Cost,Profit,Margin\n';
            <?php foreach ($profit_analysis['top_products'] as $product): ?>
            csv += '<?php echo addslashes($product['name']); ?>,<?php echo $product['total_sold']; ?>,<?php echo $product['total_revenue']; ?>,<?php echo $product['total_cost']; ?>,<?php echo $product['profit']; ?>,<?php echo $product['profit_margin']; ?>%\n';
            <?php endforeach; ?>
            
            // Download
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.setAttribute('hidden', '');
            a.setAttribute('href', url);
            a.setAttribute('download', 'profit-analysis-<?php echo $start_date; ?>-to-<?php echo $end_date; ?>.csv');
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        }
        
        // Function to update date fields based on period selection
        function updateDateFields() {
            const periodSelect = document.getElementById('period');
            const startDateInput = document.getElementById('start_date');
            const endDateInput = document.getElementById('end_date');
            const form = document.querySelector('form');
            
            const period = periodSelect.value;
            const today = new Date();
            let startDate, endDate;
            
            switch (period) {
                case 'weekly':
                    // Get Monday of this week
                    const monday = new Date(today);
                    monday.setDate(today.getDate() - today.getDay() + 1);
                    startDate = monday.toISOString().split('T')[0];
                    
                    // Get Sunday of this week
                    const sunday = new Date(monday);
                    sunday.setDate(monday.getDate() + 6);
                    endDate = sunday.toISOString().split('T')[0];
                    break;
                    
                case 'monthly':
                    // First day of current month
                    startDate = new Date(today.getFullYear(), today.getMonth(), 1).toISOString().split('T')[0];
                    // Last day of current month
                    endDate = new Date(today.getFullYear(), today.getMonth() + 1, 0).toISOString().split('T')[0];
                    break;
                    
                case 'quarterly':
                    const currentMonth = today.getMonth() + 1; // getMonth() returns 0-11
                    let quarterStart, quarterEnd;
                    
                    if (currentMonth <= 3) {
                        quarterStart = new Date(today.getFullYear(), 0, 1); // Jan 1
                        quarterEnd = new Date(today.getFullYear(), 2, 31); // Mar 31
                    } else if (currentMonth <= 6) {
                        quarterStart = new Date(today.getFullYear(), 3, 1); // Apr 1
                        quarterEnd = new Date(today.getFullYear(), 5, 30); // Jun 30
                    } else if (currentMonth <= 9) {
                        quarterStart = new Date(today.getFullYear(), 6, 1); // Jul 1
                        quarterEnd = new Date(today.getFullYear(), 8, 30); // Sep 30
                    } else {
                        quarterStart = new Date(today.getFullYear(), 9, 1); // Oct 1
                        quarterEnd = new Date(today.getFullYear(), 11, 31); // Dec 31
                    }
                    
                    startDate = quarterStart.toISOString().split('T')[0];
                    endDate = quarterEnd.toISOString().split('T')[0];
                    break;
                    
                case 'yearly':
                    // January 1st to December 31st of current year
                    startDate = new Date(today.getFullYear(), 0, 1).toISOString().split('T')[0];
                    endDate = new Date(today.getFullYear(), 11, 31).toISOString().split('T')[0];
                    break;
                    
                case 'custom':
                    // Don't change dates for custom range
                    return;
            }
            
            // Update the date inputs
            if (startDate && endDate) {
                startDateInput.value = startDate;
                endDateInput.value = endDate;
                
                // Auto-submit the form to update the data
                form.submit();
            }
        }
        
        // Add loading animation on page load
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.fade-in');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
            });
        });
    </script>
</body>
</html>
