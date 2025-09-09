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
        WHERE rp.role_id = ?
    ");
    $stmt->execute([$role_id]);
    $permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Check if user has permission to view inventory analytics
if (!hasPermission('view_inventory', $permissions)) {
    die('Access denied. You do not have permission to view inventory analytics.');
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Initialize analytics data
$analytics = [];

try {
    // Total Products Count
    $stmt = $conn->prepare("SELECT COUNT(*) as total_products FROM products WHERE status = 'active'");
    $stmt->execute();
    $analytics['total_products'] = $stmt->fetch(PDO::FETCH_ASSOC)['total_products'];

    // Low Stock Products (below reorder point)
    $stmt = $conn->prepare("
        SELECT COUNT(*) as low_stock_count 
        FROM products 
        WHERE status = 'active' 
        AND quantity <= reorder_point 
        AND reorder_point > 0
    ");
    $stmt->execute();
    $analytics['low_stock_count'] = $stmt->fetch(PDO::FETCH_ASSOC)['low_stock_count'];

    // Out of Stock Products
    $stmt = $conn->prepare("
        SELECT COUNT(*) as out_of_stock_count 
        FROM products 
        WHERE status = 'active' 
        AND quantity = 0
    ");
    $stmt->execute();
    $analytics['out_of_stock_count'] = $stmt->fetch(PDO::FETCH_ASSOC)['out_of_stock_count'];

    // Total Inventory Value
    $stmt = $conn->prepare("
        SELECT SUM(quantity * cost_price) as total_inventory_value 
        FROM products 
        WHERE status = 'active'
    ");
    $stmt->execute();
    $analytics['total_inventory_value'] = $stmt->fetch(PDO::FETCH_ASSOC)['total_inventory_value'] ?? 0;

    // Top Categories by Product Count
    $stmt = $conn->prepare("
        SELECT 
            c.name as category_name,
            COUNT(p.id) as product_count,
            SUM(p.quantity) as total_quantity,
            SUM(p.quantity * p.cost_price) as total_value
        FROM categories c
        LEFT JOIN products p ON c.id = p.category_id AND p.status = 'active'
        GROUP BY c.id, c.name
        ORDER BY product_count DESC
    ");
    $stmt->execute();
    $analytics['top_categories'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Top Brands by Product Count
    $stmt = $conn->prepare("
        SELECT 
            b.name as brand_name,
            COUNT(p.id) as product_count,
            SUM(p.quantity) as total_quantity,
            SUM(p.quantity * p.cost_price) as total_value
        FROM brands b
        LEFT JOIN products p ON b.id = p.brand_id AND p.status = 'active'
        GROUP BY b.id, b.name
        ORDER BY product_count DESC
    ");
    $stmt->execute();
    $analytics['top_brands'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Low Stock Products Details
    $stmt = $conn->prepare("
        SELECT 
            p.name as product_name,
            p.sku,
            p.quantity,
            p.reorder_point,
            p.cost_price,
            c.name as category_name,
            b.name as brand_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN brands b ON p.brand_id = b.id
        WHERE p.status = 'active' 
        AND p.quantity <= p.reorder_point 
        AND p.reorder_point > 0
        ORDER BY (p.quantity - p.reorder_point) ASC
    ");
    $stmt->execute();
    $analytics['low_stock_products'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Recent Stock Movements (Last 30 Days) - Grouped by Product and Date
    $stmt = $conn->prepare("
        SELECT 
            p.name as product_name,
            p.sku,
            SUM(si.quantity) as total_quantity_sold,
            AVG(si.unit_price) as avg_unit_price,
            SUM(si.total_price) as total_revenue,
            DATE(s.sale_date) as sale_date,
            COUNT(DISTINCT s.id) as transaction_count
        FROM sales s
        JOIN sale_items si ON s.id = si.sale_id
        JOIN products p ON si.product_id = p.id
        WHERE s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY p.id, p.name, p.sku, DATE(s.sale_date)
        ORDER BY sale_date DESC, total_quantity_sold DESC
    ");
    $stmt->execute();
    $analytics['recent_movements'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Inventory Turnover Analysis (Last 30 Days)
    $stmt = $conn->prepare("
        SELECT 
            p.name as product_name,
            p.sku,
            p.quantity as current_stock,
            COALESCE(SUM(si.quantity), 0) as quantity_sold,
            CASE 
                WHEN p.quantity > 0 THEN COALESCE(SUM(si.quantity), 0) / p.quantity 
                ELSE 0 
            END as turnover_ratio
        FROM products p
        LEFT JOIN sale_items si ON p.id = si.product_id
        LEFT JOIN sales s ON si.sale_id = s.id AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        WHERE p.status = 'active'
        GROUP BY p.id, p.name, p.sku, p.quantity
        HAVING quantity_sold > 0
        ORDER BY turnover_ratio DESC
    ");
    $stmt->execute();
    $analytics['turnover_analysis'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Database error in inventory analytics: " . $e->getMessage());
    $analytics = [
        'total_products' => 0,
        'low_stock_count' => 0,
        'out_of_stock_count' => 0,
        'total_inventory_value' => 0,
        'top_categories' => [],
        'top_brands' => [],
        'low_stock_products' => [],
        'recent_movements' => [],
        'turnover_analysis' => []
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Analytics - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
        
        .analytics-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1rem;
        }
        
        .analytics-card.products {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
        }
        
        .analytics-card.stock {
            background: linear-gradient(135deg, #fc466b 0%, #3f5efb 100%);
        }
        
        .analytics-card.value {
            background: linear-gradient(135deg, #fdbb2d 0%, #22c1c3 100%);
        }
        
        .analytics-card.warning {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
        
        .chart-container {
            position: relative;
            height: 400px;
            margin: 20px 0;
        }
        
        .table-responsive {
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .stat-card {
            border: none;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.2);
        }
        
        .inventory-item {
            padding: 0.75rem;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .inventory-item:hover {
            background-color: #f8f9fa;
            border-color: #dee2e6;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .low-stock {
            border-left: 4px solid #dc3545;
        }
        
        .out-of-stock {
            border-left: 4px solid #6c757d;
        }
        
        .quick-actions {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 1.5rem;
        }
        
        .quick-action-btn {
            background: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: white;
            transition: all 0.3s ease;
        }
        
        .quick-action-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            color: white;
            transform: translateY(-2px);
        }
        
        .scrollable-container {
            max-height: 400px;
            overflow-y: auto;
            padding-right: 10px;
        }
        
        .scrollable-container::-webkit-scrollbar {
            width: 6px;
        }
        
        .scrollable-container::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 3px;
        }
        
        .scrollable-container::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 3px;
        }
        
        .scrollable-container::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }
        
        .container-controls {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
        }
        
        .search-input {
            border: 1px solid #ced4da;
            border-radius: 6px;
            padding: 8px 12px;
            font-size: 14px;
        }
        
        .filter-select, .sort-select {
            border: 1px solid #ced4da;
            border-radius: 6px;
            padding: 8px 12px;
            font-size: 14px;
            background: white;
        }
        
        .export-btn {
            background: #28a745;
            border: none;
            color: white;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .export-btn:hover {
            background: #218838;
            transform: translateY(-1px);
        }
        
        .no-results {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }
        
        .results-count {
            font-size: 12px;
            color: #6c757d;
            margin-top: 5px;
        }
    </style>
</head>
<body class="bg-light">
    <?php include '../include/navmenu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid py-4">
            <!-- Header -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h1 class="h3 mb-0"><i class="bi bi-boxes"></i> Inventory Analytics</h1>
                            <p class="text-muted mb-0">Comprehensive inventory insights and performance metrics</p>
                        </div>
                        <div>
                            <a href="index.php" class="btn btn-primary">
                                <i class="bi bi-arrow-left"></i> Back to Analytics
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Key Metrics -->
            <div class="row mb-4">
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card stat-card">
                        <div class="card-body">
                            <div class="analytics-card products">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h6 class="mb-0">Total Products</h6>
                                    <i class="bi bi-box fs-4"></i>
                                </div>
                                <h3 class="mb-0"><?php echo number_format($analytics['total_products']); ?></h3>
                                <small class="opacity-75">Active products in inventory</small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card stat-card">
                        <div class="card-body">
                            <div class="analytics-card warning">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h6 class="mb-0">Low Stock</h6>
                                    <i class="bi bi-exclamation-triangle fs-4"></i>
                                </div>
                                <h3 class="mb-0"><?php echo number_format($analytics['low_stock_count']); ?></h3>
                                <small class="opacity-75">Products below reorder point</small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card stat-card">
                        <div class="card-body">
                            <div class="analytics-card stock">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h6 class="mb-0">Out of Stock</h6>
                                    <i class="bi bi-x-circle fs-4"></i>
                                </div>
                                <h3 class="mb-0"><?php echo number_format($analytics['out_of_stock_count']); ?></h3>
                                <small class="opacity-75">Products with zero quantity</small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card stat-card">
                        <div class="card-body">
                            <div class="analytics-card value">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h6 class="mb-0">Total Value</h6>
                                    <i class="bi bi-currency-dollar fs-4"></i>
                                </div>
                                <h3 class="mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($analytics['total_inventory_value'], 2); ?></h3>
                                <small class="opacity-75">Total inventory value</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Categories and Brands Performance -->
            <div class="row mb-4">
                <!-- Top Categories -->
                <div class="col-xl-6 mb-4">
                    <div class="card stat-card" id="categories-container">
                        <div class="card-header">
                            <h5 class="card-title mb-0"><i class="bi bi-tags"></i> Top Categories by Product Count</h5>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($analytics['top_categories'])): ?>
                            <!-- Controls -->
                            <div class="container-controls">
                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <input type="text" class="form-control search-input" placeholder="Search categories...">
                                    </div>
                                    <div class="col-md-3">
                                        <select class="form-select filter-select">
                                            <option value="">All Categories</option>
                                            <option value="has-products">Has Products</option>
                                            <option value="has-value">Has Value</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <select class="form-select sort-select">
                                            <option value="count-desc">Product Count (High to Low)</option>
                                            <option value="count-asc">Product Count (Low to High)</option>
                                            <option value="name-asc">Name (A to Z)</option>
                                            <option value="name-desc">Name (Z to A)</option>
                                            <option value="value-desc">Value (High to Low)</option>
                                            <option value="value-asc">Value (Low to High)</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <button class="btn export-btn w-100">
                                            <i class="bi bi-file-earmark-excel"></i> Export Excel
                                        </button>
                                    </div>
                                </div>
                                <div class="results-count"></div>
                            </div>
                            <div class="category-list scrollable-container">
                                <?php foreach ($analytics['top_categories'] as $index => $category): ?>
                                <div class="inventory-item d-flex justify-content-between align-items-center mb-3">
                                    <div class="d-flex align-items-center">
                                        <div class="category-rank me-3">
                                            <span class="badge bg-primary rounded-circle"><?php echo $index + 1; ?></span>
                                        </div>
                                        <div>
                                            <h6 class="mb-0"><?php echo htmlspecialchars($category['category_name']); ?></h6>
                                            <small class="text-muted"><?php echo number_format($category['product_count'] ?? 0); ?> products</small>
                                        </div>
                                    </div>
                                    <div class="text-end">
                                        <h6 class="mb-0 text-success"><?php echo number_format($category['total_quantity'] ?? 0); ?> units</h6>
                                        <small class="text-muted"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($category['total_value'] ?? 0, 2); ?></small>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <div class="text-center py-4">
                                <i class="bi bi-tags fs-1 text-muted"></i>
                                <p class="text-muted mt-2">No category data available</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Top Brands -->
                <div class="col-xl-6 mb-4">
                    <div class="card stat-card" id="brands-container">
                        <div class="card-header">
                            <h5 class="card-title mb-0"><i class="bi bi-award"></i> Top Brands by Product Count</h5>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($analytics['top_brands'])): ?>
                            <!-- Controls -->
                            <div class="container-controls">
                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <input type="text" class="form-control search-input" placeholder="Search brands...">
                                    </div>
                                    <div class="col-md-3">
                                        <select class="form-select filter-select">
                                            <option value="">All Brands</option>
                                            <option value="has-products">Has Products</option>
                                            <option value="has-value">Has Value</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <select class="form-select sort-select">
                                            <option value="count-desc">Product Count (High to Low)</option>
                                            <option value="count-asc">Product Count (Low to High)</option>
                                            <option value="name-asc">Name (A to Z)</option>
                                            <option value="name-desc">Name (Z to A)</option>
                                            <option value="value-desc">Value (High to Low)</option>
                                            <option value="value-asc">Value (Low to High)</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <button class="btn export-btn w-100">
                                            <i class="bi bi-file-earmark-excel"></i> Export Excel
                                        </button>
                                    </div>
                                </div>
                                <div class="results-count"></div>
                            </div>
                            <div class="brand-list scrollable-container">
                                <?php foreach ($analytics['top_brands'] as $index => $brand): ?>
                                <div class="inventory-item d-flex justify-content-between align-items-center mb-3">
                                    <div class="d-flex align-items-center">
                                        <div class="brand-rank me-3">
                                            <span class="badge bg-success rounded-circle"><?php echo $index + 1; ?></span>
                                        </div>
                                        <div>
                                            <h6 class="mb-0"><?php echo htmlspecialchars($brand['brand_name']); ?></h6>
                                            <small class="text-muted"><?php echo number_format($brand['product_count'] ?? 0); ?> products</small>
                                        </div>
                                    </div>
                                    <div class="text-end">
                                        <h6 class="mb-0 text-primary"><?php echo number_format($brand['total_quantity'] ?? 0); ?> units</h6>
                                        <small class="text-muted"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($brand['total_value'] ?? 0, 2); ?></small>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <div class="text-center py-4">
                                <i class="bi bi-award fs-1 text-muted"></i>
                                <p class="text-muted mt-2">No brand data available</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Low Stock Products and Recent Movements -->
            <div class="row mb-4">
                <!-- Low Stock Products -->
                <div class="col-xl-6 mb-4">
                    <div class="card stat-card" id="low-stock-container">
                        <div class="card-header">
                            <h5 class="card-title mb-0"><i class="bi bi-exclamation-triangle"></i> Low Stock Products</h5>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($analytics['low_stock_products'])): ?>
                            <!-- Controls -->
                            <div class="container-controls">
                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <input type="text" class="form-control search-input" placeholder="Search products...">
                                    </div>
                                    <div class="col-md-3">
                                        <select class="form-select filter-select">
                                            <option value="">All Low Stock</option>
                                            <option value="low-stock">Low Stock Only</option>
                                            <option value="has-value">Has Value</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <select class="form-select sort-select">
                                            <option value="name-asc">Name (A to Z)</option>
                                            <option value="name-desc">Name (Z to A)</option>
                                            <option value="count-asc">Quantity (Low to High)</option>
                                            <option value="count-desc">Quantity (High to Low)</option>
                                            <option value="value-desc">Value (High to Low)</option>
                                            <option value="value-asc">Value (Low to High)</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <button class="btn export-btn w-100">
                                            <i class="bi bi-file-earmark-excel"></i> Export Excel
                                        </button>
                                    </div>
                                </div>
                                <div class="results-count"></div>
                            </div>
                            <div class="low-stock-list scrollable-container">
                                <?php foreach ($analytics['low_stock_products'] as $product): ?>
                                <div class="inventory-item low-stock mb-3">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h6 class="mb-1"><?php echo htmlspecialchars($product['product_name']); ?></h6>
                                            <small class="text-muted">SKU: <?php echo htmlspecialchars($product['sku']); ?></small>
                                            <br>
                                            <small class="text-muted">
                                                Category: <?php echo htmlspecialchars($product['category_name'] ?? 'N/A'); ?>
                                                <?php if ($product['brand_name']): ?>
                                                | Brand: <?php echo htmlspecialchars($product['brand_name']); ?>
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                        <div class="text-end">
                                            <h6 class="mb-1 text-danger"><?php echo number_format($product['quantity']); ?> / <?php echo number_format($product['reorder_point']); ?></h6>
                                            <small class="text-muted">Current / Reorder Point</small>
                                            <br>
                                            <small class="text-success"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($product['cost_price'], 2); ?></small>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <div class="text-center py-4">
                                <i class="bi bi-check-circle fs-1 text-success"></i>
                                <p class="text-muted mt-2">No low stock products</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Recent Stock Movements -->
                <div class="col-xl-6 mb-4">
                    <div class="card stat-card" id="movements-container">
                        <div class="card-header">
                            <h5 class="card-title mb-0"><i class="bi bi-arrow-repeat"></i> Recent Stock Movements (30 Days)</h5>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($analytics['recent_movements'])): ?>
                            <!-- Controls -->
                            <div class="container-controls">
                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <input type="text" class="form-control search-input" placeholder="Search movements...">
                                    </div>
                                    <div class="col-md-3">
                                        <select class="form-select filter-select">
                                            <option value="">All Movements</option>
                                            <option value="has-value">Has Value</option>
                                            <option value="low-stock">High Volume</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <select class="form-select sort-select">
                                            <option value="count-desc">Quantity (High to Low)</option>
                                            <option value="count-asc">Quantity (Low to High)</option>
                                            <option value="name-asc">Product (A to Z)</option>
                                            <option value="name-desc">Product (Z to A)</option>
                                            <option value="value-desc">Value (High to Low)</option>
                                            <option value="value-asc">Value (Low to High)</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <button class="btn export-btn w-100">
                                            <i class="bi bi-file-earmark-excel"></i> Export Excel
                                        </button>
                                    </div>
                                </div>
                                <div class="results-count"></div>
                            </div>
                            <div class="movements-list scrollable-container">
                                <?php foreach ($analytics['recent_movements'] as $movement): ?>
                                <div class="inventory-item mb-3">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h6 class="mb-1"><?php echo htmlspecialchars($movement['product_name']); ?></h6>
                                            <small class="text-muted">SKU: <?php echo htmlspecialchars($movement['sku']); ?></small>
                                            <br>
                                            <small class="text-muted">
                                                <?php echo date('M j, Y', strtotime($movement['sale_date'])); ?>
                                                <?php if ($movement['transaction_count'] > 1): ?>
                                                    (<?php echo $movement['transaction_count']; ?> transactions)
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                        <div class="text-end">
                                            <h6 class="mb-1 text-danger">-<?php echo number_format($movement['total_quantity_sold']); ?> pcs</h6>
                                            <small class="text-muted">Total Sold Today</small>
                                            <br>
                                            <small class="text-success"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($movement['total_revenue'], 2); ?></small>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <div class="text-center py-4">
                                <i class="bi bi-arrow-repeat fs-1 text-muted"></i>
                                <p class="text-muted mt-2">No recent movements</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Turnover Analysis -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card stat-card" id="turnover-container">
                        <div class="card-header">
                            <h5 class="card-title mb-0"><i class="bi bi-graph-up-arrow"></i> Inventory Turnover Analysis (30 Days)</h5>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($analytics['turnover_analysis'])): ?>
                            <!-- Controls -->
                            <div class="container-controls">
                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <input type="text" class="form-control search-input" placeholder="Search products...">
                                    </div>
                                    <div class="col-md-3">
                                        <select class="form-select filter-select">
                                            <option value="">All Products</option>
                                            <option value="high-turnover">High Turnover (>1.0)</option>
                                            <option value="medium-turnover">Medium Turnover (0.5-1.0)</option>
                                            <option value="low-turnover">Low Turnover (<0.5)</option>
                                            <option value="has-stock">Has Stock</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <select class="form-select sort-select">
                                            <option value="turnover-desc">Turnover Ratio (High to Low)</option>
                                            <option value="turnover-asc">Turnover Ratio (Low to High)</option>
                                            <option value="name-asc">Product (A to Z)</option>
                                            <option value="name-desc">Product (Z to A)</option>
                                            <option value="stock-desc">Stock (High to Low)</option>
                                            <option value="stock-asc">Stock (Low to High)</option>
                                            <option value="sold-desc">Quantity Sold (High to Low)</option>
                                            <option value="sold-asc">Quantity Sold (Low to High)</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <button class="btn export-btn w-100">
                                            <i class="bi bi-file-earmark-excel"></i> Export Excel
                                        </button>
                                    </div>
                                </div>
                                <div class="results-count"></div>
                            </div>
                            <div class="table-responsive scrollable-container">
                                <table class="table table-hover" id="turnover-table">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Product</th>
                                            <th>SKU</th>
                                            <th>Current Stock</th>
                                            <th>Quantity Sold</th>
                                            <th>Turnover Ratio</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($analytics['turnover_analysis'] as $item): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                                            <td><?php echo htmlspecialchars($item['sku']); ?></td>
                                            <td><?php echo number_format($item['current_stock']); ?></td>
                                            <td><?php echo number_format($item['quantity_sold']); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo $item['turnover_ratio'] > 1 ? 'success' : ($item['turnover_ratio'] > 0.5 ? 'warning' : 'danger'); ?>">
                                                    <?php echo number_format($item['turnover_ratio'], 2); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($item['turnover_ratio'] > 1): ?>
                                                    <span class="text-success"><i class="bi bi-check-circle"></i> High Turnover</span>
                                                <?php elseif ($item['turnover_ratio'] > 0.5): ?>
                                                    <span class="text-warning"><i class="bi bi-clock"></i> Medium Turnover</span>
                                                <?php else: ?>
                                                    <span class="text-danger"><i class="bi bi-exclamation-triangle"></i> Low Turnover</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php else: ?>
                            <div class="text-center py-4">
                                <i class="bi bi-graph-up-arrow fs-1 text-muted"></i>
                                <p class="text-muted mt-2">No turnover data available</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script>
        // Search, Filter, Sort, and Export functionality
        function initializeContainerControls(containerId, dataType) {
            const container = document.getElementById(containerId);
            if (!container) {
                console.log(`Container ${containerId} not found`);
                return;
            }
            
            const searchInput = container.querySelector('.search-input');
            const filterSelect = container.querySelector('.filter-select');
            const sortSelect = container.querySelector('.sort-select');
            const exportBtn = container.querySelector('.export-btn');
            const resultsCount = container.querySelector('.results-count');
            const listContainer = container.querySelector('.scrollable-container');
            
            if (!listContainer) {
                console.log(`Scrollable container not found in ${containerId}`);
                return;
            }
            
            let allItems = Array.from(listContainer.querySelectorAll('.inventory-item'));
            let filteredItems = [...allItems];
            
            // Search functionality
            function performSearch() {
                const searchTerm = searchInput.value.toLowerCase();
                filteredItems = allItems.filter(item => {
                    const text = item.textContent.toLowerCase();
                    return text.includes(searchTerm);
                });
                updateDisplay();
            }
            
            // Filter functionality
            function performFilter() {
                const filterValue = filterSelect.value;
                let items = [...allItems];
                
                if (filterValue === 'has-products') {
                    items = items.filter(item => {
                        const productCount = item.querySelector('.text-muted');
                        return productCount && !productCount.textContent.includes('0 products');
                    });
                } else if (filterValue === 'has-value') {
                    items = items.filter(item => {
                        const valueText = item.textContent;
                        return valueText.includes('KES') && !valueText.includes('KES 0.00');
                    });
                } else if (filterValue === 'low-stock') {
                    items = items.filter(item => {
                        const quantityText = item.textContent;
                        return quantityText.includes('Low Stock') || quantityText.includes('Out of Stock');
                    });
                }
                
                filteredItems = items;
                updateDisplay();
            }
            
            // Sort functionality
            function performSort() {
                const sortValue = sortSelect.value;
                
                filteredItems.sort((a, b) => {
                    let aValue, bValue;
                    
                    switch(sortValue) {
                        case 'name-asc':
                            aValue = a.querySelector('h6').textContent.toLowerCase();
                            bValue = b.querySelector('h6').textContent.toLowerCase();
                            return aValue.localeCompare(bValue);
                        case 'name-desc':
                            aValue = a.querySelector('h6').textContent.toLowerCase();
                            bValue = b.querySelector('h6').textContent.toLowerCase();
                            return bValue.localeCompare(aValue);
                        case 'count-asc':
                            aValue = parseInt(a.textContent.match(/\d+/)?.[0] || 0);
                            bValue = parseInt(b.textContent.match(/\d+/)?.[0] || 0);
                            return aValue - bValue;
                        case 'count-desc':
                            aValue = parseInt(a.textContent.match(/\d+/)?.[0] || 0);
                            bValue = parseInt(b.textContent.match(/\d+/)?.[0] || 0);
                            return bValue - aValue;
                        case 'value-asc':
                            aValue = parseFloat(a.textContent.match(/KES ([\d,]+\.?\d*)/)?.[1]?.replace(/,/g, '') || 0);
                            bValue = parseFloat(b.textContent.match(/KES ([\d,]+\.?\d*)/)?.[1]?.replace(/,/g, '') || 0);
                            return aValue - bValue;
                        case 'value-desc':
                            aValue = parseFloat(a.textContent.match(/KES ([\d,]+\.?\d*)/)?.[1]?.replace(/,/g, '') || 0);
                            bValue = parseFloat(b.textContent.match(/KES ([\d,]+\.?\d*)/)?.[1]?.replace(/,/g, '') || 0);
                            return bValue - aValue;
                        default:
                            return 0;
                    }
                });
                
                updateDisplay();
            }
            
            // Update display
            function updateDisplay() {
                // Clear current display
                listContainer.innerHTML = '';
                
                if (filteredItems.length === 0) {
                    listContainer.innerHTML = '<div class="no-results"><i class="bi bi-search fs-1"></i><p>No results found</p></div>';
                } else {
                    // Update ranking numbers
                    filteredItems.forEach((item, index) => {
                        const badge = item.querySelector('.badge');
                        if (badge) {
                            badge.textContent = index + 1;
                        }
                        listContainer.appendChild(item);
                    });
                }
                
                // Update results count
                if (resultsCount) {
                    resultsCount.textContent = `Showing ${filteredItems.length} of ${allItems.length} items`;
                }
            }
            
            // Export functionality
            function exportData() {
                if (filteredItems.length === 0) {
                    alert('No data to export. Please check your filters or search terms.');
                    return;
                }
                
                const data = filteredItems.map((item, index) => {
                    const name = item.querySelector('h6') ? item.querySelector('h6').textContent : 'N/A';
                    const details = Array.from(item.querySelectorAll('.text-muted')).map(el => el.textContent).join(' | ');
                    const values = Array.from(item.querySelectorAll('h6, h5')).map(el => el.textContent).join(' | ');
                    return {
                        'Rank': index + 1,
                        'Name': name,
                        'Details': details,
                        'Values': values
                    };
                });
                
                // Create Excel workbook
                const wb = XLSX.utils.book_new();
                const ws = XLSX.utils.json_to_sheet(data);
                
                // Set column widths
                ws['!cols'] = [
                    { wch: 8 },   // Rank
                    { wch: 25 },  // Name
                    { wch: 40 },  // Details
                    { wch: 30 }   // Values
                ];
                
                // Add worksheet to workbook
                XLSX.utils.book_append_sheet(wb, ws, dataType.charAt(0).toUpperCase() + dataType.slice(1));
                
                // Generate and download Excel file
                const fileName = `${dataType}_export_${new Date().toISOString().split('T')[0]}.xlsx`;
                XLSX.writeFile(wb, fileName);
            }
            
            // Event listeners
            if (searchInput) {
                searchInput.addEventListener('input', performSearch);
            }
            if (filterSelect) {
                filterSelect.addEventListener('change', performFilter);
            }
            if (sortSelect) {
                sortSelect.addEventListener('change', performSort);
            }
            if (exportBtn) {
                exportBtn.addEventListener('click', exportData);
            }
        }
        
        // Special handler for turnover table
        function initializeTurnoverControls() {
            const container = document.getElementById('turnover-container');
            if (!container) {
                console.log('Turnover container not found');
                return;
            }
            
            const searchInput = container.querySelector('.search-input');
            const filterSelect = container.querySelector('.filter-select');
            const sortSelect = container.querySelector('.sort-select');
            const exportBtn = container.querySelector('.export-btn');
            const resultsCount = container.querySelector('.results-count');
            const table = document.getElementById('turnover-table');
            
            if (!table) {
                console.log('Turnover table not found');
                return;
            }
            
            let allRows = Array.from(table.querySelectorAll('tbody tr'));
            let filteredRows = [...allRows];
            
            // Search functionality
            function performSearch() {
                const searchTerm = searchInput.value.toLowerCase();
                filteredRows = allRows.filter(row => {
                    const text = row.textContent.toLowerCase();
                    return text.includes(searchTerm);
                });
                updateTableDisplay();
            }
            
            // Filter functionality
            function performFilter() {
                const filterValue = filterSelect.value;
                let rows = [...allRows];
                
                if (filterValue === 'high-turnover') {
                    rows = rows.filter(row => {
                        const turnoverCell = row.cells[4];
                        const turnoverValue = parseFloat(turnoverCell.textContent);
                        return turnoverValue > 1.0;
                    });
                } else if (filterValue === 'medium-turnover') {
                    rows = rows.filter(row => {
                        const turnoverCell = row.cells[4];
                        const turnoverValue = parseFloat(turnoverCell.textContent);
                        return turnoverValue >= 0.5 && turnoverValue <= 1.0;
                    });
                } else if (filterValue === 'low-turnover') {
                    rows = rows.filter(row => {
                        const turnoverCell = row.cells[4];
                        const turnoverValue = parseFloat(turnoverCell.textContent);
                        return turnoverValue < 0.5;
                    });
                } else if (filterValue === 'has-stock') {
                    rows = rows.filter(row => {
                        const stockCell = row.cells[2];
                        const stockValue = parseInt(stockCell.textContent);
                        return stockValue > 0;
                    });
                }
                
                filteredRows = rows;
                updateTableDisplay();
            }
            
            // Sort functionality
            function performSort() {
                const sortValue = sortSelect.value;
                
                filteredRows.sort((a, b) => {
                    let aValue, bValue;
                    
                    switch(sortValue) {
                        case 'name-asc':
                            aValue = a.cells[0].textContent.toLowerCase();
                            bValue = b.cells[0].textContent.toLowerCase();
                            return aValue.localeCompare(bValue);
                        case 'name-desc':
                            aValue = a.cells[0].textContent.toLowerCase();
                            bValue = b.cells[0].textContent.toLowerCase();
                            return bValue.localeCompare(aValue);
                        case 'turnover-desc':
                            aValue = parseFloat(a.cells[4].textContent);
                            bValue = parseFloat(b.cells[4].textContent);
                            return bValue - aValue;
                        case 'turnover-asc':
                            aValue = parseFloat(a.cells[4].textContent);
                            bValue = parseFloat(b.cells[4].textContent);
                            return aValue - bValue;
                        case 'stock-desc':
                            aValue = parseInt(a.cells[2].textContent);
                            bValue = parseInt(b.cells[2].textContent);
                            return bValue - aValue;
                        case 'stock-asc':
                            aValue = parseInt(a.cells[2].textContent);
                            bValue = parseInt(b.cells[2].textContent);
                            return aValue - bValue;
                        case 'sold-desc':
                            aValue = parseInt(a.cells[3].textContent);
                            bValue = parseInt(b.cells[3].textContent);
                            return bValue - aValue;
                        case 'sold-asc':
                            aValue = parseInt(a.cells[3].textContent);
                            bValue = parseInt(b.cells[3].textContent);
                            return aValue - bValue;
                        default:
                            return 0;
                    }
                });
                
                updateTableDisplay();
            }
            
            // Update table display
            function updateTableDisplay() {
                const tbody = table.querySelector('tbody');
                tbody.innerHTML = '';
                
                if (filteredRows.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="6" class="text-center no-results"><i class="bi bi-search fs-1"></i><p>No results found</p></td></tr>';
                } else {
                    filteredRows.forEach(row => {
                        tbody.appendChild(row);
                    });
                }
                
                // Update results count
                if (resultsCount) {
                    resultsCount.textContent = `Showing ${filteredRows.length} of ${allRows.length} products`;
                }
            }
            
            // Export functionality
            function exportData() {
                if (filteredRows.length === 0) {
                    alert('No data to export. Please check your filters or search terms.');
                    return;
                }
                
                const data = filteredRows.map(row => {
                    return {
                        'Product': row.cells[0] ? row.cells[0].textContent : 'N/A',
                        'SKU': row.cells[1] ? row.cells[1].textContent : 'N/A',
                        'Current Stock': row.cells[2] ? row.cells[2].textContent : 'N/A',
                        'Quantity Sold': row.cells[3] ? row.cells[3].textContent : 'N/A',
                        'Turnover Ratio': row.cells[4] ? row.cells[4].textContent : 'N/A',
                        'Status': row.cells[5] ? row.cells[5].textContent.trim() : 'N/A'
                    };
                });
                
                // Create Excel workbook
                const wb = XLSX.utils.book_new();
                const ws = XLSX.utils.json_to_sheet(data);
                
                // Set column widths
                ws['!cols'] = [
                    { wch: 25 },  // Product
                    { wch: 15 },  // SKU
                    { wch: 12 },  // Current Stock
                    { wch: 15 },  // Quantity Sold
                    { wch: 15 },  // Turnover Ratio
                    { wch: 20 }   // Status
                ];
                
                // Add worksheet to workbook
                XLSX.utils.book_append_sheet(wb, ws, 'Turnover Analysis');
                
                // Generate and download Excel file
                const fileName = `turnover_analysis_export_${new Date().toISOString().split('T')[0]}.xlsx`;
                XLSX.writeFile(wb, fileName);
            }
            
            // Event listeners
            if (searchInput) {
                searchInput.addEventListener('input', performSearch);
            }
            if (filterSelect) {
                filterSelect.addEventListener('change', performFilter);
            }
            if (sortSelect) {
                sortSelect.addEventListener('change', performSort);
            }
            if (exportBtn) {
                exportBtn.addEventListener('click', exportData);
            }
        }
        
        // Initialize all containers when page loads
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Initializing inventory analytics controls...');
            
            // Wait a bit to ensure all elements are rendered
            setTimeout(() => {
                initializeContainerControls('categories-container', 'categories');
                initializeContainerControls('brands-container', 'brands');
                initializeContainerControls('low-stock-container', 'low_stock');
                initializeContainerControls('movements-container', 'movements');
                initializeTurnoverControls();
                
                console.log('All controls initialized');
            }, 100);
        });
    </script>
</body>
</html>
