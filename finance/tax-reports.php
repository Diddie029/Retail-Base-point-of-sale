<?php
session_start();
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Get user information
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

// Check if user has permission to view tax reports
if (!hasPermission('view_finance', $permissions)) {
    header("Location: ../dashboard/dashboard.php");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get date range from URL parameters
$start_date = $_GET['start_date'] ?? date('Y-m-01'); // First day of current month
$end_date = $_GET['end_date'] ?? date('Y-m-d'); // Today
$category_id = !empty($_GET['category_id']) ? (int)$_GET['category_id'] : null;

// Get tax categories for filter
$stmt = $conn->query("SELECT id, name FROM tax_categories WHERE is_active = 1 ORDER BY name");
$tax_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Build tax report query
$where_conditions = ["s.sale_date BETWEEN ? AND ?"];
$params = [$start_date, $end_date];

if ($category_id) {
    $where_conditions[] = "st.tax_rate_id IN (SELECT id FROM tax_rates WHERE tax_category_id = ?)";
    $params[] = $category_id;
}

$sql = "
    SELECT 
        st.tax_category_name,
        st.tax_name,
        st.tax_rate,
        COUNT(DISTINCT st.sale_id) as sale_count,
        SUM(st.taxable_amount) as total_taxable_amount,
        SUM(st.tax_amount) as total_tax_amount,
        AVG(st.tax_rate) as avg_tax_rate
    FROM sale_taxes st
    JOIN sales s ON st.sale_id = s.id
    WHERE " . implode(' AND ', $where_conditions) . "
    GROUP BY st.tax_category_name, st.tax_name, st.tax_rate
    ORDER BY st.tax_category_name, st.tax_name
";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$tax_report = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate summary totals
$total_taxable_amount = array_sum(array_column($tax_report, 'total_taxable_amount'));
$total_tax_amount = array_sum(array_column($tax_report, 'total_tax_amount'));
$total_sales = array_sum(array_column($tax_report, 'sale_count'));

// Get daily tax summary
$daily_sql = "
    SELECT 
        DATE(s.sale_date) as sale_date,
        SUM(st.taxable_amount) as daily_taxable_amount,
        SUM(st.tax_amount) as daily_tax_amount,
        COUNT(DISTINCT s.id) as daily_sales
    FROM sale_taxes st
    JOIN sales s ON st.sale_id = s.id
    WHERE s.sale_date BETWEEN ? AND ?
    GROUP BY DATE(s.sale_date)
    ORDER BY sale_date
";

$stmt = $conn->prepare($daily_sql);
$stmt->execute([$start_date, $end_date]);
$daily_summary = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get top products by tax collected
$top_products_sql = "
    SELECT 
        p.name as product_name,
        p.sku,
        SUM(si.quantity) as total_quantity,
        SUM(si.total_price) as total_sales,
        SUM(st.tax_amount) as total_tax_collected
    FROM sale_items si
    JOIN sales s ON si.sale_id = s.id
    JOIN products p ON si.product_id = p.id
    LEFT JOIN sale_taxes st ON s.id = st.sale_id
    WHERE s.sale_date BETWEEN ? AND ?
    GROUP BY p.id, p.name, p.sku
    HAVING total_tax_collected > 0
    ORDER BY total_tax_collected DESC
    LIMIT 10
";

$stmt = $conn->prepare($top_products_sql);
$stmt->execute([$start_date, $end_date]);
$top_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tax Reports - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/tax-management.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }

        .summary-card {
            background: linear-gradient(135deg, var(--primary-color), #4f46e5);
            color: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .summary-card h3 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .summary-card p {
            opacity: 0.9;
            margin-bottom: 0;
        }

        .chart-container {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            margin-bottom: 1.5rem;
        }

        .report-table {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .report-table th {
            background: #f8fafc;
            border-bottom: 2px solid #e2e8f0;
            font-weight: 600;
            color: #374151;
        }

        .tax-rate-badge {
            background: var(--primary-color);
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-weight: 600;
            font-size: 0.875rem;
        }

        .amount-cell {
            text-align: right;
            font-weight: 600;
        }

        .filter-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        /* Enhanced Category Display Styles */
        .category-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
            height: 100%;
        }

        .category-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .category-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid #f1f5f9;
        }

        .category-name {
            font-size: 1.1rem;
            font-weight: 600;
            color: #1f2937;
            margin: 0;
        }

        .category-percentage {
            background: linear-gradient(135deg, var(--primary-color), #4f46e5);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
        }

        .category-stats {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .stat-item {
            text-align: center;
        }

        .stat-value {
            font-size: 1.25rem;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.75rem;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .category-rates {
            margin-bottom: 1rem;
        }

        .rate-badge-small {
            display: inline-block;
            background: #f3f4f6;
            color: #374151;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 500;
            margin-right: 0.25rem;
            margin-bottom: 0.25rem;
        }

        .category-progress {
            margin-top: auto;
        }

        .progress {
            background-color: #f1f5f9;
            border-radius: 3px;
        }

        .progress-bar {
            border-radius: 3px;
            transition: width 0.6s ease;
        }

        /* Enhanced Empty State Styles */
        .empty-state-container {
            text-align: center;
            padding: 3rem 2rem;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-radius: 12px;
            border: 2px dashed #cbd5e1;
        }

        .empty-state-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 1.5rem;
            background: linear-gradient(135deg, var(--primary-color), #4f46e5);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
        }

        .empty-state-icon i {
            font-size: 2rem;
            color: white;
        }

        .empty-state-title {
            color: #1f2937;
            font-weight: 600;
            margin-bottom: 1rem;
        }

        .empty-state-description {
            color: #6b7280;
            margin-bottom: 1.5rem;
            max-width: 500px;
            margin-left: auto;
            margin-right: auto;
        }

        .empty-state-list {
            text-align: left;
            max-width: 400px;
            margin: 0 auto 2rem;
            color: #6b7280;
        }

        .empty-state-list li {
            margin-bottom: 0.5rem;
            position: relative;
            padding-left: 1.5rem;
        }

        .empty-state-list li::before {
            content: "•";
            color: var(--primary-color);
            font-weight: bold;
            position: absolute;
            left: 0;
        }

        .empty-state-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }

        .empty-state-actions .btn {
            min-width: 160px;
        }

        /* Table Empty State */
        .table-empty-state {
            text-align: center;
            padding: 3rem 2rem;
            background: #f8fafc;
            border-radius: 8px;
            margin: 1rem;
        }

        .table-empty-state i {
            font-size: 2.5rem;
            color: #cbd5e1;
            margin-bottom: 1rem;
        }

        .table-empty-state h6 {
            color: #6b7280;
            margin-bottom: 0.5rem;
        }

        .table-empty-state p {
            color: #9ca3af;
            margin-bottom: 0;
        }

        /* Enhanced Error Notices */
        .alert-enhanced {
            border: none;
            border-radius: 12px;
            padding: 1.25rem 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .alert-enhanced .alert-icon {
            font-size: 1.5rem;
            margin-right: 0.75rem;
        }

        .alert-enhanced .alert-title {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .alert-enhanced .alert-message {
            margin-bottom: 0;
            line-height: 1.5;
        }

        .alert-success.alert-enhanced {
            background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);
            color: #065f46;
            border-left: 4px solid #10b981;
        }

        .alert-danger.alert-enhanced {
            background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
            color: #991b1b;
            border-left: 4px solid #ef4444;
        }

        .alert-warning.alert-enhanced {
            background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
            color: #92400e;
            border-left: 4px solid #f59e0b;
        }

        .alert-info.alert-enhanced {
            background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
            color: #1e40af;
            border-left: 4px solid #3b82f6;
        }

        /* Category Controls */
        .category-controls {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .category-controls .btn-group .btn {
            border-radius: 6px;
            font-size: 0.875rem;
            padding: 0.375rem 0.75rem;
        }

        .category-controls .btn.active {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
        }

        .category-controls .btn.sorted {
            background-color: #f8fafc;
            border-color: var(--primary-color);
            color: var(--primary-color);
        }

        /* List View Styles */
        .category-color-indicator {
            flex-shrink: 0;
        }

        .rate-badge-small {
            display: inline-block;
            background: #f3f4f6;
            color: #374151;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 500;
            margin-right: 0.25rem;
            margin-bottom: 0.25rem;
        }

        /* Enhanced Category Cards */
        .category-item {
            transition: all 0.3s ease;
        }

        .category-item:hover {
            transform: translateY(-2px);
        }

        .category-card {
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .category-progress {
            margin-top: auto;
        }

        /* Default Category Styling */
        .default-category {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border: 2px dashed #f59e0b;
        }

        .default-category .category-name {
            color: #92400e;
        }

        .default-category .category-percentage {
            background: linear-gradient(135deg, #f59e0b, #d97706);
        }

        .default-category .stat-value {
            color: #92400e;
        }

        .default-category .stat-label {
            color: #a16207;
        }

        .default-category-info {
            margin-top: 1rem;
        }

        .default-category-info .alert {
            padding: 0.5rem 0.75rem;
            font-size: 0.8rem;
            border-radius: 6px;
        }

        /* Chart Placeholder */
        .chart-placeholder {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 200px;
            text-align: center;
            background: #f8fafc;
            border: 2px dashed #e2e8f0;
            border-radius: 8px;
            margin: 1rem 0;
        }

        .placeholder-icon {
            font-size: 3rem;
            color: #cbd5e1;
            margin-bottom: 1rem;
        }

        .placeholder-text {
            color: #6b7280;
            font-weight: 500;
            margin-bottom: 0.5rem;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .category-stats {
                grid-template-columns: 1fr;
                gap: 0.75rem;
            }
            
            .category-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }

            .empty-state-actions {
                flex-direction: column;
                align-items: center;
            }

            .empty-state-actions .btn {
                width: 100%;
                max-width: 280px;
            }

            .category-controls {
                flex-direction: column;
                gap: 0.5rem;
                align-items: stretch;
            }

            .category-controls .btn-group {
                justify-content: center;
            }
        }

        @media (max-width: 576px) {
            .category-controls .btn-group .btn {
                font-size: 0.75rem;
                padding: 0.25rem 0.5rem;
            }
        }
    </style>
</head>
<body>
    <?php include '../include/navmenu.php'; ?>

    <div class="main-content">
        <div class="container-fluid">
            <!-- Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h3 mb-0">Tax Reports</h1>
                    <p class="text-muted">Tax collection and analysis reports</p>
                </div>
                <div>
                    <button class="btn btn-outline-primary" onclick="exportReport()">
                        <i class="bi bi-download me-2"></i>Export Report
                    </button>
                </div>
            </div>

            <!-- Filters -->
            <div class="filter-card">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label for="start_date" class="form-label">Start Date</label>
                        <input type="date" class="form-control" id="start_date" name="start_date" 
                               value="<?php echo htmlspecialchars($start_date); ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="end_date" class="form-label">End Date</label>
                        <input type="date" class="form-control" id="end_date" name="end_date" 
                               value="<?php echo htmlspecialchars($end_date); ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="category_id" class="form-label">Tax Category</label>
                        <select class="form-select" id="category_id" name="category_id">
                            <option value="">All Categories</option>
                            <?php foreach ($tax_categories as $category): ?>
                            <option value="<?php echo $category['id']; ?>" 
                                    <?php echo ($category_id == $category['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($category['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">&nbsp;</label>
                        <div>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-search me-2"></i>Generate Report
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Summary Cards -->
            <div class="row">
                <div class="col-md-3">
                    <div class="summary-card">
                        <h3><?php echo number_format($total_tax_amount, 2); ?></h3>
                        <p>Total Tax Collected</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="summary-card">
                        <h3><?php echo number_format($total_taxable_amount, 2); ?></h3>
                        <p>Total Taxable Amount</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="summary-card">
                        <h3><?php echo $total_sales; ?></h3>
                        <p>Total Sales</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="summary-card">
                        <h3><?php echo $total_taxable_amount > 0 ? number_format(($total_tax_amount / $total_taxable_amount) * 100, 2) : '0.00'; ?>%</h3>
                        <p>Average Tax Rate</p>
                    </div>
                </div>
            </div>

            <!-- Enhanced Tax by Category Display - Moved Up -->
            <div class="row mt-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-pie-chart me-2"></i>Tax Collection by Category
                                </h5>
                                <div class="category-controls">
                                    <div class="btn-group" role="group">
                                        <button type="button" class="btn btn-outline-secondary btn-sm" id="sortByTax" title="Sort by Tax Collected">
                                            <i class="bi bi-arrow-down-up me-1"></i>Tax
                                        </button>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" id="sortBySales" title="Sort by Sales Count">
                                            <i class="bi bi-arrow-down-up me-1"></i>Sales
                                        </button>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" id="sortByPercentage" title="Sort by Percentage">
                                            <i class="bi bi-arrow-down-up me-1"></i>%
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php 
                            // Create default category from admin settings if no tax data exists
                            $default_category = null;
                            if (empty($tax_report) && !empty($settings['tax_rate']) && floatval($settings['tax_rate']) > 0) {
                                $default_category = [
                                    'name' => $settings['tax_name'] ?? 'Default Tax',
                                    'rate' => floatval($settings['tax_rate']) / 100, // Convert percentage to decimal
                                    'total_tax' => 0,
                                    'total_taxable' => 0,
                                    'sale_count' => 0,
                                    'is_default' => true
                                ];
                            }
                            
                            if (empty($tax_report) && !$default_category): ?>
                            <div class="empty-state-container">
                                <div class="empty-state-icon">
                                    <i class="bi bi-graph-up"></i>
                                </div>
                                <h4 class="empty-state-title">No Tax Data Found</h4>
                                <p class="empty-state-description">
                                    No tax transactions were recorded for the selected period. 
                                    This could be because:
                                </p>
                                <ul class="empty-state-list">
                                    <li>No sales were made during this period</li>
                                    <li>Tax categories haven't been configured yet</li>
                                    <li>Products don't have tax rates assigned</li>
                                    <li>All sales were tax-exempt</li>
                                </ul>
                                <div class="empty-state-actions">
                                    <a href="../admin/tax_management.php" class="btn btn-primary">
                                        <i class="bi bi-gear me-2"></i>Configure Tax Settings
                                    </a>
                                    <a href="../pos/sale.php" class="btn btn-outline-primary">
                                        <i class="bi bi-cart-plus me-2"></i>Make a Sale
                                    </a>
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="row">
                                <?php 
                                $category_totals = [];
                                
                                // Add default category if no tax data exists
                                if ($default_category) {
                                    $category_totals[$default_category['name']] = [
                                        'total_tax' => $default_category['total_tax'],
                                        'total_taxable' => $default_category['total_taxable'],
                                        'sale_count' => $default_category['sale_count'],
                                        'rates' => [['tax_rate' => $default_category['rate'], 'tax_name' => $default_category['name']]],
                                        'is_default' => true
                                    ];
                                }
                                
                                // Process actual tax report data
                                foreach ($tax_report as $row) {
                                    $category = $row['tax_category_name'];
                                    if (!isset($category_totals[$category])) {
                                        $category_totals[$category] = [
                                            'total_tax' => 0,
                                            'total_taxable' => 0,
                                            'sale_count' => 0,
                                            'rates' => [],
                                            'is_default' => false
                                        ];
                                    }
                                    $category_totals[$category]['total_tax'] += $row['total_tax_amount'];
                                    $category_totals[$category]['total_taxable'] += $row['total_taxable_amount'];
                                    $category_totals[$category]['sale_count'] += $row['sale_count'];
                                    $category_totals[$category]['rates'][] = $row;
                                }
                                
                                // Sort categories by total tax collected
                                uasort($category_totals, function($a, $b) {
                                    return $b['total_tax'] <=> $a['total_tax'];
                                });
                                
                                $colors = ['#6366f1', '#8b5cf6', '#ec4899', '#f59e0b', '#10b981', '#ef4444', '#06b6d4', '#84cc16', '#f97316'];
                                $color_index = 0;
                                ?>
                                
                                <?php foreach ($category_totals as $category_name => $data): ?>
                                <div class="col-md-6 col-lg-4 mb-4 category-item" 
                                     data-tax="<?php echo $data['total_tax']; ?>" 
                                     data-sales="<?php echo $data['sale_count']; ?>" 
                                     data-percentage="<?php echo $total_tax_amount > 0 ? ($data['total_tax'] / $total_tax_amount) * 100 : 0; ?>">
                                    <div class="category-card <?php echo isset($data['is_default']) && $data['is_default'] ? 'default-category' : ''; ?>" 
                                         style="border-left: 4px solid <?php echo $colors[$color_index % count($colors)]; ?>">
                                        <div class="category-header">
                                            <h6 class="category-name">
                                                <?php echo htmlspecialchars($category_name); ?>
                                                <?php if (isset($data['is_default']) && $data['is_default']): ?>
                                                <small class="badge bg-warning text-dark ms-2">Default</small>
                                                <?php endif; ?>
                                            </h6>
                                            <span class="category-percentage">
                                                <?php echo $total_tax_amount > 0 ? number_format(($data['total_tax'] / $total_tax_amount) * 100, 1) : '0.0'; ?>%
                                            </span>
                                        </div>
                                        
                                        <div class="category-stats">
                                            <div class="stat-item">
                                                <div class="stat-value"><?php echo $settings['currency_symbol']; ?><?php echo number_format($data['total_tax'], 2); ?></div>
                                                <div class="stat-label">Tax Collected</div>
                                            </div>
                                            <div class="stat-item">
                                                <div class="stat-value"><?php echo $settings['currency_symbol']; ?><?php echo number_format($data['total_taxable'], 2); ?></div>
                                                <div class="stat-label">Taxable Amount</div>
                                            </div>
                                            <div class="stat-item">
                                                <div class="stat-value"><?php echo number_format($data['sale_count']); ?></div>
                                                <div class="stat-label">Sales</div>
                                            </div>
                                        </div>
                                        
                                        <div class="category-rates">
                                            <small class="text-muted">Tax Rates:</small>
                                            <?php foreach ($data['rates'] as $rate): ?>
                                            <span class="rate-badge-small">
                                                <?php echo number_format($rate['tax_rate'] * 100, 2); ?>%
                                            </span>
                                            <?php endforeach; ?>
                                        </div>
                                        
                                        <?php if (isset($data['is_default']) && $data['is_default']): ?>
                                        <div class="default-category-info">
                                            <div class="alert alert-info alert-sm mb-0">
                                                <i class="bi bi-info-circle me-2"></i>
                                                <small>This is your default tax category from admin settings. No sales data available yet.</small>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <div class="category-progress">
                                            <div class="progress" style="height: 6px;">
                                                <div class="progress-bar" 
                                                     style="width: <?php echo $total_tax_amount > 0 ? ($data['total_tax'] / $total_tax_amount) * 100 : 0; ?>%; 
                                                            background-color: <?php echo $colors[$color_index % count($colors)]; ?>;">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php $color_index++; ?>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts -->
            <?php if (!empty($tax_report) || $default_category): ?>
            <div class="row mt-4">
                <div class="col-md-6">
                    <div class="chart-container">
                        <h5 class="mb-3">Daily Tax Collection</h5>
                        <canvas id="dailyTaxChart"></canvas>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="chart-container">
                        <h5 class="mb-3">Tax by Category</h5>
                        <?php if (!empty($tax_report)): ?>
                        <canvas id="categoryTaxChart"></canvas>
                        <?php else: ?>
                        <div class="chart-placeholder">
                            <div class="placeholder-icon">
                                <i class="bi bi-pie-chart"></i>
                            </div>
                            <p class="placeholder-text">Chart will appear when tax data is available</p>
                            <small class="text-muted">Make some sales to see the tax distribution chart</small>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Tax Report Table -->
            <div class="report-table">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Tax Category</th>
                                <th>Tax Name</th>
                                <th>Rate</th>
                                <th>Sales Count</th>
                                <th>Taxable Amount</th>
                                <th>Tax Collected</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($tax_report)): ?>
                            <tr>
                                <td colspan="6" class="table-empty-state">
                                    <i class="bi bi-graph-up"></i>
                                    <h6>No Tax Data Found</h6>
                                    <p>No tax transactions were recorded for the selected period</p>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($tax_report as $row): ?>
                            <tr>
                                <td>
                                    <span class="badge bg-secondary"><?php echo htmlspecialchars($row['tax_category_name']); ?></span>
                                </td>
                                <td><?php echo htmlspecialchars($row['tax_name']); ?></td>
                                <td>
                                    <span class="tax-rate-badge"><?php echo number_format($row['tax_rate'] * 100, 2); ?>%</span>
                                </td>
                                <td><?php echo number_format($row['sale_count']); ?></td>
                                <td class="amount-cell"><?php echo $settings['currency_symbol']; ?><?php echo number_format($row['total_taxable_amount'], 2); ?></td>
                                <td class="amount-cell"><?php echo $settings['currency_symbol']; ?><?php echo number_format($row['total_tax_amount'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Top Products by Tax -->
            <?php if (!empty($top_products)): ?>
            <div class="report-table mt-4">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th colspan="5" class="bg-light">
                                    <h6 class="mb-0">Top Products by Tax Collected</h6>
                                </th>
                            </tr>
                            <tr>
                                <th>Product</th>
                                <th>SKU</th>
                                <th>Quantity Sold</th>
                                <th>Total Sales</th>
                                <th>Tax Collected</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($top_products as $product): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($product['product_name']); ?></td>
                                <td><?php echo htmlspecialchars($product['sku']); ?></td>
                                <td><?php echo number_format($product['total_quantity']); ?></td>
                                <td class="amount-cell"><?php echo $settings['currency_symbol']; ?><?php echo number_format($product['total_sales'], 2); ?></td>
                                <td class="amount-cell"><?php echo $settings['currency_symbol']; ?><?php echo number_format($product['total_tax_collected'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Daily Tax Chart
        const dailyTaxCtx = document.getElementById('dailyTaxChart').getContext('2d');
        new Chart(dailyTaxCtx, {
            type: 'line',
            data: {
                labels: [<?php echo implode(',', array_map(function($day) { return "'" . date('M j', strtotime($day['sale_date'])) . "'"; }, $daily_summary)); ?>],
                datasets: [{
                    label: 'Tax Collected',
                    data: [<?php echo implode(',', array_column($daily_summary, 'daily_tax_amount')); ?>],
                    borderColor: '<?php echo $settings['theme_color'] ?? '#6366f1'; ?>',
                    backgroundColor: '<?php echo $settings['theme_color'] ?? '#6366f1'; ?>20',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '<?php echo $settings['currency_symbol']; ?>' + value.toFixed(2);
                            }
                        }
                    }
                }
            }
        });

        // Category Tax Chart
        const categoryTaxCtx = document.getElementById('categoryTaxChart').getContext('2d');
        new Chart(categoryTaxCtx, {
            type: 'doughnut',
            data: {
                labels: [<?php echo implode(',', array_map(function($row) { return "'" . $row['tax_category_name'] . "'"; }, $tax_report)); ?>],
                datasets: [{
                    data: [<?php echo implode(',', array_column($tax_report, 'total_tax_amount')); ?>],
                    backgroundColor: [
                        '#6366f1', '#8b5cf6', '#ec4899', '#f59e0b', '#10b981',
                        '#ef4444', '#06b6d4', '#84cc16', '#f97316', '#6366f1'
                    ]
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });

        function exportReport() {
            // Create a simple CSV export
            const table = document.querySelector('.report-table table');
            const rows = Array.from(table.querySelectorAll('tr'));
            const csvContent = rows.map(row => 
                Array.from(row.querySelectorAll('th, td')).map(cell => 
                    '"' + cell.textContent.replace(/"/g, '""') + '"'
                ).join(',')
            ).join('\n');
            
            const blob = new Blob([csvContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'tax_report_<?php echo $start_date; ?>_to_<?php echo $end_date; ?>.csv';
            a.click();
            window.URL.revokeObjectURL(url);
        }

        // Category Sort Controls
        document.addEventListener('DOMContentLoaded', function() {
            const sortByTaxBtn = document.getElementById('sortByTax');
            const sortBySalesBtn = document.getElementById('sortBySales');
            const sortByPercentageBtn = document.getElementById('sortByPercentage');

            let currentSort = 'tax';
            let sortDirection = 'desc';

            // Sorting functions
            function sortCategories(sortBy) {
                const items = Array.from(document.querySelectorAll('.category-item'));
                
                items.sort((a, b) => {
                    let aValue, bValue;
                    
                    switch(sortBy) {
                        case 'tax':
                            aValue = parseFloat(a.dataset.tax);
                            bValue = parseFloat(b.dataset.tax);
                            break;
                        case 'sales':
                            aValue = parseInt(a.dataset.sales);
                            bValue = parseInt(b.dataset.sales);
                            break;
                        case 'percentage':
                            aValue = parseFloat(a.dataset.percentage);
                            bValue = parseFloat(b.dataset.percentage);
                            break;
                    }
                    
                    if (sortDirection === 'desc') {
                        return bValue - aValue;
                    } else {
                        return aValue - bValue;
                    }
                });
                
                // Re-append sorted items
                const container = document.querySelector('.row');
                items.forEach(item => container.appendChild(item));
            }

            // Sort button handlers
            [sortByTaxBtn, sortBySalesBtn, sortByPercentageBtn].forEach(btn => {
                btn.addEventListener('click', function() {
                    // Remove active class from all sort buttons
                    [sortByTaxBtn, sortBySalesBtn, sortByPercentageBtn].forEach(b => b.classList.remove('active'));
                    
                    // Add active class to clicked button
                    this.classList.add('active');
                    
                    // Determine sort type
                    if (this === sortByTaxBtn) {
                        currentSort = 'tax';
                    } else if (this === sortBySalesBtn) {
                        currentSort = 'sales';
                    } else if (this === sortByPercentageBtn) {
                        currentSort = 'percentage';
                    }
                    
                    // Toggle sort direction if same button clicked
                    if (this.classList.contains('sorted')) {
                        sortDirection = sortDirection === 'desc' ? 'asc' : 'desc';
                    } else {
                        sortDirection = 'desc';
                    }
                    
                    // Add sorted class and update icon
                    this.classList.add('sorted');
                    const icon = this.querySelector('i');
                    icon.className = sortDirection === 'desc' ? 'bi bi-arrow-down me-1' : 'bi bi-arrow-up me-1';
                    
                    // Sort the categories
                    sortCategories(currentSort);
                });
            });

            // Initialize with tax sort
            sortByTaxBtn.click();
        });
    </script>
</body>
</html>
