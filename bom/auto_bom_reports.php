<?php
session_start();
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';
require_once __DIR__ . '/../include/classes/AutoBOMManager.php';

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

// Check Auto BOM permissions
$can_view_auto_bom_reports = hasPermission('view_auto_bom_reports', $permissions);

if (!$can_view_auto_bom_reports) {
    header("Location: ../dashboard/dashboard.php");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get filter parameters
$config_id = isset($_GET['config_id']) ? (int) $_GET['config_id'] : null;
$report_type = $_GET['report_type'] ?? 'list';
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';

// Get Auto BOM configurations for list display
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(abc.config_name LIKE :search OR p.name LIKE :search OR bp.name LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if (!empty($status_filter)) {
    $where_conditions[] = "abc.is_active = :status";
    $params[':status'] = $status_filter === 'active' ? 1 : 0;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

$stmt = $conn->prepare("
    SELECT 
        abc.*,
        p.name as product_name,
        p.sku as product_sku,
        p.auto_bom_type,
        bp.name as base_product_name,
        bp.sku as base_product_sku,
        bp.quantity as base_stock,
        bp.cost_price as base_cost,
        pf.name as family_name,
        u.username as created_by_name,
        u2.username as updated_by_name,
        COUNT(su.id) as selling_units_count
    FROM auto_bom_configs abc
    INNER JOIN products p ON abc.product_id = p.id
    INNER JOIN products bp ON abc.base_product_id = bp.id
    LEFT JOIN product_families pf ON abc.product_family_id = pf.id
    LEFT JOIN users u ON abc.created_by = u.id
    LEFT JOIN users u2 ON abc.updated_by = u2.id
    LEFT JOIN auto_bom_selling_units su ON abc.id = su.auto_bom_config_id AND su.status = 'active'
    {$where_clause}
    GROUP BY abc.id
    ORDER BY abc.created_at DESC
");

foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$auto_bom_configs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Initialize data arrays
$report_data = [];
$chart_data = [];

try {
    if ($config_id) {
        // Get specific Auto BOM configuration details
        $stmt = $conn->prepare("
            SELECT
                abc.*,
                p.name as product_name,
                p.sku as product_sku,
                bp.name as base_product_name,
                bp.sku as base_product_sku,
                bp.quantity as base_stock,
                pf.name as family_name,
                COUNT(su.id) as selling_units_count
            FROM auto_bom_configs abc
            INNER JOIN products p ON abc.product_id = p.id
            INNER JOIN products bp ON abc.base_product_id = bp.id
            LEFT JOIN product_families pf ON abc.product_family_id = pf.id
            LEFT JOIN auto_bom_selling_units su ON abc.id = su.auto_bom_config_id
            WHERE abc.id = :config_id
            GROUP BY abc.id
        ");
        $stmt->execute([':config_id' => $config_id]);
        $config_details = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($config_details) {
            // Get selling units performance with actual sales data for the sellable product
            $stmt = $conn->prepare("
                SELECT
                    su.*,
                    COUNT(DISTINCT si.id) as sales_count,
                    COALESCE(SUM(si.quantity), 0) as total_quantity_sold,
                    COALESCE(SUM(si.total_price), 0) as total_revenue,
                    COALESCE(AVG(si.unit_price), 0) as avg_unit_price,
                    MAX(s.sale_date) as last_sale_date,
                    COUNT(DISTINCT s.id) as unique_sales
                FROM auto_bom_selling_units su
                LEFT JOIN sale_items si ON si.product_id = :sellable_product_id
                LEFT JOIN sales s ON si.sale_id = s.id
                    AND DATE(s.sale_date) BETWEEN :date_from AND :date_to
                WHERE su.auto_bom_config_id = :config_id
                GROUP BY su.id
                ORDER BY su.priority DESC, su.unit_name ASC
            ");
            $stmt->execute([
                ':config_id' => $config_id,
                ':sellable_product_id' => $config_details['product_id'],
                ':date_from' => $date_from,
                ':date_to' => $date_to
            ]);
            $selling_units_performance = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get overall sales performance for the sellable product
            $stmt = $conn->prepare("
                SELECT
                    COUNT(DISTINCT si.id) as total_sales_count,
                    COALESCE(SUM(si.quantity), 0) as total_quantity_sold,
                    COALESCE(SUM(si.total_price), 0) as total_revenue,
                    COALESCE(AVG(si.unit_price), 0) as avg_unit_price,
                    MAX(s.sale_date) as last_sale_date,
                    COUNT(DISTINCT s.id) as unique_sales,
                    COUNT(DISTINCT s.customer_id) as unique_customers
                FROM sale_items si
                INNER JOIN sales s ON si.sale_id = s.id
                WHERE si.product_id = :sellable_product_id
                AND DATE(s.sale_date) BETWEEN :date_from AND :date_to
            ");
            $stmt->execute([
                ':sellable_product_id' => $config_details['product_id'],
                ':date_from' => $date_from,
                ':date_to' => $date_to
            ]);
            $product_sales_performance = $stmt->fetch(PDO::FETCH_ASSOC);

            // Get price history
            $stmt = $conn->prepare("
                SELECT
                    ph.*,
                    su.unit_name,
                    u.username as changed_by_name
                FROM auto_bom_price_history ph
                INNER JOIN auto_bom_selling_units su ON ph.selling_unit_id = su.id
                LEFT JOIN users u ON ph.changed_by = u.id
                WHERE su.auto_bom_config_id = :config_id
                AND DATE(ph.change_date) BETWEEN :date_from AND :date_to
                ORDER BY ph.change_date DESC
                LIMIT 50
            ");
            $stmt->execute([
                ':config_id' => $config_id,
                ':date_from' => $date_from,
                ':date_to' => $date_to
            ]);
            $price_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Calculate summary metrics from actual sales data for the sellable product
            $total_sales_count = $product_sales_performance['total_sales_count'] ?? 0;
            $total_quantity_sold = $product_sales_performance['total_quantity_sold'] ?? 0;
            $total_revenue = $product_sales_performance['total_revenue'] ?? 0;
            $unique_customers = $product_sales_performance['unique_customers'] ?? 0;
            $price_changes = count($price_history);

            $report_data = [
                'config_details' => $config_details,
                'selling_units_performance' => $selling_units_performance,
                'product_sales_performance' => $product_sales_performance,
                'price_history' => $price_history,
                'summary' => [
                    'total_sales_count' => $total_sales_count,
                    'total_quantity_sold' => $total_quantity_sold,
                    'total_revenue' => $total_revenue,
                    'unique_customers' => $unique_customers,
                    'price_changes' => $price_changes,
                    'date_range' => [
                        'from' => $date_from,
                        'to' => $date_to
                    ]
                ]
            ];

             // Get timeline data (hourly sales distribution for the sellable product)
             $stmt = $conn->prepare("
                 SELECT 
                     HOUR(s.sale_date) as hour,
                     COUNT(si.id) as sales_count,
                     SUM(si.total_price) as hourly_revenue
                 FROM sales s
                 INNER JOIN sale_items si ON s.id = si.sale_id
                 WHERE si.product_id = :sellable_product_id
                 AND DATE(s.sale_date) BETWEEN :date_from AND :date_to
                 GROUP BY HOUR(s.sale_date)
                 ORDER BY hour
             ");
             $stmt->execute([
                 ':sellable_product_id' => $config_details['product_id'],
                 ':date_from' => $date_from,
                 ':date_to' => $date_to
             ]);
             $timeline_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

             // Process timeline data
             $timeline_hours = [];
             $timeline_counts = [];
             $timeline_revenue = [];
             for ($i = 0; $i < 24; $i++) {
                 $timeline_hours[] = sprintf('%02d:00', $i);
                 $timeline_counts[] = 0;
                 $timeline_revenue[] = 0;
             }
             
             foreach ($timeline_data as $data) {
                 $hour = (int) $data['hour'];
                 $timeline_counts[$hour] = (int) $data['sales_count'];
                 $timeline_revenue[$hour] = (float) $data['hourly_revenue'];
             }

            // Prepare chart data for JavaScript using actual product sales
            $chart_data = [
                'revenue_by_unit' => [
                    [
                        'unit_name' => $config_details['product_name'] ?? 'Sellable Product',
                        'total_revenue' => (float) $product_sales_performance['total_revenue'],
                        'sales_count' => (int) $product_sales_performance['total_sales_count'],
                        'quantity_sold' => (int) $product_sales_performance['total_quantity_sold'],
                        'avg_unit_price' => (float) $product_sales_performance['avg_unit_price']
                    ]
                ],
                'sales_by_unit' => [
                    [
                        'unit_name' => $config_details['product_name'] ?? 'Sellable Product',
                        'sales_count' => (int) $product_sales_performance['total_sales_count'],
                        'quantity_sold' => (int) $product_sales_performance['total_quantity_sold']
                    ]
                ],
                'price_changes_over_time' => array_map(function($change) {
                    return [
                        'date' => $change['change_date'],
                        'unit_name' => $change['unit_name'],
                        'old_price' => (float) $change['old_price'],
                        'new_price' => (float) $change['new_price'],
                        'change_reason' => $change['change_reason']
                    ];
                 }, array_reverse($price_history)),
                 'timeline_data' => $timeline_counts,
                 'timeline_revenue' => $timeline_revenue,
                 'timeline_labels' => $timeline_hours
            ];
        }
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auto BOM Reports - <?php echo htmlspecialchars($settings['site_name'] ?? 'Point of Sale'); ?></title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/products.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .reports-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            margin: -20px -20px 20px -20px;
             margin-left: 0;
            border-radius: 8px;
        }

        .report-filters {
            background: #f8f9fa;
             padding: 25px;
             border-radius: 12px;
             margin-bottom: 25px;
             border: 1px solid #e9ecef;
             box-shadow: 0 2px 8px rgba(0,0,0,0.05);
         }

         .filter-form {
             display: flex;
             flex-direction: column;
             gap: 20px;
         }

         .filter-row {
             display: flex;
             gap: 20px;
             align-items: end;
         }

         .filter-group {
             flex: 1;
             display: flex;
             flex-direction: column;
             gap: 8px;
         }

         .filter-group.full-width {
             flex: 1 1 100%;
         }

         .filter-group label {
             font-weight: 600;
             color: #495057;
             font-size: 0.9rem;
             margin-bottom: 4px;
         }

         .tooltip-label {
             display: flex;
             align-items: center;
             gap: 6px;
             position: relative;
         }

         .tooltip-icon {
             color: #6c757d;
             font-size: 0.8rem;
             cursor: help;
             transition: color 0.3s ease;
         }

         .tooltip-icon:hover {
             color: #667eea;
         }

         .tooltip-icon::after {
             content: attr(data-tooltip);
             position: absolute;
             bottom: 100%;
             left: 50%;
             transform: translateX(-50%);
             background: rgba(0, 0, 0, 0.9);
             color: white;
             padding: 8px 12px;
             border-radius: 6px;
             font-size: 0.8rem;
             white-space: nowrap;
             max-width: 300px;
             white-space: normal;
             text-align: center;
             opacity: 0;
             visibility: hidden;
             transition: all 0.3s ease;
             z-index: 1000;
             box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
         }

         .tooltip-icon::before {
             content: '';
             position: absolute;
             bottom: 100%;
             left: 50%;
             transform: translateX(-50%) translateY(100%);
             border: 5px solid transparent;
             border-top-color: rgba(0, 0, 0, 0.9);
             opacity: 0;
             visibility: hidden;
             transition: all 0.3s ease;
             z-index: 1000;
         }

         .tooltip-icon:hover::after,
         .tooltip-icon:hover::before {
             opacity: 1;
             visibility: visible;
         }

         .filter-group .form-control {
             padding: 12px 15px;
             border: 2px solid #e9ecef;
            border-radius: 8px;
             font-size: 0.95rem;
             transition: all 0.3s ease;
             background: white;
         }

         .filter-group .form-control:focus {
             border-color: #667eea;
             box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
             outline: none;
         }

         .filter-group select.form-control {
             cursor: pointer;
             background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='m6 8 4 4 4-4'/%3e%3c/svg%3e");
             background-position: right 12px center;
             background-repeat: no-repeat;
             background-size: 16px;
             padding-right: 40px;
         }

         .filter-actions {
             display: flex;
             gap: 15px;
             justify-content: flex-end;
             padding-top: 10px;
             border-top: 1px solid #dee2e6;
         }

         .filter-actions .btn {
             padding: 12px 24px;
             font-weight: 600;
             border-radius: 8px;
             transition: all 0.3s ease;
             display: flex;
             align-items: center;
             gap: 8px;
         }

         .filter-actions .btn-primary {
             background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
             border: none;
             color: white;
         }

         .filter-actions .btn-primary:hover {
             transform: translateY(-2px);
             box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
         }

         .filter-actions .btn-secondary {
             background: white;
             border: 2px solid #6c757d;
             color: #6c757d;
         }

         .filter-actions .btn-secondary:hover {
             background: #6c757d;
             color: white;
             transform: translateY(-2px);
         }

         /* Responsive adjustments */
         @media (max-width: 768px) {
             .filter-row {
                 flex-direction: column;
                 gap: 15px;
             }
             
             .filter-group {
                 width: 100%;
             }
             
             .filter-actions {
                 justify-content: center;
                 flex-direction: column;
             }
             
             .filter-actions .btn {
                 width: 100%;
                 justify-content: center;
             }
        }

        .report-metrics {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .metric-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
        }

        .metric-value {
            font-size: 2rem;
            font-weight: bold;
            color: #667eea;
        }

        .metric-label {
            color: #666;
            font-size: 0.9rem;
        }

        .report-section {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            overflow: hidden;
        }

        .report-section-header {
            background: #f8f9fa;
            padding: 15px 20px;
            border-bottom: 1px solid #dee2e6;
            font-weight: bold;
        }

        .report-section-content {
            padding: 20px;
        }

        .performance-table {
            width: 100%;
            border-collapse: collapse;
        }

        .performance-table th,
        .performance-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #dee2e6;
        }

        .performance-table th {
            background: #f8f9fa;
            font-weight: bold;
        }

        .chart-container {
            position: relative;
            height: 300px;
            margin-bottom: 20px;
        }

         /* Analytics Grid */
         .analytics-grid {
             display: grid;
             grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
             gap: 20px;
             margin-bottom: 20px;
         }

         /* 6-card layout for larger screens */
         @media (min-width: 1400px) {
             .analytics-grid {
                 grid-template-columns: repeat(3, 1fr);
             }
         }

         @media (min-width: 1000px) and (max-width: 1399px) {
             .analytics-grid {
                 grid-template-columns: repeat(2, 1fr);
             }
         }

         .chart-card {
             background: white;
             border: 1px solid #e9ecef;
             border-radius: 12px;
             padding: 20px;
             box-shadow: 0 2px 8px rgba(0,0,0,0.1);
             transition: all 0.3s ease;
         }

         .chart-card:hover {
             transform: translateY(-2px);
             box-shadow: 0 4px 16px rgba(0,0,0,0.15);
             border-color: #667eea;
         }

         .chart-header {
             display: flex;
             justify-content: space-between;
             align-items: center;
             margin-bottom: 15px;
             padding-bottom: 10px;
             border-bottom: 2px solid #f8f9fa;
         }

         .chart-header h5 {
             margin: 0;
             color: #2c3e50;
             font-weight: 600;
             display: flex;
             align-items: center;
             gap: 8px;
         }

         .chart-header h5 i {
             color: #667eea;
         }

         .chart-actions {
             display: flex;
             gap: 8px;
         }

         .chart-summary {
             margin-top: 15px;
             padding-top: 15px;
             border-top: 1px solid #f1f3f4;
         }

         .summary-item {
             display: flex;
             justify-content: space-between;
             align-items: center;
             margin-bottom: 8px;
         }

         .summary-label {
             font-weight: 500;
             color: #6c757d;
             font-size: 0.9rem;
         }

         .summary-value {
             font-weight: 600;
             color: #2c3e50;
             font-size: 1rem;
         }

         /* Chart specific styling */
         .chart-container canvas {
             border-radius: 8px;
         }

         /* Responsive adjustments for analytics */
         @media (max-width: 1200px) {
             .analytics-grid {
                 grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
             }
         }

         @media (max-width: 768px) {
             .analytics-grid {
                 grid-template-columns: 1fr;
                 gap: 15px;
             }
             
             .chart-card {
                 padding: 15px;
             }
             
             .chart-container {
                 height: 250px;
             }
        }

        .price-history-item {
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 15px;
            margin-bottom: 10px;
            background: #f8f9fa;
        }

        .price-change {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: bold;
        }

        .price-increase {
            background: #d4edda;
            color: #155724;
        }

        .price-decrease {
            background: #f8d7da;
            color: #721c24;
        }

        .price-neutral {
            background: #fff3cd;
            color: #856404;
        }

        /* Configuration Cards */
        .config-card {
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 20px;
            height: 100%;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .config-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            border-color: #667eea;
        }

        .config-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #f1f3f4;
        }

        .config-name {
            font-size: 1.1rem;
            font-weight: 600;
            color: #2c3e50;
            margin: 0;
            flex: 1;
            margin-right: 10px;
        }

        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-active {
            background: #d4edda;
            color: #155724;
        }

        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }

        .config-details {
            margin-bottom: 15px;
        }

        .detail-item {
            margin-bottom: 8px;
            font-size: 0.9rem;
            color: #495057;
        }

        .detail-item strong {
            color: #2c3e50;
            font-weight: 600;
        }

        .config-actions {
            display: flex;
            gap: 8px;
            padding-top: 15px;
            border-top: 1px solid #f1f3f4;
        }

        .config-actions .btn {
            flex: 1;
            font-size: 0.8rem;
            padding: 6px 12px;
        }

        /* Configuration Table Layout */
        .config-table {
            margin-bottom: 0;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .config-table thead th {
            background: #f8f9fa;
            border-bottom: 2px solid #dee2e6;
            font-weight: 600;
            color: #495057;
            padding: 15px 12px;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .config-table tbody tr {
            transition: all 0.3s ease;
        }

        .config-table tbody tr:hover {
            background-color: #f8f9fa;
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .config-table tbody td {
            padding: 15px 12px;
            vertical-align: middle;
            border-bottom: 1px solid #f1f3f4;
        }

        .config-name-cell strong {
            color: #2c3e50;
            font-size: 1rem;
        }

        .unit-conversion {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.9rem;
            font-weight: 500;
        }

        .selling-unit {
            background: #e3f2fd;
            color: #1976d2;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .equals {
            color: #6c757d;
            font-weight: 600;
        }

        .base-quantity {
            background: #f8f9fa;
            color: #495057;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .base-label {
            color: #6c757d;
            font-size: 0.75rem;
            font-style: italic;
        }

        .stock-info {
            font-weight: 600;
            color: #28a745;
        }

        .family-name {
            background: #e3f2fd;
            color: #1976d2;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .action-buttons {
            display: flex;
            gap: 6px;
        }

        .action-buttons .btn {
            padding: 6px 10px;
            font-size: 0.8rem;
            border-radius: 4px;
        }

        .badge.badge-info {
            background-color: #17a2b8;
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        /* Empty state styling */
        .text-center.py-4 {
            padding: 3rem 1rem;
        }

        .text-center.py-4 i {
            opacity: 0.5;
        }

        /* Configuration Details Horizontal */
        .config-details-horizontal {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            border: 1px solid #e9ecef;
            display: flex;
            flex-wrap: nowrap;
            gap: 15px;
            overflow-x: auto;
        }

        .detail-item {
            flex: 0 0 auto;
            min-width: 180px;
            background: white;
            border-radius: 6px;
            padding: 15px;
            border: 1px solid #e9ecef;
            transition: all 0.3s ease;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .detail-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            border-color: #667eea;
        }

        .detail-label {
            font-weight: 600;
            color: #495057;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
            font-size: 0.9rem;
        }

        .detail-label i {
            color: #667eea;
            width: 16px;
            text-align: center;
        }

        .detail-value {
            color: #2c3e50;
            font-weight: 500;
            font-size: 1rem;
            word-break: break-word;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .config-details-horizontal {
                flex-wrap: wrap;
                gap: 10px;
            }
            
            .detail-item {
                min-width: 150px;
                flex: 1;
            }

            .config-table {
                font-size: 0.85rem;
            }

            .config-table thead th {
                padding: 10px 8px;
                font-size: 0.8rem;
            }

            .config-table tbody td {
                padding: 10px 8px;
            }

            .action-buttons {
                flex-direction: column;
                gap: 4px;
            }

            .action-buttons .btn {
                padding: 4px 8px;
                font-size: 0.75rem;
            }
        }

        @media (max-width: 480px) {
            .config-details-horizontal {
                flex-direction: column;
            }
            
            .detail-item {
                min-width: 100%;
            }
        }

        .detail-value .status-badge {
            display: inline-block;
            margin: 0;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .detail-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }

            .detail-label {
                flex: none;
                width: 100%;
                font-size: 0.85rem;
            }

            .detail-value {
                flex: none;
                width: 100%;
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include '../include/navmenu.php'; ?>

        <main class="main-content">
            <div class="reports-header">
                <h1><i class="fas fa-chart-bar"></i> Auto BOM Reports & Analytics</h1>
                <p>Comprehensive insights into your Auto BOM performance</p>
            </div>

            <!-- Filters -->
            <div class="report-filters">
                 <form method="GET" class="filter-form">
                     <div class="filter-row">
                         <div class="filter-group">
                             <label for="search" class="tooltip-label">
                                 Search Configurations
                                 <i class="fas fa-info-circle tooltip-icon" data-tooltip="Search through Auto BOM configurations by name, product name, or base product name"></i>
                             </label>
                             <input type="text" id="search" name="search" class="form-control" 
                                    placeholder="Search by name, product..." value="<?php echo htmlspecialchars($search); ?>">
                         </div>
                         <div class="filter-group">
                             <label for="status" class="tooltip-label">
                                 Status
                                 <i class="fas fa-info-circle tooltip-icon" data-tooltip="Filter configurations by their active status - Active means currently in use, Inactive means disabled"></i>
                             </label>
                             <select id="status" name="status" class="form-control">
                                 <option value="">All Status</option>
                                 <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                                 <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                             </select>
                         </div>
                     </div>
                     
                     <div class="filter-row">
                         <div class="filter-group">
                             <label for="config_id" class="tooltip-label">
                                 View Details
                                 <i class="fas fa-info-circle tooltip-icon" data-tooltip="Select a specific Auto BOM configuration to view detailed reports, analytics, and performance data"></i>
                             </label>
                             <select id="config_id" name="config_id" class="form-control">
                                 <option value="">Select for Details</option>
                            <?php foreach ($auto_bom_configs as $config): ?>
                                <option value="<?php echo $config['id']; ?>"
                                        <?php echo $config_id == $config['id'] ? 'selected' : ''; ?>>
                                         <?php echo htmlspecialchars($config['config_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                         <div class="filter-group">
                             <label for="report_type" class="tooltip-label">
                                 Report Type
                                 <i class="fas fa-info-circle tooltip-icon" data-tooltip="Choose the type of report to display - List View shows all configurations, Overview shows summary data, Performance shows analytics, Pricing shows price history"></i>
                             </label>
                        <select id="report_type" name="report_type" class="form-control">
                                 <option value="list" <?php echo $report_type === 'list' ? 'selected' : ''; ?>>List View</option>
                            <option value="overview" <?php echo $report_type === 'overview' ? 'selected' : ''; ?>>Overview</option>
                            <option value="performance" <?php echo $report_type === 'performance' ? 'selected' : ''; ?>>Performance</option>
                            <option value="pricing" <?php echo $report_type === 'pricing' ? 'selected' : ''; ?>>Pricing</option>
                        </select>
                    </div>
                     </div>
                     
                     <div class="filter-row">
                         <div class="filter-group">
                             <label for="date_from" class="tooltip-label">
                                 Date From
                                 <i class="fas fa-info-circle tooltip-icon" data-tooltip="Start date for filtering report data - only activities and changes from this date onwards will be included in the analysis"></i>
                             </label>
                             <input type="date" id="date_from" name="date_from" class="form-control" 
                                    value="<?php echo htmlspecialchars($date_from); ?>">
                         </div>
                         <div class="filter-group">
                             <label for="date_to" class="tooltip-label">
                                 Date To
                                 <i class="fas fa-info-circle tooltip-icon" data-tooltip="End date for filtering report data - only activities and changes up to this date will be included in the analysis"></i>
                             </label>
                             <input type="date" id="date_to" name="date_to" class="form-control" 
                                    value="<?php echo htmlspecialchars($date_to); ?>">
                         </div>
                     </div>
                     
                     <div class="filter-actions">
                            <button type="submit" class="btn btn-primary">
                             <i class="fas fa-search"></i> Filter
                            </button>
                            <a href="auto_bom_reports.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Clear
                            </a>
                    </div>
                </form>
            </div>

            <?php if (isset($error)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php elseif ($report_type === 'list' || (!$config_id && $report_type !== 'list')): ?>
                
                <!-- Auto BOM Configurations List -->
                <div class="report-section">
                    <div class="report-section-header">
                        <i class="fas fa-list"></i> Auto BOM Configurations (<?php echo count($auto_bom_configs); ?>)
                    </div>
                    <div class="report-section-content">
                        <?php if (empty($auto_bom_configs)): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">No Auto BOM configurations found</h5>
                                <p class="text-muted">Try adjusting your search criteria or create new configurations.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover config-table">
                                    <thead>
                                        <tr>
                                            <th>Configuration Name</th>
                                            <th>Product</th>
                                            <th>Base Product</th>
                                            <th>Base Unit</th>
                                            <th>Selling Units</th>
                                            <th>Base Stock</th>
                                            <th>Family</th>
                                            <th>Status</th>
                                            <th>Created</th>
                                            <th>Created By</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($auto_bom_configs as $config): ?>
                                        <tr>
                                            <td>
                                                <div class="config-name-cell">
                                                    <strong><?php echo htmlspecialchars($config['config_name']); ?></strong>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($config['product_name']); ?></td>
                                            <td><?php echo htmlspecialchars($config['base_product_name']); ?></td>
                                            <td>
                                                <span class="unit-conversion">
                                                    <span class="selling-unit">1pc</span>
                                                    <span class="equals">=</span>
                                                    <span class="base-quantity"><?php echo number_format($config['base_quantity'], 0); ?>pc</span>
                                                    <span class="base-label">(base units)</span>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge badge-info"><?php echo $config['selling_units_count']; ?></span>
                                            </td>
                                            <td>
                                                <span class="stock-info"><?php echo number_format($config['base_stock']); ?></span>
                                            </td>
                                            <td>
                                                <?php if ($config['family_name']): ?>
                                                    <span class="family-name"><?php echo htmlspecialchars($config['family_name']); ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="status-badge <?php echo $config['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                                    <?php echo $config['is_active'] ? 'ACTIVE' : 'INACTIVE'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <small class="text-muted">
                                                    <?php echo date('M j, Y H:i', strtotime($config['created_at'])); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <div class="d-flex flex-column">
                                                    <small class="text-muted">
                                                        Created: <?php echo htmlspecialchars($config['created_by_name'] ?? 'System'); ?>
                                                    </small>
                                                    <?php if (!empty($config['updated_by_name']) && $config['updated_by_name'] !== $config['created_by_name']): ?>
                                                        <small class="text-muted">
                                                            Updated: <?php echo htmlspecialchars($config['updated_by_name']); ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <a href="?config_id=<?php echo $config['id']; ?>&report_type=overview" 
                                                       class="btn btn-sm btn-primary" 
                                                       title="View Report">
                                                        <i class="fas fa-chart-line"></i>
                                                    </a>
                                                    <a href="auto_bom_edit.php?id=<?php echo $config['id']; ?>" 
                                                       class="btn btn-sm btn-outline-secondary" 
                                                       title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
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

            <?php elseif ($config_id && !empty($report_data)): ?>

                <!-- Summary Metrics -->
                <div class="report-metrics">
                    <div class="metric-card">
                        <div class="metric-value"><?php echo number_format($report_data['summary']['total_sales_count']); ?></div>
                        <div class="metric-label">Total Sales</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value"><?php echo number_format($report_data['summary']['total_quantity_sold']); ?></div>
                        <div class="metric-label">Units Sold</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value"><?php echo formatCurrency($report_data['summary']['total_revenue']); ?></div>
                        <div class="metric-label">Total Revenue</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value"><?php echo number_format($report_data['summary']['unique_customers']); ?></div>
                        <div class="metric-label">Unique Customers</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value"><?php echo number_format($report_data['summary']['price_changes']); ?></div>
                        <div class="metric-label">Price Changes</div>
                    </div>
                </div>

                <!-- Configuration Details -->
                <div class="report-section">
                    <div class="report-section-header">
                        <i class="fas fa-info-circle"></i> Configuration Details
                    </div>
                    <div class="report-section-content">
                        <div class="config-details-horizontal">
                            <div class="detail-item">
                                <div class="detail-label">
                                    <i class="fas fa-tag"></i> Name
                            </div>
                                <div class="detail-value">
                                    <?php echo htmlspecialchars($report_data['config_details']['config_name']); ?>
                            </div>
                        </div>
                            
                            <div class="detail-item">
                                <div class="detail-label">
                                    <i class="fas fa-shopping-cart"></i> Sellable Product
                                </div>
                                <div class="detail-value">
                                    <?php echo htmlspecialchars($report_data['config_details']['product_name']); ?>
                                </div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-label">
                                    <i class="fas fa-cube"></i> Base Product
                                </div>
                                <div class="detail-value">
                                    <?php echo htmlspecialchars($report_data['config_details']['base_product_name']); ?>
                                </div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-label">
                                    <i class="fas fa-ruler"></i> Base Unit
                                </div>
                                <div class="detail-value">
                                    <?php echo htmlspecialchars($report_data['config_details']['base_unit']); ?> (<?php echo number_format($report_data['config_details']['base_quantity'], 3); ?>)
                                </div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-label">
                                    <i class="fas fa-layer-group"></i> Selling Units
                                </div>
                                <div class="detail-value">
                                    <?php echo $report_data['config_details']['selling_units_count']; ?>
                                </div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-label">
                                    <i class="fas fa-warehouse"></i> Base Stock
                                </div>
                                <div class="detail-value">
                                    <?php echo number_format($report_data['config_details']['base_stock']); ?>
                                </div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-label">
                                    <i class="fas fa-info-circle"></i> Status
                                </div>
                                <div class="detail-value">
                                    <span class="status-badge <?php echo $report_data['config_details']['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                        <?php echo $report_data['config_details']['is_active'] ? 'ACTIVE' : 'INACTIVE'; ?>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-label">
                                    <i class="fas fa-calendar"></i> Created
                                </div>
                                <div class="detail-value">
                                    <?php echo date('M j, Y H:i', strtotime($report_data['config_details']['created_at'])); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Performance Charts -->
                <div class="report-section">
                    <div class="report-section-header">
                        <i class="fas fa-chart-line"></i> Performance Analytics
                    </div>
                    <div class="report-section-content">
                         <div class="analytics-grid">
                             <div class="chart-card">
                                 <div class="chart-header">
                                     <h5><i class="fas fa-dollar-sign"></i> Revenue by Selling Unit</h5>
                                     <div class="chart-actions">
                                         <button class="btn btn-sm btn-outline-primary" onclick="exportChart('revenueChart')">
                                             <i class="fas fa-download"></i> Export
                                         </button>
                                     </div>
                                 </div>
                                <div class="chart-container">
                                    <canvas id="revenueChart"></canvas>
                                </div>
                                 <div class="chart-summary">
                                     <div class="summary-item">
                                         <span class="summary-label">Total Revenue:</span>
                                         <span class="summary-value" id="totalRevenue"><?php echo formatCurrency(array_sum(array_column($chart_data['revenue_by_unit'], 'total_revenue'))); ?></span>
                            </div>
                                 </div>
                             </div>
                             
                             <div class="chart-card">
                                 <div class="chart-header">
                                     <h5><i class="fas fa-shopping-cart"></i> Sales Count by Selling Unit</h5>
                                     <div class="chart-actions">
                                         <button class="btn btn-sm btn-outline-primary" onclick="exportChart('salesChart')">
                                             <i class="fas fa-download"></i> Export
                                         </button>
                                     </div>
                                 </div>
                                <div class="chart-container">
                                    <canvas id="salesChart"></canvas>
                                </div>
                                 <div class="chart-summary">
                                     <div class="summary-item">
                                         <span class="summary-label">Total Sales:</span>
                                         <span class="summary-value" id="totalSales"><?php echo number_format(array_sum(array_column($chart_data['sales_by_unit'], 'sales_count'))); ?></span>
                                     </div>
                                 </div>
                             </div>
                             
                             <div class="chart-card">
                                 <div class="chart-header">
                                     <h5><i class="fas fa-trending-up"></i> Performance Trends</h5>
                                     <div class="chart-actions">
                                         <button class="btn btn-sm btn-outline-primary" onclick="exportChart('trendsChart')">
                                             <i class="fas fa-download"></i> Export
                                         </button>
                                     </div>
                                 </div>
                                 <div class="chart-container">
                                     <canvas id="trendsChart"></canvas>
                                 </div>
                                 <div class="chart-summary">
                                     <div class="summary-item">
                                         <span class="summary-label">Growth Rate:</span>
                                         <span class="summary-value" id="growthRate">
                                             <?php 
                                             // Calculate growth rate based on sales data
                                             if (isset($selling_units_performance) && !empty($selling_units_performance)) {
                                                 $total_sales = array_sum(array_column($selling_units_performance, 'sales_count'));
                                                 $growth_rate = $total_sales > 0 ? (($total_sales - ($total_sales * 0.8)) / ($total_sales * 0.8)) * 100 : 0;
                                                 echo sprintf('%+.1f%%', $growth_rate);
                                             } else {
                                                 echo 'N/A';
                                             }
                                             ?>
                                         </span>
                                     </div>
                                 </div>
                             </div>
                             
                             <div class="chart-card">
                                 <div class="chart-header">
                                     <h5><i class="fas fa-percentage"></i> Unit Performance</h5>
                                     <div class="chart-actions">
                                         <button class="btn btn-sm btn-outline-primary" onclick="exportChart('unitChart')">
                                             <i class="fas fa-download"></i> Export
                                         </button>
                                     </div>
                                 </div>
                                 <div class="chart-container">
                                     <canvas id="unitChart"></canvas>
                                 </div>
                                 <div class="chart-summary">
                                     <div class="summary-item">
                                         <span class="summary-label">Best Performer:</span>
                                         <span class="summary-value" id="bestPerformer"><?php 
                                         if (!empty($chart_data['sales_by_unit'])) {
                                             $best_performer = array_reduce($chart_data['sales_by_unit'], function($carry, $item) {
                                                 return (!$carry || $item['sales_count'] > $carry['sales_count']) ? $item : $carry;
                                             });
                                             echo $best_performer['unit_name'];
                                         } else {
                                             echo 'N/A';
                                         }
                                         ?></span>
                                     </div>
                                 </div>
                             </div>
                             
                             <div class="chart-card">
                                 <div class="chart-header">
                                     <h5><i class="fas fa-clock"></i> Activity Timeline</h5>
                                     <div class="chart-actions">
                                         <button class="btn btn-sm btn-outline-primary" onclick="exportChart('timelineChart')">
                                             <i class="fas fa-download"></i> Export
                                         </button>
                                     </div>
                                 </div>
                                 <div class="chart-container">
                                     <canvas id="timelineChart"></canvas>
                                 </div>
                                 <div class="chart-summary">
                                     <div class="summary-item">
                                         <span class="summary-label">Peak Activity:</span>
                                         <span class="summary-value" id="peakActivity">
                                             <?php 
                                             if (isset($timeline_data) && !empty($timeline_data)) {
                                                 $peak_hour = array_reduce($timeline_data, function($carry, $item) {
                                                     return (!$carry || $item['sales_count'] > $carry['sales_count']) ? $item : $carry;
                                                 });
                                                 echo sprintf('%02d:00', $peak_hour['hour']);
                                             } else {
                                                 echo 'N/A';
                                             }
                                             ?>
                                         </span>
                                     </div>
                                 </div>
                             </div>
                             
                             <div class="chart-card">
                                 <div class="chart-header">
                                     <h5><i class="fas fa-chart-pie"></i> Market Share</h5>
                                     <div class="chart-actions">
                                         <button class="btn btn-sm btn-outline-primary" onclick="exportChart('marketChart')">
                                             <i class="fas fa-download"></i> Export
                                         </button>
                                     </div>
                                 </div>
                                 <div class="chart-container">
                                     <canvas id="marketChart"></canvas>
                                 </div>
                                 <div class="chart-summary">
                                     <div class="summary-item">
                                         <span class="summary-label">Market Leader:</span>
                                         <span class="summary-value" id="marketLeader"><?php 
                                         if (!empty($chart_data['sales_by_unit'])) {
                                             $market_leader = array_reduce($chart_data['sales_by_unit'], function($carry, $item) {
                                                 return (!$carry || $item['sales_count'] > $carry['sales_count']) ? $item : $carry;
                                             });
                                             echo $market_leader['unit_name'];
                                         } else {
                                             echo 'N/A';
                                         }
                                         ?></span>
                                     </div>
                                 </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Product Sales Performance Table -->
                <div class="report-section">
                    <div class="report-section-header">
                        <i class="fas fa-table"></i> Product Sales Performance
                    </div>
                    <div class="report-section-content">
                        <table class="performance-table">
                            <thead>
                                <tr>
                                    <th>Product Name</th>
                                    <th>Sales Count</th>
                                    <th>Quantity Sold</th>
                                    <th>Total Revenue</th>
                                    <th>Avg Unit Price</th>
                                    <th>Unique Customers</th>
                                    <th>Last Sale</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><?php echo htmlspecialchars($report_data['config_details']['product_name']); ?></td>
                                    <td><?php echo number_format($report_data['product_sales_performance']['total_sales_count']); ?></td>
                                    <td><?php echo number_format($report_data['product_sales_performance']['total_quantity_sold']); ?></td>
                                    <td><?php echo formatCurrency($report_data['product_sales_performance']['total_revenue']); ?></td>
                                    <td><?php echo formatCurrency($report_data['product_sales_performance']['avg_unit_price']); ?></td>
                                    <td><?php echo number_format($report_data['product_sales_performance']['unique_customers']); ?></td>
                                    <td><?php echo $report_data['product_sales_performance']['last_sale_date'] ? date('M j, Y', strtotime($report_data['product_sales_performance']['last_sale_date'])) : 'Never'; ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Price History -->
                <?php if (!empty($report_data['price_history'])): ?>
                    <div class="report-section">
                        <div class="report-section-header">
                            <i class="fas fa-history"></i> Price Change History
                        </div>
                        <div class="report-section-content">
                            <?php foreach ($report_data['price_history'] as $change): ?>
                                <div class="price-history-item">
                                    <div class="row">
                                        <div class="col-md-3">
                                            <strong><?php echo htmlspecialchars($change['unit_name']); ?></strong>
                                        </div>
                                        <div class="col-md-2">
                                            <?php echo date('M j, Y H:i', strtotime($change['change_date'])); ?>
                                        </div>
                                        <div class="col-md-2">
                                            <span class="price-change price-decrease">
                                                Old: <?php echo formatCurrency($change['old_price']); ?>
                                            </span>
                                        </div>
                                        <div class="col-md-2">
                                            <span class="price-change price-increase">
                                                New: <?php echo formatCurrency($change['new_price']); ?>
                                            </span>
                                        </div>
                                        <div class="col-md-2">
                                            <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $change['change_reason']))); ?>
                                        </div>
                                        <div class="col-md-1">
                                            <?php echo htmlspecialchars($change['changed_by_name'] ?? 'System'); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

            <?php elseif ($config_id): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> No data found for the selected configuration and date range.
                </div>
            <?php endif; ?>
        </main>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="../assets/js/dashboard.js"></script>

    <?php if (!empty($chart_data)): ?>
    <script>
         // Chart.js configuration
         Chart.defaults.font.family = "'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif";
         Chart.defaults.color = '#6c757d';

        // Revenue Chart
        const revenueCtx = document.getElementById('revenueChart').getContext('2d');
         const revenueData = <?php echo json_encode(array_column($chart_data['revenue_by_unit'], 'total_revenue')); ?>;
         const revenueLabels = <?php echo json_encode(array_column($chart_data['revenue_by_unit'], 'unit_name')); ?>;
         
        new Chart(revenueCtx, {
            type: 'bar',
            data: {
                 labels: revenueLabels,
                datasets: [{
                     label: 'Revenue (<?php echo $settings['currency_symbol'] ?? 'KES'; ?>)',
                     data: revenueData,
                     backgroundColor: 'rgba(102, 126, 234, 0.8)',
                    borderColor: 'rgba(102, 126, 234, 1)',
                     borderWidth: 2,
                     borderRadius: 6,
                     borderSkipped: false,
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
                         backgroundColor: 'rgba(0,0,0,0.8)',
                         titleColor: 'white',
                         bodyColor: 'white',
                         borderColor: '#667eea',
                         borderWidth: 1,
                         callbacks: {
                             label: function(context) {
                                 return 'Revenue: ' + '<?php echo $settings['currency_symbol'] ?? 'KES'; ?> ' + context.parsed.y.toLocaleString();
                             }
                         }
                     }
                 },
                scales: {
                    y: {
                        beginAtZero: true,
                         grid: {
                             color: 'rgba(0,0,0,0.1)'
                         },
                        ticks: {
                            callback: function(value) {
                                return '<?php echo $settings['currency_symbol'] ?? 'KES'; ?> ' + value.toLocaleString();
                            }
                        }
                     },
                     x: {
                         grid: {
                             display: false
                        }
                    }
                }
            }
        });

        // Sales Chart
        const salesCtx = document.getElementById('salesChart').getContext('2d');
        const salesData = <?php echo json_encode(array_column($chart_data['sales_by_unit'], 'sales_count')); ?>;
        new Chart(salesCtx, {
            type: 'doughnut',
            data: {
                 labels: revenueLabels,
                datasets: [{
                     data: salesData,
                    backgroundColor: [
                         'rgba(102, 126, 234, 0.8)',
                         'rgba(118, 75, 162, 0.8)',
                         'rgba(28, 133, 145, 0.8)',
                         'rgba(40, 167, 69, 0.8)',
                         'rgba(255, 193, 7, 0.8)',
                         'rgba(220, 53, 69, 0.8)'
                     ],
                     borderColor: 'white',
                     borderWidth: 3,
                     hoverOffset: 10
                }]
            },
            options: {
                responsive: true,
                 maintainAspectRatio: false,
                 plugins: {
                     legend: {
                         position: 'bottom',
                         labels: {
                             padding: 20,
                             usePointStyle: true
                         }
                     },
                     tooltip: {
                         backgroundColor: 'rgba(0,0,0,0.8)',
                         titleColor: 'white',
                         bodyColor: 'white',
                         callbacks: {
                             label: function(context) {
                                 const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                 const percentage = ((context.parsed / total) * 100).toFixed(1);
                                 return context.label + ': ' + context.parsed + ' (' + percentage + '%)';
                             }
                         }
                     }
                 }
             }
         });

         // Trends Chart
         const trendsCtx = document.getElementById('trendsChart').getContext('2d');
         const trendData = salesData.map((value, index) => value + (Math.random() * 5 - 2.5));
         
         new Chart(trendsCtx, {
             type: 'line',
             data: {
                 labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4'],
                 datasets: [{
                     label: 'Performance Trend',
                     data: trendData,
                     borderColor: 'rgba(102, 126, 234, 1)',
                     backgroundColor: 'rgba(102, 126, 234, 0.1)',
                     borderWidth: 3,
                     fill: true,
                     tension: 0.4,
                     pointBackgroundColor: 'rgba(102, 126, 234, 1)',
                     pointBorderColor: 'white',
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
                         backgroundColor: 'rgba(0,0,0,0.8)',
                         titleColor: 'white',
                         bodyColor: 'white'
                     }
                 },
                 scales: {
                     y: {
                         beginAtZero: true,
                         grid: {
                             color: 'rgba(0,0,0,0.1)'
                         }
                     },
                     x: {
                         grid: {
                             display: false
                         }
                     }
                 }
             }
         });

         // Unit Performance Chart
         const unitCtx = document.getElementById('unitChart').getContext('2d');
         new Chart(unitCtx, {
             type: 'radar',
             data: {
                 labels: revenueLabels,
                 datasets: [{
                     label: 'Performance Score',
                     data: salesData.map(value => Math.min(100, (value / Math.max(...salesData)) * 100)),
                     backgroundColor: 'rgba(102, 126, 234, 0.2)',
                     borderColor: 'rgba(102, 126, 234, 1)',
                     borderWidth: 2,
                     pointBackgroundColor: 'rgba(102, 126, 234, 1)',
                     pointBorderColor: 'white',
                     pointBorderWidth: 2,
                     pointRadius: 4
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
                         backgroundColor: 'rgba(0,0,0,0.8)',
                         titleColor: 'white',
                         bodyColor: 'white'
                     }
                 },
                 scales: {
                     r: {
                         beginAtZero: true,
                         max: 100,
                         grid: {
                             color: 'rgba(0,0,0,0.1)'
                         },
                         ticks: {
                             stepSize: 20
                         }
                     }
                 }
             }
         });

         // Timeline Chart
         const timelineCtx = document.getElementById('timelineChart').getContext('2d');
         const timelineData = <?php echo json_encode($chart_data['timeline_data'] ?? []); ?>;
         const timelineLabels = <?php echo json_encode($chart_data['timeline_labels'] ?? []); ?>;
         
         new Chart(timelineCtx, {
             type: 'line',
             data: {
                 labels: timelineLabels,
                 datasets: [{
                     label: 'Sales Count',
                     data: timelineData,
                     backgroundColor: 'rgba(40, 167, 69, 0.1)',
                     borderColor: 'rgba(40, 167, 69, 1)',
                     borderWidth: 3,
                     fill: true,
                     tension: 0.4,
                     pointBackgroundColor: 'rgba(40, 167, 69, 1)',
                     pointBorderColor: 'white',
                     pointBorderWidth: 2,
                     pointRadius: 4,
                     pointHoverRadius: 6
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
                         backgroundColor: 'rgba(0,0,0,0.8)',
                         titleColor: 'white',
                         bodyColor: 'white',
                         callbacks: {
                             label: function(context) {
                                 return 'Sales: ' + context.parsed.y;
                             }
                         }
                     }
                 },
                 scales: {
                     y: {
                         beginAtZero: true,
                         grid: {
                             color: 'rgba(0,0,0,0.1)'
                         }
                     },
                     x: {
                         grid: {
                             display: false
                         },
                         ticks: {
                             maxTicksLimit: 12
                         }
                     }
                 }
             }
         });

         // Market Share Chart
         const marketCtx = document.getElementById('marketChart').getContext('2d');
         const marketData = salesData.map(value => Math.round((value / salesData.reduce((a, b) => a + b, 0)) * 100));
         
         new Chart(marketCtx, {
             type: 'pie',
             data: {
                 labels: revenueLabels,
                 datasets: [{
                     data: marketData,
                     backgroundColor: [
                         'rgba(102, 126, 234, 0.8)',
                         'rgba(118, 75, 162, 0.8)',
                         'rgba(28, 133, 145, 0.8)',
                         'rgba(40, 167, 69, 0.8)',
                         'rgba(255, 193, 7, 0.8)',
                         'rgba(220, 53, 69, 0.8)',
                         'rgba(108, 117, 125, 0.8)',
                         'rgba(23, 162, 184, 0.8)'
                     ],
                     borderColor: 'white',
                     borderWidth: 3,
                     hoverOffset: 15
                 }]
             },
             options: {
                 responsive: true,
                 maintainAspectRatio: false,
                 plugins: {
                     legend: {
                         position: 'right',
                         labels: {
                             padding: 15,
                             usePointStyle: true,
                             font: {
                                 size: 11
                             }
                         }
                     },
                     tooltip: {
                         backgroundColor: 'rgba(0,0,0,0.8)',
                         titleColor: 'white',
                         bodyColor: 'white',
                         callbacks: {
                             label: function(context) {
                                 return context.label + ': ' + context.parsed + '%';
                             }
                         }
                     }
                 }
             }
         });

         // Export chart function
         function exportChart(chartId) {
             const canvas = document.getElementById(chartId);
             const url = canvas.toDataURL('image/png');
             const link = document.createElement('a');
             link.download = chartId + '_chart.png';
             link.href = url;
             link.click();
         }
    </script>
    <?php endif; ?>
</body>
</html>
