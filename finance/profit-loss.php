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

// Get user's role_id
$role_id = $_SESSION['role_id'] ?? null;

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

// Get filter parameters
$period = $_GET['period'] ?? 'current_month';
$custom_start = $_GET['start_date'] ?? '';
$custom_end = $_GET['end_date'] ?? '';

// Set date ranges based on period
$start_date = '';
$end_date = '';
$period_label = '';

switch ($period) {
    case 'current_month':
        $start_date = date('Y-m-01');
        $end_date = date('Y-m-t');
        $period_label = date('F Y');
        break;
    case 'last_month':
        $start_date = date('Y-m-01', strtotime('-1 month'));
        $end_date = date('Y-m-t', strtotime('-1 month'));
        $period_label = date('F Y', strtotime('-1 month'));
        break;
    case 'current_year':
        $start_date = date('Y-01-01');
        $end_date = date('Y-12-31');
        $period_label = date('Y');
        break;
    case 'last_year':
        $start_date = date('Y-01-01', strtotime('-1 year'));
        $end_date = date('Y-12-31', strtotime('-1 year'));
        $period_label = date('Y', strtotime('-1 year'));
        break;
    case 'custom':
        $start_date = $custom_start ?: date('Y-m-01');
        $end_date = $custom_end ?: date('Y-m-t');
        $period_label = date('M j, Y', strtotime($start_date)) . ' - ' . date('M j, Y', strtotime($end_date));
        break;
}

// REVENUE SECTION
// Total Sales Revenue
$stmt = $conn->prepare("SELECT COALESCE(SUM(final_amount), 0) as total FROM sales WHERE sale_date BETWEEN ? AND ?");
$stmt->execute([$start_date, $end_date]);
$total_sales = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Sales by Payment Method
$stmt = $conn->prepare("
    SELECT payment_method, COALESCE(SUM(final_amount), 0) as total 
    FROM sales 
    WHERE sale_date BETWEEN ? AND ? 
    GROUP BY payment_method 
    ORDER BY total DESC
");
$stmt->execute([$start_date, $end_date]);
$sales_by_method = $stmt->fetchAll(PDO::FETCH_ASSOC);

// COST OF GOODS SOLD (COGS)
// Calculate COGS based on products sold and their cost prices
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(si.quantity * COALESCE(p.cost_price, 0)), 0) as total_cogs
    FROM sale_items si
    JOIN sales s ON si.sale_id = s.id
    JOIN products p ON si.product_id = p.id
    WHERE s.sale_date BETWEEN ? AND ?
");
$stmt->execute([$start_date, $end_date]);
$cogs = $stmt->fetch(PDO::FETCH_ASSOC)['total_cogs'];

// Gross Profit
$gross_profit = $total_sales - $cogs;
$gross_margin = $total_sales > 0 ? ($gross_profit / $total_sales) * 100 : 0;

// OPERATING EXPENSES
$operating_expenses = 0;
$expense_categories = [];

try {
    // Get total operating expenses
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(total_amount), 0) as total 
        FROM expenses 
        WHERE expense_date BETWEEN ? AND ? 
        AND approval_status = 'approved'
    ");
    $stmt->execute([$start_date, $end_date]);
    $operating_expenses = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get expenses by category
    $stmt = $conn->prepare("
        SELECT 
            COALESCE(ec.name, 'Uncategorized') as category_name,
            COALESCE(SUM(e.total_amount), 0) as total
        FROM expenses e
        LEFT JOIN expense_categories ec ON e.category_id = ec.id
        WHERE e.expense_date BETWEEN ? AND ? 
        AND e.approval_status = 'approved'
        GROUP BY e.category_id, ec.name
        ORDER BY total DESC
    ");
    $stmt->execute([$start_date, $end_date]);
    $expense_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $operating_expenses = 0;
    $expense_categories = [];
}

// Net Profit/Loss
$net_profit = $gross_profit - $operating_expenses;
$net_margin = $total_sales > 0 ? ($net_profit / $total_sales) * 100 : 0;

