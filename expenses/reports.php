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
$user_role = $_SESSION['role'] ?? $_SESSION['role_name'] ?? 'User';
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

// Check permissions
if (!hasPermission('view_expense_reports', $permissions)) {
    header('Location: index.php');
    exit();
}

// Get filter parameters
$period = $_GET['period'] ?? 'month';
$year = $_GET['year'] ?? date('Y');
$month = $_GET['month'] ?? date('m');
$category_filter = $_GET['category'] ?? '';
$department_filter = $_GET['department'] ?? '';

// Calculate date range
switch ($period) {
    case 'week':
        $start_date = date('Y-m-d', strtotime('monday this week'));
        $end_date = date('Y-m-d', strtotime('sunday this week'));
        break;
    case 'month':
        $start_date = "$year-$month-01";
        $end_date = date('Y-m-t', strtotime($start_date));
        break;
    case 'quarter':
        $quarter = ceil($month / 3);
        $start_month = ($quarter - 1) * 3 + 1;
        $start_date = "$year-" . str_pad($start_month, 2, '0', STR_PAD_LEFT) . "-01";
        $end_date = date('Y-m-t', strtotime($start_date . ' +2 months'));
        break;
    case 'year':
        $start_date = "$year-01-01";
        $end_date = "$year-12-31";
        break;
    default:
        $start_date = date('Y-m-01');
        $end_date = date('Y-m-t');
}

// Build where conditions
$where_conditions = ["e.expense_date BETWEEN ? AND ?"];
$params = [$start_date, $end_date];

if ($category_filter) {
    $where_conditions[] = "e.category_id = ?";
    $params[] = $category_filter;
}

if ($department_filter) {
    $where_conditions[] = "e.department_id = ?";
    $params[] = $department_filter;
}

$where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

// Get summary statistics
$summary_query = "
    SELECT 
        COUNT(*) as total_expenses,
        SUM(total_amount) as total_amount,
        SUM(tax_amount) as total_tax,
        AVG(total_amount) as avg_amount,
        SUM(CASE WHEN is_tax_deductible = 1 THEN total_amount ELSE 0 END) as tax_deductible_amount,
        SUM(CASE WHEN payment_status = 'pending' THEN total_amount ELSE 0 END) as pending_amount,
        SUM(CASE WHEN approval_status = 'pending' THEN 1 ELSE 0 END) as pending_approvals
    FROM expenses e
    $where_clause
";

$stmt = $conn->prepare($summary_query);
$stmt->execute($params);
$summary = $stmt->fetch(PDO::FETCH_ASSOC);

// Get expenses by category
$category_query = "
    SELECT 
        ec.name as category_name,
        ec.color_code,
        COUNT(*) as expense_count,
        SUM(e.total_amount) as total_amount,
        AVG(e.total_amount) as avg_amount
    FROM expenses e
    JOIN expense_categories ec ON e.category_id = ec.id
    $where_clause
    GROUP BY ec.id, ec.name, ec.color_code
    ORDER BY total_amount DESC
";

$stmt = $conn->prepare($category_query);
$stmt->execute($params);
$category_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get expenses by department
$department_query = "
    SELECT 
        ed.name as department_name,
        COUNT(*) as expense_count,
        SUM(e.total_amount) as total_amount,
        AVG(e.total_amount) as avg_amount
    FROM expenses e
    JOIN expense_departments ed ON e.department_id = ed.id
    $where_clause
    GROUP BY ed.id, ed.name
    ORDER BY total_amount DESC
";

$stmt = $conn->prepare($department_query);
$stmt->execute($params);
$department_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get monthly trend data
$trend_query = "
    SELECT 
        DATE_FORMAT(expense_date, '%Y-%m') as month,
        COUNT(*) as expense_count,
        SUM(total_amount) as total_amount
    FROM expenses e
    $where_clause
    GROUP BY DATE_FORMAT(expense_date, '%Y-%m')
    ORDER BY month
";

