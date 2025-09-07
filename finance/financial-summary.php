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

// Date filters
$period = $_GET['period'] ?? 'monthly';
$custom_start = $_GET['start_date'] ?? '';
$custom_end = $_GET['end_date'] ?? '';

// Set date ranges based on period
switch ($period) {
    case 'today':
        $start_date = date('Y-m-d');
        $end_date = date('Y-m-d');
        $prev_start = date('Y-m-d', strtotime('-1 day'));
        $prev_end = date('Y-m-d', strtotime('-1 day'));
        break;
    case 'weekly':
        $start_date = date('Y-m-d', strtotime('monday this week'));
        $end_date = date('Y-m-d', strtotime('sunday this week'));
        $prev_start = date('Y-m-d', strtotime('monday last week'));
        $prev_end = date('Y-m-d', strtotime('sunday last week'));
        break;
    case 'monthly':
        $start_date = date('Y-m-01');
        $end_date = date('Y-m-t');
        $prev_start = date('Y-m-01', strtotime('first day of last month'));
        $prev_end = date('Y-m-t', strtotime('last day of last month'));
        break;
    case 'yearly':
        $start_date = date('Y-01-01');
        $end_date = date('Y-12-31');
        $prev_start = date('Y-01-01', strtotime('-1 year'));
        $prev_end = date('Y-12-31', strtotime('-1 year'));
        break;
    case 'custom':
        $start_date = $custom_start ?: date('Y-m-01');
        $end_date = $custom_end ?: date('Y-m-t');
        $prev_start = date('Y-m-d', strtotime($start_date . ' -1 month'));
        $prev_end = date('Y-m-d', strtotime($end_date . ' -1 month'));
        break;
    default:
        $start_date = date('Y-m-01');
        $end_date = date('Y-m-t');
        $prev_start = date('Y-m-01', strtotime('first day of last month'));
        $prev_end = date('Y-m-t', strtotime('last day of last month'));
}

// Initialize financial summary
$summary = [
    'revenue' => [
        'current' => 0,
        'previous' => 0,
        'growth' => 0
    ],
    'expenses' => [
        'current' => 0,
        'previous' => 0,
        'growth' => 0
    ],
    'profit' => [
        'current' => 0,
        'previous' => 0,
        'growth' => 0
    ],
    'transactions' => [
        'current' => 0,
        'previous' => 0,
        'growth' => 0
    ],
    'avg_transaction' => [
        'current' => 0,
        'previous' => 0,
        'growth' => 0
    ]
];

