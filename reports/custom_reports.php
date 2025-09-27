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

// Check if user has permission to view reports
$hasAccess = isAdmin($role_name) || 
             hasPermission('view_analytics', $permissions) || 
             hasPermission('manage_sales', $permissions) || 
             hasPermission('view_finance', $permissions);

if (!$hasAccess) {
    header('Location: ../dashboard/dashboard.php');
    exit();
}

// Get report parameters
$report_type = $_GET['report_type'] ?? 'daily_comparison';
$period1 = $_GET['period1'] ?? date('Y-m-d', strtotime('-1 day'));
$period2 = $_GET['period2'] ?? date('Y-m-d');
$category_id = $_GET['category_id'] ?? '';
$product_id = $_GET['product_id'] ?? '';

// Set default values based on report type
if ($report_type === 'daily_comparison' && !isset($_GET['period1'])) {
    $period1 = date('Y-m-d', strtotime('-1 day'));
    $period2 = date('Y-m-d');
} elseif ($report_type === 'weekly_comparison' && !isset($_GET['period1'])) {
    $period1 = date('Y-W', strtotime('-1 week'));
    $period2 = date('Y-W');
} elseif ($report_type === 'weekly_comparison' && !isset($_GET['period2'])) {
    $period2 = date('Y-W');
} elseif ($report_type === 'monthly_comparison' && !isset($_GET['period1'])) {
    $period1 = date('Y-m', strtotime('-1 month'));
    $period2 = date('Y-m');
} elseif ($report_type === 'yearly_comparison' && !isset($_GET['period1'])) {
    $period1 = date('Y', strtotime('-1 year'));
    $period2 = date('Y');
}

// Get categories for filters
$stmt = $conn->query("SELECT id, name FROM categories ORDER BY name");
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get products for filters
$stmt = $conn->query("SELECT id, name, sku FROM products ORDER BY name");
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Initialize report data
$report_data = [];
$comparison_data = [];



