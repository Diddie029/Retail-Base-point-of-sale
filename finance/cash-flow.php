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

// Initialize cash flow statement
$cash_flow = [
    'operating' => [
        'inflows' => [],
        'outflows' => [],
        'total_inflow' => 0,
        'total_outflow' => 0,
        'net_operating' => 0
    ],
    'investing' => [
        'inflows' => [],
        'outflows' => [],
        'total_inflow' => 0,
        'total_outflow' => 0,
        'net_investing' => 0
    ],
    'financing' => [
        'inflows' => [],
        'outflows' => [],
        'total_inflow' => 0,
        'total_outflow' => 0,
        'net_financing' => 0
    ],
    'net_change' => 0,
    'beginning_cash' => 0,
    'ending_cash' => 0
];

// OPERATING ACTIVITIES
// Cash received from sales
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(final_amount), 0) as total
    FROM sales 
    WHERE sale_date BETWEEN ? AND ?
");
$stmt->execute([$start_date, $end_date]);
$sales_revenue = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Cash received from accounts receivable (credit sales paid) - simplified
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(final_amount), 0) as total
    FROM sales 
    WHERE sale_date BETWEEN ? AND ? 
    AND payment_method = 'credit'
");
$stmt->execute([$start_date, $end_date]);
$receivables_collected = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$cash_flow['operating']['inflows'] = [
    'Sales Revenue (Cash)' => $sales_revenue,
    'Collections from Customers' => $receivables_collected
];
$cash_flow['operating']['total_inflow'] = array_sum($cash_flow['operating']['inflows']);

// Operating expenses
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(total_amount), 0) as total
    FROM expenses e
    JOIN expense_categories ec ON e.category_id = ec.id
    WHERE e.expense_date BETWEEN ? AND ? 
    AND ec.name IN ('operations', 'utilities', 'rent', 'salaries', 'supplies')
    AND e.approval_status = 'approved'
");
$stmt->execute([$start_date, $end_date]);
$operating_expenses = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Cost of goods sold (inventory purchases)
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(si.quantity * p.cost_price), 0) as total
    FROM sale_items si
    JOIN sales s ON si.sale_id = s.id
    JOIN products p ON si.product_id = p.id
    WHERE s.sale_date BETWEEN ? AND ?
");
$stmt->execute([$start_date, $end_date]);
$cogs = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Tax payments
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(total_amount), 0) as total
    FROM expenses e
    JOIN expense_categories ec ON e.category_id = ec.id
    WHERE e.expense_date BETWEEN ? AND ? 
    AND ec.name = 'tax'
    AND e.approval_status = 'approved'
");
$stmt->execute([$start_date, $end_date]);
$tax_payments = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$cash_flow['operating']['outflows'] = [
    'Cost of Goods Sold' => $cogs,
    'Operating Expenses' => $operating_expenses,
    'Tax Payments' => $tax_payments
];
$cash_flow['operating']['total_outflow'] = array_sum($cash_flow['operating']['outflows']);
$cash_flow['operating']['net_operating'] = $cash_flow['operating']['total_inflow'] - $cash_flow['operating']['total_outflow'];

// INVESTING ACTIVITIES
// Equipment purchases
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(total_amount), 0) as total
    FROM expenses e
    JOIN expense_categories ec ON e.category_id = ec.id
    WHERE e.expense_date BETWEEN ? AND ? 
    AND ec.name IN ('equipment', 'asset_purchase')
    AND e.approval_status = 'approved'
");
$stmt->execute([$start_date, $end_date]);
$equipment_purchases = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Asset sales (if any)
$asset_sales = 0; // Would need asset management system

$cash_flow['investing']['inflows'] = [
    'Sale of Assets' => $asset_sales
];
$cash_flow['investing']['outflows'] = [
    'Equipment Purchases' => $equipment_purchases,
    'Technology Investments' => 0
];

$cash_flow['investing']['total_inflow'] = array_sum($cash_flow['investing']['inflows']);
$cash_flow['investing']['total_outflow'] = array_sum($cash_flow['investing']['outflows']);
$cash_flow['investing']['net_investing'] = $cash_flow['investing']['total_inflow'] - $cash_flow['investing']['total_outflow'];

