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

// Get selected date or use current date
$selected_date = $_GET['date'] ?? date('Y-m-d');
$report_date = new DateTime($selected_date);

// Calculate Balance Sheet Data
$balance_sheet = [
    'assets' => [
        'current_assets' => [],
        'fixed_assets' => [],
        'total_current' => 0,
        'total_fixed' => 0,
        'total_assets' => 0
    ],
    'liabilities' => [
        'current_liabilities' => [],
        'long_term_liabilities' => [],
        'total_current' => 0,
        'total_long_term' => 0,
        'total_liabilities' => 0
    ],
    'equity' => [
        'items' => [],
        'total_equity' => 0
    ]
];

// ========================================
// CURRENT ASSETS - Real Data from Database
// ========================================

// 1. Cash and Cash Equivalents (from sales and cash transactions)
$stmt = $conn->prepare("
    SELECT 
        COALESCE(SUM(CASE WHEN payment_method IN ('cash', 'mpesa', 'bank_transfer') THEN final_amount ELSE 0 END), 0) as cash_sales,
        COALESCE(SUM(CASE WHEN payment_method = 'credit' THEN final_amount ELSE 0 END), 0) as credit_sales
    FROM sales 
    WHERE sale_date <= ?
");
$stmt->execute([$selected_date]);
$sales_data = $stmt->fetch(PDO::FETCH_ASSOC);
$cash_from_sales = $sales_data['cash_sales'];

// 2. Accounts Receivable (credit sales minus payments received)
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(final_amount), 0) as total_credit_sales
    FROM sales 
    WHERE sale_date <= ? AND payment_method = 'credit'
");
$stmt->execute([$selected_date]);
$total_credit_sales = $stmt->fetch(PDO::FETCH_ASSOC)['total_credit_sales'];

// Get payments received on credit sales (if payment tracking exists)
$payments_received = 0;
try {
$stmt = $conn->prepare("
        SELECT COALESCE(SUM(amount), 0) as payments_received
        FROM customer_payments 
        WHERE payment_date <= ?
    ");
    $stmt->execute([$selected_date]);
    $payments_received = $stmt->fetch(PDO::FETCH_ASSOC)['payments_received'];
} catch (Exception $e) {
    // If customer_payments table doesn't exist, assume no payments received
    $payments_received = 0;
}
$accounts_receivable = $total_credit_sales - $payments_received;

// 3. Inventory Value (current stock at cost)
$stmt = $conn->prepare("
    SELECT 
        COALESCE(SUM(p.cost_price * p.quantity), 0) as inventory_value,
        COUNT(CASE WHEN p.quantity <= p.reorder_point THEN 1 END) as low_stock_items,
        COUNT(CASE WHEN p.quantity = 0 THEN 1 END) as out_of_stock_items
    FROM products p
    WHERE p.status = 'active'
");
$stmt->execute();
$inventory_data = $stmt->fetch(PDO::FETCH_ASSOC);
$inventory_value = $inventory_data['inventory_value'];

// 4. Prepaid Expenses (from expense management)
$prepaid_expenses = 0;
try {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(total_amount), 0) as prepaid_expenses
        FROM expenses 
        WHERE expense_date > ? AND approval_status = 'approved'
");
$stmt->execute([$selected_date]);
    $prepaid_expenses = $stmt->fetch(PDO::FETCH_ASSOC)['prepaid_expenses'];
} catch (Exception $e) {
    $prepaid_expenses = 0;
}

// 5. Other Current Assets (deposits, advances, etc.)
$other_current_assets = 0;
try {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(amount), 0) as other_current_assets
        FROM supplier_advances 
        WHERE advance_date <= ? AND status = 'active'
    ");
    $stmt->execute([$selected_date]);
    $other_current_assets = $stmt->fetch(PDO::FETCH_ASSOC)['other_current_assets'];
} catch (Exception $e) {
    $other_current_assets = 0;
}

// Current Assets Summary
$balance_sheet['assets']['current_assets'] = [
    'Cash and Cash Equivalents' => $cash_from_sales,
    'Accounts Receivable' => max(0, $accounts_receivable),
    'Inventory' => $inventory_value,
    'Prepaid Expenses' => $prepaid_expenses,
    'Other Current Assets' => $other_current_assets
];

$balance_sheet['assets']['total_current'] = array_sum($balance_sheet['assets']['current_assets']);

// ========================================
// FIXED ASSETS - Real Data from Database
// ========================================

