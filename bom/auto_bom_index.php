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

// Check Auto BOM permissions - use granular permissions
$can_create_auto_boms = hasPermission('create_auto_boms', $permissions);
$can_edit_auto_boms = hasPermission('edit_auto_boms', $permissions);
$can_view_auto_boms = hasPermission('view_auto_boms', $permissions);
$can_manage_configs = hasPermission('manage_auto_bom_configs', $permissions);
$can_view_units = hasPermission('view_auto_bom_units', $permissions);

if (!$can_create_auto_boms && !$can_edit_auto_boms && !$can_view_auto_boms && !$can_manage_configs && !$can_view_units) {
    header("Location: ../dashboard/dashboard.php");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Handle filters and search
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$family_filter = $_GET['family'] ?? '';

// Build query
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(abc.config_name LIKE :search OR p.name LIKE :search OR p.sku LIKE :search OR bp.name LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if (!empty($status_filter)) {
    $where_conditions[] = "abc.is_active = :status";
    $params[':status'] = $status_filter === 'active' ? 1 : 0;
}

if (!empty($family_filter)) {
    $where_conditions[] = "abc.product_family_id = :family_id";
    $params[':family_id'] = $family_filter;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get Auto BOM configurations with pagination
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

$count_sql = "
    SELECT COUNT(*) as total
    FROM auto_bom_configs abc
    INNER JOIN products p ON abc.product_id = p.id
    INNER JOIN products bp ON abc.base_product_id = bp.id
    {$where_clause}
";

$stmt = $conn->prepare($count_sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$total_records = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_records / $per_page);

$sql = "
    SELECT
        abc.*,
        p.name as product_name,
        p.sku as product_sku,
        p.quantity as product_stock,
        bp.name as base_product_name,
        bp.sku as base_product_sku,
        bp.quantity as base_stock,
        bp.cost_price as base_cost,
        pf.name as family_name,
        u.username as created_by_name,
        COUNT(su.id) as selling_units_count
    FROM auto_bom_configs abc
    INNER JOIN products p ON abc.product_id = p.id
    INNER JOIN products bp ON abc.base_product_id = bp.id
    LEFT JOIN product_families pf ON abc.product_family_id = pf.id
    LEFT JOIN users u ON abc.created_by = u.id
    LEFT JOIN auto_bom_selling_units su ON abc.id = su.auto_bom_config_id AND su.status = 'active'
    {$where_clause}
    GROUP BY abc.id
    ORDER BY abc.created_at DESC
    LIMIT {$per_page} OFFSET {$offset}
";

$stmt = $conn->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$auto_boms = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get product families for filter
$product_families = [];
$stmt = $conn->query("SELECT id, name FROM product_families WHERE status = 'active' ORDER BY name");
$product_families = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auto BOM Management - <?php echo htmlspecialchars($settings['site_name'] ?? 'Point of Sale'); ?></title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/products.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --danger-color: #ef4444;
            --info-color: #06b6d4;
            --purple-color: #8b5cf6;
            --indigo-color: #6366f1;
            --emerald-color: #10b981;
            --amber-color: #f59e0b;
            --rose-color: #f43f5e;
            --sky-color: #0ea5e9;
            --slate-color: #64748b;
            --gray-50: #f8fafc;
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-300: #cbd5e1;
            --gray-400: #94a3b8;
            --gray-500: #64748b;
            --gray-600: #475569;
            --gray-700: #334155;
            --gray-800: #1e293b;
            --gray-900: #0f172a;
        }

        /* Viewport and Layout Management */
        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
            overflow-x: hidden;
        }

        .dashboard-container {
            display: flex;
            min-height: 100vh;
            max-height: 100vh;
            overflow: hidden;
        }

        .main-content {
            flex: 1;
            padding: 20px;
            margin-left: 280px;
            overflow-y: auto;
            max-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .content-wrapper {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        .auto-bom-header {
            background: linear-gradient(135deg, var(--indigo-color) 0%, var(--purple-color) 100%);
            color: white;
            padding: 1.5rem 2rem;
            margin: -20px -20px 20px -20px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(99, 102, 241, 0.3);
            flex-shrink: 0;
        }

        .auto-bom-card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            overflow: hidden;
        }

        .auto-bom-card-header {
            background: #f8f9fa;
            padding: 15px 20px;
            border-bottom: 1px solid #dee2e6;
            display: flex;
            justify-content: between;
            align-items: center;
        }

        .auto-bom-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
            flex-shrink: 0;
        }

        .stat-card {
            background: white;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            text-align: center;
            border: 1px solid var(--gray-200);
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--indigo-color);
            margin-bottom: 8px;
        }

        .stat-label {
            color: var(--gray-600);
            font-size: 0.9rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: bold;
        }

        .status-active {
            background: #d4edda;
            color: #155724;
        }

        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
        }

        .btn-small {
            padding: 6px 12px;
            font-size: 0.8rem;
        }

        .filters-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .filters-row {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 15px;
            flex-shrink: 0;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            min-width: 200px;
        }

        .filter-label {
            font-weight: bold;
            margin-bottom: 5px;
            color: #333;
        }

        .filter-input {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        
        /* Auto BOM Grid Layout */
        .auto-bom-grid-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 20px;
            padding: 20px 0;
        }
        
        /* Auto BOM Product Cards */
        .auto-bom-product-card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 16px;
            overflow: hidden;
            transition: all 0.3s ease;
            border: 1px solid #e5e7eb;
            width: 100%;
        }
        
        .auto-bom-product-card:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        
        /* Card Header with Status */
        .card-header {
            background: #ffffff;
            padding: 16px 20px;
            border-bottom: 1px solid #f3f4f6;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .product-info {
            flex: 1;
        }
        
        .product-name {
            font-size: 1.125rem;
            font-weight: 600;
            color: #ef4444;
            margin-bottom: 4px;
        }
        
        .product-sku {
            color: #9ca3af;
            font-size: 0.875rem;
            margin: 0;
        }
        
        .config-name-display {
            background: #f3f4f6;
            color: #374151;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 500;
            display: inline-block;
            margin-top: 4px;
        }
        
        .status-badge-card {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .status-active {
            background: #dcfce7;
            color: #16a34a;
        }
        
        .status-inactive {
            background: #fef2f2;
            color: #dc2626;
        }
        
        /* Card Body */
        .card-body {
            padding: 16px 20px;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        
        .base-product-section {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-left: 4px solid #ef4444;
            padding: 12px 16px;
            border-radius: 6px;
        }
        
        .base-product-title {
            font-weight: 600;
            color: #374151;
            margin-bottom: 6px;
            font-size: 0.875rem;
        }
        
        .base-product-name {
            font-size: 0.95rem;
            font-weight: 500;
            color: #111827;
            margin-bottom: 8px;
        }
        
        .base-product-details {
            display: flex;
            gap: 12px;
            font-size: 0.8rem;
            color: #6b7280;
        }
        
        .base-detail-item {
            display: flex;
            flex-direction: column;
        }
        
        .base-detail-label {
            font-weight: 500;
            color: #374151;
        }
        
        .product-metrics {
            display: flex;
            gap: 15px;
            justify-content: space-between;
            flex-wrap: wrap;
        }
        
        @media (max-width: 768px) {
            .product-metrics {
                flex-direction: column;
                gap: 10px;
            }
        }
        
        .metric-box {
            text-align: center;
            padding: 8px 12px;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            min-width: 80px;
        }
        
        .metric-number {
            font-size: 1.25rem;
            font-weight: 700;
            color: #ef4444;
            display: block;
            line-height: 1.2;
        }
        
        .metric-label {
            font-size: 0.75rem;
            color: #9ca3af;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 500;
            margin-top: 4px;
        }
        
        .config-label {
            color: #6b7280;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 500;
        }
        
        .config-value {
            color: #111827;
            font-weight: 500;
            font-size: 0.8rem;
        }
        
        .family-label {
            color: #6b7280;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 500;
        }
        
        .family-value {
            color: #111827;
            font-weight: 500;
            font-size: 0.8rem;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .btn-small {
            padding: 8px 12px;
            font-size: 0.8rem;
            white-space: nowrap;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        
        .btn-outline-primary {
            border: 2px solid var(--indigo-color);
            color: var(--indigo-color);
            background: transparent;
        }
        
        .btn-outline-primary:hover {
            background: var(--indigo-color);
            color: white;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
        }
        
        .btn-outline-secondary {
            border: 2px solid var(--gray-500);
            color: var(--gray-600);
            background: transparent;
        }
        
        .btn-outline-secondary:hover {
            background: var(--gray-500);
            color: white;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(100, 116, 139, 0.3);
        }
        
        .btn-outline-info {
            border: 2px solid var(--sky-color);
            color: var(--sky-color);
            background: transparent;
        }
        
        .btn-outline-info:hover {
            background: var(--sky-color);
            color: white;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(14, 165, 233, 0.3);
        }
        
        .table-container {
            flex: 1;
            overflow-y: auto;
            min-height: 0;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            border: 1px solid var(--gray-200);
        }

        .table {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            margin: 0;
            width: 100%;
        }
        
        .table thead th {
            background: linear-gradient(135deg, var(--gray-50) 0%, var(--gray-100) 100%);
            border-bottom: 2px solid var(--gray-300);
            font-weight: 600;
            color: var(--gray-700);
            padding: 16px;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            position: relative;
        }
        
        .table thead th:first-child {
            border-top-left-radius: 12px;
        }
        
        .table thead th:last-child {
            border-top-right-radius: 12px;
        }
        
        .table tbody td {
            padding: 20px 16px;
            vertical-align: middle;
            border-bottom: 1px solid var(--gray-200);
            transition: all 0.2s ease;
        }
        
        .table tbody tr:hover {
            background: linear-gradient(135deg, var(--gray-50) 0%, rgba(99, 102, 241, 0.02) 100%);
            transform: scale(1.001);
        }
        
        .table tbody tr:last-child td {
            border-bottom: none;
        }
        
        .product-image {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, var(--indigo-color) 0%, var(--purple-color) 100%);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            box-shadow: 0 2px 8px rgba(99, 102, 241, 0.3);
        }
        
        .badge {
            font-size: 0.75rem;
            font-weight: 600;
            padding: 6px 12px;
            border-radius: 20px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .badge.bg-primary {
            background: linear-gradient(135deg, var(--indigo-color) 0%, var(--purple-color) 100%) !important;
            color: white;
        }
        
        .badge.bg-info {
            background: linear-gradient(135deg, var(--sky-color) 0%, var(--info-color) 100%) !important;
            color: white;
        }
        
        .badge.bg-success {
            background: linear-gradient(135deg, var(--emerald-color) 0%, #059669 100%) !important;
            color: white;
        }
        
        .badge.bg-warning {
            background: linear-gradient(135deg, var(--amber-color) 0%, #d97706 100%) !important;
            color: white;
        }
        
        .badge.bg-danger {
            background: linear-gradient(135deg, var(--rose-color) 0%, var(--danger-color) 100%) !important;
            color: white;
        }
        
        .badge.bg-secondary {
            background: linear-gradient(135deg, var(--gray-500) 0%, var(--gray-600) 100%) !important;
            color: white;
        }
        
        /* Base Unit Info */
        .base-unit-display {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 8px;
        }
        
        .base-unit {
            background: #e0e7ff;
            color: #3730a3;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .base-quantity {
            color: #6b7280;
            font-size: 0.85rem;
        }
        
        /* Stock Status */
        .stock-status {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 8px;
        }
        
        .stock-indicator {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }
        
        .stock-high {
            background-color: #10b981;
        }
        
        .stock-low {
            background-color: #f59e0b;
        }
        
        .stock-out {
            background-color: #ef4444;
        }
        
        .stock-text {
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        /* Selling Units Display */
        .selling-units-display {
            text-align: center;
            background: #f0f4ff;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .units-count-large {
            font-size: 2rem;
            font-weight: 800;
            color: #667eea;
            margin-bottom: 5px;
        }
        
        .units-label {
            color: #6b7280;
            font-size: 0.8rem;
            text-transform: uppercase;
            font-weight: 500;
            letter-spacing: 0.5px;
        }
        
        /* Family Badge */
        .family-badge-card {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #f3f4f6;
            color: #374151;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            margin-bottom: 15px;
        }
        
        .family-badge-card i {
            color: #667eea;
        }
        
        .no-family {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #9ca3af;
            font-size: 0.8rem;
            margin-bottom: 15px;
        }
        
        /* Card Footer with Actions */
        .card-footer {
            background: #fafbfc;
            padding: 15px 20px;
            border-top: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .created-info {
            color: #6b7280;
            font-size: 0.8rem;
        }
        
        .action-buttons-card {
            display: flex;
            gap: 8px;
        }
        
        .btn-card {
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 500;
            transition: all 0.2s;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .btn-primary-card {
            background: #667eea;
            color: white;
        }
        
        .btn-primary-card:hover {
            background: #5a67d8;
            transform: translateY(-1px);
        }
        
        .btn-secondary-card {
            background: #6b7280;
            color: white;
        }
        
        .btn-secondary-card:hover {
            background: #5b6371;
            transform: translateY(-1px);
        }
        
        .btn-info-card {
            background: #0ea5e9;
            color: white;
        }
        
        .btn-info-card:hover {
            background: #0284c7;
            transform: translateY(-1px);
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6b7280;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.5;
            color: #667eea;
        }
        
        .empty-state h3 {
            font-size: 1.5rem;
            margin-bottom: 10px;
            color: #374151;
        }
        
        .empty-state p {
            font-size: 1rem;
            margin-bottom: 30px;
        }
        
        /* Utility Classes */
        .text-success {
            color: #10b981 !important;
        }
        
        .text-danger {
            color: #ef4444 !important;
        }
        
        .text-warning {
            color: #f59e0b !important;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 15px;
            }
            
            .auto-bom-stats {
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
            }
            
            .filters-row {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
            }
            
            .filter-group {
                min-width: auto;
            }
            
            .auto-bom-grid-container {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .card-header-main {
                flex-direction: column;
                align-items: center;
                text-align: center;
                gap: 10px;
            }
            
            .product-icon-large {
                width: 50px;
                height: 50px;
                font-size: 1.5rem;
            }
            
            .bom-details-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .action-buttons-card {
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .card-footer {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }
        }
        
        @media (max-width: 480px) {
            .main-content {
                padding: 10px;
            }
            
            .auto-bom-stats {
                grid-template-columns: 1fr;
                gap: 8px;
            }
            
            .stat-card {
                padding: 16px;
            }
            
            .auto-bom-grid-container {
                grid-template-columns: 1fr;
                padding: 10px 0;
            }
            
            .product-name {
                font-size: 1.1rem;
            }
            
            .detail-section {
                padding: 12px;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include '../include/navmenu.php'; ?>

        <main class="main-content">
            <div class="content-wrapper">
                <div class="auto-bom-header">
                    <h1><i class="bi bi-gear-fill"></i> Auto BOM Management</h1>
                    <p>Manage automatic bill of materials and unit conversions</p>
                </div>

            <!-- Statistics Cards -->
            <div class="auto-bom-stats">
                <div class="stat-card">
                    <div class="stat-value"><?php echo $total_records; ?></div>
                    <div class="stat-label">Total Auto BOMs</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value">
                        <?php
                        $active_count = array_reduce($auto_boms, function($count, $bom) {
                            return $count + ($bom['is_active'] ? 1 : 0);
                        }, 0);
                        echo $active_count;
                        ?>
                    </div>
                    <div class="stat-label">Active Configurations</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value">
                        <?php
                        $total_units = array_reduce($auto_boms, function($count, $bom) {
                            return $count + $bom['selling_units_count'];
                        }, 0);
                        echo $total_units;
                        ?>
                    </div>
                    <div class="stat-label">Selling Units</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo count($product_families); ?></div>
                    <div class="stat-label">Product Families</div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="mb-4">
                <?php if ($can_manage_auto_boms): ?>
                    <a href="auto_bom_setup.php" class="btn btn-primary">
                        <i class="bi bi-plus-circle"></i> Create Auto BOM
                    </a>
                    <a href="auto_bom_pricing.php" class="btn btn-secondary">
                        <i class="bi bi-tags"></i> Manage Pricing
                    </a>
                <?php endif; ?>
            </div>

            <!-- Filters Section -->
            <div class="filters-section">
                <form method="GET" class="filters-row">
                    <div class="filter-group">
                        <label class="filter-label">Search</label>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                               placeholder="Search by name, SKU..." class="filter-input">
                    </div>

                    <div class="filter-group">
                        <label class="filter-label">Status</label>
                        <select name="status" class="filter-input">
                            <option value="">All Status</option>
                            <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label class="filter-label">Product Family</label>
                        <select name="family" class="filter-input">
                            <option value="">All Families</option>
                            <?php foreach ($product_families as $family): ?>
                                <option value="<?php echo $family['id']; ?>"
                                        <?php echo $family_filter == $family['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($family['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label class="filter-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-search"></i> Filter
                        </button>
                        <a href="auto_bom_index.php" class="btn btn-secondary">
                            <i class="bi bi-x"></i> Clear
                        </a>
                    </div>
                </form>
            </div>

            <!-- Auto BOM Products Grid -->
            <?php if (empty($auto_boms)): ?>
                <div class="empty-state">
                    <i class="bi bi-gear-fill"></i>
                    <h3>No Auto BOM configurations found</h3>
                    <p>Get started by creating your first Auto BOM configuration.</p>
                    <?php if ($can_manage_auto_boms): ?>
                        <a href="auto_bom_setup.php" class="btn btn-primary">
                            <i class="bi bi-plus-circle"></i> Create First Auto BOM
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <!-- Auto BOM Table -->
                <div class="table-container">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>
                                    <input type="checkbox" id="selectAll" class="form-check-input">
                                </th>
                                <th>PRODUCT</th>
                                <th>CONFIGURATION</th>
                                <th>FAMILY</th>
                                <th>SELLING UNITS</th>
                                <th>BASE UNIT</th>
                                <th>STOCK</th>
                                <th>STATUS</th>
                                <th>CREATED</th>
                                <th>ACTIONS</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($auto_boms as $bom): ?>
                            <tr>
                                <td>
                                    <input type="checkbox" class="form-check-input" value="<?php echo $bom['id']; ?>">
                                </td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="product-image me-3">
                                            <i class="bi bi-box-seam" style="font-size: 1.5rem;"></i>
                                        </div>
                                        <div>
                                            <div class="fw-bold" style="color: var(--gray-800);"><?php echo htmlspecialchars($bom['base_product_name']); ?></div>
                                            <small style="color: var(--gray-500);">SKU: <?php echo htmlspecialchars($bom['base_product_sku']); ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-primary">
                                        <i class="bi bi-gear-fill me-1"></i>
                                        <?php echo htmlspecialchars($bom['config_name']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($bom['family_name']): ?>
                                        <span class="badge bg-info"><?php echo htmlspecialchars($bom['family_name']); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">None</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="text-center">
                                        <div class="fw-bold" style="color: var(--indigo-color); font-size: 1.2rem;"><?php echo number_format($bom['selling_units_count']); ?></div>
                                        <small style="color: var(--gray-500);">units</small>
                                    </div>
                                </td>
                                <td>
                                    <div>
                                        <div class="fw-bold" style="color: var(--gray-800);"><?php echo htmlspecialchars($bom['base_unit']); ?></div>
                                        <small style="color: var(--gray-500);">Qty: <?php echo number_format($bom['base_quantity']); ?></small>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <span class="fw-bold me-2" style="color: var(--gray-800);"><?php echo number_format($bom['base_stock']); ?></span>
                                        <?php if ($bom['base_stock'] > 50): ?>
                                            <span class="badge bg-success">IN STOCK</span>
                                        <?php elseif ($bom['base_stock'] > 10): ?>
                                            <span class="badge bg-warning">LOW STOCK</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">CRITICAL</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge <?php echo $bom['is_active'] ? 'bg-success' : 'bg-secondary'; ?>">
                                        <?php echo $bom['is_active'] ? 'ACTIVE' : 'INACTIVE'; ?>
                                    </span>
                                </td>
                                <td>
                                    <small style="color: var(--gray-600);"><?php echo date('M j, Y', strtotime($bom['created_at'])); ?></small>
                                </td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <?php if ($can_manage_auto_boms): ?>
                                        <a href="auto_bom_edit.php?id=<?php echo $bom['id']; ?>" class="btn btn-outline-primary btn-sm" title="Edit">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <a href="auto_bom_pricing.php?config_id=<?php echo $bom['id']; ?>" class="btn btn-outline-secondary btn-sm" title="Pricing">
                                            <i class="bi bi-tags"></i>
                                        </a>
                                        <?php endif; ?>
                                        <?php if ($can_view_auto_boms): ?>
                                        <a href="auto_bom_reports.php?config_id=<?php echo $bom['id']; ?>" class="btn btn-outline-info btn-sm" title="Reports">
                                            <i class="bi bi-graph-up"></i>
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&family=<?php echo urlencode($family_filter); ?>" class="btn btn-secondary">
                            <i class="bi bi-chevron-left"></i> Previous
                        </a>
                    <?php endif; ?>

                    <span class="page-info">
                        Page <?php echo $page; ?> of <?php echo $total_pages; ?>
                        (<?php echo $total_records; ?> total)
                    </span>

                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&family=<?php echo urlencode($family_filter); ?>" class="btn btn-secondary">
                            Next <i class="bi bi-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            </div>
        </main>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="../assets/js/dashboard.js"></script>
    
    <script>
    $(document).ready(function() {
        // Handle expandable details toggle
        $('.toggle-details').click(function() {
            const bomId = $(this).data('bom-id');
            const detailsRow = $('#details-' + bomId);
            const button = $(this);
            const icon = button.find('i');
            
            if (detailsRow.is(':visible')) {
                // Hide details
                detailsRow.slideUp(200);
                button.removeClass('expanded');
                icon.removeClass('bi-chevron-up').addClass('bi-chevron-down');
            } else {
                // Show details
                detailsRow.slideDown(200);
                button.addClass('expanded');
                icon.removeClass('bi-chevron-down').addClass('bi-chevron-up');
            }
        });
        
        // Optional: Close other expanded rows when opening a new one
        $('.toggle-details').click(function() {
            const currentBomId = $(this).data('bom-id');
            
            // Close all other expanded rows
            $('.toggle-details').not(this).each(function() {
                const otherBomId = $(this).data('bom-id');
                const otherDetailsRow = $('#details-' + otherBomId);
                const otherButton = $(this);
                const otherIcon = otherButton.find('i');
                
                if (otherDetailsRow.is(':visible')) {
                    otherDetailsRow.slideUp(200);
                    otherButton.removeClass('expanded');
                    otherIcon.removeClass('bi-chevron-up').addClass('bi-chevron-down');
                }
            });
        });
    });
    
    // Select All functionality
    document.getElementById('selectAll').addEventListener('change', function() {
        const checkboxes = document.querySelectorAll('tbody input[type="checkbox"]');
        checkboxes.forEach(checkbox => {
            checkbox.checked = this.checked;
        });
    });
    
    // Individual checkbox change
    document.querySelectorAll('tbody input[type="checkbox"]').forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            const allCheckboxes = document.querySelectorAll('tbody input[type="checkbox"]');
            const checkedCheckboxes = document.querySelectorAll('tbody input[type="checkbox"]:checked');
            const selectAllCheckbox = document.getElementById('selectAll');
            
            if (checkedCheckboxes.length === allCheckboxes.length) {
                selectAllCheckbox.checked = true;
                selectAllCheckbox.indeterminate = false;
            } else if (checkedCheckboxes.length === 0) {
                selectAllCheckbox.checked = false;
                selectAllCheckbox.indeterminate = false;
            } else {
                selectAllCheckbox.checked = false;
                selectAllCheckbox.indeterminate = true;
            }
        });
    });
    </script>
</body>
</html>