// Generate report data based on type
if ($report_type === 'monthly_comparison') {
    // Monthly Sales Comparison
    $query1 = "
        SELECT 
            DATE_FORMAT(created_at, '%Y-%m') as period,
            COUNT(*) as total_sales,
            SUM(total_amount) as total_revenue,
            AVG(total_amount) as avg_sale_amount,
            COUNT(DISTINCT customer_id) as unique_customers
        FROM sales 
        WHERE DATE_FORMAT(created_at, '%Y-%m') = :period1
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ";
    
    $query2 = "
        SELECT 
            DATE_FORMAT(created_at, '%Y-%m') as period,
            COUNT(*) as total_sales,
            SUM(total_amount) as total_revenue,
            AVG(total_amount) as avg_sale_amount,
            COUNT(DISTINCT customer_id) as unique_customers
        FROM sales 
        WHERE DATE_FORMAT(created_at, '%Y-%m') = :period2
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ";
    
    $stmt1 = $conn->prepare($query1);
    $stmt1->bindParam(':period1', $period1);
    $stmt1->execute();
    $report_data = $stmt1->fetch(PDO::FETCH_ASSOC) ?: [];
    
    $stmt2 = $conn->prepare($query2);
    $stmt2->bindParam(':period2', $period2);
    $stmt2->execute();
    $comparison_data = $stmt2->fetch(PDO::FETCH_ASSOC) ?: [];
    
} elseif ($report_type === 'weekly_comparison') {
    // Weekly Sales Comparison
    // Convert week format from "2025-W38" to date range
    // Parse year and week number from format like "2025-W38"
    preg_match('/(\d{4})-W(\d{1,2})/', $period1, $matches1);
    preg_match('/(\d{4})-W(\d{1,2})/', $period2, $matches2);
    
    if ($matches1 && $matches2) {
        $year1 = $matches1[1];
        $week1 = $matches1[2];
        $year2 = $matches2[1];
        $week2 = $matches2[2];
        
        // Calculate Monday of the week
        $week1_start = date('Y-m-d', strtotime($year1 . 'W' . str_pad($week1, 2, '0', STR_PAD_LEFT)));
        $week1_end = date('Y-m-d', strtotime($week1_start . ' +6 days'));
        $week2_start = date('Y-m-d', strtotime($year2 . 'W' . str_pad($week2, 2, '0', STR_PAD_LEFT)));
        $week2_end = date('Y-m-d', strtotime($week2_start . ' +6 days'));
    } else {
        // Fallback to current week if parsing fails
        $week1_start = date('Y-m-d', strtotime('monday this week'));
        $week1_end = date('Y-m-d', strtotime('sunday this week'));
        $week2_start = date('Y-m-d', strtotime('monday next week'));
        $week2_end = date('Y-m-d', strtotime('sunday next week'));
    }
    
    $query1 = "
        SELECT 
            DATE(created_at) as period,
            COUNT(*) as total_sales,
            SUM(total_amount) as total_revenue,
            AVG(total_amount) as avg_sale_amount,
            COUNT(DISTINCT customer_id) as unique_customers
        FROM sales 
        WHERE DATE(created_at) BETWEEN :start_date1 AND :end_date1
        GROUP BY DATE(created_at)
    ";
    
    $query2 = "
        SELECT 
            DATE(created_at) as period,
            COUNT(*) as total_sales,
            SUM(total_amount) as total_revenue,
            AVG(total_amount) as avg_sale_amount,
            COUNT(DISTINCT customer_id) as unique_customers
        FROM sales 
        WHERE DATE(created_at) BETWEEN :start_date2 AND :end_date2
        GROUP BY DATE(created_at)
    ";
    
    $stmt1 = $conn->prepare($query1);
    $stmt1->bindParam(':start_date1', $week1_start);
    $stmt1->bindParam(':end_date1', $week1_end);
    $stmt1->execute();
    $week1_results = $stmt1->fetchAll(PDO::FETCH_ASSOC);
    
    // Aggregate the results for the week
    $report_data = [
        'total_sales' => array_sum(array_column($week1_results, 'total_sales')),
        'total_revenue' => array_sum(array_column($week1_results, 'total_revenue')),
        'avg_sale_amount' => $week1_results ? array_sum(array_column($week1_results, 'avg_sale_amount')) / count($week1_results) : 0,
        'unique_customers' => count(array_unique(array_column($week1_results, 'unique_customers')))
    ];
    
    $stmt2 = $conn->prepare($query2);
    $stmt2->bindParam(':start_date2', $week2_start);
    $stmt2->bindParam(':end_date2', $week2_end);
    $stmt2->execute();
    $week2_results = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    
    // Aggregate the results for the week
    $comparison_data = [
        'total_sales' => array_sum(array_column($week2_results, 'total_sales')),
        'total_revenue' => array_sum(array_column($week2_results, 'total_revenue')),
        'avg_sale_amount' => $week2_results ? array_sum(array_column($week2_results, 'avg_sale_amount')) / count($week2_results) : 0,
        'unique_customers' => count(array_unique(array_column($week2_results, 'unique_customers')))
    ];
    
    
} elseif ($report_type === 'product_comparison') {
    // Product Performance Comparison
    $query = "
        SELECT 
            p.id,
            p.name,
            p.sku,
            COUNT(si.id) as total_sales,
            SUM(si.quantity) as total_quantity,
            SUM(si.total_price) as total_revenue,
            AVG(si.unit_price) as avg_price,
            SUM(si.quantity * COALESCE(p.cost_price, 0)) as total_cost,
            SUM(si.total_price) - SUM(si.quantity * COALESCE(p.cost_price, 0)) as total_profit
        FROM products p
        LEFT JOIN sale_items si ON p.id = si.product_id
        LEFT JOIN sales s ON si.sale_id = s.id
        WHERE s.created_at >= DATE_SUB(NOW(), INTERVAL 2 MONTH)
        " . ($product_id ? "AND p.id = :product_id" : "") . "
        GROUP BY p.id, p.name, p.sku
        HAVING total_sales > 0
        ORDER BY total_revenue DESC
        LIMIT 20
    ";
    
    $stmt = $conn->prepare($query);
    if ($product_id) {
        $stmt->bindParam(':product_id', $product_id);
    }
    $stmt->execute();
    $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} elseif ($report_type === 'yearly_comparison') {
    // Yearly Sales Comparison
    $query1 = "
        SELECT 
            YEAR(created_at) as period,
            COUNT(*) as total_sales,
            SUM(total_amount) as total_revenue,
            AVG(total_amount) as avg_sale_amount,
            COUNT(DISTINCT customer_id) as unique_customers
        FROM sales 
        WHERE YEAR(created_at) = :period1
        GROUP BY YEAR(created_at)
    ";
    
    $query2 = "
        SELECT 
            YEAR(created_at) as period,
            COUNT(*) as total_sales,
            SUM(total_amount) as total_revenue,
            AVG(total_amount) as avg_sale_amount,
            COUNT(DISTINCT customer_id) as unique_customers
        FROM sales 
        WHERE YEAR(created_at) = :period2
        GROUP BY YEAR(created_at)
    ";
    
    $stmt1 = $conn->prepare($query1);
    $stmt1->bindParam(':period1', $period1);
    $stmt1->execute();
    $report_data = $stmt1->fetch(PDO::FETCH_ASSOC) ?: [];
    
    $stmt2 = $conn->prepare($query2);
    $stmt2->bindParam(':period2', $period2);
    $stmt2->execute();
    $comparison_data = $stmt2->fetch(PDO::FETCH_ASSOC) ?: [];
    
} elseif ($report_type === 'daily_comparison') {
    // Daily Sales Comparison
    $query1 = "
        SELECT 
            DATE(created_at) as period,
            COUNT(*) as total_sales,
            SUM(total_amount) as total_revenue,
            AVG(total_amount) as avg_sale_amount,
            COUNT(DISTINCT customer_id) as unique_customers
        FROM sales 
        WHERE DATE(created_at) = :period1
        GROUP BY DATE(created_at)
    ";
    
    $query2 = "
        SELECT 
            DATE(created_at) as period,
            COUNT(*) as total_sales,
            SUM(total_amount) as total_revenue,
            AVG(total_amount) as avg_sale_amount,
            COUNT(DISTINCT customer_id) as unique_customers
        FROM sales 
        WHERE DATE(created_at) = :period2
        GROUP BY DATE(created_at)
    ";
    
    $stmt1 = $conn->prepare($query1);
    $stmt1->bindParam(':period1', $period1);
    $stmt1->execute();
    $report_data = $stmt1->fetch(PDO::FETCH_ASSOC) ?: [];
    
    $stmt2 = $conn->prepare($query2);
    $stmt2->bindParam(':period2', $period2);
    $stmt2->execute();
    $comparison_data = $stmt2->fetch(PDO::FETCH_ASSOC) ?: [];
    
} elseif ($report_type === 'category_comparison') {
    // Category Performance Comparison
    $query = "
        SELECT 
            c.id,
            c.name as category_name,
            COUNT(DISTINCT s.id) as total_sales,
            SUM(si.quantity) as total_quantity,
            SUM(si.total_price) as total_revenue,
            AVG(si.unit_price) as avg_price,
            SUM(si.quantity * COALESCE(p.cost_price, 0)) as total_cost,
            SUM(si.total_price) - SUM(si.quantity * COALESCE(p.cost_price, 0)) as total_profit
        FROM categories c
        LEFT JOIN products p ON c.id = p.category_id
        LEFT JOIN sale_items si ON p.id = si.product_id
        LEFT JOIN sales s ON si.sale_id = s.id
        WHERE s.created_at >= DATE_SUB(NOW(), INTERVAL 2 MONTH)
        " . ($category_id ? "AND c.id = :category_id" : "") . "
        GROUP BY c.id, c.name
        HAVING total_sales > 0
        ORDER BY total_revenue DESC
    ";
    
    $stmt = $conn->prepare($query);
    if ($category_id) {
        $stmt->bindParam(':category_id', $category_id);
    }
    $stmt->execute();
    $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} elseif ($report_type === 'product_monthly_trends') {
    // Product Sales by Month
    $year_filter = $_GET['year_filter'] ?? date('Y');
    $query = "
        SELECT 
            p.id,
            p.name,
            p.sku,
            MONTH(s.created_at) as month,
            MONTHNAME(s.created_at) as month_name,
            COUNT(si.id) as total_sales,
            SUM(si.quantity) as total_quantity,
            SUM(si.total_price) as total_revenue,
            AVG(si.unit_price) as avg_price
        FROM products p
        LEFT JOIN sale_items si ON p.id = si.product_id
        LEFT JOIN sales s ON si.sale_id = s.id
        WHERE YEAR(s.created_at) = :year_filter
        " . ($product_id ? "AND p.id = :product_id" : "") . "
        GROUP BY p.id, p.name, p.sku, MONTH(s.created_at), MONTHNAME(s.created_at)
        HAVING total_sales > 0
        ORDER BY p.name, MONTH(s.created_at)
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':year_filter', $year_filter);
    if ($product_id) {
        $stmt->bindParam(':product_id', $product_id);
    }
    $stmt->execute();
    $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} elseif ($report_type === 'category_vs_category') {
    // Category vs Category Comparison
    $category2_id = $_GET['category2_id'] ?? '';
    
    if ($category_id && $category2_id) {
        $query = "
            SELECT 
                c.id,
                c.name as category_name,
                COALESCE(COUNT(DISTINCT s.id), 0) as total_sales,
                COALESCE(SUM(si.quantity), 0) as total_quantity,
                COALESCE(SUM(si.total_price), 0) as total_revenue,
                COALESCE(AVG(si.unit_price), 0) as avg_price,
                COALESCE(SUM(si.quantity * COALESCE(p.cost_price, 0)), 0) as total_cost,
                COALESCE(SUM(si.total_price), 0) - COALESCE(SUM(si.quantity * COALESCE(p.cost_price, 0)), 0) as total_profit
            FROM categories c
            LEFT JOIN products p ON c.id = p.category_id
            LEFT JOIN sale_items si ON p.id = si.product_id
            LEFT JOIN sales s ON si.sale_id = s.id AND s.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
            WHERE c.id IN (:category_id, :category2_id)
            GROUP BY c.id, c.name
            ORDER BY c.name
        ";
        
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':category_id', $category_id);
        $stmt->bindParam(':category2_id', $category2_id);
        $stmt->execute();
        $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $report_data = [];
    }
    
} elseif ($report_type === 'category_monthly_trends') {
    // Category Sales by Month
    $year_filter = $_GET['year_filter'] ?? date('Y');
    $query = "
        SELECT 
            c.id,
            c.name as category_name,
            MONTH(s.created_at) as month,
            MONTHNAME(s.created_at) as month_name,
            COUNT(DISTINCT s.id) as total_sales,
            SUM(si.quantity) as total_quantity,
            SUM(si.total_price) as total_revenue,
            AVG(si.unit_price) as avg_price
        FROM categories c
        LEFT JOIN products p ON c.id = p.category_id
        LEFT JOIN sale_items si ON p.id = si.product_id
        LEFT JOIN sales s ON si.sale_id = s.id
        WHERE YEAR(s.created_at) = :year_filter
        " . ($category_id ? "AND c.id = :category_id" : "") . "
        GROUP BY c.id, c.name, MONTH(s.created_at), MONTHNAME(s.created_at)
        HAVING total_sales > 0
        ORDER BY c.name, MONTH(s.created_at)
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':year_filter', $year_filter);
    if ($category_id) {
        $stmt->bindParam(':category_id', $category_id);
    }
    $stmt->execute();
    $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} elseif ($report_type === 'product_category_analysis') {
    // Product vs Category Analysis
    $query = "
        SELECT 
            p.id,
            p.name as product_name,
            p.sku,
            c.name as category_name,
            COUNT(si.id) as total_sales,
            SUM(si.quantity) as total_quantity,
            SUM(si.total_price) as total_revenue,
            AVG(si.unit_price) as avg_price,
            SUM(si.quantity * COALESCE(p.cost_price, 0)) as total_cost,
            SUM(si.total_price) - SUM(si.quantity * COALESCE(p.cost_price, 0)) as total_profit
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN sale_items si ON p.id = si.product_id
        LEFT JOIN sales s ON si.sale_id = s.id
        WHERE s.created_at >= DATE_SUB(NOW(), INTERVAL 3 MONTH)
        " . ($product_id ? "AND p.id = :product_id" : "") . "
        " . ($category_id ? "AND c.id = :category_id" : "") . "
        GROUP BY p.id, p.name, p.sku, c.id, c.name
        HAVING total_sales > 0
        ORDER BY total_revenue DESC
        LIMIT 50
    ";
    
    $stmt = $conn->prepare($query);
    if ($product_id) {
        $stmt->bindParam(':product_id', $product_id);
    }
    if ($category_id) {
        $stmt->bindParam(':category_id', $category_id);
    }
    $stmt->execute();
    $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} elseif ($report_type === 'supplier_comparison') {
    // Supplier Performance Comparison
    $query = "
        SELECT 
            s.id,
            s.name as supplier_name,
            COUNT(DISTINCT io.id) as total_orders,
            COUNT(DISTINCT CASE WHEN io.status = 'received' THEN io.id END) as completed_orders,
            AVG(CASE WHEN io.status = 'received' THEN DATEDIFF(io.updated_at, io.created_at) END) as avg_delivery_days,
            SUM(CASE WHEN io.status = 'received' THEN ioi.received_quantity * COALESCE(ioi.cost_price, 0) END) as total_order_value,
            COUNT(DISTINCT p.id) as products_supplied
        FROM suppliers s
        LEFT JOIN inventory_orders io ON s.id = io.supplier_id
        LEFT JOIN inventory_order_items ioi ON io.id = ioi.order_id
        LEFT JOIN products p ON s.id = p.supplier_id
        GROUP BY s.id, s.name
        HAVING total_orders > 0
        ORDER BY total_order_value DESC
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} elseif ($report_type === 'customer_analysis') {
    // Customer Analysis
    $query = "
        SELECT 
            c.id,
            c.name as customer_name,
            c.phone,
            c.email,
            COUNT(s.id) as total_orders,
            SUM(s.total_amount) as total_spent,
            AVG(s.total_amount) as avg_order_value,
            MAX(s.created_at) as last_order_date,
            MIN(s.created_at) as first_order_date
        FROM customers c
        LEFT JOIN sales s ON c.id = s.customer_id
        WHERE s.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY c.id, c.name, c.phone, c.email
        HAVING total_orders > 0
        ORDER BY total_spent DESC
        LIMIT 50
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Custom Reports - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
        
        .report-header {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
        }
        
        .filter-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .comparison-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            padding: 1.5rem;
            margin-bottom: 1rem;
        }
        
        .metric-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            margin-bottom: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .metric-card.comparison {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
        
        .metric-value {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .metric-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }
        
        .metric-card.comparison {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
        
        /* Select2 Custom Styling */
        .select2-container--default .select2-selection--single {
            height: 38px;
            border: 1px solid #ced4da;
            border-radius: 0.375rem;
        }
        
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 36px;
            padding-left: 12px;
        }
        
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 36px;
        }
        
        .select2-dropdown {
            border: 1px solid #ced4da;
            border-radius: 0.375rem;
        }
        
        .select2-container--default .select2-results__option--highlighted[aria-selected] {
            background-color: #0d6efd;
        }
        
        /* Product Search Autocomplete Styling */
        .cursor-pointer {
            cursor: pointer;
        }
        
        .suggestion-item {
            transition: background-color 0.2s ease;
        }
        
        .suggestion-item:hover,
        .suggestion-item.active {
            background-color: #f8f9fa;
        }
        
        .suggestion-item:last-child {
            border-bottom: none !important;
        }
        
        #product_suggestions {
            border: 1px solid #dee2e6;
            border-top: none;
            border-radius: 0 0 0.375rem 0.375rem;
        }
        
        #product_search:focus + #product_suggestions {
            border-color: #86b7fe;
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
        }
        
        .metric-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .metric-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }
        
        .comparison-table {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        
        .comparison-table th {
            background: var(--primary-color);
            color: white;
            border: none;
            font-weight: 600;
        }
        
        .trend-up {
            color: #10b981;
        }
        
        .trend-down {
            color: #ef4444;
        }
        
        .trend-neutral {
            color: #6b7280;
        }
    </style>
</head>
<body>
    <?php include '../include/navmenu.php'; ?>

    <div class="main-content">
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1><i class="bi bi-gear"></i> Custom Reports</h1>
                    <p class="header-subtitle">Create and customize your own reports with flexible parameters</p>
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
                <!-- Report Header -->
                <div class="report-header">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h2><i class="bi bi-gear"></i> Custom Reports</h2>
                            <p class="mb-0">Build and customize your own business reports</p>
                        </div>
                    </div>
                </div>

                <!-- Report Filters -->
                <div class="filter-card">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label for="report_type" class="form-label">Report Type</label>
                            <select class="form-select" id="report_type" name="report_type" onchange="this.form.submit()">
                                <optgroup label="Time-based Comparisons">
                                    <option value="daily_comparison" <?php echo $report_type === 'daily_comparison' ? 'selected' : ''; ?>>Daily Comparison</option>
                                    <option value="weekly_comparison" <?php echo $report_type === 'weekly_comparison' ? 'selected' : ''; ?>>Weekly Comparison</option>
                                    <option value="monthly_comparison" <?php echo $report_type === 'monthly_comparison' ? 'selected' : ''; ?>>Monthly Comparison</option>
                                    <option value="yearly_comparison" <?php echo $report_type === 'yearly_comparison' ? 'selected' : ''; ?>>Yearly Comparison</option>
                                </optgroup>
                                <optgroup label="Product Analysis">
                                    <option value="product_comparison" <?php echo $report_type === 'product_comparison' ? 'selected' : ''; ?>>Product Performance</option>
                                    <option value="product_monthly_trends" <?php echo $report_type === 'product_monthly_trends' ? 'selected' : ''; ?>>Product Sales by Month</option>
                                    <option value="product_category_analysis" <?php echo $report_type === 'product_category_analysis' ? 'selected' : ''; ?>>Product vs Category Analysis</option>
                                </optgroup>
                                <optgroup label="Category Analysis">
                                    <option value="category_comparison" <?php echo $report_type === 'category_comparison' ? 'selected' : ''; ?>>Category Performance</option>
                                    <option value="category_vs_category" <?php echo $report_type === 'category_vs_category' ? 'selected' : ''; ?>>Category vs Category</option>
                                    <option value="category_monthly_trends" <?php echo $report_type === 'category_monthly_trends' ? 'selected' : ''; ?>>Category Sales by Month</option>
                                </optgroup>
                                <optgroup label="Advanced Comparisons">
                                    <option value="supplier_comparison" <?php echo $report_type === 'supplier_comparison' ? 'selected' : ''; ?>>Supplier Performance</option>
                                    <option value="customer_analysis" <?php echo $report_type === 'customer_analysis' ? 'selected' : ''; ?>>Customer Analysis</option>
                                </optgroup>
                            </select>
                </div>
                        
                        <?php if (in_array($report_type, ['daily_comparison', 'weekly_comparison', 'monthly_comparison', 'yearly_comparison'])): ?>
                        <div class="col-md-3">
                            <label for="period1" class="form-label">Period 1</label>
                            <?php if ($report_type === 'yearly_comparison'): ?>
                                <select class="form-select" id="period1" name="period1">
                                    <?php 
                                    $current_year = date('Y');
                                    for ($year = $current_year - 10; $year <= $current_year + 5; $year++): 
                                    ?>
                                    <option value="<?php echo $year; ?>" <?php echo $period1 == $year ? 'selected' : ''; ?>>
                                        <?php echo $year; ?>
                                    </option>
                                    <?php endfor; ?>
                                </select>
                            <?php else: ?>
                                <input type="<?php 
                                    if ($report_type === 'daily_comparison') echo 'date';
                                    elseif ($report_type === 'weekly_comparison') echo 'week';
                                    elseif ($report_type === 'monthly_comparison') echo 'month';
                                ?>" 
                                       class="form-control" id="period1" name="period1" 
                                       value="<?php echo htmlspecialchars($period1); ?>">
                            <?php endif; ?>
                        </div>
                        <div class="col-md-3">
                            <label for="period2" class="form-label">Period 2</label>
                            <?php if ($report_type === 'yearly_comparison'): ?>
                                <select class="form-select" id="period2" name="period2">
                                    <?php 
                                    $current_year = date('Y');
                                    for ($year = $current_year - 10; $year <= $current_year + 5; $year++): 
                                    ?>
                                    <option value="<?php echo $year; ?>" <?php echo $period2 == $year ? 'selected' : ''; ?>>
                                        <?php echo $year; ?>
                                    </option>
                                    <?php endfor; ?>
                                </select>
                            <?php else: ?>
                                <input type="<?php 
                                    if ($report_type === 'daily_comparison') echo 'date';
                                    elseif ($report_type === 'weekly_comparison') echo 'week';
                                    elseif ($report_type === 'monthly_comparison') echo 'month';
                                ?>" 
                                       class="form-control" id="period2" name="period2" 
                                       value="<?php echo htmlspecialchars($period2); ?>">
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (in_array($report_type, ['product_comparison', 'product_monthly_trends', 'product_category_analysis'])): ?>
                        <div class="col-md-3">
                            <label for="product_search" class="form-label">Product Filter</label>
                            <div class="position-relative">
                                <input type="text" 
                                       class="form-control" 
                                       id="product_search" 
                                       name="product_search" 
                                       placeholder="Search products by name, SKU, or barcode..."
                                       value="<?php echo htmlspecialchars($_GET['product_search'] ?? ''); ?>"
                                       autocomplete="off">
                                <input type="hidden" id="product_id" name="product_id" value="<?php echo $product_id; ?>">
                                <div id="product_suggestions" class="position-absolute w-100 bg-white border rounded shadow-lg" style="z-index: 1000; display: none; max-height: 300px; overflow-y: auto;"></div>
                                <div class="position-absolute top-50 end-0 translate-middle-y pe-3">
                                    <i class="bi bi-search text-muted"></i>
                                </div>
                            </div>
                            <small class="text-muted">Type to search products by name, SKU, or barcode</small>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (in_array($report_type, ['category_comparison', 'category_vs_category', 'category_monthly_trends', 'product_category_analysis'])): ?>
                        <div class="col-md-3">
                            <label for="category_id" class="form-label">Category Filter</label>
                            <select class="form-select" id="category_id" name="category_id">
                                <option value="">All Categories</option>
                                <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>" <?php echo $category_id == $category['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($category['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($report_type === 'category_vs_category'): ?>
                        <div class="col-md-3">
                            <label for="category2_id" class="form-label">Compare With Category</label>
                            <select class="form-select" id="category2_id" name="category2_id">
                                <option value="">Select Category</option>
                                <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>" <?php echo ($_GET['category2_id'] ?? '') == $category['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($category['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (in_array($report_type, ['product_monthly_trends', 'category_monthly_trends'])): ?>
                        <div class="col-md-3">
                            <label for="year_filter" class="form-label">Year</label>
                            <select class="form-select" id="year_filter" name="year_filter">
                                <?php 
                                $current_year = date('Y');
                                for ($year = $current_year - 3; $year <= $current_year; $year++): 
                                ?>
                                <option value="<?php echo $year; ?>" <?php echo ($_GET['year_filter'] ?? $current_year) == $year ? 'selected' : ''; ?>>
                                    <?php echo $year; ?>
                                </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        
                        <div class="col-md-3 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary me-2">
                                <i class="bi bi-search"></i> Generate Report
                            </button>
                            <button type="button" class="btn btn-success" onclick="exportReport()">
                                <i class="bi bi-download"></i> Export
                            </button>
                        </div>
                    </form>
                    
                    <!-- Quick Selection Buttons -->
                    <?php if (in_array($report_type, ['daily_comparison', 'weekly_comparison', 'monthly_comparison', 'yearly_comparison'])): ?>
                    <div class="mt-3">
                        <h6 class="mb-2">Quick Selections:</h6>
                        <div class="btn-group" role="group">
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setSamePeriod()">
                                <i class="bi bi-arrow-repeat"></i> Same Period
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setPreviousPeriod()">
                                <i class="bi bi-arrow-left"></i> Previous Period
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setCurrentPeriod()">
                                <i class="bi bi-calendar-check"></i> Current Period
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>


                <!-- Report Results -->
                <?php if (in_array($report_type, ['daily_comparison', 'weekly_comparison', 'monthly_comparison', 'yearly_comparison'])): ?>
                    <!-- Time-based Comparison -->
                    <div class="row">
                        <div class="col-md-6">
                            <div class="comparison-card">
                                <h5 class="mb-3">
                                    <i class="bi bi-calendar"></i> 
                                    <?php 
                                        $period_label = 'Period';
                                        if ($report_type === 'daily_comparison') $period_label = 'Day';
                                        elseif ($report_type === 'weekly_comparison') $period_label = 'Week';
                                        elseif ($report_type === 'monthly_comparison') $period_label = 'Month';
                                        elseif ($report_type === 'yearly_comparison') $period_label = 'Year';
                                        echo $period_label;
                                    ?> 1
                                    <small class="text-muted">(<?php echo htmlspecialchars($period1); ?>)</small>
                                </h5>
                                
                                <div class="row">
                                    <div class="col-6">
                                        <div class="metric-card">
                                            <div class="metric-value"><?php echo number_format($report_data['total_sales'] ?? 0); ?></div>
                                            <div class="metric-label">Total Sales</div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="metric-card">
                                            <div class="metric-value"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($report_data['total_revenue'] ?? 0, 2); ?></div>
                                            <div class="metric-label">Total Revenue</div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="metric-card">
                                            <div class="metric-value"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($report_data['avg_sale_amount'] ?? 0, 2); ?></div>
                                            <div class="metric-label">Avg Sale Amount</div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="metric-card">
                                            <div class="metric-value"><?php echo number_format($report_data['unique_customers'] ?? 0); ?></div>
                                            <div class="metric-label">Unique Customers</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="comparison-card">
                                <h5 class="mb-3">
                                    <i class="bi bi-calendar"></i> 
                                    <?php 
                                        $period_label = 'Period';
                                        if ($report_type === 'daily_comparison') $period_label = 'Day';
                                        elseif ($report_type === 'weekly_comparison') $period_label = 'Week';
                                        elseif ($report_type === 'monthly_comparison') $period_label = 'Month';
                                        elseif ($report_type === 'yearly_comparison') $period_label = 'Year';
                                        echo $period_label;
                                    ?> 2
                                    <small class="text-muted">(<?php echo htmlspecialchars($period2); ?>)</small>
                                </h5>
                                
                                <div class="row">
                                    <div class="col-6">
                                        <div class="metric-card comparison">
                                            <div class="metric-value"><?php echo number_format($comparison_data['total_sales'] ?? 0); ?></div>
                                            <div class="metric-label">Total Sales</div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="metric-card comparison">
                                            <div class="metric-value"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($comparison_data['total_revenue'] ?? 0, 2); ?></div>
                                            <div class="metric-label">Total Revenue</div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="metric-card comparison">
                                            <div class="metric-value"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($comparison_data['avg_sale_amount'] ?? 0, 2); ?></div>
                                            <div class="metric-label">Avg Sale Amount</div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="metric-card comparison">
                                            <div class="metric-value"><?php echo number_format($comparison_data['unique_customers'] ?? 0); ?></div>
                                            <div class="metric-label">Unique Customers</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Comparison Summary -->
                    <div class="comparison-card">
                        <h5 class="mb-3"><i class="bi bi-graph-up"></i> Comparison Summary</h5>
                        <?php if ($period1 === $period2): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> <strong>Same Period Comparison:</strong> You are comparing the same period to itself. This shows the data for that specific period.
                        </div>
                        <?php endif; ?>
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th>Metric</th>
                                        <th>Period 1 (<?php echo htmlspecialchars($period1); ?>)</th>
                                        <th>Period 2 (<?php echo htmlspecialchars($period2); ?>)</th>
                                        <th>Change</th>
                                        <th>% Change</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $metrics = [
                                        'Total Sales' => ['total_sales', 0],
                                        'Total Revenue' => ['total_revenue', 2],
                                        'Avg Sale Amount' => ['avg_sale_amount', 2],
                                        'Unique Customers' => ['unique_customers', 0]
                                    ];
                                    
                                    foreach ($metrics as $label => $config):
                                        $key = $config[0];
                                        $decimals = $config[1];
                                        $value1 = $report_data[$key] ?? 0;
                                        $value2 = $comparison_data[$key] ?? 0;
                                        $change = $value2 - $value1;
                                        $percent_change = $value1 != 0 ? ($change / $value1) * 100 : 0;
                                        
                                        $trend_class = 'trend-neutral';
                                        if ($change > 0) $trend_class = 'trend-up';
                                        elseif ($change < 0) $trend_class = 'trend-down';
                                    ?>
                                    <tr>
                                        <td><strong><?php echo $label; ?></strong></td>
                                        <td><?php echo $decimals > 0 ? number_format($value1, $decimals) : number_format($value1); ?></td>
                                        <td><?php echo $decimals > 0 ? number_format($value2, $decimals) : number_format($value2); ?></td>
                                        <td class="<?php echo $trend_class; ?>">
                                            <?php echo $change > 0 ? '+' : ''; ?><?php echo $decimals > 0 ? number_format($change, $decimals) : number_format($change); ?>
                                        </td>
                                        <td class="<?php echo $trend_class; ?>">
                                            <?php echo $change > 0 ? '+' : ''; ?><?php echo number_format($percent_change, 1); ?>%
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                <?php elseif (in_array($report_type, ['product_comparison', 'category_comparison', 'product_monthly_trends', 'category_monthly_trends', 'product_category_analysis', 'supplier_comparison', 'customer_analysis'])): ?>
                    <!-- Advanced Comparison Reports -->
                    <div class="comparison-card">
                        <h5 class="mb-3">
                            <i class="bi bi-<?php 
                                if (in_array($report_type, ['product_comparison', 'product_monthly_trends', 'product_category_analysis'])) echo 'box';
                                elseif (in_array($report_type, ['category_comparison', 'category_vs_category', 'category_monthly_trends'])) echo 'tags';
                                elseif ($report_type === 'supplier_comparison') echo 'truck';
                                elseif ($report_type === 'customer_analysis') echo 'people';
                                else echo 'graph-up';
                            ?>"></i> 
                            <?php 
                                $titles = [
                                    'product_comparison' => 'Product Performance',
                                    'category_comparison' => 'Category Performance',
                                    'product_monthly_trends' => 'Product Sales by Month',
                                    'category_monthly_trends' => 'Category Sales by Month',
                                    'category_vs_category' => 'Category vs Category Comparison',
                                    'product_category_analysis' => 'Product vs Category Analysis',
                                    'supplier_comparison' => 'Supplier Performance',
                                    'customer_analysis' => 'Customer Analysis'
                                ];
                                echo $titles[$report_type] ?? 'Performance Analysis';
                            ?>
                        </h5>
                        
                        <?php if ($report_type === 'category_vs_category' && empty($report_data)): ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i> Please select two categories to compare.
                        </div>
                        <?php endif; ?>
                        
                        <div class="table-responsive">
                            <table class="table comparison-table">
                                <thead>
                                    <tr>
                                        <?php if (in_array($report_type, ['product_monthly_trends', 'category_monthly_trends'])): ?>
                                            <th><?php echo $report_type === 'product_monthly_trends' ? 'Product' : 'Category'; ?></th>
                                            <th>Month</th>
                                            <th>Sales Count</th>
                                            <th>Quantity Sold</th>
                                            <th>Revenue</th>
                                            <th>Avg Price</th>
                                        <?php elseif ($report_type === 'category_vs_category'): ?>
                                            <th>Category</th>
                                            <th>Sales Count</th>
                                            <th>Quantity Sold</th>
                                            <th>Revenue</th>
                                            <th>Avg Price</th>
                                            <th>Total Cost</th>
                                            <th>Profit</th>
                                            <th>Profit Margin</th>
                                        <?php elseif ($report_type === 'product_category_analysis'): ?>
                                            <th>Product</th>
                                            <th>Category</th>
                                            <th>Sales Count</th>
                                            <th>Quantity Sold</th>
                                            <th>Revenue</th>
                                            <th>Avg Price</th>
                                            <th>Total Cost</th>
                                            <th>Profit</th>
                                            <th>Profit Margin</th>
                                        <?php elseif ($report_type === 'supplier_comparison'): ?>
                                            <th>Supplier</th>
                                            <th>Total Orders</th>
                                            <th>Completed Orders</th>
                                            <th>Avg Delivery Days</th>
                                            <th>Total Order Value</th>
                                            <th>Products Supplied</th>
                                        <?php elseif ($report_type === 'customer_analysis'): ?>
                                            <th>Customer</th>
                                            <th>Phone</th>
                                            <th>Email</th>
                                            <th>Total Orders</th>
                                            <th>Total Spent</th>
                                            <th>Avg Order Value</th>
                                            <th>Last Order</th>
                                        <?php else: ?>
                                            <th><?php echo $report_type === 'product_comparison' ? 'Product' : 'Category'; ?></th>
                                            <th>Sales Count</th>
                                            <th>Quantity Sold</th>
                                            <th>Revenue</th>
                                            <th>Avg Price</th>
                                            <th>Total Cost</th>
                                            <th>Profit</th>
                                            <th>Profit Margin</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($report_data as $item): 
                                        if (in_array($report_type, ['product_comparison', 'category_comparison', 'category_vs_category', 'product_category_analysis'])) {
                                            $profit_margin = $item['total_revenue'] > 0 ? ($item['total_profit'] / $item['total_revenue']) * 100 : 0;
                                        }
                                    ?>
                                    <tr>
                                        <?php if (in_array($report_type, ['product_monthly_trends', 'category_monthly_trends'])): ?>
                                            <td><strong><?php echo htmlspecialchars($item[$report_type === 'product_monthly_trends' ? 'name' : 'category_name']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($item['month_name']); ?></td>
                                            <td><?php echo number_format($item['total_sales']); ?></td>
                                            <td><?php echo number_format($item['total_quantity']); ?></td>
                                            <td><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($item['total_revenue'], 2); ?></td>
                                            <td><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($item['avg_price'], 2); ?></td>
                                        <?php elseif ($report_type === 'category_vs_category'): ?>
                                            <td><strong><?php echo htmlspecialchars($item['category_name']); ?></strong></td>
                                            <td><?php echo number_format($item['total_sales']); ?></td>
                                            <td><?php echo number_format($item['total_quantity']); ?></td>
                                            <td><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($item['total_revenue'], 2); ?></td>
                                            <td><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($item['avg_price'], 2); ?></td>
                                            <td><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($item['total_cost'], 2); ?></td>
                                            <td class="<?php echo $item['total_profit'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($item['total_profit'], 2); ?>
                                            </td>
                                            <td class="<?php echo $profit_margin >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                <?php echo number_format($profit_margin, 1); ?>%
                                            </td>
                                        <?php elseif ($report_type === 'product_category_analysis'): ?>
                                            <td><strong><?php echo htmlspecialchars($item['product_name']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($item['category_name']); ?></td>
                                            <td><?php echo number_format($item['total_sales']); ?></td>
                                            <td><?php echo number_format($item['total_quantity']); ?></td>
                                            <td><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($item['total_revenue'], 2); ?></td>
                                            <td><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($item['avg_price'], 2); ?></td>
                                            <td><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($item['total_cost'], 2); ?></td>
                                            <td class="<?php echo $item['total_profit'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($item['total_profit'], 2); ?>
                                            </td>
                                            <td class="<?php echo $profit_margin >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                <?php echo number_format($profit_margin, 1); ?>%
                                            </td>
                                        <?php elseif ($report_type === 'supplier_comparison'): ?>
                                            <td><strong><?php echo htmlspecialchars($item['supplier_name']); ?></strong></td>
                                            <td><?php echo number_format($item['total_orders']); ?></td>
                                            <td><?php echo number_format($item['completed_orders']); ?></td>
                                            <td><?php echo number_format($item['avg_delivery_days'], 1); ?> days</td>
                                            <td><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($item['total_order_value'], 2); ?></td>
                                            <td><?php echo number_format($item['products_supplied']); ?></td>
                                        <?php elseif ($report_type === 'customer_analysis'): ?>
                                            <td><strong><?php echo htmlspecialchars($item['customer_name']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($item['phone']); ?></td>
                                            <td><?php echo htmlspecialchars($item['email']); ?></td>
                                            <td><?php echo number_format($item['total_orders']); ?></td>
                                            <td><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($item['total_spent'], 2); ?></td>
                                            <td><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($item['avg_order_value'], 2); ?></td>
                                            <td><?php echo date('M d, Y', strtotime($item['last_order_date'])); ?></td>
                                        <?php else: ?>
                                            <td><strong><?php echo htmlspecialchars($item[$report_type === 'product_comparison' ? 'name' : 'category_name']); ?></strong></td>
                                            <td><?php echo number_format($item['total_sales']); ?></td>
                                            <td><?php echo number_format($item['total_quantity']); ?></td>
                                            <td><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($item['total_revenue'], 2); ?></td>
                                            <td><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($item['avg_price'], 2); ?></td>
                                            <td><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($item['total_cost'], 2); ?></td>
                                            <td class="<?php echo $item['total_profit'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($item['total_profit'], 2); ?>
                                            </td>
                                            <td class="<?php echo $profit_margin >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                <?php echo number_format($profit_margin, 1); ?>%
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php elseif (in_array($report_type, ['product_comparison', 'product_monthly_trends', 'product_category_analysis', 'category_comparison', 'category_vs_category', 'category_monthly_trends', 'supplier_comparison', 'customer_analysis'])): ?>
                    <!-- Product/Category/Advanced Reports -->
                    <div class="row">
                        <div class="col-12">
                            <div class="comparison-card">
                                <h5 class="mb-3">
                                    <i class="bi bi-graph-up"></i> 
                                    <?php 
                                        $report_titles = [
                                            'product_comparison' => 'Product Performance Analysis',
                                            'product_monthly_trends' => 'Product Sales by Month',
                                            'product_category_analysis' => 'Product vs Category Analysis',
                                            'category_comparison' => 'Category Performance Analysis',
                                            'category_vs_category' => 'Category vs Category Comparison',
                                            'category_monthly_trends' => 'Category Sales by Month',
                                            'supplier_comparison' => 'Supplier Performance Analysis',
                                            'customer_analysis' => 'Customer Analysis'
                                        ];
                                        echo $report_titles[$report_type] ?? 'Report Results';
                                    ?>
                                </h5>
                                
                                <?php if (empty($report_data)): ?>
                                <div class="alert alert-warning">
                                    <i class="bi bi-exclamation-triangle"></i> No data found for the selected criteria. Please adjust your filters and try again.
                                </div>
                                <?php else: ?>
                                
                                <!-- Summary Cards -->
                                <div class="row mb-4">
                                    <?php if (in_array($report_type, ['product_comparison', 'product_monthly_trends', 'product_category_analysis'])): ?>
                                    <div class="col-md-3">
                                        <div class="metric-card">
                                            <div class="metric-value"><?php echo count($report_data); ?></div>
                                            <div class="metric-label">Total Products</div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="metric-card">
                                            <div class="metric-value"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format(array_sum(array_column($report_data, 'total_revenue')), 2); ?></div>
                                            <div class="metric-label">Total Revenue</div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="metric-card">
                                            <div class="metric-value"><?php echo number_format(array_sum(array_column($report_data, 'total_quantity')), 0); ?></div>
                                            <div class="metric-label">Total Quantity Sold</div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="metric-card">
                                            <div class="metric-value"><?php echo number_format(array_sum(array_column($report_data, 'total_sales')), 0); ?></div>
                                            <div class="metric-label">Total Sales Count</div>
                                        </div>
                                    </div>
                                    <?php elseif (in_array($report_type, ['category_comparison', 'category_vs_category', 'category_monthly_trends'])): ?>
                                    <div class="col-md-3">
                                        <div class="metric-card">
                                            <div class="metric-value"><?php echo $report_type === 'category_vs_category' ? '2' : count($report_data); ?></div>
                                            <div class="metric-label">Total Categories</div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="metric-card">
                                            <div class="metric-value"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format(array_sum(array_column($report_data, 'total_revenue')), 2); ?></div>
                                            <div class="metric-label">Total Revenue</div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="metric-card">
                                            <div class="metric-value"><?php echo number_format(array_sum(array_column($report_data, 'total_quantity')), 0); ?></div>
                                            <div class="metric-label">Total Quantity Sold</div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="metric-card">
                                            <div class="metric-value"><?php echo number_format(array_sum(array_column($report_data, 'total_sales')), 0); ?></div>
                                            <div class="metric-label">Total Sales Count</div>
                                        </div>
                                    </div>
                                    <?php elseif ($report_type === 'supplier_comparison'): ?>
                                    <div class="col-md-3">
                                        <div class="metric-card">
                                            <div class="metric-value"><?php echo count($report_data); ?></div>
                                            <div class="metric-label">Total Suppliers</div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="metric-card">
                                            <div class="metric-value"><?php echo number_format(array_sum(array_column($report_data, 'total_orders')), 0); ?></div>
                                            <div class="metric-label">Total Orders</div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="metric-card">
                                            <div class="metric-value"><?php echo number_format(array_sum(array_column($report_data, 'completed_orders')), 0); ?></div>
                                            <div class="metric-label">Completed Orders</div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="metric-card">
                                            <div class="metric-value"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format(array_sum(array_column($report_data, 'total_order_value')), 2); ?></div>
                                            <div class="metric-label">Total Order Value</div>
                                        </div>
                                    </div>
                                    <?php elseif ($report_type === 'customer_analysis'): ?>
                                    <div class="col-md-3">
                                        <div class="metric-card">
                                            <div class="metric-value"><?php echo count($report_data); ?></div>
                                            <div class="metric-label">Total Customers</div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="metric-card">
                                            <div class="metric-value"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format(array_sum(array_column($report_data, 'total_spent')), 2); ?></div>
                                            <div class="metric-label">Total Spent</div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="metric-card">
                                            <div class="metric-value"><?php echo number_format(array_sum(array_column($report_data, 'total_orders')), 0); ?></div>
                                            <div class="metric-label">Total Orders</div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="metric-card">
                                            <div class="metric-value"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format(array_sum(array_column($report_data, 'avg_order_value')), 2); ?></div>
                                            <div class="metric-label">Avg Order Value</div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Detailed Table -->
                                <div class="table-responsive">
                                    <table class="table table-bordered table-striped">
                                        <thead class="table-dark">
                                            <tr>
                                                <?php if (in_array($report_type, ['product_comparison', 'product_monthly_trends', 'product_category_analysis'])): ?>
                                                <th>Product Name</th>
                                                <th>SKU</th>
                                                <th>Category</th>
                                                <th>Total Sales</th>
                                                <th>Total Quantity</th>
                                                <th>Total Revenue</th>
                                                <th>Avg Price</th>
                                                <?php if ($report_type === 'product_monthly_trends'): ?>
                                                <th>Month</th>
                                                <?php endif; ?>
                                                <?php elseif (in_array($report_type, ['category_comparison', 'category_vs_category', 'category_monthly_trends'])): ?>
                                                <th>Category Name</th>
                                                <th>Total Sales</th>
                                                <th>Total Quantity</th>
                                                <th>Total Revenue</th>
                                                <th>Avg Price</th>
                                                <?php if ($report_type === 'category_vs_category'): ?>
                                                <th>Performance</th>
                                                <?php endif; ?>
                                                <?php if ($report_type === 'category_monthly_trends'): ?>
                                                <th>Month</th>
                                                <?php endif; ?>
                                                <?php elseif ($report_type === 'supplier_comparison'): ?>
                                                <th>Supplier Name</th>
                                                <th>Total Orders</th>
                                                <th>Completed Orders</th>
                                                <th>Completion Rate</th>
                                                <th>Avg Delivery Days</th>
                                                <th>Total Order Value</th>
                                                <?php elseif ($report_type === 'customer_analysis'): ?>
                                                <th>Customer Name</th>
                                                <th>Total Orders</th>
                                                <th>Total Spent</th>
                                                <th>Avg Order Value</th>
                                                <th>Last Order Date</th>
                                                <?php endif; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($report_data as $row): ?>
                                            <tr>
                                                <?php if (in_array($report_type, ['product_comparison', 'product_monthly_trends', 'product_category_analysis'])): ?>
                                                <td><?php echo htmlspecialchars($row['product_name'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($row['sku'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($row['category_name'] ?? 'N/A'); ?></td>
                                                <td><?php echo number_format($row['total_sales'] ?? 0); ?></td>
                                                <td><?php echo number_format($row['total_quantity'] ?? 0); ?></td>
                                                <td><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($row['total_revenue'] ?? 0, 2); ?></td>
                                                <td><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($row['avg_price'] ?? 0, 2); ?></td>
                                                <?php if ($report_type === 'product_monthly_trends'): ?>
                                                <td><?php echo htmlspecialchars($row['month'] ?? 'N/A'); ?></td>
                                                <?php endif; ?>
                                                <?php elseif (in_array($report_type, ['category_comparison', 'category_vs_category', 'category_monthly_trends'])): ?>
                                                <td><?php echo htmlspecialchars($row['category_name'] ?? 'N/A'); ?></td>
                                                <td><?php echo number_format($row['total_sales'] ?? 0); ?></td>
                                                <td><?php echo number_format($row['total_quantity'] ?? 0); ?></td>
                                                <td><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($row['total_revenue'] ?? 0, 2); ?></td>
                                                <td><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($row['avg_price'] ?? 0, 2); ?></td>
                                                <?php if ($report_type === 'category_vs_category'): ?>
                                                <td>
                                                    <?php 
                                                    $revenue = $row['total_revenue'] ?? 0;
                                                    if ($revenue > 0) {
                                                        echo '<span class="badge bg-success">Active</span>';
                                                    } else {
                                                        echo '<span class="badge bg-secondary">No Sales</span>';
                                                    }
                                                    ?>
                                                </td>
                                                <?php endif; ?>
                                                <?php if ($report_type === 'category_monthly_trends'): ?>
                                                <td><?php echo htmlspecialchars($row['month'] ?? 'N/A'); ?></td>
                                                <?php endif; ?>
                                                <?php elseif ($report_type === 'supplier_comparison'): ?>
                                                <td><?php echo htmlspecialchars($row['supplier_name'] ?? 'N/A'); ?></td>
                                                <td><?php echo number_format($row['total_orders'] ?? 0); ?></td>
                                                <td><?php echo number_format($row['completed_orders'] ?? 0); ?></td>
                                                <td><?php echo number_format(($row['completion_rate'] ?? 0) * 100, 1); ?>%</td>
                                                <td><?php echo number_format($row['avg_delivery_days'] ?? 0, 1); ?> days</td>
                                                <td><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($row['total_order_value'] ?? 0, 2); ?></td>
                                                <?php elseif ($report_type === 'customer_analysis'): ?>
                                                <td><?php echo htmlspecialchars($row['customer_name'] ?? 'N/A'); ?></td>
                                                <td><?php echo number_format($row['total_orders'] ?? 0); ?></td>
                                                <td><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($row['total_spent'] ?? 0, 2); ?></td>
                                                <td><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($row['avg_order_value'] ?? 0, 2); ?></td>
                                                <td><?php echo htmlspecialchars($row['last_order_date'] ?? 'N/A'); ?></td>
                                                <?php endif; ?>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        // Initialize Select2 for searchable dropdowns
        $(document).ready(function() {
            // Initialize category search
            $('#category_id, #category2_id').select2({
                placeholder: 'Search and select a category...',
                allowClear: true,
                width: '100%',
                dropdownParent: $('#category_id').parent()
            });
            
            // Initialize supplier search
            $('#supplier_id').select2({
                placeholder: 'Search and select a supplier...',
                allowClear: true,
                width: '100%',
                dropdownParent: $('#supplier_id').parent()
            });
            
            // Initialize product autocomplete
            initializeProductSearch();
            
            // Load selected product if exists
            loadSelectedProduct();
        });
        
        function initializeProductSearch() {
            const searchInput = $('#product_search');
            const suggestionsDiv = $('#product_suggestions');
            const productIdInput = $('#product_id');
            let searchTimeout;
            let selectedProduct = null;
            
            // Handle input changes
            searchInput.on('input', function() {
                const query = $(this).val().trim();
                
                clearTimeout(searchTimeout);
                
                if (query.length < 2) {
                    suggestionsDiv.hide();
                    productIdInput.val('');
                    selectedProduct = null;
                    return;
                }
                
                searchTimeout = setTimeout(() => {
                    searchProducts(query);
                }, 300);
            });
            
            // Handle focus
            searchInput.on('focus', function() {
                if ($(this).val().trim().length >= 2) {
                    suggestionsDiv.show();
                }
            });
            
            // Handle blur (hide suggestions after a delay)
            searchInput.on('blur', function() {
                setTimeout(() => {
                    suggestionsDiv.hide();
                }, 200);
            });
            
            // Handle keyboard navigation
            searchInput.on('keydown', function(e) {
                const suggestions = suggestionsDiv.find('.suggestion-item');
                const active = suggestionsDiv.find('.suggestion-item.active');
                
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    if (active.length === 0) {
                        suggestions.first().addClass('active');
                    } else {
                        active.removeClass('active').next().addClass('active');
                    }
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    if (active.length > 0) {
                        active.removeClass('active').prev().addClass('active');
                    }
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (active.length > 0) {
                        active.click();
                    }
                } else if (e.key === 'Escape') {
                    suggestionsDiv.hide();
                }
            });
            
            function searchProducts(query) {
                $.ajax({
                    url: '../api/search_products.php',
                    method: 'GET',
                    data: { q: query, limit: 20 },
                    dataType: 'json',
                    success: function(response) {
                        if (response && response.products) {
                            displaySuggestions(response.products);
                        } else {
                            suggestionsDiv.html('<div class="p-2 text-muted">No products found</div>').show();
                        }
                    },
                    error: function() {
                        suggestionsDiv.html('<div class="p-2 text-danger">Error loading products. Please try again.</div>').show();
                    }
                });
            }
            
            function displaySuggestions(products) {
                if (products.length === 0) {
                    suggestionsDiv.html('<div class="p-2 text-muted">No products found</div>').show();
                    return;
                }
                
                let html = '';
                products.forEach(function(product) {
                    html += `
                        <div class="suggestion-item p-2 border-bottom cursor-pointer" 
                             data-id="${product.id}" 
                             data-name="${product.name}"
                             data-sku="${product.sku}">
                            <div class="fw-bold">${product.name}</div>
                            <small class="text-muted">
                                SKU: ${product.sku} | 
                                Barcode: ${product.barcode} | 
                                Price: KES ${product.price} | 
                                Stock: ${product.quantity} | 
                                Category: ${product.category}
                            </small>
                        </div>
                    `;
                });
                
                suggestionsDiv.html(html).show();
                
                // Handle suggestion clicks
                suggestionsDiv.find('.suggestion-item').on('click', function() {
                    const productId = $(this).data('id');
                    const productName = $(this).data('name');
                    const productSku = $(this).data('sku');
                    
                    productIdInput.val(productId);
                    searchInput.val(productName + ' (' + productSku + ')');
                    suggestionsDiv.hide();
                    selectedProduct = { id: productId, name: productName, sku: productSku };
                });
                
                // Handle hover effects
                suggestionsDiv.find('.suggestion-item').on('mouseenter', function() {
                    suggestionsDiv.find('.suggestion-item').removeClass('active');
                    $(this).addClass('active');
                });
            }
        }
        
        function loadSelectedProduct() {
            const productId = $('#product_id').val();
            if (productId) {
                // Fetch product details to display
                $.ajax({
                    url: '../api/search_products.php',
                    method: 'GET',
                    data: { product_id: productId },
                    dataType: 'json',
                    success: function(response) {
                        if (response.products && response.products.length > 0) {
                            const product = response.products[0];
                            $('#product_search').val(product.display_text);
                        }
                    }
                });
            }
        }
        
        function setSamePeriod() {
            const period1 = document.getElementById('period1').value;
            document.getElementById('period2').value = period1;
        }
        
        function setPreviousPeriod() {
            const reportType = '<?php echo $report_type; ?>';
            const period1 = document.getElementById('period1').value;
            let period2 = '';
            
            if (reportType === 'daily_comparison') {
                const date = new Date(period1);
                date.setDate(date.getDate() - 1);
                period2 = date.toISOString().split('T')[0];
            } else if (reportType === 'weekly_comparison') {
                const [year, week] = period1.split('-W');
                const date = new Date(year, 0, 1);
                const weekStart = new Date(date.getTime() + (week - 1) * 7 * 24 * 60 * 60 * 1000);
                weekStart.setDate(weekStart.getDate() - 7);
                const prevWeek = getWeekNumber(weekStart);
                period2 = `${weekStart.getFullYear()}-W${prevWeek.toString().padStart(2, '0')}`;
            } else if (reportType === 'monthly_comparison') {
                const [year, month] = period1.split('-');
                const date = new Date(year, month - 1, 1);
                date.setMonth(date.getMonth() - 1);
                period2 = `${date.getFullYear()}-${(date.getMonth() + 1).toString().padStart(2, '0')}`;
            } else if (reportType === 'yearly_comparison') {
                period2 = (parseInt(period1) - 1).toString();
            }
            
            document.getElementById('period2').value = period2;
        }
        
        function setCurrentPeriod() {
            const reportType = '<?php echo $report_type; ?>';
            let currentPeriod = '';
            
            if (reportType === 'daily_comparison') {
                currentPeriod = new Date().toISOString().split('T')[0];
            } else if (reportType === 'weekly_comparison') {
                const now = new Date();
                const weekNumber = getWeekNumber(now);
                currentPeriod = `${now.getFullYear()}-W${weekNumber.toString().padStart(2, '0')}`;
            } else if (reportType === 'monthly_comparison') {
                const now = new Date();
                currentPeriod = `${now.getFullYear()}-${(now.getMonth() + 1).toString().padStart(2, '0')}`;
            } else if (reportType === 'yearly_comparison') {
                currentPeriod = new Date().getFullYear().toString();
            }
            
            document.getElementById('period2').value = currentPeriod;
        }
        
        function getWeekNumber(date) {
            const firstDayOfYear = new Date(date.getFullYear(), 0, 1);
            const pastDaysOfYear = (date - firstDayOfYear) / 86400000;
            return Math.ceil((pastDaysOfYear + firstDayOfYear.getDay() + 1) / 7);
        }
        
        function exportReport() {
            const reportType = '<?php echo $report_type; ?>';
            let csvContent = '';
            
            if (['daily_comparison', 'weekly_comparison', 'monthly_comparison', 'yearly_comparison'].includes(reportType)) {
                // Time-based comparison export
                const reportLabels = {
                    'daily_comparison': 'Daily',
                    'weekly_comparison': 'Weekly', 
                    'monthly_comparison': 'Monthly',
                    'yearly_comparison': 'Yearly'
                };
                csvContent += `${reportLabels[reportType]} Comparison Report\n`;
                csvContent += `Period 1: <?php echo $period1; ?>, Period 2: <?php echo $period2; ?>\n\n`;
                csvContent += `Metric,Period 1,Period 2,Change,% Change\n`;
                
                const metrics = [
                    ['Total Sales', <?php echo $report_data['total_sales'] ?? 0; ?>, <?php echo $comparison_data['total_sales'] ?? 0; ?>],
                    ['Total Revenue', <?php echo $report_data['total_revenue'] ?? 0; ?>, <?php echo $comparison_data['total_revenue'] ?? 0; ?>],
                    ['Avg Sale Amount', <?php echo $report_data['avg_sale_amount'] ?? 0; ?>, <?php echo $comparison_data['avg_sale_amount'] ?? 0; ?>],
                    ['Unique Customers', <?php echo $report_data['unique_customers'] ?? 0; ?>, <?php echo $comparison_data['unique_customers'] ?? 0; ?>]
                ];
                
                metrics.forEach(([metric, value1, value2]) => {
                    const change = value2 - value1;
                    const percentChange = value1 !== 0 ? (change / value1) * 100 : 0;
                    csvContent += `${metric},${value1},${value2},${change},${percentChange.toFixed(1)}%\n`;
                });
                
            } else if (reportType === 'product_comparison' || reportType === 'category_comparison') {
                // Product/Category comparison export
                csvContent += `${reportType === 'product_comparison' ? 'Product' : 'Category'} Performance Comparison\n\n`;
                csvContent += `${reportType === 'product_comparison' ? 'Product' : 'Category'},Sales Count,Quantity Sold,Revenue,Avg Price,Total Cost,Profit,Profit Margin\n`;
                
                // Add data rows
                const reportData = <?php echo json_encode($report_data); ?>;
                const nameField = '<?php echo $report_type === 'product_comparison' ? 'name' : 'category_name'; ?>';
                reportData.forEach(function(item) {
                    const profitMargin = item.total_revenue > 0 ? (item.total_profit / item.total_revenue) * 100 : 0;
                    const name = item[nameField] || 'N/A';
                    csvContent += `"${name}",${item.total_sales},${item.total_quantity},${item.total_revenue},${item.avg_price},${item.total_cost},${item.total_profit},${profitMargin.toFixed(1)}%\n`;
                });
            }
            
            const encodedUri = encodeURI(csvContent);
            const link = document.createElement("a");
            link.setAttribute("href", encodedUri);
            link.setAttribute("download", `custom_report_${reportType}_${new Date().toISOString().split('T')[0]}.csv`);
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
    </script>
</body>
</html>
