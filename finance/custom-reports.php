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

// Handle form submissions for report generation
$report_data = [];
$report_generated = false;
$error_message = '';

if ($_POST && isset($_POST['generate_report'])) {
    $report_type = $_POST['report_type'] ?? '';
    $start_date = $_POST['start_date'] ?? date('Y-m-01');
    $end_date = $_POST['end_date'] ?? date('Y-m-t');
    $group_by = $_POST['group_by'] ?? 'day';
    $filters = $_POST['filters'] ?? [];
    $chart_type = $_POST['chart_type'] ?? 'line';
    
    try {
        switch ($report_type) {
            case 'sales_summary':
                $report_data = generateSalesSummaryReport($conn, $start_date, $end_date, $group_by, $filters);
                break;
            case 'product_performance':
                $report_data = generateProductPerformanceReport($conn, $start_date, $end_date, $filters);
                break;
            case 'customer_analysis':
                $report_data = generateCustomerAnalysisReport($conn, $start_date, $end_date, $filters);
                break;
            case 'expense_breakdown':
                $report_data = generateExpenseBreakdownReport($conn, $start_date, $end_date, $group_by, $filters);
                break;
            case 'profit_analysis':
                $report_data = generateProfitAnalysisReport($conn, $start_date, $end_date, $group_by);
                break;
            case 'inventory_valuation':
                $report_data = generateInventoryValuationReport($conn, $filters);
                break;
            default:
                $error_message = 'Please select a valid report type.';
        }
        
        if (!empty($report_data)) {
            $report_generated = true;
        }
    } catch (Exception $e) {
        $error_message = 'Error generating report: ' . $e->getMessage();
    }
}