// Current period revenue
$stmt = $conn->prepare("
    SELECT COUNT(*) as count, COALESCE(SUM(final_amount), 0) as total 
    FROM sales 
    WHERE sale_date BETWEEN ? AND ?
");
$stmt->execute([$start_date, $end_date]);
$current_sales = $stmt->fetch(PDO::FETCH_ASSOC);
$summary['revenue']['current'] = $current_sales['total'];
$summary['transactions']['current'] = $current_sales['count'];
$summary['avg_transaction']['current'] = $current_sales['count'] > 0 ? $current_sales['total'] / $current_sales['count'] : 0;

// Previous period revenue
$stmt->execute([$prev_start, $prev_end]);
$prev_sales = $stmt->fetch(PDO::FETCH_ASSOC);
$summary['revenue']['previous'] = $prev_sales['total'];
$summary['transactions']['previous'] = $prev_sales['count'];
$summary['avg_transaction']['previous'] = $prev_sales['count'] > 0 ? $prev_sales['total'] / $prev_sales['count'] : 0;

// Current period expenses
try {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(total_amount), 0) as total 
        FROM expenses 
        WHERE expense_date BETWEEN ? AND ? AND approval_status = 'approved'
    ");
    $stmt->execute([$start_date, $end_date]);
    $summary['expenses']['current'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Previous period expenses
    $stmt->execute([$prev_start, $prev_end]);
    $summary['expenses']['previous'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
} catch (Exception $e) {
    $summary['expenses']['current'] = 0;
    $summary['expenses']['previous'] = 0;
}

// Calculate profits
$summary['profit']['current'] = $summary['revenue']['current'] - $summary['expenses']['current'];
$summary['profit']['previous'] = $summary['revenue']['previous'] - $summary['expenses']['previous'];

// Calculate growth percentages
foreach (['revenue', 'expenses', 'profit', 'transactions', 'avg_transaction'] as $metric) {
    if ($summary[$metric]['previous'] > 0) {
        $summary[$metric]['growth'] = (($summary[$metric]['current'] - $summary[$metric]['previous']) / $summary[$metric]['previous']) * 100;
    } else {
        $summary[$metric]['growth'] = $summary[$metric]['current'] > 0 ? 100 : 0;
    }
}

// Get top products for the period
$top_products = [];
$stmt = $conn->prepare("
    SELECT p.name, SUM(si.quantity) as total_sold, SUM(si.quantity * si.price) as revenue
    FROM products p
    JOIN sale_items si ON p.id = si.product_id
    JOIN sales s ON si.sale_id = s.id
    WHERE s.sale_date BETWEEN ? AND ?
    GROUP BY p.id, p.name
    ORDER BY revenue DESC
    LIMIT 5
");
$stmt->execute([$start_date, $end_date]);
$top_products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get payment method breakdown
$payment_breakdown = [];
$stmt = $conn->prepare("
    SELECT payment_method, COUNT(*) as count, SUM(final_amount) as total
    FROM sales 
    WHERE sale_date BETWEEN ? AND ?
    GROUP BY payment_method
    ORDER BY total DESC
");
$stmt->execute([$start_date, $end_date]);
$payment_breakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get daily trend for charts
$daily_trend = [];
$stmt = $conn->prepare("
    SELECT DATE(sale_date) as date, 
           COUNT(*) as transactions, 
           SUM(final_amount) as revenue
    FROM sales 
    WHERE sale_date BETWEEN ? AND ?
    GROUP BY DATE(sale_date)
    ORDER BY DATE(sale_date) ASC
");
$stmt->execute([$start_date, $end_date]);
$daily_trend = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get expense breakdown by category
$expense_breakdown = [];
try {
    $stmt = $conn->prepare("
        SELECT ec.name as expense_category, SUM(e.total_amount) as total
        FROM expenses e
        JOIN expense_categories ec ON e.category_id = ec.id
        WHERE e.expense_date BETWEEN ? AND ? AND e.approval_status = 'approved'
        GROUP BY ec.name
        ORDER BY total DESC
        LIMIT 5
    ");
    $stmt->execute([$start_date, $end_date]);
    $expense_breakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $expense_breakdown = [];
}

// Calculate key financial ratios
$profit_margin = $summary['revenue']['current'] > 0 ? ($summary['profit']['current'] / $summary['revenue']['current']) * 100 : 0;
$expense_ratio = $summary['revenue']['current'] > 0 ? ($summary['expenses']['current'] / $summary['revenue']['current']) * 100 : 0;

// Get inventory value
$inventory_value = 0;
$stmt = $conn->prepare("SELECT COALESCE(SUM(cost_price * quantity), 0) as value FROM products");
$stmt->execute();
$inventory_value = $stmt->fetch(PDO::FETCH_ASSOC)['value'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Summary - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
        .summary-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            transition: all 0.3s ease;
        }
        .summary-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0,0,0,0.1);
        }
        .summary-card.revenue {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
        }
        .summary-card.expense {
            background: linear-gradient(135deg, #fc466b 0%, #3f5efb 100%);
        }
        .summary-card.profit {
            background: linear-gradient(135deg, #fdbb2d 0%, #22c1c3 100%);
        }
        .summary-card.transactions {
            background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%);
            color: #333;
        }
        .growth-indicator {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8em;
            font-weight: bold;
        }
        .growth-positive { background: rgba(40, 167, 69, 0.2); color: #28a745; }
        .growth-negative { background: rgba(220, 53, 69, 0.2); color: #dc3545; }
        .growth-neutral { background: rgba(108, 117, 125, 0.2); color: #6c757d; }
        .chart-container { height: 300px; }
        .metric-card {
            border-left: 4px solid var(--primary-color);
            transition: all 0.3s ease;
        }
        .metric-card:hover {
            transform: translateX(5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .period-selector {
            background: var(--primary-color);
            color: white;
            border-radius: 10px;
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
                            <li class="breadcrumb-item"><a href="reports.php">Financial Reports</a></li>
                            <li class="breadcrumb-item active">Financial Summary</li>
                        </ol>
                    </nav>
                    <h1><i class="bi bi-graph-up"></i> Financial Summary</h1>
                    <p class="header-subtitle">Executive overview of key financial metrics</p>
                </div>
                <div class="header-actions">
                    <button class="btn btn-primary me-2" onclick="window.print()">
                        <i class="bi bi-printer me-1"></i> Print
                    </button>
                    <button class="btn btn-outline-success" onclick="exportSummary()">
                        <i class="bi bi-download me-1"></i> Export
                    </button>
                </div>
            </div>
        </header>

        <main class="content">
            <div class="container-fluid">
                <!-- Period Selection -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card period-selector">
                            <div class="card-body">
                                <form method="GET" class="row g-3 align-items-end">
                                    <div class="col-md-2">
                                        <label class="form-label text-white opacity-75">Period</label>
                                        <select class="form-select" name="period" onchange="this.form.submit()">
                                            <option value="today" <?php echo $period === 'today' ? 'selected' : ''; ?>>Today</option>
                                            <option value="weekly" <?php echo $period === 'weekly' ? 'selected' : ''; ?>>This Week</option>
                                            <option value="monthly" <?php echo $period === 'monthly' ? 'selected' : ''; ?>>This Month</option>
                                            <option value="yearly" <?php echo $period === 'yearly' ? 'selected' : ''; ?>>This Year</option>
                                            <option value="custom" <?php echo $period === 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                                        </select>
                                    </div>
                                    <?php if ($period === 'custom'): ?>
                                    <div class="col-md-2">
                                        <label class="form-label text-white opacity-75">Start Date</label>
                                        <input type="date" class="form-select" name="start_date" value="<?php echo $custom_start; ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label text-white opacity-75">End Date</label>
                                        <input type="date" class="form-select" name="end_date" value="<?php echo $custom_end; ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <button type="submit" class="btn btn-light fw-bold">Update</button>
                                    </div>
                                    <?php endif; ?>
                                    <div class="col-md-4">
                                        <div class="text-white opacity-75">
                                            <small>Period: <?php echo date('M d, Y', strtotime($start_date)); ?> to <?php echo date('M d, Y', strtotime($end_date)); ?></small>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Key Metrics Cards -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="card summary-card revenue h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div class="flex-grow-1">
                                        <h6 class="card-title opacity-75 mb-2">Total Revenue</h6>
                                        <h2 class="mb-2"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($summary['revenue']['current'], 2); ?></h2>
                                        <div class="growth-indicator <?php echo $summary['revenue']['growth'] >= 0 ? 'growth-positive' : 'growth-negative'; ?>">
                                            <i class="bi bi-arrow-<?php echo $summary['revenue']['growth'] >= 0 ? 'up' : 'down'; ?>"></i>
                                            <?php echo number_format(abs($summary['revenue']['growth']), 1); ?>%
                                        </div>
                                    </div>
                                    <div class="text-end opacity-75">
                                        <i class="bi bi-currency-dollar fs-1"></i>
                                    </div>
                                </div>
                                <div class="mt-3 pt-3 border-top border-light border-opacity-25">
                                    <small class="opacity-75">Previous: <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($summary['revenue']['previous'], 2); ?></small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="card summary-card expense h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div class="flex-grow-1">
                                        <h6 class="card-title opacity-75 mb-2">Total Expenses</h6>
                                        <h2 class="mb-2"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($summary['expenses']['current'], 2); ?></h2>
                                        <div class="growth-indicator <?php echo $summary['expenses']['growth'] <= 0 ? 'growth-positive' : 'growth-negative'; ?>">
                                            <i class="bi bi-arrow-<?php echo $summary['expenses']['growth'] >= 0 ? 'up' : 'down'; ?>"></i>
                                            <?php echo number_format(abs($summary['expenses']['growth']), 1); ?>%
                                        </div>
                                    </div>
                                    <div class="text-end opacity-75">
                                        <i class="bi bi-cash-stack fs-1"></i>
                                    </div>
                                </div>
                                <div class="mt-3 pt-3 border-top border-light border-opacity-25">
                                    <small class="opacity-75">Previous: <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($summary['expenses']['previous'], 2); ?></small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="card summary-card profit h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div class="flex-grow-1">
                                        <h6 class="card-title opacity-75 mb-2">Net Profit</h6>
                                        <h2 class="mb-2"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($summary['profit']['current'], 2); ?></h2>
                                        <div class="growth-indicator <?php echo $summary['profit']['growth'] >= 0 ? 'growth-positive' : 'growth-negative'; ?>">
                                            <i class="bi bi-arrow-<?php echo $summary['profit']['growth'] >= 0 ? 'up' : 'down'; ?>"></i>
                                            <?php echo number_format(abs($summary['profit']['growth']), 1); ?>%
                                        </div>
                                    </div>
                                    <div class="text-end opacity-75">
                                        <i class="bi bi-trophy fs-1"></i>
                                    </div>
                                </div>
                                <div class="mt-3 pt-3 border-top border-light border-opacity-25">
                                    <small class="opacity-75">Margin: <?php echo number_format($profit_margin, 1); ?>%</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="card summary-card transactions h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div class="flex-grow-1">
                                        <h6 class="card-title opacity-75 mb-2">Transactions</h6>
                                        <h2 class="mb-2"><?php echo number_format($summary['transactions']['current']); ?></h2>
                                        <div class="growth-indicator <?php echo $summary['transactions']['growth'] >= 0 ? 'growth-positive' : 'growth-negative'; ?>">
                                            <i class="bi bi-arrow-<?php echo $summary['transactions']['growth'] >= 0 ? 'up' : 'down'; ?>"></i>
                                            <?php echo number_format(abs($summary['transactions']['growth']), 1); ?>%
                                        </div>
                                    </div>
                                    <div class="text-end opacity-75">
                                        <i class="bi bi-receipt fs-1"></i>
                                    </div>
                                </div>
                                <div class="mt-3 pt-3 border-top border-dark border-opacity-25">
                                    <small class="opacity-75">Avg: <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($summary['avg_transaction']['current'], 2); ?></small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Charts and Analysis Row -->
                <div class="row mb-4">
                    <!-- Revenue Trend Chart -->
                    <div class="col-lg-8 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="bi bi-graph-up me-2"></i>Revenue Trend</h6>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="revenueChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Key Ratios -->
                    <div class="col-lg-4 mb-4">
                        <div class="card h-100">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="bi bi-calculator me-2"></i>Key Ratios</h6>
                            </div>
                            <div class="card-body">
                                <div class="metric-card p-3 mb-3 bg-light">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="fw-bold">Profit Margin</span>
                                        <span class="fs-5 text-primary"><?php echo number_format($profit_margin, 1); ?>%</span>
                                    </div>
                                </div>
                                
                                <div class="metric-card p-3 mb-3 bg-light">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="fw-bold">Expense Ratio</span>
                                        <span class="fs-5 text-danger"><?php echo number_format($expense_ratio, 1); ?>%</span>
                                    </div>
                                </div>
                                
                                <div class="metric-card p-3 mb-3 bg-light">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="fw-bold">Avg Transaction</span>
                                        <span class="fs-5 text-info"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($summary['avg_transaction']['current'], 0); ?></span>
                                    </div>
                                </div>
                                
                                <div class="metric-card p-3 bg-light">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="fw-bold">Inventory Value</span>
                                        <span class="fs-5 text-success"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($inventory_value, 0); ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Detailed Breakdowns Row -->
                <div class="row mb-4">
                    <!-- Top Products -->
                    <div class="col-lg-4 mb-4">
                        <div class="card h-100">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="bi bi-star me-2"></i>Top Products</h6>
                            </div>
                            <div class="card-body">
                                <?php foreach ($top_products as $product): ?>
                                <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
                                    <div>
                                        <div class="fw-bold"><?php echo htmlspecialchars($product['name']); ?></div>
                                        <small class="text-muted"><?php echo $product['total_sold']; ?> units sold</small>
                                    </div>
                                    <div class="text-end">
                                        <div class="fw-bold text-success"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($product['revenue'], 0); ?></div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Methods -->
                    <div class="col-lg-4 mb-4">
                        <div class="card h-100">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="bi bi-credit-card me-2"></i>Payment Methods</h6>
                            </div>
                            <div class="card-body">
                                <?php foreach ($payment_breakdown as $payment): ?>
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <div>
                                        <div class="fw-bold text-capitalize"><?php echo htmlspecialchars($payment['payment_method']); ?></div>
                                        <small class="text-muted"><?php echo $payment['count']; ?> transactions</small>
                                    </div>
                                    <div class="text-end">
                                        <div class="fw-bold"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($payment['total'], 0); ?></div>
                                        <small class="text-muted">
                                            <?php echo $summary['revenue']['current'] > 0 ? number_format(($payment['total'] / $summary['revenue']['current']) * 100, 1) : 0; ?>%
                                        </small>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Expense Categories -->
                    <div class="col-lg-4 mb-4">
                        <div class="card h-100">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="bi bi-pie-chart me-2"></i>Top Expenses</h6>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($expense_breakdown)): ?>
                                    <?php foreach ($expense_breakdown as $expense): ?>
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <div>
                                            <div class="fw-bold text-capitalize"><?php echo htmlspecialchars(str_replace('_', ' ', $expense['expense_category'])); ?></div>
                                        </div>
                                        <div class="text-end">
                                            <div class="fw-bold text-danger"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($expense['total'], 0); ?></div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                <div class="text-center text-muted py-3">
                                    <i class="bi bi-info-circle"></i>
                                    <p class="mb-0">No expense data available</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Revenue Trend Chart
        const revenueCtx = document.getElementById('revenueChart').getContext('2d');
        const revenueChart = new Chart(revenueCtx, {
            type: 'line',
            data: {
                labels: [<?php foreach ($daily_trend as $day): ?>'<?php echo date('M d', strtotime($day['date'])); ?>',<?php endforeach; ?>],
                datasets: [{
                    label: 'Daily Revenue',
                    data: [<?php foreach ($daily_trend as $day): ?><?php echo $day['revenue']; ?>,<?php endforeach; ?>],
                    borderColor: '#11998e',
                    backgroundColor: 'rgba(17, 153, 142, 0.1)',
                    tension: 0.4,
                    fill: true
                }, {
                    label: 'Transactions',
                    data: [<?php foreach ($daily_trend as $day): ?><?php echo $day['transactions']; ?>,<?php endforeach; ?>],
                    borderColor: '#fc466b',
                    backgroundColor: 'rgba(252, 70, 107, 0.1)',
                    tension: 0.4,
                    yAxisID: 'y1'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    intersect: false,
                    mode: 'index'
                },
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Revenue'
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Transactions'
                        },
                        grid: {
                            drawOnChartArea: false,
                        },
                    }
                }
            }
        });

        function exportSummary() {
            let csv = 'Financial Summary Report\n';
            csv += 'Period: <?php echo $start_date; ?> to <?php echo $end_date; ?>\n';
            csv += 'Generated: <?php echo date('Y-m-d H:i:s'); ?>\n\n';
            
            csv += 'KEY METRICS\n';
            csv += 'Total Revenue,<?php echo $summary['revenue']['current']; ?>\n';
            csv += 'Total Expenses,<?php echo $summary['expenses']['current']; ?>\n';
            csv += 'Net Profit,<?php echo $summary['profit']['current']; ?>\n';
            csv += 'Total Transactions,<?php echo $summary['transactions']['current']; ?>\n';
            csv += 'Average Transaction,<?php echo $summary['avg_transaction']['current']; ?>\n';
            csv += 'Profit Margin,<?php echo $profit_margin; ?>%\n';
            csv += 'Expense Ratio,<?php echo $expense_ratio; ?>%\n\n';
            
            csv += 'TOP PRODUCTS\n';
            csv += 'Product,Units Sold,Revenue\n';
            <?php foreach ($top_products as $product): ?>
            csv += '<?php echo addslashes($product['name']); ?>,<?php echo $product['total_sold']; ?>,<?php echo $product['revenue']; ?>\n';
            <?php endforeach; ?>
            
            csv += '\nPAYMENT METHODS\n';
            csv += 'Method,Transactions,Amount\n';
            <?php foreach ($payment_breakdown as $payment): ?>
            csv += '<?php echo addslashes($payment['payment_method']); ?>,<?php echo $payment['count']; ?>,<?php echo $payment['total']; ?>\n';
            <?php endforeach; ?>
            
            // Download
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.setAttribute('hidden', '');
            a.setAttribute('href', url);
            a.setAttribute('download', 'financial-summary-<?php echo $start_date; ?>-to-<?php echo $end_date; ?>.csv');
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        }
    </script>
</body>
</html>
