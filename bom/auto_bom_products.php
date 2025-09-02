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
$can_manage_auto_boms = hasPermission('manage_auto_boms', $permissions);
$can_view_auto_boms = hasPermission('view_auto_boms', $permissions);

if (!$can_manage_auto_boms && !$can_view_auto_boms) {
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
$category_filter = $_GET['category'] ?? '';
$family_filter = $_GET['family'] ?? '';
$status_filter = $_GET['status'] ?? '';

// Build query
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(p.name LIKE :search OR p.sku LIKE :search OR bp.name LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if (!empty($category_filter)) {
    $where_conditions[] = "p.category_id = :category_id";
    $params[':category_id'] = $category_filter;
}

if (!empty($family_filter)) {
    $where_conditions[] = "abc.product_family_id = :family_id";
    $params[':family_id'] = $family_filter;
}

if ($status_filter === 'active') {
    $where_conditions[] = "abc.is_active = 1";
} elseif ($status_filter === 'inactive') {
    $where_conditions[] = "abc.is_active = 0";
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get Auto BOM products with pagination
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 12;
$offset = ($page - 1) * $per_page;

$count_sql = "
    SELECT COUNT(DISTINCT p.id) as total
    FROM products p
    INNER JOIN auto_bom_configs abc ON p.id = abc.product_id
    INNER JOIN products bp ON abc.base_product_id = bp.id
    LEFT JOIN categories c ON p.category_id = c.id
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
        p.*,
        c.name as category_name,
        abc.id as auto_bom_config_id,
        abc.config_name,
        abc.is_active as auto_bom_active,
        abc.base_unit,
        abc.base_quantity,
        bp.name as base_product_name,
        bp.sku as base_product_sku,
        bp.quantity as base_stock,
        bp.cost_price as base_cost,
        pf.name as family_name,
        COUNT(DISTINCT su.id) as selling_units_count,
        GROUP_CONCAT(DISTINCT su.pricing_strategy SEPARATOR ', ') as pricing_strategies
    FROM products p
    INNER JOIN auto_bom_configs abc ON p.id = abc.product_id
    INNER JOIN products bp ON abc.base_product_id = bp.id
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN product_families pf ON abc.product_family_id = pf.id
    LEFT JOIN auto_bom_selling_units su ON abc.id = su.auto_bom_config_id AND su.status = 'active'
    {$where_clause}
    GROUP BY p.id, abc.id
    ORDER BY p.name ASC
    LIMIT {$per_page} OFFSET {$offset}
";

$stmt = $conn->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$auto_bom_products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get filter options
$categories = [];
$stmt = $conn->query("SELECT id, name FROM categories WHERE status = 'active' ORDER BY name");
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

$product_families = [];
$stmt = $conn->query("SELECT id, name FROM product_families WHERE status = 'active' ORDER BY name");
$product_families = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get detailed selling units for each product (for expandable sections)
$selling_units_data = [];
foreach ($auto_bom_products as $product) {
    $stmt = $conn->prepare("
        SELECT
            su.*,
            CASE
                WHEN su.pricing_strategy = 'fixed' THEN CONCAT('Fixed: ', '{$settings['currency_symbol']}', su.fixed_price)
                WHEN su.pricing_strategy = 'cost_based' THEN CONCAT('Cost+: ', su.markup_percentage, '%')
                WHEN su.pricing_strategy = 'market_based' THEN CONCAT('Market: ', '{$settings['currency_symbol']}', su.market_price)
                WHEN su.pricing_strategy = 'dynamic' THEN 'Dynamic'
                WHEN su.pricing_strategy = 'hybrid' THEN 'Hybrid'
                ELSE 'Unknown'
            END as pricing_display
        FROM auto_bom_selling_units su
        WHERE su.auto_bom_config_id = :config_id AND su.status = 'active'
        ORDER BY su.priority ASC, su.unit_name ASC
    ");
    $stmt->execute([':config_id' => $product['auto_bom_config_id']]);
    $selling_units_data[$product['id']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auto BOM Products - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght=300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --danger-color: #ef4444;
            --info-color: #06b6d4;
        }

        .auto-bom-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            margin: -20px -20px 20px -20px;
            border-radius: 8px;
        }

        .product-card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 16px;
            overflow: hidden;
            transition: all 0.3s ease;
            border: 1px solid #e5e7eb;
            width: 100%;
        }

        .product-card:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }

        .product-header {
            background: #ffffff;
            padding: 16px 20px;
            border-bottom: 1px solid #f3f4f6;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .product-name {
            font-size: 1.125rem;
            font-weight: 600;
            color: #ef4444;
            margin-bottom: 4px;
        }

        .product-meta {
            color: #9ca3af;
            font-size: 0.875rem;
            margin: 0;
        }

        .status-badge {
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

        .product-body {
            padding: 16px 20px;
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr auto auto;
            gap: 20px;
            align-items: center;
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
            display: contents;
        }
        
        @media (max-width: 1200px) {
            .product-body {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .product-metrics {
                display: flex;
                gap: 10px;
                justify-content: space-around;
                flex-wrap: wrap;
            }
            
            .action-buttons {
                justify-content: center;
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

        .show-details-column {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .metric-card {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            border: 1px solid #e9ecef;
        }

        .metric-value {
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--primary-color);
            display: block;
        }

        .selling-units-section {
            grid-column: 1 / -1;
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid #e9ecef;
        }

        .selling-units-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .selling-unit-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            border: 1px solid #e9ecef;
            border-radius: 6px;
            margin-bottom: 8px;
            background: #f8f9fa;
        }

        .selling-unit-info {
            flex: 1;
        }

        .selling-unit-name {
            font-weight: 600;
            margin-bottom: 2px;
        }

        .selling-unit-details {
            font-size: 0.8rem;
            color: #64748b;
        }

        .selling-unit-price {
            font-weight: 700;
            color: var(--primary-color);
        }

        .filters-section {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border: 1px solid #e2e8f0;
        }

        .filters-row {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
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

        .expand-toggle {
            cursor: pointer;
            color: var(--primary-color);
            font-size: 0.9rem;
            text-decoration: none;
        }

        .expand-toggle:hover {
            text-decoration: underline;
        }

        .selling-units-container {
            display: none;
        }

        .selling-units-container.expanded {
            display: block;
        }

        .auto-bom-indicator {
            background: #06b6d4;
            color: white;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 8px;
        }

        .base-product-info {
            background: #f0f9ff;
            padding: 10px;
            border-radius: 6px;
            margin-bottom: 15px;
            border-left: 4px solid var(--primary-color);
        }

        .btn-small {
            padding: 6px 12px;
            font-size: 0.8rem;
            white-space: nowrap;
        }

        .products-list {
            max-width: 100%;
        }
        
        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }
        
        .main-content {
            flex: 1;
            padding: 20px;
            margin-left: 280px;
        }
        
        .row {
            display: flex;
            flex-wrap: wrap;
            margin-right: -15px;
            margin-left: -15px;
        }
        
        .col-md-3 {
            flex: 0 0 25%;
            max-width: 25%;
            padding-right: 15px;
            padding-left: 15px;
        }
        
        .col-lg-6 {
            flex: 0 0 50%;
            max-width: 50%;
            padding-right: 15px;
            padding-left: 15px;
        }
        
        .col-xl-4 {
            flex: 0 0 33.333333%;
            max-width: 33.333333%;
            padding-right: 15px;
            padding-left: 15px;
        }
        
        @media (max-width: 1199.98px) {
            .col-xl-4 {
                flex: 0 0 50%;
                max-width: 50%;
            }
        }
        
        @media (max-width: 991.98px) {
            .col-lg-6 {
                flex: 0 0 100%;
                max-width: 100%;
            }
        }
        
        .mb-4 {
            margin-bottom: 1.5rem !important;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include '../include/navmenu.php'; ?>

        <main class="main-content">
            <div class="auto-bom-header">
                <h1><i class="bi bi-gear-fill"></i> Auto BOM Products</h1>
                <p>View and manage products with Auto BOM configurations</p>
            </div>

            <!-- Statistics Cards -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="metric-card">
                        <div class="metric-value"><?php echo number_format($total_records); ?></div>
                        <div class="metric-label">Total Auto BOM Products</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="metric-card">
                        <div class="metric-value">
                            <?php
                            $active_count = array_reduce($auto_bom_products, function($count, $product) {
                                return $count + ($product['auto_bom_active'] ? 1 : 0);
                            }, 0);
                            echo number_format($active_count);
                            ?>
                        </div>
                        <div class="metric-label">Active Configurations</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="metric-card">
                        <div class="metric-value">
                            <?php
                            $total_units = array_reduce($auto_bom_products, function($count, $product) {
                                return $count + $product['selling_units_count'];
                            }, 0);
                            echo number_format($total_units);
                            ?>
                        </div>
                        <div class="metric-label">Selling Units</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="metric-card">
                        <div class="metric-value"><?php echo number_format(count($product_families)); ?></div>
                        <div class="metric-label">Product Families</div>
                    </div>
                </div>
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
                        <label class="filter-label">Category</label>
                        <select name="category" class="filter-input">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>"
                                        <?php echo $category_filter == $category['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($category['name']); ?>
                                </option>
                            <?php endforeach; ?>
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
                        <label class="filter-label">Status</label>
                        <select name="status" class="filter-input">
                            <option value="">All Status</option>
                            <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label class="filter-label">&nbsp;</label>
                        <div>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-search"></i> Filter
                            </button>
                            <a href="auto_bom_products.php" class="btn btn-secondary">
                                <i class="bi bi-x"></i> Clear
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Products Grid -->
            <div class="row">
                <?php if (empty($auto_bom_products)): ?>
                    <div class="col-12">
                        <div class="text-center py-5">
                            <i class="bi bi-gear-fill display-1 text-muted mb-3"></i>
                            <h4 class="text-muted">No Auto BOM products found</h4>
                            <p class="text-muted mb-4">Get started by creating Auto BOM configurations for your products</p>

                            <!-- Unit System Example -->
                            <div class="alert alert-info mt-4">
                                <h6><i class="bi bi-lightbulb me-2"></i>How Auto BOM Units Work:</h6>
                                <div class="row mt-3">
                                    <div class="col-md-4">
                                        <strong>Example 1 - Cooking Oil:</strong><br>
                                        Base: 20L container<br>
                                        Selling: 500ml bottles<br>
                                        Conversion: 40 bottles = 1 container
                                    </div>
                                    <div class="col-md-4">
                                        <strong>Example 2 - Rice:</strong><br>
                                        Base: 50kg bag<br>
                                        Selling: 1kg packs<br>
                                        Conversion: 50 packs = 1 bag
                                    </div>
                                    <div class="col-md-4">
                                        <strong>Example 3 - Soap:</strong><br>
                                        Base: Case of 24 bars<br>
                                        Selling: Single bars<br>
                                        Conversion: 24 bars = 1 case
                                    </div>
                                </div>
                            </div>
                            <?php if ($can_manage_auto_boms): ?>
                                <a href="auto_bom_setup.php" class="btn btn-primary">
                                    <i class="bi bi-plus-circle"></i> Create Auto BOM
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="products-list">
                    <?php foreach ($auto_bom_products as $product): ?>
                        <div class="product-card">
                            <div class="product-header">
                                <div>
                                    <div class="product-name"><?php echo htmlspecialchars($product['name']); ?></div>
                                    <div class="product-meta">
                                        SKU: <?php echo htmlspecialchars($product['sku'] ?? 'SKU000001'); ?> | Category: <?php echo htmlspecialchars($product['category_name'] ?? 'Clothing'); ?>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center">
                                    <span class="status-badge status-<?php echo $product['auto_bom_active'] ? 'active' : 'inactive'; ?>">
                                        <?php echo $product['auto_bom_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </div>
                            </div>

                            <div class="product-body">
                                <!-- Base Product Info -->
                                <div class="base-product-section">
                                    <div class="base-product-title">Base Product:</div>
                                    <div class="base-product-name"><?php echo htmlspecialchars($product['base_product_name']); ?></div>
                                    <div class="base-product-details">
                                        <div class="base-detail-item">
                                            <span class="base-detail-label">Stock:</span>
                                            <span><?php echo number_format($product['base_stock']); ?></span>
                                        </div>
                                        <div class="base-detail-item">
                                            <span class="base-detail-label">Cost:</span>
                                            <span>KES <?php echo number_format($product['base_cost'], 2); ?></span>
                                        </div>
                                        <div class="base-detail-item">
                                            <span class="base-detail-label">Unit:</span>
                                            <span><?php echo htmlspecialchars($product['base_unit']); ?> (<?php echo number_format($product['base_quantity']); ?>)</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Metrics (using grid) -->
                                <div class="product-metrics">
                                    <!-- Selling Units Column -->
                                    <div class="metric-box">
                                        <div class="metric-number"><?php echo number_format($product['selling_units_count']); ?></div>
                                        <div class="metric-label">Selling Units</div>
                                    </div>
                                    
                                    <!-- Configuration Column -->
                                    <div class="metric-box">
                                        <div class="config-label">Configuration</div>
                                        <div class="config-value"><?php echo htmlspecialchars($product['config_name'] ?? 'Ufuta 20lts'); ?></div>
                                    </div>
                                    
                                    <!-- Family Column -->
                                    <div class="metric-box">
                                        <div class="family-label">Family</div>
                                        <div class="family-value"><?php echo htmlspecialchars($product['family_name'] ?? 'None'); ?></div>
                                    </div>
                                </div>

                                <!-- Action Buttons Column -->
                                <div class="action-buttons">
                                    <?php if ($can_manage_auto_boms): ?>
                                        <a href="auto_bom_edit.php?id=<?php echo $product['auto_bom_config_id']; ?>" class="btn btn-outline-primary btn-small">
                                            <i class="bi bi-pencil"></i> Edit
                                        </a>
                                        <a href="auto_bom_pricing.php?config_id=<?php echo $product['auto_bom_config_id']; ?>" class="btn btn-outline-secondary btn-small">
                                            <i class="bi bi-tags"></i> Pricing
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($can_view_auto_boms): ?>
                                        <a href="auto_bom_reports.php?config_id=<?php echo $product['auto_bom_config_id']; ?>" class="btn btn-outline-info btn-small">
                                            <i class="bi bi-graph-up"></i> Reports
                                        </a>
                                    <?php endif; ?>
                                </div>

                                <!-- Show Details Column -->
                                <div class="show-details-column">
                                    <?php if (!empty($selling_units_data[$product['id']])): ?>
                                        <a href="#" class="expand-toggle" data-product-id="<?php echo $product['id']; ?>">
                                            <i class="bi bi-chevron-down"></i> Show Details
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Selling Units Section (spans full width) -->
                            <?php if (!empty($selling_units_data[$product['id']])): ?>
                                <div class="selling-units-section">
                                    <div class="selling-units-container" id="units-<?php echo $product['id']; ?>">
                                        <?php foreach ($selling_units_data[$product['id']] as $unit): ?>
                                            <div class="selling-unit-item">
                                                <div class="selling-unit-info">
                                                    <div class="selling-unit-name"><?php echo htmlspecialchars($unit['unit_name']); ?></div>
                                                    <div class="selling-unit-details">
                                                        <?php echo $unit['unit_quantity']; ?> <?php echo htmlspecialchars($unit['unit_name']); ?> = 1 <?php echo htmlspecialchars($product['base_unit']); ?> |
                                                        SKU: <?php echo htmlspecialchars($unit['unit_sku'] ?? 'N/A'); ?> |
                                                        Strategy: <?php echo ucfirst(str_replace('_', ' ', $unit['pricing_strategy'])); ?>
                                                    </div>
                                                </div>
                                                <div class="selling-unit-price">
                                                    <?php
                                                    if ($unit['pricing_strategy'] === 'fixed' && $unit['fixed_price']) {
                                                        echo $settings['currency_symbol'] . ' ' . number_format($unit['fixed_price'], 2);
                                                    } elseif ($unit['pricing_strategy'] === 'market_based' && $unit['market_price']) {
                                                        echo $settings['currency_symbol'] . ' ' . number_format($unit['market_price'], 2);
                                                    } else {
                                                        echo 'Auto-calculated';
                                                    }
                                                    ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <nav aria-label="Auto BOM products pagination">
                    <ul class="pagination justify-content-center">
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&category=<?php echo urlencode($category_filter); ?>&family=<?php echo urlencode($family_filter); ?>&status=<?php echo urlencode($status_filter); ?>">
                                    <i class="bi bi-chevron-left"></i>
                                </a>
                            </li>
                        <?php endif; ?>

                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&category=<?php echo urlencode($category_filter); ?>&family=<?php echo urlencode($family_filter); ?>&status=<?php echo urlencode($status_filter); ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&category=<?php echo urlencode($category_filter); ?>&family=<?php echo urlencode($family_filter); ?>&status=<?php echo urlencode($status_filter); ?>">
                                    <i class="bi bi-chevron-right"></i>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/dashboard.js"></script>

    <script>
        // Expand/collapse selling units
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.expand-toggle').forEach(toggle => {
                toggle.addEventListener('click', function(e) {
                    e.preventDefault();
                    const productId = this.dataset.productId;
                    const container = document.getElementById('units-' + productId);
                    const icon = this.querySelector('i');

                    if (container.classList.contains('expanded')) {
                        container.classList.remove('expanded');
                        icon.className = 'bi bi-chevron-down';
                        this.innerHTML = '<i class="bi bi-chevron-down"></i> Show Details';
                    } else {
                        container.classList.add('expanded');
                        icon.className = 'bi bi-chevron-up';
                        this.innerHTML = '<i class="bi bi-chevron-up"></i> Hide Details';
                    }
                });
            });
        });
    </script>
</body>
</html>