// Year-over-year comparison (if applicable)
$comparison_data = [];
if (in_array($period, ['current_month', 'current_year'])) {
    $comparison_start = '';
    $comparison_end = '';
    
    if ($period === 'current_month') {
        $comparison_start = date('Y-m-01', strtotime('-1 year'));
        $comparison_end = date('Y-m-t', strtotime('-1 year'));
    } else {
        $comparison_start = date('Y-01-01', strtotime('-1 year'));
        $comparison_end = date('Y-12-31', strtotime('-1 year'));
    }
    
    // Previous period sales
    $stmt = $conn->prepare("SELECT COALESCE(SUM(final_amount), 0) as total FROM sales WHERE sale_date BETWEEN ? AND ?");
    $stmt->execute([$comparison_start, $comparison_end]);
    $prev_sales = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Previous period COGS
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(si.quantity * COALESCE(p.cost_price, 0)), 0) as total_cogs
        FROM sale_items si
        JOIN sales s ON si.sale_id = s.id
        JOIN products p ON si.product_id = p.id
        WHERE s.sale_date BETWEEN ? AND ?
    ");
    $stmt->execute([$comparison_start, $comparison_end]);
    $prev_cogs = $stmt->fetch(PDO::FETCH_ASSOC)['total_cogs'];
    
    // Previous period expenses
    $prev_expenses = 0;
    try {
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(total_amount), 0) as total 
            FROM expenses 
            WHERE expense_date BETWEEN ? AND ? 
            AND approval_status = 'approved'
        ");
        $stmt->execute([$comparison_start, $comparison_end]);
        $prev_expenses = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    } catch (Exception $e) {
        $prev_expenses = 0;
    }
    
    $prev_gross_profit = $prev_sales - $prev_cogs;
    $prev_net_profit = $prev_gross_profit - $prev_expenses;
    
    $comparison_data = [
        'sales' => $prev_sales,
        'cogs' => $prev_cogs,
        'gross_profit' => $prev_gross_profit,
        'expenses' => $prev_expenses,
        'net_profit' => $prev_net_profit
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profit & Loss Statement - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
        .financial-statement { font-family: 'Courier New', monospace; }
        .statement-line { border-bottom: 1px solid #eee; padding: 8px 0; }
        .statement-total { border-top: 2px solid #333; font-weight: bold; }
        .positive { color: #28a745; }
        .negative { color: #dc3545; }
        .print-hide { display: block; }
        @media print {
            .print-hide { display: none; }
            .main-content { margin-left: 0; }
        }
    </style>
</head>
<body>
    <?php include '../include/navmenu.php'; ?>

    <div class="main-content">
        <header class="header print-hide">
            <div class="header-content">
                <div class="header-title">
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="index.php">Finance Dashboard</a></li>
                            <li class="breadcrumb-item"><a href="reports.php">Financial Reports</a></li>
                            <li class="breadcrumb-item active">Profit & Loss Statement</li>
                        </ol>
                    </nav>
                    <h1><i class="bi bi-graph-up"></i> Profit & Loss Statement</h1>
                    <p class="header-subtitle">Income statement for <?php echo htmlspecialchars($period_label); ?></p>
                </div>
                <div class="header-actions">
                    <button onclick="window.print()" class="btn btn-outline-primary">
                        <i class="bi bi-printer"></i> Print Report
                    </button>
                    <button onclick="exportToPDF()" class="btn btn-outline-success">
                        <i class="bi bi-download"></i> Export PDF
                    </button>
                </div>
            </div>
        </header>

        <main class="content">
            <div class="container-fluid">
                <!-- Filter Controls -->
                <div class="row mb-4 print-hide">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <form method="GET" class="row g-3">
                                    <div class="col-md-3">
                                        <label for="period" class="form-label">Period</label>
                                        <select id="period" name="period" class="form-select" onchange="toggleCustomDates()">
                                            <option value="current_month" <?php echo $period === 'current_month' ? 'selected' : ''; ?>>Current Month</option>
                                            <option value="last_month" <?php echo $period === 'last_month' ? 'selected' : ''; ?>>Last Month</option>
                                            <option value="current_year" <?php echo $period === 'current_year' ? 'selected' : ''; ?>>Current Year</option>
                                            <option value="last_year" <?php echo $period === 'last_year' ? 'selected' : ''; ?>>Last Year</option>
                                            <option value="custom" <?php echo $period === 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3" id="start-date-group" style="display: <?php echo $period === 'custom' ? 'block' : 'none'; ?>;">
                                        <label for="start_date" class="form-label">Start Date</label>
                                        <input type="date" id="start_date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($custom_start); ?>">
                                    </div>
                                    <div class="col-md-3" id="end-date-group" style="display: <?php echo $period === 'custom' ? 'block' : 'none'; ?>;">
                                        <label for="end_date" class="form-label">End Date</label>
                                        <input type="date" id="end_date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($custom_end); ?>">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">&nbsp;</label>
                                        <button type="submit" class="btn btn-primary d-block">Generate Report</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- P&L Statement -->
                <div class="row">
                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">
                                    <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?><br>
                                    <small class="text-muted">Profit & Loss Statement</small><br>
                                    <small class="text-muted">For the period: <?php echo htmlspecialchars($period_label); ?></small>
                                </h5>
                            </div>
                            <div class="card-body financial-statement">
                                <!-- REVENUE -->
                                <div class="statement-line">
                                    <div class="row">
                                        <div class="col-8"><strong>REVENUE</strong></div>
                                        <div class="col-4 text-end"><strong>Amount (<?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?>)</strong></div>
                                    </div>
                                </div>
                                
                                <div class="statement-line">
                                    <div class="row">
                                        <div class="col-8">Sales Revenue</div>
                                        <div class="col-4 text-end"><?php echo number_format($total_sales, 2); ?></div>
                                    </div>
                                </div>
                                
                                <div class="statement-line statement-total">
                                    <div class="row">
                                        <div class="col-8"><strong>Total Revenue</strong></div>
                                        <div class="col-4 text-end"><strong><?php echo number_format($total_sales, 2); ?></strong></div>
                                    </div>
                                </div>

                                <!-- COST OF GOODS SOLD -->
                                <div class="statement-line mt-3">
                                    <div class="row">
                                        <div class="col-8"><strong>COST OF GOODS SOLD</strong></div>
                                        <div class="col-4 text-end"></div>
                                    </div>
                                </div>
                                
                                <div class="statement-line">
                                    <div class="row">
                                        <div class="col-8">Cost of Products Sold</div>
                                        <div class="col-4 text-end"><?php echo number_format($cogs, 2); ?></div>
                                    </div>
                                </div>
                                
                                <div class="statement-line statement-total">
                                    <div class="row">
                                        <div class="col-8"><strong>Total COGS</strong></div>
                                        <div class="col-4 text-end"><strong><?php echo number_format($cogs, 2); ?></strong></div>
                                    </div>
                                </div>

                                <!-- GROSS PROFIT -->
                                <div class="statement-line statement-total mt-2">
                                    <div class="row">
                                        <div class="col-8"><strong>GROSS PROFIT</strong></div>
                                        <div class="col-4 text-end"><strong class="<?php echo $gross_profit >= 0 ? 'positive' : 'negative'; ?>"><?php echo number_format($gross_profit, 2); ?></strong></div>
                                    </div>
                                </div>

                                <!-- OPERATING EXPENSES -->
                                <div class="statement-line mt-3">
                                    <div class="row">
                                        <div class="col-8"><strong>OPERATING EXPENSES</strong></div>
                                        <div class="col-4 text-end"></div>
                                    </div>
                                </div>
                                
                                <?php foreach ($expense_categories as $expense): ?>
                                <div class="statement-line">
                                    <div class="row">
                                        <div class="col-8"><?php echo htmlspecialchars($expense['category_name']); ?></div>
                                        <div class="col-4 text-end"><?php echo number_format($expense['total'], 2); ?></div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                
                                <div class="statement-line statement-total">
                                    <div class="row">
                                        <div class="col-8"><strong>Total Operating Expenses</strong></div>
                                        <div class="col-4 text-end"><strong><?php echo number_format($operating_expenses, 2); ?></strong></div>
                                    </div>
                                </div>

                                <!-- NET PROFIT/LOSS -->
                                <div class="statement-line statement-total mt-2" style="border-top: 3px double #333;">
                                    <div class="row">
                                        <div class="col-8"><strong>NET <?php echo $net_profit >= 0 ? 'PROFIT' : 'LOSS'; ?></strong></div>
                                        <div class="col-4 text-end"><strong class="<?php echo $net_profit >= 0 ? 'positive' : 'negative'; ?>"><?php echo number_format($net_profit, 2); ?></strong></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-4">
                        <!-- Key Metrics -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h6 class="mb-0">Key Performance Indicators</h6>
                            </div>
                            <div class="card-body">
                                <div class="row mb-3">
                                    <div class="col-12">
                                        <small class="text-muted">Gross Margin</small>
                                        <h4 class="<?php echo $gross_margin >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo number_format($gross_margin, 1); ?>%</h4>
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-12">
                                        <small class="text-muted">Net Margin</small>
                                        <h4 class="<?php echo $net_margin >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo number_format($net_margin, 1); ?>%</h4>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-12">
                                        <small class="text-muted">Expense Ratio</small>
                                        <h4 class="text-warning"><?php echo $total_sales > 0 ? number_format(($operating_expenses / $total_sales) * 100, 1) : '0'; ?>%</h4>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Year-over-Year Comparison -->
                        <?php if (!empty($comparison_data)): ?>
                        <div class="card">
                            <div class="card-header">
                                <h6 class="mb-0">Year-over-Year Comparison</h6>
                            </div>
                            <div class="card-body">
                                <div class="row mb-2">
                                    <div class="col-8">Sales Growth</div>
                                    <div class="col-4 text-end">
                                        <?php 
                                        $sales_growth = $comparison_data['sales'] > 0 ? (($total_sales - $comparison_data['sales']) / $comparison_data['sales']) * 100 : 0;
                                        echo ($sales_growth >= 0 ? '+' : '') . number_format($sales_growth, 1) . '%';
                                        ?>
                                    </div>
                                </div>
                                <div class="row mb-2">
                                    <div class="col-8">Profit Growth</div>
                                    <div class="col-4 text-end">
                                        <?php 
                                        $profit_growth = $comparison_data['net_profit'] != 0 ? (($net_profit - $comparison_data['net_profit']) / abs($comparison_data['net_profit'])) * 100 : 0;
                                        echo ($profit_growth >= 0 ? '+' : '') . number_format($profit_growth, 1) . '%';
                                        ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleCustomDates() {
            const period = document.getElementById('period').value;
            const startGroup = document.getElementById('start-date-group');
            const endGroup = document.getElementById('end-date-group');
            
            if (period === 'custom') {
                startGroup.style.display = 'block';
                endGroup.style.display = 'block';
            } else {
                startGroup.style.display = 'none';
                endGroup.style.display = 'none';
            }
        }

        function exportToPDF() {
            alert('PDF export functionality will be implemented soon!');
        }
    </script>
</body>
</html>
