<?php
session_start();

// Debug session configuration
error_log("Session save path: " . session_save_path());
error_log("Session cookie params: " . print_r(session_get_cookie_params(), true));
error_log("Session status: " . session_status());

// Ensure session is working properly
if (session_status() !== PHP_SESSION_ACTIVE) {
    error_log("Session not active, starting new session");
    session_start();
}
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Validate user exists in database
try {
    $stmt = $conn->prepare("SELECT id FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    if (!$stmt->fetch()) {
        // User doesn't exist, log them out
        session_destroy();
        header("Location: ../auth/login.php?error=user_not_found");
        exit();
    }
} catch (PDOException $e) {
    error_log("User validation error: " . $e->getMessage());
    header("Location: ../auth/login.php?error=db_error");
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

// Check if user has permission to view orders
if (!in_array('manage_products', $permissions) && !in_array('process_sales', $permissions)) {
    header("Location: inventory.php?error=permission_denied");
    exit();
}

// Get order ID from URL
$order_id = isset($_GET['id']) ? trim($_GET['id']) : null;
if (!$order_id) {
    header("Location: inventory.php?error=invalid_order");
    exit();
}

// Sanitize order ID - remove any potential harmful characters but keep alphanumeric and some special chars
$order_id = filter_var($order_id, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
if (empty($order_id)) {
    header("Location: inventory.php?error=invalid_order");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Function to get order data
function getOrderData($conn, $order_id) {
    try {
        // Get order details - try multiple approaches to find the order
        $stmt = $conn->prepare("
            SELECT io.*,
                   s.name as supplier_name, s.contact_person, s.phone, s.email, s.address,
                   u.username as created_by_name
            FROM inventory_orders io
            LEFT JOIN suppliers s ON io.supplier_id = s.id
            LEFT JOIN users u ON io.user_id = u.id
            WHERE (io.id = :order_id_numeric AND :order_id_numeric > 0)
               OR io.order_number = :order_number
               OR io.invoice_number = :invoice_number
        ");
        $stmt->execute([
            ':order_id_numeric' => is_numeric($order_id) ? (int)$order_id : 0,
            ':order_number' => $order_id,
            ':invoice_number' => $order_id
        ]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            // Debug: Log what we're looking for
            error_log("Order not found. Looking for: " . $order_id);
            error_log("Query params - order_id_numeric: " . (is_numeric($order_id) ? (int)$order_id : 0) . ", order_number: " . $order_id . ", invoice_number: " . $order_id);

            // Check if order exists with different criteria
            $debug_stmt = $conn->prepare("SELECT COUNT(*) as count FROM inventory_orders WHERE id = ? OR order_number = ? OR invoice_number = ?");
            $debug_stmt->execute([is_numeric($order_id) ? (int)$order_id : 0, $order_id, $order_id]);
            $count = $debug_stmt->fetch(PDO::FETCH_ASSOC)['count'];
            error_log("Total orders matching criteria: " . $count);

            // Also check what orders actually exist
            $debug_stmt2 = $conn->prepare("SELECT id, order_number, invoice_number, status FROM inventory_orders ORDER BY id DESC LIMIT 5");
            $debug_stmt2->execute();
            $recent_orders = $debug_stmt2->fetchAll(PDO::FETCH_ASSOC);
            error_log("Recent orders in database: " . json_encode($recent_orders));

            return null;
        }

        // Check if this is a received order - if so, redirect to invoice view
        if ($order['status'] === 'received') {
            header("Location: view_invoice.php?id=" . urlencode($order_id));
            exit();
        }

        // Get order items
        $stmt = $conn->prepare("
            SELECT ioi.*,
                   p.name as product_name, p.sku, p.description, p.image_url,
                   c.name as category_name, b.name as brand_name
            FROM inventory_order_items ioi
            LEFT JOIN products p ON ioi.product_id = p.id
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN brands b ON p.brand_id = b.id
            WHERE ioi.order_id = :order_id
            ORDER BY ioi.id ASC
        ");
        $stmt->bindParam(':order_id', $order['id']);
        $stmt->execute();
        $order['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate received totals
        $order['total_received_items'] = 0;
        $order['total_received_amount'] = 0;
        foreach ($order['items'] as $item) {
            $order['total_received_items'] += $item['received_quantity'];
            $order['total_received_amount'] += ($item['received_quantity'] * $item['cost_price']);
        }

        return $order;
    } catch (PDOException $e) {
        error_log("Error getting order data: " . $e->getMessage());
        return null;
    }
}

// Get order data
$order = getOrderData($conn, $order_id);
if (!$order) {
    header("Location: inventory.php?error=order_not_found");
    exit();
}

// Handle status updates
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'update_status':
            $new_status = $_POST['status'] ?? '';
            $valid_statuses = ['pending', 'sent', 'waiting_for_delivery', 'received', 'cancelled'];

            if (in_array($new_status, $valid_statuses)) {
                try {
                    $conn->beginTransaction();

                    // Update order status
                    $stmt = $conn->prepare("
                        UPDATE inventory_orders
                        SET status = :status, updated_at = NOW()
                        WHERE id = :order_id
                    ");
                    $stmt->execute([
                        ':status' => $new_status,
                        ':order_id' => $order['id']
                    ]);

                    // Log activity
                    logActivity($conn, $user_id, 'order_status_update',
                        "Updated order {$order['order_number']} status to {$new_status}");

                    $conn->commit();

                    // Refresh order data
                    $order = getOrderData($conn, $order_id);
                    $message = "Order status updated successfully to " . ucfirst($new_status);
                    $message_type = 'success';

                } catch (PDOException $e) {
                    $conn->rollBack();
                    $message = "Error updating order status: " . $e->getMessage();
                    $message_type = 'danger';
                }
            }
            break;

        case 'update_received_quantity':
            $item_id = $_POST['item_id'] ?? 0;
            $received_quantity = intval($_POST['received_quantity'] ?? 0);

            try {
                $conn->beginTransaction();

                // Get current item data
                $stmt = $conn->prepare("SELECT quantity FROM inventory_order_items WHERE id = :item_id AND order_id = :order_id");
                $stmt->execute([':item_id' => $item_id, ':order_id' => $order['id']]);
                $item = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($item && $received_quantity >= 0 && $received_quantity <= $item['quantity']) {
                    // Update received quantity
                    $stmt = $conn->prepare("
                        UPDATE inventory_order_items
                        SET received_quantity = :received_quantity
                        WHERE id = :item_id AND order_id = :order_id
                    ");
                    $stmt->execute([
                        ':received_quantity' => $received_quantity,
                        ':order_id' => $order['id'],
                        ':item_id' => $item_id
                    ]);

                    // Update product stock if fully received
                    if ($received_quantity == $item['quantity']) {
                        $stmt = $conn->prepare("
                            UPDATE products
                            SET quantity = quantity + :received_quantity,
                                updated_at = NOW()
                            WHERE id = (SELECT product_id FROM inventory_order_items WHERE id = :item_id)
                        ");
                        $stmt->bindParam(':received_quantity', $received_quantity, PDO::PARAM_INT);
                        $stmt->bindParam(':item_id', $item_id, PDO::PARAM_INT);
                        $stmt->execute();
                    }

                    // Log activity
                    logActivity($conn, $user_id, 'order_item_received',
                        "Updated received quantity for item in order {$order['order_number']}");

                    $conn->commit();

                    // Refresh order data
                    $order = getOrderData($conn, $order_id);
                    $message = "Received quantity updated successfully";
                    $message_type = 'success';
                } else {
                    $message = "Invalid received quantity";
                    $message_type = 'warning';
                }

            } catch (PDOException $e) {
                $conn->rollBack();
                $message = "Error updating received quantity: " . $e->getMessage();
                $message_type = 'danger';
            }
            break;
    }
}

// Get order status history (we'll implement this as a simple timeline based on status changes)
$status_timeline = [
    [
        'status' => 'pending',
        'date' => $order['created_at'],
        'user' => $order['created_by_name'] ?? 'System',
        'description' => 'Order created'
    ]
];

// Add current status if different from created
if ($order['status'] !== 'pending' && $order['updated_at'] !== $order['created_at']) {
    $status_timeline[] = [
        'status' => $order['status'],
        'date' => $order['updated_at'],
        'user' => $username,
        'description' => 'Status changed to ' . ucfirst($order['status'])
    ];
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Order <?php echo htmlspecialchars($order['order_number']); ?> - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
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

        .order-header {
            background: linear-gradient(135deg, var(--primary-color), #8b5cf6);
            color: white;
            border-radius: 0.5rem;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .status-badge {
            padding: 0.375rem 0.75rem;
            border-radius: 0.375rem;
            font-size: 0.875rem;
            font-weight: 600;
        }

        .status-pending {
            background-color: #fff3cd;
            color: #856404;
        }

        .status-sent {
            background-color: #cce7ff;
            color: #0066cc;
        }

        .status-waiting_for_delivery {
            background-color: #fff3cd;
            color: #856404;
        }

        .status-received {
            background-color: #d1edff;
            color: #0c5460;
        }

        .status-cancelled {
            background-color: #f8d7da;
            color: #721c24;
        }

        .timeline {
            position: relative;
            padding: 1rem 0;
        }

        .timeline-item {
            position: relative;
            padding-left: 2.5rem;
            margin-bottom: 1rem;
        }

        .timeline-item:before {
            content: '';
            position: absolute;
            left: 0.75rem;
            top: 0.25rem;
            width: 0.75rem;
            height: 0.75rem;
            border-radius: 50%;
            background-color: var(--primary-color);
            border: 0.125rem solid white;
            box-shadow: 0 0 0 0.25rem rgba(99, 102, 241, 0.1);
        }

        .timeline-item:after {
            content: '';
            position: absolute;
            left: 1rem;
            top: 2rem;
            width: 0.125rem;
            height: calc(100% - 1rem);
            background-color: #e9ecef;
        }

        .timeline-item:last-child:after {
            display: none;
        }

        .product-image {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 0.375rem;
        }

        .action-buttons .btn {
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
        }

        .small {
            font-size: 0.875rem;
        }

        .form-label.small {
            font-size: 0.8rem;
            font-weight: 600;
            color: #6c757d;
        }

        .badge-outline-success {
            color: #198754;
            border: 1px solid #198754;
            background-color: transparent;
        }

        .badge-outline-warning {
            color: #ffc107;
            border: 1px solid #ffc107;
            background-color: transparent;
        }

        .badge-outline-secondary {
            color: #6c757d;
            border: 1px solid #6c757d;
            background-color: transparent;
        }

        .breadcrumb-item a {
            text-decoration: none;
            color: var(--primary-color);
        }

        .breadcrumb-item a:hover {
            color: #5a67d8;
        }

        .progress-bar {
            transition: width 0.3s ease;
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
                    <h2>Order <?php echo htmlspecialchars($order['order_number']); ?></h2>
                    <p class="header-subtitle small">View order details and manage order status</p>
                </div>
                <div class="header-actions">
                    <a href="view_order.php?id=<?php echo urlencode($order_id); ?>" class="btn btn-outline-secondary" target="_blank">
                        <i class="bi bi-printer me-2"></i>Print Preview
                    </a>
                    <a href="create_order.php" class="btn btn-outline-primary">
                        <i class="bi bi-plus-circle me-2"></i>New Order
                    </a>
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

            <!-- Enhanced Navigation Breadcrumb -->
            <div class="card mb-3">
                <div class="card-body py-2">
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-0">
                            <li class="breadcrumb-item">
                                <a href="inventory.php"><i class="bi bi-house-door me-1"></i>Inventory</a>
                            </li>
                            <li class="breadcrumb-item">
                                <a href="view_orders.php">Orders</a>
                            </li>
                            <li class="breadcrumb-item active" aria-current="page">
                                Order <?php echo htmlspecialchars($order['order_number']); ?>
                            </li>
                            <?php if ($order['status'] === 'received' && !empty($order['invoice_number'])): ?>
                            <li class="breadcrumb-item">
                                <i class="bi bi-arrow-right mx-2"></i>
                                <a href="view_invoice.php?id=<?php echo urlencode($order['invoice_number']); ?>" class="text-success">
                                    <i class="bi bi-receipt me-1"></i>Invoice <?php echo htmlspecialchars($order['invoice_number']); ?>
                                </a>
                            </li>
                            <?php endif; ?>
                        </ol>
                    </nav>
                </div>
            </div>

            <!-- Order Status Flow -->
            <div class="card mb-3">
                <div class="card-body py-2">
                    <div class="d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center">
                            <span class="badge bg-<?php echo $order['status'] === 'pending' ? 'warning' : ($order['status'] === 'received' ? 'success' : 'primary'); ?> me-2">
                                <i class="bi bi-<?php echo $order['status'] === 'pending' ? 'clock' : ($order['status'] === 'received' ? 'check-circle' : 'arrow-right'); ?> me-1"></i>
                                <?php echo ucfirst(str_replace('_', ' ', $order['status'])); ?>
                            </span>
                            
                            <!-- Progress indicators -->
                            <div class="d-flex align-items-center small text-muted">
                                <span class="badge badge-outline-<?php echo in_array($order['status'], ['pending', 'sent', 'waiting_for_delivery', 'received']) ? 'success' : 'secondary'; ?>">Created</span>
                                <i class="bi bi-arrow-right mx-2"></i>
                                <span class="badge badge-outline-<?php echo in_array($order['status'], ['sent', 'waiting_for_delivery', 'received']) ? 'success' : 'secondary'; ?>">Sent</span>
                                <i class="bi bi-arrow-right mx-2"></i>
                                <span class="badge badge-outline-<?php echo in_array($order['status'], ['waiting_for_delivery', 'received']) ? 'warning' : 'secondary'; ?>">Awaiting</span>
                                <i class="bi bi-arrow-right mx-2"></i>
                                <span class="badge badge-outline-<?php echo $order['status'] === 'received' ? 'success' : 'secondary'; ?>">Received</span>
                                <?php if ($order['status'] === 'received' && !empty($order['invoice_number'])): ?>
                                <i class="bi bi-arrow-right mx-2"></i>
                                <span class="badge badge-outline-success">
                                    <i class="bi bi-receipt me-1"></i>Invoiced
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <small class="text-muted">
                            <?php 
                            $progress = 0;
                            switch($order['status']) {
                                case 'pending': $progress = 25; break;
                                case 'sent': $progress = 50; break;
                                case 'waiting_for_delivery': $progress = 75; break;
                                case 'received': $progress = 100; break;
                            }
                            echo $progress . '% Complete';
                            ?>
                        </small>
                    </div>
                    <div class="progress mt-2" style="height: 4px;">
                        <div class="progress-bar bg-<?php echo $order['status'] === 'received' ? 'success' : 'primary'; ?>" 
                             style="width: <?php echo $progress; ?>%"></div>
                    </div>
                </div>
            </div>

            <!-- Order Header -->
            <div class="order-header">
                <div class="row">
                    <div class="col-md-6">
                        <h4 class="mb-1">Order <?php echo htmlspecialchars($order['order_number']); ?></h4>
                        <p class="mb-0 opacity-75 small">Created on <?php echo date('M j, Y g:i A', strtotime($order['created_at'])); ?></p>
                    </div>
                    <div class="col-md-6 text-end">
                        <span class="status-badge status-<?php echo $order['status']; ?>">
                            <?php echo ucfirst(str_replace('_', ' ', $order['status'])); ?>
                        </span>
                    </div>
                </div>
            </div>

            <div class="row">
                <!-- Order Details -->
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>Order Details</h5>
                        </div>
                        <div class="card-body">
                            <div class="row order-details-row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold small">Supplier Information</label>
                                        <div class="fw-bold"><?php echo htmlspecialchars($order['supplier_name'] ?? 'N/A'); ?></div>
                                        <?php if ($order['contact_person']): ?>
                                        <small class="text-muted d-block">Contact: <?php echo htmlspecialchars($order['contact_person']); ?></small>
                                        <?php endif; ?>
                                        <?php if ($order['phone']): ?>
                                        <small class="text-muted d-block">Phone: <?php echo htmlspecialchars($order['phone']); ?></small>
                                        <?php endif; ?>
                                        <?php if ($order['email']): ?>
                                        <small class="text-muted d-block">Email: <?php echo htmlspecialchars($order['email']); ?></small>
                                        <?php endif; ?>
                                        <?php if ($order['address']): ?>
                                        <small class="text-muted d-block">Address: <?php echo nl2br(htmlspecialchars($order['address'])); ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3 company-details">
                                        <label class="form-label fw-semibold small">Company Details</label>
                                        <div class="fw-bold"><?php echo htmlspecialchars($settings['company_name'] ?? 'Liza Point Of Sale'); ?></div>
                                        <?php if ($settings['company_address']): ?>
                                        <small class="text-muted d-block"><?php echo nl2br(htmlspecialchars($settings['company_address'])); ?></small>
                                        <?php endif; ?>
                                        <?php if ($settings['company_phone']): ?>
                                        <small class="text-muted d-block">Phone: <?php echo htmlspecialchars($settings['company_phone']); ?></small>
                                        <?php endif; ?>
                                        <?php if ($settings['company_email']): ?>
                                        <small class="text-muted d-block">Email: <?php echo htmlspecialchars($settings['company_email']); ?></small>
                                        <?php endif; ?>
                                        <?php if ($settings['company_website']): ?>
                                        <small class="text-muted d-block">Website: <?php echo htmlspecialchars($settings['company_website']); ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="row mt-3">
                                <div class="col-12">
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold small">Order Dates</label>
                                        <div class="row">
                                            <div class="col-md-4">
                                                <small class="text-muted">Order Date:</small>
                                                <div><?php echo date('M j, Y', strtotime($order['order_date'])); ?></div>
                                            </div>
                                            <?php if ($order['expected_date']): ?>
                                            <div class="col-md-4">
                                                <small class="text-muted">Expected Date:</small>
                                                <div><?php echo date('M j, Y', strtotime($order['expected_date'])); ?></div>
                                            </div>
                                            <?php endif; ?>
                                            <div class="col-md-4">
                                                <small class="text-muted">Created:</small>
                                                <div><?php echo date('M j, Y g:i A', strtotime($order['created_at'])); ?></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <?php if ($order['notes']): ?>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Notes</label>
                                <div><?php echo nl2br(htmlspecialchars($order['notes'])); ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Order Items -->
                    <div class="card mt-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>Order Items</h5>
                            <span class="badge bg-primary"><?php echo count($order['items']); ?> items</span>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Product</th>
                                            <th>SKU</th>
                                            <th class="text-center">Ordered</th>
                                            <th class="text-center">Received</th>
                                            <th class="text-end">Cost Price</th>
                                            <th class="text-end">Total</th>
                                            <th class="text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($order['items'] as $item): ?>
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <?php if ($item['image_url']): ?>
                                                    <img src="<?php echo htmlspecialchars($item['image_url']); ?>"
                                                         alt="<?php echo htmlspecialchars($item['product_name']); ?>"
                                                         class="product-image me-3">
                                                    <?php endif; ?>
                                                    <div>
                                                        <div class="fw-semibold"><?php echo htmlspecialchars($item['product_name']); ?></div>
                                                        <?php if ($item['category_name']): ?>
                                                        <small class="text-muted"><?php echo htmlspecialchars($item['category_name']); ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($item['sku'] ?? 'N/A'); ?></td>
                                            <td class="text-center"><?php echo $item['quantity']; ?></td>
                                            <td class="text-center">
                                                <span class="fw-semibold"><?php echo $item['received_quantity']; ?></span>
                                                <?php if ($item['received_quantity'] < $item['quantity'] && $order['status'] !== 'cancelled'): ?>
                                                <form method="POST" class="d-inline ms-2">
                                                    <input type="hidden" name="action" value="update_received_quantity">
                                                    <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                                                    <input type="number" name="received_quantity" value="<?php echo $item['received_quantity']; ?>"
                                                           min="0" max="<?php echo $item['quantity']; ?>" class="form-control form-control-sm d-inline-block"
                                                           style="width: 70px;" onchange="this.form.submit()">
                                                </form>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end"><?php echo formatCurrency($item['cost_price'], $settings['currency_symbol'] ?? 'KES'); ?></td>
                                            <td class="text-end"><?php echo formatCurrency($item['quantity'] * $item['cost_price'], $settings['currency_symbol'] ?? 'KES'); ?></td>
                                            <td class="text-center">
                                                <?php if ($item['received_quantity'] < $item['quantity'] && $order['status'] !== 'cancelled'): ?>
                                                <button type="button" class="btn btn-sm btn-outline-success"
                                                        onclick="updateReceivedQuantity(<?php echo $item['id']; ?>, <?php echo $item['quantity']; ?>)">
                                                    <i class="bi bi-check-circle"></i>
                                                </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sidebar -->
                <div class="col-lg-4">
                    <!-- Order Summary -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-calculator me-2"></i>Order Summary</h5>
                        </div>
                        <div class="card-body">
                            <div class="row mb-2">
                                <div class="col-6">Total Items:</div>
                                <div class="col-6 text-end fw-semibold"><?php echo $order['total_items']; ?></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-6">Total Amount:</div>
                                <div class="col-6 text-end fw-semibold"><?php echo formatCurrency($order['total_amount'], $settings['currency_symbol'] ?? 'KES'); ?></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-6">Received Items:</div>
                                <div class="col-6 text-end"><?php echo $order['total_received_items']; ?></div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-6">Received Amount:</div>
                                <div class="col-6 text-end"><?php echo formatCurrency($order['total_received_amount'], $settings['currency_symbol'] ?? 'KES'); ?></div>
                            </div>
                            <hr>
                            <div class="row">
                                <div class="col-6 fw-semibold">Outstanding:</div>
                                <div class="col-6 text-end fw-bold">
                                    <?php
                                    $outstanding_items = $order['total_items'] - $order['total_received_items'];
                                    $outstanding_amount = $order['total_amount'] - $order['total_received_amount'];
                                    echo $outstanding_items . ' items / ' . formatCurrency($outstanding_amount, $settings['currency_symbol'] ?? 'KES');
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="card mt-4">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-gear me-2"></i>Actions</h5>
                        </div>
                        <div class="card-body">
                            <!-- Quick Reception Actions -->
                            <?php if (in_array($order['status'], ['sent', 'waiting_for_delivery'])): ?>
                            <div class="mb-3 p-3 bg-light rounded">
                                <h6 class="text-primary mb-2"><i class="bi bi-lightning me-2"></i>Quick Reception</h6>
                                <div class="d-grid gap-2 d-md-flex justify-content-md-start">
                                    <button type="button" class="btn btn-success btn-sm" onclick="markAllItemsReceived()">
                                        <i class="bi bi-check-all me-1"></i>Mark All Received
                                    </button>
                                    <button type="button" class="btn btn-warning btn-sm" onclick="showDiscrepancyModal()">
                                        <i class="bi bi-exclamation-triangle me-1"></i>Receive with Discrepancies
                                    </button>
                                    <a href="receive_order.php?id=<?php echo urlencode($order_id); ?>" class="btn btn-info btn-sm">
                                        <i class="bi bi-boxes me-1"></i>Partial Reception
                                    </a>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <div class="action-buttons">
                                <?php if ($order['status'] === 'pending'): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="status" value="sent">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-send me-2"></i>Mark as Sent
                                    </button>
                                </form>
                                <?php endif; ?>

                                <?php if ($order['status'] === 'sent'): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="status" value="waiting_for_delivery">
                                    <button type="submit" class="btn btn-warning">
                                        <i class="bi bi-clock me-2"></i>Mark as Waiting for Delivery
                                    </button>
                                </form>
                                <?php endif; ?>

                                <?php if ($order['status'] === 'waiting_for_delivery' || $order['status'] === 'sent'): ?>
                                <a href="receive_order.php?id=<?php echo urlencode($order_id); ?>" class="btn btn-success">
                                    <i class="bi bi-box-arrow-in-down me-2"></i>Receive Order
                                </a>
                                <?php endif; ?>

                                <?php if ($order['status'] === 'waiting_for_delivery'): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="status" value="received">
                                    <button type="submit" class="btn btn-success">
                                        <i class="bi bi-check-circle me-2"></i>Mark as Received
                                    </button>
                                </form>
                                <?php endif; ?>

                                <?php if (in_array($order['status'], ['pending', 'sent', 'waiting_for_delivery'])): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="status" value="cancelled">
                                    <button type="submit" class="btn btn-danger" onclick="return confirm('Are you sure you want to cancel this order?')">
                                        <i class="bi bi-x-circle me-2"></i>Cancel Order
                                    </button>
                                </form>
                                <?php endif; ?>

                                <a href="generate_pdf.php?id=<?php echo urlencode($order_id); ?>&download=1" class="btn btn-success">
                                    <i class="bi bi-file-earmark-pdf me-2"></i><?php echo $order['status'] === 'received' ? 'Download Invoice' : 'Download PDF'; ?>
                                </a>
                                <a href="view_order.php?id=<?php echo urlencode($order_id); ?>" class="btn btn-outline-secondary" target="_blank">
                                    <i class="bi bi-printer me-2"></i>Print Preview
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Order Timeline -->
                    <div class="card mt-4">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Order Timeline</h5>
                        </div>
                        <div class="card-body">
                            <div class="timeline">
                                <?php foreach ($status_timeline as $timeline_item): ?>
                                <div class="timeline-item">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($timeline_item['description']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($timeline_item['user']); ?></small>
                                        </div>
                                        <small class="text-muted"><?php echo date('M j, g:i A', strtotime($timeline_item['date'])); ?></small>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function updateReceivedQuantity(itemId, maxQuantity) {
            const quantity = prompt('Enter received quantity (max: ' + maxQuantity + '):', '0');
            if (quantity !== null) {
                const qty = parseInt(quantity);
                if (qty >= 0 && qty <= maxQuantity) {
                    // Create a form to submit the update
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `
                        <input type="hidden" name="action" value="update_received_quantity">
                        <input type="hidden" name="item_id" value="${itemId}">
                        <input type="hidden" name="received_quantity" value="${qty}">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                } else {
                    alert('Please enter a valid quantity between 0 and ' + maxQuantity);
                }
            }
        }

        // Quick reception functions
        function markAllItemsReceived() {
            if (confirm('Mark all items as fully received? This will update the product stock and mark the order as received.')) {
                const promises = [];
                
                <?php foreach ($order['items'] as $item): ?>
                <?php if ($item['received_quantity'] < $item['quantity']): ?>
                const form<?php echo $item['id']; ?> = document.createElement('form');
                form<?php echo $item['id']; ?>.method = 'POST';
                form<?php echo $item['id']; ?>.innerHTML = `
                    <input type="hidden" name="action" value="update_received_quantity">
                    <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                    <input type="hidden" name="received_quantity" value="<?php echo $item['quantity']; ?>">
                `;
                document.body.appendChild(form<?php echo $item['id']; ?>);
                form<?php echo $item['id']; ?>.submit();
                return; // Submit first form and let page reload
                <?php endif; ?>
                <?php endforeach; ?>
                
                // If no items need updating, just reload
                location.reload();
            }
        }
        
        function showDiscrepancyModal() {
            // Create a simple modal for bulk discrepancy handling
            const modalHtml = `
                <div class="modal fade" id="discrepancyModal" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Receive with Discrepancies</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <form id="discrepancyForm">
                                    <input type="hidden" name="action" value="bulk_receive_discrepancies">
                                    <div class="table-responsive">
                                        <table class="table">
                                            <thead>
                                                <tr>
                                                    <th>Product</th>
                                                    <th>Ordered</th>
                                                    <th>Received</th>
                                                    <th>New Quantity</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($order['items'] as $item): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                                                    <td><?php echo $item['quantity']; ?></td>
                                                    <td><?php echo $item['received_quantity']; ?></td>
                                                    <td>
                                                        <input type="number" class="form-control" 
                                                               name="item_<?php echo $item['id']; ?>" 
                                                               value="<?php echo $item['received_quantity']; ?>"
                                                               min="0" max="<?php echo $item['quantity']; ?>">
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </form>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="button" class="btn btn-warning" onclick="submitDiscrepancies()">Update Quantities</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Remove existing modal if any
            const existingModal = document.getElementById('discrepancyModal');
            if (existingModal) {
                existingModal.remove();
            }
            
            // Add modal to DOM
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            
            // Show modal
            const modal = new bootstrap.Modal(document.getElementById('discrepancyModal'));
            modal.show();
        }
        
        function submitDiscrepancies() {
            const form = document.getElementById('discrepancyForm');
            const formData = new FormData(form);
            
            // Process each item
            let hasUpdates = false;
            <?php foreach ($order['items'] as $item): ?>
            const qty<?php echo $item['id']; ?> = parseInt(formData.get('item_<?php echo $item['id']; ?>')) || 0;
            if (qty<?php echo $item['id']; ?> !== <?php echo $item['received_quantity']; ?>) {
                hasUpdates = true;
                // Submit individual update
                const itemForm = document.createElement('form');
                itemForm.method = 'POST';
                itemForm.innerHTML = `
                    <input type="hidden" name="action" value="update_received_quantity">
                    <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                    <input type="hidden" name="received_quantity" value="${qty<?php echo $item['id']; ?>}">
                `;
                document.body.appendChild(itemForm);
                itemForm.submit();
                return; // Submit first change and reload
            }
            <?php endforeach; ?>
            
            if (!hasUpdates) {
                alert('No changes detected.');
            }
        }

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