// 1. Equipment and Assets (from expense management - capital expenses)
$equipment_value = 0;
try {
    $stmt = $conn->prepare("
        SELECT 
            COALESCE(SUM(total_amount), 0) as equipment_value
        FROM expenses 
        WHERE expense_date <= ? 
        AND approval_status = 'approved' 
        AND category_id IN (
            SELECT id FROM expense_categories 
            WHERE name LIKE '%equipment%' OR name LIKE '%furniture%' OR name LIKE '%computer%'
        )
    ");
    $stmt->execute([$selected_date]);
    $equipment_value = $stmt->fetch(PDO::FETCH_ASSOC)['equipment_value'];
} catch (Exception $e) {
    $equipment_value = 0;
}

// 2. Computer Equipment and Software
$computer_equipment = 0;
try {
    $stmt = $conn->prepare("
        SELECT 
            COALESCE(SUM(total_amount), 0) as computer_equipment
        FROM expenses 
        WHERE expense_date <= ? 
        AND approval_status = 'approved' 
        AND category_id IN (
            SELECT id FROM expense_categories 
            WHERE name LIKE '%computer%' OR name LIKE '%software%' OR name LIKE '%technology%'
        )
    ");
    $stmt->execute([$selected_date]);
    $computer_equipment = $stmt->fetch(PDO::FETCH_ASSOC)['computer_equipment'];
} catch (Exception $e) {
    $computer_equipment = 0;
}

// 3. Furniture and Fixtures
$furniture_fixtures = 0;
try {
    $stmt = $conn->prepare("
        SELECT 
            COALESCE(SUM(total_amount), 0) as furniture_fixtures
        FROM expenses 
        WHERE expense_date <= ? 
        AND approval_status = 'approved' 
        AND category_id IN (
            SELECT id FROM expense_categories 
            WHERE name LIKE '%furniture%' OR name LIKE '%fixtures%' OR name LIKE '%office%'
        )
    ");
    $stmt->execute([$selected_date]);
    $furniture_fixtures = $stmt->fetch(PDO::FETCH_ASSOC)['furniture_fixtures'];
} catch (Exception $e) {
    $furniture_fixtures = 0;
}

// 4. Vehicles (if any)
$vehicles = 0;
try {
    $stmt = $conn->prepare("
        SELECT 
            COALESCE(SUM(total_amount), 0) as vehicles
        FROM expenses 
        WHERE expense_date <= ? 
        AND approval_status = 'approved' 
        AND category_id IN (
            SELECT id FROM expense_categories 
            WHERE name LIKE '%vehicle%' OR name LIKE '%transport%'
        )
    ");
    $stmt->execute([$selected_date]);
    $vehicles = $stmt->fetch(PDO::FETCH_ASSOC)['vehicles'];
} catch (Exception $e) {
    $vehicles = 0;
}

// 5. Accumulated Depreciation (simplified calculation)
$total_fixed_before_depreciation = $equipment_value + $computer_equipment + $furniture_fixtures + $vehicles;
$accumulated_depreciation = $total_fixed_before_depreciation * 0.1; // 10% depreciation (simplified)

$balance_sheet['assets']['fixed_assets'] = [
    'Equipment' => $equipment_value,
    'Computer Equipment' => $computer_equipment,
    'Furniture and Fixtures' => $furniture_fixtures,
    'Vehicles' => $vehicles,
    'Less: Accumulated Depreciation' => -$accumulated_depreciation
];

$balance_sheet['assets']['total_fixed'] = array_sum($balance_sheet['assets']['fixed_assets']);
$balance_sheet['assets']['total_assets'] = $balance_sheet['assets']['total_current'] + $balance_sheet['assets']['total_fixed'];

// ========================================
// LIABILITIES - Real Data from Database
// ========================================

// 1. Accounts Payable (unpaid expenses and supplier invoices)
$accounts_payable = 0;
try {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(total_amount), 0) as accounts_payable
        FROM expenses 
        WHERE expense_date <= ? AND approval_status = 'pending'
    ");
    $stmt->execute([$selected_date]);
    $accounts_payable = $stmt->fetch(PDO::FETCH_ASSOC)['accounts_payable'];
} catch (Exception $e) {
    $accounts_payable = 0;
}

// 2. Accrued Expenses (expenses incurred but not yet paid)
$accrued_expenses = 0;
try {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(total_amount), 0) as accrued_expenses
        FROM expenses 
        WHERE expense_date <= ? 
        AND approval_status = 'approved' 
        AND payment_status = 'pending'
    ");
    $stmt->execute([$selected_date]);
    $accrued_expenses = $stmt->fetch(PDO::FETCH_ASSOC)['accrued_expenses'];
} catch (Exception $e) {
    $accrued_expenses = 0;
}

// 3. Tax Liabilities (estimated from sales)
$stmt = $conn->prepare("
    SELECT 
        COALESCE(SUM(final_amount), 0) as total_sales,
        COALESCE(SUM(tax_amount), 0) as tax_collected
    FROM sales 
    WHERE sale_date <= ?
");
$stmt->execute([$selected_date]);
$tax_data = $stmt->fetch(PDO::FETCH_ASSOC);
$estimated_tax_liability = $tax_data['tax_collected'] * 0.8; // Assume 80% of collected tax is liability

// 4. Short-term Loans removed - loan tracking not implemented

// 5. Supplier Advances (money owed to suppliers)
$supplier_advances = 0;
try {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(amount), 0) as supplier_advances
        FROM supplier_advances 
        WHERE advance_date <= ? AND status = 'outstanding'
    ");
    $stmt->execute([$selected_date]);
    $supplier_advances = $stmt->fetch(PDO::FETCH_ASSOC)['supplier_advances'];
} catch (Exception $e) {
    $supplier_advances = 0;
}

$balance_sheet['liabilities']['current_liabilities'] = [
    'Accounts Payable' => $accounts_payable,
    'Accrued Expenses' => $accrued_expenses,
    'Tax Liabilities' => $estimated_tax_liability,
    'Supplier Advances' => $supplier_advances
];

$balance_sheet['liabilities']['total_current'] = array_sum($balance_sheet['liabilities']['current_liabilities']);

// Long-term Liabilities removed - loan tracking not implemented
$balance_sheet['liabilities']['long_term_liabilities'] = [
    // Equipment Financing will be added when actual financing data is available
];

$balance_sheet['liabilities']['total_long_term'] = array_sum($balance_sheet['liabilities']['long_term_liabilities']);
$balance_sheet['liabilities']['total_liabilities'] = $balance_sheet['liabilities']['total_current'] + $balance_sheet['liabilities']['total_long_term'];

// ========================================
// EQUITY - Real Data from Database
// ========================================

// 1. Owner's Capital (initial investment + additional capital)
$owner_capital = 0;
try {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(amount), 0) as owner_capital
        FROM capital_investments 
        WHERE investment_date <= ?
    ");
    $stmt->execute([$selected_date]);
    $owner_capital = $stmt->fetch(PDO::FETCH_ASSOC)['owner_capital'];
} catch (Exception $e) {
    $owner_capital = 0;
}

// If no capital investments table, set to zero (no default demo data)
if ($owner_capital == 0) {
    $owner_capital = 0; // No demo data - use actual capital investments only
}

// 2. Retained Earnings (calculated from profit/loss)
$stmt = $conn->prepare("
    SELECT 
        COALESCE(SUM(CASE WHEN sale_date <= ? THEN final_amount ELSE 0 END), 0) as total_revenue,
        COALESCE(SUM(CASE WHEN sale_date <= ? THEN (si.quantity * si.price) ELSE 0 END), 0) as total_sales,
        COALESCE(SUM(CASE WHEN sale_date <= ? THEN (si.quantity * p.cost_price) ELSE 0 END), 0) as total_cogs
    FROM sales s
    LEFT JOIN sale_items si ON s.id = si.sale_id
    LEFT JOIN products p ON si.product_id = p.id
    WHERE s.sale_date <= ?
");
$stmt->execute([$selected_date, $selected_date, $selected_date, $selected_date]);
$revenue_data = $stmt->fetch(PDO::FETCH_ASSOC);

// Calculate expenses
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(total_amount), 0) as total_expenses
    FROM expenses 
    WHERE expense_date <= ? AND approval_status = 'approved'
");
$stmt->execute([$selected_date]);
$total_expenses = $stmt->fetch(PDO::FETCH_ASSOC)['total_expenses'];

// Calculate net income
$gross_profit = $revenue_data['total_sales'] - $revenue_data['total_cogs'];
$net_income = $gross_profit - $total_expenses;

// 3. Retained Earnings (previous retained earnings + current net income)
$previous_retained_earnings = 0;
try {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(amount), 0) as previous_retained_earnings
        FROM retained_earnings 
        WHERE date <= ?
    ");
    $stmt->execute([$selected_date]);
    $previous_retained_earnings = $stmt->fetch(PDO::FETCH_ASSOC)['previous_retained_earnings'];
} catch (Exception $e) {
    $previous_retained_earnings = 0;
}

