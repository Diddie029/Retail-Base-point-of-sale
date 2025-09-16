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

// Check if user has permission to view sales or POS management
$hasAccess = false;

// Check if user is admin
if (isAdmin($role_name)) {
    $hasAccess = true;
}

// Check if user has sales permissions
if (!$hasAccess && !empty($permissions)) {
    if (hasPermission('view_sales', $permissions) || hasPermission('manage_sales', $permissions) || hasPermission('view_finance', $permissions)) {
        $hasAccess = true;
    }
}

// Check if user has admin access through permissions
if (!$hasAccess && hasAdminAccess($role_name, $permissions)) {
    $hasAccess = true;
}

if (!$hasAccess) {
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

// Check if user has selected a till for this session
$selected_till = null;
if (isset($_SESSION['selected_till_id'])) {
    $stmt = $conn->prepare("SELECT * FROM register_tills WHERE id = ? AND is_active = 1");
    $stmt->execute([$_SESSION['selected_till_id']]);
    $selected_till = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Handle till selection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'select_till') {
    $till_id = $_POST['till_id'] ?? null;
    if ($till_id) {
        $stmt = $conn->prepare("SELECT * FROM register_tills WHERE id = ? AND is_active = 1");
        $stmt->execute([$till_id]);
        $till = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($till) {
            $_SESSION['selected_till_id'] = $till_id;
            $_SESSION['selected_till_name'] = $till['till_name'];
            $_SESSION['selected_till_code'] = $till['till_code'];
            $selected_till = $till;
        }
    }
}

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

// Get POS Management statistics
try {
    // Till statistics
    $stmt = $conn->query("
        SELECT 
            COUNT(*) as total_tills,
            COUNT(CASE WHEN is_active = 1 THEN 1 END) as active_tills,
            SUM(current_balance) as total_cash_in_tills
        FROM register_tills
    ");
    $till_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Cash drop statistics (today)
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_drops_today,
            SUM(drop_amount) as total_dropped_today,
            COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_drops
        FROM cash_drops 
        WHERE DATE(drop_date) = ?
    ");
    $stmt->execute([$today]);
    $cash_drop_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Payment method statistics
    $stmt = $conn->query("
        SELECT 
            COUNT(*) as total_payment_types,
            COUNT(CASE WHEN is_active = 1 THEN 1 END) as active_payment_types
        FROM payment_types
    ");
    $payment_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $till_stats = ['total_tills' => 0, 'active_tills' => 0, 'total_cash_in_tills' => 0];
    $cash_drop_stats = ['total_drops_today' => 0, 'total_dropped_today' => 0, 'pending_drops' => 0];
    $payment_stats = ['total_payment_types' => 0, 'active_payment_types' => 0];
}

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

// Get recent customers (exclude walk-in/default/internal customers)
$recentCustomersSql = "SELECT c.*, COUNT(s.id) as total_purchases, SUM(s.final_amount) as total_spent,"
    . " CONCAT(c.first_name, ' ', c.last_name) as full_name"
    . " FROM customers c"
    . " LEFT JOIN sales s ON c.id = s.customer_id"
    . " WHERE c.membership_status = 'active'"
    . " AND (COALESCE(c.customer_type, '') != 'walk_in')"
    . " AND (COALESCE(c.customer_number, '') NOT LIKE 'WALK-IN%')"
    . " AND (CONCAT(c.first_name, ' ', c.last_name) NOT LIKE '%Walk-in%')"
    . " GROUP BY c.id"
    . " ORDER BY total_spent DESC"
    . " LIMIT 10";

$stmt = $conn->query($recentCustomersSql);
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
                    <h2><i class="bi bi-cash-register"></i> POS Management Dashboard</h2>
                    <p class="text-muted">Comprehensive point of sale management including sales, tills, cash drops, and payment methods</p>
                </div>
                <div class="d-flex align-items-center gap-3">
                    <span class="badge bg-primary fs-6"><?php echo date('M d, Y'); ?></span>
                    <a href="../auth/logout.php" class="btn btn-outline-danger" title="Logout">
                        <i class="bi bi-box-arrow-right"></i> Logout
                    </a>
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

            <!-- POS Management Statistics -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #17a2b8 0%, #20c997 100%);">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-1">Total Tills</h6>
                                <h3 class="mb-0"><?php echo $till_stats['total_tills'] ?? 0; ?></h3>
                                <small class="text-white-50"><?php echo $till_stats['active_tills'] ?? 0; ?> Active</small>
                            </div>
                            <i class="bi bi-cash-register fs-1 opacity-75"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #28a745 0%, #20c997 100%);">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-1">Cash in Tills</h6>
                                <h3 class="mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($till_stats['total_cash_in_tills'] ?? 0, 2); ?></h3>
                            </div>
                            <i class="bi bi-cash-coin fs-1 opacity-75"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #ffc107 0%, #fd7e14 100%);">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-1">Cash Drops Today</h6>
                                <h3 class="mb-0"><?php echo $cash_drop_stats['total_drops_today'] ?? 0; ?></h3>
                                <small class="text-white-50"><?php echo $cash_drop_stats['pending_drops'] ?? 0; ?> Pending</small>
                            </div>
                            <i class="bi bi-cash-stack fs-1 opacity-75"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #6f42c1 0%, #e83e8c 100%);">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-1">Payment Types</h6>
                                <h3 class="mb-0"><?php echo $payment_stats['active_payment_types'] ?? 0; ?></h3>
                                <small class="text-white-50"><?php echo $payment_stats['total_payment_types'] ?? 0; ?> Total</small>
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

            <!-- Analytics Section -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-graph-up"></i> Analytics & Insights</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <!-- Sales Analytics -->
                                <div class="col-md-6">
                                    <div class="feature-card" onclick="window.location.href='../analytics/index.php'">
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

                                <!-- Inventory Analytics -->
                                <div class="col-md-6">
                                    <div class="feature-card" onclick="window.location.href='../analytics/inventory.php'">
                                        <div class="d-flex align-items-center">
                                            <div class="feature-icon" style="background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); color: white;">
                                                <i class="bi bi-boxes"></i>
                                            </div>
                                            <div class="ms-3">
                                                <h6 class="mb-1">Inventory Analytics</h6>
                                                <p class="text-muted mb-0">Stock levels, turnover rates, and inventory optimization</p>
                                                <small class="text-danger"><?php echo count($low_stock_alerts); ?> low stock alerts</small>
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

    <!-- Quick Add Points Modal -->
    <div class="modal fade" id="quickAddPointsModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="loyalty-points.php">
                    <input type="hidden" name="action" value="add_points">
                    <input type="hidden" name="source" value="manual">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-plus-circle"></i> Quick Add Points</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            <strong>Note:</strong> Points added from here will require approval before being applied to the customer's account.
                        </div>
                        <div class="mb-3">
                            <label for="customer_id" class="form-label">Customer</label>
                            <select class="form-select" name="customer_id" required>
                                <option value="">Select Customer</option>
                                <?php foreach ($recent_customers as $customer): ?>
                                <option value="<?php echo $customer['id']; ?>">
                                    <?php echo htmlspecialchars($customer['full_name']); ?> - <?php echo htmlspecialchars($customer['phone']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="points" class="form-label">Points to Add</label>
                            <input type="number" class="form-control" name="points" min="1" required>
                        </div>
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <input type="text" class="form-control" name="description" placeholder="e.g., Manual adjustment, Special reward" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Points (Pending Approval)</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Till Selection Modal -->
    <div class="modal fade" id="tillSelectionModal" tabindex="-1" aria-labelledby="tillSelectionModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="tillSelectionModalLabel">
                        <i class="bi bi-cash-register"></i> Select Till
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="select_till">
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i>
                            Please select a till to continue with sales operations. You can change this selection at any time.
                        </div>
                        
                        <div class="row">
                            <?php foreach ($register_tills as $till): ?>
                            <div class="col-md-6 mb-3">
                                <div class="card h-100 till-card" onclick="selectTill(<?php echo $till['id']; ?>)">
                                    <div class="card-body text-center">
                                        <div class="till-icon mb-3">
                                            <i class="bi bi-cash-register" style="font-size: 2rem; color: #667eea;"></i>
                                        </div>
                                        <h5 class="card-title"><?php echo htmlspecialchars($till['till_name']); ?></h5>
                                        <p class="card-text">
                                            <strong>Code:</strong> <?php echo htmlspecialchars($till['till_code']); ?><br>
                                            <strong>Location:</strong> <?php echo htmlspecialchars($till['location'] ?? 'N/A'); ?><br>
                                            <strong>Current Balance:</strong> 
                                            <span class="text-success fw-bold">
                                                <?php echo formatCurrency($till['current_balance'], $settings); ?>
                                            </span>
                                        </p>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="till_id" value="<?php echo $till['id']; ?>" id="till_<?php echo $till['id']; ?>">
                                            <label class="form-check-label" for="till_<?php echo $till['id']; ?>">
                                                Select This Till
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <?php if (empty($register_tills)): ?>
                        <div class="text-center py-4">
                            <i class="bi bi-exclamation-triangle text-warning" style="font-size: 3rem;"></i>
                            <h5 class="mt-3">No Active Tills Available</h5>
                            <p class="text-muted">Please contact your administrator to set up active tills.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="confirmTillSelection" disabled>
                            <i class="bi bi-check-circle"></i> Confirm Selection
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Show till selection modal
        function showTillSelection() {
            const modal = new bootstrap.Modal(document.getElementById('tillSelectionModal'));
            modal.show();
        }
        
        // Select till when card is clicked
        function selectTill(tillId) {
            // Uncheck all radio buttons
            document.querySelectorAll('input[name="till_id"]').forEach(radio => {
                radio.checked = false;
            });
            
            // Check the selected till
            document.getElementById('till_' + tillId).checked = true;
            
            // Enable confirm button
            document.getElementById('confirmTillSelection').disabled = false;
            
            // Add visual feedback
            document.querySelectorAll('.till-card').forEach(card => {
                card.classList.remove('border-primary', 'bg-light');
            });
            event.currentTarget.classList.add('border-primary', 'bg-light');
        }
        
        // Enable confirm button when radio is selected
        document.querySelectorAll('input[name="till_id"]').forEach(radio => {
            radio.addEventListener('change', function() {
                document.getElementById('confirmTillSelection').disabled = !this.checked;
            });
        });
        
        // Show modal on page load if no till is selected
        <?php if (!$selected_till && !empty($register_tills)): ?>
        document.addEventListener('DOMContentLoaded', function() {
            showTillSelection();
        });
        <?php endif; ?>
    </script>
    
    <style>
        .till-card {
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }
        
        .till-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            border-color: #667eea;
        }
        
        .till-card.border-primary {
            border-color: #667eea !important;
            background-color: #f8f9ff !important;
        }
        
        .till-icon {
            width: 60px;
            height: 60px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            color: white;
        }
        /* Extra space at page bottom to prevent content/footer overlap */
        .page-bottom-space {
            height: 80px;
            width: 100%;
            display: block;
        }
    </style>
    <!-- bottom spacing so content doesn't sit flush to the viewport bottom -->
    <div class="page-bottom-space" aria-hidden="true"></div>
</body>
</html>
