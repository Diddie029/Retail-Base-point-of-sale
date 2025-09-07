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

// Get budget settings
$budget_settings = [];
try {
    $stmt = $conn->query("SELECT setting_key, setting_value FROM budget_settings");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $budget_settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    $budget_settings = ['default_currency' => 'KES'];
}

// Filter parameters
$report_type = $_GET['report_type'] ?? 'variance';
$period_filter = $_GET['period'] ?? 'current';
$budget_id = $_GET['budget_id'] ?? '';
$category_id = $_GET['category_id'] ?? '';

// Set date range based on period filter
switch ($period_filter) {
    case 'current':
        $start_date = date('Y-m-01');
        $end_date = date('Y-m-t');
        break;
    case 'last_month':
        $start_date = date('Y-m-01', strtotime('-1 month'));
        $end_date = date('Y-m-t', strtotime('-1 month'));
        break;
    case 'quarter':
        $start_date = date('Y-m-01', strtotime('-2 months'));
        $end_date = date('Y-m-t');
        break;
    case 'year':
        $start_date = date('Y-01-01');
        $end_date = date('Y-12-31');
        break;
    default:
        $start_date = date('Y-m-01');
        $end_date = date('Y-m-t');
}

// Custom date range if provided
if (isset($_GET['custom_start']) && isset($_GET['custom_end'])) {
    $start_date = $_GET['custom_start'];
    $end_date = $_GET['custom_end'];
    $period_filter = 'custom';
}

