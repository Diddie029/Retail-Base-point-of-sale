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
$comparison_type = $_GET['comparison_type'] ?? 'monthly';
$period1_start = $_GET['period1_start'] ?? '';
$period1_end = $_GET['period1_end'] ?? '';
$period2_start = $_GET['period2_start'] ?? '';
$period2_end = $_GET['period2_end'] ?? '';

// Set date ranges based on comparison type
switch ($comparison_type) {
    case 'monthly':
        // Current month vs last month
        $period1_start = date('Y-m-01');
        $period1_end = date('Y-m-t');
        $period2_start = date('Y-m-01', strtotime('first day of last month'));
        $period2_end = date('Y-m-t', strtotime('last day of last month'));
        $period1_label = date('F Y');
        $period2_label = date('F Y', strtotime('first day of last month'));
        break;
    case 'quarterly':
        // Current quarter vs last quarter
        $current_quarter = ceil(date('n') / 3);
        $current_year = date('Y');
        
        // Current quarter
        $period1_start = date('Y-m-01', mktime(0, 0, 0, ($current_quarter - 1) * 3 + 1, 1, $current_year));
        $period1_end = date('Y-m-t', mktime(0, 0, 0, $current_quarter * 3, 1, $current_year));
        
        // Previous quarter
        if ($current_quarter == 1) {
            $prev_quarter = 4;
            $prev_year = $current_year - 1;
        } else {
            $prev_quarter = $current_quarter - 1;
            $prev_year = $current_year;
        }
        $period2_start = date('Y-m-01', mktime(0, 0, 0, ($prev_quarter - 1) * 3 + 1, 1, $prev_year));
        $period2_end = date('Y-m-t', mktime(0, 0, 0, $prev_quarter * 3, 1, $prev_year));
        
        $period1_label = "Q$current_quarter $current_year";
        $period2_label = "Q$prev_quarter $prev_year";
        break;
    case 'yearly':
        // Current year vs last year
        $period1_start = date('Y-01-01');
        $period1_end = date('Y-12-31');
        $period2_start = date('Y-01-01', strtotime('-1 year'));
        $period2_end = date('Y-12-31', strtotime('-1 year'));
        $period1_label = date('Y');
        $period2_label = date('Y', strtotime('-1 year'));
        break;
    case 'custom':
        $period1_start = $period1_start ?: date('Y-m-01');
        $period1_end = $period1_end ?: date('Y-m-t');
        $period2_start = $period2_start ?: date('Y-m-01', strtotime('first day of last month'));
        $period2_end = $period2_end ?: date('Y-m-t', strtotime('last day of last month'));
        $period1_label = date('M d, Y', strtotime($period1_start)) . ' - ' . date('M d, Y', strtotime($period1_end));
        $period2_label = date('M d, Y', strtotime($period2_start)) . ' - ' . date('M d, Y', strtotime($period2_end));
        break;
    default:
        $period1_start = date('Y-m-01');
        $period1_end = date('Y-m-t');
        $period2_start = date('Y-m-01', strtotime('first day of last month'));
        $period2_end = date('Y-m-t', strtotime('last day of last month'));
        $period1_label = date('F Y');
        $period2_label = date('F Y', strtotime('first day of last month'));
}