// FINANCING ACTIVITIES
// Loan proceeds
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(total_amount), 0) as total
    FROM expenses e
    JOIN expense_categories ec ON e.category_id = ec.id
    WHERE e.expense_date BETWEEN ? AND ? 
    AND ec.name = 'loan_proceeds'
    AND e.approval_status = 'approved'
");
$stmt->execute([$start_date, $end_date]);
$loan_proceeds = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Loan repayments
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(total_amount), 0) as total
    FROM expenses e
    JOIN expense_categories ec ON e.category_id = ec.id
    WHERE e.expense_date BETWEEN ? AND ? 
    AND ec.name IN ('loan_repayment', 'interest')
    AND e.approval_status = 'approved'
");
$stmt->execute([$start_date, $end_date]);
$loan_repayments = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Owner contributions/distributions
$owner_contributions = 0; // Would need owner equity tracking
$owner_distributions = 0;

$cash_flow['financing']['inflows'] = [
    'Loan Proceeds' => $loan_proceeds,
    'Owner Contributions' => $owner_contributions
];
$cash_flow['financing']['outflows'] = [
    'Loan Repayments' => $loan_repayments,
    'Owner Distributions' => $owner_distributions
];

$cash_flow['financing']['total_inflow'] = array_sum($cash_flow['financing']['inflows']);
$cash_flow['financing']['total_outflow'] = array_sum($cash_flow['financing']['outflows']);
$cash_flow['financing']['net_financing'] = $cash_flow['financing']['total_inflow'] - $cash_flow['financing']['total_outflow'];

// Calculate net change in cash
$cash_flow['net_change'] = $cash_flow['operating']['net_operating'] + 
                          $cash_flow['investing']['net_investing'] + 
                          $cash_flow['financing']['net_financing'];

// Get beginning cash balance
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(final_amount), 0) as total
    FROM sales 
    WHERE sale_date < ? 
    AND payment_method IN ('cash', 'mpesa', 'bank_transfer')