try {
    // Get budgets for filter dropdown
    $budgets = [];
    $stmt = $conn->query("SELECT id, name FROM budgets ORDER BY created_at DESC");
    $budgets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get categories for filter dropdown
    $categories = [];
    $stmt = $conn->query("SELECT id, name FROM budget_categories WHERE is_active = TRUE ORDER BY name");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Initialize report data
    $report_data = [];
    $summary_stats = [
        'total_budgets' => 0,
        'total_budgeted' => 0,
        'total_actual' => 0,
        'total_variance' => 0,
        'avg_variance_pct' => 0
    ];
    
    // Build base query conditions
    $where_conditions = [];
    $params = [];
    
    if ($budget_id) {
        $where_conditions[] = "b.id = ?";
        $params[] = $budget_id;
    }
    
    if ($category_id) {
        $where_conditions[] = "bi.category_id = ?";
        $params[] = $category_id;
    }
    
    // Add date filter for active budgets
    $where_conditions[] = "(b.start_date <= ? AND b.end_date >= ?)";
    $params[] = $end_date;
    $params[] = $start_date;
    
    $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
    
    switch ($report_type) {
        case 'variance':
            // Variance Analysis Report
            $query = "
                SELECT 
                    b.id as budget_id,
                    b.name as budget_name,
                    b.budget_type,
                    b.start_date,
                    b.end_date,
                    b.status,
                    bi.id as item_id,
                    bi.name as item_name,
                    bc.name as category_name,
                    bc.color as category_color,
                    bi.budgeted_amount,
                    bi.actual_amount,
                    (bi.actual_amount - bi.budgeted_amount) as variance_amount,
                    CASE 
                        WHEN bi.budgeted_amount = 0 THEN NULL
                        ELSE ((bi.actual_amount - bi.budgeted_amount) / bi.budgeted_amount) * 100
                    END as variance_percentage,
                    bi.priority
                FROM budgets b
                LEFT JOIN budget_items bi ON b.id = bi.budget_id
                LEFT JOIN budget_categories bc ON bi.category_id = bc.id
                {$where_clause}
                ORDER BY ABS(bi.actual_amount - bi.budgeted_amount) DESC, b.name, bi.name
            ";
            break;
            
        case 'performance':
            // Budget Performance Report
            $query = "
                SELECT 
                    b.id as budget_id,
                    b.name as budget_name,
                    b.budget_type,
                    b.start_date,
                    b.end_date,
                    b.status,
                    b.total_budget_amount,
                    b.total_actual_amount,
                    (b.total_actual_amount - b.total_budget_amount) as total_variance,
                    CASE 
                        WHEN b.total_budget_amount = 0 THEN NULL
                        ELSE ((b.total_actual_amount - b.total_budget_amount) / b.total_budget_amount) * 100
                    END as total_variance_pct,
                    COUNT(bi.id) as items_count,
                    COUNT(CASE WHEN bi.actual_amount > bi.budgeted_amount THEN 1 END) as over_budget_items,
                    u.username as created_by_name
                FROM budgets b
                LEFT JOIN budget_items bi ON b.id = bi.budget_id
                LEFT JOIN users u ON b.created_by = u.id
                {$where_clause}
                GROUP BY b.id
                ORDER BY ABS(b.total_actual_amount - b.total_budget_amount) DESC
            ";
            break;
            
        case 'category':
            // Category Analysis Report
            $query = "
                SELECT 
                    bc.id as category_id,
                    bc.name as category_name,
                    bc.color as category_color,
                    COUNT(DISTINCT b.id) as budgets_count,
                    COUNT(bi.id) as items_count,
                    COALESCE(SUM(bi.budgeted_amount), 0) as total_budgeted,
                    COALESCE(SUM(bi.actual_amount), 0) as total_actual,
                    COALESCE(SUM(bi.actual_amount - bi.budgeted_amount), 0) as total_variance,
                    CASE 
                        WHEN SUM(bi.budgeted_amount) = 0 THEN NULL
                        ELSE (SUM(bi.actual_amount - bi.budgeted_amount) / SUM(bi.budgeted_amount)) * 100
                    END as variance_percentage
                FROM budget_categories bc
                LEFT JOIN budget_items bi ON bc.id = bi.category_id
                LEFT JOIN budgets b ON bi.budget_id = b.id
                {$where_clause}
                GROUP BY bc.id, bc.name, bc.color
                HAVING total_budgeted > 0 OR total_actual > 0
                ORDER BY ABS(total_variance) DESC
            ";
            break;
            
        case 'timeline':
            // Budget Timeline Report
            $query = "
                SELECT 
                    DATE(bt.transaction_date) as transaction_date,
                    SUM(bt.amount) as daily_spending,
                    COUNT(bt.id) as transaction_count,
                    COUNT(DISTINCT bt.budget_id) as active_budgets
                FROM budget_transactions bt
                JOIN budgets b ON bt.budget_id = b.id
                WHERE bt.transaction_date BETWEEN ? AND ?
                " . ($budget_id ? " AND b.id = ?" : "") . "
                GROUP BY DATE(bt.transaction_date)
                ORDER BY transaction_date DESC
                LIMIT 30
            ";
            $params = [$start_date, $end_date];
            if ($budget_id) {
                $params[] = $budget_id;
            }
            break;
    }
    
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate summary statistics
    if ($report_type === 'variance' && !empty($report_data)) {
        $total_budgeted = array_sum(array_column($report_data, 'budgeted_amount'));
        $total_actual = array_sum(array_column($report_data, 'actual_amount'));
        $summary_stats = [
            'total_budgets' => count(array_unique(array_column($report_data, 'budget_id'))),
            'total_budgeted' => $total_budgeted,
            'total_actual' => $total_actual,
            'total_variance' => $total_actual - $total_budgeted,
            'avg_variance_pct' => $total_budgeted > 0 ? (($total_actual - $total_budgeted) / $total_budgeted) * 100 : 0
        ];
    } elseif ($report_type === 'performance' && !empty($report_data)) {
        $summary_stats = [
            'total_budgets' => count($report_data),
            'total_budgeted' => array_sum(array_column($report_data, 'total_budget_amount')),
            'total_actual' => array_sum(array_column($report_data, 'total_actual_amount')),
            'total_variance' => array_sum(array_column($report_data, 'total_variance')),
            'avg_variance_pct' => count($report_data) > 0 ? array_sum(array_filter(array_column($report_data, 'total_variance_pct'))) / count(array_filter(array_column($report_data, 'total_variance_pct'))) : 0
        ];
    } elseif ($report_type === 'category' && !empty($report_data)) {
        $summary_stats = [
            'total_budgets' => array_sum(array_column($report_data, 'budgets_count')),
            'total_budgeted' => array_sum(array_column($report_data, 'total_budgeted')),
            'total_actual' => array_sum(array_column($report_data, 'total_actual')),
            'total_variance' => array_sum(array_column($report_data, 'total_variance')),
            'avg_variance_pct' => count($report_data) > 0 ? array_sum(array_filter(array_column($report_data, 'variance_percentage'))) / count(array_filter(array_column($report_data, 'variance_percentage'))) : 0
        ];
    }
    
} catch (Exception $e) {
    $error_message = "Error generating report: " . $e->getMessage();
    $report_data = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Budget Reports - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .report-card {
            transition: all 0.3s ease;
            border-left: 4px solid transparent;
        }
        .report-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .variance-positive { 
            color: #dc3545; 
            background: rgba(220, 53, 69, 0.1);
            padding: 2px 6px;
            border-radius: 4px;
        }
        .variance-negative { 
            color: #198754; 
            background: rgba(25, 135, 84, 0.1);
            padding: 2px 6px;
            border-radius: 4px;
        }
        .summary-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
        }
        .filter-section {
            background: #f8f9fa;
            border-radius: 10px;
            border: 1px solid #e9ecef;
        }
        .category-badge {
            padding: 4px 12px;
            border-radius: 20px;
            color: white;
            font-size: 0.85em;
        }
        .chart-container {
            height: 300px;
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
                            <li class="breadcrumb-item"><a href="budget.php">Budget Management</a></li>
                            <li class="breadcrumb-item active">Budget Reports</li>
                        </ol>
                    </nav>
                    <h1><i class="bi bi-graph-up"></i> Budget Reports</h1>
                    <p class="header-subtitle">Comprehensive budget analysis and variance reporting</p>
                </div>
                <div class="header-actions">
                    <button type="button" class="btn btn-primary" onclick="exportReport()">
                        <i class="bi bi-download me-1"></i> Export Report
                    </button>
                    <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
                        <i class="bi bi-printer me-1"></i> Print
                    </button>
                </div>
            </div>
        </header>

        <main class="content">
            <div class="container-fluid">
                <?php if (isset($error_message)): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle me-2"></i><?php echo $error_message; ?>
                </div>
                <?php endif; ?>

                <!-- Filter Section -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card filter-section">
                            <div class="card-body">
                                <form method="GET" class="row g-3 align-items-end">
                                    <div class="col-md-2">
                                        <label class="form-label">Report Type</label>
                                        <select class="form-select" name="report_type">
                                            <option value="variance" <?php echo $report_type === 'variance' ? 'selected' : ''; ?>>Variance Analysis</option>
                                            <option value="performance" <?php echo $report_type === 'performance' ? 'selected' : ''; ?>>Budget Performance</option>
                                            <option value="category" <?php echo $report_type === 'category' ? 'selected' : ''; ?>>Category Analysis</option>
                                            <option value="timeline" <?php echo $report_type === 'timeline' ? 'selected' : ''; ?>>Spending Timeline</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Period</label>
                                        <select class="form-select" name="period">
                                            <option value="current" <?php echo $period_filter === 'current' ? 'selected' : ''; ?>>Current Month</option>
                                            <option value="last_month" <?php echo $period_filter === 'last_month' ? 'selected' : ''; ?>>Last Month</option>
                                            <option value="quarter" <?php echo $period_filter === 'quarter' ? 'selected' : ''; ?>>Current Quarter</option>
                                            <option value="year" <?php echo $period_filter === 'year' ? 'selected' : ''; ?>>Current Year</option>
                                            <option value="custom" <?php echo $period_filter === 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Budget</label>
                                        <select class="form-select" name="budget_id">
                                            <option value="">All Budgets</option>
                                            <?php foreach ($budgets as $b): ?>
                                            <option value="<?php echo $b['id']; ?>" <?php echo $budget_id == $b['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($b['name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Category</label>
                                        <select class="form-select" name="category_id">
                                            <option value="">All Categories</option>
                                            <?php foreach ($categories as $cat): ?>
                                            <option value="<?php echo $cat['id']; ?>" <?php echo $category_id == $cat['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($cat['name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <?php if ($period_filter === 'custom'): ?>
                                    <div class="col-md-2">
                                        <label class="form-label">Start Date</label>
                                        <input type="date" class="form-control" name="custom_start" value="<?php echo $start_date; ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">End Date</label>
                                        <input type="date" class="form-control" name="custom_end" value="<?php echo $end_date; ?>">
                                    </div>
                                    <?php endif; ?>
                                    <div class="col-md-2">
                                        <button type="submit" class="btn btn-primary w-100">
                                            <i class="bi bi-funnel me-1"></i> Apply Filters
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Summary Cards -->
                <?php if ($report_type !== 'timeline'): ?>
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="card summary-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="mb-1 opacity-75">Total Budgeted</h6>
                                        <h4 class="mb-0"><?php echo htmlspecialchars($budget_settings['default_currency'] ?? 'KES'); ?> <?php echo number_format($summary_stats['total_budgeted'], 2); ?></h4>
                                    </div>
                                    <div>
                                        <i class="bi bi-wallet2 fs-1 opacity-75"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="card <?php echo $summary_stats['total_actual'] > $summary_stats['total_budgeted'] ? 'bg-danger' : 'bg-success'; ?> text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="mb-1 opacity-75">Total Actual</h6>
                                        <h4 class="mb-0"><?php echo htmlspecialchars($budget_settings['default_currency'] ?? 'KES'); ?> <?php echo number_format($summary_stats['total_actual'], 2); ?></h4>
                                    </div>
                                    <div>
                                        <i class="bi bi-cash-stack fs-1 opacity-75"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="card <?php echo $summary_stats['total_variance'] >= 0 ? 'bg-warning' : 'bg-info'; ?> text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="mb-1 opacity-75">Total Variance</h6>
                                        <h4 class="mb-0"><?php echo htmlspecialchars($budget_settings['default_currency'] ?? 'KES'); ?> <?php echo number_format(abs($summary_stats['total_variance']), 2); ?></h4>
                                        <small class="opacity-75"><?php echo $summary_stats['total_variance'] >= 0 ? 'Over budget' : 'Under budget'; ?></small>
                                    </div>
                                    <div>
                                        <i class="bi bi-arrow-<?php echo $summary_stats['total_variance'] >= 0 ? 'up' : 'down'; ?> fs-1 opacity-75"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="card bg-secondary text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="mb-1 opacity-75">Avg Variance</h6>
                                        <h4 class="mb-0"><?php echo number_format(abs($summary_stats['avg_variance_pct']), 1); ?>%</h4>
                                        <small class="opacity-75"><?php echo $summary_stats['total_budgets']; ?> budgets</small>
                                    </div>
                                    <div>
                                        <i class="bi bi-percent fs-1 opacity-75"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Report Content -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="mb-0">
                                    <i class="bi bi-file-earmark-text me-2"></i>
                                    <?php 
                                        switch($report_type) {
                                            case 'variance': echo 'Variance Analysis Report'; break;
                                            case 'performance': echo 'Budget Performance Report'; break;
                                            case 'category': echo 'Category Analysis Report'; break;
                                            case 'timeline': echo 'Spending Timeline Report'; break;
                                            default: echo 'Budget Report';
                                        }
                                    ?>
                                </h6>
                            </div>
                            <div class="card-body">
                                <?php if (empty($report_data)): ?>
                                <div class="text-center py-5">
                                    <i class="bi bi-inbox fs-1 text-muted mb-3"></i>
                                    <h5 class="text-muted">No Data Available</h5>
                                    <p class="text-muted">No data found for the selected filters and report type.</p>
                                </div>
                                <?php elseif ($report_type === 'variance'): ?>
                                <!-- Variance Analysis Report -->
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Budget / Item</th>
                                                <th>Category</th>
                                                <th class="text-end">Budgeted</th>
                                                <th class="text-end">Actual</th>
                                                <th class="text-end">Variance</th>
                                                <th class="text-center">Variance %</th>
                                                <th>Priority</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($report_data as $row): ?>
                                            <?php if ($row['item_name']): ?>
                                            <tr class="report-card" style="border-left-color: <?php echo $row['category_color'] ?? '#6366f1'; ?>">
                                                <td>
                                                    <strong><?php echo htmlspecialchars($row['budget_name']); ?></strong>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($row['item_name']); ?></small>
                                                </td>
                                                <td>
                                                    <?php if ($row['category_name']): ?>
                                                    <span class="category-badge" style="background-color: <?php echo $row['category_color'] ?? '#6366f1'; ?>">
                                                        <?php echo htmlspecialchars($row['category_name']); ?>
                                                    </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end">
                                                    <?php echo htmlspecialchars($budget_settings['default_currency'] ?? 'KES'); ?> <?php echo number_format($row['budgeted_amount'], 2); ?>
                                                </td>
                                                <td class="text-end">
                                                    <?php echo htmlspecialchars($budget_settings['default_currency'] ?? 'KES'); ?> <?php echo number_format($row['actual_amount'], 2); ?>
                                                </td>
                                                <td class="text-end">
                                                    <span class="<?php echo $row['variance_amount'] >= 0 ? 'variance-positive' : 'variance-negative'; ?>">
                                                        <?php echo $row['variance_amount'] >= 0 ? '+' : ''; ?><?php echo htmlspecialchars($budget_settings['default_currency'] ?? 'KES'); ?><?php echo number_format($row['variance_amount'], 2); ?>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($row['variance_percentage'] !== null): ?>
                                                    <span class="<?php echo $row['variance_percentage'] >= 0 ? 'variance-positive' : 'variance-negative'; ?>">
                                                        <?php echo $row['variance_percentage'] >= 0 ? '+' : ''; ?><?php echo number_format($row['variance_percentage'], 1); ?>%
                                                    </span>
                                                    <?php else: ?>
                                                    <span class="text-muted">N/A</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php echo $row['priority'] === 'high' ? 'danger' : ($row['priority'] === 'medium' ? 'warning' : 'secondary'); ?>">
                                                        <?php echo ucfirst($row['priority']); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <?php endif; ?>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <?php elseif ($report_type === 'performance'): ?>
                                <!-- Budget Performance Report -->
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Budget Name</th>
                                                <th>Type</th>
                                                <th>Period</th>
                                                <th>Status</th>
                                                <th class="text-end">Total Budget</th>
                                                <th class="text-end">Total Actual</th>
                                                <th class="text-end">Variance</th>
                                                <th class="text-center">Performance</th>
                                                <th>Items</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($report_data as $row): ?>
                                            <tr class="report-card">
                                                <td>
                                                    <strong><?php echo htmlspecialchars($row['budget_name']); ?></strong>
                                                    <br><small class="text-muted">by <?php echo htmlspecialchars($row['created_by_name']); ?></small>
                                                </td>
                                                <td>
                                                    <span class="badge bg-secondary"><?php echo ucfirst($row['budget_type']); ?></span>
                                                </td>
                                                <td>
                                                    <small><?php echo date('M d', strtotime($row['start_date'])); ?> - <?php echo date('M d, Y', strtotime($row['end_date'])); ?></small>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php echo $row['status'] === 'active' ? 'success' : ($row['status'] === 'draft' ? 'warning' : 'secondary'); ?>">
                                                        <?php echo ucfirst($row['status']); ?>
                                                    </span>
                                                </td>
                                                <td class="text-end">
                                                    <?php echo htmlspecialchars($budget_settings['default_currency'] ?? 'KES'); ?> <?php echo number_format($row['total_budget_amount'], 2); ?>
                                                </td>
                                                <td class="text-end">
                                                    <?php echo htmlspecialchars($budget_settings['default_currency'] ?? 'KES'); ?> <?php echo number_format($row['total_actual_amount'], 2); ?>
                                                </td>
                                                <td class="text-end">
                                                    <span class="<?php echo $row['total_variance'] >= 0 ? 'variance-positive' : 'variance-negative'; ?>">
                                                        <?php echo $row['total_variance'] >= 0 ? '+' : ''; ?><?php echo htmlspecialchars($budget_settings['default_currency'] ?? 'KES'); ?><?php echo number_format($row['total_variance'], 2); ?>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($row['total_variance_pct'] !== null): ?>
                                                    <span class="<?php echo $row['total_variance_pct'] >= 0 ? 'variance-positive' : 'variance-negative'; ?>">
                                                        <?php echo $row['total_variance_pct'] >= 0 ? '+' : ''; ?><?php echo number_format($row['total_variance_pct'], 1); ?>%
                                                    </span>
                                                    <?php else: ?>
                                                    <span class="text-muted">N/A</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-info"><?php echo $row['items_count']; ?> items</span>
                                                    <?php if ($row['over_budget_items'] > 0): ?>
                                                    <br><span class="badge bg-warning"><?php echo $row['over_budget_items']; ?> over budget</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <?php elseif ($report_type === 'category'): ?>
                                <!-- Category Analysis Report -->
                                <div class="row">
                                    <?php foreach ($report_data as $row): ?>
                                    <div class="col-md-6 col-xl-4 mb-3">
                                        <div class="card report-card h-100" style="border-left-color: <?php echo $row['category_color'] ?? '#6366f1'; ?>">
                                            <div class="card-body">
                                                <div class="d-flex align-items-center mb-3">
                                                    <div class="me-3" style="width: 40px; height: 40px; background-color: <?php echo $row['category_color'] ?? '#6366f1'; ?>; border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                                                        <i class="bi bi-folder text-white"></i>
                                                    </div>
                                                    <div class="flex-grow-1">
                                                        <h6 class="mb-1"><?php echo htmlspecialchars($row['category_name']); ?></h6>
                                                        <small class="text-muted"><?php echo $row['budgets_count']; ?> budgets, <?php echo $row['items_count']; ?> items</small>
                                                    </div>
                                                </div>
                                                
                                                <div class="row mb-2">
                                                    <div class="col-6">
                                                        <div class="text-success">
                                                            <small>Budgeted</small><br>
                                                            <strong><?php echo htmlspecialchars($budget_settings['default_currency'] ?? 'KES'); ?> <?php echo number_format($row['total_budgeted'], 2); ?></strong>
                                                        </div>
                                                    </div>
                                                    <div class="col-6 text-end">
                                                        <div class="text-primary">
                                                            <small>Actual</small><br>
                                                            <strong><?php echo htmlspecialchars($budget_settings['default_currency'] ?? 'KES'); ?> <?php echo number_format($row['total_actual'], 2); ?></strong>
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <div class="text-center">
                                                    <div class="<?php echo $row['total_variance'] >= 0 ? 'variance-positive' : 'variance-negative'; ?>">
                                                        <strong>Variance: <?php echo $row['total_variance'] >= 0 ? '+' : ''; ?><?php echo htmlspecialchars($budget_settings['default_currency'] ?? 'KES'); ?><?php echo number_format($row['total_variance'], 2); ?></strong>
                                                    </div>
                                                    <?php if ($row['variance_percentage'] !== null): ?>
                                                    <small class="<?php echo $row['variance_percentage'] >= 0 ? 'variance-positive' : 'variance-negative'; ?>">
                                                        <?php echo $row['variance_percentage'] >= 0 ? '+' : ''; ?><?php echo number_format($row['variance_percentage'], 1); ?>%
                                                    </small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                
                                <?php elseif ($report_type === 'timeline'): ?>
                                <!-- Timeline Report -->
                                <div class="row">
                                    <div class="col-lg-8 mb-4">
                                        <div class="chart-container">
                                            <canvas id="timelineChart"></canvas>
                                        </div>
                                    </div>
                                    <div class="col-lg-4">
                                        <div class="table-responsive">
                                            <table class="table table-sm">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>Date</th>
                                                        <th class="text-end">Spending</th>
                                                        <th class="text-center">Transactions</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach (array_slice($report_data, 0, 15) as $row): ?>
                                                    <tr>
                                                        <td><?php echo date('M d', strtotime($row['transaction_date'])); ?></td>
                                                        <td class="text-end">
                                                            <?php echo htmlspecialchars($budget_settings['default_currency'] ?? 'KES'); ?> <?php echo number_format($row['daily_spending'], 2); ?>
                                                        </td>
                                                        <td class="text-center">
                                                            <span class="badge bg-primary"><?php echo $row['transaction_count']; ?></span>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
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
        // Timeline chart for spending timeline report
        <?php if ($report_type === 'timeline' && !empty($report_data)): ?>
        const timelineCtx = document.getElementById('timelineChart').getContext('2d');
        new Chart(timelineCtx, {
            type: 'line',
            data: {
                labels: [<?php foreach (array_reverse($report_data) as $row): ?>'<?php echo date('M d', strtotime($row['transaction_date'])); ?>',<?php endforeach; ?>],
                datasets: [{
                    label: 'Daily Spending',
                    data: [<?php foreach (array_reverse($report_data) as $row): ?><?php echo $row['daily_spending']; ?>,<?php endforeach; ?>],
                    borderColor: '#667eea',
                    backgroundColor: 'rgba(102, 126, 234, 0.1)',
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
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Amount (<?php echo htmlspecialchars($budget_settings['default_currency'] ?? 'KES'); ?>)'
                        }
                    }
                }
            }
        });
        <?php endif; ?>
        
        function exportReport() {
            let csv = 'Budget Report Export\n';
            csv += 'Report Type: <?php echo ucfirst($report_type); ?>\n';
            csv += 'Period: <?php echo $start_date; ?> to <?php echo $end_date; ?>\n';
            csv += 'Generated: <?php echo date('Y-m-d H:i:s'); ?>\n\n';
            
            <?php if ($report_type !== 'timeline'): ?>
            csv += 'SUMMARY\n';
            csv += 'Total Budgeted,<?php echo $summary_stats['total_budgeted']; ?>\n';
            csv += 'Total Actual,<?php echo $summary_stats['total_actual']; ?>\n';
            csv += 'Total Variance,<?php echo $summary_stats['total_variance']; ?>\n';
            csv += 'Average Variance %,<?php echo number_format($summary_stats['avg_variance_pct'], 2); ?>\n\n';
            <?php endif; ?>
            
            csv += 'REPORT DATA\n';
            <?php if ($report_type === 'variance'): ?>
            csv += 'Budget Name,Item Name,Category,Budgeted Amount,Actual Amount,Variance,Variance %,Priority\n';
            <?php foreach ($report_data as $row): ?>
            <?php if ($row['item_name']): ?>
            csv += '<?php echo addslashes($row['budget_name']); ?>,<?php echo addslashes($row['item_name']); ?>,<?php echo addslashes($row['category_name'] ?? ''); ?>,<?php echo $row['budgeted_amount']; ?>,<?php echo $row['actual_amount']; ?>,<?php echo $row['variance_amount']; ?>,<?php echo $row['variance_percentage'] ?? ''; ?>,<?php echo $row['priority']; ?>\n';
            <?php endif; ?>
            <?php endforeach; ?>
            <?php endif; ?>
            
            // Download
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.setAttribute('hidden', '');
            a.setAttribute('href', url);
            a.setAttribute('download', 'budget-report-<?php echo $report_type; ?>-<?php echo date('Y-m-d'); ?>.csv');
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        }
    </script>
</body>
</html>
