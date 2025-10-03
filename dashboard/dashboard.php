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

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get dashboard statistics
$stats = [];

// Total Sales Today
$stmt = $conn->prepare("SELECT COUNT(*) as count, COALESCE(SUM(final_amount), 0) as total FROM sales WHERE DATE(sale_date) = CURDATE()");
$stmt->execute();
$today_sales = $stmt->fetch(PDO::FETCH_ASSOC);
$stats['today_sales_count'] = (int)($today_sales['count'] ?? 0);
$stats['today_sales_total'] = (float)($today_sales['total'] ?? 0);

// Total Sales This Month
$stmt = $conn->prepare("SELECT COUNT(*) as count, COALESCE(SUM(final_amount), 0) as total FROM sales WHERE MONTH(sale_date) = MONTH(CURDATE()) AND YEAR(sale_date) = YEAR(CURDATE())");
$stmt->execute();
$month_sales = $stmt->fetch(PDO::FETCH_ASSOC);
$stats['month_sales_count'] = (int)($month_sales['count'] ?? 0);
$stats['month_sales_total'] = (float)($month_sales['total'] ?? 0);

// Items sold today
try {
    $stmt = $conn->prepare("SELECT COALESCE(SUM(si.quantity), 0) AS items_sold FROM sale_items si JOIN sales s ON s.id = si.sale_id WHERE DATE(s.sale_date) = CURDATE()");
    $stmt->execute();
    $stats['items_sold_today'] = (int)($stmt->fetchColumn() ?: 0);
} catch (Exception $e) { $stats['items_sold_today'] = 0; }

// Average ticket (avoid divide by zero)
$stats['avg_ticket'] = $stats['today_sales_count'] > 0 ? ($stats['today_sales_total'] / $stats['today_sales_count']) : 0;

// On-hold/Draft orders (held_transactions)
try {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM held_transactions WHERE status = 'held'");
    $stmt->execute();
    $stats['on_hold_count'] = (int)($stmt->fetchColumn() ?: 0);
} catch (Exception $e) { $stats['on_hold_count'] = 0; }

// Voids today (void_transactions)
try {
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM(ABS(COALESCE(total_amount,0))), 0) AS amt FROM void_transactions WHERE DATE(voided_at) = CURDATE()");
    $stmt->execute();
    $voids = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['cnt' => 0, 'amt' => 0];
    $stats['voids_count_today'] = (int)$voids['cnt'];
    $stats['voids_total_today'] = (float)$voids['amt'];
} catch (Exception $e) { $stats['voids_count_today'] = 0; $stats['voids_total_today'] = 0; }

// Payment breakdown today (prefer sale_payments; fallback to sales if empty)
$payment_breakdown_today = [];
try {
    $stmt = $conn->prepare("SELECT payment_method, COALESCE(SUM(amount),0) AS total FROM sale_payments WHERE DATE(received_at) = CURDATE() GROUP BY payment_method ORDER BY total DESC");
    $stmt->execute();
    $payment_breakdown_today = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $payment_breakdown_today = []; }

if (empty($payment_breakdown_today)) {
    try {
        $stmt = $conn->prepare("SELECT payment_method, COALESCE(SUM(final_amount),0) AS total FROM sales WHERE DATE(sale_date) = CURDATE() GROUP BY payment_method ORDER BY total DESC");
        $stmt->execute();
        $payment_breakdown_today = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $payment_breakdown_today = []; }
}

// Total Products
$stmt = $conn->query("SELECT COUNT(*) as count FROM products");
$stats['total_products'] = (int)($stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0);

// Low stock count using configured thresholds where available
try {
    $stmt = $conn->query("SELECT COUNT(*) as count FROM products WHERE track_inventory = 1 AND ((minimum_stock > 0 AND quantity <= minimum_stock) OR (reorder_point > 0 AND quantity <= reorder_point) OR (minimum_stock = 0 AND reorder_point = 0 AND quantity < 10))");
    $stats['low_stock'] = (int)($stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0);
} catch (Exception $e) {
    // Fallback
    $stmt = $conn->query("SELECT COUNT(*) as count FROM products WHERE quantity < 10");
    $stats['low_stock'] = (int)($stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0);
}

// Total Customers (unique from sales, excluding walk-in)
$stmt = $conn->query("SELECT COUNT(DISTINCT customer_name) as count FROM sales WHERE customer_name != 'Walking Customer'");
$stats['total_customers'] = (int)($stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0);