// Function to get financial data for a period
function getPeriodData($conn, $start_date, $end_date) {
    $data = [
        'revenue' => 0,
        'expenses' => 0,
        'profit' => 0,
        'transactions' => 0,
        'avg_transaction' => 0,
        'top_products' => [],
        'payment_methods' => [],
        'expense_categories' => []
    ];
    
    // Revenue and transactions
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count, COALESCE(SUM(final_amount), 0) as total 
        FROM sales 
        WHERE sale_date BETWEEN ? AND ?
    ");
    $stmt->execute([$start_date, $end_date]);
    $sales = $stmt->fetch(PDO::FETCH_ASSOC);
    $data['revenue'] = $sales['total'];
    $data['transactions'] = $sales['count'];
    $data['avg_transaction'] = $sales['count'] > 0 ? $sales['total'] / $sales['count'] : 0;
    
    // Expenses
    try {
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(total_amount), 0) as total 
            FROM expenses 
            WHERE expense_date BETWEEN ? AND ? AND approval_status = 'approved'
        ");
        $stmt->execute([$start_date, $end_date]);
        $data['expenses'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    } catch (Exception $e) {
        $data['expenses'] = 0;
    }
    
    // Calculate profit
    $data['profit'] = $data['revenue'] - $data['expenses'];
    
    // Top products
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
    $data['top_products'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Payment methods
    $stmt = $conn->prepare("
        SELECT payment_method, COUNT(*) as count, SUM(final_amount) as total
        FROM sales 
        WHERE sale_date BETWEEN ? AND ?
        GROUP BY payment_method
        ORDER BY total DESC
    ");
    $stmt->execute([$start_date, $end_date]);
    $data['payment_methods'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Expense categories
    try {
        $stmt = $conn->prepare("
            SELECT expense_category, SUM(total_amount) as total
            FROM expenses 
            WHERE expense_date BETWEEN ? AND ? AND approval_status = 'approved'
            GROUP BY expense_category
            ORDER BY total DESC
            LIMIT 5
        ");
        $stmt->execute([$start_date, $end_date]);
        $data['expense_categories'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $data['expense_categories'] = [];
    }
    
    return $data;
}

// Get data for both periods
$period1_data = getPeriodData($conn, $period1_start, $period1_end);
$period2_data = getPeriodData($conn, $period2_start, $period2_end);

// Calculate comparisons
$comparisons = [];
$metrics = ['revenue', 'expenses', 'profit', 'transactions', 'avg_transaction'];
foreach ($metrics as $metric) {
    $current = $period1_data[$metric];
    $previous = $period2_data[$metric];
    $change = $current - $previous;
    $percentage = $previous > 0 ? (($change / $previous) * 100) : ($current > 0 ? 100 : 0);
    
    $comparisons[$metric] = [
        'current' => $current,
        'previous' => $previous,
        'change' => $change,
        'percentage' => $percentage
    ];
}

// Get daily comparison data for charts
$daily_comparison = [];
$stmt = $conn->prepare("
    SELECT DATE(sale_date) as date, 
           COUNT(*) as transactions, 
           SUM(final_amount) as revenue
    FROM sales 
    WHERE sale_date BETWEEN ? AND ?
    GROUP BY DATE(sale_date)
    ORDER BY DATE(sale_date) ASC
");

// Period 1 daily data
$stmt->execute([$period1_start, $period1_end]);
$period1_daily = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Period 2 daily data
$stmt->execute([$period2_start, $period2_end]);
$period2_daily = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comparative Reports - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
        .comparison-card {
            border-radius: 15px;
            transition: all 0.3s ease;
            border: 1px solid rgba(0,0,0,0.1);
        }
        .comparison-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0,0,0,0.1);
        }
        .period-header {
            background: linear-gradient(135deg, var(--primary-color), #667eea);
            color: white;
            border-radius: 10px 10px 0 0;
        }
        .change-positive {
            color: #28a745;
            background: rgba(40, 167, 69, 0.1);
            padding: 4px 8px;
            border-radius: 8px;
            font-weight: bold;
        }
        .change-negative {
            color: #dc3545;
            background: rgba(220, 53, 69, 0.1);
            padding: 4px 8px;
            border-radius: 8px;
            font-weight: bold;
        }
        .change-neutral {
            color: #6c757d;
            background: rgba(108, 117, 125, 0.1);
            padding: 4px 8px;
            border-radius: 8px;
            font-weight: bold;
        }
        .metric-row {
            padding: 1rem;
            border-bottom: 1px solid rgba(0,0,0,0.05);
            transition: background-color 0.3s ease;
        }
        .metric-row:hover {
            background-color: rgba(0,0,0,0.02);
        }
        .metric-row:last-child {
            border-bottom: none;
        }
        .chart-container {
            height: 350px;
        }
        .comparison-selector {
            background: var(--primary-color);
            color: white;
            border-radius: 10px;
        }
        .vs-divider {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 40px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            font-weight: bold;
            margin: 0 10px;
            border-radius: 20px;
        }
        .period-card {
            border: 2px solid transparent;
            transition: all 0.3s ease;
        }
        .period-card.period1 {
            border-color: #28a745;
        }
        .period-card.period2 {
            border-color: #6610f2;
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
                            <li class="breadcrumb-item active">Comparative Reports</li>
                        </ol>
                    </nav>
                    <h1><i class="bi bi-bar-chart-line"></i> Comparative Reports</h1>
                    <p class="header-subtitle">Period-over-period financial analysis and comparisons</p>
                </div>
                <div class="header-actions">
                    <button class="btn btn-primary me-2" onclick="window.print()">
                        <i class="bi bi-printer me-1"></i> Print
                    </button>
                    <button class="btn btn-outline-success" onclick="exportComparison()">
                        <i class="bi bi-download me-1"></i> Export
                    </button>
                </div>
            </div>
        </header>

        <main class="content">
            <div class="container-fluid">
                <!-- Comparison Selection -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card comparison-selector">
                            <div class="card-body">
                                <form method="GET" class="row g-3 align-items-end">
                                    <div class="col-md-3">
                                        <label class="form-label text-white opacity-75">Comparison Type</label>
                                        <select class="form-select" name="comparison_type" onchange="toggleCustomDates()">
                                            <option value="monthly" <?php echo $comparison_type === 'monthly' ? 'selected' : ''; ?>>Month-over-Month</option>
                                            <option value="quarterly" <?php echo $comparison_type === 'quarterly' ? 'selected' : ''; ?>>Quarter-over-Quarter</option>
                                            <option value="yearly" <?php echo $comparison_type === 'yearly' ? 'selected' : ''; ?>>Year-over-Year</option>
                                            <option value="custom" <?php echo $comparison_type === 'custom' ? 'selected' : ''; ?>>Custom Periods</option>
                                        </select>
                                    </div>
                                    
                                    <?php if ($comparison_type === 'custom'): ?>
                                    <div class="col-md-2">
                                        <label class="form-label text-white opacity-75">Period 1 Start</label>
                                        <input type="date" class="form-control" name="period1_start" value="<?php echo $period1_start; ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label text-white opacity-75">Period 1 End</label>
                                        <input type="date" class="form-control" name="period1_end" value="<?php echo $period1_end; ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label text-white opacity-75">Period 2 Start</label>
                                        <input type="date" class="form-control" name="period2_start" value="<?php echo $period2_start; ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label text-white opacity-75">Period 2 End</label>
                                        <input type="date" class="form-control" name="period2_end" value="<?php echo $period2_end; ?>">
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="col-md-1">
                                        <button type="submit" class="btn btn-light fw-bold">Update</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Period Headers -->
                <div class="row mb-4">
                    <div class="col-md-5">
                        <div class="card period-card period1">
                            <div class="period-header text-center py-3">
                                <h5 class="mb-0"><i class="bi bi-calendar-check me-2"></i><?php echo $period1_label; ?></h5>
                                <small class="opacity-75"><?php echo date('M d, Y', strtotime($period1_start)); ?> - <?php echo date('M d, Y', strtotime($period1_end)); ?></small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="vs-divider">
                            <i class="bi bi-arrow-left-right me-2"></i> VS
                        </div>
                    </div>
                    <div class="col-md-5">
                        <div class="card period-card period2">
                            <div class="period-header text-center py-3" style="background: linear-gradient(135deg, #6610f2, #6f42c1);">
                                <h5 class="mb-0"><i class="bi bi-calendar me-2"></i><?php echo $period2_label; ?></h5>
                                <small class="opacity-75"><?php echo date('M d, Y', strtotime($period2_start)); ?> - <?php echo date('M d, Y', strtotime($period2_end)); ?></small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Key Metrics Comparison -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card comparison-card">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="bi bi-graph-up me-2"></i>Key Metrics Comparison</h6>
                            </div>
                            <div class="card-body p-0">
                                <?php foreach ($comparisons as $metric => $data): ?>
                                <div class="metric-row">
                                    <div class="row align-items-center">
                                        <div class="col-md-3">
                                            <strong class="text-capitalize"><?php echo str_replace('_', ' ', $metric); ?></strong>
                                        </div>
                                        <div class="col-md-3 text-center">
                                            <div class="fs-5 fw-bold text-success">
                                                <?php if (in_array($metric, ['revenue', 'expenses', 'profit', 'avg_transaction'])): ?>
                                                    <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($data['current'], 2); ?>
                                                <?php else: ?>
                                                    <?php echo number_format($data['current']); ?>
                                                <?php endif; ?>
                                            </div>
                                            <small class="text-muted"><?php echo $period1_label; ?></small>
                                        </div>
                                        <div class="col-md-3 text-center">
                                            <div class="fs-5 fw-bold text-primary">
                                                <?php if (in_array($metric, ['revenue', 'expenses', 'profit', 'avg_transaction'])): ?>
                                                    <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($data['previous'], 2); ?>
                                                <?php else: ?>
                                                    <?php echo number_format($data['previous']); ?>
                                                <?php endif; ?>
                                            </div>
                                            <small class="text-muted"><?php echo $period2_label; ?></small>
                                        </div>
                                        <div class="col-md-3 text-center">
                                            <div class="<?php echo $data['change'] >= 0 ? 'change-positive' : 'change-negative'; ?>">
                                                <i class="bi bi-arrow-<?php echo $data['change'] >= 0 ? 'up' : 'down'; ?>"></i>
                                                <?php echo ($data['change'] >= 0 ? '+' : '') . number_format($data['percentage'], 1); ?>%
                                            </div>
                                            <small class="text-muted">
                                                <?php if (in_array($metric, ['revenue', 'expenses', 'profit', 'avg_transaction'])): ?>
                                                    <?php echo ($data['change'] >= 0 ? '+' : '') . htmlspecialchars($settings['currency_symbol'] ?? 'KES') . number_format($data['change'], 2); ?>
                                                <?php else: ?>
                                                    <?php echo ($data['change'] >= 0 ? '+' : '') . number_format($data['change']); ?>
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Revenue Trend Comparison Chart -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="bi bi-graph-up me-2"></i>Revenue Trend Comparison</h6>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="revenueComparisonChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Detailed Comparisons -->
                <div class="row mb-4">
                    <!-- Top Products Comparison -->
                    <div class="col-lg-6 mb-4">
                        <div class="card h-100">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="bi bi-star me-2"></i>Top Products Comparison</h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-6">
                                        <h6 class="text-success"><?php echo $period1_label; ?></h6>
                                        <?php foreach ($period1_data['top_products'] as $index => $product): ?>
                                        <div class="d-flex justify-content-between align-items-center mb-2 pb-1 border-bottom border-success border-opacity-25">
                                            <div>
                                                <small class="fw-bold"><?php echo htmlspecialchars($product['name']); ?></small><br>
                                                <small class="text-muted"><?php echo $product['total_sold']; ?> units</small>
                                            </div>
                                            <div class="text-end">
                                                <small class="fw-bold text-success"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?><?php echo number_format($product['revenue'], 0); ?></small>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="col-6">
                                        <h6 class="text-primary"><?php echo $period2_label; ?></h6>
                                        <?php foreach ($period2_data['top_products'] as $index => $product): ?>
                                        <div class="d-flex justify-content-between align-items-center mb-2 pb-1 border-bottom border-primary border-opacity-25">
                                            <div>
                                                <small class="fw-bold"><?php echo htmlspecialchars($product['name']); ?></small><br>
                                                <small class="text-muted"><?php echo $product['total_sold']; ?> units</small>
                                            </div>
                                            <div class="text-end">
                                                <small class="fw-bold text-primary"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?><?php echo number_format($product['revenue'], 0); ?></small>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Methods Comparison -->
                    <div class="col-lg-6 mb-4">
                        <div class="card h-100">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="bi bi-credit-card me-2"></i>Payment Methods Comparison</h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-6">
                                        <h6 class="text-success"><?php echo $period1_label; ?></h6>
                                        <?php foreach ($period1_data['payment_methods'] as $payment): ?>
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <div>
                                                <small class="fw-bold text-capitalize"><?php echo htmlspecialchars($payment['payment_method']); ?></small><br>
                                                <small class="text-muted"><?php echo $payment['count']; ?> txns</small>
                                            </div>
                                            <div class="text-end">
                                                <small class="fw-bold text-success"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?><?php echo number_format($payment['total'], 0); ?></small>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="col-6">
                                        <h6 class="text-primary"><?php echo $period2_label; ?></h6>
                                        <?php foreach ($period2_data['payment_methods'] as $payment): ?>
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <div>
                                                <small class="fw-bold text-capitalize"><?php echo htmlspecialchars($payment['payment_method']); ?></small><br>
                                                <small class="text-muted"><?php echo $payment['count']; ?> txns</small>
                                            </div>
                                            <div class="text-end">
                                                <small class="fw-bold text-primary"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?><?php echo number_format($payment['total'], 0); ?></small>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Expense Categories Comparison -->
                <?php if (!empty($period1_data['expense_categories']) || !empty($period2_data['expense_categories'])): ?>
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="bi bi-pie-chart me-2"></i>Expense Categories Comparison</h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6 class="text-success"><?php echo $period1_label; ?></h6>
                                        <?php foreach ($period1_data['expense_categories'] as $expense): ?>
                                        <div class="d-flex justify-content-between align-items-center mb-2 pb-1 border-bottom border-success border-opacity-25">
                                            <div class="fw-bold text-capitalize"><?php echo htmlspecialchars(str_replace('_', ' ', $expense['expense_category'])); ?></div>
                                            <div class="fw-bold text-danger"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?><?php echo number_format($expense['total'], 0); ?></div>
                                        </div>
                                        <?php endforeach; ?>
                                        <?php if (empty($period1_data['expense_categories'])): ?>
                                        <div class="text-center text-muted py-3">
                                            <small>No expense data</small>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-6">
                                        <h6 class="text-primary"><?php echo $period2_label; ?></h6>
                                        <?php foreach ($period2_data['expense_categories'] as $expense): ?>
                                        <div class="d-flex justify-content-between align-items-center mb-2 pb-1 border-bottom border-primary border-opacity-25">
                                            <div class="fw-bold text-capitalize"><?php echo htmlspecialchars(str_replace('_', ' ', $expense['expense_category'])); ?></div>
                                            <div class="fw-bold text-danger"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?><?php echo number_format($expense['total'], 0); ?></div>
                                        </div>
                                        <?php endforeach; ?>
                                        <?php if (empty($period2_data['expense_categories'])): ?>
                                        <div class="text-center text-muted py-3">
                                            <small>No expense data</small>
                                        </div>
                                        <?php endif; ?>
                                    </div>
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
    <script>
        function toggleCustomDates() {
            const type = document.querySelector('[name="comparison_type"]').value;
            if (type === 'custom') {
                document.querySelector('[name="comparison_type"]').form.submit();
            } else {
                document.querySelector('[name="comparison_type"]').form.submit();
            }
        }

        // Revenue Comparison Chart
        const ctx = document.getElementById('revenueComparisonChart').getContext('2d');
        
        // Prepare chart data
        const period1Days = <?php echo json_encode(array_map(function($day) { return date('M d', strtotime($day['date'])); }, $period1_daily)); ?>;
        const period1Revenue = <?php echo json_encode(array_column($period1_daily, 'revenue')); ?>;
        const period2Days = <?php echo json_encode(array_map(function($day) { return date('M d', strtotime($day['date'])); }, $period2_daily)); ?>;
        const period2Revenue = <?php echo json_encode(array_column($period2_daily, 'revenue')); ?>;
        
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: period1Days.length >= period2Days.length ? period1Days : period2Days,
                datasets: [{
                    label: '<?php echo $period1_label; ?>',
                    data: period1Revenue,
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                    tension: 0.4,
                    fill: false
                }, {
                    label: '<?php echo $period2_label; ?>',
                    data: period2Revenue,
                    borderColor: '#6610f2',
                    backgroundColor: 'rgba(102, 16, 242, 0.1)',
                    tension: 0.4,
                    fill: false
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
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Revenue (<?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?>)'
                        }
                    }
                },
                plugins: {
                    legend: {
                        position: 'top'
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?>' + context.parsed.y.toLocaleString();
                            }
                        }
                    }
                }
            }
        });

        function exportComparison() {
            let csv = 'Comparative Financial Report\n';
            csv += 'Period 1: <?php echo $period1_label; ?> (<?php echo $period1_start; ?> to <?php echo $period1_end; ?>)\n';
            csv += 'Period 2: <?php echo $period2_label; ?> (<?php echo $period2_start; ?> to <?php echo $period2_end; ?>)\n';
            csv += 'Generated: <?php echo date('Y-m-d H:i:s'); ?>\n\n';
            
            csv += 'KEY METRICS COMPARISON\n';
            csv += 'Metric,<?php echo $period1_label; ?>,<?php echo $period2_label; ?>,Change,Change %\n';
            <?php foreach ($comparisons as $metric => $data): ?>
            csv += '<?php echo ucfirst(str_replace('_', ' ', $metric)); ?>,<?php echo $data['current']; ?>,<?php echo $data['previous']; ?>,<?php echo $data['change']; ?>,<?php echo number_format($data['percentage'], 2); ?>%\n';
            <?php endforeach; ?>
            
            csv += '\nTOP PRODUCTS - <?php echo $period1_label; ?>\n';
            csv += 'Product,Units Sold,Revenue\n';
            <?php foreach ($period1_data['top_products'] as $product): ?>
            csv += '<?php echo addslashes($product['name']); ?>,<?php echo $product['total_sold']; ?>,<?php echo $product['revenue']; ?>\n';
            <?php endforeach; ?>
            
            csv += '\nTOP PRODUCTS - <?php echo $period2_label; ?>\n';
            csv += 'Product,Units Sold,Revenue\n';
            <?php foreach ($period2_data['top_products'] as $product): ?>
            csv += '<?php echo addslashes($product['name']); ?>,<?php echo $product['total_sold']; ?>,<?php echo $product['revenue']; ?>\n';
            <?php endforeach; ?>
            
            // Download
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.setAttribute('hidden', '');
            a.setAttribute('href', url);
            a.setAttribute('download', 'comparative-report-<?php echo $period1_start; ?>-vs-<?php echo $period2_start; ?>.csv');
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        }
    </script>
</body>
</html>
