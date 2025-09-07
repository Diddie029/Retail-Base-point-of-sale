<?php
session_start();
require_once '../include/db.php';
require_once '../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

// Get user info
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

// Check if user has permission to view sales
if (!hasPermission('view_sales', $permissions) && !hasPermission('view_finance', $permissions)) {
    header('Location: ../dashboard/dashboard.php?error=access_denied');
    exit();
}

// Get system settings
$stmt = $conn->query("SELECT * FROM settings");
$settings = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get today's sales summary
$today = date('Y-m-d');
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_sales,
        SUM(final_amount) as total_amount,
        AVG(final_amount) as average_sale,
        COUNT(DISTINCT payment_method) as payment_methods_used
    FROM sales 
    WHERE DATE(sale_date) = ?
");
$stmt->execute([$today]);
$today_stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Get register tills
$stmt = $conn->query("
    SELECT * FROM register_tills 
    WHERE is_active = 1 
    ORDER BY till_name
");
$register_tills = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent cash drops
$stmt = $conn->query("
    SELECT cd.*, rt.till_name, u.username as dropped_by
    FROM cash_drops cd
    LEFT JOIN register_tills rt ON cd.till_id = rt.id
    LEFT JOIN users u ON cd.user_id = u.id
    ORDER BY cd.drop_date DESC
    LIMIT 10
");
$recent_cash_drops = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get payment methods
$stmt = $conn->query("
    SELECT * FROM payment_types 
    WHERE is_active = 1 
    ORDER BY sort_order, display_name
");
$payment_methods = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get low stock alerts
$stmt = $conn->query("
    SELECT p.*, c.name as category_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.quantity <= p.reorder_point AND p.status = 'active'
    ORDER BY p.quantity ASC
    LIMIT 10
");
$low_stock_alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get top selling products today
$stmt = $conn->prepare("
    SELECT p.name, p.sku, SUM(si.quantity) as total_sold, SUM(si.total_price) as total_revenue
    FROM sales s
    JOIN sale_items si ON s.id = si.sale_id
    JOIN products p ON si.product_id = p.id
    WHERE DATE(s.sale_date) = ?
    GROUP BY p.id, p.name, p.sku
    ORDER BY total_sold DESC
    LIMIT 5
");
$stmt->execute([$today]);
$top_products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent customers
$stmt = $conn->query("
    SELECT c.*, COUNT(s.id) as total_purchases, SUM(s.final_amount) as total_spent,
           CONCAT(c.first_name, ' ', c.last_name) as full_name
    FROM customers c
    LEFT JOIN sales s ON c.id = s.customer_id
    WHERE c.membership_status = 'active'
    GROUP BY c.id
    ORDER BY total_spent DESC
    LIMIT 10
");
$recent_customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Dashboard - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/dashboard.css" rel="stylesheet">
    <style>
        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .feature-card {
            border: 1px solid #e9ecef;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .feature-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            border-color: #667eea;
        }
        .feature-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 1rem;
        }
        .alert-item {
            border-left: 4px solid #dc3545;
            background: #f8d7da;
            padding: 0.75rem;
            margin-bottom: 0.5rem;
            border-radius: 0 5px 5px 0;
        }
        .till-card {
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            background: #f8f9fa;
        }
        .till-status {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 0.5rem;
        }
        .till-active { background-color: #28a745; }
        .till-inactive { background-color: #dc3545; }
    </style>
</head>
<body>
    <?php include '../include/navmenu.php'; ?>

    <div class="main-content">
        <div class="container-fluid">
            <!-- Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2><i class="bi bi-graph-up"></i> Sales Dashboard</h2>
                    <p class="text-muted">Manage your point of sale operations and settings</p>
                </div>
                <div>
                    <span class="badge bg-primary fs-6"><?php echo date('M d, Y'); ?></span>
                </div>
            </div>

            <!-- Today's Stats -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="stats-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-1">Today's Sales</h6>
                                <h3 class="mb-0"><?php echo $today_stats['total_sales'] ?? 0; ?></h3>
                            </div>
                            <i class="bi bi-cart-check fs-1 opacity-75"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #28a745 0%, #20c997 100%);">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-1">Today's Revenue</h6>
                                <h3 class="mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($today_stats['total_amount'] ?? 0, 2); ?></h3>
                            </div>
                            <i class="bi bi-currency-dollar fs-1 opacity-75"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #ffc107 0%, #fd7e14 100%);">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-1">Average Sale</h6>
                                <h3 class="mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($today_stats['average_sale'] ?? 0, 2); ?></h3>
                            </div>
                            <i class="bi bi-graph-up fs-1 opacity-75"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #6f42c1 0%, #e83e8c 100%);">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-1">Payment Methods</h6>
                                <h3 class="mb-0"><?php echo $today_stats['payment_methods_used'] ?? 0; ?></h3>
                            </div>
                            <i class="bi bi-credit-card fs-1 opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- POS Settings Section -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-gear"></i> Point of Sale Settings</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <!-- Register Tills -->
                                <div class="col-md-6">
                                    <div class="feature-card" onclick="window.location.href='tills.php'">
                                        <div class="d-flex align-items-center">
                                            <div class="feature-icon" style="background: linear-gradient(135deg, #007bff 0%, #0056b3 100%); color: white;">
                                                <i class="bi bi-cash-register"></i>
                                            </div>
                                            <div class="ms-3">
                                                <h6 class="mb-1">Register Tills</h6>
                                                <p class="text-muted mb-0">Manage cash registers and till assignments</p>
                                                <small class="text-primary"><?php echo count($register_tills); ?> active tills</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Cash Drop -->
                                <div class="col-md-6">
                                    <div class="feature-card" onclick="window.location.href='cash-drop.php'">
                                        <div class="d-flex align-items-center">
                                            <div class="feature-icon" style="background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white;">
                                                <i class="bi bi-cash-coin"></i>
                                            </div>
                                            <div class="ms-3">
                                                <h6 class="mb-1">Cash Drop</h6>
                                                <p class="text-muted mb-0">Record cash drops and till management</p>
                                                <small class="text-success"><?php echo count($recent_cash_drops); ?> recent drops</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Payment Methods -->
                                <div class="col-md-6">
                                    <div class="feature-card" onclick="window.location.href='payment-methods.php'">
                                        <div class="d-flex align-items-center">
                                            <div class="feature-icon" style="background: linear-gradient(135deg, #6f42c1 0%, #e83e8c 100%); color: white;">
                                                <i class="bi bi-credit-card"></i>
                                            </div>
                                            <div class="ms-3">
                                                <h6 class="mb-1">Payment Methods</h6>
                                                <p class="text-muted mb-0">Configure payment types and settings</p>
                                                <small class="text-info"><?php echo count($payment_methods); ?> methods configured</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- POS Configuration -->
                                <div class="col-md-6">
                                    <div class="feature-card" onclick="window.location.href='pos-config.php'">
                                        <div class="d-flex align-items-center">
                                            <div class="feature-icon" style="background: linear-gradient(135deg, #fd7e14 0%, #dc3545 100%); color: white;">
                                                <i class="bi bi-sliders"></i>
                                            </div>
                                            <div class="ms-3">
                                                <h6 class="mb-1">POS Configuration</h6>
                                                <p class="text-muted mb-0">System settings and preferences</p>
                                                <small class="text-warning">Configure settings</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Additional Features Section -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-plus-circle"></i> Additional Features</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <!-- Sales Analytics -->
                                <div class="col-md-4">
                                    <div class="feature-card" onclick="window.location.href='analytics.php'">
                                        <div class="d-flex align-items-center">
                                            <div class="feature-icon" style="background: linear-gradient(135deg, #17a2b8 0%, #138496 100%); color: white;">
                                                <i class="bi bi-graph-up-arrow"></i>
                                            </div>
                                            <div class="ms-3">
                                                <h6 class="mb-1">Sales Analytics</h6>
                                                <p class="text-muted mb-0">Detailed sales reports and insights</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Inventory Alerts -->
                                <div class="col-md-4">
                                    <div class="feature-card" onclick="window.location.href='inventory-alerts.php'">
                                        <div class="d-flex align-items-center">
                                            <div class="feature-icon" style="background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); color: white;">
                                                <i class="bi bi-exclamation-triangle"></i>
                                            </div>
                                            <div class="ms-3">
                                                <h6 class="mb-1">Inventory Alerts</h6>
                                                <p class="text-muted mb-0">Low stock notifications and management</p>
                                                <small class="text-danger"><?php echo count($low_stock_alerts); ?> alerts</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Loyalty Points System -->
                                <div class="col-md-4">
                                    <div class="feature-card" onclick="window.location.href='loyalty-points.php'">
                                        <div class="d-flex align-items-center">
                                            <div class="feature-icon" style="background: linear-gradient(135deg, #ffc107 0%, #fd7e14 100%); color: white;">
                                                <i class="bi bi-star-fill"></i>
                                            </div>
                                            <div class="ms-3">
                                                <h6 class="mb-1">Loyalty Points</h6>
                                                <p class="text-muted mb-0">Customer loyalty program and rewards</p>
                                                <small class="text-warning">Points system</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Overview -->
            <div class="row">
                <!-- Low Stock Alerts -->
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0"><i class="bi bi-exclamation-triangle text-danger"></i> Low Stock Alerts</h6>
                        </div>
                        <div class="card-body">
                            <?php if (empty($low_stock_alerts)): ?>
                            <div class="text-center py-3">
                                <i class="bi bi-check-circle text-success fs-1 mb-3"></i>
                                <p class="text-muted">All products are well stocked!</p>
                            </div>
                            <?php else: ?>
                            <?php foreach ($low_stock_alerts as $alert): ?>
                            <div class="alert-item">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong><?php echo htmlspecialchars($alert['name']); ?></strong>
                                        <br>
                                        <small class="text-muted">SKU: <?php echo htmlspecialchars($alert['sku']); ?></small>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge bg-danger"><?php echo $alert['quantity']; ?> left</span>
                                        <br>
                                        <small class="text-muted">Reorder: <?php echo $alert['reorder_level']; ?></small>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Top Products Today -->
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0"><i class="bi bi-trophy text-warning"></i> Top Products Today</h6>
                        </div>
                        <div class="card-body">
                            <?php if (empty($top_products)): ?>
                            <div class="text-center py-3">
                                <i class="bi bi-cart-x text-muted fs-1 mb-3"></i>
                                <p class="text-muted">No sales today</p>
                            </div>
                            <?php else: ?>
                            <?php foreach ($top_products as $product): ?>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div>
                                    <strong><?php echo htmlspecialchars($product['name']); ?></strong>
                                    <br>
                                    <small class="text-muted">SKU: <?php echo htmlspecialchars($product['sku']); ?></small>
                                </div>
                                <div class="text-end">
                                    <span class="badge bg-primary"><?php echo $product['total_sold']; ?> sold</span>
                                    <br>
                                    <small class="text-success"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($product['total_revenue'], 2); ?></small>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