");
$stmt->execute([$start_date]);
$cash_flow['beginning_cash'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$cash_flow['ending_cash'] = $cash_flow['beginning_cash'] + $cash_flow['net_change'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cash Flow Statement - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --danger-color: #ef4444;
            --info-color: #3b82f6;
            --gradient-primary: linear-gradient(135deg, var(--primary-color) 0%, #4f46e5 100%);
            --gradient-success: linear-gradient(135deg, #10b981 0%, #059669 100%);
            --gradient-danger: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            --gradient-info: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
        }
        
        .cashflow-table {
            font-size: 0.95rem;
            border-collapse: separate;
            border-spacing: 0;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
        }
        
        .section-header {
            background: var(--gradient-primary);
            color: white;
            font-weight: bold;
            text-shadow: 0 1px 2px rgba(0,0,0,0.1);
            position: relative;
            overflow: hidden;
        }
        
        .section-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, transparent 0%, rgba(255,255,255,0.3) 50%, transparent 100%);
        }
        
        .subsection-header {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            font-weight: 600;
            color: #374151;
            border-left: 4px solid var(--primary-color);
        }
        
        .total-row {
            border-top: 3px solid var(--primary-color);
            font-weight: bold;
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: #92400e;
        }
        
        .net-row {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            font-weight: bold;
            color: #1e40af;
        }
        
        .final-total {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            font-weight: bold;
            border-top: 3px solid var(--success-color);
            color: #065f46;
        }
        
        .negative {
            color: var(--danger-color);
            font-weight: 600;
        }
        
        .positive {
            color: var(--success-color);
            font-weight: 600;
        }
        
        .print-btn {
            background: var(--gradient-primary);
            border: none;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
        }
        
        .print-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0,0,0,0.15);
        }
        
        .cashflow-card {
            border-radius: 16px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            border: 1px solid #e5e7eb;
            transition: all 0.3s ease;
            overflow: hidden;
        }
        
        .cashflow-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(0,0,0,0.1);
        }
        
        .cashflow-card .card-header {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            border-bottom: 2px solid var(--primary-color);
            border-radius: 16px 16px 0 0 !important;
            position: relative;
        }
        
        .cashflow-card .card-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--gradient-primary);
        }
        
        .summary-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 8px 16px rgba(0,0,0,0.1);
        }
        
        .summary-item {
            text-align: center;
            padding: 1rem;
        }
        
        .summary-value {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .summary-label {
            font-size: 1rem;
            opacity: 0.9;
        }
        
        .cashflow-icon {
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
        
        .table-hover tbody tr:hover {
            background-color: rgba(99, 102, 241, 0.05);
            transform: scale(1.01);
            transition: all 0.2s ease;
        }
        
        .cashflow-table tbody tr {
            transition: all 0.2s ease;
        }
        
        .cashflow-table tbody tr:nth-child(even) {
            background-color: rgba(0,0,0,0.02);
        }
        
        .amount-cell {
            font-family: 'Courier New', monospace;
            font-weight: 600;
        }
        
        .fade-in {
            animation: fadeIn 0.5s ease-in;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
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
        
        @media print {
            .no-print { display: none !important; }
            .cashflow-table { font-size: 0.8rem; }
            .summary-card { break-inside: avoid; }
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
                            <li class="breadcrumb-item"><a href="reports.php" class="text-decoration-none"><i class="bi bi-graph-up me-1"></i>Financial Reports</a></li>
                            <li class="breadcrumb-item active"><i class="bi bi-currency-exchange me-1"></i>Cash Flow Statement</li>
                        </ol>
                    </nav>
                    <div class="d-flex align-items-center mb-3">
                        <div class="cashflow-icon me-3">
                            <i class="bi bi-currency-exchange"></i>
                        </div>
                        <div>
                            <h1 class="mb-1">Cash Flow Statement</h1>
                            <p class="header-subtitle mb-0">
                                <i class="bi bi-calendar3 me-2"></i>
                                For the period <strong><?php echo date('M d, Y', strtotime($start_date)); ?> to <?php echo date('M d, Y', strtotime($end_date)); ?></strong>
                            </p>
                        </div>
                    </div>
                </div>
                <div class="header-actions">
                    <button class="btn print-btn text-white me-2" onclick="window.print()">
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
                        <div class="card cashflow-card">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="bi bi-calendar-range me-2"></i>Report Period</h6>
                            </div>
                            <div class="card-body">
                                <form method="GET" class="row g-3 align-items-end">
                                    <div class="col-md-4">
                                        <label for="period" class="form-label">Period</label>
                                        <select class="form-select" id="period" name="period" onchange="this.form.submit()">
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
                        <div class="summary-card">
                            <div class="row">
                                <div class="col-6">
                                    <div class="summary-item">
                                        <div class="summary-value"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($cash_flow['beginning_cash'], 2); ?></div>
                                        <div class="summary-label">Beginning Cash</div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="summary-item">
                                        <div class="summary-value <?php echo $cash_flow['net_change'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                            <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($cash_flow['net_change'], 2); ?>
                                        </div>
                                        <div class="summary-label">Net Change</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Cash Flow Statement -->
                <div class="row fade-in">
                    <div class="col-12">
                        <div class="card cashflow-card">
                            <div class="card-header">
                                <h5 class="mb-0">
                                    <i class="bi bi-graph-up me-2"></i>
                                    <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?> - Cash Flow Statement
                                </h5>
                                <p class="mb-0 text-muted">
                                    For the period <?php echo date('M d, Y', strtotime($start_date)); ?> to <?php echo date('M d, Y', strtotime($end_date)); ?>
                                </p>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table cashflow-table mb-0 table-hover">
                                        <thead>
                                            <tr>
                                                <th width="60%">CASH FLOW ITEM</th>
                                                <th width="40%" class="text-end">AMOUNT (<?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?>)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <!-- OPERATING ACTIVITIES -->
                                            <tr class="section-header">
                                                <td colspan="2">CASH FLOWS FROM OPERATING ACTIVITIES</td>
                                            </tr>
                                            
                                            <!-- Operating Inflows -->
                                            <tr class="subsection-header">
                                                <td>&nbsp;&nbsp;Cash Inflows</td>
                                                <td></td>
                                            </tr>
                                            <?php foreach ($cash_flow['operating']['inflows'] as $item => $amount): ?>
                                            <?php if ($amount > 0): ?>
                                            <tr>
                                                <td>&nbsp;&nbsp;&nbsp;&nbsp;<?php echo htmlspecialchars($item); ?></td>
                                                <td class="text-end positive amount-cell"><?php echo number_format($amount, 2); ?></td>
                                            </tr>
                                            <?php endif; ?>
                                            <?php endforeach; ?>
                                            
                                            <!-- Operating Outflows -->
                                            <tr class="subsection-header">
                                                <td>&nbsp;&nbsp;Cash Outflows</td>
                                                <td></td>
                                            </tr>
                                            <?php foreach ($cash_flow['operating']['outflows'] as $item => $amount): ?>
                                            <?php if ($amount > 0): ?>
                                            <tr>
                                                <td>&nbsp;&nbsp;&nbsp;&nbsp;<?php echo htmlspecialchars($item); ?></td>
                                                <td class="text-end negative amount-cell">(<?php echo number_format($amount, 2); ?>)</td>
                                            </tr>
                                            <?php endif; ?>
                                            <?php endforeach; ?>
                                            
                                            <tr class="net-row">
                                                <td>&nbsp;&nbsp;Net Cash from Operating Activities</td>
                                                <td class="text-end <?php echo $cash_flow['operating']['net_operating'] >= 0 ? 'positive' : 'negative'; ?>">
                                                    <?php echo $cash_flow['operating']['net_operating'] >= 0 ? '' : '('; ?>
                                                    <?php echo number_format(abs($cash_flow['operating']['net_operating']), 2); ?>
                                                    <?php echo $cash_flow['operating']['net_operating'] >= 0 ? '' : ')'; ?>
                                                </td>
                                            </tr>
                                            
                                            <!-- INVESTING ACTIVITIES -->
                                            <tr class="section-header">
                                                <td colspan="2">CASH FLOWS FROM INVESTING ACTIVITIES</td>
                                            </tr>
                                            
                                            <!-- Investing Inflows -->
                                            <?php if ($cash_flow['investing']['total_inflow'] > 0): ?>
                                            <tr class="subsection-header">
                                                <td>&nbsp;&nbsp;Cash Inflows</td>
                                                <td></td>
                                            </tr>
                                            <?php foreach ($cash_flow['investing']['inflows'] as $item => $amount): ?>
                                            <?php if ($amount > 0): ?>
                                            <tr>
                                                <td>&nbsp;&nbsp;&nbsp;&nbsp;<?php echo htmlspecialchars($item); ?></td>
                                                <td class="text-end positive"><?php echo number_format($amount, 2); ?></td>
                                            </tr>
                                            <?php endif; ?>
                                            <?php endforeach; ?>
                                            <?php endif; ?>
                                            
                                            <!-- Investing Outflows -->
                                            <?php if ($cash_flow['investing']['total_outflow'] > 0): ?>
                                            <tr class="subsection-header">
                                                <td>&nbsp;&nbsp;Cash Outflows</td>
                                                <td></td>
                                            </tr>
                                            <?php foreach ($cash_flow['investing']['outflows'] as $item => $amount): ?>
                                            <?php if ($amount > 0): ?>
                                            <tr>
                                                <td>&nbsp;&nbsp;&nbsp;&nbsp;<?php echo htmlspecialchars($item); ?></td>
                                                <td class="text-end negative">(<?php echo number_format($amount, 2); ?>)</td>
                                            </tr>
                                            <?php endif; ?>
                                            <?php endforeach; ?>
                                            <?php endif; ?>
                                            
                                            <tr class="net-row">
                                                <td>&nbsp;&nbsp;Net Cash from Investing Activities</td>
                                                <td class="text-end <?php echo $cash_flow['investing']['net_investing'] >= 0 ? 'positive' : 'negative'; ?>">
                                                    <?php echo $cash_flow['investing']['net_investing'] >= 0 ? '' : '('; ?>
                                                    <?php echo number_format(abs($cash_flow['investing']['net_investing']), 2); ?>
                                                    <?php echo $cash_flow['investing']['net_investing'] >= 0 ? '' : ')'; ?>
                                                </td>
                                            </tr>
                                            
                                            <!-- FINANCING ACTIVITIES -->
                                            <tr class="section-header">
                                                <td colspan="2">CASH FLOWS FROM FINANCING ACTIVITIES</td>
                                            </tr>
                                            
                                            <!-- Financing Inflows -->
                                            <?php if ($cash_flow['financing']['total_inflow'] > 0): ?>
                                            <tr class="subsection-header">
                                                <td>&nbsp;&nbsp;Cash Inflows</td>
                                                <td></td>
                                            </tr>
                                            <?php foreach ($cash_flow['financing']['inflows'] as $item => $amount): ?>
                                            <?php if ($amount > 0): ?>
                                            <tr>
                                                <td>&nbsp;&nbsp;&nbsp;&nbsp;<?php echo htmlspecialchars($item); ?></td>
                                                <td class="text-end positive"><?php echo number_format($amount, 2); ?></td>
                                            </tr>
                                            <?php endif; ?>
                                            <?php endforeach; ?>
                                            <?php endif; ?>
                                            
                                            <!-- Financing Outflows -->
                                            <?php if ($cash_flow['financing']['total_outflow'] > 0): ?>
                                            <tr class="subsection-header">
                                                <td>&nbsp;&nbsp;Cash Outflows</td>
                                                <td></td>
                                            </tr>
                                            <?php foreach ($cash_flow['financing']['outflows'] as $item => $amount): ?>
                                            <?php if ($amount > 0): ?>
                                            <tr>
                                                <td>&nbsp;&nbsp;&nbsp;&nbsp;<?php echo htmlspecialchars($item); ?></td>
                                                <td class="text-end negative">(<?php echo number_format($amount, 2); ?>)</td>
                                            </tr>
                                            <?php endif; ?>
                                            <?php endforeach; ?>
                                            <?php endif; ?>
                                            
                                            <tr class="net-row">
                                                <td>&nbsp;&nbsp;Net Cash from Financing Activities</td>
                                                <td class="text-end <?php echo $cash_flow['financing']['net_financing'] >= 0 ? 'positive' : 'negative'; ?>">
                                                    <?php echo $cash_flow['financing']['net_financing'] >= 0 ? '' : '('; ?>
                                                    <?php echo number_format(abs($cash_flow['financing']['net_financing']), 2); ?>
                                                    <?php echo $cash_flow['financing']['net_financing'] >= 0 ? '' : ')'; ?>
                                                </td>
                                            </tr>
                                            
                                            <!-- NET CHANGE IN CASH -->
                                            <tr class="total-row">
                                                <td>NET INCREASE (DECREASE) IN CASH</td>
                                                <td class="text-end <?php echo $cash_flow['net_change'] >= 0 ? 'positive' : 'negative'; ?>">
                                                    <?php echo $cash_flow['net_change'] >= 0 ? '' : '('; ?>
                                                    <?php echo number_format(abs($cash_flow['net_change']), 2); ?>
                                                    <?php echo $cash_flow['net_change'] >= 0 ? '' : ')'; ?>
                                                </td>
                                            </tr>
                                            
                                            <!-- CASH RECONCILIATION -->
                                            <tr>
                                                <td>Cash at Beginning of Period</td>
                                                <td class="text-end"><?php echo number_format($cash_flow['beginning_cash'], 2); ?></td>
                                            </tr>
                                            
                                            <tr class="final-total">
                                                <td>CASH AT END OF PERIOD</td>
                                                <td class="text-end"><?php echo number_format($cash_flow['ending_cash'], 2); ?></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Cash Flow Analysis -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="bi bi-bar-chart me-2"></i>Cash Flow Analysis</h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="text-center p-3 bg-light rounded">
                                            <h5 class="<?php echo $cash_flow['operating']['net_operating'] >= 0 ? 'text-success' : 'text-danger'; ?> mb-1">
                                                <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($cash_flow['operating']['net_operating'], 0); ?>
                                            </h5>
                                            <small class="text-muted">Operating Cash Flow</small>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="text-center p-3 bg-light rounded">
                                            <h5 class="<?php echo $cash_flow['investing']['net_investing'] >= 0 ? 'text-success' : 'text-danger'; ?> mb-1">
                                                <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($cash_flow['investing']['net_investing'], 0); ?>
                                            </h5>
                                            <small class="text-muted">Investing Cash Flow</small>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="text-center p-3 bg-light rounded">
                                            <h5 class="<?php echo $cash_flow['financing']['net_financing'] >= 0 ? 'text-success' : 'text-danger'; ?> mb-1">
                                                <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($cash_flow['financing']['net_financing'], 0); ?>
                                            </h5>
                                            <small class="text-muted">Financing Cash Flow</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="row mt-3">
                                    <div class="col-md-6">
                                        <div class="text-center p-3 bg-primary text-white rounded">
                                            <h5 class="mb-1">
                                                <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($cash_flow['net_change'], 0); ?>
                                            </h5>
                                            <small>Net Change in Cash</small>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="text-center p-3 bg-success text-white rounded">
                                            <h5 class="mb-1">
                                                <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($cash_flow['ending_cash'], 0); ?>
                                            </h5>
                                            <small>Ending Cash Balance</small>
                                        </div>
                                    </div>
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
            let csv = 'Cash Flow Item,Amount\n';
            
            // Operating Activities
            csv += 'CASH FLOWS FROM OPERATING ACTIVITIES,\n';
            csv += 'Cash Inflows,\n';
            <?php foreach ($cash_flow['operating']['inflows'] as $item => $amount): ?>
            <?php if ($amount > 0): ?>
            csv += '<?php echo addslashes($item); ?>,<?php echo $amount; ?>\n';
            <?php endif; ?>
            <?php endforeach; ?>
            
            csv += 'Cash Outflows,\n';
            <?php foreach ($cash_flow['operating']['outflows'] as $item => $amount): ?>
            <?php if ($amount > 0): ?>
            csv += '<?php echo addslashes($item); ?>,-<?php echo $amount; ?>\n';
            <?php endif; ?>
            <?php endforeach; ?>
            
            csv += 'Net Cash from Operating Activities,<?php echo $cash_flow['operating']['net_operating']; ?>\n';
            
            // Investing Activities
            csv += 'CASH FLOWS FROM INVESTING ACTIVITIES,\n';
            <?php foreach ($cash_flow['investing']['inflows'] as $item => $amount): ?>
            <?php if ($amount > 0): ?>
            csv += '<?php echo addslashes($item); ?>,<?php echo $amount; ?>\n';
            <?php endif; ?>
            <?php endforeach; ?>
            
            <?php foreach ($cash_flow['investing']['outflows'] as $item => $amount): ?>
            <?php if ($amount > 0): ?>
            csv += '<?php echo addslashes($item); ?>,-<?php echo $amount; ?>\n';
            <?php endif; ?>
            <?php endforeach; ?>
            
            csv += 'Net Cash from Investing Activities,<?php echo $cash_flow['investing']['net_investing']; ?>\n';
            
            // Financing Activities
            csv += 'CASH FLOWS FROM FINANCING ACTIVITIES,\n';
            <?php foreach ($cash_flow['financing']['inflows'] as $item => $amount): ?>
            <?php if ($amount > 0): ?>
            csv += '<?php echo addslashes($item); ?>,<?php echo $amount; ?>\n';
            <?php endif; ?>
            <?php endforeach; ?>
            
            <?php foreach ($cash_flow['financing']['outflows'] as $item => $amount): ?>
            <?php if ($amount > 0): ?>
            csv += '<?php echo addslashes($item); ?>,-<?php echo $amount; ?>\n';
            <?php endif; ?>
            <?php endforeach; ?>
            
            csv += 'Net Cash from Financing Activities,<?php echo $cash_flow['financing']['net_financing']; ?>\n';
            csv += 'NET INCREASE (DECREASE) IN CASH,<?php echo $cash_flow['net_change']; ?>\n';
            csv += 'Cash at Beginning of Period,<?php echo $cash_flow['beginning_cash']; ?>\n';
            csv += 'CASH AT END OF PERIOD,<?php echo $cash_flow['ending_cash']; ?>\n';
            
            // Download
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.setAttribute('hidden', '');
            a.setAttribute('href', url);
            a.setAttribute('download', 'cash-flow-<?php echo $start_date; ?>-to-<?php echo $end_date; ?>.csv');
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        }
        
        // Add loading animation on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Add staggered animation to cards
            const cards = document.querySelectorAll('.fade-in');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
            });
            
            // Add hover effects to table rows
            const tableRows = document.querySelectorAll('.cashflow-table tbody tr');
            tableRows.forEach(row => {
                row.addEventListener('mouseenter', function() {
                    this.style.transform = 'scale(1.01)';
                });
                row.addEventListener('mouseleave', function() {
                    this.style.transform = 'scale(1)';
                });
            });
        });
    </script>
</body>
</html>
