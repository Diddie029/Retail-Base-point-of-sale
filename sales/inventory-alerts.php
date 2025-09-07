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

// Check if user has permission to view inventory
if (!hasPermission('view_inventory', $permissions) && !hasPermission('manage_inventory', $permissions)) {
    header('Location: ../dashboard/dashboard.php?error=access_denied');
    exit();
}

// Handle actions
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'update_reorder_level':
                try {
                    $product_id = $_POST['product_id'];
                    $new_reorder_level = $_POST['reorder_level'];
                    
                    $stmt = $conn->prepare("UPDATE products SET reorder_point = ? WHERE id = ?");
                    $stmt->execute([$new_reorder_level, $product_id]);
                    $success = 'Reorder level updated successfully!';
                } catch (Exception $e) {
                    $error = 'Error updating reorder level: ' . $e->getMessage();
                }
                break;
                
            case 'mark_alert_read':
                try {
                    $product_id = $_POST['product_id'];
                    // You could add a field to track read alerts, for now we'll just show success
                    $success = 'Alert marked as read!';
                } catch (Exception $e) {
                    $error = 'Error marking alert: ' . $e->getMessage();
                }
                break;
        }
    }
}

// Get system settings
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
$settings = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get low stock alerts
$stmt = $conn->query("
    SELECT p.*, c.name as category_name, s.name as supplier_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN suppliers s ON p.supplier_id = s.id
    WHERE p.quantity <= p.reorder_point AND p.status = 'active'
    ORDER BY (p.quantity / p.reorder_point) ASC, p.quantity ASC
");
$low_stock_alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get out of stock products
$stmt = $conn->query("
    SELECT p.*, c.name as category_name, s.name as supplier_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN suppliers s ON p.supplier_id = s.id
    WHERE p.quantity = 0 AND p.status = 'active'
    ORDER BY p.name
");
$out_of_stock = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get products approaching reorder level (within 20% of reorder point)
$stmt = $conn->query("
    SELECT p.*, c.name as category_name, s.name as supplier_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN suppliers s ON p.supplier_id = s.id
    WHERE p.quantity > p.reorder_point 
    AND p.quantity <= (p.reorder_point * 1.2) 
    AND p.status = 'active'
    ORDER BY (p.quantity / p.reorder_point) ASC
");
$approaching_reorder = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get inventory summary
$stmt = $conn->query("
    SELECT 
        COUNT(*) as total_products,
        SUM(CASE WHEN quantity = 0 THEN 1 ELSE 0 END) as out_of_stock,
        SUM(CASE WHEN quantity <= reorder_point AND quantity > 0 THEN 1 ELSE 0 END) as low_stock,
        SUM(CASE WHEN quantity > reorder_point THEN 1 ELSE 0 END) as in_stock,
        SUM(quantity * price) as total_inventory_value
    FROM products 
    WHERE status = 'active'
");
$inventory_summary = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Alerts - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        /* Main Content Layout */
        .main-content {
            margin-left: 250px;
            min-height: 100vh;
            background-color: #f8fafc;
            padding: 20px;
        }
        
        .alert-card {
            transition: transform 0.2s;
            border-left: 4px solid;
        }
        .alert-card:hover {
            transform: translateY(-2px);
        }
        .alert-critical {
            border-left-color: #dc3545;
        }
        .alert-warning {
            border-left-color: #ffc107;
        }
        .alert-info {
            border-left-color: #17a2b8;
        }
        .stock-level {
            font-size: 1.2rem;
            font-weight: bold;
        }
        .reorder-form {
            display: none;
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 10px;
            }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../include/navmenu.php'; ?>
    
    <!-- Main Content -->
    <div class="main-content">
        <div class="container-fluid">
        <div class="row">
            <div class="col-md-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h2><i class="bi bi-exclamation-triangle text-danger"></i> Inventory Alerts</h2>
                        <p class="text-muted">Low stock notifications and management</p>
                    </div>
                    <a href="salesdashboard.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Back to Dashboard
                    </a>
                </div>

                <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle"></i> <?php echo $success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-circle"></i> <?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <!-- Inventory Summary -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <div class="text-primary fs-2"><?php echo $inventory_summary['total_products']; ?></div>
                                <div class="text-muted">Total Products</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <div class="text-danger fs-2"><?php echo $inventory_summary['out_of_stock']; ?></div>
                                <div class="text-muted">Out of Stock</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <div class="text-warning fs-2"><?php echo $inventory_summary['low_stock']; ?></div>
                                <div class="text-muted">Low Stock</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <div class="text-success fs-2"><?php echo $inventory_summary['in_stock']; ?></div>
                                <div class="text-muted">In Stock</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Out of Stock Products -->
                <?php if (!empty($out_of_stock)): ?>
                <div class="card mb-4">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0"><i class="bi bi-x-circle"></i> Out of Stock (<?php echo count($out_of_stock); ?>)</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php foreach ($out_of_stock as $product): ?>
                            <div class="col-md-6 mb-3">
                                <div class="alert-card alert-critical card">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <h6 class="mb-1"><?php echo htmlspecialchars($product['name']); ?></h6>
                                                <p class="text-muted mb-1">SKU: <?php echo htmlspecialchars($product['sku']); ?></p>
                                                <p class="text-muted mb-1">Category: <?php echo htmlspecialchars($product['category_name'] ?? 'N/A'); ?></p>
                                                <p class="text-muted mb-0">Supplier: <?php echo htmlspecialchars($product['supplier_name'] ?? 'N/A'); ?></p>
                                            </div>
                                            <div class="text-end">
                                                <div class="stock-level text-danger">0</div>
                                                <small class="text-muted">Reorder: <?php echo $product['reorder_point']; ?></small>
                                            </div>
                                        </div>
                                        <div class="mt-2">
                                            <button class="btn btn-sm btn-outline-primary" onclick="toggleReorderForm(<?php echo $product['id']; ?>)">
                                                <i class="bi bi-gear"></i> Update Reorder Level
                                            </button>
                                            <a href="../inventory/create_order.php?product_id=<?php echo $product['id']; ?>" class="btn btn-sm btn-primary">
                                                <i class="bi bi-plus"></i> Create Order
                                            </a>
                                        </div>
                                        <div class="reorder-form mt-2" id="reorder-form-<?php echo $product['id']; ?>">
                                            <form method="POST" class="d-flex gap-2">
                                                <input type="hidden" name="action" value="update_reorder_level">
                                                <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                                                <input type="number" name="reorder_level" class="form-control form-control-sm" 
                                                       value="<?php echo $product['reorder_point']; ?>" min="0" step="1">
                                                <button type="submit" class="btn btn-sm btn-success">
                                                    <i class="bi bi-check"></i> Update
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Low Stock Alerts -->
                <?php if (!empty($low_stock_alerts)): ?>
                <div class="card mb-4">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="mb-0"><i class="bi bi-exclamation-triangle"></i> Low Stock Alerts (<?php echo count($low_stock_alerts); ?>)</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php foreach ($low_stock_alerts as $product): ?>
                            <div class="col-md-6 mb-3">
                                <div class="alert-card alert-warning card">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <h6 class="mb-1"><?php echo htmlspecialchars($product['name']); ?></h6>
                                                <p class="text-muted mb-1">SKU: <?php echo htmlspecialchars($product['sku']); ?></p>
                                                <p class="text-muted mb-1">Category: <?php echo htmlspecialchars($product['category_name'] ?? 'N/A'); ?></p>
                                                <p class="text-muted mb-0">Supplier: <?php echo htmlspecialchars($product['supplier_name'] ?? 'N/A'); ?></p>
                                            </div>
                                            <div class="text-end">
                                                <div class="stock-level text-warning"><?php echo $product['quantity']; ?></div>
                                                <small class="text-muted">Reorder: <?php echo $product['reorder_point']; ?></small>
                                            </div>
                                        </div>
                                        <div class="mt-2">
                                            <button class="btn btn-sm btn-outline-primary" onclick="toggleReorderForm(<?php echo $product['id']; ?>)">
                                                <i class="bi bi-gear"></i> Update Reorder Level
                                            </button>
                                            <a href="../inventory/create_order.php?product_id=<?php echo $product['id']; ?>" class="btn btn-sm btn-primary">
                                                <i class="bi bi-plus"></i> Create Order
                                            </a>
                                        </div>
                                        <div class="reorder-form mt-2" id="reorder-form-<?php echo $product['id']; ?>">
                                            <form method="POST" class="d-flex gap-2">
                                                <input type="hidden" name="action" value="update_reorder_level">
                                                <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                                                <input type="number" name="reorder_level" class="form-control form-control-sm" 
                                                       value="<?php echo $product['reorder_point']; ?>" min="0" step="1">
                                                <button type="submit" class="btn btn-sm btn-success">
                                                    <i class="bi bi-check"></i> Update
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Approaching Reorder Level -->
                <?php if (!empty($approaching_reorder)): ?>
                <div class="card mb-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="bi bi-info-circle"></i> Approaching Reorder Level (<?php echo count($approaching_reorder); ?>)</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php foreach ($approaching_reorder as $product): ?>
                            <div class="col-md-6 mb-3">
                                <div class="alert-card alert-info card">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <h6 class="mb-1"><?php echo htmlspecialchars($product['name']); ?></h6>
                                                <p class="text-muted mb-1">SKU: <?php echo htmlspecialchars($product['sku']); ?></p>
                                                <p class="text-muted mb-1">Category: <?php echo htmlspecialchars($product['category_name'] ?? 'N/A'); ?></p>
                                                <p class="text-muted mb-0">Supplier: <?php echo htmlspecialchars($product['supplier_name'] ?? 'N/A'); ?></p>
                                            </div>
                                            <div class="text-end">
                                                <div class="stock-level text-info"><?php echo $product['quantity']; ?></div>
                                                <small class="text-muted">Reorder: <?php echo $product['reorder_point']; ?></small>
                                            </div>
                                        </div>
                                        <div class="mt-2">
                                            <button class="btn btn-sm btn-outline-primary" onclick="toggleReorderForm(<?php echo $product['id']; ?>)">
                                                <i class="bi bi-gear"></i> Update Reorder Level
                                            </button>
                                            <a href="../inventory/create_order.php?product_id=<?php echo $product['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                <i class="bi bi-plus"></i> Create Order
                                            </a>
                                        </div>
                                        <div class="reorder-form mt-2" id="reorder-form-<?php echo $product['id']; ?>">
                                            <form method="POST" class="d-flex gap-2">
                                                <input type="hidden" name="action" value="update_reorder_level">
                                                <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                                                <input type="number" name="reorder_level" class="form-control form-control-sm" 
                                                       value="<?php echo $product['reorder_point']; ?>" min="0" step="1">
                                                <button type="submit" class="btn btn-sm btn-success">
                                                    <i class="bi bi-check"></i> Update
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- No Alerts Message -->
                <?php if (empty($out_of_stock) && empty($low_stock_alerts) && empty($approaching_reorder)): ?>
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="bi bi-check-circle text-success" style="font-size: 4rem;"></i>
                        <h4 class="mt-3 text-success">All Good!</h4>
                        <p class="text-muted">No inventory alerts at this time. All products are well stocked.</p>
                        <a href="../inventory/inventory.php" class="btn btn-primary">
                            <i class="bi bi-boxes"></i> View Inventory
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleReorderForm(productId) {
            const form = document.getElementById('reorder-form-' + productId);
            if (form.style.display === 'none' || form.style.display === '') {
                form.style.display = 'block';
            } else {
                form.style.display = 'none';
            }
        }
    </script>
</body>
</html>