// Report generation functions
function generateSalesSummaryReport($conn, $start_date, $end_date, $group_by, $filters) {
    $group_sql = '';
    $select_group = '';
    
    switch ($group_by) {
        case 'day':
            $group_sql = 'GROUP BY DATE(sale_date)';
            $select_group = 'DATE(sale_date) as period';
            break;
        case 'week':
            $group_sql = 'GROUP BY YEAR(sale_date), WEEK(sale_date)';
            $select_group = 'CONCAT(YEAR(sale_date), "-W", LPAD(WEEK(sale_date), 2, "0")) as period';
            break;
        case 'month':
            $group_sql = 'GROUP BY YEAR(sale_date), MONTH(sale_date)';
            $select_group = 'CONCAT(YEAR(sale_date), "-", LPAD(MONTH(sale_date), 2, "0")) as period';
            break;
    }
    
    $where_conditions = ["sale_date BETWEEN ? AND ?"];
    $params = [$start_date, $end_date];
    
    if (!empty($filters['payment_method'])) {
        $where_conditions[] = "payment_method = ?";
        $params[] = $filters['payment_method'];
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    $stmt = $conn->prepare("
        SELECT 
            $select_group,
            COUNT(*) as transaction_count,
            SUM(final_amount) as total_revenue,
            AVG(final_amount) as avg_transaction,
            SUM(discount_amount) as total_discounts
        FROM sales 
        WHERE $where_clause
        $group_sql
        ORDER BY period ASC
    ");
    
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function generateProductPerformanceReport($conn, $start_date, $end_date, $filters) {
    $where_conditions = ["s.sale_date BETWEEN ? AND ?"];
    $params = [$start_date, $end_date];
    
    if (!empty($filters['category'])) {
        $where_conditions[] = "p.category_id = ?";
        $params[] = $filters['category'];
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    $stmt = $conn->prepare("
        SELECT 
            p.name as product_name,
            c.name as category_name,
            SUM(si.quantity) as total_sold,
            SUM(si.quantity * si.price) as total_revenue,
            SUM(si.quantity * p.cost_price) as total_cost,
            AVG(si.price) as avg_selling_price,
            p.quantity as current_stock
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        JOIN sale_items si ON p.id = si.product_id
        JOIN sales s ON si.sale_id = s.id
        WHERE $where_clause
        GROUP BY p.id, p.name, c.name, p.quantity
        ORDER BY total_revenue DESC
        LIMIT 50
    ");
    
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function generateCustomerAnalysisReport($conn, $start_date, $end_date, $filters) {
    $where_conditions = ["sale_date BETWEEN ? AND ?", "customer_name IS NOT NULL", "customer_name != ''"];
    $params = [$start_date, $end_date];
    
    $where_clause = implode(' AND ', $where_conditions);
    
    $stmt = $conn->prepare("
        SELECT 
            customer_name,
            COUNT(*) as purchase_count,
            SUM(final_amount) as total_spent,
            AVG(final_amount) as avg_purchase,
            MAX(sale_date) as last_purchase,
            MIN(sale_date) as first_purchase
        FROM sales 
        WHERE $where_clause
        GROUP BY customer_name
        ORDER BY total_spent DESC
        LIMIT 50
    ");
    
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function generateExpenseBreakdownReport($conn, $start_date, $end_date, $group_by, $filters) {
    try {
        $group_sql = '';
        $select_group = '';
        
        switch ($group_by) {
            case 'category':
                $group_sql = 'GROUP BY expense_category';
                $select_group = 'expense_category as period';
                break;
            case 'month':
                $group_sql = 'GROUP BY YEAR(expense_date), MONTH(expense_date)';
                $select_group = 'CONCAT(YEAR(expense_date), "-", LPAD(MONTH(expense_date), 2, "0")) as period';
                break;
            case 'vendor':
                $group_sql = 'GROUP BY vendor_name';
                $select_group = 'vendor_name as period';
                break;
        }
        
        $where_conditions = ["expense_date BETWEEN ? AND ?"];
        $params = [$start_date, $end_date];
        
        if (!empty($filters['category'])) {
            $where_conditions[] = "expense_category = ?";
            $params[] = $filters['category'];
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        
        $stmt = $conn->prepare("
            SELECT 
                $select_group,
                COUNT(*) as expense_count,
                SUM(total_amount) as total_amount,
                AVG(total_amount) as avg_amount,
                SUM(CASE WHEN approval_status = 'approved' THEN total_amount ELSE 0 END) as approved_amount
            FROM expenses 
            WHERE $where_clause
            $group_sql
            ORDER BY total_amount DESC
        ");
        
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function generateProfitAnalysisReport($conn, $start_date, $end_date, $group_by) {
    $group_sql = '';
    $select_group = '';
    
    switch ($group_by) {
        case 'day':
            $group_sql = 'GROUP BY DATE(sale_date)';
            $select_group = 'DATE(sale_date) as period';
            break;
        case 'month':
            $group_sql = 'GROUP BY YEAR(sale_date), MONTH(sale_date)';
            $select_group = 'CONCAT(YEAR(sale_date), "-", LPAD(MONTH(sale_date), 2, "0")) as period';
            break;
    }
    
    $stmt = $conn->prepare("
        SELECT 
            $select_group,
            SUM(s.final_amount) as revenue,
            SUM(si.quantity * p.cost_price) as cogs,
            (SUM(s.final_amount) - SUM(si.quantity * p.cost_price)) as gross_profit,
            COUNT(DISTINCT s.id) as transaction_count
        FROM sales s
        JOIN sale_items si ON s.id = si.sale_id
        JOIN products p ON si.product_id = p.id
        WHERE s.sale_date BETWEEN ? AND ?
        $group_sql
        ORDER BY period ASC
    ");
    
    $stmt->execute([$start_date, $end_date]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function generateInventoryValuationReport($conn, $filters) {
    $where_conditions = ["1=1"];
    $params = [];
    
    if (!empty($filters['category'])) {
        $where_conditions[] = "p.category_id = ?";
        $params[] = $filters['category'];
    }
    
    if (!empty($filters['low_stock']) && $filters['low_stock'] === 'yes') {
        $where_conditions[] = "p.quantity <= p.reorder_point";
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    $stmt = $conn->prepare("
        SELECT 
            p.name as product_name,
            c.name as category_name,
            p.quantity,
            p.cost_price,
            p.price as selling_price,
            (p.quantity * p.cost_price) as inventory_value,
            (p.quantity * p.price) as potential_revenue,
            p.reorder_point as reorder_level,
            CASE 
                WHEN p.quantity <= p.reorder_point THEN 'Low Stock'
                WHEN p.quantity = 0 THEN 'Out of Stock'
                ELSE 'In Stock'
            END as stock_status
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE $where_clause
        ORDER BY inventory_value DESC
    ");
    
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get available categories for filters
$stmt = $conn->query("SELECT id, name FROM categories ORDER BY name");
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Custom Reports - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
        .report-card {
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .report-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        .report-builder {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
        }
        .form-section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .chart-container {
            height: 400px;
        }
        .report-table {
            font-size: 0.9rem;
        }
        .filter-group {
            display: none;
        }
        .filter-group.active {
            display: block;
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
                            <li class="breadcrumb-item active">Custom Reports</li>
                        </ol>
                    </nav>
                    <h1><i class="bi bi-file-earmark-text"></i> Custom Reports</h1>
                    <p class="header-subtitle">Build flexible reports with custom filters and visualizations</p>
                </div>
            </div>
        </header>

        <main class="content">
            <div class="container-fluid">
                <!-- Report Builder -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card report-builder">
                            <div class="card-body">
                                <h5 class="mb-4"><i class="bi bi-tools me-2"></i>Report Builder</h5>
                                
                                <form method="POST" id="reportForm">
                                    <input type="hidden" name="generate_report" value="1">
                                    
                                    <div class="row">
                                        <!-- Report Type -->
                                        <div class="col-md-3 mb-3">
                                            <label class="form-label">Report Type</label>
                                            <select class="form-select" name="report_type" id="reportType" required onchange="toggleFilters()">
                                                <option value="">Select Report Type</option>
                                                <option value="sales_summary" <?php echo ($_POST['report_type'] ?? '') === 'sales_summary' ? 'selected' : ''; ?>>Sales Summary</option>
                                                <option value="product_performance" <?php echo ($_POST['report_type'] ?? '') === 'product_performance' ? 'selected' : ''; ?>>Product Performance</option>
                                                <option value="customer_analysis" <?php echo ($_POST['report_type'] ?? '') === 'customer_analysis' ? 'selected' : ''; ?>>Customer Analysis</option>
                                                <option value="expense_breakdown" <?php echo ($_POST['report_type'] ?? '') === 'expense_breakdown' ? 'selected' : ''; ?>>Expense Breakdown</option>
                                                <option value="profit_analysis" <?php echo ($_POST['report_type'] ?? '') === 'profit_analysis' ? 'selected' : ''; ?>>Profit Analysis</option>
                                                <option value="inventory_valuation" <?php echo ($_POST['report_type'] ?? '') === 'inventory_valuation' ? 'selected' : ''; ?>>Inventory Valuation</option>
                                            </select>
                                        </div>
                                        
                                        <!-- Date Range -->
                                        <div class="col-md-2 mb-3 date-filter">
                                            <label class="form-label">Start Date</label>
                                            <input type="date" class="form-control" name="start_date" value="<?php echo $_POST['start_date'] ?? date('Y-m-01'); ?>">
                                        </div>
                                        
                                        <div class="col-md-2 mb-3 date-filter">
                                            <label class="form-label">End Date</label>
                                            <input type="date" class="form-control" name="end_date" value="<?php echo $_POST['end_date'] ?? date('Y-m-t'); ?>">
                                        </div>
                                        
                                        <!-- Group By -->
                                        <div class="col-md-2 mb-3 group-filter">
                                            <label class="form-label">Group By</label>
                                            <select class="form-select" name="group_by">
                                                <option value="day" <?php echo ($_POST['group_by'] ?? 'day') === 'day' ? 'selected' : ''; ?>>Daily</option>
                                                <option value="week" <?php echo ($_POST['group_by'] ?? '') === 'week' ? 'selected' : ''; ?>>Weekly</option>
                                                <option value="month" <?php echo ($_POST['group_by'] ?? '') === 'month' ? 'selected' : ''; ?>>Monthly</option>
                                                <option value="category" <?php echo ($_POST['group_by'] ?? '') === 'category' ? 'selected' : ''; ?>>Category</option>
                                            </select>
                                        </div>
                                        
                                        <!-- Chart Type -->
                                        <div class="col-md-2 mb-3">
                                            <label class="form-label">Chart Type</label>
                                            <select class="form-select" name="chart_type">
                                                <option value="line" <?php echo ($_POST['chart_type'] ?? 'line') === 'line' ? 'selected' : ''; ?>>Line Chart</option>
                                                <option value="bar" <?php echo ($_POST['chart_type'] ?? '') === 'bar' ? 'selected' : ''; ?>>Bar Chart</option>
                                                <option value="pie" <?php echo ($_POST['chart_type'] ?? '') === 'pie' ? 'selected' : ''; ?>>Pie Chart</option>
                                                <option value="table" <?php echo ($_POST['chart_type'] ?? '') === 'table' ? 'selected' : ''; ?>>Table Only</option>
                                            </select>
                                        </div>
                                        
                                        <!-- Generate Button -->
                                        <div class="col-md-1 mb-3 d-flex align-items-end">
                                            <button type="submit" class="btn btn-light fw-bold">
                                                <i class="bi bi-play-fill"></i> Generate
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <!-- Advanced Filters -->
                                    <div class="row mt-3">
                                        <div class="col-12">
                                            <h6 class="opacity-75 mb-3">Advanced Filters</h6>
                                        </div>
                                        
                                        <!-- Category Filter -->
                                        <div class="col-md-3 mb-3 filter-group" id="categoryFilter">
                                            <label class="form-label opacity-75">Category</label>
                                            <select class="form-select form-control-sm" name="filters[category]">
                                                <option value="">All Categories</option>
                                                <?php foreach ($categories as $category): ?>
                                                <option value="<?php echo $category['id']; ?>" <?php echo ($_POST['filters']['category'] ?? '') == $category['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($category['name']); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <!-- Payment Method Filter -->
                                        <div class="col-md-3 mb-3 filter-group" id="paymentFilter">
                                            <label class="form-label opacity-75">Payment Method</label>
                                            <select class="form-select form-control-sm" name="filters[payment_method]">
                                                <option value="">All Methods</option>
                                                <option value="cash" <?php echo ($_POST['filters']['payment_method'] ?? '') === 'cash' ? 'selected' : ''; ?>>Cash</option>
                                                <option value="mpesa" <?php echo ($_POST['filters']['payment_method'] ?? '') === 'mpesa' ? 'selected' : ''; ?>>M-Pesa</option>
                                                <option value="card" <?php echo ($_POST['filters']['payment_method'] ?? '') === 'card' ? 'selected' : ''; ?>>Card</option>
                                                <option value="bank_transfer" <?php echo ($_POST['filters']['payment_method'] ?? '') === 'bank_transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                                            </select>
                                        </div>
                                        
                                        <!-- Low Stock Filter -->
                                        <div class="col-md-3 mb-3 filter-group" id="stockFilter">
                                            <label class="form-label opacity-75">Stock Level</label>
                                            <select class="form-select form-control-sm" name="filters[low_stock]">
                                                <option value="">All Items</option>
                                                <option value="yes" <?php echo ($_POST['filters']['low_stock'] ?? '') === 'yes' ? 'selected' : ''; ?>>Low Stock Only</option>
                                            </select>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Error Message -->
                <?php if (!empty($error_message)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <?php echo htmlspecialchars($error_message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <!-- Generated Report -->
                <?php if ($report_generated && !empty($report_data)): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">
                                    <i class="bi bi-graph-up me-2"></i>
                                    <?php echo ucwords(str_replace('_', ' ', $_POST['report_type'])); ?> Report
                                </h5>
                                <div>
                                    <button class="btn btn-sm btn-outline-primary me-2" onclick="window.print()">
                                        <i class="bi bi-printer"></i> Print
                                    </button>
                                    <button class="btn btn-sm btn-outline-success" onclick="exportReportToCSV()">
                                        <i class="bi bi-download"></i> Export CSV
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <!-- Chart Container -->
                                <?php if (($_POST['chart_type'] ?? 'line') !== 'table'): ?>
                                <div class="chart-container mb-4">
                                    <canvas id="reportChart"></canvas>
                                </div>
                                <?php endif; ?>
                                
                                <!-- Data Table -->
                                <div class="table-responsive">
                                    <table class="table table-hover report-table">
                                        <thead class="table-light">
                                            <tr>
                                                <?php if (!empty($report_data)): ?>
                                                    <?php foreach (array_keys($report_data[0]) as $column): ?>
                                                    <th class="text-capitalize"><?php echo str_replace('_', ' ', $column); ?></th>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($report_data as $row): ?>
                                            <tr>
                                                <?php foreach ($row as $key => $value): ?>
                                                <td>
                                                    <?php 
                                                    if (is_numeric($value) && strpos($key, 'amount') !== false || strpos($key, 'revenue') !== false || strpos($key, 'cost') !== false || strpos($key, 'value') !== false) {
                                                        echo htmlspecialchars($settings['currency_symbol'] ?? 'KES') . ' ' . number_format($value, 2);
                                                    } elseif (is_numeric($value) && $value != intval($value)) {
                                                        echo number_format($value, 2);
                                                    } elseif (strpos($key, 'date') !== false && !empty($value)) {
                                                        echo date('M d, Y', strtotime($value));
                                                    } else {
                                                        echo htmlspecialchars($value);
                                                    }
                                                    ?>
                                                </td>
                                                <?php endforeach; ?>
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

                <!-- Report Templates -->
                <div class="row mt-4">
                    <div class="col-12 mb-3">
                        <h5>Quick Report Templates</h5>
                        <p class="text-muted">Click on a template to quickly generate common reports</p>
                    </div>
                </div>

                <div class="row">
                    <!-- Today's Sales -->
                    <div class="col-lg-3 col-md-6 mb-4">
                        <div class="card report-card" onclick="generateTemplate('sales_summary', 'today')">
                            <div class="card-body text-center">
                                <i class="bi bi-calendar-day text-primary fs-1 mb-3"></i>
                                <h6 class="card-title">Today's Sales</h6>
                                <p class="card-text small text-muted">Quick overview of today's sales performance</p>
                            </div>
                        </div>
                    </div>

                    <!-- This Month Profit -->
                    <div class="col-lg-3 col-md-6 mb-4">
                        <div class="card report-card" onclick="generateTemplate('profit_analysis', 'month')">
                            <div class="card-body text-center">
                                <i class="bi bi-graph-up text-success fs-1 mb-3"></i>
                                <h6 class="card-title">Monthly Profit</h6>
                                <p class="card-text small text-muted">Profit analysis for the current month</p>
                            </div>
                        </div>
                    </div>

                    <!-- Top Products -->
                    <div class="col-lg-3 col-md-6 mb-4">
                        <div class="card report-card" onclick="generateTemplate('product_performance', 'month')">
                            <div class="card-body text-center">
                                <i class="bi bi-star text-warning fs-1 mb-3"></i>
                                <h6 class="card-title">Top Products</h6>
                                <p class="card-text small text-muted">Best performing products this month</p>
                            </div>
                        </div>
                    </div>

                    <!-- Low Stock Items -->
                    <div class="col-lg-3 col-md-6 mb-4">
                        <div class="card report-card" onclick="generateTemplate('inventory_valuation', 'low_stock')">
                            <div class="card-body text-center">
                                <i class="bi bi-exclamation-triangle text-danger fs-1 mb-3"></i>
                                <h6 class="card-title">Low Stock Alert</h6>
                                <p class="card-text small text-muted">Items that need restocking</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle filters based on report type
        function toggleFilters() {
            const reportType = document.getElementById('reportType').value;
            const filterGroups = document.querySelectorAll('.filter-group');
            
            // Hide all filter groups first
            filterGroups.forEach(group => group.classList.remove('active'));
            
            // Show relevant filters based on report type
            switch (reportType) {
                case 'sales_summary':
                    document.getElementById('paymentFilter').classList.add('active');
                    break;
                case 'product_performance':
                case 'inventory_valuation':
                    document.getElementById('categoryFilter').classList.add('active');
                    if (reportType === 'inventory_valuation') {
                        document.getElementById('stockFilter').classList.add('active');
                    }
                    break;
            }
        }

        // Generate template reports
        function generateTemplate(type, period) {
            const form = document.getElementById('reportForm');
            const reportType = document.querySelector('[name="report_type"]');
            const startDate = document.querySelector('[name="start_date"]');
            const endDate = document.querySelector('[name="end_date"]');
            const groupBy = document.querySelector('[name="group_by"]');
            
            reportType.value = type;
            
            const today = new Date();
            switch (period) {
                case 'today':
                    const todayStr = today.toISOString().split('T')[0];
                    startDate.value = todayStr;
                    endDate.value = todayStr;
                    groupBy.value = 'day';
                    break;
                case 'month':
                    startDate.value = new Date(today.getFullYear(), today.getMonth(), 1).toISOString().split('T')[0];
                    endDate.value = new Date(today.getFullYear(), today.getMonth() + 1, 0).toISOString().split('T')[0];
                    groupBy.value = 'day';
                    break;
                case 'low_stock':
                    document.querySelector('[name="filters[low_stock]"]').value = 'yes';
                    break;
            }
            
            toggleFilters();
            form.submit();
        }

        // Generate chart if report data exists
        <?php if ($report_generated && !empty($report_data) && ($_POST['chart_type'] ?? 'line') !== 'table'): ?>
        const chartData = <?php echo json_encode($report_data); ?>;
        const chartType = '<?php echo $_POST['chart_type'] ?? 'line'; ?>';
        const reportType = '<?php echo $_POST['report_type'] ?? ''; ?>';
        
        generateChart(chartData, chartType, reportType);
        <?php endif; ?>

        function generateChart(data, type, reportType) {
            const ctx = document.getElementById('reportChart').getContext('2d');
            
            let labels = [];
            let datasets = [];
            let chartConfig = {
                type: type === 'pie' ? 'doughnut' : type,
                options: {
                    responsive: true,
                    maintainAspectRatio: false
                }
            };
            
            // Configure chart based on report type
            switch (reportType) {
                case 'sales_summary':
                    labels = data.map(item => item.period || 'N/A');
                    datasets = [{
                        label: 'Revenue',
                        data: data.map(item => parseFloat(item.total_revenue || 0)),
                        borderColor: 'rgb(75, 192, 192)',
                        backgroundColor: 'rgba(75, 192, 192, 0.2)',
                        tension: 0.1
                    }];
                    break;
                    
                case 'product_performance':
                    labels = data.slice(0, 10).map(item => item.product_name || 'N/A');
                    datasets = [{
                        label: 'Revenue',
                        data: data.slice(0, 10).map(item => parseFloat(item.total_revenue || 0)),
                        backgroundColor: [
                            '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF',
                            '#FF9F40', '#FF6384', '#C9CBCF', '#4BC0C0', '#FF6384'
                        ]
                    }];
                    break;
                    
                case 'profit_analysis':
                    labels = data.map(item => item.period || 'N/A');
                    datasets = [
                        {
                            label: 'Revenue',
                            data: data.map(item => parseFloat(item.revenue || 0)),
                            borderColor: 'rgb(75, 192, 192)',
                            backgroundColor: 'rgba(75, 192, 192, 0.2)',
                        },
                        {
                            label: 'Cost of Goods',
                            data: data.map(item => parseFloat(item.cogs || 0)),
                            borderColor: 'rgb(255, 99, 132)',
                            backgroundColor: 'rgba(255, 99, 132, 0.2)',
                        }
                    ];
                    break;
                    
                default:
                    // Generic chart
                    if (data.length > 0) {
                        const firstRow = data[0];
                        const numericColumns = Object.keys(firstRow).filter(key => 
                            !isNaN(parseFloat(firstRow[key])) && key !== 'period'
                        );
                        
                        labels = data.map(item => Object.values(item)[0]);
                        if (numericColumns.length > 0) {
                            datasets = [{
                                label: numericColumns[0].replace('_', ' '),
                                data: data.map(item => parseFloat(item[numericColumns[0]] || 0)),
                                backgroundColor: '#36A2EB'
                            }];
                        }
                    }
            }
            
            chartConfig.data = { labels, datasets };
            
            new Chart(ctx, chartConfig);
        }

        // Export to CSV function
        function exportReportToCSV() {
            <?php if ($report_generated && !empty($report_data)): ?>
            let csv = '<?php echo ucwords(str_replace('_', ' ', $_POST['report_type'])); ?> Report\n';
            csv += 'Generated: <?php echo date('Y-m-d H:i:s'); ?>\n';
            csv += 'Period: <?php echo $_POST['start_date']; ?> to <?php echo $_POST['end_date']; ?>\n\n';
            
            // Headers
            <?php if (!empty($report_data)): ?>
            csv += '<?php echo implode(',', array_map(function($key) { return ucwords(str_replace('_', ' ', $key)); }, array_keys($report_data[0]))); ?>\n';
            
            // Data rows
            <?php foreach ($report_data as $row): ?>
            csv += '<?php echo implode(',', array_map(function($value) { return is_numeric($value) ? $value : '"' . addslashes($value) . '"'; }, $row)); ?>\n';
            <?php endforeach; ?>
            <?php endif; ?>
            
            // Download
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.setAttribute('hidden', '');
            a.setAttribute('href', url);
            a.setAttribute('download', 'custom-report-<?php echo date('Y-m-d'); ?>.csv');
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            <?php endif; ?>
        }

        // Initialize filters on page load
        document.addEventListener('DOMContentLoaded', function() {
            toggleFilters();
        });
    </script>
</body>
</html>