$retained_earnings = $previous_retained_earnings + $net_income;

// 4. Additional Paid-in Capital (if any)
$additional_capital = 0;
try {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(amount), 0) as additional_capital
        FROM additional_capital 
        WHERE date <= ?
    ");
    $stmt->execute([$selected_date]);
    $additional_capital = $stmt->fetch(PDO::FETCH_ASSOC)['additional_capital'];
} catch (Exception $e) {
    $additional_capital = 0;
}

$balance_sheet['equity']['items'] = [
    'Owner\'s Capital' => $owner_capital,
    'Additional Paid-in Capital' => $additional_capital,
    'Retained Earnings' => $retained_earnings
];

$balance_sheet['equity']['total_equity'] = array_sum($balance_sheet['equity']['items']);

// Verify balance (Assets = Liabilities + Equity)
$balance_check = abs($balance_sheet['assets']['total_assets'] - ($balance_sheet['liabilities']['total_liabilities'] + $balance_sheet['equity']['total_equity']));

// ========================================
// ADDITIONAL FINANCIAL METRICS
// ========================================

// Calculate key financial ratios
$current_ratio = $balance_sheet['liabilities']['total_current'] > 0 ? 
    $balance_sheet['assets']['total_current'] / $balance_sheet['liabilities']['total_current'] : 0;

$debt_to_equity = $balance_sheet['equity']['total_equity'] > 0 ? 
    $balance_sheet['liabilities']['total_liabilities'] / $balance_sheet['equity']['total_equity'] : 0;

$working_capital = $balance_sheet['assets']['total_current'] - $balance_sheet['liabilities']['total_current'];