// Recent Sales (last 10)
$recent_sales = [];
if (hasPermission('manage_sales', $permissions) || hasPermission('process_sales', $permissions)) {
    $stmt = $conn->prepare("
        SELECT s.*, u.username as cashier_name 
        FROM sales s 
        LEFT JOIN users u ON s.user_id = u.id 
        ORDER BY s.sale_date DESC 
        LIMIT 10
    ");
    $stmt->execute();
    $recent_sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Recent voids (last 10)
$recent_voids = [];
try {
    $stmt = $conn->prepare("SELECT id, user_id, void_type, product_name, quantity, total_amount, voided_at FROM void_transactions ORDER BY voided_at DESC LIMIT 10");
    $stmt->execute();
    $recent_voids = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $recent_voids = []; }

// Held transactions (last 10 held)
$held_list = [];
try {
    $stmt = $conn->prepare("SELECT id, customer_reference, reason, created_at FROM held_transactions WHERE status = 'held' ORDER BY created_at DESC LIMIT 10");
    $stmt->execute();
    $held_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $held_list = []; }

// Low stock list (top 10)
$low_stock_list = [];
try {
    $stmt = $conn->prepare("SELECT id, name, quantity, minimum_stock, reorder_point FROM products WHERE track_inventory = 1 AND ((minimum_stock > 0 AND quantity <= minimum_stock) OR (reorder_point > 0 AND quantity <= reorder_point) OR (minimum_stock = 0 AND reorder_point = 0 AND quantity < 10)) ORDER BY quantity ASC LIMIT 10");
    $stmt->execute();
    $low_stock_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $low_stock_list = []; }

// Near expiry products (next 30 days)
$near_expiry_list = [];
try {
    $stmt = $conn->prepare("SELECT ped.id, ped.product_id, ped.expiry_date, ped.remaining_quantity AS quantity, p.name FROM product_expiry_dates ped JOIN products p ON p.id = ped.product_id WHERE ped.expiry_date IS NOT NULL AND ped.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND ped.remaining_quantity > 0 ORDER BY ped.expiry_date ASC LIMIT 10");
    $stmt->execute();
    $near_expiry_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $near_expiry_list = []; }

// New customers today (count and list)
$new_customers_today = [];
try {
    $stmt = $conn->prepare("SELECT id, first_name, last_name, created_at FROM customers WHERE DATE(created_at) = CURDATE() ORDER BY created_at DESC LIMIT 10");
    $stmt->execute();
    $new_customers_today = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stats['new_customers_today_count'] = count($new_customers_today);
} catch (Exception $e) { $stats['new_customers_today_count'] = 0; $new_customers_today = []; }

// Top Selling Products
$top_products = [];
if (hasPermission('manage_products', $permissions)) {
    $stmt = $conn->prepare("
        SELECT p.name, SUM(si.quantity) as total_sold, SUM(si.quantity * si.unit_price) as total_revenue
        FROM products p
        JOIN sale_items si ON p.id = si.product_id
        JOIN sales s ON si.sale_id = s.id
        WHERE DATE(s.sale_date) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY p.id, p.name
        ORDER BY total_sold DESC
        LIMIT 5
    ");
    $stmt->execute();
    $top_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Additional counts for quick links
$quick_stats = [];

// Total Categories
try {
    $stmt = $conn->query("SELECT COUNT(*) as count FROM categories WHERE status = 'active'");
    $quick_stats['total_categories'] = (int)($stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0);
} catch (Exception $e) { $quick_stats['total_categories'] = 0; }

// Total Suppliers
try {
    $stmt = $conn->query("SELECT COUNT(*) as count FROM suppliers WHERE status = 'active'");
    $quick_stats['total_suppliers'] = (int)($stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0);
} catch (Exception $e) { $quick_stats['total_suppliers'] = 0; }

// Total Brands
try {
    $stmt = $conn->query("SELECT COUNT(*) as count FROM brands WHERE status = 'active'");
    $quick_stats['total_brands'] = (int)($stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0);
} catch (Exception $e) { $quick_stats['total_brands'] = 0; }

// Pending Quotations
try {
    $stmt = $conn->query("SELECT COUNT(*) as count FROM quotations WHERE status = 'pending'");
    $quick_stats['pending_quotations'] = (int)($stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0);
} catch (Exception $e) { $quick_stats['pending_quotations'] = 0; }

// Total Expenses This Month
try {
    $stmt = $conn->prepare("SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as total FROM expenses WHERE MONTH(expense_date) = MONTH(CURDATE()) AND YEAR(expense_date) = YEAR(CURDATE())");
    $stmt->execute();
    $expense_data = $stmt->fetch(PDO::FETCH_ASSOC);
    $quick_stats['month_expenses_count'] = (int)($expense_data['count'] ?? 0);
    $quick_stats['month_expenses_total'] = (float)($expense_data['total'] ?? 0);
} catch (Exception $e) { 
    $quick_stats['month_expenses_count'] = 0; 
    $quick_stats['month_expenses_total'] = 0; 
}

// Active BOMs
try {
    $stmt = $conn->query("SELECT COUNT(*) as count FROM boms WHERE status = 'active'");
    $quick_stats['active_boms'] = (int)($stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0);
} catch (Exception $e) { $quick_stats['active_boms'] = 0; }

// Near Expiry Count (30 days)
try {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM product_expiry_dates WHERE expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND remaining_quantity > 0");
    $stmt->execute();
    $quick_stats['near_expiry_count'] = (int)($stmt->fetchColumn() ?: 0);
} catch (Exception $e) { $quick_stats['near_expiry_count'] = 0; }

// Total Users
try {
    $stmt = $conn->query("SELECT COUNT(*) as count FROM users WHERE status = 'active'");
    $quick_stats['total_users'] = (int)($stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0);
} catch (Exception $e) { $quick_stats['total_users'] = 0; }

// Active Tills
try {
    $stmt = $conn->query("SELECT COUNT(*) as count FROM register_tills WHERE is_closed = 0");
    $quick_stats['active_tills'] = (int)($stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0);
} catch (Exception $e) { $quick_stats['active_tills'] = 0; }

// Get inventory statistics using the function from db.php
$inventory_stats = getInventoryStatistics($conn);

// Get sales statistics for this month using the function from db.php
$sales_stats = getSalesStatistics($conn, date('Y-m-01'), date('Y-m-d'));

// Function to format large numbers
function formatLargeNumber($number) {
    // Handle null or non-numeric values
    if ($number === null || !is_numeric($number)) {
        $number = 0;
    }

    // Convert to float to ensure proper handling
    $number = (float) $number;

    if ($number >= 1000000000) {
        return number_format($number / 1000000000, 1) . 'B';
    } elseif ($number >= 1000000) {
        return number_format($number / 1000000, 1) . 'M';
    } elseif ($number >= 10000) {
        return number_format($number / 1000, 1) . 'k';
    } else {
        return number_format($number);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }

        /* Enhanced Statistics Cards */
        .enhanced-stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            grid-template-rows: repeat(2, 1fr);
            gap: 1.5rem;
            margin-bottom: 2.5rem;
            max-width: 100%;
        }

        .enhanced-stat-card {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            border: 1px solid #e5e7eb;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .enhanced-stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-color), #8b5cf6);
            border-radius: 16px 16px 0 0;
        }

        .enhanced-stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
        }

        .card-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .card-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
            background: linear-gradient(135deg, var(--primary-color), #8b5cf6);
        }

        .card-title {
            font-size: 1rem;
            font-weight: 600;
            color: #374151;
            margin: 0;
        }

        .card-content {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .primary-stat {
            display: flex;
            align-items: baseline;
            gap: 0.5rem;
        }

        .primary-stat .currency,
        .primary-stat .number {
            font-size: 2rem;
            font-weight: 700;
            color: #1f2937;
            line-height: 1;
        }

        .primary-stat .label {
            font-size: 0.875rem;
            color: #6b7280;
            font-weight: 500;
        }

        .secondary-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .stat-item {
            text-align: center;
            padding: 0.75rem;
            background: #f8fafc;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            transition: all 0.2s ease;
        }

        .stat-item:hover {
            background: #f1f5f9;
            border-color: #cbd5e1;
        }

        .stat-number {
            display: block;
            font-size: 1.25rem;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 0.25rem;
        }

        .stat-text {
            font-size: 0.75rem;
            color: #6b7280;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Card-specific styling */
        .sales-card .card-icon { background: linear-gradient(135deg, #10b981, #047857); }
        .monthly-card .card-icon { background: linear-gradient(135deg, #3b82f6, #1d4ed8); }
        .inventory-card .card-icon { background: linear-gradient(135deg, #f59e0b, #d97706); }
        .customers-card .card-icon { background: linear-gradient(135deg, #06b6d4, #0891b2); }
        .value-card .card-icon { background: linear-gradient(135deg, #8b5cf6, #7c3aed); }
        .activity-card .card-icon { background: linear-gradient(135deg, #ef4444, #dc2626); }

        /* Status colors for stat items */
        .stat-item.success {
            background: #f0fdf4;
            border-color: #bbf7d0;
        }

        .stat-item.success .stat-number {
            color: #16a34a;
        }

        .stat-item.warning {
            background: #fffbeb;
            border-color: #fed7aa;
        }

        .stat-item.warning .stat-number {
            color: #d97706;
        }

        .stat-item.danger {
            background: #fef2f2;
            border-color: #fecaca;
        }

        .stat-item.danger .stat-number {
            color: #dc2626;
        }

        /* Quick Links Grid Styles */
        .quick-links-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .quick-link-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            border: 1px solid #e5e7eb;
            position: relative;
            overflow: hidden;
        }

        .quick-link-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
        }

        .quick-link-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .quick-link-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
            background: linear-gradient(135deg, var(--primary-color), #8b5cf6);
        }

        .quick-link-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #1f2937;
        }

        .quick-link-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-item {
            text-align: center;
            padding: 0.75rem;
            background: #f8fafc;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }

        .stat-number {
            display: block;
            font-size: 1.5rem;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.875rem;
            color: #6b7280;
            font-weight: 500;
        }

        .quick-link-actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .quick-link-actions .btn {
            flex: 1;
            min-width: 120px;
            font-size: 0.875rem;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.2s ease;
        }

        /* Card-specific color themes */
        .pos-card .quick-link-icon { background: linear-gradient(135deg, #3b82f6, #1d4ed8); }
        .inventory-card .quick-link-icon { background: linear-gradient(135deg, #10b981, #047857); }
        .customers-card .quick-link-icon { background: linear-gradient(135deg, #06b6d4, #0891b2); }
        .categories-card .quick-link-icon { background: linear-gradient(135deg, #f59e0b, #d97706); }
        .suppliers-card .quick-link-icon { background: linear-gradient(135deg, #6b7280, #4b5563); }
        .quotations-card .quick-link-icon { background: linear-gradient(135deg, #8b5cf6, #7c3aed); }
        .expenses-card .quick-link-icon { background: linear-gradient(135deg, #ef4444, #dc2626); }
        .bom-card .quick-link-icon { background: linear-gradient(135deg, #22c55e, #16a34a); }
        .expiry-card .quick-link-icon { background: linear-gradient(135deg, #f59e0b, #d97706); }
        .reports-card .quick-link-icon { background: linear-gradient(135deg, #06b6d4, #0891b2); }
        .admin-card .quick-link-icon { background: linear-gradient(135deg, #374151, #1f2937); }

        /* Responsive adjustments */
        @media (max-width: 1024px) {
            .enhanced-stats-grid {
                grid-template-columns: repeat(2, 1fr);
                grid-template-rows: repeat(3, 1fr);
                gap: 1.25rem;
            }
        }

        @media (max-width: 768px) {
            .enhanced-stats-grid {
                grid-template-columns: 1fr;
                grid-template-rows: repeat(6, 1fr);
                gap: 1rem;
                margin-bottom: 2rem;
            }
            
            .enhanced-stat-card {
                padding: 1.25rem;
            }
            
            .primary-stat .currency,
            .primary-stat .number {
                font-size: 1.75rem;
            }
            
            .secondary-stats {
                grid-template-columns: 1fr;
                gap: 0.75rem;
            }
            
            .quick-links-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            
            .quick-link-card {
                padding: 1rem;
            }
            
            .quick-link-stats {
                grid-template-columns: 1fr;
                gap: 0.75rem;
            }
            
            .quick-link-actions {
                flex-direction: column;
            }
            
            .quick-link-actions .btn {
                min-width: auto;
            }
        }

        @media (max-width: 480px) {
            .enhanced-stat-card {
                padding: 1rem;
            }
            
            .card-header {
                margin-bottom: 1rem;
            }
            
            .card-icon {
                width: 40px;
                height: 40px;
                font-size: 1.25rem;
            }
            
            .primary-stat .currency,
            .primary-stat .number {
                font-size: 1.5rem;
            }
        }

        /* Section header styling */
        .section-header {
            margin-bottom: 1.5rem;
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 0.5rem;
        }

        /* Alert colors for stats */
        .text-warning { color: #f59e0b !important; }
        .text-success { color: #10b981 !important; }
        .text-danger { color: #ef4444 !important; }

        /* Currency formatting */
        .currency {
            font-family: 'Inter', monospace;
            font-weight: 600;
        }

        /* Enhanced Header Styles */
        .enhanced-header {
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            border-bottom: 1px solid #e5e7eb;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            position: sticky;
            top: 0;
            z-index: 100;
            backdrop-filter: blur(10px);
        }

        .header-container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem 2rem;
            max-width: 100%;
            gap: 2rem;
        }

        /* Left Section - Title */
        .header-left {
            flex: 1;
            min-width: 0;
        }

        .page-title {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .title-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, var(--primary-color), #8b5cf6);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
        }

        .title-content h1 {
            margin: 0;
            font-size: 1.75rem;
            font-weight: 700;
            color: #1f2937;
            line-height: 1.2;
        }

        .welcome-text {
            margin: 0.25rem 0 0 0;
            font-size: 0.95rem;
            color: #6b7280;
            font-weight: 400;
        }

        .username-highlight {
            color: var(--primary-color);
            font-weight: 600;
        }

        /* Center Section - Quick Stats */
        .header-center {
            flex: 1;
            display: flex;
            justify-content: center;
        }

        .quick-stats-bar {
            display: flex;
            gap: 1.5rem;
            background: white;
            padding: 0.75rem 1.5rem;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            border: 1px solid #e5e7eb;
        }

        .quick-stat-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.5rem 0;
        }

        .stat-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            color: white;
        }

        .stat-icon.success { background: linear-gradient(135deg, #10b981, #047857); }
        .stat-icon.info { background: linear-gradient(135deg, #3b82f6, #1d4ed8); }
        .stat-icon.warning { background: linear-gradient(135deg, #f59e0b, #d97706); }

        .stat-content {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
        }

        .stat-value {
            font-size: 0.95rem;
            font-weight: 700;
            color: #1f2937;
            line-height: 1;
        }

        .stat-label {
            font-size: 0.75rem;
            color: #6b7280;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Right Section - Actions */
        .header-right {
            flex: 1;
            display: flex;
            justify-content: flex-end;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .action-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        /* Notification Bell */
        .notification-bell {
            position: relative;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 8px;
            transition: background-color 0.2s ease;
        }

        .notification-bell:hover {
            background-color: #f3f4f6;
        }

        .notification-icon {
            position: relative;
            font-size: 1.25rem;
            color: #6b7280;
        }

        .notification-badge {
            position: absolute;
            top: -8px;
            right: -8px;
            background: #ef4444;
            color: white;
            font-size: 0.7rem;
            font-weight: 600;
            padding: 0.125rem 0.375rem;
            border-radius: 10px;
            min-width: 18px;
            text-align: center;
            line-height: 1;
        }

        /* Date Time Display */
        .datetime-display {
            background: white;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }

        .datetime-content {
            text-align: center;
        }

        .current-date {
            font-size: 0.8rem;
            color: #6b7280;
            font-weight: 500;
            line-height: 1;
        }

        .current-time {
            font-size: 0.9rem;
            color: #1f2937;
            font-weight: 600;
            margin-top: 0.125rem;
        }

        /* User Profile */
        .user-profile {
            background: white;
            padding: 0.75rem 1rem;
            border-radius: 12px;
            border: 1px solid #e5e7eb;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .user-profile:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
            transform: translateY(-1px);
        }

        .user-avatar-enhanced {
            position: relative;
        }

        .avatar-circle {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--primary-color), #8b5cf6);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .online-indicator {
            position: absolute;
            bottom: 2px;
            right: 2px;
            width: 12px;
            height: 12px;
            background: #10b981;
            border: 2px solid white;
            border-radius: 50%;
        }

        .user-details {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
        }

        .user-name {
            font-size: 0.9rem;
            font-weight: 600;
            color: #1f2937;
            line-height: 1;
        }

        .user-role {
            font-size: 0.75rem;
            color: #6b7280;
            font-weight: 500;
            margin-top: 0.125rem;
        }

        .dropdown-arrow {
            color: #9ca3af;
            font-size: 0.8rem;
            transition: transform 0.2s ease;
        }

        .user-profile:hover .dropdown-arrow {
            transform: rotate(180deg);
        }

        /* Responsive Header */
        @media (max-width: 1024px) {
            .header-center {
                display: none;
            }
            
            .header-container {
                gap: 1rem;
            }
        }

        @media (max-width: 768px) {
            .header-container {
                padding: 1rem;
                flex-direction: column;
                gap: 1rem;
            }
            
            .header-left,
            .header-right {
                flex: none;
                width: 100%;
            }
            
            .header-right {
                justify-content: center;
            }
            
            .page-title {
                justify-content: center;
            }
            
            .title-content h1 {
                font-size: 1.5rem;
            }
            
            .datetime-display {
                display: none;
            }
        }

        @media (max-width: 480px) {
            .header-actions {
                gap: 1rem;
            }
            
            .user-details {
                display: none;
            }
            
            .dropdown-arrow {
                display: none;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <?php
    $current_page = 'dashboard';
    include __DIR__ . '/../include/navmenu.php';
    ?>

    <div class="main-content">
        <!-- Enhanced Header -->
        <header class="enhanced-header">
            <div class="header-container">
                <!-- Left Section: Title & Welcome -->
                <div class="header-left">
                    <div class="page-title">
                        <div class="title-icon">
                            <i class="bi bi-speedometer2"></i>
                        </div>
                        <div class="title-content">
                            <h1>Dashboard</h1>
                            <p class="welcome-text">Welcome back, <span class="username-highlight"><?php echo htmlspecialchars($username); ?></span>!</p>
                        </div>
                    </div>
                </div>

                <!-- Center Section: Quick Stats -->
                <div class="header-center">
                    <div class="quick-stats-bar">
                        <div class="quick-stat-item">
                            <div class="stat-icon success">
                                <i class="bi bi-graph-up-arrow"></i>
                            </div>
                            <div class="stat-content">
                                <span class="stat-value"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo formatLargeNumber($stats['today_sales_total']); ?></span>
                                <span class="stat-label">Today</span>
                            </div>
                        </div>
                        <div class="quick-stat-item">
                            <div class="stat-icon info">
                                <i class="bi bi-box"></i>
                            </div>
                            <div class="stat-content">
                                <span class="stat-value"><?php echo formatLargeNumber($inventory_stats['total_products_count']); ?></span>
                                <span class="stat-label">Products</span>
                            </div>
                        </div>
                        <div class="quick-stat-item">
                            <div class="stat-icon warning">
                                <i class="bi bi-exclamation-triangle"></i>
                            </div>
                            <div class="stat-content">
                                <span class="stat-value"><?php echo formatLargeNumber($inventory_stats['low_stock']); ?></span>
                                <span class="stat-label">Low Stock</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Section: User Info & Actions -->
                <div class="header-right">
                    <div class="header-actions">
                        <!-- Notifications -->
                        <div class="action-item notification-bell">
                            <div class="notification-icon">
                                <i class="bi bi-bell"></i>
                                <?php if (($inventory_stats['low_stock'] + $stats['on_hold_count'] + $quick_stats['near_expiry_count']) > 0): ?>
                                <span class="notification-badge"><?php echo ($inventory_stats['low_stock'] + $stats['on_hold_count'] + $quick_stats['near_expiry_count']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Current Date & Time -->
                        <div class="action-item datetime-display">
                            <div class="datetime-content">
                                <div class="current-date"><?php echo date('M j, Y'); ?></div>
                                <div class="current-time" id="current-time"><?php echo date('g:i A'); ?></div>
                            </div>
                        </div>

                        <!-- User Profile -->
                        <div class="action-item user-profile">
                            <div class="user-avatar-enhanced">
                                <div class="avatar-circle">
                                    <?php echo strtoupper(substr($username, 0, 2)); ?>
                                </div>
                                <div class="online-indicator"></div>
                            </div>
                            <div class="user-details">
                                <div class="user-name"><?php echo htmlspecialchars($username); ?></div>
                                <div class="user-role"><?php echo htmlspecialchars($role_name); ?></div>
                            </div>
                            <div class="dropdown-arrow">
                                <i class="bi bi-chevron-down"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- Content -->
        <main class="content">
            <!-- Error Messages -->
            <?php if (isset($_SESSION['logout_error'])): ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <?php echo htmlspecialchars($_SESSION['logout_error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['logout_error']); ?>
            <?php endif; ?>
            
            <!-- Enhanced Statistics Cards -->
            <div class="enhanced-stats-grid">
                <!-- Today's Sales Card -->
                <div class="enhanced-stat-card sales-card">
                    <div class="card-header">
                        <div class="card-icon">
                            <i class="bi bi-cash-coin"></i>
                        </div>
                        <div class="card-title">Today's Sales</div>
                    </div>
                    <div class="card-content">
                        <div class="primary-stat">
                            <span class="currency"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo formatLargeNumber($stats['today_sales_total']); ?></span>
                        </div>
                        <div class="secondary-stats">
                            <div class="stat-item">
                                <span class="stat-number"><?php echo formatLargeNumber($stats['today_sales_count']); ?></span>
                                <span class="stat-text">Transactions</span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-number"><?php echo formatLargeNumber($stats['items_sold_today']); ?></span>
                                <span class="stat-text">Items Sold</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Monthly Performance Card -->
                <div class="enhanced-stat-card monthly-card">
                    <div class="card-header">
                        <div class="card-icon">
                            <i class="bi bi-calendar-month"></i>
                        </div>
                        <div class="card-title">Monthly Performance</div>
                    </div>
                    <div class="card-content">
                        <div class="primary-stat">
                            <span class="currency"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo formatLargeNumber($stats['month_sales_total']); ?></span>
                        </div>
                        <div class="secondary-stats">
                            <div class="stat-item">
                                <span class="stat-number"><?php echo formatLargeNumber($stats['month_sales_count']); ?></span>
                                <span class="stat-text">Sales</span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-number currency"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo formatLargeNumber($stats['avg_ticket']); ?></span>
                                <span class="stat-text">Avg Ticket</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Inventory Overview Card -->
                <div class="enhanced-stat-card inventory-card">
                    <div class="card-header">
                        <div class="card-icon">
                            <i class="bi bi-boxes"></i>
                        </div>
                        <div class="card-title">Inventory Overview</div>
                    </div>
                    <div class="card-content">
                        <div class="primary-stat">
                            <span class="number"><?php echo formatLargeNumber($inventory_stats['total_products_count']); ?></span>
                            <span class="label">Products</span>
                        </div>
                        <div class="secondary-stats">
                            <div class="stat-item <?php echo $inventory_stats['low_stock'] > 0 ? 'warning' : 'success'; ?>">
                                <span class="stat-number"><?php echo formatLargeNumber($inventory_stats['low_stock']); ?></span>
                                <span class="stat-text">Low Stock</span>
                            </div>
                            <div class="stat-item <?php echo $inventory_stats['out_of_stock'] > 0 ? 'danger' : 'success'; ?>">
                                <span class="stat-number"><?php echo formatLargeNumber($inventory_stats['out_of_stock']); ?></span>
                                <span class="stat-text">Out of Stock</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Customer Insights Card -->
                <div class="enhanced-stat-card customers-card">
                    <div class="card-header">
                        <div class="card-icon">
                            <i class="bi bi-people"></i>
                        </div>
                        <div class="card-title">Customer Insights</div>
                    </div>
                    <div class="card-content">
                        <div class="primary-stat">
                            <span class="number"><?php echo formatLargeNumber($stats['total_customers']); ?></span>
                            <span class="label">Customers</span>
                        </div>
                        <div class="secondary-stats">
                            <div class="stat-item">
                                <span class="stat-number"><?php echo formatLargeNumber($stats['new_customers_today_count']); ?></span>
                                <span class="stat-text">New Today</span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-number"><?php echo formatLargeNumber($sales_stats['total_sales'] ?? 0); ?></span>
                                <span class="stat-text">Monthly Sales</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- NEW: Inventory Value Card -->
                <div class="enhanced-stat-card value-card">
                    <div class="card-header">
                        <div class="card-icon">
                            <i class="bi bi-currency-dollar"></i>
                        </div>
                        <div class="card-title">Inventory Value</div>
                    </div>
                    <div class="card-content">
                        <div class="primary-stat">
                            <span class="currency"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo formatLargeNumber($inventory_stats['total_retail_value']); ?></span>
                        </div>
                        <div class="secondary-stats">
                            <div class="stat-item">
                                <span class="stat-number currency"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo formatLargeNumber($inventory_stats['total_inventory_value']); ?></span>
                                <span class="stat-text">Cost Value</span>
                            </div>
                            <div class="stat-item success">
                                <span class="stat-number"><?php echo $inventory_stats['total_retail_value'] > 0 ? number_format((($inventory_stats['total_retail_value'] - $inventory_stats['total_inventory_value']) / $inventory_stats['total_retail_value']) * 100, 1) : 0; ?>%</span>
                                <span class="stat-text">Margin</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- NEW: System Activity Card -->
                <div class="enhanced-stat-card activity-card">
                    <div class="card-header">
                        <div class="card-icon">
                            <i class="bi bi-activity"></i>
                        </div>
                        <div class="card-title">System Activity</div>
                    </div>
                    <div class="card-content">
                        <div class="primary-stat">
                            <span class="number"><?php echo formatLargeNumber($quick_stats['active_tills']); ?></span>
                            <span class="label">Active Tills</span>
                        </div>
                        <div class="secondary-stats">
                            <div class="stat-item <?php echo $stats['on_hold_count'] > 0 ? 'warning' : 'success'; ?>">
                                <span class="stat-number"><?php echo formatLargeNumber($stats['on_hold_count'] ?? 0); ?></span>
                                <span class="stat-text">On Hold</span>
                            </div>
                            <div class="stat-item <?php echo $stats['voids_count_today'] > 0 ? 'danger' : 'success'; ?>">
                                <span class="stat-number"><?php echo formatLargeNumber($stats['voids_count_today'] ?? 0); ?></span>
                                <span class="stat-text">Voids Today</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Links Section -->
            <div class="row g-3 mb-4">
                <div class="col-12">
                    <div class="section-header">
                        <h3 class="section-title">Quick Access</h3>
                        <p class="text-muted mb-0">Navigate to key sections with current counts</p>
                    </div>
                </div>
            </div>

            <!-- Quick Links Grid -->
            <div class="quick-links-grid mb-4">
                <!-- POS Section -->
                <?php if (hasPermission('process_sales', $permissions)): ?>
                <div class="quick-link-card pos-card">
                    <div class="quick-link-header">
                        <div class="quick-link-icon">
                            <i class="bi bi-cart-plus"></i>
                        </div>
                        <div class="quick-link-title">Point of Sale</div>
                    </div>
                    <div class="quick-link-stats">
                        <div class="stat-item">
                            <span class="stat-number"><?php echo $stats['today_sales_count']; ?></span>
                            <span class="stat-label">Sales Today</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-number"><?php echo $quick_stats['active_tills']; ?></span>
                            <span class="stat-label">Active Tills</span>
                        </div>
                    </div>
                    <div class="quick-link-actions">
                        <a href="<?php echo url('pos/sale.php'); ?>" class="btn btn-primary btn-sm">
                            <i class="bi bi-cart-plus"></i> New Sale
                        </a>
                        <a href="<?php echo url('sales/index.php'); ?>" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-list"></i> View Sales
                        </a>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Inventory Section -->
                <?php if (hasPermission('manage_inventory', $permissions) || hasPermission('manage_products', $permissions)): ?>
                <div class="quick-link-card inventory-card">
                    <div class="quick-link-header">
                        <div class="quick-link-icon">
                            <i class="bi bi-boxes"></i>
                        </div>
                        <div class="quick-link-title">Inventory</div>
                    </div>
                    <div class="quick-link-stats">
                        <div class="stat-item">
                            <span class="stat-number"><?php echo number_format($stats['total_products']); ?></span>
                            <span class="stat-label">Products</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-number <?php echo $stats['low_stock'] > 0 ? 'text-warning' : 'text-success'; ?>"><?php echo $stats['low_stock']; ?></span>
                            <span class="stat-label">Low Stock</span>
                        </div>
                    </div>
                    <div class="quick-link-actions">
                        <a href="<?php echo url('products/add.php'); ?>" class="btn btn-success btn-sm">
                            <i class="bi bi-plus-circle"></i> Add Product
                        </a>
                        <a href="<?php echo url('inventory/inventory.php'); ?>" class="btn btn-outline-success btn-sm">
                            <i class="bi bi-boxes"></i> Manage
                        </a>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Customers Section -->
                <?php if (hasPermission('manage_customers', $permissions) || hasPermission('view_customers', $permissions)): ?>
                <div class="quick-link-card customers-card">
                    <div class="quick-link-header">
                        <div class="quick-link-icon">
                            <i class="bi bi-people"></i>
                        </div>
                        <div class="quick-link-title">Customers</div>
                    </div>
                    <div class="quick-link-stats">
                        <div class="stat-item">
                            <span class="stat-number"><?php echo number_format($stats['total_customers']); ?></span>
                            <span class="stat-label">Total</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-number"><?php echo $stats['new_customers_today_count']; ?></span>
                            <span class="stat-label">New Today</span>
                        </div>
                    </div>
                    <div class="quick-link-actions">
                        <a href="<?php echo url('customers/add.php'); ?>" class="btn btn-info btn-sm">
                            <i class="bi bi-person-plus"></i> Add Customer
                        </a>
                        <a href="<?php echo url('customers/index.php'); ?>" class="btn btn-outline-info btn-sm">
                            <i class="bi bi-people"></i> View All
                        </a>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Categories & Organization -->
                <?php if (hasPermission('manage_categories', $permissions)): ?>
                <div class="quick-link-card categories-card">
                    <div class="quick-link-header">
                        <div class="quick-link-icon">
                            <i class="bi bi-tags"></i>
                        </div>
                        <div class="quick-link-title">Organization</div>
                    </div>
                    <div class="quick-link-stats">
                        <div class="stat-item">
                            <span class="stat-number"><?php echo $quick_stats['total_categories']; ?></span>
                            <span class="stat-label">Categories</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-number"><?php echo $quick_stats['total_brands']; ?></span>
                            <span class="stat-label">Brands</span>
                        </div>
                    </div>
                    <div class="quick-link-actions">
                        <a href="<?php echo url('categories/categories.php'); ?>" class="btn btn-warning btn-sm">
                            <i class="bi bi-tags"></i> Categories
                        </a>
                        <a href="<?php echo url('brands/brands.php'); ?>" class="btn btn-outline-warning btn-sm">
                            <i class="bi bi-star"></i> Brands
                        </a>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Suppliers Section -->
                <?php if (hasPermission('manage_product_suppliers', $permissions)): ?>
                <div class="quick-link-card suppliers-card">
                    <div class="quick-link-header">
                        <div class="quick-link-icon">
                            <i class="bi bi-truck"></i>
                        </div>
                        <div class="quick-link-title">Suppliers</div>
                    </div>
                    <div class="quick-link-stats">
                        <div class="stat-item">
                            <span class="stat-number"><?php echo $quick_stats['total_suppliers']; ?></span>
                            <span class="stat-label">Active</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-number">-</span>
                            <span class="stat-label">Orders</span>
                        </div>
                    </div>
                    <div class="quick-link-actions">
                        <a href="<?php echo url('suppliers/add.php'); ?>" class="btn btn-secondary btn-sm">
                            <i class="bi bi-plus-circle"></i> Add Supplier
                        </a>
                        <a href="<?php echo url('suppliers/suppliers.php'); ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-truck"></i> View All
                        </a>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Quotations Section -->
                <?php if (hasPermission('manage_quotations', $permissions) || hasPermission('view_quotations', $permissions)): ?>
                <div class="quick-link-card quotations-card">
                    <div class="quick-link-header">
                        <div class="quick-link-icon">
                            <i class="bi bi-file-earmark-text"></i>
                        </div>
                        <div class="quick-link-title">Quotations</div>
                    </div>
                    <div class="quick-link-stats">
                        <div class="stat-item">
                            <span class="stat-number"><?php echo $quick_stats['pending_quotations']; ?></span>
                            <span class="stat-label">Pending</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-number">-</span>
                            <span class="stat-label">This Month</span>
                        </div>
                    </div>
                    <div class="quick-link-actions">
                        <a href="<?php echo url('quotations/quotation.php?action=create'); ?>" class="btn btn-primary btn-sm">
                            <i class="bi bi-plus-circle"></i> New Quote
                        </a>
                        <a href="<?php echo url('quotations/quotations.php'); ?>" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-list"></i> View All
                        </a>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Expenses Section -->
                <?php if (hasPermission('create_expenses', $permissions) || hasPermission('view_expense_reports', $permissions)): ?>
                <div class="quick-link-card expenses-card">
                    <div class="quick-link-header">
                        <div class="quick-link-icon">
                            <i class="bi bi-cash-stack"></i>
                        </div>
                        <div class="quick-link-title">Expenses</div>
                    </div>
                    <div class="quick-link-stats">
                        <div class="stat-item">
                            <span class="stat-number"><?php echo $quick_stats['month_expenses_count']; ?></span>
                            <span class="stat-label">This Month</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-number currency"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($quick_stats['month_expenses_total'], 0); ?></span>
                            <span class="stat-label">Total</span>
                        </div>
                    </div>
                    <div class="quick-link-actions">
                        <a href="<?php echo url('expenses/add.php'); ?>" class="btn btn-danger btn-sm">
                            <i class="bi bi-plus-circle"></i> Add Expense
                        </a>
                        <a href="<?php echo url('expenses/index.php'); ?>" class="btn btn-outline-danger btn-sm">
                            <i class="bi bi-list"></i> View All
                        </a>
                    </div>
                </div>
                <?php endif; ?>

                <!-- BOM Section -->
                <?php if (hasPermission('view_boms', $permissions) || hasPermission('create_boms', $permissions)): ?>
                <div class="quick-link-card bom-card">
                    <div class="quick-link-header">
                        <div class="quick-link-icon">
                            <i class="bi bi-diagram-3"></i>
                        </div>
                        <div class="quick-link-title">Bill of Materials</div>
                    </div>
                    <div class="quick-link-stats">
                        <div class="stat-item">
                            <span class="stat-number"><?php echo $quick_stats['active_boms']; ?></span>
                            <span class="stat-label">Active BOMs</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-number">-</span>
                            <span class="stat-label">Production</span>
                        </div>
                    </div>
                    <div class="quick-link-actions">
                        <a href="<?php echo url('bom/add.php'); ?>" class="btn btn-success btn-sm">
                            <i class="bi bi-plus-circle"></i> Create BOM
                        </a>
                        <a href="<?php echo url('bom/index.php'); ?>" class="btn btn-outline-success btn-sm">
                            <i class="bi bi-list"></i> View All
                        </a>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Expiry Tracker -->
                <?php if (hasPermission('view_expiry_alerts', $permissions) || hasPermission('manage_expiry_tracker', $permissions)): ?>
                <div class="quick-link-card expiry-card">
                    <div class="quick-link-header">
                        <div class="quick-link-icon">
                            <i class="bi bi-clock-history"></i>
                        </div>
                        <div class="quick-link-title">Expiry Tracker</div>
                    </div>
                    <div class="quick-link-stats">
                        <div class="stat-item">
                            <span class="stat-number <?php echo $quick_stats['near_expiry_count'] > 0 ? 'text-warning' : 'text-success'; ?>"><?php echo $quick_stats['near_expiry_count']; ?></span>
                            <span class="stat-label">Near Expiry</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-number">30</span>
                            <span class="stat-label">Days Alert</span>
                        </div>
                    </div>
                    <div class="quick-link-actions">
                        <a href="<?php echo url('expiry_tracker/add_expiry_date.php'); ?>" class="btn btn-warning btn-sm">
                            <i class="bi bi-plus-circle"></i> Add Expiry
                        </a>
                        <a href="<?php echo url('expiry_tracker/expiry_tracker.php'); ?>" class="btn btn-outline-warning btn-sm">
                            <i class="bi bi-clock-history"></i> View All
                        </a>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Reports Section -->
                <?php if (hasPermission('view_reports', $permissions) || hasPermission('view_analytics', $permissions)): ?>
                <div class="quick-link-card reports-card">
                    <div class="quick-link-header">
                        <div class="quick-link-icon">
                            <i class="bi bi-graph-up"></i>
                        </div>
                        <div class="quick-link-title">Reports & Analytics</div>
                    </div>
                    <div class="quick-link-stats">
                        <div class="stat-item">
                            <span class="stat-number">15+</span>
                            <span class="stat-label">Report Types</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-number">-</span>
                            <span class="stat-label">Analytics</span>
                        </div>
                    </div>
                    <div class="quick-link-actions">
                        <a href="<?php echo url('reports/sales_reports.php'); ?>" class="btn btn-info btn-sm">
                            <i class="bi bi-graph-up"></i> Sales Reports
                        </a>
                        <a href="<?php echo url('analytics/index.php'); ?>" class="btn btn-outline-info btn-sm">
                            <i class="bi bi-bar-chart"></i> Analytics
                        </a>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Admin Section -->
                <?php if (hasPermission('manage_users', $permissions) || hasPermission('manage_settings', $permissions)): ?>
                <div class="quick-link-card admin-card">
                    <div class="quick-link-header">
                        <div class="quick-link-icon">
                            <i class="bi bi-gear"></i>
                        </div>
                        <div class="quick-link-title">Administration</div>
                    </div>
                    <div class="quick-link-stats">
                        <div class="stat-item">
                            <span class="stat-number"><?php echo $quick_stats['total_users']; ?></span>
                            <span class="stat-label">Users</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-number">-</span>
                            <span class="stat-label">Settings</span>
                        </div>
                    </div>
                    <div class="quick-link-actions">
                        <a href="<?php echo url('dashboard/users/index.php'); ?>" class="btn btn-dark btn-sm">
                            <i class="bi bi-people"></i> Users
                        </a>
                        <a href="<?php echo url('admin/settings/adminsetting.php'); ?>" class="btn btn-outline-dark btn-sm">
                            <i class="bi bi-gear"></i> Settings
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/dashboard.js"></script>
    
    <!-- Enhanced Header JavaScript -->
    <script>
        // Update time every second
        function updateTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('en-US', { 
                hour: 'numeric', 
                minute: '2-digit',
                hour12: true 
            });
            
            const timeElement = document.getElementById('current-time');
            if (timeElement) {
                timeElement.textContent = timeString;
            }
        }

        // Update time immediately and then every second
        updateTime();
        setInterval(updateTime, 1000);

        // Add click handler for notification bell
        document.addEventListener('DOMContentLoaded', function() {
            const notificationBell = document.querySelector('.notification-bell');
            if (notificationBell) {
                notificationBell.addEventListener('click', function() {
                    // You can add notification dropdown functionality here
                    console.log('Notifications clicked');
                });
            }

            // Add click handler for user profile
            const userProfile = document.querySelector('.user-profile');
            if (userProfile) {
                userProfile.addEventListener('click', function() {
                    // You can add user menu dropdown functionality here
                    console.log('User profile clicked');
                });
            }
        });
    </script>
</body>
</html>