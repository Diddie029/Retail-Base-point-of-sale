<?php
session_start();
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$role_name = $_SESSION['role_name'] ?? 'User';

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$message = '';
$message_type = '';

// Handle form submission to search for specific order
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $search_term = trim($_POST['search_term'] ?? '');
    if (!empty($search_term)) {
        try {
            $stmt = $conn->prepare("
                SELECT io.*,
                       s.name as supplier_name,
                       u.username as created_by_name
                FROM inventory_orders io
                LEFT JOIN suppliers s ON io.supplier_id = s.id
                LEFT JOIN users u ON io.user_id = u.id
                WHERE io.id = :search_id OR io.order_number = :search_term OR io.invoice_number = :search_term
            ");
            $stmt->execute([
                ':search_id' => is_numeric($search_term) ? (int)$search_term : 0,
                ':search_term' => $search_term
            ]);
            $found_order = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($found_order) {
                $message = "Order found! ID: {$found_order['id']}, Order Number: {$found_order['order_number']}, Status: {$found_order['status']}";
                $message_type = 'success';
            } else {
                $message = "Order not found with search term: '{$search_term}'";
                $message_type = 'warning';
            }
        } catch (PDOException $e) {
            $message = "Database error: " . $e->getMessage();
            $message_type = 'danger';
        }
    }
}

// Get recent orders
$recent_orders = [];
try {
    $stmt = $conn->query("
        SELECT io.id, io.order_number, io.invoice_number, io.status, io.created_at,
               s.name as supplier_name,
               u.username as created_by_name
        FROM inventory_orders io
        LEFT JOIN suppliers s ON io.supplier_id = s.id
        LEFT JOIN users u ON io.user_id = u.id
        ORDER BY io.created_at DESC
        LIMIT 20
    ");
    $recent_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message = "Error loading orders: " . $e->getMessage();
    $message_type = 'danger';
}

// Get order statistics
$stats = [];
try {
    $stmt = $conn->query("SELECT COUNT(*) as total FROM inventory_orders");
    $stats['total_orders'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    $stmt = $conn->query("SELECT COUNT(*) as received FROM inventory_orders WHERE status = 'received'");
    $stats['received_orders'] = $stmt->fetch(PDO::FETCH_ASSOC)['received'];

    $stmt = $conn->query("SELECT COUNT(*) as pending FROM inventory_orders WHERE status = 'pending'");
    $stats['pending_orders'] = $stmt->fetch(PDO::FETCH_ASSOC)['pending'];
} catch (PDOException $e) {
    $stats = ['total_orders' => 0, 'received_orders' => 0, 'pending_orders' => 0];
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Debug Orders - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }

        .debug-section {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .debug-section h5 {
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }

        .stats-card {
            background: linear-gradient(135deg, var(--primary-color), #8b5cf6);
            color: white;
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1rem;
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
                    <h2>Debug Orders</h2>
                    <p class="header-subtitle">Troubleshoot order lookup issues</p>
                </div>
                <div class="header-actions">
                    <a href="inventory.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-2"></i>Back to Inventory
                    </a>
                </div>
            </div>
        </header>

        <!-- Content -->
        <main class="content">
            <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                <i class="bi bi-<?php echo $message_type === 'success' ? 'check-circle' : ($message_type === 'danger' ? 'exclamation-triangle' : 'info-circle'); ?> me-2"></i>
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Order Statistics -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="stats-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="mb-0"><?php echo $stats['total_orders']; ?></h5>
                                <small>Total Orders</small>
                            </div>
                            <i class="bi bi-receipt fs-1 opacity-75"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="mb-0"><?php echo $stats['received_orders']; ?></h5>
                                <small>Received Orders</small>
                            </div>
                            <i class="bi bi-check-circle fs-1 opacity-75"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="mb-0"><?php echo $stats['pending_orders']; ?></h5>
                                <small>Pending Orders</small>
                            </div>
                            <i class="bi bi-clock fs-1 opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Order Search -->
            <div class="debug-section">
                <h5><i class="bi bi-search me-2"></i>Search for Specific Order</h5>
                <form method="POST" class="row g-3">
                    <div class="col-md-8">
                        <label for="search_term" class="form-label">Order ID, Order Number, or Invoice Number</label>
                        <input type="text" class="form-control" id="search_term" name="search_term"
                               placeholder="Enter order ID, number, or invoice number" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary d-block">
                            <i class="bi bi-search me-2"></i>Search Order
                        </button>
                    </div>
                </form>
            </div>

            <!-- Recent Orders -->
            <div class="debug-section">
                <h5><i class="bi bi-clock-history me-2"></i>Recent Orders (Last 20)</h5>
                <?php if (empty($recent_orders)): ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>No orders found in the database.
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Order Number</th>
                                <th>Invoice Number</th>
                                <th>Status</th>
                                <th>Supplier</th>
                                <th>Created By</th>
                                <th>Created Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_orders as $order): ?>
                            <tr>
                                <td><?php echo $order['id']; ?></td>
                                <td><?php echo htmlspecialchars($order['order_number'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($order['invoice_number'] ?? 'N/A'); ?></td>
                                <td>
                                    <span class="badge bg-<?php
                                        echo $order['status'] === 'received' ? 'success' :
                                             ($order['status'] === 'pending' ? 'warning' :
                                             ($order['status'] === 'cancelled' ? 'danger' : 'secondary'));
                                    ?>">
                                        <?php echo ucfirst($order['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($order['supplier_name'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($order['created_by_name'] ?? 'System'); ?></td>
                                <td><?php echo date('M j, Y g:i A', strtotime($order['created_at'])); ?></td>
                                <td>
                                    <?php if ($order['status'] === 'received'): ?>
                                    <a href="view_invoice.php?id=<?php echo $order['id']; ?>" class="btn btn-sm btn-outline-success">
                                        <i class="bi bi-receipt"></i> View Invoice
                                    </a>
                                    <a href="view_order.php?id=<?php echo $order['id']; ?>" class="btn btn-sm btn-outline-primary ms-1">
                                        <i class="bi bi-eye"></i> Order Details
                                    </a>
                                    <?php else: ?>
                                    <a href="view_order.php?id=<?php echo $order['id']; ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-eye"></i> View Order
                                    </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- Database Check -->
            <div class="debug-section">
                <h5><i class="bi bi-database me-2"></i>Database Information</h5>
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Tables checked:</strong></p>
                        <ul>
                            <li>inventory_orders</li>
                            <li>inventory_order_items</li>
                            <li>suppliers</li>
                            <li>users</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Common issues:</strong></p>
                        <ul>
                            <li>Order ID format mismatch</li>
                            <li>Missing table data</li>
                            <li>Permission issues</li>
                            <li>Database connection problems</li>
                        </ul>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
    </script>
</body>
</html>
