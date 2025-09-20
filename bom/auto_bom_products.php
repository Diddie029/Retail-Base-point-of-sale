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
$can_view_auto_boms = hasPermission('view_auto_boms', $permissions);
$can_manage_auto_boms = hasPermission('manage_auto_boms', $permissions);
$can_manage_product_families = hasPermission('manage_product_families', $permissions);
$can_view_product_families = hasPermission('view_product_families', $permissions);
$can_assign_families = hasPermission('assign_product_families', $permissions);

if (!$can_view_auto_boms && !$can_manage_product_families && !$can_view_product_families && !$can_assign_families) {
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
$per_page = intval($_GET['per_page'] ?? 12);
$per_page = in_array($per_page, [10, 20, 50, 100]) ? $per_page : 12; // Validate per_page value
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

            <!-- Display Controls and Product Count -->
            <div class="row mb-3">
                <div class="col-md-6">
                    <div class="d-flex align-items-center">
                        <span class="me-3">
                            <strong>Display Mode:</strong>
                        </span>
                        <div class="btn-group" role="group" aria-label="Display mode">
                            <button type="button" class="btn btn-outline-primary active" id="table-view-btn">
                                <i class="bi bi-table"></i> Table View
                            </button>
                            <button type="button" class="btn btn-outline-secondary" id="grid-view-btn">
                                <i class="bi bi-grid-3x3"></i> Grid View
                            </button>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="d-flex justify-content-end align-items-center">
                        <span class="me-3">
                            <strong>Products per page:</strong>
                        </span>
                        <select class="form-select form-select-sm" id="per-page-select" style="width: auto;">
                            <option value="10" <?php echo $per_page == 10 ? 'selected' : ''; ?>>10</option>
                            <option value="20" <?php echo $per_page == 20 ? 'selected' : ''; ?>>20</option>
                            <option value="50" <?php echo $per_page == 50 ? 'selected' : ''; ?>>50</option>
                            <option value="100" <?php echo $per_page == 100 ? 'selected' : ''; ?>>100</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Product Count and Results Info -->
            <div class="row mb-3">
                <div class="col-12">
                    <div class="alert alert-info d-flex justify-content-between align-items-center">
                        <div>
                            <i class="bi bi-info-circle me-2"></i>
                            Showing <strong><?php echo number_format(count($auto_bom_products)); ?></strong> of <strong><?php echo number_format($total_records); ?></strong> Auto BOM products
                            <?php if (!empty($search) || !empty($category_filter) || !empty($family_filter) || !empty($status_filter)): ?>
                                (filtered results)
                            <?php endif; ?>
                        </div>
                        <div>
                            Page <strong><?php echo $page; ?></strong> of <strong><?php echo $total_pages; ?></strong>
                        </div>
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

            <!-- Auto BOM Products Display -->
            <?php if (empty($auto_bom_products)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-gear-fill display-1 text-muted mb-3"></i>
                    <h4 class="text-muted">No Auto BOM products found</h4>
                    <p class="text-muted mb-4">Get started by creating Auto BOM configurations for your products</p>
                    <?php if ($can_manage_auto_boms): ?>
                        <a href="auto_bom_setup.php" class="btn btn-primary">
                            <i class="bi bi-plus-circle"></i> Create Auto BOM
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <!-- Table View -->
                <div id="table-view" class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead class="table-dark">
                            <tr>
                                <th>Configuration</th>
                                <th>Sellable Product</th>
                                <th>Base Product</th>
                                <th>Base Unit</th>
                                <th>Selling Units</th>
                                <th>Family</th>
                                <th>Stock</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($auto_bom_products as $product): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($product['config_name']); ?></strong>
                                </td>
                                <td>
                                    <div>
                                        <strong><?php echo htmlspecialchars($product['name']); ?></strong><br>
                                        <small class="text-muted">SKU: <?php echo htmlspecialchars($product['sku']); ?></small><br>
                                        <small class="text-muted">Category: <?php echo htmlspecialchars($product['category_name'] ?? 'N/A'); ?></small>
                                    </div>
                                </td>
                                <td>
                                    <div>
                                        <?php echo htmlspecialchars($product['base_product_name']); ?><br>
                                        <small class="text-muted">SKU: <?php echo htmlspecialchars($product['base_product_sku']); ?></small>
                                    </div>
                                </td>
                                <td>
                                    <?php echo number_format($product['base_quantity']); ?> <?php echo htmlspecialchars($product['base_unit']); ?>
                                </td>
                                <td>
                                    <span class="badge bg-primary"><?php echo number_format($product['selling_units_count']); ?> units</span>
                                </td>
                                <td>
                                    <?php if ($product['family_name']): ?>
                                        <span class="badge bg-info"><?php echo htmlspecialchars($product['family_name']); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">None</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo number_format($product['base_stock']); ?></strong>
                                    <?php if ($product['base_stock'] > 50): ?>
                                        <span class="badge bg-success ms-1">Good</span>
                                    <?php elseif ($product['base_stock'] > 10): ?>
                                        <span class="badge bg-warning ms-1">Low</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger ms-1">Critical</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge <?php echo $product['auto_bom_active'] ? 'bg-success' : 'bg-secondary'; ?>">
                                        <?php echo $product['auto_bom_active'] ? 'ACTIVE' : 'INACTIVE'; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo date('M j, Y', strtotime($product['created_at'] ?? 'now')); ?>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm" role="group">
                                        <?php if ($can_manage_auto_boms): ?>
                                        <a href="auto_bom_edit.php?id=<?php echo $product['auto_bom_config_id']; ?>" class="btn btn-outline-primary btn-sm" title="Edit">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <a href="auto_bom_pricing.php?config_id=<?php echo $product['auto_bom_config_id']; ?>" class="btn btn-outline-secondary btn-sm" title="Pricing">
                                            <i class="bi bi-tags"></i>
                                        </a>
                                        <?php endif; ?>
                                        <?php if ($can_view_auto_boms): ?>
                                        <a href="auto_bom_reports.php?config_id=<?php echo $product['auto_bom_config_id']; ?>" class="btn btn-outline-info btn-sm" title="Reports">
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

                <!-- Grid View -->
                <div id="grid-view" class="row" style="display: none;">
                    <?php foreach ($auto_bom_products as $product): ?>
                    <div class="col-xl-4 col-lg-6 col-md-6 mb-4">
                        <div class="card h-100 shadow-sm">
                            <div class="card-header bg-primary text-white">
                                <h6 class="card-title mb-0">
                                    <i class="bi bi-gear-fill me-2"></i>
                                    <?php echo htmlspecialchars($product['config_name']); ?>
                                </h6>
                            </div>
                            <div class="card-body">
                                <!-- Sellable Product -->
                                <div class="mb-3">
                                    <h6 class="text-success">
                                        <i class="bi bi-cart-check me-1"></i> Sellable Product
                                    </h6>
                                    <p class="mb-1"><strong><?php echo htmlspecialchars($product['name']); ?></strong></p>
                                    <small class="text-muted">SKU: <?php echo htmlspecialchars($product['sku']); ?></small><br>
                                    <small class="text-muted">Category: <?php echo htmlspecialchars($product['category_name'] ?? 'N/A'); ?></small>
                                </div>

                                <!-- Base Product -->
                                <div class="mb-3">
                                    <h6 class="text-warning">
                                        <i class="bi bi-box me-1"></i> Base Product
                                    </h6>
                                    <p class="mb-1"><?php echo htmlspecialchars($product['base_product_name']); ?></p>
                                    <small class="text-muted">SKU: <?php echo htmlspecialchars($product['base_product_sku']); ?></small>
                                </div>

                                <!-- Details -->
                                <div class="row text-center mb-3">
                                    <div class="col-4">
                                        <div class="border rounded p-2">
                                            <div class="fw-bold text-primary"><?php echo number_format($product['base_quantity']); ?></div>
                                            <small class="text-muted">Base Qty</small>
                                        </div>
                                    </div>
                                    <div class="col-4">
                                        <div class="border rounded p-2">
                                            <div class="fw-bold text-info"><?php echo number_format($product['selling_units_count']); ?></div>
                                            <small class="text-muted">Selling Units</small>
                                        </div>
                                    </div>
                                    <div class="col-4">
                                        <div class="border rounded p-2">
                                            <div class="fw-bold text-success"><?php echo number_format($product['base_stock']); ?></div>
                                            <small class="text-muted">Stock</small>
                                        </div>
                                    </div>
                                </div>

                                <!-- Status and Family -->
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <span class="badge <?php echo $product['auto_bom_active'] ? 'bg-success' : 'bg-secondary'; ?>">
                                        <?php echo $product['auto_bom_active'] ? 'ACTIVE' : 'INACTIVE'; ?>
                                    </span>
                                    <?php if ($product['family_name']): ?>
                                        <span class="badge bg-info"><?php echo htmlspecialchars($product['family_name']); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">No Family</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="card-footer bg-light">
                                <div class="btn-group w-100" role="group">
                                    <?php if ($can_manage_auto_boms): ?>
                                    <a href="auto_bom_edit.php?id=<?php echo $product['auto_bom_config_id']; ?>" class="btn btn-outline-primary btn-sm">
                                        <i class="bi bi-pencil"></i> Edit
                                    </a>
                                    <a href="auto_bom_pricing.php?config_id=<?php echo $product['auto_bom_config_id']; ?>" class="btn btn-outline-secondary btn-sm">
                                        <i class="bi bi-tags"></i> Pricing
                                    </a>
                                    <?php endif; ?>
                                    <?php if ($can_view_auto_boms): ?>
                                    <a href="auto_bom_reports.php?config_id=<?php echo $product['auto_bom_config_id']; ?>" class="btn btn-outline-info btn-sm">
                                        <i class="bi bi-graph-up"></i> Reports
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <nav aria-label="Auto BOM products pagination">
                    <ul class="pagination justify-content-center">
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&category=<?php echo urlencode($category_filter); ?>&family=<?php echo urlencode($family_filter); ?>&status=<?php echo urlencode($status_filter); ?>&per_page=<?php echo $per_page; ?>">
                                    <i class="bi bi-chevron-left"></i>
                                </a>
                            </li>
                        <?php endif; ?>

                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&category=<?php echo urlencode($category_filter); ?>&family=<?php echo urlencode($family_filter); ?>&status=<?php echo urlencode($status_filter); ?>&per_page=<?php echo $per_page; ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&category=<?php echo urlencode($category_filter); ?>&family=<?php echo urlencode($family_filter); ?>&status=<?php echo urlencode($status_filter); ?>&per_page=<?php echo $per_page; ?>">
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
        document.addEventListener('DOMContentLoaded', function() {
            // Display mode switching
            const tableViewBtn = document.getElementById('table-view-btn');
            const gridViewBtn = document.getElementById('grid-view-btn');
            const tableView = document.getElementById('table-view');
            const gridView = document.getElementById('grid-view');
            const perPageSelect = document.getElementById('per-page-select');

            // Table view button
            tableViewBtn.addEventListener('click', function() {
                // Update button states
                tableViewBtn.classList.remove('btn-outline-secondary');
                tableViewBtn.classList.add('btn-outline-primary', 'active');
                gridViewBtn.classList.remove('btn-outline-primary', 'active');
                gridViewBtn.classList.add('btn-outline-secondary');

                // Show/hide views
                tableView.style.display = 'block';
                gridView.style.display = 'none';
            });

            // Grid view button
            gridViewBtn.addEventListener('click', function() {
                // Update button states
                gridViewBtn.classList.remove('btn-outline-secondary');
                gridViewBtn.classList.add('btn-outline-primary', 'active');
                tableViewBtn.classList.remove('btn-outline-primary', 'active');
                tableViewBtn.classList.add('btn-outline-secondary');

                // Show/hide views
                gridView.style.display = 'block';
                tableView.style.display = 'none';
            });

            // Per page selection
            perPageSelect.addEventListener('change', function() {
                const currentUrl = new URL(window.location);
                currentUrl.searchParams.set('per_page', this.value);
                currentUrl.searchParams.set('page', '1'); // Reset to first page
                window.location.href = currentUrl.toString();
            });

        });
    </script>
</body>
</html>
