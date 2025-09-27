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

// Get date range parameters
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$report_type = $_GET['report_type'] ?? 'summary';
$category_id = $_GET['category_id'] ?? '';
$supplier_id = $_GET['supplier_id'] ?? '';

// Get inventory statistics
// Get inventory statistics using function from db.php
$stats = getInventoryStatistics($conn);


// Get product categories for filter
$stmt = $conn->query("SELECT id, name FROM categories ORDER BY name");
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get suppliers for filter
$stmt = $conn->query("SELECT id, name FROM suppliers ORDER BY name");
$suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Stock level data based on report type
$stock_data = [];
$low_stock_alerts = [];
$inventory_turnover = [];
$valuation_data = [];
$supplier_performance = [];

if ($report_type === 'stock_levels') {
    // Get all products with stock information
    $where_conditions = [];
    $params = [];

    if (!empty($category_id)) {
        $where_conditions[] = "p.category_id = ?";
        $params[] = $category_id;
    }

    if (!empty($supplier_id)) {
        $where_conditions[] = "p.supplier_id = ?";
        $params[] = $supplier_id;
    }

    $where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

    $query = "
        SELECT
            p.id, p.name, p.sku, p.quantity, p.minimum_stock, p.maximum_stock,
            p.cost_price, p.price, p.status,
            c.name as category_name,
            s.name as supplier_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN suppliers s ON p.supplier_id = s.id
        $where_clause
        ORDER BY p.name
    ";

    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $stock_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

} elseif ($report_type === 'low_stock_alerts') {
    // Get low stock alerts
    $query = "
        SELECT
            p.id, p.name, p.sku, p.quantity, p.minimum_stock, p.maximum_stock,
            p.cost_price, p.price, p.status,
            c.name as category_name,
            s.name as supplier_name,
            (p.minimum_stock - p.quantity) as reorder_quantity
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN suppliers s ON p.supplier_id = s.id
        WHERE p.quantity <= p.minimum_stock AND p.quantity > 0 AND p.status = 'active'
        ORDER BY p.quantity ASC
    ";

    $stmt = $conn->prepare($query);
    $stmt->execute();
    $low_stock_alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

} elseif ($report_type === 'inventory_turnover') {
    // Get inventory turnover data
    $query = "
        SELECT
            p.id, p.name, p.sku, p.quantity, p.cost_price, p.price,
            COALESCE(SUM(si.quantity), 0) as total_sold,
            COALESCE(AVG(si.quantity), 0) as avg_monthly_sales,
            c.name as category_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN sale_items si ON p.id = si.product_id
        LEFT JOIN sales s ON si.sale_id = s.id AND s.sale_date BETWEEN ? AND ?
        WHERE p.quantity > 0
        GROUP BY p.id, p.name, p.sku, p.quantity, p.cost_price, p.price, c.name
        ORDER BY total_sold DESC
    ";

    $stmt = $conn->prepare($query);
    $stmt->execute([$date_from, $date_to]);
    $inventory_turnover = $stmt->fetchAll(PDO::FETCH_ASSOC);

} elseif ($report_type === 'valuation') {
    // Get inventory valuation by category
    $query = "
        SELECT
            c.name as category_name,
            COUNT(p.id) as product_count,
            SUM(p.quantity) as total_quantity,
            SUM(p.quantity * COALESCE(p.cost_price, 0)) as total_cost_value,
            SUM(p.quantity * COALESCE(p.price, 0)) as total_retail_value,
            AVG(p.quantity * COALESCE(p.cost_price, 0)) as avg_cost_value
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.quantity > 0
        GROUP BY c.id, c.name
        ORDER BY total_cost_value DESC
    ";

    $stmt = $conn->prepare($query);
    $stmt->execute();
    $valuation_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

} elseif ($report_type === 'supplier_performance') {
    // Get supplier performance data
    $query = "
        SELECT
            s.id, s.name as supplier_name,
            COUNT(DISTINCT io.id) as total_orders,
            COUNT(DISTINCT CASE WHEN io.status = 'received' THEN io.id END) as completed_orders,
            AVG(CASE WHEN io.status = 'received' THEN DATEDIFF(io.updated_at, io.created_at) END) as avg_delivery_days,
            SUM(CASE WHEN io.status = 'received' THEN ioi.received_quantity * COALESCE(ioi.cost_price, 0) END) as total_order_value
        FROM suppliers s
        LEFT JOIN inventory_orders io ON s.id = io.supplier_id
        LEFT JOIN inventory_order_items ioi ON io.id = ioi.order_id
        GROUP BY s.id, s.name
        HAVING total_orders > 0
        ORDER BY total_order_value DESC
    ";

    $stmt = $conn->prepare($query);
    $stmt->execute();
    $supplier_performance = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Reports - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
        }
        
        .inventory-card {
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            color: white;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            transition: transform 0.3s ease;
        }

        .inventory-card:hover {
            transform: translateY(-5px);
        }

        .inventory-card.low-stock {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        }

        .inventory-card.out-of-stock {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
        }

        .inventory-card.value {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }

        .report-card {
            border: none;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            border-radius: 12px;
        }

        .report-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .report-header {
            border-bottom: 1px solid #e9ecef;
            padding-bottom: 1rem;
            margin-bottom: 1rem;
        }

        .chart-container {
            position: relative;
            height: 300px;
        }

        .filter-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .btn-export {
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            border: none;
            color: white;
        }

        .btn-export:hover {
            background: linear-gradient(135deg, #4338ca 0%, #6d28d9 100%);
            color: white;
        }

        .table-responsive {
            border-radius: 8px;
            overflow: hidden;
        }

        .table thead th {
            background: var(--primary-color);
            color: white;
            border: none;
        }

        .metric-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
        }

        .metric-card:hover {
            transform: translateY(-2px);
        }

        .stock-status {
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .stock-status.in-stock {
            background-color: #d1fae5;
            color: #065f46;
        }

        .stock-status.low-stock {
            background-color: #fef3c7;
            color: #92400e;
        }

        .stock-status.out-of-stock {
            background-color: #fee2e2;
            color: #991b1b;
        }

        .stock-status.over-stock {
            background-color: #dbeafe;
            color: #1e40af;
        }
    </style>
</head>
<body>
    <?php include '../include/navmenu.php'; ?>

    <div class="main-content">
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1><i class="bi bi-boxes"></i> Inventory Reports</h1>
                    <p class="header-subtitle">Stock levels, turnover rates, and inventory optimization reports</p>
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
                            <h2><i class="bi bi-boxes"></i> Inventory Reports</h2>
                            <p class="mb-0">Comprehensive inventory analysis and optimization tools</p>
                        </div>
                    </div>
                </div>

                <!-- Inventory Filter Section -->
                <div class="filter-section">
                    <div class="row align-items-end">
                        <div class="col-md-2">
                            <label for="date_from" class="form-label fw-semibold">From Date</label>
                            <input type="date" class="form-control" id="date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                        </div>
                        <div class="col-md-2">
                            <label for="date_to" class="form-label fw-semibold">To Date</label>
                            <input type="date" class="form-control" id="date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                        </div>
                        <div class="col-md-2">
                            <label for="report_type" class="form-label fw-semibold">Report Type</label>
                            <select class="form-select" id="report_type" name="report_type">
                                <option value="summary" <?php echo $report_type === 'summary' ? 'selected' : ''; ?>>Inventory Summary</option>
                                <option value="stock_levels" <?php echo $report_type === 'stock_levels' ? 'selected' : ''; ?>>Stock Levels</option>
                                <option value="low_stock_alerts" <?php echo $report_type === 'low_stock_alerts' ? 'selected' : ''; ?>>Low Stock Alerts</option>
                                <option value="inventory_turnover" <?php echo $report_type === 'inventory_turnover' ? 'selected' : ''; ?>>Inventory Turnover</option>
                                <option value="valuation" <?php echo $report_type === 'valuation' ? 'selected' : ''; ?>>Inventory Valuation</option>
                                <option value="supplier_performance" <?php echo $report_type === 'supplier_performance' ? 'selected' : ''; ?>>Supplier Performance</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="category_id" class="form-label fw-semibold">Category</label>
                            <select class="form-select" id="category_id" name="category_id">
                                <option value="">All Categories</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo $category['id']; ?>" <?php echo $category_id == $category['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($category['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="supplier_id" class="form-label fw-semibold">Supplier</label>
                            <select class="form-select" id="supplier_id" name="supplier_id">
                                <option value="">All Suppliers</option>
                                <?php foreach ($suppliers as $supplier): ?>
                                    <option value="<?php echo $supplier['id']; ?>" <?php echo $supplier_id == $supplier['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($supplier['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="button" class="btn btn-primary me-2" onclick="applyFilters()">
                                <i class="bi bi-search"></i> Apply Filters
                            </button>
                            <button type="button" class="btn btn-export" onclick="exportReport()">
                                <i class="bi bi-download"></i> Export
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Inventory Summary Cards -->
                <div class="row mb-4" id="inventory-summary-cards">
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="inventory-card">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Total Products</h6>
                                <i class="bi bi-boxes fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo number_format($stats['total_products']); ?></h3>
                            <small class="opacity-75">In stock items</small>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="inventory-card low-stock">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Low Stock Items</h6>
                                <i class="bi bi-exclamation-triangle fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo number_format($stats['low_stock']); ?></h3>
                            <small class="opacity-75">Need reordering</small>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="inventory-card out-of-stock">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Out of Stock</h6>
                                <i class="bi bi-dash-circle fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo number_format($stats['out_of_stock']); ?></h3>
                            <small class="opacity-75">Unavailable items</small>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="inventory-card value">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Inventory Value</h6>
                                <i class="bi bi-cash-coin fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($stats['total_inventory_value'], 2); ?></h3>
            <small class="opacity-75">Cost value</small>
                        </div>
                    </div>
                </div>

                <!-- Report Content -->
                <div id="report-content">
                    <div class="report-card">
                        <div class="card-body">
                            <div class="report-header">
                                <h4><i class="bi bi-bar-chart"></i> Inventory Overview</h4>
                                <p class="text-muted mb-0">Summary of current inventory status and key metrics</p>
                            </div>

                            <div class="row">
                                <div class="col-lg-6 mb-4">
                                    <div class="chart-container">
                                        <canvas id="stockChart"></canvas>
                                    </div>
                                </div>
                                <div class="col-lg-6 mb-4">
                                    <div class="chart-container">
                                        <canvas id="valueChart"></canvas>
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
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Stock Distribution Chart
        const stockCtx = document.getElementById('stockChart').getContext('2d');
        new Chart(stockCtx, {
            type: 'doughnut',
            data: {
                labels: ['In Stock', 'Low Stock', 'Out of Stock'],
                datasets: [{
                    data: [<?php echo $stats['total_products'] - $stats['low_stock'] - $stats['out_of_stock']; ?>, <?php echo $stats['low_stock']; ?>, <?php echo $stats['out_of_stock']; ?>],
                    backgroundColor: [
                        'rgba(34, 197, 94, 0.8)',
                        'rgba(251, 191, 36, 0.8)',
                        'rgba(239, 68, 68, 0.8)'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });

        // Inventory Value Chart
        const valueCtx = document.getElementById('valueChart').getContext('2d');
        new Chart(valueCtx, {
            type: 'bar',
            data: {
                labels: ['Cost Value', 'Retail Value'],
                datasets: [{
                    label: 'Inventory Value',
                    data: [<?php echo $stats['total_inventory_value']; ?>, <?php echo $stats['total_retail_value']; ?>],
                    backgroundColor: [
                        'rgba(99, 102, 241, 0.8)',
                        'rgba(34, 197, 94, 0.8)'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '<?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> ' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });

        // Apply Filters Function
        function applyFilters() {
            const dateFrom = document.getElementById('date_from').value;
            const dateTo = document.getElementById('date_to').value;
            const reportType = document.getElementById('report_type').value;
            const categoryId = document.getElementById('category_id').value;
            const supplierId = document.getElementById('supplier_id').value;

            const params = new URLSearchParams(window.location.search);
            params.set('date_from', dateFrom);
            params.set('date_to', dateTo);
            params.set('report_type', reportType);
            params.set('category_id', categoryId);
            params.set('supplier_id', supplierId);

            window.location.href = window.location.pathname + '?' + params.toString();
        }

        // Export Report Function
        function exportReport() {
            const dateFrom = document.getElementById('date_from').value;
            const dateTo = document.getElementById('date_to').value;
            const reportType = document.getElementById('report_type').value;

            // Create a simple CSV export
            let csvContent = "data:text/csv;charset=utf-8,";

            // Add header
            csvContent += "Inventory Report\n";
            csvContent += `Period: ${dateFrom} to ${dateTo}\n`;
            csvContent += `Report Type: ${reportType}\n\n`;

            // Add summary data
            csvContent += "Inventory Summary\n";
            csvContent += `Total Products,<?php echo $stats['total_products']; ?>\n`;
            csvContent += `Low Stock Items,<?php echo $stats['low_stock']; ?>\n`;
            csvContent += `Out of Stock Items,<?php echo $stats['out_of_stock']; ?>\n`;
            csvContent += `Total Inventory Value,<?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($stats['total_inventory_value'], 2); ?>\n\n`;

            // Add product data based on report type
            if (reportType === 'stock_levels') {
                csvContent += "Product Stock Levels\n";
                csvContent += "Product Name,SKU,Category,Supplier,Current Stock,Min Stock,Max Stock,Cost Price,Selling Price,Status\n";
                <?php foreach ($stock_data as $product): ?>
                    csvContent += "<?php echo addslashes($product['name']); ?>,<?php echo addslashes($product['sku'] ?? 'N/A'); ?>,<?php echo addslashes($product['category_name'] ?? 'N/A'); ?>,<?php echo addslashes($product['supplier_name'] ?? 'N/A'); ?>,<?php echo $product['quantity']; ?>,<?php echo $product['minimum_stock']; ?>,<?php echo $product['maximum_stock'] ?? 0; ?>,<?php echo $product['cost_price']; ?>,<?php echo $product['price']; ?>,<?php echo $product['status']; ?>\n";
                <?php endforeach; ?>
            } else if (reportType === 'low_stock_alerts') {
                csvContent += "Low Stock Alerts\n";
                csvContent += "Product Name,SKU,Current Stock,Min Stock,Reorder Quantity,Cost Price,Reorder Value\n";
                <?php foreach ($low_stock_alerts as $product): ?>
                    csvContent += "<?php echo addslashes($product['name']); ?>,<?php echo addslashes($product['sku'] ?? 'N/A'); ?>,<?php echo $product['quantity']; ?>,<?php echo $product['minimum_stock']; ?>,<?php echo $product['reorder_quantity']; ?>,<?php echo $product['cost_price']; ?>,<?php echo $product['reorder_quantity'] * ($product['cost_price'] ?? 0); ?>\n";
                <?php endforeach; ?>
            }

            const encodedUri = encodeURI(csvContent);
            const link = document.createElement("a");
            link.setAttribute("href", encodedUri);
            link.setAttribute("download", `inventory_report_${reportType}_${dateFrom}_${dateTo}.csv`);
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        // Auto-submit form when report type changes
        document.getElementById('report_type').addEventListener('change', function() {
            applyFilters();
        });
    </script>
</body>
</html>
