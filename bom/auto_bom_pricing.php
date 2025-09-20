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

// Check Auto BOM pricing permissions - use granular permissions
$can_view_auto_boms = hasPermission('view_auto_boms', $permissions);
$can_manage_auto_boms = hasPermission('manage_auto_boms', $permissions);
$can_view_pricing = hasPermission('view_auto_bom_pricing', $permissions);
$can_manage_pricing = hasPermission('manage_auto_bom_pricing', $permissions);
$can_analyze_pricing = hasPermission('analyze_auto_bom_pricing', $permissions);

if (!$can_view_auto_boms && !$can_view_pricing && !$can_manage_pricing && !$can_analyze_pricing) {
    header("Location: ../dashboard/dashboard.php");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Handle form submissions
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $auto_bom_manager = new AutoBOMManager($conn, $user_id);
        
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'update_single_price':
                    $selling_unit_id = (int) $_POST['selling_unit_id'];
                    $new_price = (float) $_POST['new_price'];
                    $reason = sanitizeInput($_POST['reason'] ?? 'manual_update');
                    
                    // Update price and log change
                    $stmt = $conn->prepare("
                        UPDATE auto_bom_selling_units 
                        SET fixed_price = :price, updated_at = NOW() 
                        WHERE id = :id
                    ");
                    $stmt->execute([':price' => $new_price, ':id' => $selling_unit_id]);
                    
                    // Log the change
                    logActivity($conn, $user_id, 'update_auto_bom_price', 
                        "Updated price for selling unit ID: $selling_unit_id to $new_price. Reason: $reason");
                    
                    $message = "Price updated successfully!";
                    break;
                    
                case 'bulk_price_update':
                    $update_type = sanitizeInput($_POST['bulk_update_type']);
                    $percentage = (float) $_POST['percentage'];
                    $config_ids = $_POST['config_ids'] ?? [];
                    
                    if (!empty($config_ids)) {
                        $updated_count = 0;
                        
                        foreach ($config_ids as $config_id) {
                            $config_id = (int) $config_id;
                            
                            // Get all selling units for this config
                            $stmt = $conn->prepare("
                                SELECT id, fixed_price, pricing_strategy 
                                FROM auto_bom_selling_units 
                                WHERE auto_bom_config_id = :config_id AND status = 'active'
                            ");
                            $stmt->execute([':config_id' => $config_id]);
                            $units = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            
                            foreach ($units as $unit) {
                                $old_price = $unit['fixed_price'];
                                $new_price = 0;
                                
                                if ($update_type === 'increase') {
                                    $new_price = $old_price * (1 + $percentage / 100);
                                } elseif ($update_type === 'decrease') {
                                    $new_price = $old_price * (1 - $percentage / 100);
                                } elseif ($update_type === 'set_margin') {
                                    // Recalculate based on strategy with new margin
                                    $new_price = $auto_bom_manager->calculateSellingUnitPrice($unit['id'], ['margin_override' => $percentage]);
                                }
                                
                                if ($new_price > 0) {
                                    $stmt = $conn->prepare("
                                        UPDATE auto_bom_selling_units 
                                        SET fixed_price = :price, updated_at = NOW() 
                                        WHERE id = :id
                                    ");
                                    $stmt->execute([':price' => $new_price, ':id' => $unit['id']]);
                                    $updated_count++;
                                }
                            }
                        }
                        
                        logActivity($conn, $user_id, 'bulk_update_auto_bom_prices', 
                            "Bulk updated $updated_count selling unit prices. Type: $update_type, Percentage: $percentage%");
                        
                        $message = "Successfully updated prices for $updated_count selling units!";
                    }
                    break;
                    
                case 'recalculate_all_prices':
                    $config_ids = $_POST['config_ids'] ?? [];
                    $updated_count = 0;
                    
                    foreach ($config_ids as $config_id) {
                        $config_id = (int) $config_id;
                        $auto_bom_manager->updatePricesBasedOnStrategy($config_id);
                        $updated_count++;
                    }
                    
                    logActivity($conn, $user_id, 'recalculate_auto_bom_prices', 
                        "Recalculated prices for $updated_count Auto BOM configurations");
                    
                    $message = "Successfully recalculated prices for $updated_count configurations!";
                    break;
            }
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Handle filters
$search = $_GET['search'] ?? '';
$config_filter = $_GET['config_id'] ?? '';
$strategy_filter = $_GET['strategy'] ?? '';
$status_filter = $_GET['status'] ?? '';

// Build query for selling units with pricing info
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(su.unit_name LIKE :search OR p.name LIKE :search OR p.sku LIKE :search OR abc.config_name LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if (!empty($config_filter)) {
    $where_conditions[] = "abc.id = :config_id";
    $params[':config_id'] = $config_filter;
}

if (!empty($strategy_filter)) {
    $where_conditions[] = "su.pricing_strategy = :strategy";
    $params[':strategy'] = $strategy_filter;
}

if (!empty($status_filter)) {
    $where_conditions[] = "su.status = :status";
    $params[':status'] = $status_filter;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get selling units with pricing information
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 25;
$offset = ($page - 1) * $per_page;

$count_sql = "
    SELECT COUNT(*) as total
    FROM auto_bom_selling_units su
    INNER JOIN auto_bom_configs abc ON su.auto_bom_config_id = abc.id
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
        su.*,
        abc.config_name,
        abc.base_unit,
        abc.base_quantity,
        abc.is_active as config_active,
        p.name as product_name,
        p.sku as product_sku,
        bp.name as base_product_name,
        bp.sku as base_product_sku,
        bp.cost_price as base_cost_price,
        bp.quantity as base_stock
    FROM auto_bom_selling_units su
    INNER JOIN auto_bom_configs abc ON su.auto_bom_config_id = abc.id
    INNER JOIN products p ON abc.product_id = p.id
    INNER JOIN products bp ON abc.base_product_id = bp.id
    {$where_clause}
    ORDER BY abc.config_name ASC, su.priority DESC, su.unit_name ASC
    LIMIT {$per_page} OFFSET {$offset}
";

$stmt = $conn->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$selling_units = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get Auto BOM configurations for filter
$configs = [];
$stmt = $conn->query("
    SELECT abc.id, abc.config_name, p.name as product_name
    FROM auto_bom_configs abc
    INNER JOIN products p ON abc.product_id = p.id
    WHERE abc.is_active = 1
    ORDER BY abc.config_name ASC
");
$configs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate pricing statistics
$pricing_stats = [
    'total_units' => 0,
    'avg_margin' => 0,
    'strategies' => []
];

foreach ($selling_units as $unit) {
    $pricing_stats['total_units']++;
    
    // Calculate margin
    if ($unit['fixed_price'] && $unit['base_cost_price']) {
        $unit_cost = ($unit['base_cost_price'] / $unit['base_quantity']) * $unit['unit_quantity'];
        $margin = (($unit['fixed_price'] - $unit_cost) / $unit_cost) * 100;
        $pricing_stats['avg_margin'] += $margin;
    }
    
    // Count strategies
    $strategy = $unit['pricing_strategy'];
    $pricing_stats['strategies'][$strategy] = ($pricing_stats['strategies'][$strategy] ?? 0) + 1;
}

if ($pricing_stats['total_units'] > 0) {
    $pricing_stats['avg_margin'] = $pricing_stats['avg_margin'] / $pricing_stats['total_units'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auto BOM Pricing Management - <?php echo htmlspecialchars($settings['site_name'] ?? 'Point of Sale'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/products.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <style>
        .pricing-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            margin: -20px -20px 20px -20px;
            border-radius: 8px;
        }

        .pricing-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
            border-left: 4px solid #667eea;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: bold;
            color: #667eea;
            margin-bottom: 5px;
        }

        .stat-label {
            color: #666;
            font-size: 0.9rem;
        }

        .pricing-table {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .pricing-table-header {
            background: #f8f9fa;
            padding: 15px 20px;
            border-bottom: 1px solid #dee2e6;
            display: flex;
            justify-content: space-between;
            align-items: center;
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
            align-items: end;
            flex-wrap: wrap;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            min-width: 150px;
        }

        .filter-label {
            font-weight: bold;
            margin-bottom: 5px;
            color: #333;
            font-size: 0.9rem;
        }

        .strategy-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: bold;
            text-transform: uppercase;
        }

        .strategy-fixed { background: #e3f2fd; color: #1565c0; }
        .strategy-cost-based { background: #f3e5f5; color: #7b1fa2; }
        .strategy-market-based { background: #e8f5e8; color: #2e7d32; }
        .strategy-dynamic { background: #fff3e0; color: #f57c00; }
        .strategy-hybrid { background: #fce4ec; color: #c2185b; }

        .price-input {
            width: 100px;
        }

        .margin-indicator {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 0.8rem;
            font-weight: bold;
        }

        .margin-good { background: #d4edda; color: #155724; }
        .margin-fair { background: #fff3cd; color: #856404; }
        .margin-poor { background: #f8d7da; color: #721c24; }

        .bulk-actions {
            background: #e3f2fd;
            border: 1px solid #bbdefb;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .price-history-btn {
            font-size: 0.8rem;
            padding: 4px 8px;
        }

        .config-group {
            border-left: 3px solid #667eea;
            margin-bottom: 25px;
        }

        .config-header {
            background: #f8f9fa;
            padding: 15px;
            border-bottom: 1px solid #dee2e6;
            font-weight: bold;
            color: #333;
        }

        .unit-row {
            padding: 15px;
            border-bottom: 1px solid #f0f0f0;
        }

        .unit-row:hover {
            background: #f8f9fa;
        }

        .price-calc-info {
            font-size: 0.8rem;
            color: #666;
            margin-top: 5px;
        }

        .quick-actions {
            display: flex;
            gap: 5px;
        }

        .btn-xs {
            padding: 4px 8px;
            font-size: 0.75rem;
        }

        .alert {
            border-radius: 6px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include '../include/navmenu.php'; ?>

        <main class="main-content">
            <div class="pricing-header">
                <h1><i class="fas fa-tags"></i> Auto BOM Pricing Management</h1>
                <p>Manage pricing strategies and prices for all Auto BOM selling units</p>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <div class="pricing-stats">
                <div class="stat-card">
                    <div class="stat-value"><?php echo count($selling_units); ?></div>
                    <div class="stat-label">Selling Units</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo number_format($pricing_stats['avg_margin'], 1); ?>%</div>
                    <div class="stat-label">Average Margin</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo count($pricing_stats['strategies']); ?></div>
                    <div class="stat-label">Pricing Strategies</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo count($configs); ?></div>
                    <div class="stat-label">Active Configs</div>
                </div>
            </div>

            <!-- Navigation Buttons -->
            <div class="mb-4">
                <a href="auto_bom_index.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Auto BOM List
                </a>
                <?php if ($can_view_auto_boms): ?>
                    <a href="auto_bom_pricing_analytics.php" class="btn btn-info">
                        <i class="fas fa-chart-line"></i> Analytics Dashboard
                    </a>
                <?php endif; ?>
                <?php if ($can_manage_auto_boms): ?>
                    <a href="auto_bom_setup.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Create Auto BOM
                    </a>
                <?php endif; ?>
            </div>

            <!-- Bulk Actions Section -->
            <?php if ($can_manage_auto_boms): ?>
                <div class="bulk-actions">
                    <h4><i class="fas fa-tools"></i> Bulk Actions</h4>
                    <form method="POST" id="bulk-actions-form">
                        <input type="hidden" name="action" value="bulk_price_update">
                        
                        <div class="row">
                            <div class="col-md-3">
                                <label class="form-label">Update Type</label>
                                <select name="bulk_update_type" class="form-select" required>
                                    <option value="">Select Type</option>
                                    <option value="increase">Increase Prices</option>
                                    <option value="decrease">Decrease Prices</option>
                                    <option value="set_margin">Set Target Margin</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Percentage (%)</label>
                                <input type="number" name="percentage" class="form-control" 
                                       step="0.1" min="0" max="100" required>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label">Select Configurations</label>
                                <select name="config_ids[]" class="form-select" multiple required>
                                    <?php foreach ($configs as $config): ?>
                                        <option value="<?php echo $config['id']; ?>">
                                            <?php echo htmlspecialchars($config['config_name'] . ' - ' . $config['product_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">&nbsp;</label>
                                <button type="submit" class="btn btn-warning w-100">
                                    <i class="fas fa-calculator"></i> Apply
                                </button>
                            </div>
                        </div>
                    </form>

                    <hr class="my-3">

                    <form method="POST" class="d-inline">
                        <input type="hidden" name="action" value="recalculate_all_prices">
                        <select name="config_ids[]" class="form-select d-inline-block w-auto" multiple>
                            <?php foreach ($configs as $config): ?>
                                <option value="<?php echo $config['id']; ?>">
                                    <?php echo htmlspecialchars($config['config_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-info ms-2" 
                                onclick="return confirm('This will recalculate all prices based on their configured strategies. Continue?')">
                            <i class="fas fa-sync-alt"></i> Recalculate Strategy Prices
                        </button>
                    </form>
                </div>
            <?php endif; ?>

            <!-- Filters Section -->
            <div class="filters-section">
                <form method="GET" class="filters-row">
                    <div class="filter-group">
                        <label class="filter-label">Search</label>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                               placeholder="Search units, products..." class="form-control">
                    </div>

                    <div class="filter-group">
                        <label class="filter-label">Configuration</label>
                        <select name="config_id" class="form-select">
                            <option value="">All Configurations</option>
                            <?php foreach ($configs as $config): ?>
                                <option value="<?php echo $config['id']; ?>"
                                        <?php echo $config_filter == $config['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($config['config_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label class="filter-label">Strategy</label>
                        <select name="strategy" class="form-select">
                            <option value="">All Strategies</option>
                            <option value="fixed" <?php echo $strategy_filter === 'fixed' ? 'selected' : ''; ?>>Fixed Price</option>
                            <option value="cost_based" <?php echo $strategy_filter === 'cost_based' ? 'selected' : ''; ?>>Cost-Based</option>
                            <option value="market_based" <?php echo $strategy_filter === 'market_based' ? 'selected' : ''; ?>>Market-Based</option>
                            <option value="dynamic" <?php echo $strategy_filter === 'dynamic' ? 'selected' : ''; ?>>Dynamic</option>
                            <option value="hybrid" <?php echo $strategy_filter === 'hybrid' ? 'selected' : ''; ?>>Hybrid</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label class="filter-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="">All Status</option>
                            <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Filter
                        </button>
                        <a href="auto_bom_pricing.php" class="btn btn-secondary mt-2">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    </div>
                </form>
            </div>

            <!-- Pricing Table -->
            <div class="pricing-table">
                <div class="pricing-table-header">
                    <h3><i class="fas fa-list"></i> Selling Unit Prices</h3>
                    <div>
                        <span class="badge bg-info"><?php echo $total_records; ?> units found</span>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead class="table-dark">
                            <tr>
                                <th>Configuration</th>
                                <th>Unit Details</th>
                                <th>Strategy</th>
                                <th>Current Price</th>
                                <th>Cost Info</th>
                                <th>Margin</th>
                                <th>Stock</th>
                                <th>Status</th>
                                <?php if ($can_manage_auto_boms): ?>
                                    <th>Actions</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($selling_units)): ?>
                                <tr>
                                    <td colspan="<?php echo $can_manage_auto_boms ? 9 : 8; ?>" class="text-center py-4">
                                        <i class="fas fa-tags fa-3x text-muted mb-3"></i>
                                        <h5>No selling units found</h5>
                                        <p class="text-muted">Try adjusting your filters or create a new Auto BOM configuration.</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php
                                $current_config = '';
                                foreach ($selling_units as $unit):
                                    // Calculate derived values
                                    $unit_cost = ($unit['base_cost_price'] / $unit['base_quantity']) * $unit['unit_quantity'];
                                    $margin_amount = $unit['fixed_price'] - $unit_cost;
                                    $margin_percentage = $unit_cost > 0 ? (($margin_amount / $unit_cost) * 100) : 0;
                                    
                                    // Determine margin class
                                    $margin_class = 'margin-poor';
                                    if ($margin_percentage > 30) {
                                        $margin_class = 'margin-good';
                                    } elseif ($margin_percentage > 15) {
                                        $margin_class = 'margin-fair';
                                    }
                                    
                                    // Available stock in selling units
                                    $available_units = floor($unit['base_stock'] / $unit['unit_quantity']);
                                ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($unit['config_name']); ?></strong><br>
                                            <small class="text-muted">
                                                <?php echo htmlspecialchars($unit['product_name']); ?><br>
                                                SKU: <?php echo htmlspecialchars($unit['product_sku']); ?>
                                            </small>
                                        </td>
                                        
                                        <td>
                                            <strong><?php echo htmlspecialchars($unit['unit_name']); ?></strong><br>
                                            <small class="text-muted">
                                                Qty: <?php echo $unit['unit_quantity']; ?> <?php echo htmlspecialchars($unit['base_unit']); ?><br>
                                                <?php if ($unit['unit_sku']): ?>
                                                    SKU: <?php echo htmlspecialchars($unit['unit_sku']); ?><br>
                                                <?php endif; ?>
                                                Priority: <?php echo $unit['priority']; ?>
                                            </small>
                                        </td>
                                        
                                        <td>
                                            <span class="strategy-badge strategy-<?php echo str_replace('_', '-', $unit['pricing_strategy']); ?>">
                                                <?php echo str_replace('_', ' ', $unit['pricing_strategy']); ?>
                                            </span>
                                        </td>
                                        
                                        <td>
                                            <?php if ($can_manage_auto_boms): ?>
                                                <form method="POST" class="d-inline price-update-form">
                                                    <input type="hidden" name="action" value="update_single_price">
                                                    <input type="hidden" name="selling_unit_id" value="<?php echo $unit['id']; ?>">
                                                    <div class="input-group input-group-sm">
                                                        <span class="input-group-text"><?php echo $settings['currency_symbol'] ?? 'KES'; ?></span>
                                                        <input type="number" name="new_price" class="form-control price-input"
                                                               value="<?php echo number_format($unit['fixed_price'], 2, '.', ''); ?>"
                                                               step="0.01" min="0">
                                                        <button type="submit" class="btn btn-outline-primary btn-sm">
                                                            <i class="fas fa-save"></i>
                                                        </button>
                                                    </div>
                                                </form>
                                            <?php else: ?>
                                                <strong><?php echo formatCurrency($unit['fixed_price'], $settings); ?></strong>
                                            <?php endif; ?>
                                        </td>
                                        
                                        <td>
                                            <small>
                                                <strong>Base Cost:</strong> <?php echo formatCurrency($unit['base_cost_price'], $settings); ?><br>
                                                <strong>Unit Cost:</strong> <?php echo formatCurrency($unit_cost, $settings); ?><br>
                                                <span class="text-muted">
                                                    Base: <?php echo htmlspecialchars($unit['base_product_name']); ?>
                                                </span>
                                            </small>
                                        </td>
                                        
                                        <td>
                                            <span class="margin-indicator <?php echo $margin_class; ?>">
                                                <?php echo number_format($margin_percentage, 1); ?>%
                                            </span><br>
                                            <small class="text-muted">
                                                <?php echo formatCurrency($margin_amount, $settings); ?>
                                            </small>
                                        </td>
                                        
                                        <td>
                                            <strong><?php echo $available_units; ?></strong> units<br>
                                            <small class="text-muted">
                                                (<?php echo $unit['base_stock']; ?> <?php echo htmlspecialchars($unit['base_unit']); ?>)
                                            </small>
                                        </td>
                                        
                                        <td>
                                            <span class="badge bg-<?php echo $unit['status'] === 'active' ? 'success' : 'secondary'; ?>">
                                                <?php echo ucfirst($unit['status']); ?>
                                            </span>
                                            <?php if (!$unit['config_active']): ?>
                                                <br><small class="text-warning">Config Inactive</small>
                                            <?php endif; ?>
                                        </td>
                                        
                                        <?php if ($can_manage_auto_boms): ?>
                                            <td>
                                                <div class="quick-actions">
                                                    <button type="button" class="btn btn-outline-info btn-xs" 
                                                            onclick="calculatePrice(<?php echo $unit['id']; ?>)">
                                                        <i class="fas fa-calculator"></i>
                                                    </button>
                                                    
                                                    <?php if ($unit['pricing_strategy'] !== 'fixed'): ?>
                                                        <button type="button" class="btn btn-outline-warning btn-xs"
                                                                onclick="recalculateUnitPrice(<?php echo $unit['id']; ?>)">
                                                            <i class="fas fa-sync-alt"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <nav aria-label="Pricing pagination">
                    <ul class="pagination justify-content-center">
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?php echo $page - 1; ?>&<?php echo http_build_query($_GET); ?>">
                                    <i class="fas fa-chevron-left"></i> Previous
                                </a>
                            </li>
                        <?php endif; ?>
                        
                        <li class="page-item active">
                            <span class="page-link">
                                Page <?php echo $page; ?> of <?php echo $total_pages; ?>
                                (<?php echo $total_records; ?> total)
                            </span>
                        </li>
                        
                        <?php if ($page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?php echo $page + 1; ?>&<?php echo http_build_query($_GET); ?>">
                                    Next <i class="fas fa-chevron-right"></i>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        </main>
    </div>

    <!-- Price Calculation Modal -->
    <div class="modal fade" id="priceCalculationModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-calculator"></i> Price Calculator</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="price-calculation-content">
                    <!-- Content will be loaded here -->
                </div>
            </div>
        </div>
    </div>

    

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/dashboard.js"></script>

         <script>
         $(document).ready(function() {
             // Configure AJAX to include credentials
             $.ajaxSetup({
                 xhrFields: {
                     withCredentials: true
                 }
             });

             // Confirm price updates
             $('.price-update-form').on('submit', function(e) {
                 const unitName = $(this).closest('tr').find('td:nth-child(2) strong').text();
                 const newPrice = $(this).find('input[name="new_price"]').val();
                 
                 if (!confirm(`Update price for "${unitName}" to ${newPrice}?`)) {
                     e.preventDefault();
                 }
             });

             // Auto-submit price forms on Enter key
             $('input[name="new_price"]').on('keypress', function(e) {
                 if (e.which === 13) { // Enter key
                     $(this).closest('form').submit();
                 }
             });
         });

        // Calculate price for a selling unit
        function calculatePrice(sellingUnitId) {
            if (!sellingUnitId) {
                alert('Invalid selling unit ID');
                return;
            }

            // Show loading indicator
            $('#price-calculation-content').html('<div class="text-center py-4"><i class="fas fa-spinner fa-spin"></i> Calculating price...</div>');
            $('#priceCalculationModal').modal('show');

                         $.ajax({
                 url: '../api/auto_bom_price_calculation.php',
                 method: 'POST',
                 data: {
                     selling_unit_id: sellingUnitId,
                     quantity: 1,
                     user_id: <?php echo $user_id; ?>
                 },
                 dataType: 'json',
                 timeout: 10000
             })
            .done(function(response) {
                if (response && response.success) {
                    const data = response.data;
                    const content = `
                        <div class="row">
                            <div class="col-md-6">
                                <h6>Unit Information</h6>
                                <table class="table table-sm">
                                    <tr><td><strong>Product:</strong></td><td>${data.product_name || 'N/A'}</td></tr>
                                    <tr><td><strong>Unit:</strong></td><td>${data.unit_name || 'N/A'}</td></tr>
                                    <tr><td><strong>Strategy:</strong></td><td>${data.pricing_strategy || 'N/A'}</td></tr>
                                    <tr><td><strong>Unit Quantity:</strong></td><td>${data.unit_quantity || 'N/A'}</td></tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <h6>Price Calculation</h6>
                                <table class="table table-sm">
                                    <tr><td><strong>Unit Price:</strong></td><td>${data.formatted_unit_price || 'N/A'}</td></tr>
                                    <tr><td><strong>Total Price:</strong></td><td class="text-success"><strong>${data.formatted_total_price || 'N/A'}</strong></td></tr>
                                    <tr><td><strong>Margin:</strong></td><td>${data.margin_percentage || 0}%</td></tr>
                                    <tr><td><strong>Margin Amount:</strong></td><td>${data.currency || 'KES'} ${(data.margin_amount || 0).toFixed(2)}</td></tr>
                                </table>
                            </div>
                        </div>
                    `;
                    $('#price-calculation-content').html(content);
                } else {
                    const errorMsg = response && response.error ? response.error : 'Unknown error occurred';
                    $('#price-calculation-content').html(`<div class="alert alert-danger">Error calculating price: ${errorMsg}</div>`);
                }
            })
            .fail(function(xhr, status, error) {
                let errorMessage = 'Error connecting to price calculation service.';
                
                if (xhr.status === 401) {
                    errorMessage = 'Authentication required. Please log in again.';
                    // Try to parse response for redirect URL
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if (response.redirect) {
                            setTimeout(() => {
                                window.location.href = response.redirect;
                            }, 2000);
                        }
                    } catch (e) {
                        // Fallback redirect
                        setTimeout(() => {
                            window.location.href = '../auth/login.php';
                        }, 2000);
                    }
                } else if (xhr.status === 404) {
                    errorMessage = 'Price calculation service not found.';
                } else if (xhr.status >= 500) {
                    errorMessage = 'Server error. Please try again later.';
                }
                
                $('#price-calculation-content').html(`<div class="alert alert-danger">${errorMessage}</div>`);
            });
        }

        

        // Recalculate individual unit price
        function recalculateUnitPrice(sellingUnitId) {
            if (!sellingUnitId) {
                alert('Invalid selling unit ID');
                return;
            }

            if (!confirm('Recalculate price based on the configured strategy?')) {
                return;
            }
            
            // Show loading state on the button
            const button = $(`button[onclick="recalculateUnitPrice(${sellingUnitId})"]`);
            const originalHtml = button.html();
            button.html('<i class="fas fa-spinner fa-spin"></i>').prop('disabled', true);
            
                         $.ajax({
                 url: '../api/recalculate_unit_price.php',
                 method: 'POST',
                 data: {
                     selling_unit_id: sellingUnitId,
                     user_id: <?php echo $user_id; ?>
                 },
                 dataType: 'json',
                 timeout: 10000
             })
            .done(function(response) {
                if (response && response.success) {
                    if (response.data && response.data.price_changed) {
                        const data = response.data;
                        const changeMsg = `Price updated from ${data.formatted_old_price} to ${data.formatted_new_price} (${data.price_change_percentage.toFixed(1)}% change)`;
                        alert(`Price recalculated successfully!\n\n${changeMsg}`);
                    } else {
                        alert('Price recalculated - no change needed.');
                    }
                    location.reload();
                } else {
                    const errorMsg = response && response.error ? response.error : 'Unknown error occurred';
                    alert(`Error recalculating price: ${errorMsg}`);
                }
            })
            .fail(function(xhr, status, error) {
                let errorMessage = 'Error connecting to recalculation service.';
                
                if (xhr.status === 401) {
                    errorMessage = 'Authentication required. Please log in again.';
                    // Try to parse response for redirect URL
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if (response.redirect) {
                            alert(errorMessage);
                            setTimeout(() => {
                                window.location.href = response.redirect;
                            }, 2000);
                            return;
                        }
                    } catch (e) {
                        // Fallback redirect
                        alert(errorMessage);
                        setTimeout(() => {
                            window.location.href = '../auth/login.php';
                        }, 2000);
                        return;
                    }
                } else if (xhr.status === 404) {
                    errorMessage = 'Price recalculation service not found.';
                } else if (xhr.status >= 500) {
                    errorMessage = 'Server error. Please try again later.';
                }
                
                alert(errorMessage);
            })
            .always(function() {
                // Restore button state
                button.html(originalHtml).prop('disabled', false);
            });
        }

        // Strategy change notifications
        function showStrategyInfo(strategy) {
            const strategies = {
                'fixed': 'Uses a fixed price set by the user.',
                'cost_based': 'Calculates price based on cost plus markup percentage.',
                'market_based': 'Uses market price as the selling price.',
                'dynamic': 'Adjusts price based on stock levels and demand.',
                'hybrid': 'Combines multiple strategies based on conditions.'
            };
            
            alert(strategies[strategy] || 'Unknown pricing strategy.');
        }
    </script>
</body>
</html>
