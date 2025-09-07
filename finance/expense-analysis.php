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
$period_type = $_GET['period'] ?? 'monthly';
$category_filter = $_GET['category'] ?? 'all';
$status_filter = $_GET['status'] ?? 'all';

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
            $quarter_start = date('Y-m-01', strtotime('first day of -2 month'));
            $start_date = $quarter_start;
            $end_date = date('Y-m-t');
            break;
        case 'yearly':
            $start_date = date('Y-01-01');
            $end_date = date('Y-12-31');
            break;
    }
}

// Initialize analysis data
$analysis = [
    'summary' => [],
    'categories' => [],
    'trends' => [],
    'monthly_breakdown' => [],
    'top_expenses' => [],
    'vendor_analysis' => [],
    'department_analysis' => []
];

// Build WHERE clause for filtering
$where_conditions = ["e.expense_date BETWEEN ? AND ?"];
$params = [$start_date, $end_date];

if ($category_filter !== 'all') {
    $where_conditions[] = "ec.name = ?";
    $params[] = $category_filter;
}

if ($status_filter !== 'all') {
    $where_conditions[] = "e.approval_status = ?";
    $params[] = $status_filter;
}

$where_clause = implode(' AND ', $where_conditions);

// Expense Summary
try {
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_expenses,
            COALESCE(SUM(total_amount), 0) as total_amount,
            COALESCE(AVG(total_amount), 0) as avg_expense,
            COALESCE(SUM(CASE WHEN approval_status = 'approved' THEN total_amount ELSE 0 END), 0) as approved_amount,
            COALESCE(SUM(CASE WHEN approval_status = 'pending' THEN total_amount ELSE 0 END), 0) as pending_amount,
            COALESCE(SUM(CASE WHEN approval_status = 'rejected' THEN total_amount ELSE 0 END), 0) as rejected_amount
        FROM expenses 
        WHERE $where_clause
    ");
    $stmt->execute($params);
    $analysis['summary'] = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // If expenses table doesn't exist, create dummy data
    $analysis['summary'] = [
        'total_expenses' => 0,
        'total_amount' => 0,
        'avg_expense' => 0,
        'approved_amount' => 0,
        'pending_amount' => 0,
        'rejected_amount' => 0
    ];
}

// Previous period comparison
$prev_start = date('Y-m-d', strtotime($start_date . ' -1 month'));
$prev_end = date('Y-m-d', strtotime($end_date . ' -1 month'));

try {
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as prev_expenses,
            COALESCE(SUM(total_amount), 0) as prev_amount
        FROM expenses 
        WHERE expense_date BETWEEN ? AND ?
    ");
    $stmt->execute([$prev_start, $prev_end]);
    $prev_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $analysis['summary']['amount_growth'] = $prev_data['prev_amount'] > 0 ? 
        (($analysis['summary']['total_amount'] - $prev_data['prev_amount']) / $prev_data['prev_amount']) * 100 : 0;
    
    $analysis['summary']['expense_growth'] = $prev_data['prev_expenses'] > 0 ? 
        (($analysis['summary']['total_expenses'] - $prev_data['prev_expenses']) / $prev_data['prev_expenses']) * 100 : 0;
} catch (Exception $e) {
    $analysis['summary']['amount_growth'] = 0;
    $analysis['summary']['expense_growth'] = 0;
}