// Calculate inventory turnover properly
// Formula: Inventory Turnover = Cost of Goods Sold / Average Inventory
// This represents how many times inventory is sold and replaced annually
// First, check how much historical data we have
$stmt = $conn->prepare("
    SELECT
        MIN(sale_date) as earliest_sale,
        MAX(sale_date) as latest_sale,
        COUNT(DISTINCT DATE_FORMAT(sale_date, '%Y-%m')) as months_with_sales
    FROM sales
    WHERE sale_date <= ?
");
$stmt->execute([$selected_date]);
$sales_period = $stmt->fetch(PDO::FETCH_ASSOC);

$inventory_turnover = 0;

if ($sales_period['earliest_sale']) {
    // Calculate period length (in months) for turnover calculation
    $period_months = max(1, min(12, $sales_period['months_with_sales']));

    // Get COGS for available period
    $period_start = date('Y-m-d', strtotime($selected_date . " -{$period_months} months"));
$stmt = $conn->prepare("
    SELECT 
        COALESCE(SUM(si.quantity * p.cost_price), 0) as cost_of_goods_sold
    FROM sales s
    JOIN sale_items si ON s.id = si.sale_id
    JOIN products p ON si.product_id = p.id
        WHERE s.sale_date BETWEEN ? AND ?
    ");
    $stmt->execute([$period_start, $selected_date]);
    $cogs_period = $stmt->fetch(PDO::FETCH_ASSOC)['cost_of_goods_sold'];

    // Calculate average inventory (using current as approximation)
    $average_inventory = $inventory_value;

    // Calculate inventory turnover ratio (annualized if period is less than 12 months)
    if ($average_inventory > 0 && $cogs_period > 0) {
        $period_turnover = $cogs_period / $average_inventory;
        // Annualize if we have less than 12 months of data
        $inventory_turnover = $period_months < 12 ? $period_turnover * (12 / $period_months) : $period_turnover;
    }
}

// Ensure reasonable bounds for display
$inventory_turnover = min($inventory_turnover, 999); // Cap at reasonable maximum

// Get accounts receivable aging
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_credit_sales,
        COALESCE(SUM(final_amount), 0) as total_receivables,
        AVG(DATEDIFF(?, sale_date)) as avg_days_outstanding
    FROM sales 
    WHERE sale_date <= ? AND payment_method = 'credit'
");
$stmt->execute([$selected_date, $selected_date]);
$receivables_data = $stmt->fetch(PDO::FETCH_ASSOC);

// Financial health indicators
$financial_metrics = [
    'current_ratio' => round($current_ratio, 2),
    'debt_to_equity' => round($debt_to_equity, 2),
    'working_capital' => $working_capital,
    'inventory_turnover' => round($inventory_turnover, 1), // Updated to use corrected calculation
    'avg_receivables_days' => round($receivables_data['avg_days_outstanding'] ?? 0, 0),
    'total_credit_sales' => $receivables_data['total_credit_sales'] ?? 0,
    'low_stock_items' => $inventory_data['low_stock_items'] ?? 0,
    'out_of_stock_items' => $inventory_data['out_of_stock_items'] ?? 0
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Balance Sheet - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
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
        }
        
        .balance-sheet-table {
            font-size: 0.95rem;
            border-collapse: separate;
            border-spacing: 0;
        }
        
        .section-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, #4f46e5 100%);
            color: white;
            font-weight: bold;
            text-shadow: 0 1px 2px rgba(0,0,0,0.1);
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
        
        .balance-check {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            border: 2px solid var(--success-color);
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
        }
        
        .print-btn {
            background: linear-gradient(135deg, var(--primary-color) 0%, #4f46e5 100%);
            border: none;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
        }
        
        .print-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0,0,0,0.15);
        }
        
        .metric-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
        }
        
        .metric-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(0,0,0,0.1);
        }
        
        .metric-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .metric-label {
            font-size: 0.875rem;
            color: #6b7280;
            font-weight: 500;
        }
        
        .metric-description {
            font-size: 0.75rem;
            color: #9ca3af;
        }
        
        .balance-sheet-card {
            border-radius: 16px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            border: 1px solid #e5e7eb;
            transition: all 0.3s ease;
            overflow: hidden;
        }
        
        .balance-sheet-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(0,0,0,0.1);
        }
        
        .balance-sheet-card .card-header {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            border-bottom: 2px solid var(--primary-color);
            border-radius: 16px 16px 0 0 !important;
            position: relative;
            overflow: hidden;
        }
        
        .balance-sheet-card .card-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--primary-color) 0%, #4f46e5 100%);
        }
        
        .balance-sheet-card .card-header h5 {
            position: relative;
            z-index: 1;
        }
        
        .table-hover tbody tr:hover {
            background-color: rgba(99, 102, 241, 0.05);
            transform: scale(1.01);
            transition: all 0.2s ease;
        }
        
        .balance-sheet-table tbody tr {
            transition: all 0.2s ease;
        }
        
        .balance-sheet-table tbody tr:nth-child(even) {
            background-color: rgba(0,0,0,0.02);
        }
        
        .balance-sheet-table tbody tr:nth-child(odd) {
            background-color: transparent;
        }
        
        .section-header td {
            position: relative;
            overflow: hidden;
        }
        
        .section-header td::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent 0%, rgba(255,255,255,0.3) 50%, transparent 100%);
        }
        
        .loading-animation {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .fade-in {
            animation: fadeIn 0.5s ease-in;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .amount-cell {
            font-family: 'Courier New', monospace;
            font-weight: 600;
        }
        
        .positive-amount {
            color: var(--success-color);
        }
        
        .negative-amount {
            color: var(--danger-color);
        }
        
        .neutral-amount {
            color: #6b7280;
        }
        
        .status-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 8px;
        }
        
        .status-good { background-color: var(--success-color); }
        .status-warning { background-color: var(--warning-color); }
        .status-danger { background-color: var(--danger-color); }
        
        .financial-summary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 2rem;
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
        
        .balance-sheet-column {
            min-height: 600px;
        }
        
        .balance-sheet-column .card {
            height: 100%;
        }
        
        .balance-sheet-column .card-body {
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        
        .balance-sheet-column .table-responsive {
            flex: 1;
        }
        
        .balance-sheet-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--primary-color) 0%, #4f46e5 100%);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
        }
        
        .balance-summary-cards {
            margin-top: 1.5rem;
        }
        
        .summary-mini-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 1rem;
            display: flex;
            align-items: center;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .summary-mini-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(0,0,0,0.1);
        }
        
        .summary-mini-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
            font-size: 1.2rem;
        }
        
        .summary-mini-icon.assets {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }
        
        .summary-mini-icon.liabilities {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
        }
        
        .summary-mini-icon.equity {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
        }
        
        .summary-mini-icon.balance {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
        }
        
        .summary-mini-content {
            flex: 1;
        }
        
        .summary-mini-value {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            color: #1f2937;
        }
        
        .summary-mini-label {
            font-size: 0.8rem;
            color: #6b7280;
            font-weight: 500;
        }
        
        .header-subtitle {
            font-size: 1rem;
            color: #6b7280;
        }
        
        .breadcrumb {
            background: none;
            padding: 0;
            margin-bottom: 1rem;
        }
        
        .breadcrumb-item a {
            color: var(--primary-color);
            transition: color 0.3s ease;
        }
        
        .breadcrumb-item a:hover {
            color: #4f46e5;
        }
        
        .breadcrumb-item.active {
            color: #6b7280;
        }
        
        @media (max-width: 768px) {
            .balance-sheet-column {
                margin-bottom: 2rem;
            }
            
            .balance-sheet-icon {
                width: 50px;
                height: 50px;
                font-size: 1.2rem;
            }
            
            .summary-mini-card {
                padding: 0.75rem;
            }
            
            .summary-mini-value {
                font-size: 1rem;
            }
        }
        
        @media print {
            .no-print { display: none !important; }
            .balance-sheet-table { font-size: 0.8rem; }
            .metric-card { break-inside: avoid; }
            .col-md-6 { 
                width: 50% !important; 
                float: left;
            }
            .balance-sheet-column {
                page-break-inside: avoid;
            }
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
                            <li class="breadcrumb-item active"><i class="bi bi-bar-chart me-1"></i>Balance Sheet</li>
                        </ol>
                    </nav>
                    <div class="d-flex align-items-center mb-3">
                        <div class="balance-sheet-icon me-3">
                            <i class="bi bi-building"></i>
                        </div>
                        <div>
                            <h1 class="mb-1">Balance Sheet</h1>
                            <p class="header-subtitle mb-0">
                                <i class="bi bi-calendar3 me-2"></i>
                                Financial position as of <strong><?php echo $report_date->format('F d, Y'); ?></strong>
                            </p>
                        </div>
                    </div>
                    <div class="balance-summary-cards">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <div class="summary-mini-card">
                                    <div class="summary-mini-icon assets">
                                        <i class="bi bi-building"></i>
                                    </div>
                                    <div class="summary-mini-content">
                                        <div class="summary-mini-value"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($balance_sheet['assets']['total_assets'], 0); ?></div>
                                        <div class="summary-mini-label">Total Assets</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="summary-mini-card">
                                    <div class="summary-mini-icon liabilities">
                                        <i class="bi bi-credit-card"></i>
                                    </div>
                                    <div class="summary-mini-content">
                                        <div class="summary-mini-value"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($balance_sheet['liabilities']['total_liabilities'], 0); ?></div>
                                        <div class="summary-mini-label">Total Liabilities</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="summary-mini-card">
                                    <div class="summary-mini-icon equity">
                                        <i class="bi bi-person-badge"></i>
                                    </div>
                                    <div class="summary-mini-content">
                                        <div class="summary-mini-value"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($balance_sheet['equity']['total_equity'], 0); ?></div>
                                        <div class="summary-mini-label">Owner's Equity</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="summary-mini-card">
                                    <div class="summary-mini-icon balance">
                                        <i class="bi bi-<?php echo $balance_sheet['assets']['total_assets'] == ($balance_sheet['liabilities']['total_liabilities'] + $balance_sheet['equity']['total_equity']) ? 'check-circle' : 'x-circle'; ?>"></i>
                                    </div>
                                    <div class="summary-mini-content">
                                        <div class="summary-mini-value <?php echo $balance_sheet['assets']['total_assets'] == ($balance_sheet['liabilities']['total_liabilities'] + $balance_sheet['equity']['total_equity']) ? 'text-success' : 'text-danger'; ?>">
                                            <?php echo $balance_sheet['assets']['total_assets'] == ($balance_sheet['liabilities']['total_liabilities'] + $balance_sheet['equity']['total_equity']) ? 'BALANCED' : 'UNBALANCED'; ?>
                                        </div>
                                        <div class="summary-mini-label">Balance Status</div>
                                    </div>
                                </div>
                            </div>
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
                <!-- Date Selection -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body">
                                <form method="GET" class="d-flex align-items-end gap-2">
                                    <div class="flex-grow-1">
                                        <label for="date" class="form-label">Report Date</label>
                                        <input type="date" class="form-control" id="date" name="date" value="<?php echo $selected_date; ?>">
                                    </div>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-search"></i> Update
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-8">
                        <div class="card balance-check">
                            <div class="card-body">
                                <h6 class="card-title mb-2">Balance Verification</h6>
                                <div class="row">
                                    <div class="col-md-4">
                                        <small class="text-muted">Total Assets</small>
                                        <div class="fw-bold"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($balance_sheet['assets']['total_assets'], 2); ?></div>
                                    </div>
                                    <div class="col-md-4">
                                        <small class="text-muted">Total Liabilities + Equity</small>
                                        <div class="fw-bold"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($balance_sheet['liabilities']['total_liabilities'] + $balance_sheet['equity']['total_equity'], 2); ?></div>
                                    </div>
                                    <div class="col-md-4">
                                        <small class="text-muted">Difference</small>
                                        <div class="fw-bold <?php echo $balance_check < 1 ? 'text-success' : 'text-warning'; ?>">
                                            <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($balance_check, 2); ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Financial Metrics -->
                <div class="row mb-4 fade-in">
                    <div class="col-12">
                        <div class="card balance-sheet-card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-graph-up me-2"></i>Financial Health Metrics</h5>
                                <small class="text-muted">Key performance indicators for financial analysis</small>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-3 mb-4">
                                        <div class="metric-card p-3 text-center">
                                            <div class="metric-value <?php echo $financial_metrics['current_ratio'] >= 2 ? 'text-success' : ($financial_metrics['current_ratio'] >= 1 ? 'text-warning' : 'text-danger'); ?>">
                                                <?php echo $financial_metrics['current_ratio']; ?>
                                            </div>
                                            <div class="metric-label">Current Ratio</div>
                                            <div class="metric-description">
                                                <span class="status-indicator <?php echo $financial_metrics['current_ratio'] >= 2 ? 'status-good' : ($financial_metrics['current_ratio'] >= 1 ? 'status-warning' : 'status-danger'); ?>"></span>
                                                ≥2.0 is good
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-3 mb-4">
                                        <div class="metric-card p-3 text-center">
                                            <div class="metric-value <?php echo $financial_metrics['debt_to_equity'] <= 1 ? 'text-success' : ($financial_metrics['debt_to_equity'] <= 2 ? 'text-warning' : 'text-danger'); ?>">
                                                <?php echo $financial_metrics['debt_to_equity']; ?>
                                            </div>
                                            <div class="metric-label">Debt-to-Equity</div>
                                            <div class="metric-description">
                                                <span class="status-indicator <?php echo $financial_metrics['debt_to_equity'] <= 1 ? 'status-good' : ($financial_metrics['debt_to_equity'] <= 2 ? 'status-warning' : 'status-danger'); ?>"></span>
                                                ≤1.0 is good
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-3 mb-4">
                                        <div class="metric-card p-3 text-center">
                                            <div class="metric-value <?php echo $financial_metrics['working_capital'] > 0 ? 'text-success' : 'text-danger'; ?>">
                                                <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($financial_metrics['working_capital'], 0); ?>
                                            </div>
                                            <div class="metric-label">Working Capital</div>
                                            <div class="metric-description">
                                                <span class="status-indicator <?php echo $financial_metrics['working_capital'] > 0 ? 'status-good' : 'status-danger'; ?>"></span>
                                                Positive is good
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-3 mb-4">
                                        <div class="metric-card p-3 text-center" title="How many times inventory is sold and replaced annually. Higher turnover indicates efficient inventory management.">
                                            <div class="metric-value text-info">
                                                <?php echo number_format($inventory_turnover, 1); ?>
                                            </div>
                                            <div class="metric-label">Inventory Turnover</div>
                                            <div class="metric-description">
                                                <span class="status-indicator <?php echo $inventory_turnover >= 4 ? 'status-good' : ($inventory_turnover >= 2 ? 'status-warning' : 'status-danger'); ?>"></span>
                                                <?php if ($inventory_turnover == 0): ?>
                                                    No sales data available
                                                <?php elseif ($inventory_turnover >= 6): ?>
                                                    Very high (Excellent)
                                                <?php elseif ($inventory_turnover >= 4): ?>
                                                    Good (4-6 times/year)
                                                <?php elseif ($inventory_turnover >= 2): ?>
                                                    Moderate (2-4 times/year)
                                                <?php else: ?>
                                                    Low (Needs improvement)
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="row mt-4">
                                    <div class="col-md-4">
                                        <div class="d-flex justify-content-between align-items-center p-3 bg-light rounded">
                                            <span class="text-muted">
                                                <i class="bi bi-clock me-2"></i>Avg. Receivables Days
                                            </span>
                                            <span class="fw-bold fs-5"><?php echo $financial_metrics['avg_receivables_days']; ?> days</span>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="d-flex justify-content-between align-items-center p-3 bg-warning bg-opacity-10 rounded">
                                            <span class="text-muted">
                                                <i class="bi bi-exclamation-triangle me-2"></i>Low Stock Items
                                            </span>
                                            <span class="fw-bold fs-5 text-warning"><?php echo $financial_metrics['low_stock_items']; ?></span>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="d-flex justify-content-between align-items-center p-3 bg-danger bg-opacity-10 rounded">
                                            <span class="text-muted">
                                                <i class="bi bi-x-circle me-2"></i>Out of Stock
                                            </span>
                                            <span class="fw-bold fs-5 text-danger"><?php echo $financial_metrics['out_of_stock_items']; ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Financial Summary -->
                <div class="financial-summary fade-in">
                    <div class="row">
                        <div class="col-md-3">
                            <div class="summary-item">
                                <div class="summary-value"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($balance_sheet['assets']['total_assets'], 0); ?></div>
                                <div class="summary-label">Total Assets</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="summary-item">
                                <div class="summary-value"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($balance_sheet['liabilities']['total_liabilities'], 0); ?></div>
                                <div class="summary-label">Total Liabilities</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="summary-item">
                                <div class="summary-value"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($balance_sheet['equity']['total_equity'], 0); ?></div>
                                <div class="summary-label">Owner's Equity</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="summary-item">
                                <div class="summary-value">
                                    <?php if ($balance_sheet['assets']['total_assets'] == ($balance_sheet['liabilities']['total_liabilities'] + $balance_sheet['equity']['total_equity'])): ?>
                                        <i class="bi bi-check-circle-fill text-success"></i>
                                    <?php else: ?>
                                        <i class="bi bi-x-circle-fill text-danger"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="summary-label">
                                    <?php echo $balance_sheet['assets']['total_assets'] == ($balance_sheet['liabilities']['total_liabilities'] + $balance_sheet['equity']['total_equity']) ? 'BALANCED' : 'NOT BALANCED'; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Balance Sheet -->
                <div class="row fade-in">
                    <!-- ASSETS Column -->
                    <div class="col-md-6 mb-4 balance-sheet-column">
                        <div class="card balance-sheet-card">
                            <div class="card-header">
                                <h5 class="mb-0">
                                    <i class="bi bi-building me-2"></i>
                                    ASSETS
                                </h5>
                                <small class="text-muted">As of <?php echo $report_date->format('F d, Y'); ?></small>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table balance-sheet-table mb-0">
                                        <thead>
                                            <tr>
                                                <th width="60%">Account</th>
                                                <th width="40%" class="text-end">Amount (<?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?>)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <!-- Current Assets -->
                                            <tr class="subsection-header">
                                                <td>&nbsp;&nbsp;Current Assets</td>
                                                <td></td>
                                            </tr>
                                            <?php foreach ($balance_sheet['assets']['current_assets'] as $account => $amount): ?>
                                            <tr>
                                                <td>&nbsp;&nbsp;&nbsp;&nbsp;<?php echo htmlspecialchars($account); ?></td>
                                                <td class="text-end amount-cell <?php echo $amount > 0 ? 'positive-amount' : 'neutral-amount'; ?>">
                                                    <?php echo number_format($amount, 2); ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <tr class="total-row">
                                                <td>&nbsp;&nbsp;&nbsp;&nbsp;Total Current Assets</td>
                                                <td class="text-end amount-cell positive-amount"><?php echo number_format($balance_sheet['assets']['total_current'], 2); ?></td>
                                            </tr>
                                            
                                            <!-- Fixed Assets -->
                                            <tr class="subsection-header">
                                                <td>&nbsp;&nbsp;Fixed Assets</td>
                                                <td></td>
                                            </tr>
                                            <?php foreach ($balance_sheet['assets']['fixed_assets'] as $account => $amount): ?>
                                            <tr>
                                                <td>&nbsp;&nbsp;&nbsp;&nbsp;<?php echo htmlspecialchars($account); ?></td>
                                                <td class="text-end amount-cell <?php echo $amount > 0 ? 'positive-amount' : ($amount < 0 ? 'negative-amount' : 'neutral-amount'); ?>">
                                                    <?php echo number_format($amount, 2); ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <tr class="total-row">
                                                <td>&nbsp;&nbsp;&nbsp;&nbsp;Total Fixed Assets</td>
                                                <td class="text-end amount-cell positive-amount"><?php echo number_format($balance_sheet['assets']['total_fixed'], 2); ?></td>
                                            </tr>
                                            
                                            <tr class="total-row" style="background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%); border-top: 3px solid var(--primary-color);">
                                                <td><strong>TOTAL ASSETS</strong></td>
                                                <td class="text-end amount-cell positive-amount"><strong><?php echo number_format($balance_sheet['assets']['total_assets'], 2); ?></strong></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- LIABILITIES & EQUITY Column -->
                    <div class="col-md-6 mb-4 balance-sheet-column">
                        <div class="card balance-sheet-card">
                            <div class="card-header">
                                <h5 class="mb-0">
                                    <i class="bi bi-credit-card me-2"></i>
                                    LIABILITIES & EQUITY
                                </h5>
                                <small class="text-muted">As of <?php echo $report_date->format('F d, Y'); ?></small>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table balance-sheet-table mb-0">
                                        <thead>
                                            <tr>
                                                <th width="60%">Account</th>
                                                <th width="40%" class="text-end">Amount (<?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?>)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <!-- LIABILITIES -->
                                            <tr class="section-header">
                                                <td colspan="2">LIABILITIES</td>
                                            </tr>
                                            
                                            <!-- Current Liabilities -->
                                            <tr class="subsection-header">
                                                <td>&nbsp;&nbsp;Current Liabilities</td>
                                                <td></td>
                                            </tr>
                                            <?php foreach ($balance_sheet['liabilities']['current_liabilities'] as $account => $amount): ?>
                                            <tr>
                                                <td>&nbsp;&nbsp;&nbsp;&nbsp;<?php echo htmlspecialchars($account); ?></td>
                                                <td class="text-end amount-cell <?php echo $amount > 0 ? 'negative-amount' : 'neutral-amount'; ?>">
                                                    <?php echo number_format($amount, 2); ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <tr class="total-row">
                                                <td>&nbsp;&nbsp;&nbsp;&nbsp;Total Current Liabilities</td>
                                                <td class="text-end amount-cell negative-amount"><?php echo number_format($balance_sheet['liabilities']['total_current'], 2); ?></td>
                                            </tr>
                                            
                                            <!-- Long-term Liabilities -->
                                            <tr class="subsection-header">
                                                <td>&nbsp;&nbsp;Long-term Liabilities</td>
                                                <td></td>
                                            </tr>
                                            <?php foreach ($balance_sheet['liabilities']['long_term_liabilities'] as $account => $amount): ?>
                                            <tr>
                                                <td>&nbsp;&nbsp;&nbsp;&nbsp;<?php echo htmlspecialchars($account); ?></td>
                                                <td class="text-end amount-cell <?php echo $amount > 0 ? 'negative-amount' : 'neutral-amount'; ?>">
                                                    <?php echo number_format($amount, 2); ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <tr class="total-row">
                                                <td>&nbsp;&nbsp;&nbsp;&nbsp;Total Long-term Liabilities</td>
                                                <td class="text-end amount-cell negative-amount"><?php echo number_format($balance_sheet['liabilities']['total_long_term'], 2); ?></td>
                                            </tr>
                                            
                                            <tr class="total-row" style="background: linear-gradient(135deg, #fef2f2 0%, #fecaca 100%); border-top: 3px solid var(--danger-color);">
                                                <td><strong>TOTAL LIABILITIES</strong></td>
                                                <td class="text-end amount-cell negative-amount"><strong><?php echo number_format($balance_sheet['liabilities']['total_liabilities'], 2); ?></strong></td>
                                            </tr>
                                            
                                            <!-- EQUITY -->
                                            <tr class="section-header">
                                                <td colspan="2">OWNER'S EQUITY</td>
                                            </tr>
                                            
                                            <?php foreach ($balance_sheet['equity']['items'] as $account => $amount): ?>
                                            <tr>
                                                <td>&nbsp;&nbsp;<?php echo htmlspecialchars($account); ?></td>
                                                <td class="text-end amount-cell <?php echo $amount > 0 ? 'positive-amount' : ($amount < 0 ? 'negative-amount' : 'neutral-amount'); ?>">
                                                    <?php echo number_format($amount, 2); ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                            
                                            <tr class="total-row">
                                                <td><strong>TOTAL OWNER'S EQUITY</strong></td>
                                                <td class="text-end amount-cell positive-amount"><strong><?php echo number_format($balance_sheet['equity']['total_equity'], 2); ?></strong></td>
                                            </tr>
                                            
                                            <tr class="total-row" style="background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%); border-top: 3px solid var(--success-color);">
                                                <td><strong>TOTAL LIABILITIES + OWNER'S EQUITY</strong></td>
                                                <td class="text-end amount-cell positive-amount"><strong><?php echo number_format($balance_sheet['liabilities']['total_liabilities'] + $balance_sheet['equity']['total_equity'], 2); ?></strong></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Financial Ratios -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="bi bi-calculator me-2"></i>Key Financial Ratios</h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-3">
                                        <div class="text-center p-3 bg-light rounded">
                                            <h5 class="text-primary mb-1">
                                                <?php 
                                                $current_ratio = $balance_sheet['liabilities']['total_current'] > 0 ? 
                                                    $balance_sheet['assets']['total_current'] / $balance_sheet['liabilities']['total_current'] : 0;
                                                echo number_format($current_ratio, 2);
                                                ?>
                                            </h5>
                                            <small class="text-muted">Current Ratio</small>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="text-center p-3 bg-light rounded">
                                            <h5 class="text-success mb-1">
                                                <?php 
                                                $debt_to_equity = $balance_sheet['equity']['total_equity'] > 0 ? 
                                                    $balance_sheet['liabilities']['total_liabilities'] / $balance_sheet['equity']['total_equity'] : 0;
                                                echo number_format($debt_to_equity, 2);
                                                ?>
                                            </h5>
                                            <small class="text-muted">Debt-to-Equity</small>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="text-center p-3 bg-light rounded">
                                            <h5 class="text-info mb-1">
                                                <?php 
                                                $asset_turnover = $balance_sheet['assets']['total_assets'] > 0 ? 
                                                    ($balance_sheet['assets']['total_current'] / $balance_sheet['assets']['total_assets']) * 100 : 0;
                                                echo number_format($asset_turnover, 1) . '%';
                                                ?>
                                            </h5>
                                            <small class="text-muted">Current Assets %</small>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="text-center p-3 bg-light rounded">
                                            <h5 class="text-warning mb-1">
                                                <?php 
                                                $equity_ratio = $balance_sheet['assets']['total_assets'] > 0 ? 
                                                    ($balance_sheet['equity']['total_equity'] / $balance_sheet['assets']['total_assets']) * 100 : 0;
                                                echo number_format($equity_ratio, 1) . '%';
                                                ?>
                                            </h5>
                                            <small class="text-muted">Equity Ratio</small>
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
            // Simple CSV export
            let csv = 'Account,Amount\n';
            
            // Assets
            csv += 'ASSETS,\n';
            csv += 'Current Assets,\n';
            <?php foreach ($balance_sheet['assets']['current_assets'] as $account => $amount): ?>
            csv += '<?php echo addslashes($account); ?>,<?php echo $amount; ?>\n';
            <?php endforeach; ?>
            csv += 'Total Current Assets,<?php echo $balance_sheet['assets']['total_current']; ?>\n';
            
            // Fixed Assets
            csv += 'Fixed Assets,\n';
            <?php foreach ($balance_sheet['assets']['fixed_assets'] as $account => $amount): ?>
            csv += '<?php echo addslashes($account); ?>,<?php echo $amount; ?>\n';
            <?php endforeach; ?>
            csv += 'Total Fixed Assets,<?php echo $balance_sheet['assets']['total_fixed']; ?>\n';
            csv += 'TOTAL ASSETS,<?php echo $balance_sheet['assets']['total_assets']; ?>\n';
            
            // Liabilities
            csv += 'LIABILITIES,\n';
            csv += 'Current Liabilities,\n';
            <?php foreach ($balance_sheet['liabilities']['current_liabilities'] as $account => $amount): ?>
            csv += '<?php echo addslashes($account); ?>,<?php echo $amount; ?>\n';
            <?php endforeach; ?>
            csv += 'Total Current Liabilities,<?php echo $balance_sheet['liabilities']['total_current']; ?>\n';
            
            csv += 'Long-term Liabilities,\n';
            <?php foreach ($balance_sheet['liabilities']['long_term_liabilities'] as $account => $amount): ?>
            csv += '<?php echo addslashes($account); ?>,<?php echo $amount; ?>\n';
            <?php endforeach; ?>
            csv += 'Total Long-term Liabilities,<?php echo $balance_sheet['liabilities']['total_long_term']; ?>\n';
            csv += 'TOTAL LIABILITIES,<?php echo $balance_sheet['liabilities']['total_liabilities']; ?>\n';
            
            // Equity
            csv += 'OWNERS EQUITY,\n';
            <?php foreach ($balance_sheet['equity']['items'] as $account => $amount): ?>
            csv += '<?php echo addslashes($account); ?>,<?php echo $amount; ?>\n';
            <?php endforeach; ?>
            csv += 'TOTAL OWNERS EQUITY,<?php echo $balance_sheet['equity']['total_equity']; ?>\n';
            
            // Download
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.setAttribute('hidden', '');
            a.setAttribute('href', url);
            a.setAttribute('download', 'balance-sheet-<?php echo $selected_date; ?>.csv');
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
        });
    </script>
</body>
</html>
