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

// Check permissions
$hasAccess = hasPermission('view_finance', $permissions) || 
             hasPermission('manage_sales', $permissions) || 
             hasPermission('view_analytics', $permissions) ||
             hasPermission('manage_users', $permissions);

if (!$hasAccess) {
    header('Location: ../dashboard/dashboard.php');
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get date range parameters
$start_date = $_GET['start_date'] ?? date('Y-m-01'); // First day of current month
$end_date = $_GET['end_date'] ?? date('Y-m-t'); // Last day of current month
$period = $_GET['period'] ?? 'monthly';

// Validate dates
$start_date = date('Y-m-d', strtotime($start_date));
$end_date = date('Y-m-d', strtotime($end_date));

// Cash Flow Data Functions
function getCashInflows($conn, $start_date, $end_date) {
    $inflows = [];
    
    // Sales Revenue
    $stmt = $conn->prepare("
        SELECT 
            DATE(sale_date) as transaction_date,
            SUM(final_amount) as amount,
            'Sales Revenue' as category,
            'Operating' as activity_type
        FROM sales 
        WHERE DATE(sale_date) BETWEEN :start_date AND :end_date
        GROUP BY DATE(sale_date)
        ORDER BY transaction_date
    ");
    $stmt->bindParam(':start_date', $start_date);
    $stmt->bindParam(':end_date', $end_date);
    $stmt->execute();
    $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($sales as $sale) {
        $inflows[] = $sale;
    }
    
    // Add other potential inflows (loans, investments, etc.)
    // This would be expanded based on your specific business needs
    
    return $inflows;
}

function getCashOutflows($conn, $start_date, $end_date) {
    $outflows = [];
    
    // Operating Expenses
    $stmt = $conn->prepare("
        SELECT 
            DATE(expense_date) as transaction_date,
            SUM(total_amount) as amount,
            'Operating Expenses' as category,
            'Operating' as activity_type
        FROM expenses 
        WHERE DATE(expense_date) BETWEEN :start_date AND :end_date
        AND approval_status = 'approved'
        GROUP BY DATE(expense_date)
        ORDER BY transaction_date
    ");
    $stmt->bindParam(':start_date', $start_date);
    $stmt->bindParam(':end_date', $end_date);
    $stmt->execute();
    $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($expenses as $expense) {
        $outflows[] = $expense;
    }
    
    // Inventory Purchases (if you have purchase orders)
    try {
        $stmt = $conn->prepare("
            SELECT 
                DATE(created_at) as transaction_date,
                SUM(total_amount) as amount,
                'Inventory Purchases' as category,
                'Operating' as activity_type
            FROM purchase_orders 
            WHERE DATE(created_at) BETWEEN :start_date AND :end_date
            AND status = 'completed'
            GROUP BY DATE(created_at)
            ORDER BY transaction_date
        ");
        $stmt->bindParam(':start_date', $start_date);
        $stmt->bindParam(':end_date', $end_date);
        $stmt->execute();
        $purchases = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($purchases as $purchase) {
            $outflows[] = $purchase;
        }
    } catch (Exception $e) {
        // Purchase orders table might not exist
    }
    
    return $outflows;
}

function calculateCashFlowMetrics($inflows, $outflows) {
    $metrics = [];
    
    // Calculate totals
    $total_inflows = array_sum(array_column($inflows, 'amount'));
    $total_outflows = array_sum(array_column($outflows, 'amount'));
    $net_cash_flow = $total_inflows - $total_outflows;
    
    // Calculate by activity type
    $operating_inflows = array_sum(array_column(array_filter($inflows, function($item) {
        return $item['activity_type'] === 'Operating';
    }), 'amount'));
    
    $operating_outflows = array_sum(array_column(array_filter($outflows, function($item) {
        return $item['activity_type'] === 'Operating';
    }), 'amount'));
    
    $operating_cash_flow = $operating_inflows - $operating_outflows;
    
    $metrics = [
        'total_inflows' => $total_inflows,
        'total_outflows' => $total_outflows,
        'net_cash_flow' => $net_cash_flow,
        'operating_inflows' => $operating_inflows,
        'operating_outflows' => $operating_outflows,
        'operating_cash_flow' => $operating_cash_flow,
        'inflow_count' => count($inflows),
        'outflow_count' => count($outflows)
    ];
    
    return $metrics;
}

function getCashFlowTrends($conn, $start_date, $end_date, $period = 'daily') {
    $trends = [];
    
    // Generate date range
    $current = strtotime($start_date);
    $end = strtotime($end_date);
    
    while ($current <= $end) {
        $date = date('Y-m-d', $current);
        
        // Get inflows for this date
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(final_amount), 0) as amount
            FROM sales 
            WHERE DATE(sale_date) = :date
        ");
        $stmt->bindParam(':date', $date);
        $stmt->execute();
        $inflows = $stmt->fetch(PDO::FETCH_ASSOC)['amount'];
        
        // Get outflows for this date
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(total_amount), 0) as amount
            FROM expenses 
            WHERE DATE(expense_date) = :date
            AND approval_status = 'approved'
        ");
        $stmt->bindParam(':date', $date);
        $stmt->execute();
        $outflows = $stmt->fetch(PDO::FETCH_ASSOC)['amount'];
        
        $trends[] = [
            'date' => $date,
            'inflows' => $inflows,
            'outflows' => $outflows,
            'net_flow' => $inflows - $outflows
        ];
        
        $current = strtotime('+1 day', $current);
    }
    
    return $trends;
}

// Get cash flow data
$inflows = getCashInflows($conn, $start_date, $end_date);
$outflows = getCashOutflows($conn, $start_date, $end_date);
$metrics = calculateCashFlowMetrics($inflows, $outflows);
$trends = getCashFlowTrends($conn, $start_date, $end_date, $period);

// Calculate running balance
$running_balance = 0;
$balance_trends = [];
foreach ($trends as $trend) {
    $running_balance += $trend['net_flow'];
    $balance_trends[] = [
        'date' => $trend['date'],
        'balance' => $running_balance
    ];
}

// Cash Flow Forecasting Functions
function generateCashFlowForecast($conn, $start_date, $end_date, $forecast_days = 30) {
    $forecast = [];
    
    // Get historical data for trend analysis
    $historical_start = date('Y-m-d', strtotime($start_date . ' -30 days'));
    $historical_end = $end_date;
    
    $stmt = $conn->prepare("
        SELECT 
            DATE(sale_date) as date,
            COALESCE(SUM(final_amount), 0) as daily_sales
        FROM sales 
        WHERE DATE(sale_date) BETWEEN :start_date AND :end_date
        GROUP BY DATE(sale_date)
        ORDER BY date
    ");
    $stmt->bindParam(':start_date', $historical_start);
    $stmt->bindParam(':end_date', $historical_end);
    $stmt->execute();
    $historical_sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $conn->prepare("
        SELECT 
            DATE(expense_date) as date,
            COALESCE(SUM(total_amount), 0) as daily_expenses
        FROM expenses 
        WHERE DATE(expense_date) BETWEEN :start_date AND :end_date
        AND approval_status = 'approved'
        GROUP BY DATE(expense_date)
        ORDER BY date
    ");
    $stmt->bindParam(':start_date', $historical_start);
    $stmt->bindParam(':end_date', $historical_end);
    $stmt->execute();
    $historical_expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate average daily flows
    $avg_daily_sales = count($historical_sales) > 0 ? array_sum(array_column($historical_sales, 'daily_sales')) / count($historical_sales) : 0;
    $avg_daily_expenses = count($historical_expenses) > 0 ? array_sum(array_column($historical_expenses, 'daily_expenses')) / count($historical_expenses) : 0;
    
    // Generate forecast
    $current_date = strtotime($end_date . ' +1 day');
    for ($i = 0; $i < $forecast_days; $i++) {
        $forecast_date = date('Y-m-d', $current_date);
        
        // Simple trend-based forecast (can be enhanced with more sophisticated algorithms)
        $forecast_sales = $avg_daily_sales * (1 + (rand(-10, 10) / 100)); // ±10% variation
        $forecast_expenses = $avg_daily_expenses * (1 + (rand(-5, 5) / 100)); // ±5% variation
        
        $forecast[] = [
            'date' => $forecast_date,
            'inflows' => round($forecast_sales, 2),
            'outflows' => round($forecast_expenses, 2),
            'net_flow' => round($forecast_sales - $forecast_expenses, 2),
            'is_forecast' => true
        ];
        
        $current_date = strtotime('+1 day', $current_date);
    }
    
    return $forecast;
}

function getCashFlowInsights($metrics, $trends) {
    $insights = [];
    
    // Cash flow health
    if ($metrics['net_cash_flow'] > 0) {
        $insights[] = [
            'type' => 'positive',
            'title' => 'Positive Cash Flow',
            'message' => 'Your business is generating positive cash flow, which is excellent for growth and stability.'
        ];
    } else {
        $insights[] = [
            'type' => 'warning',
            'title' => 'Negative Cash Flow',
            'message' => 'Your business has negative cash flow. Consider reviewing expenses and increasing revenue.'
        ];
    }
    
    // Operating efficiency
    $operating_ratio = $metrics['operating_outflows'] > 0 ? $metrics['operating_inflows'] / $metrics['operating_outflows'] : 0;
    if ($operating_ratio > 1.5) {
        $insights[] = [
            'type' => 'success',
            'title' => 'High Operating Efficiency',
            'message' => 'Your operating cash flow ratio is strong, indicating efficient operations.'
        ];
    } elseif ($operating_ratio < 1) {
        $insights[] = [
            'type' => 'danger',
            'title' => 'Low Operating Efficiency',
            'message' => 'Operating expenses exceed operating income. Review your cost structure.'
        ];
    }
    
    // Trend analysis
    if (count($trends) >= 7) {
        $recent_trends = array_slice($trends, -7);
        $trend_direction = 0;
        for ($i = 1; $i < count($recent_trends); $i++) {
            $trend_direction += $recent_trends[$i]['net_flow'] - $recent_trends[$i-1]['net_flow'];
        }
        
        if ($trend_direction > 0) {
            $insights[] = [
                'type' => 'info',
                'title' => 'Improving Trend',
                'message' => 'Your cash flow is showing an improving trend over the past week.'
            ];
        } elseif ($trend_direction < 0) {
            $insights[] = [
                'type' => 'warning',
                'title' => 'Declining Trend',
                'message' => 'Your cash flow is showing a declining trend. Monitor closely.'
            ];
        }
    }
    
    return $insights;
}

// Generate forecast and insights
$forecast = generateCashFlowForecast($conn, $start_date, $end_date, 30);
$insights = getCashFlowInsights($metrics, $trends);

// Cash Flow Statement Categories
function categorizeCashFlow($inflows, $outflows) {
    $statement = [
        'operating' => [
            'inflows' => [],
            'outflows' => [],
            'net' => 0
        ],
        'investing' => [
            'inflows' => [],
            'outflows' => [],
            'net' => 0
        ],
        'financing' => [
            'inflows' => [],
            'outflows' => [],
            'net' => 0
        ]
    ];
    
    // Categorize inflows
    foreach ($inflows as $inflow) {
        $category = $inflow['activity_type'] === 'Operating' ? 'operating' : 
                   (strpos($inflow['category'], 'Investment') !== false ? 'investing' : 'financing');
        $statement[$category]['inflows'][] = $inflow;
    }
    
    // Categorize outflows
    foreach ($outflows as $outflow) {
        $category = $outflow['activity_type'] === 'Operating' ? 'operating' : 
                   (strpos($outflow['category'], 'Investment') !== false ? 'investing' : 'financing');
        $statement[$category]['outflows'][] = $outflow;
    }
    
    // Calculate net for each category
    foreach ($statement as $category => $data) {
        $inflow_total = array_sum(array_column($data['inflows'], 'amount'));
        $outflow_total = array_sum(array_column($data['outflows'], 'amount'));
        $statement[$category]['net'] = $inflow_total - $outflow_total;
    }
    
    return $statement;
}

$cash_flow_statement = categorizeCashFlow($inflows, $outflows);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cash Flow Analysis - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
        
        .cashflow-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1rem;
        }
        
        .cashflow-card.inflow {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
        }
        
        .cashflow-card.outflow {
            background: linear-gradient(135deg, #fc466b 0%, #3f5efb 100%);
        }
        
        .cashflow-card.net {
            background: linear-gradient(135deg, #fdbb2d 0%, #22c1c3 100%);
        }
        
        .chart-container {
            position: relative;
            height: 400px;
            margin: 20px 0;
        }
        
        .filter-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .transaction-item {
            border-left: 4px solid #28a745;
            padding: 1rem;
            margin-bottom: 0.5rem;
            background: #f8f9fa;
            border-radius: 0 8px 8px 0;
        }
        
        .transaction-item.outflow {
            border-left-color: #dc3545;
        }
        
        .metric-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 1rem;
        }
        
        .trend-up {
            color: #28a745;
        }
        
        .trend-down {
            color: #dc3545;
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
                            <li class="breadcrumb-item active">Cash Flow Analysis</li>
                        </ol>
                    </nav>
                    <h1><i class="bi bi-currency-exchange"></i> Cash Flow Analysis</h1>
                    <p class="header-subtitle">Monitor cash inflows, outflows and liquidity positions</p>
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
                <!-- Date Range Filter -->
                <div class="filter-card">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label for="start_date" class="form-label">Start Date</label>
                            <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo $start_date; ?>">
                        </div>
                        <div class="col-md-3">
                            <label for="end_date" class="form-label">End Date</label>
                            <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo $end_date; ?>">
                        </div>
                        <div class="col-md-3">
                            <label for="period" class="form-label">Period</label>
                            <select class="form-select" id="period" name="period">
                                <option value="daily" <?php echo $period === 'daily' ? 'selected' : ''; ?>>Daily</option>
                                <option value="weekly" <?php echo $period === 'weekly' ? 'selected' : ''; ?>>Weekly</option>
                                <option value="monthly" <?php echo $period === 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">&nbsp;</label>
                            <div>
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-search"></i> Update Analysis
                                </button>
                                <a href="?start_date=<?php echo date('Y-m-01'); ?>&end_date=<?php echo date('Y-m-t'); ?>&period=monthly" class="btn btn-outline-secondary">
                                    <i class="bi bi-calendar-month"></i> This Month
                                </a>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Key Metrics -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="cashflow-card inflow">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Total Cash Inflows</h6>
                                <i class="bi bi-arrow-up-circle fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($metrics['total_inflows'], 2); ?></h3>
                            <small class="opacity-75"><?php echo $metrics['inflow_count']; ?> transactions</small>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="cashflow-card outflow">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Total Cash Outflows</h6>
                                <i class="bi bi-arrow-down-circle fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($metrics['total_outflows'], 2); ?></h3>
                            <small class="opacity-75"><?php echo $metrics['outflow_count']; ?> transactions</small>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="cashflow-card net">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Net Cash Flow</h6>
                                <i class="bi bi-<?php echo $metrics['net_cash_flow'] >= 0 ? 'trending-up' : 'trending-down'; ?> fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($metrics['net_cash_flow'], 2); ?></h3>
                            <small class="opacity-75"><?php echo $metrics['net_cash_flow'] >= 0 ? 'Positive' : 'Negative'; ?> flow</small>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="cashflow-card">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Operating Cash Flow</h6>
                                <i class="bi bi-cash-coin fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($metrics['operating_cash_flow'], 2); ?></h3>
                            <small class="opacity-75">Core business operations</small>
                        </div>
                    </div>
                </div>

                <!-- Charts Section -->
                <div class="row mb-4">
                    <div class="col-lg-8">
                        <div class="metric-card">
                            <h5><i class="bi bi-graph-up"></i> Cash Flow Trends</h5>
                            <div class="chart-container">
                                <canvas id="cashFlowChart"></canvas>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="metric-card">
                            <h5><i class="bi bi-pie-chart"></i> Cash Flow Breakdown</h5>
                            <div class="chart-container">
                                <canvas id="cashFlowPieChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Running Balance Chart -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="metric-card">
                            <h5><i class="bi bi-graph-down"></i> Running Cash Balance</h5>
                            <div class="chart-container">
                                <canvas id="balanceChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Cash Flow Forecast -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="metric-card">
                            <h5><i class="bi bi-crystal-ball"></i> 30-Day Cash Flow Forecast</h5>
                            <div class="chart-container">
                                <canvas id="forecastChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Cash Flow Statement -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="metric-card">
                            <h5><i class="bi bi-file-text"></i> Cash Flow Statement</h5>
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>Activity</th>
                                            <th class="text-end">Inflows</th>
                                            <th class="text-end">Outflows</th>
                                            <th class="text-end">Net Cash Flow</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td><strong>Operating Activities</strong></td>
                                            <td class="text-end text-success"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format(array_sum(array_column($cash_flow_statement['operating']['inflows'], 'amount')), 2); ?></td>
                                            <td class="text-end text-danger"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format(array_sum(array_column($cash_flow_statement['operating']['outflows'], 'amount')), 2); ?></td>
                                            <td class="text-end <?php echo $cash_flow_statement['operating']['net'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                <strong><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($cash_flow_statement['operating']['net'], 2); ?></strong>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td><strong>Investing Activities</strong></td>
                                            <td class="text-end text-success"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format(array_sum(array_column($cash_flow_statement['investing']['inflows'], 'amount')), 2); ?></td>
                                            <td class="text-end text-danger"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format(array_sum(array_column($cash_flow_statement['investing']['outflows'], 'amount')), 2); ?></td>
                                            <td class="text-end <?php echo $cash_flow_statement['investing']['net'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                <strong><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($cash_flow_statement['investing']['net'], 2); ?></strong>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td><strong>Financing Activities</strong></td>
                                            <td class="text-end text-success"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format(array_sum(array_column($cash_flow_statement['financing']['inflows'], 'amount')), 2); ?></td>
                                            <td class="text-end text-danger"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format(array_sum(array_column($cash_flow_statement['financing']['outflows'], 'amount')), 2); ?></td>
                                            <td class="text-end <?php echo $cash_flow_statement['financing']['net'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                <strong><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($cash_flow_statement['financing']['net'], 2); ?></strong>
                                            </td>
                                        </tr>
                                        <tr class="table-dark">
                                            <td><strong>Net Change in Cash</strong></td>
                                            <td class="text-end"><strong><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($metrics['total_inflows'], 2); ?></strong></td>
                                            <td class="text-end"><strong><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($metrics['total_outflows'], 2); ?></strong></td>
                                            <td class="text-end <?php echo $metrics['net_cash_flow'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                <strong><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($metrics['net_cash_flow'], 2); ?></strong>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Insights and Recommendations -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="metric-card">
                            <h5><i class="bi bi-lightbulb"></i> Insights & Recommendations</h5>
                            <div class="row">
                                <?php foreach ($insights as $insight): ?>
                                    <div class="col-md-6 mb-3">
                                        <div class="alert alert-<?php echo $insight['type'] === 'positive' ? 'success' : ($insight['type'] === 'warning' ? 'warning' : ($insight['type'] === 'danger' ? 'danger' : 'info')); ?>">
                                            <h6 class="alert-heading">
                                                <i class="bi bi-<?php echo $insight['type'] === 'positive' ? 'check-circle' : ($insight['type'] === 'warning' ? 'exclamation-triangle' : ($insight['type'] === 'danger' ? 'x-circle' : 'info-circle')); ?>"></i>
                                                <?php echo htmlspecialchars($insight['title']); ?>
                                            </h6>
                                            <p class="mb-0"><?php echo htmlspecialchars($insight['message']); ?></p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Detailed Transactions -->
                <div class="row">
                    <div class="col-lg-6">
                        <div class="metric-card">
                            <h5><i class="bi bi-arrow-up-circle text-success"></i> Cash Inflows</h5>
                            <div style="max-height: 400px; overflow-y: auto;">
                                <?php if (empty($inflows)): ?>
                                    <p class="text-muted text-center py-3">No cash inflows in the selected period</p>
                                <?php else: ?>
                                    <?php foreach ($inflows as $inflow): ?>
                                        <div class="transaction-item">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <strong><?php echo htmlspecialchars($inflow['category']); ?></strong>
                                                    <br>
                                                    <small class="text-muted"><?php echo date('M d, Y', strtotime($inflow['transaction_date'])); ?></small>
                                                </div>
                                                <div class="text-end">
                                                    <strong class="text-success">+<?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($inflow['amount'], 2); ?></strong>
                                                    <br>
                                                    <small class="text-muted"><?php echo htmlspecialchars($inflow['activity_type']); ?></small>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-6">
                        <div class="metric-card">
                            <h5><i class="bi bi-arrow-down-circle text-danger"></i> Cash Outflows</h5>
                            <div style="max-height: 400px; overflow-y: auto;">
                                <?php if (empty($outflows)): ?>
                                    <p class="text-muted text-center py-3">No cash outflows in the selected period</p>
                                <?php else: ?>
                                    <?php foreach ($outflows as $outflow): ?>
                                        <div class="transaction-item outflow">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <strong><?php echo htmlspecialchars($outflow['category']); ?></strong>
                                                    <br>
                                                    <small class="text-muted"><?php echo date('M d, Y', strtotime($outflow['transaction_date'])); ?></small>
                                                </div>
                                                <div class="text-end">
                                                    <strong class="text-danger">-<?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($outflow['amount'], 2); ?></strong>
                                                    <br>
                                                    <small class="text-muted"><?php echo htmlspecialchars($outflow['activity_type']); ?></small>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="row mt-4">
                    <div class="col-12 text-center">
                        <a href="index.php" class="btn btn-outline-primary me-2">
                            <i class="bi bi-arrow-left"></i> Back to Finance Dashboard
                        </a>
                        <button class="btn btn-primary" onclick="exportCashFlow()">
                            <i class="bi bi-download"></i> Export Report
                        </button>
                        <button class="btn btn-success" onclick="printCashFlow()">
                            <i class="bi bi-printer"></i> Print Report
                        </button>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Cash Flow Trends Chart
        const trendsData = <?php echo json_encode($trends); ?>;
        const balanceData = <?php echo json_encode($balance_trends); ?>;
        const forecastData = <?php echo json_encode($forecast); ?>;
        
        const ctx1 = document.getElementById('cashFlowChart').getContext('2d');
        new Chart(ctx1, {
            type: 'line',
            data: {
                labels: trendsData.map(item => new Date(item.date).toLocaleDateString()),
                datasets: [{
                    label: 'Cash Inflows',
                    data: trendsData.map(item => item.inflows),
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                    tension: 0.4
                }, {
                    label: 'Cash Outflows',
                    data: trendsData.map(item => item.outflows),
                    borderColor: '#dc3545',
                    backgroundColor: 'rgba(220, 53, 69, 0.1)',
                    tension: 0.4
                }, {
                    label: 'Net Flow',
                    data: trendsData.map(item => item.net_flow),
                    borderColor: '#007bff',
                    backgroundColor: 'rgba(0, 123, 255, 0.1)',
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '<?php echo $settings['currency_symbol'] ?? 'KES'; ?> ' + value.toLocaleString();
                            }
                        }
                    }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': <?php echo $settings['currency_symbol'] ?? 'KES'; ?> ' + context.parsed.y.toLocaleString();
                            }
                        }
                    }
                }
            }
        });

        // Cash Flow Pie Chart
        const ctx2 = document.getElementById('cashFlowPieChart').getContext('2d');
        new Chart(ctx2, {
            type: 'doughnut',
            data: {
                labels: ['Cash Inflows', 'Cash Outflows'],
                datasets: [{
                    data: [<?php echo $metrics['total_inflows']; ?>, <?php echo $metrics['total_outflows']; ?>],
                    backgroundColor: ['#28a745', '#dc3545'],
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.label + ': <?php echo $settings['currency_symbol'] ?? 'KES'; ?> ' + context.parsed.toLocaleString();
                            }
                        }
                    }
                }
            }
        });

        // Running Balance Chart
        const ctx3 = document.getElementById('balanceChart').getContext('2d');
        new Chart(ctx3, {
            type: 'line',
            data: {
                labels: balanceData.map(item => new Date(item.date).toLocaleDateString()),
                datasets: [{
                    label: 'Running Cash Balance',
                    data: balanceData.map(item => item.balance),
                    borderColor: '#6f42c1',
                    backgroundColor: 'rgba(111, 66, 193, 0.1)',
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        ticks: {
                            callback: function(value) {
                                return '<?php echo $settings['currency_symbol'] ?? 'KES'; ?> ' + value.toLocaleString();
                            }
                        }
                    }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return 'Balance: <?php echo $settings['currency_symbol'] ?? 'KES'; ?> ' + context.parsed.y.toLocaleString();
                            }
                        }
                    }
                }
            }
        });

        // Forecast Chart
        const ctx4 = document.getElementById('forecastChart').getContext('2d');
        new Chart(ctx4, {
            type: 'line',
            data: {
                labels: [
                    ...trendsData.map(item => new Date(item.date).toLocaleDateString()),
                    ...forecastData.map(item => new Date(item.date).toLocaleDateString())
                ],
                datasets: [{
                    label: 'Historical Inflows',
                    data: [
                        ...trendsData.map(item => item.inflows),
                        ...new Array(forecastData.length).fill(null)
                    ],
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                    tension: 0.4,
                    borderDash: []
                }, {
                    label: 'Historical Outflows',
                    data: [
                        ...trendsData.map(item => item.outflows),
                        ...new Array(forecastData.length).fill(null)
                    ],
                    borderColor: '#dc3545',
                    backgroundColor: 'rgba(220, 53, 69, 0.1)',
                    tension: 0.4,
                    borderDash: []
                }, {
                    label: 'Forecast Inflows',
                    data: [
                        ...new Array(trendsData.length).fill(null),
                        ...forecastData.map(item => item.inflows)
                    ],
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                    tension: 0.4,
                    borderDash: [5, 5]
                }, {
                    label: 'Forecast Outflows',
                    data: [
                        ...new Array(trendsData.length).fill(null),
                        ...forecastData.map(item => item.outflows)
                    ],
                    borderColor: '#dc3545',
                    backgroundColor: 'rgba(220, 53, 69, 0.1)',
                    tension: 0.4,
                    borderDash: [5, 5]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '<?php echo $settings['currency_symbol'] ?? 'KES'; ?> ' + value.toLocaleString();
                            }
                        }
                    }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': <?php echo $settings['currency_symbol'] ?? 'KES'; ?> ' + context.parsed.y.toLocaleString();
                            }
                        }
                    },
                    legend: {
                        onClick: function(e, legendItem, legend) {
                            const index = legendItem.datasetIndex;
                            const ci = legend.chart;
                            if (ci.isDatasetVisible(index)) {
                                ci.hide(index);
                                legendItem.hidden = true;
                            } else {
                                ci.show(index);
                                legendItem.hidden = false;
                            }
                        }
                    }
                }
            }
        });

        // Export and Print Functions
        function exportCashFlow() {
            // Create a simple CSV export
            let csv = 'Date,Inflows,Outflows,Net Flow\n';
            trendsData.forEach(item => {
                csv += `${item.date},${item.inflows},${item.outflows},${item.net_flow}\n`;
            });
            
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'cash_flow_<?php echo $start_date; ?>_to_<?php echo $end_date; ?>.csv';
            a.click();
            window.URL.revokeObjectURL(url);
        }

        function printCashFlow() {
            window.print();
        }
    </script>
</body>
</html>
