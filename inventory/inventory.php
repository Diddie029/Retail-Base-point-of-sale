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

// Get inventory statistics
$stats = [];

// Total Products in Inventory
$stmt = $conn->query("SELECT COUNT(*) as count FROM products WHERE quantity > 0");
$stats['total_products'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Low Stock Products (quantity < 10)
$stmt = $conn->query("SELECT COUNT(*) as count FROM products WHERE quantity < 10 AND quantity > 0");
$stats['low_stock'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Out of Stock Products
$stmt = $conn->query("SELECT COUNT(*) as count FROM products WHERE quantity = 0");
$stats['out_of_stock'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Total Inventory Value
$stmt = $conn->query("SELECT COALESCE(SUM(quantity * cost_price), 0) as total FROM products");
$stats['total_inventory_value'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Pending Orders (we'll need to create orders table later)
$stats['pending_orders'] = 0; // Placeholder

// Recent Inventory Activities (placeholder for now)
$recent_activities = [];

// Low Stock Alert Products
$low_stock_products = [];
if (hasPermission('manage_products', $permissions)) {
    $stmt = $conn->prepare("
        SELECT id, name, quantity, minimum_stock, cost_price
        FROM products
        WHERE quantity <= minimum_stock AND quantity > 0
        ORDER BY quantity ASC
        LIMIT 5
    ");
    $stmt->execute();
    $low_stock_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Top Suppliers section removed as requested
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Dashboard - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/inventory.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <?php
    $current_page = 'inventory';
    include __DIR__ . '/../include/navmenu.php';
    ?>

    <div class="main-content">
        <!-- Header -->
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1>Inventory Management</h1>
                    <p class="header-subtitle">Monitor and manage your inventory efficiently</p>
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

        <!-- Content -->
        <main class="content">
            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-primary">
                            <i class="bi bi-box"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['total_products']); ?></div>
                    <div class="stat-label">Total Products</div>
                    <div class="stat-change positive">
                        <i class="bi bi-check-circle"></i> In stock
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-warning">
                            <i class="bi bi-exclamation-triangle"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['low_stock']); ?></div>
                    <div class="stat-label">Low Stock Items</div>
                    <?php if ($stats['low_stock'] > 0): ?>
                    <div class="stat-change negative">
                        <i class="bi bi-arrow-down"></i> Requires attention
                    </div>
                    <?php else: ?>
                    <div class="stat-change positive">
                        <i class="bi bi-check-circle"></i> All good
                    </div>
                    <?php endif; ?>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-danger">
                            <i class="bi bi-x-circle"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['out_of_stock']); ?></div>
                    <div class="stat-label">Out of Stock</div>
                    <?php if ($stats['out_of_stock'] > 0): ?>
                    <div class="stat-change negative">
                        <i class="bi bi-exclamation-triangle"></i> Needs restocking
                    </div>
                    <?php else: ?>
                    <div class="stat-change positive">
                        <i class="bi bi-check-circle"></i> Fully stocked
                    </div>
                    <?php endif; ?>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-success">
                            <i class="bi bi-cash-coin"></i>
                        </div>
                    </div>
                    <div class="stat-value currency"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($stats['total_inventory_value'], 2); ?></div>
                    <div class="stat-label">Inventory Value</div>
                    <div class="stat-change positive">
                        <i class="bi bi-arrow-up"></i> Current value
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="quick-actions">
                <a href="create_order.php" class="action-btn">
                    <i class="bi bi-plus-circle"></i>
                    Create Order
                </a>
                <a href="view_orders.php" class="action-btn">
                    <i class="bi bi-list-check"></i>
                    View Orders
                </a>
                <a href="view_orders.php?filter=receivable" class="action-btn">
                    <i class="bi bi-box-arrow-in-down"></i>
                    Receive Order
                </a>
                <a href="create_return.php" class="action-btn">
                    <i class="bi bi-arrow-return-left"></i>
                    Create Return
                </a>
                <?php if (hasPermission('manage_products', $permissions)): ?>
                <a href="../products/add.php" class="action-btn">
                    <i class="bi bi-box-seam"></i>
                    Add Product
                </a>
                <?php endif; ?>
            </div>

            <!-- Low Stock Alert -->
            <?php if (!empty($low_stock_products)): ?>
            <div class="data-section">
                <div class="section-header">
                    <h3 class="section-title">Low Stock Alert</h3>
                    <a href="../products/products.php?filter=low_stock" class="btn btn-outline-warning btn-sm">View All</a>
                </div>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Product Name</th>
                                <th>Current Stock</th>
                                <th>Min. Stock</th>
                                <th>Cost Price</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($low_stock_products as $product): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($product['name']); ?></td>
                                <td><span class="badge bg-warning"><?php echo $product['quantity']; ?></span></td>
                                <td><?php echo $product['minimum_stock']; ?></td>
                                <td class="currency"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($product['cost_price'], 2); ?></td>
                                <td><span class="badge bg-danger">Low Stock</span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>



            <!-- Out of Stock Items -->
            <?php if ($stats['out_of_stock'] > 0): ?>
            <div class="data-section">
                <div class="section-header">
                    <h3 class="section-title">Out of Stock Items</h3>
                    <a href="../products/products.php?filter=out_of_stock" class="btn btn-outline-danger btn-sm">View All</a>
                </div>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <?php echo $stats['out_of_stock']; ?> products are currently out of stock and need immediate attention.
                </div>
            </div>
            <?php endif; ?>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/dashboard.js"></script>
</body>
</html>
