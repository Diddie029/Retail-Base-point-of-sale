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

function getTillCashData($conn, $start_date, $end_date) {
    $till_data = [];
    
    // Get till cash amounts from till closings with more details
    $stmt = $conn->prepare("
        SELECT 
            tc.id,
            tc.closed_at,
            DATE(tc.closed_at) as transaction_date,
            TIME(tc.closed_at) as close_time,
            tc.till_id,
            rt.till_name,
            rt.location,
            tc.opening_amount,
            tc.total_sales,
            tc.cash_amount,
            tc.total_amount,
            tc.difference,
            tc.shortage_type,
            tc.created_at,
            u.username as closed_by,
            u.username as closer_name,
            (SELECT COUNT(*) FROM sales WHERE till_id = tc.till_id AND DATE(sale_date) = DATE(tc.closed_at)) as transaction_count,
            (SELECT SUM(final_amount) FROM sales WHERE till_id = tc.till_id AND DATE(sale_date) = DATE(tc.closed_at)) as actual_sales
        FROM till_closings tc
        LEFT JOIN register_tills rt ON tc.till_id = rt.id
        LEFT JOIN users u ON tc.user_id = u.id
        WHERE DATE(tc.closed_at) BETWEEN :start_date AND :end_date
        ORDER BY tc.closed_at DESC
    ");
    $stmt->bindParam(':start_date', $start_date);
    $stmt->bindParam(':end_date', $end_date);
    $stmt->execute();
    $till_closings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return $till_closings;
}

function getOpenTillsNotClosed($conn) {
    $open_tills = [];
    
    // Get tills that are opened but not closed yet (using created_at as proxy for opened_at)
    $stmt = $conn->prepare("
        SELECT 
            rt.id as till_id,
            rt.till_name,
            rt.location,
            rt.till_status,
            rt.created_at as opened_at,
            rt.current_balance,
            rt.current_user_id,
            u.username as `current_user`,
            u.username as current_user_name,
            DATEDIFF(NOW(), rt.created_at) as days_open,
            TIMESTAMPDIFF(HOUR, rt.created_at, NOW()) as hours_open,
            TIMESTAMPDIFF(MINUTE, rt.created_at, NOW()) as minutes_open,
            (SELECT COUNT(*) FROM sales WHERE till_id = rt.id AND DATE(sale_date) = DATE(rt.created_at)) as transaction_count,
            (SELECT SUM(final_amount) FROM sales WHERE till_id = rt.id AND DATE(sale_date) = DATE(rt.created_at)) as total_sales,
            (SELECT SUM(CASE WHEN payment_method = 'cash' THEN final_amount ELSE 0 END) FROM sales WHERE till_id = rt.id AND DATE(sale_date) = DATE(rt.created_at)) as cash_sales,
            (SELECT MAX(sale_date) FROM sales WHERE till_id = rt.id AND DATE(sale_date) = DATE(rt.created_at)) as last_sale
        FROM register_tills rt
        LEFT JOIN users u ON rt.current_user_id = u.id
        WHERE rt.is_active = 1 
        AND rt.till_status = 'opened'
        AND rt.created_at IS NOT NULL
        AND NOT EXISTS (
            SELECT 1 FROM till_closings tc 
            WHERE tc.till_id = rt.id 
            AND DATE(tc.closed_at) = DATE(rt.created_at)
        )
        ORDER BY rt.created_at DESC
    ");
    $stmt->execute();
    $open_tills = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return $open_tills;
}

function getCurrentSessionSales($conn) {
    $current_session = [];
    
    // Get today's sales by till with more details
    $stmt = $conn->prepare("
        SELECT 
            s.till_id,
            rt.till_name,
            rt.location,
            COUNT(s.id) as transaction_count,
            SUM(s.final_amount) as total_sales,
            SUM(CASE WHEN s.payment_method = 'cash' THEN s.final_amount ELSE 0 END) as cash_sales,
            SUM(CASE WHEN s.payment_method != 'cash' THEN s.final_amount ELSE 0 END) as non_cash_sales,
            MIN(s.sale_date) as first_sale,
            MAX(s.sale_date) as last_sale,
            AVG(s.final_amount) as avg_transaction,
            COUNT(DISTINCT s.customer_id) as unique_customers
        FROM sales s
        LEFT JOIN register_tills rt ON s.till_id = rt.id
        WHERE DATE(s.sale_date) = CURDATE()
        GROUP BY s.till_id, rt.till_name, rt.location
        ORDER BY total_sales DESC
    ");
    $stmt->execute();
    $today_sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get current till status with more details
    $stmt = $conn->prepare("
        SELECT 
            rt.id,
            rt.till_name,
            rt.location,
            rt.till_status,
            rt.current_balance,
            rt.current_user_id,
            rt.created_at as opened_at,
            u.username as `current_user`,
            u.username as current_user_name,
            TIMESTAMPDIFF(HOUR, rt.created_at, NOW()) as hours_open,
            TIMESTAMPDIFF(MINUTE, rt.created_at, NOW()) as minutes_open
        FROM register_tills rt
        LEFT JOIN users u ON rt.current_user_id = u.id
        WHERE rt.is_active = 1
        ORDER BY rt.till_name
    ");
    $stmt->execute();
    $till_status = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'today_sales' => $today_sales,
        'till_status' => $till_status
    ];
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
$till_cash_data = getTillCashData($conn, $start_date, $end_date);
$open_tills_not_closed = getOpenTillsNotClosed($conn);
$current_session = getCurrentSessionSales($conn);
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
        
        .cashflow-card.till-cash {
            background: linear-gradient(135deg, #ff9a56 0%, #ff6b95 100%);
        }
        
        .cashflow-card.till-deposits {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }
        
        .cashflow-card.till-session {
            background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
        }
        
        .cashflow-card.till-accuracy {
            background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
        }
        
        .cashflow-card.open-tills-count {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a52 100%);
        }
        
        .cashflow-card.open-tills-sales {
            background: linear-gradient(135deg, #ffa726 0%, #ff9800 100%);
        }
        
        .cashflow-card.open-tills-cash {
            background: linear-gradient(135deg, #ab47bc 0%, #9c27b0 100%);
        }
        
        /* Enhanced table styling */
        .table-responsive {
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .table-responsive::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        .table-responsive::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        
        .table-responsive::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 4px;
        }
        
        .table-responsive::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }
        
        .sticky-top {
            position: sticky;
            top: 0;
            z-index: 10;
            background: white !important;
        }
        
        .table-hover tbody tr:hover {
            background-color: rgba(0,123,255,0.1);
        }
        
        .btn-group-actions .btn {
            margin: 0 2px;
        }
        
        .search-filter-row {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
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

                <!-- Till Cash Tracking Section -->
                <div class="row mb-4">
                    <!-- Current Session Sales -->
                    <div class="col-lg-6 mb-4">
                        <div class="metric-card">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="mb-0"><i class="bi bi-cash-coin text-success"></i> Current Session Sales</h5>
                                <div>
                                    <button class="btn btn-sm btn-outline-success me-2" onclick="refreshSessionData()" title="Refresh current session data">
                                        <i class="bi bi-arrow-clockwise"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-primary" onclick="exportCurrentSession()" title="Export current session data">
                                        <i class="bi bi-download"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Search and Filter -->
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <input type="text" class="form-control form-control-sm" id="sessionSearch" placeholder="Search tills..." onkeyup="filterSessionTable()">
                                </div>
                                <div class="col-md-6">
                                    <select class="form-select form-select-sm" id="sessionFilter" onchange="filterSessionTable()">
                                        <option value="">All Status</option>
                                        <option value="opened">Opened</option>
                                        <option value="closed">Closed</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                                <table class="table table-sm table-hover" id="sessionTable">
                                    <thead class="sticky-top bg-light">
                                        <tr>
                                            <th>Till</th>
                                            <th>Cashier</th>
                                            <th>Status</th>
                                            <th>Transactions</th>
                                            <th>Cash Sales</th>
                                            <th>Total Sales</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($current_session['today_sales'])): ?>
                                        <tr>
                                            <td colspan="7" class="text-center text-muted">
                                                <i class="bi bi-info-circle"></i> No sales recorded today
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                        <?php foreach ($current_session['today_sales'] as $session): ?>
                                        <?php
                                        $till_status = null;
                                        foreach ($current_session['till_status'] as $status) {
                                            if ($status['id'] == $session['till_id']) {
                                                $till_status = $status;
                                                break;
                                            }
                                        }
                                        ?>
                                        <tr data-till-id="<?php echo $session['till_id']; ?>" data-status="<?php echo $till_status['till_status'] ?? 'unknown'; ?>">
                                            <td>
                                                <strong><?php echo htmlspecialchars($session['till_name'] ?? 'N/A'); ?></strong>
                                                <?php if ($session['location']): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($session['location']); ?></small>
                                                <?php endif; ?>
                                                <?php if ($session['unique_customers'] > 0): ?>
                                                <br><small class="text-info"><?php echo $session['unique_customers']; ?> customers</small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($till_status && $till_status['current_user_name']): ?>
                                                    <strong><?php echo htmlspecialchars($till_status['current_user_name']); ?></strong>
                                                    <br><small class="text-muted">@<?php echo htmlspecialchars($till_status['current_user']); ?></small>
                                                    <?php if ($till_status['hours_open'] > 0): ?>
                                                    <br><small class="text-success"><?php echo $till_status['hours_open']; ?>h <?php echo $till_status['minutes_open'] % 60; ?>m</small>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">No cashier</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($till_status): ?>
                                                    <span class="badge bg-<?php echo $till_status['till_status'] == 'opened' ? 'success' : 'secondary'; ?>">
                                                        <?php echo ucfirst($till_status['till_status']); ?>
                                                    </span>
                                                    <?php if ($till_status['current_balance'] > 0): ?>
                                                    <br><small class="text-muted">Balance: <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($till_status['current_balance'], 2); ?></small>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Unknown</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <?php echo number_format($session['transaction_count']); ?>
                                                <?php if ($session['avg_transaction'] > 0): ?>
                                                <br><small class="text-muted">Avg: <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($session['avg_transaction'], 2); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($session['cash_sales'], 2); ?>
                                                <?php if ($session['total_sales'] > 0): ?>
                                                <br><small class="text-muted"><?php echo number_format(($session['cash_sales'] / $session['total_sales']) * 100, 1); ?>%</small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end fw-bold"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($session['total_sales'], 2); ?></td>
                                            <td>
                                                <button class="btn btn-sm btn-outline-info" onclick="viewSessionDetails(<?php echo $session['till_id']; ?>)" title="View Details">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Till Cash Summary -->
                    <div class="col-lg-6 mb-4">
                        <div class="metric-card">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="mb-0"><i class="bi bi-bank text-primary"></i> Till Cash Summary</h5>
                                <div>
                                    <button class="btn btn-sm btn-outline-primary me-2" onclick="exportTillCashReport()" title="Export Till Cash Report">
                                        <i class="bi bi-file-earmark-spreadsheet"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-info" onclick="viewDetailedReport()" title="View Detailed Report">
                                        <i class="bi bi-list-ul"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Search and Filter -->
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <input type="text" class="form-control form-control-sm" id="tillSearch" placeholder="Search tills, cashiers..." onkeyup="filterTillTable()">
                                </div>
                                <div class="col-md-6">
                                    <select class="form-select form-select-sm" id="tillFilter" onchange="filterTillTable()">
                                        <option value="">All Differences</option>
                                        <option value="exact">Exact</option>
                                        <option value="shortage">Shortage</option>
                                        <option value="excess">Excess</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                                <table class="table table-sm table-hover" id="tillTable">
                                    <thead class="sticky-top bg-light">
                                        <tr>
                                            <th>Date & Time</th>
                                            <th>Till</th>
                                            <th>Closed By</th>
                                            <th>Opening</th>
                                            <th>Transactions</th>
                                            <th>Cash Sales</th>
                                            <th>Total Deposit</th>
                                            <th>Difference</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($till_cash_data)): ?>
                                        <tr>
                                            <td colspan="9" class="text-center text-muted">
                                                <i class="bi bi-info-circle"></i> No till closings in selected period
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                        <?php foreach ($till_cash_data as $till): ?>
                                        <tr data-difference="<?php echo $till['difference'] == 0 ? 'exact' : $till['shortage_type']; ?>">
                                            <td>
                                                <strong><?php echo date('M d', strtotime($till['transaction_date'])); ?></strong>
                                                <br><small class="text-muted"><?php echo date('H:i', strtotime($till['close_time'])); ?></small>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($till['till_name'] ?? 'N/A'); ?></strong>
                                                <?php if ($till['location']): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($till['location']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($till['closer_name']): ?>
                                                    <strong><?php echo htmlspecialchars($till['closer_name']); ?></strong>
                                                    <br><small class="text-muted">@<?php echo htmlspecialchars($till['closed_by']); ?></small>
                                                <?php else: ?>
                                                    <span class="text-muted">Unknown</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($till['opening_amount'], 2); ?>
                                            </td>
                                            <td class="text-center">
                                                <?php echo number_format($till['transaction_count'] ?? 0); ?>
                                                <?php if ($till['actual_sales'] && $till['actual_sales'] != $till['total_sales']): ?>
                                                <br><small class="text-warning">Actual: <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($till['actual_sales'], 2); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($till['cash_amount'], 2); ?></td>
                                            <td class="text-end fw-bold"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($till['total_amount'], 2); ?></td>
                                            <td class="text-end">
                                                <?php if ($till['difference'] != 0): ?>
                                                    <span class="badge bg-<?php echo $till['shortage_type'] == 'shortage' ? 'danger' : ($till['shortage_type'] == 'excess' ? 'success' : 'warning'); ?>">
                                                        <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format(abs($till['difference']), 2); ?>
                                                    </span>
                                                    <br><small class="text-muted"><?php echo ucfirst($till['shortage_type']); ?></small>
                                                <?php else: ?>
                                                    <span class="badge bg-success">Exact</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-outline-info" onclick="viewTillDetails(<?php echo $till['id']; ?>)" title="View Details">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Open Tills Not Closed Section -->
                <?php if (!empty($open_tills_not_closed)): ?>
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="metric-card">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="mb-0"><i class="bi bi-exclamation-triangle text-warning"></i> Open Tills Not Closed</h5>
                                <div>
                                    <button class="btn btn-sm btn-outline-warning me-2" onclick="exportOpenTills()" title="Export Open Tills Report">
                                        <i class="bi bi-file-earmark-spreadsheet"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-info" onclick="refreshOpenTills()" title="Refresh Open Tills Data">
                                        <i class="bi bi-arrow-clockwise"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <div class="alert alert-warning mb-3">
                                <i class="bi bi-info-circle"></i> 
                                <strong>Warning:</strong> The following tills have been opened but not yet closed. 
                                This may indicate incomplete cash reconciliation.
                            </div>
                            
                            <!-- Search and Filter -->
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <input type="text" class="form-control form-control-sm" id="openTillsSearch" placeholder="Search open tills..." onkeyup="filterOpenTillsTable()">
                                </div>
                                <div class="col-md-6">
                                    <select class="form-select form-select-sm" id="openTillsFilter" onchange="filterOpenTillsTable()">
                                        <option value="">All Days Open</option>
                                        <option value="0">Today</option>
                                        <option value="1">1+ Days</option>
                                        <option value="2">2+ Days</option>
                                        <option value="3">3+ Days</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                                <table class="table table-sm table-hover" id="openTillsTable">
                                    <thead class="sticky-top bg-light">
                                        <tr>
                                            <th>Till</th>
                                            <th>Cashier</th>
                                            <th>Opened</th>
                                            <th>Days Open</th>
                                            <th>Current Balance</th>
                                            <th>Transactions</th>
                                            <th>Cash Sales</th>
                                            <th>Total Sales</th>
                                            <th>Last Sale</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($open_tills_not_closed as $open_till): ?>
                                        <tr data-days-open="<?php echo $open_till['days_open']; ?>">
                                            <td>
                                                <strong><?php echo htmlspecialchars($open_till['till_name']); ?></strong>
                                                <?php if ($open_till['location']): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($open_till['location']); ?></small>
                                                <?php endif; ?>
                                                <span class="badge bg-warning ms-2">Not Closed</span>
                                            </td>
                                            <td>
                                                <?php if ($open_till['current_user_name']): ?>
                                                    <strong><?php echo htmlspecialchars($open_till['current_user_name']); ?></strong>
                                                    <br><small class="text-muted">@<?php echo htmlspecialchars($open_till['current_user']); ?></small>
                                                <?php else: ?>
                                                    <span class="text-muted">No cashier</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <strong><?php echo date('M d, Y', strtotime($open_till['opened_at'])); ?></strong>
                                                <br><small class="text-muted"><?php echo date('H:i', strtotime($open_till['opened_at'])); ?></small>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge bg-<?php echo $open_till['days_open'] >= 3 ? 'danger' : ($open_till['days_open'] >= 1 ? 'warning' : 'info'); ?>">
                                                    <?php echo $open_till['days_open']; ?> day<?php echo $open_till['days_open'] != 1 ? 's' : ''; ?>
                                                </span>
                                                <br><small class="text-muted"><?php echo $open_till['hours_open']; ?>h <?php echo $open_till['minutes_open'] % 60; ?>m</small>
                                            </td>
                                            <td class="text-end">
                                                <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($open_till['current_balance'], 2); ?>
                                            </td>
                                            <td class="text-center">
                                                <?php echo number_format($open_till['transaction_count'] ?? 0); ?>
                                            </td>
                                            <td class="text-end">
                                                <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($open_till['cash_sales'] ?? 0, 2); ?>
                                            </td>
                                            <td class="text-end fw-bold">
                                                <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($open_till['total_sales'] ?? 0, 2); ?>
                                            </td>
                                            <td>
                                                <?php if ($open_till['last_sale']): ?>
                                                    <small><?php echo date('H:i', strtotime($open_till['last_sale'])); ?></small>
                                                    <br><small class="text-muted"><?php echo date('M d', strtotime($open_till['last_sale'])); ?></small>
                                                <?php else: ?>
                                                    <span class="text-muted">No sales</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-outline-warning" onclick="forceCloseTill(<?php echo $open_till['till_id']; ?>)" title="Force Close Till">
                                                    <i class="bi bi-x-circle"></i>
                                                </button>
                                                <button class="btn btn-sm btn-outline-info" onclick="viewOpenTillDetails(<?php echo $open_till['till_id']; ?>)" title="View Details">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Till Cash Summary Cards -->
                <div class="row mb-4">
                    <?php
                    // Calculate till cash totals
                    $total_cash_sales = array_sum(array_column($till_cash_data, 'cash_amount'));
                    $total_deposits = array_sum(array_column($till_cash_data, 'total_amount'));
                    $total_differences = array_sum(array_column($till_cash_data, 'difference'));
                    $shortage_count = count(array_filter($till_cash_data, function($till) { return $till['shortage_type'] == 'shortage'; }));
                    $excess_count = count(array_filter($till_cash_data, function($till) { return $till['shortage_type'] == 'excess'; }));
                    $exact_count = count(array_filter($till_cash_data, function($till) { return $till['difference'] == 0; }));
                    
                    // Current session totals
                    $current_total_sales = array_sum(array_column($current_session['today_sales'], 'total_sales'));
                    $current_cash_sales = array_sum(array_column($current_session['today_sales'], 'cash_sales'));
                    $current_transactions = array_sum(array_column($current_session['today_sales'], 'transaction_count'));
                    
                    // Open tills totals
                    $open_tills_count = count($open_tills_not_closed);
                    $open_tills_total_sales = array_sum(array_column($open_tills_not_closed, 'total_sales'));
                    $open_tills_total_cash = array_sum(array_column($open_tills_not_closed, 'cash_sales'));
                    $open_tills_current_balance = array_sum(array_column($open_tills_not_closed, 'current_balance'));
                    ?>
                    
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="cashflow-card till-cash">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Total Till Cash Sales</h6>
                                <i class="bi bi-cash-coin fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($total_cash_sales, 2); ?></h3>
                            <small class="opacity-75">From till closings</small>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="cashflow-card till-deposits">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Total Deposits</h6>
                                <i class="bi bi-bank fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($total_deposits, 2); ?></h3>
                            <small class="opacity-75">Actual bank deposits</small>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="cashflow-card till-session">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Today's Session</h6>
                                <i class="bi bi-clock fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($current_total_sales, 2); ?></h3>
                            <small class="opacity-75"><?php echo number_format($current_transactions); ?> transactions</small>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="cashflow-card till-accuracy">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Till Accuracy</h6>
                                <i class="bi bi-check-circle fs-4"></i>
                            </div>
                            <h3 class="mb-0">
                                <?php 
                                $total_closings = count($till_cash_data);
                                $accuracy_rate = $total_closings > 0 ? ($exact_count / $total_closings) * 100 : 0;
                                echo number_format($accuracy_rate, 1) . '%';
                                ?>
                            </h3>
                            <small class="opacity-75">
                                <?php echo $exact_count; ?> exact, 
                                <?php echo $shortage_count; ?> shortage, 
                                <?php echo $excess_count; ?> excess
                            </small>
                        </div>
                    </div>
                </div>

                <!-- Open Tills Summary Cards -->
                <?php if ($open_tills_count > 0): ?>
                <div class="row mb-4">
                    <div class="col-xl-4 col-md-6 mb-3">
                        <div class="cashflow-card open-tills-count">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Open Tills Not Closed</h6>
                                <i class="bi bi-exclamation-triangle fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo $open_tills_count; ?></h3>
                            <small class="opacity-75">Requires attention</small>
                        </div>
                    </div>
                    
                    <div class="col-xl-4 col-md-6 mb-3">
                        <div class="cashflow-card open-tills-sales">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Open Tills Sales</h6>
                                <i class="bi bi-cash-stack fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($open_tills_total_sales, 2); ?></h3>
                            <small class="opacity-75">Unreconciled sales</small>
                        </div>
                    </div>
                    
                    <div class="col-xl-4 col-md-6 mb-3">
                        <div class="cashflow-card open-tills-cash">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Open Tills Cash</h6>
                                <i class="bi bi-coin fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($open_tills_total_cash, 2); ?></h3>
                            <small class="opacity-75">Cash in open tills</small>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
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

        // Refresh session data
        function refreshSessionData() {
            // Reload the page to get fresh session data
            window.location.reload();
        }
        
        // Filter session table
        function filterSessionTable() {
            const searchTerm = document.getElementById('sessionSearch').value.toLowerCase();
            const statusFilter = document.getElementById('sessionFilter').value;
            const table = document.getElementById('sessionTable');
            const rows = table.getElementsByTagName('tr');
            
            for (let i = 1; i < rows.length; i++) {
                const row = rows[i];
                const tillName = row.cells[0].textContent.toLowerCase();
                const cashier = row.cells[1].textContent.toLowerCase();
                const status = row.getAttribute('data-status') || '';
                
                const matchesSearch = tillName.includes(searchTerm) || cashier.includes(searchTerm);
                const matchesStatus = !statusFilter || status === statusFilter;
                
                if (matchesSearch && matchesStatus) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            }
        }
        
        // Filter till table
        function filterTillTable() {
            const searchTerm = document.getElementById('tillSearch').value.toLowerCase();
            const differenceFilter = document.getElementById('tillFilter').value;
            const table = document.getElementById('tillTable');
            const rows = table.getElementsByTagName('tr');
            
            for (let i = 1; i < rows.length; i++) {
                const row = rows[i];
                const tillName = row.cells[1].textContent.toLowerCase();
                const cashier = row.cells[2].textContent.toLowerCase();
                const difference = row.getAttribute('data-difference') || '';
                
                const matchesSearch = tillName.includes(searchTerm) || cashier.includes(searchTerm);
                const matchesDifference = !differenceFilter || difference === differenceFilter;
                
                if (matchesSearch && matchesDifference) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            }
        }
        
        // Export current session data
        function exportCurrentSession() {
            const table = document.getElementById('sessionTable');
            let csv = 'Till,Location,Cashier,Status,Transactions,Avg Transaction,Cash Sales,Cash %,Total Sales,Customers\n';
            
            const rows = table.getElementsByTagName('tr');
            for (let i = 1; i < rows.length; i++) {
                const row = rows[i];
                if (row.style.display !== 'none') {
                    const cells = row.getElementsByTagName('td');
                    if (cells.length >= 6) {
                        const tillName = cells[0].textContent.replace(/\n/g, ' ').trim();
                        const cashier = cells[1].textContent.replace(/\n/g, ' ').trim();
                        const status = cells[2].textContent.replace(/\n/g, ' ').trim();
                        const transactions = cells[3].textContent.replace(/\n/g, ' ').trim();
                        const cashSales = cells[4].textContent.replace(/\n/g, ' ').trim();
                        const totalSales = cells[5].textContent.replace(/\n/g, ' ').trim();
                        
                        csv += `"${tillName}","","${cashier}","${status}","${transactions}","","${cashSales}","","${totalSales}",""\n`;
                    }
                }
            }
            
            downloadCSV(csv, 'current_session_sales_' + new Date().toISOString().split('T')[0] + '.csv');
        }
        
        // Export till cash report
        function exportTillCashReport() {
            const table = document.getElementById('tillTable');
            let csv = 'Date,Close Time,Till,Location,Closed By,Opening Amount,Transactions,Actual Sales,Cash Sales,Total Deposit,Difference,Shortage Type\n';
            
            const rows = table.getElementsByTagName('tr');
            for (let i = 1; i < rows.length; i++) {
                const row = rows[i];
                if (row.style.display !== 'none') {
                    const cells = row.getElementsByTagName('td');
                    if (cells.length >= 8) {
                        const dateTime = cells[0].textContent.replace(/\n/g, ' ').trim();
                        const tillName = cells[1].textContent.replace(/\n/g, ' ').trim();
                        const cashier = cells[2].textContent.replace(/\n/g, ' ').trim();
                        const opening = cells[3].textContent.replace(/\n/g, ' ').trim();
                        const transactions = cells[4].textContent.replace(/\n/g, ' ').trim();
                        const cashSales = cells[5].textContent.replace(/\n/g, ' ').trim();
                        const totalDeposit = cells[6].textContent.replace(/\n/g, ' ').trim();
                        const difference = cells[7].textContent.replace(/\n/g, ' ').trim();

                        csv += `"${dateTime}","${tillName}","","${cashier}","${opening}","${transactions}","","${cashSales}","${totalDeposit}","${difference}",""\n`;
                    }
                }
            }
            
            downloadCSV(csv, 'till_cash_report_' + new Date().toISOString().split('T')[0] + '.csv');
        }
        
        // Download CSV file
        function downloadCSV(csv, filename) {
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }
        
        // View session details
        function viewSessionDetails(tillId) {
            // Open a modal or redirect to detailed view
            window.open(`../pos/till_details.php?till_id=${tillId}&date=${new Date().toISOString().split('T')[0]}`, '_blank');
        }
        
        // View till details
        function viewTillDetails(closingId) {
            // Open a modal or redirect to detailed view
            window.open(`../pos/till_closing_details.php?closing_id=${closingId}`, '_blank');
        }
        
        // View detailed report
        function viewDetailedReport() {
            // Open detailed report in new window
            const startDate = '<?php echo $start_date; ?>';
            const endDate = '<?php echo $end_date; ?>';
            window.open(`../reports/till_cash_detailed.php?start_date=${startDate}&end_date=${endDate}`, '_blank');
        }
        
        // Filter open tills table
        function filterOpenTillsTable() {
            const searchTerm = document.getElementById('openTillsSearch').value.toLowerCase();
            const daysFilter = document.getElementById('openTillsFilter').value;
            const table = document.getElementById('openTillsTable');
            const rows = table.getElementsByTagName('tr');
            
            for (let i = 1; i < rows.length; i++) {
                const row = rows[i];
                const tillName = row.cells[0].textContent.toLowerCase();
                const cashier = row.cells[1].textContent.toLowerCase();
                const daysOpen = parseInt(row.getAttribute('data-days-open')) || 0;
                
                const matchesSearch = tillName.includes(searchTerm) || cashier.includes(searchTerm);
                const matchesDays = !daysFilter || daysOpen >= parseInt(daysFilter);
                
                if (matchesSearch && matchesDays) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            }
        }
        
        // Export open tills data
        function exportOpenTills() {
            const table = document.getElementById('openTillsTable');
            let csv = 'Till,Location,Cashier,Opened Date,Opened Time,Days Open,Hours Open,Current Balance,Transactions,Cash Sales,Total Sales,Last Sale\n';
            
            const rows = table.getElementsByTagName('tr');
            for (let i = 1; i < rows.length; i++) {
                const row = rows[i];
                if (row.style.display !== 'none') {
                    const cells = row.getElementsByTagName('td');
                    if (cells.length >= 9) {
                        const tillName = cells[0].textContent.replace(/\n/g, ' ').trim();
                        const cashier = cells[1].textContent.replace(/\n/g, ' ').trim();
                        const opened = cells[2].textContent.replace(/\n/g, ' ').trim();
                        const daysOpen = cells[3].textContent.replace(/\n/g, ' ').trim();
                        const balance = cells[4].textContent.replace(/\n/g, ' ').trim();
                        const transactions = cells[5].textContent.replace(/\n/g, ' ').trim();
                        const cashSales = cells[6].textContent.replace(/\n/g, ' ').trim();
                        const totalSales = cells[7].textContent.replace(/\n/g, ' ').trim();
                        const lastSale = cells[8].textContent.replace(/\n/g, ' ').trim();
                        
                        csv += `"${tillName}","","${cashier}","${opened}","","${daysOpen}","","${balance}","${transactions}","${cashSales}","${totalSales}","${lastSale}"\n`;
                    }
                }
            }
            
            downloadCSV(csv, 'open_tills_not_closed_' + new Date().toISOString().split('T')[0] + '.csv');
        }
        
        // Refresh open tills data
        function refreshOpenTills() {
            window.location.reload();
        }
        
        // Force close till
        function forceCloseTill(tillId) {
            if (confirm('Are you sure you want to force close this till? This action cannot be undone.')) {
                // Redirect to force close page
                window.open(`../pos/force_close_till.php?till_id=${tillId}`, '_blank');
            }
        }
        
        // View open till details
        function viewOpenTillDetails(tillId) {
            window.open(`../pos/open_till_details.php?till_id=${tillId}`, '_blank');
        }
        
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