// Category Analysis
try {
    $stmt = $conn->prepare("
        SELECT 
            ec.name as expense_category,
            COUNT(*) as expense_count,
            SUM(e.total_amount) as total_amount,
            AVG(e.total_amount) as avg_amount,
            SUM(CASE WHEN e.approval_status = 'approved' THEN e.total_amount ELSE 0 END) as approved_amount
        FROM expenses e
        JOIN expense_categories ec ON e.category_id = ec.id
        WHERE $where_clause
        GROUP BY ec.name
        ORDER BY total_amount DESC
    ");
    $stmt->execute($params);
    $analysis['categories'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $analysis['categories'] = [];
}

// Monthly Trend Analysis (for the year)
$year = date('Y', strtotime($start_date));
try {
    $stmt = $conn->prepare("
        SELECT 
            YEAR(expense_date) as expense_year,
            MONTH(expense_date) as expense_month,
            COUNT(*) as monthly_count,
            SUM(total_amount) as monthly_total,
            AVG(total_amount) as monthly_avg
        FROM expenses 
        WHERE YEAR(expense_date) = ?
        GROUP BY YEAR(expense_date), MONTH(expense_date)
        ORDER BY expense_year, expense_month
    ");
    $stmt->execute([$year]);
    $analysis['monthly_breakdown'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $analysis['monthly_breakdown'] = [];
}

// Top Individual Expenses
try {
    $stmt = $conn->prepare("
        SELECT 
            e.description,
            ec.name as expense_category,
            e.total_amount,
            e.expense_date,
            e.vendor_name,
            e.approval_status,
            e.created_by
        FROM expenses e
        JOIN expense_categories ec ON e.category_id = ec.id
        WHERE $where_clause
        ORDER BY e.total_amount DESC
        LIMIT 15
    ");
    $stmt->execute($params);
    $analysis['top_expenses'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $analysis['top_expenses'] = [];
}

// Vendor Analysis (if vendor data exists)
try {
    $stmt = $conn->prepare("
        SELECT 
            vendor_name,
            COUNT(*) as transaction_count,
            SUM(total_amount) as total_spent,
            AVG(total_amount) as avg_transaction,
            MAX(expense_date) as last_transaction
        FROM expenses 
        WHERE $where_clause AND vendor_name IS NOT NULL AND vendor_name != ''
        GROUP BY vendor_name
        ORDER BY total_spent DESC
        LIMIT 10
    ");
    $stmt->execute($params);
    $analysis['vendor_analysis'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $analysis['vendor_analysis'] = [];
}

// Department Analysis (if department data exists)
try {
    $stmt = $conn->prepare("
        SELECT 
            department,
            COUNT(*) as expense_count,
            SUM(total_amount) as total_amount,
            AVG(total_amount) as avg_amount
        FROM expenses 
        WHERE $where_clause AND department IS NOT NULL AND department != ''
        GROUP BY department
        ORDER BY total_amount DESC
    ");
    $stmt->execute($params);
    $analysis['department_analysis'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $analysis['department_analysis'] = [];
}

// Get available categories for filter
try {
    $stmt = $conn->query("SELECT DISTINCT ec.name as expense_category FROM expenses e JOIN expense_categories ec ON e.category_id = ec.id ORDER BY ec.name");
    $available_categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $available_categories = ['operations', 'utilities', 'rent', 'supplies', 'marketing', 'equipment', 'travel'];
}

// Calculate expense-to-revenue ratio
$revenue_stmt = $conn->prepare("SELECT COALESCE(SUM(final_amount), 0) as total_revenue FROM sales WHERE sale_date BETWEEN ? AND ?");
$revenue_stmt->execute([$start_date, $end_date]);
$total_revenue = $revenue_stmt->fetch(PDO::FETCH_ASSOC)['total_revenue'];

$expense_ratio = $total_revenue > 0 ? ($analysis['summary']['approved_amount'] / $total_revenue) * 100 : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expense Analysis - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
        .expense-card {
            transition: all 0.3s ease;
        }
        .expense-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        .metric-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
        }
        .metric-card.total {
            background: linear-gradient(135deg, #fc466b 0%, #3f5efb 100%);
        }
        .metric-card.approved {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
        }
        .metric-card.pending {
            background: linear-gradient(135deg, #fdbb2d 0%, #22c1c3 100%);
        }
        .growth-positive { color: #28a745; }
        .growth-negative { color: #dc3545; }
        .chart-container { height: 350px; }
        .status-approved { color: #28a745; }
        .status-pending { color: #ffc107; }
        .status-rejected { color: #dc3545; }
        .category-card {
            border-left: 4px solid var(--primary-color);
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
                            <li class="breadcrumb-item active">Expense Analysis</li>
                        </ol>
                    </nav>
                    <h1><i class="bi bi-pie-chart"></i> Expense Analysis</h1>
                    <p class="header-subtitle">
                        Comprehensive expense analysis for <?php echo date('M d, Y', strtotime($start_date)); ?> to <?php echo date('M d, Y', strtotime($end_date)); ?>
                    </p>
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
                <!-- Filters -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <form method="GET" class="row g-3 align-items-end">
                                    <div class="col-md-2">
                                        <label for="period" class="form-label">Period</label>
                                        <select class="form-select" id="period" name="period">
                                            <option value="weekly" <?php echo $period_type == 'weekly' ? 'selected' : ''; ?>>This Week</option>
                                            <option value="monthly" <?php echo $period_type == 'monthly' ? 'selected' : ''; ?>>This Month</option>
                                            <option value="quarterly" <?php echo $period_type == 'quarterly' ? 'selected' : ''; ?>>This Quarter</option>
                                            <option value="yearly" <?php echo $period_type == 'yearly' ? 'selected' : ''; ?>>This Year</option>
                                            <option value="custom" <?php echo $period_type == 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label for="start_date" class="form-label">Start Date</label>
                                        <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo $start_date; ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label for="end_date" class="form-label">End Date</label>
                                        <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo $end_date; ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label for="category" class="form-label">Category</label>
                                        <select class="form-select" id="category" name="category">
                                            <option value="all" <?php echo $category_filter == 'all' ? 'selected' : ''; ?>>All Categories</option>
                                            <?php foreach ($available_categories as $cat): ?>
                                            <option value="<?php echo $cat; ?>" <?php echo $category_filter == $cat ? 'selected' : ''; ?>>
                                                <?php echo ucfirst(str_replace('_', ' ', $cat)); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label for="status" class="form-label">Status</label>
                                        <select class="form-select" id="status" name="status">
                                            <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All Status</option>
                                            <option value="approved" <?php echo $status_filter == 'approved' ? 'selected' : ''; ?>>Approved</option>
                                            <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                            <option value="rejected" <?php echo $status_filter == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <button type="submit" class="btn btn-primary w-100">
                                            <i class="bi bi-search"></i> Update
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Summary Cards -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="card metric-card total">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="mb-1">Total Expenses</h6>
                                        <h3 class="mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($analysis['summary']['total_amount'], 2); ?></h3>
                                        <small class="opacity-75 <?php echo $analysis['summary']['amount_growth'] >= 0 ? 'growth-negative' : 'growth-positive'; ?>">
                                            <i class="bi bi-arrow-<?php echo $analysis['summary']['amount_growth'] >= 0 ? 'up' : 'down'; ?>"></i>
                                            <?php echo number_format(abs($analysis['summary']['amount_growth']), 1); ?>% vs previous period
                                        </small>
                                    </div>
                                    <div>
                                        <i class="bi bi-cash-stack fs-1"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="card metric-card approved">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="mb-1">Approved Expenses</h6>
                                        <h3 class="mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($analysis['summary']['approved_amount'], 2); ?></h3>
                                        <small class="opacity-75">
                                            <?php echo $analysis['summary']['total_amount'] > 0 ? number_format(($analysis['summary']['approved_amount'] / $analysis['summary']['total_amount']) * 100, 1) : 0; ?>% of total
                                        </small>
                                    </div>
                                    <div>
                                        <i class="bi bi-check-circle fs-1"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="card metric-card pending">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="mb-1">Pending Expenses</h6>
                                        <h3 class="mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($analysis['summary']['pending_amount'], 2); ?></h3>
                                        <small class="opacity-75">
                                            Awaiting approval
                                        </small>
                                    </div>
                                    <div>
                                        <i class="bi bi-clock fs-1"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="card metric-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="mb-1">Expense Ratio</h6>
                                        <h3 class="mb-0"><?php echo number_format($expense_ratio, 1); ?>%</h3>
                                        <small class="opacity-75">
                                            Of total revenue
                                        </small>
                                    </div>
                                    <div>
                                        <i class="bi bi-percent fs-1"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Charts Row -->
                <div class="row mb-4">
                    <!-- Category Breakdown Chart -->
                    <div class="col-lg-6 mb-4">
                        <div class="card expense-card">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="bi bi-pie-chart-fill me-2"></i>Expense Categories</h6>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="categoryChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Monthly Trend Chart -->
                    <div class="col-lg-6 mb-4">
                        <div class="card expense-card">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="bi bi-graph-up me-2"></i>Monthly Expense Trend</h6>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="trendChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Analysis Tables Row -->
                <div class="row mb-4">
                    <!-- Category Analysis -->
                    <div class="col-lg-8 mb-4">
                        <div class="card expense-card">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="bi bi-tags me-2"></i>Category Analysis</h6>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Category</th>
                                                <th class="text-end">Count</th>
                                                <th class="text-end">Total Amount</th>
                                                <th class="text-end">Avg Amount</th>
                                                <th class="text-end">Approved</th>
                                                <th class="text-end">% of Total</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($analysis['categories'] as $category): ?>
                                            <tr>
                                                <td>
                                                    <span class="fw-bold text-capitalize">
                                                        <?php echo htmlspecialchars(str_replace('_', ' ', $category['expense_category'])); ?>
                                                    </span>
                                                </td>
                                                <td class="text-end"><span class="badge bg-info"><?php echo $category['expense_count']; ?></span></td>
                                                <td class="text-end text-danger fw-bold"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($category['total_amount'], 2); ?></td>
                                                <td class="text-end"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($category['avg_amount'], 2); ?></td>
                                                <td class="text-end text-success"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($category['approved_amount'], 2); ?></td>
                                                <td class="text-end">
                                                    <?php 
                                                    $percentage = $analysis['summary']['total_amount'] > 0 ? 
                                                        ($category['total_amount'] / $analysis['summary']['total_amount']) * 100 : 0;
                                                    ?>
                                                    <div class="progress" style="width: 60px; height: 20px;">
                                                        <div class="progress-bar bg-danger" style="width: <?php echo min($percentage, 100); ?>%"></div>
                                                    </div>
                                                    <small><?php echo number_format($percentage, 1); ?>%</small>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Top Expenses -->
                    <div class="col-lg-4 mb-4">
                        <div class="card expense-card">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="bi bi-arrow-up-circle me-2"></i>Largest Expenses</h6>
                            </div>
                            <div class="card-body">
                                <?php foreach (array_slice($analysis['top_expenses'], 0, 8) as $expense): ?>
                                <div class="d-flex justify-content-between align-items-start mb-3 pb-2 border-bottom">
                                    <div class="flex-grow-1">
                                        <div class="fw-bold"><?php echo htmlspecialchars($expense['description']); ?></div>
                                        <small class="text-muted text-capitalize"><?php echo htmlspecialchars(str_replace('_', ' ', $expense['expense_category'])); ?></small>
                                        <div><small class="text-muted"><?php echo date('M d, Y', strtotime($expense['expense_date'])); ?></small></div>
                                    </div>
                                    <div class="text-end">
                                        <div class="fw-bold text-danger"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($expense['total_amount'], 2); ?></div>
                                        <small class="status-<?php echo $expense['approval_status']; ?>">
                                            <i class="bi bi-circle-fill"></i> <?php echo ucfirst($expense['approval_status']); ?>
                                        </small>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Vendor Analysis -->
                <?php if (!empty($analysis['vendor_analysis'])): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="card expense-card">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="bi bi-building me-2"></i>Vendor Analysis</h6>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Vendor</th>
                                                <th class="text-end">Transactions</th>
                                                <th class="text-end">Total Spent</th>
                                                <th class="text-end">Avg Transaction</th>
                                                <th class="text-end">Last Transaction</th>
                                                <th class="text-end">Performance</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($analysis['vendor_analysis'] as $vendor): ?>
                                            <tr>
                                                <td class="fw-bold"><?php echo htmlspecialchars($vendor['vendor_name']); ?></td>
                                                <td class="text-end"><span class="badge bg-primary"><?php echo $vendor['transaction_count']; ?></span></td>
                                                <td class="text-end text-danger fw-bold"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($vendor['total_spent'], 2); ?></td>
                                                <td class="text-end"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($vendor['avg_transaction'], 2); ?></td>
                                                <td class="text-end"><?php echo date('M d, Y', strtotime($vendor['last_transaction'])); ?></td>
                                                <td class="text-end">
                                                    <?php 
                                                    $vendor_percentage = $analysis['summary']['total_amount'] > 0 ? 
                                                        ($vendor['total_spent'] / $analysis['summary']['total_amount']) * 100 : 0;
                                                    ?>
                                                    <div class="progress" style="width: 60px; height: 20px;">
                                                        <div class="progress-bar bg-warning" style="width: <?php echo min($vendor_percentage, 100); ?>%"></div>
                                                    </div>
                                                    <small><?php echo number_format($vendor_percentage, 1); ?>%</small>
                                                </td>
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
    <script>
        // Category Chart
        const categoryCtx = document.getElementById('categoryChart').getContext('2d');
        const categoryChart = new Chart(categoryCtx, {
            type: 'doughnut',
            data: {
                labels: [<?php foreach ($analysis['categories'] as $cat): ?>'<?php echo ucfirst(str_replace('_', ' ', $cat['expense_category'])); ?>',<?php endforeach; ?>],
                datasets: [{
                    data: [<?php foreach ($analysis['categories'] as $cat): ?><?php echo $cat['total_amount']; ?>,<?php endforeach; ?>],
                    backgroundColor: [
                        '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', 
                        '#9966FF', '#FF9F40', '#FF6384', '#C9CBCF',
                        '#4BC0C0', '#FF6384', '#36A2EB', '#FFCE56'
                    ]
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

        // Monthly Trend Chart
        const trendCtx = document.getElementById('trendChart').getContext('2d');
        const trendChart = new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: [<?php foreach ($analysis['monthly_breakdown'] as $month): ?>'<?php echo date('M Y', mktime(0, 0, 0, $month['expense_month'], 1, $month['expense_year'])); ?>',<?php endforeach; ?>],
                datasets: [{
                    label: 'Monthly Expenses',
                    data: [<?php foreach ($analysis['monthly_breakdown'] as $month): ?><?php echo $month['monthly_total']; ?>,<?php endforeach; ?>],
                    borderColor: '#FF6384',
                    backgroundColor: 'rgba(255, 99, 132, 0.1)',
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
                        beginAtZero: true
                    }
                }
            }
        });

        function exportToCSV() {
            let csv = 'Expense Analysis Report\n';
            csv += 'Period: <?php echo $start_date; ?> to <?php echo $end_date; ?>\n\n';
            
            csv += 'SUMMARY\n';
            csv += 'Total Expenses,<?php echo $analysis['summary']['total_amount']; ?>\n';
            csv += 'Total Count,<?php echo $analysis['summary']['total_expenses']; ?>\n';
            csv += 'Approved Amount,<?php echo $analysis['summary']['approved_amount']; ?>\n';
            csv += 'Pending Amount,<?php echo $analysis['summary']['pending_amount']; ?>\n\n';
            
            csv += 'CATEGORY BREAKDOWN\n';
            csv += 'Category,Count,Total Amount,Average Amount,Approved Amount\n';
            <?php foreach ($analysis['categories'] as $category): ?>
            csv += '<?php echo addslashes(str_replace('_', ' ', $category['expense_category'])); ?>,<?php echo $category['expense_count']; ?>,<?php echo $category['total_amount']; ?>,<?php echo $category['avg_amount']; ?>,<?php echo $category['approved_amount']; ?>\n';
            <?php endforeach; ?>
            
            <?php if (!empty($analysis['vendor_analysis'])): ?>
            csv += '\nVENDOR ANALYSIS\n';
            csv += 'Vendor,Transactions,Total Spent,Average Transaction\n';
            <?php foreach ($analysis['vendor_analysis'] as $vendor): ?>
            csv += '<?php echo addslashes($vendor['vendor_name']); ?>,<?php echo $vendor['transaction_count']; ?>,<?php echo $vendor['total_spent']; ?>,<?php echo $vendor['avg_transaction']; ?>\n';
            <?php endforeach; ?>
            <?php endif; ?>
            
            // Download
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.setAttribute('hidden', '');
            a.setAttribute('href', url);
            a.setAttribute('download', 'expense-analysis-<?php echo $start_date; ?>-to-<?php echo $end_date; ?>.csv');
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        }
    </script>
</body>
</html>