$stmt = $conn->prepare($trend_query);
$stmt->execute($params);
$trend_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get top vendors
$vendor_query = "
    SELECT 
        ev.name as vendor_name,
        COUNT(*) as expense_count,
        SUM(e.total_amount) as total_amount
    FROM expenses e
    JOIN expense_vendors ev ON e.vendor_id = ev.id
    $where_clause
    GROUP BY ev.id, ev.name
    ORDER BY total_amount DESC
    LIMIT 10
";

$stmt = $conn->prepare($vendor_query);
$stmt->execute($params);
$vendor_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get filter options
$categories = $conn->query("SELECT id, name FROM expense_categories WHERE parent_id IS NULL AND is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$departments = $conn->query("SELECT id, name FROM expense_departments WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expense Reports - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
    </style>
</head>
<body>
    <?php include '../include/navmenu.php'; ?>

    <div class="main-content">
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1><i class="bi bi-graph-up"></i> Expense Reports</h1>
                    <p class="header-subtitle">Analyze and track your expense data</p>
                </div>
                <div class="header-actions">
                    <button type="button" class="btn btn-outline-primary" onclick="window.print()">
                        <i class="bi bi-printer"></i> Print Report
                    </button>
                    <a href="export_report.php?<?= http_build_query($_GET) ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-download"></i> Export
                    </a>
                    <a href="index.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Back to Expenses
                    </a>
                </div>
            </div>
        </header>

        <main class="content">
            <div class="container-fluid">

                <?php if (($summary['total_expenses'] ?? 0) == 0): ?>
                <div class="alert alert-info mb-4">
                    <div class="d-flex align-items-center">
                        <i class="bi bi-info-circle fs-4 me-3"></i>
                        <div>
                            <h6 class="mb-1">No Expenses Found</h6>
                            <p class="mb-0">
                                <?php if ($category_filter || $department_filter): ?>
                                    No expenses found for the selected filters in this period. Try adjusting your filters or selecting a different time period.
                                <?php else: ?>
                                    No expenses have been recorded for this period. Start by adding some expenses to see your reports.
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Filters -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-funnel"></i> Report Filters</h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-2">
                                <label class="form-label">Period</label>
                                <select class="form-select" name="period">
                                    <option value="week" <?= $period == 'week' ? 'selected' : '' ?>>This Week</option>
                                    <option value="month" <?= $period == 'month' ? 'selected' : '' ?>>This Month</option>
                                    <option value="quarter" <?= $period == 'quarter' ? 'selected' : '' ?>>This Quarter</option>
                                    <option value="year" <?= $period == 'year' ? 'selected' : '' ?>>This Year</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Year</label>
                                <select class="form-select" name="year">
                                    <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                                    <option value="<?= $y ?>" <?= $year == $y ? 'selected' : '' ?>><?= $y ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Month</label>
                                <select class="form-select" name="month">
                                    <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?= str_pad($m, 2, '0', STR_PAD_LEFT) ?>" <?= $month == str_pad($m, 2, '0', STR_PAD_LEFT) ? 'selected' : '' ?>>
                                        <?= date('F', mktime(0, 0, 0, $m, 1)) ?>
                                    </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Category</label>
                                <select class="form-select" name="category">
                                    <option value="">All Categories</option>
                                    <?php foreach ($categories as $category): ?>
                                    <option value="<?= $category['id'] ?>" <?= $category_filter == $category['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($category['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Department</label>
                                <select class="form-select" name="department">
                                    <option value="">All Departments</option>
                                    <?php foreach ($departments as $department): ?>
                                    <option value="<?= $department['id'] ?>" <?= $department_filter == $department['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($department['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">&nbsp;</label>
                                <div>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-search"></i> Generate Report
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Summary Statistics -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card bg-primary text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="card-title">Total Expenses</h6>
                                        <h3><?= number_format($summary['total_expenses']) ?></h3>
                                        <small>Period: <?= date('M d', strtotime($start_date)) ?> - <?= date('M d', strtotime($end_date)) ?></small>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="bi bi-cash-stack fs-1"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-success text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="card-title">Total Amount</h6>
                                        <h3><?php 
                                            $total = $summary['total_amount'] ?? 0;
                                            if ($total >= 1000000) {
                                                echo 'KES ' . number_format($total / 1000000, 1) . 'M';
                                            } elseif ($total >= 1000) {
                                                echo 'KES ' . number_format($total / 1000, 1) . 'K';
                                            } else {
                                                echo 'KES ' . number_format($total, 2);
                                            }
                                        ?></h3>
                                        <small>Avg: <?php 
                                            $avg = $summary['avg_amount'] ?? 0;
                                            if ($avg >= 1000000) {
                                                echo 'KES ' . number_format($avg / 1000000, 1) . 'M';
                                            } elseif ($avg >= 1000) {
                                                echo 'KES ' . number_format($avg / 1000, 1) . 'K';
                                            } else {
                                                echo 'KES ' . number_format($avg, 2);
                                            }
                                        ?></small>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="bi bi-currency-dollar fs-1"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-info text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="card-title">Tax Deductible</h6>
                                        <h3><?php 
                                            $tax_deductible = $summary['tax_deductible_amount'] ?? 0;
                                            if ($tax_deductible >= 1000000) {
                                                echo 'KES ' . number_format($tax_deductible / 1000000, 1) . 'M';
                                            } elseif ($tax_deductible >= 1000) {
                                                echo 'KES ' . number_format($tax_deductible / 1000, 1) . 'K';
                                            } else {
                                                echo 'KES ' . number_format($tax_deductible, 2);
                                            }
                                        ?></h3>
                                        <small><?= ($summary['total_amount'] ?? 0) > 0 ? round((($summary['tax_deductible_amount'] ?? 0) / ($summary['total_amount'] ?? 1)) * 100, 1) : 0 ?>% of total</small>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="bi bi-receipt fs-1"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-warning text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="card-title">Pending Payments</h6>
                                        <h3><?php 
                                            $pending = $summary['pending_amount'] ?? 0;
                                            if ($pending >= 1000000) {
                                                echo 'KES ' . number_format($pending / 1000000, 1) . 'M';
                                            } elseif ($pending >= 1000) {
                                                echo 'KES ' . number_format($pending / 1000, 1) . 'K';
                                            } else {
                                                echo 'KES ' . number_format($pending, 2);
                                            }
                                        ?></h3>
                                <small><?= $summary['pending_approvals'] ?? 0 ?> pending approvals</small>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="bi bi-clock fs-1"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Charts Row -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Expenses by Category</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($category_data)): ?>
                                <div class="text-center py-5">
                                    <i class="bi bi-pie-chart fs-1 text-muted"></i>
                                    <h6 class="mt-3 text-muted">No Category Data</h6>
                                    <p class="text-muted mb-0">
                                        <?php if ($category_filter): ?>
                                            No expenses found for the selected category in this period.
                                        <?php else: ?>
                                            No expenses recorded for this period.
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <?php else: ?>
                                <canvas id="categoryChart" height="300"></canvas>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Monthly Trend</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($trend_data)): ?>
                                <div class="text-center py-5">
                                    <i class="bi bi-graph-up fs-1 text-muted"></i>
                                    <h6 class="mt-3 text-muted">No Trend Data</h6>
                                    <p class="text-muted mb-0">
                                        <?php if ($category_filter || $department_filter): ?>
                                            No expenses found for the selected filters in this period.
                                        <?php else: ?>
                                            No expenses recorded for this period.
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <?php else: ?>
                                <canvas id="trendChart" height="300"></canvas>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Detailed Tables -->
                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">Top Categories</h5>
                                    <div class="btn-group" role="group">
                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="toggleCategorySelection()">
                                            <i class="bi bi-check-all"></i> Select All
                                        </button>
                                        <div class="btn-group" role="group">
                                            <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" disabled id="categoryBulkActionsBtn">
                                                <i class="bi bi-gear"></i> Bulk Actions
                                            </button>
                                            <ul class="dropdown-menu">
                                                <li><a class="dropdown-item" href="#" onclick="bulkExportCategories()"><i class="bi bi-download"></i> Export Selected</a></li>
                                                <li><a class="dropdown-item" href="#" onclick="bulkAnalyzeCategories()"><i class="bi bi-graph-up"></i> Detailed Analysis</a></li>
                                                <li><a class="dropdown-item" href="#" onclick="bulkCompareCategories()"><i class="bi bi-bar-chart"></i> Compare Categories</a></li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if (empty($category_data)): ?>
                                <div class="text-center py-4">
                                    <i class="bi bi-list-ul fs-1 text-muted"></i>
                                    <h6 class="mt-3 text-muted">No Categories Found</h6>
                                    <p class="text-muted mb-0">
                                        <?php if ($category_filter): ?>
                                            No expenses found for the selected category in this period.
                                        <?php else: ?>
                                            No expenses recorded for this period.
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover" id="categoriesTable">
                                        <thead>
                                            <tr>
                                                <th><input type="checkbox" class="form-check-input" id="selectAllCategories" onchange="toggleAllCategories()"></th>
                                                <th>Category</th>
                                                <th>Count</th>
                                                <th>Amount</th>
                                                <th>%</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($category_data as $index => $category): ?>
                                            <tr class="category-row" data-category-id="<?= $index ?>" data-category-name="<?= htmlspecialchars($category['category_name']) ?>" data-category-amount="<?= $category['total_amount'] ?>">
                                                <td>
                                                    <input type="checkbox" class="form-check-input category-checkbox" value="<?= $index ?>" onchange="updateBulkActions()">
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <span class="badge me-2" style="background-color: <?= $category['color_code'] ?>; width: 12px; height: 12px;"></span>
                                                        <strong><?= htmlspecialchars($category['category_name']) ?></strong>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge bg-light text-dark"><?= number_format($category['expense_count']) ?></span>
                                                    <small class="text-muted">expenses</small>
                                                </td>
                                                <td>
                                                    <strong><?php 
                                                        $amount = $category['total_amount'];
                                                        if ($amount >= 1000000) {
                                                            echo 'KES ' . number_format($amount / 1000000, 1) . 'M';
                                                        } elseif ($amount >= 1000) {
                                                            echo 'KES ' . number_format($amount / 1000, 1) . 'K';
                                                        } else {
                                                            echo 'KES ' . number_format($amount, 2);
                                                        }
                                                    ?></strong>
                                                </td>
                                                <td>
                                                    <div class="progress" style="height: 20px;">
                                                        <div class="progress-bar" style="width: <?= $summary['total_amount'] > 0 ? round(($category['total_amount'] / $summary['total_amount']) * 100, 1) : 0 ?>%; background-color: <?= $category['color_code'] ?>">
                                                            <?= $summary['total_amount'] > 0 ? round(($category['total_amount'] / $summary['total_amount']) * 100, 1) : 0 ?>%
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Top Vendors</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($vendor_data)): ?>
                                <div class="text-center py-4">
                                    <i class="bi bi-building fs-1 text-muted"></i>
                                    <h6 class="mt-3 text-muted">No Vendors Found</h6>
                                    <p class="text-muted mb-0">
                                        <?php if ($category_filter || $department_filter): ?>
                                            No expenses found for the selected filters in this period.
                                        <?php else: ?>
                                            No expenses recorded for this period.
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Vendor</th>
                                                <th>Count</th>
                                                <th>Amount</th>
                                                <th>%</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($vendor_data as $vendor): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($vendor['vendor_name']) ?></td>
                                                <td><?= number_format($vendor['expense_count']) ?></td>
                                                <td><?php 
                                                    $amount = $vendor['total_amount'];
                                                    if ($amount >= 1000000) {
                                                        echo 'KES ' . number_format($amount / 1000000, 1) . 'M';
                                                    } elseif ($amount >= 1000) {
                                                        echo 'KES ' . number_format($amount / 1000, 1) . 'K';
                                                    } else {
                                                        echo 'KES ' . number_format($amount, 2);
                                                    }
                                                ?></td>
                                                <td><?= $summary['total_amount'] > 0 ? round(($vendor['total_amount'] / $summary['total_amount']) * 100, 1) : 0 ?>%</td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Category Chart with Enhanced Formatting
        const categoryChartElement = document.getElementById('categoryChart');
        if (categoryChartElement) {
            const categoryCtx = categoryChartElement.getContext('2d');
            const categoryData = <?= json_encode(array_column($category_data, 'total_amount')) ?>;
            
            // Enhanced currency formatting for category chart
            function formatCategoryCurrency(value) {
                if (value >= 1000000) {
                    return 'KES ' + (value / 1000000).toFixed(1) + 'M';
                } else if (value >= 1000) {
                    return 'KES ' + (value / 1000).toFixed(1) + 'K';
                } else {
                    return 'KES ' + value.toLocaleString();
                }
            }
            
            new Chart(categoryCtx, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode(array_column($category_data, 'category_name')) ?>,
                datasets: [{
                    data: categoryData,
                    backgroundColor: <?= json_encode(array_column($category_data, 'color_code')) ?>,
                    borderWidth: 3,
                    borderColor: '#fff',
                    hoverBorderWidth: 4,
                    hoverBorderColor: '#f8f9fa'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '60%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 20,
                            usePointStyle: true,
                            pointStyle: 'circle',
                            font: {
                                size: 12
                            }
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        titleColor: '#ffffff',
                        bodyColor: '#ffffff',
                        borderColor: '#6366f1',
                        borderWidth: 1,
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.parsed;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = ((value / total) * 100).toFixed(1);
                                return label + ': ' + formatCategoryCurrency(value) + ' (' + percentage + '%)';
                            }
                        }
                    }
                },
                elements: {
                    arc: {
                        borderWidth: 3
                    }
                }
            }
        });
        }

        // Trend Chart with Dynamic Scaling
        const trendChartElement = document.getElementById('trendChart');
        if (trendChartElement) {
            const trendCtx = trendChartElement.getContext('2d');
            const trendData = <?= json_encode(array_column($trend_data, 'total_amount')) ?>;
        
        // Calculate dynamic Y-axis scaling
        const maxValue = trendData.length > 0 ? Math.max(...trendData) : 0;
        const minValue = trendData.length > 0 ? Math.min(...trendData) : 0;
        
        // Ensure we always have some data to display
        const hasData = trendData.length > 0 && maxValue > 0;
        
        // Smart scaling function
        function calculateSmartScale(max, min) {
            // Handle case when there's no data
            if (max === 0 && min === 0) {
                return {
                    min: 0,
                    max: 1000,
                    step: 100
                };
            }
            
            // For very small amounts, ensure minimum visibility
            const actualMax = Math.max(max, 50); // Minimum max of 50
            const actualMin = Math.min(min, 0);   // Minimum min of 0
            
            const range = actualMax - actualMin;
            const padding = Math.max(range * 0.2, 5); // At least 5 units padding, 20% of range
            
            // Calculate appropriate step size for better visibility
            let step;
            if (actualMax < 50) {
                step = 5;
            } else if (actualMax < 100) {
                step = 10;
            } else if (actualMax < 1000) {
                step = 100;
            } else if (actualMax < 10000) {
                step = 1000;
            } else if (actualMax < 100000) {
                step = 10000;
            } else if (actualMax < 1000000) {
                step = 100000;
            } else {
                step = 1000000;
            }
            
            // Calculate nice min and max values
            const niceMin = Math.floor((actualMin - padding) / step) * step;
            const niceMax = Math.ceil((actualMax + padding) / step) * step;
            
            return {
                min: Math.max(0, niceMin),
                max: Math.max(niceMax, actualMax + padding),
                step: step
            };
        }
        
        const scale = calculateSmartScale(maxValue, minValue);
        
        // Debug logging (remove in production)
        console.log('Chart Data:', {
            trendData: trendData,
            maxValue: maxValue,
            minValue: minValue,
            scale: scale
        });
        
        // Format currency with appropriate units
        function formatCurrency(value) {
            if (value >= 1000000) {
                return 'KES ' + (value / 1000000).toFixed(1) + 'M';
            } else if (value >= 1000) {
                return 'KES ' + (value / 1000).toFixed(1) + 'K';
            } else {
                return 'KES ' + value.toLocaleString();
            }
        }
        
        // Create chart with fallback configuration
        const chartConfig = {
            type: 'line',
            data: {
                labels: <?= json_encode(array_map(function($item) { return date('M Y', strtotime($item['month'] . '-01')); }, $trend_data)) ?>,
                datasets: [{
                    label: 'Total Amount',
                    data: trendData,
                    borderColor: '#6366f1',
                    backgroundColor: 'rgba(99, 102, 241, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#6366f1',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointRadius: 6,
                    pointHoverRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        titleColor: '#ffffff',
                        bodyColor: '#ffffff',
                        borderColor: '#6366f1',
                        borderWidth: 1,
                        callbacks: {
                            label: function(context) {
                                return 'Total: ' + formatCurrency(context.parsed.y);
                            },
                            afterLabel: function(context) {
                                const dataIndex = context.dataIndex;
                                const expenseCount = <?= json_encode(array_column($trend_data, 'expense_count')) ?>[dataIndex];
                                return 'Expenses: ' + expenseCount.toLocaleString();
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        min: scale.min,
                        max: scale.max,
                        ticks: {
                            stepSize: scale.step,
                            maxTicksLimit: 10,
                            callback: function(value) {
                                return formatCurrency(value);
                            },
                            color: '#6b7280',
                            font: {
                                size: 11
                            }
                        },
                        grid: {
                            color: 'rgba(107, 114, 128, 0.1)',
                            drawBorder: false
                        }
                    },
                    x: {
                        ticks: {
                            color: '#6b7280',
                            font: {
                                size: 11
                            }
                        },
                        grid: {
                            display: false
                        }
                    }
                },
                interaction: {
                    intersect: false,
                    mode: 'index'
                },
                elements: {
                    point: {
                        hoverBackgroundColor: '#4f46e5'
                    }
                }
            }
        };
        
        // Create the chart with error handling
        try {
            new Chart(trendCtx, chartConfig);
        } catch (error) {
            console.error('Chart creation error:', error);
            // Fallback: Create a simple chart without custom scaling
            new Chart(trendCtx, {
                type: 'line',
                data: {
                    labels: <?= json_encode(array_map(function($item) { return date('M Y', strtotime($item['month'] . '-01')); }, $trend_data)) ?>,
                    datasets: [{
                        label: 'Total Amount',
                        data: trendData,
                        borderColor: '#6366f1',
                        backgroundColor: 'rgba(99, 102, 241, 0.1)',
                        borderWidth: 2,
                        fill: true,
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
                                    return 'KES ' + value.toLocaleString();
                                }
                            }
                        }
                    }
                }
            });
        }
        }

        // Bulk Actions for Categories
        function toggleAllCategories() {
            const selectAll = document.getElementById('selectAllCategories');
            const checkboxes = document.querySelectorAll('.category-checkbox');
            
            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAll.checked;
            });
            
            updateBulkActions();
        }

        function toggleCategorySelection() {
            const selectAll = document.getElementById('selectAllCategories');
            selectAll.checked = !selectAll.checked;
            toggleAllCategories();
        }

        function updateBulkActions() {
            const checkboxes = document.querySelectorAll('.category-checkbox:checked');
            const bulkActionsBtn = document.getElementById('categoryBulkActionsBtn');
            const selectAllBtn = document.getElementById('selectAllCategories');
            
            if (checkboxes.length > 0) {
                bulkActionsBtn.disabled = false;
                bulkActionsBtn.innerHTML = `<i class="bi bi-gear"></i> Bulk Actions (${checkboxes.length})`;
            } else {
                bulkActionsBtn.disabled = true;
                bulkActionsBtn.innerHTML = '<i class="bi bi-gear"></i> Bulk Actions';
            }
            
            // Update select all checkbox state
            const allCheckboxes = document.querySelectorAll('.category-checkbox');
            if (checkboxes.length === 0) {
                selectAllBtn.indeterminate = false;
                selectAllBtn.checked = false;
            } else if (checkboxes.length === allCheckboxes.length) {
                selectAllBtn.indeterminate = false;
                selectAllBtn.checked = true;
            } else {
                selectAllBtn.indeterminate = true;
            }
        }

        function bulkExportCategories() {
            const selectedCategories = getSelectedCategories();
            if (selectedCategories.length === 0) {
                alert('Please select at least one category to export.');
                return;
            }

            // Create export data
            let csvContent = "Category,Expense Count,Total Amount,Percentage\n";
            selectedCategories.forEach(category => {
                const row = document.querySelector(`tr[data-category-id="${category}"]`);
                const categoryName = row.dataset.categoryName;
                const categoryAmount = parseFloat(row.dataset.categoryAmount);
                const countCell = row.querySelector('td:nth-child(3)').textContent.match(/\d+/)[0];
                const percentageCell = row.querySelector('.progress-bar').textContent.trim();
                
                csvContent += `"${categoryName}",${countCell},${categoryAmount},${percentageCell}\n`;
            });

            // Download CSV
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            if (link.download !== undefined) {
                const url = URL.createObjectURL(blob);
                link.setAttribute('href', url);
                link.setAttribute('download', `category_report_${new Date().toISOString().split('T')[0]}.csv`);
                link.style.visibility = 'hidden';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }
        }

        function bulkAnalyzeCategories() {
            const selectedCategories = getSelectedCategories();
            if (selectedCategories.length === 0) {
                alert('Please select at least one category to analyze.');
                return;
            }

            // Calculate analysis data
            let totalAmount = 0;
            let totalCount = 0;
            let analysisData = [];

            selectedCategories.forEach(category => {
                const row = document.querySelector(`tr[data-category-id="${category}"]`);
                const categoryName = row.dataset.categoryName;
                const categoryAmount = parseFloat(row.dataset.categoryAmount);
                const countText = row.querySelector('td:nth-child(3)').textContent;
                const count = parseInt(countText.match(/\d+/)[0]);
                
                totalAmount += categoryAmount;
                totalCount += count;
                analysisData.push({ name: categoryName, amount: categoryAmount, count: count });
            });

            // Create analysis modal content
            const avgAmount = totalAmount / totalCount;
            let analysisHTML = `
                <div class="modal fade" id="categoryAnalysisModal" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Category Analysis - ${selectedCategories.length} Categories Selected</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="row mb-3">
                                    <div class="col-md-4">
                                        <div class="card bg-primary text-white">
                                            <div class="card-body text-center">
                                                <h4>KES ${totalAmount.toLocaleString()}</h4>
                                                <small>Total Amount</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="card bg-success text-white">
                                            <div class="card-body text-center">
                                                <h4>${totalCount.toLocaleString()}</h4>
                                                <small>Total Expenses</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="card bg-info text-white">
                                            <div class="card-body text-center">
                                                <h4>KES ${avgAmount.toLocaleString()}</h4>
                                                <small>Average Amount</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <h6>Breakdown by Category:</h6>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Category</th>
                                                <th>Amount</th>
                                                <th>Count</th>
                                                <th>Avg per Expense</th>
                                                <th>% of Selection</th>
                                            </tr>
                                        </thead>
                                        <tbody>`;
                                        
            analysisData.forEach(item => {
                const avgPerExpense = item.amount / item.count;
                const percentage = (item.amount / totalAmount * 100).toFixed(1);
                analysisHTML += `
                    <tr>
                        <td><strong>${item.name}</strong></td>
                        <td>KES ${item.amount.toLocaleString()}</td>
                        <td>${item.count.toLocaleString()}</td>
                        <td>KES ${avgPerExpense.toLocaleString()}</td>
                        <td><span class="badge bg-primary">${percentage}%</span></td>
                    </tr>`;
            });
                                        
            analysisHTML += `
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-primary" onclick="exportAnalysis()">Export Analysis</button>
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Remove existing modal if present
            const existingModal = document.getElementById('categoryAnalysisModal');
            if (existingModal) {
                existingModal.remove();
            }
            
            // Add modal to body and show
            document.body.insertAdjacentHTML('beforeend', analysisHTML);
            const modal = new bootstrap.Modal(document.getElementById('categoryAnalysisModal'));
            modal.show();
        }

        function bulkCompareCategories() {
            const selectedCategories = getSelectedCategories();
            if (selectedCategories.length < 2) {
                alert('Please select at least 2 categories to compare.');
                return;
            }
            
            if (selectedCategories.length > 6) {
                alert('Please select no more than 6 categories for comparison.');
                return;
            }

            // Collect comparison data
            const comparisonData = [];
            const labels = [];
            const amounts = [];
            const counts = [];
            const colors = ['#ff6384', '#36a2eb', '#ffcd56', '#4bc0c0', '#9966ff', '#ff9f40'];

            selectedCategories.forEach((category, index) => {
                const row = document.querySelector(`tr[data-category-id="${category}"]`);
                const categoryName = row.dataset.categoryName;
                const categoryAmount = parseFloat(row.dataset.categoryAmount);
                const countText = row.querySelector('td:nth-child(3)').textContent;
                const count = parseInt(countText.match(/\d+/)[0]);
                
                labels.push(categoryName);
                amounts.push(categoryAmount);
                counts.push(count);
                comparisonData.push({ name: categoryName, amount: categoryAmount, count: count, color: colors[index] });
            });

            // Create comparison modal
            const comparisonHTML = `
                <div class="modal fade" id="categoryComparisonModal" tabindex="-1">
                    <div class="modal-dialog modal-xl">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Category Comparison - ${selectedCategories.length} Categories</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6>Amount Comparison</h6>
                                        <canvas id="comparisonAmountChart" height="300"></canvas>
                                    </div>
                                    <div class="col-md-6">
                                        <h6>Count Comparison</h6>
                                        <canvas id="comparisonCountChart" height="300"></canvas>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Remove existing modal if present
            const existingModal = document.getElementById('categoryComparisonModal');
            if (existingModal) {
                existingModal.remove();
            }
            
            // Add modal to body and show
            document.body.insertAdjacentHTML('beforeend', comparisonHTML);
            const modal = new bootstrap.Modal(document.getElementById('categoryComparisonModal'));
            modal.show();
            
            // Create comparison charts after modal is shown
            setTimeout(() => {
                // Amount comparison chart
                const amountCtx = document.getElementById('comparisonAmountChart').getContext('2d');
                new Chart(amountCtx, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Total Amount (KES)',
                            data: amounts,
                            backgroundColor: colors.slice(0, labels.length)
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false }
                        }
                    }
                });
                
                // Count comparison chart
                const countCtx = document.getElementById('comparisonCountChart').getContext('2d');
                new Chart(countCtx, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Expense Count',
                            data: counts,
                            backgroundColor: colors.slice(0, labels.length)
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false }
                        }
                    }
                });
            }, 300);
        }

        function getSelectedCategories() {
            const checkboxes = document.querySelectorAll('.category-checkbox:checked');
            return Array.from(checkboxes).map(cb => cb.value);
        }

        function exportAnalysis() {
            const selectedCategories = getSelectedCategories();
            let csvContent = "Category Analysis Report\n\n";
            csvContent += "Category,Amount,Count,Avg per Expense,% of Selection\n";
            
            let totalAmount = 0;
            selectedCategories.forEach(category => {
                const row = document.querySelector(`tr[data-category-id="${category}"]`);
                const categoryAmount = parseFloat(row.dataset.categoryAmount);
                totalAmount += categoryAmount;
            });
            
            selectedCategories.forEach(category => {
                const row = document.querySelector(`tr[data-category-id="${category}"]`);
                const categoryName = row.dataset.categoryName;
                const categoryAmount = parseFloat(row.dataset.categoryAmount);
                const countText = row.querySelector('td:nth-child(3)').textContent;
                const count = parseInt(countText.match(/\d+/)[0]);
                const avgPerExpense = categoryAmount / count;
                const percentage = (categoryAmount / totalAmount * 100).toFixed(1);
                
                csvContent += `"${categoryName}",${categoryAmount},${count},${avgPerExpense.toFixed(2)},${percentage}%\n`;
            });

            // Download CSV
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            if (link.download !== undefined) {
                const url = URL.createObjectURL(blob);
                link.setAttribute('href', url);
                link.setAttribute('download', `category_analysis_${new Date().toISOString().split('T')[0]}.csv`);
                link.style.visibility = 'hidden';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }
        }
    </script>
</body>
</html>
