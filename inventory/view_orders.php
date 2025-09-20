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

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Handle filters and search
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$supplier_filter = $_GET['supplier'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$filter = $_GET['filter'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;

// Handle special filters
if ($filter === 'receivable') {

}

// Exclude received orders by default - show only active orders unless explicitly requested
if (empty($status_filter) && empty($filter) && empty($search) && empty($supplier_filter) && empty($date_from) && empty($date_to)) {
    // Default behavior: exclude received orders to show only active orders
    $exclude_received = true;
}

// Build WHERE clause
$where = [];
$params = [];

if (!empty($search)) {
    $where[] = "(io.order_number LIKE :search OR s.name LIKE :search OR u.username LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if ($filter === 'receivable') {
    // Show ALL orders for receiving - no status filtering applied
    // This allows users to see and receive any order regardless of status
} elseif (!empty($status_filter)) {
    $where[] = "io.status = :status";
    $params[':status'] = $status_filter;
} elseif (isset($exclude_received) && $exclude_received) {
    // Exclude received orders by default
    $where[] = "io.status != 'received'";
}

if (!empty($supplier_filter)) {
    $where[] = "io.supplier_id = :supplier_id";
    $params[':supplier_id'] = $supplier_filter;
}

if (!empty($date_from)) {
    $where[] = "io.order_date >= :date_from";
    $params[':date_from'] = $date_from;
}

if (!empty($date_to)) {
    $where[] = "io.order_date <= :date_to";
    $params[':date_to'] = $date_to;
}

$where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Get total count
$count_sql = "
    SELECT COUNT(*) as total
    FROM inventory_orders io
    LEFT JOIN suppliers s ON io.supplier_id = s.id
    LEFT JOIN users u ON io.user_id = u.id
    $where_clause
";

$stmt = $conn->prepare($count_sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$total_orders = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_orders / $per_page);

// Get orders with pagination
$offset = ($page - 1) * $per_page;
$sql = "
    SELECT io.*,
           s.name as supplier_name, s.contact_person,
           u.username as created_by_name,
           COALESCE(SUM(ioi.received_quantity), 0) as total_received_items,
           COUNT(ioi.id) as total_items_count
    FROM inventory_orders io
    LEFT JOIN suppliers s ON io.supplier_id = s.id
    LEFT JOIN users u ON io.user_id = u.id
    LEFT JOIN inventory_order_items ioi ON io.id = ioi.order_id
    $where_clause
    GROUP BY io.id, s.name, s.contact_person, u.username
    ORDER BY io.created_at DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $conn->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get suppliers for filter dropdown
$suppliers = [];
$stmt = $conn->query("SELECT id, name FROM suppliers ORDER BY name");
$suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get order statistics
$stats = [];
$stmt = $conn->query("
    SELECT status, COUNT(*) as count,
           COALESCE(SUM(total_amount), 0) as total_amount
    FROM inventory_orders
    GROUP BY status
");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $stats[$row['status']] = $row;
}

// Calculate totals
$stats['total'] = [
    'count' => $total_orders,
    'total_amount' => 0
];

foreach ($stats as $status => $data) {
    if ($status !== 'total') {
        $stats['total']['total_amount'] += $data['total_amount'];
    }
}

// Handle bulk actions
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $action = $_POST['bulk_action'];
    $order_ids = $_POST['order_ids'] ?? [];

    if (!empty($order_ids)) {
        try {
            $conn->beginTransaction();

            $placeholders = str_repeat('?,', count($order_ids) - 1) . '?';

            switch ($action) {
                case 'mark_sent':
                    $stmt = $conn->prepare("
                        UPDATE inventory_orders
                        SET status = 'sent', updated_at = NOW()
                        WHERE id IN ($placeholders)
                        AND status NOT IN ('sent', 'waiting_for_delivery', 'received', 'cancelled')
                    ");
                    $result = $stmt->execute($order_ids);
                    $affected_rows = $stmt->rowCount();
                    if ($affected_rows > 0) {
                        $message = "$affected_rows order(s) marked as sent";
                        if ($affected_rows < count($order_ids)) {
                            $message .= " (" . (count($order_ids) - $affected_rows) . " order(s) were already sent or in later status)";
                        }
                    } else {
                        $message = "No orders could be marked as sent - selected orders are already sent or in later status";
                        $message_type = 'warning';
                    }
                    break;

                case 'mark_waiting_delivery':
                    $stmt = $conn->prepare("
                        UPDATE inventory_orders
                        SET status = 'waiting_for_delivery', updated_at = NOW()
                        WHERE id IN ($placeholders)
                        AND status NOT IN ('waiting_for_delivery', 'received', 'cancelled')
                    ");
                    $result = $stmt->execute($order_ids);
                    $affected_rows = $stmt->rowCount();
                    if ($affected_rows > 0) {
                        $message = "$affected_rows order(s) marked as waiting for delivery";
                        if ($affected_rows < count($order_ids)) {
                            $message .= " (" . (count($order_ids) - $affected_rows) . " order(s) were already waiting for delivery or in later status)";
                        }
                    } else {
                        $message = "No orders could be marked as waiting for delivery - selected orders are already waiting for delivery or in later status";
                        $message_type = 'warning';
                    }
                    break;

                case 'mark_received':
                    $stmt = $conn->prepare("
                        UPDATE inventory_orders
                        SET status = 'received', updated_at = NOW()
                        WHERE id IN ($placeholders)
                        AND status NOT IN ('received', 'cancelled')
                    ");
                    $result = $stmt->execute($order_ids);
                    $affected_rows = $stmt->rowCount();
                    if ($affected_rows > 0) {
                        $message = "$affected_rows order(s) marked as received";
                        if ($affected_rows < count($order_ids)) {
                            $message .= " (" . (count($order_ids) - $affected_rows) . " order(s) were already received or cancelled)";
                        }
                    } else {
                        $message = "No orders could be marked as received - selected orders are already received or cancelled";
                        $message_type = 'warning';
                    }
                    break;

                case 'cancel_orders':
                    // Only cancel orders that are not received or already cancelled
                    $stmt = $conn->prepare("
                        UPDATE inventory_orders
                        SET status = 'cancelled', updated_at = NOW()
                        WHERE id IN ($placeholders)
                        AND status NOT IN ('received', 'cancelled')
                    ");
                    $result = $stmt->execute($order_ids);

                    // Check how many orders were actually updated
                    $affected_rows = $stmt->rowCount();
                    if ($affected_rows > 0) {
                        $message = "$affected_rows order(s) cancelled successfully";
                        if ($affected_rows < count($order_ids)) {
                            $message .= " (" . (count($order_ids) - $affected_rows) . " order(s) could not be cancelled - already received or cancelled)";
                        }
                    } else {
                        $message = "No orders could be cancelled - selected orders are already received or cancelled";
                        $message_type = 'warning';
                    }
                    break;

                case 'print_invoices':
                    // Validate that selected orders are received
                    $stmt = $conn->prepare("
                        SELECT COUNT(*) as count FROM inventory_orders
                        WHERE id IN ($placeholders) AND status = 'received'
                    ");
                    $stmt->execute($order_ids);
                    $received_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

                    if ($received_count > 0) {
                        if ($received_count === count($order_ids)) {
                            // All selected orders are received - redirect to bulk print
                            $order_ids_string = implode(',', $order_ids);
                            header("Location: print_bulk_invoices.php?ids=" . urlencode($order_ids_string));
                            exit();
                        } else {
                            $message = "Only $received_count out of " . count($order_ids) . " selected orders are received and can be printed";
                            $message_type = 'warning';
                        }
                    } else {
                        $message = "No received orders selected for printing";
                        $message_type = 'warning';
                    }
                    break;
            }

            // Log activity
            logActivity($conn, $user_id, 'bulk_order_update', "Bulk action: $action on " . count($order_ids) . " orders");

            $conn->commit();
            $message_type = 'success';

        } catch (PDOException $e) {
            $conn->rollBack();
            $message = "Error performing bulk action: " . $e->getMessage();
            $message_type = 'danger';
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Orders - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
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

        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 0.375rem;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-pending {
            background-color: #fff3cd;
            color: #856404;
        }

        .status-processing {
            background-color: #cce7ff;
            color: #0066cc;
        }

        .status-received {
            background-color: #d1edff;
            color: #0c5460;
        }

        .status-cancelled {
            background-color: #f8d7da;
            color: #721c24;
        }

        .filters-section {
            background-color: #f8f9fa;
            border-radius: 0.5rem;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .stats-card {
            background: linear-gradient(135deg, var(--primary-color), #8b5cf6);
            color: white;
            border-radius: 0.5rem;
            padding: 1.5rem;
            margin-bottom: 1rem;
        }

        .table-hover tbody tr:hover {
            background-color: rgba(99, 102, 241, 0.05);
        }

        .bulk-actions {
            background-color: #f8f9fa;
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .order-number {
            font-weight: 600;
            color: var(--primary-color);
        }

        .order-number:hover {
            text-decoration: underline;
        }

        @media (max-width: 768px) {
            .filters-section .row > div {
                margin-bottom: 1rem;
            }
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
                    <h1><?php echo $filter === 'receivable' ? 'Receive Orders' : 'Purchase Orders'; ?></h1>
                    <p class="header-subtitle">
                        <?php if ($filter === 'receivable'): ?>
                            All orders available for receiving (no status filtering applied)
                        <?php elseif (empty($_GET['search']) && empty($_GET['status']) && empty($_GET['supplier']) && empty($_GET['date_from']) && empty($_GET['date_to'])): ?>
                            Showing active orders (received orders excluded)
                        <?php else: ?>
                            View and manage all purchase orders
                        <?php endif; ?>
                    </p>
                </div>
                <div class="header-actions">
                    <?php if ($filter !== 'receivable'): ?>
                    <a href="create_order.php" class="btn btn-primary">
                        <i class="bi bi-plus-circle me-2"></i>Create New Order
                    </a>
                    <?php endif; ?>
                    <?php if ($filter === 'receivable'): ?>
                    <a href="view_orders.php" class="btn btn-outline-secondary">
                        <i class="bi bi-list me-2"></i>All Orders
                    </a>
                    <?php endif; ?>
                    <a href="inventory.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-2"></i>Back to Inventory
                    </a>
                </div>
            </div>
        </header>

        <!-- Content -->
        <main class="content">
            <?php if ($filter === 'receivable'): ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <i class="bi bi-info-circle me-2"></i>
                <strong>Receive Orders:</strong> This page shows ALL orders available for receiving. No status filtering is applied - you can receive any order regardless of its current status. Click the "Receive Order" button for any order to process the receiving.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php elseif (empty($_GET['search']) && empty($_GET['status']) && empty($_GET['supplier']) && empty($_GET['date_from']) && empty($_GET['date_to'])): ?>
            <div class="alert alert-primary alert-dismissible fade show" role="alert">
                <i class="bi bi-info-circle me-2"></i>
                <strong>Showing Active Orders:</strong> This page displays active orders (pending, sent, waiting for delivery, cancelled) but excludes received orders. Use the "Received" filter above to view completed orders.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php elseif (!empty($_GET['status']) && $_GET['status'] === 'received'): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-file-earmark-pdf me-2"></i>
                <strong>Received Orders:</strong> These orders have been received and their invoices are available for printing. Use the print buttons in the Actions column or select multiple orders for bulk printing.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                <i class="bi bi-<?php echo $message_type === 'success' ? 'check-circle' : ($message_type === 'danger' ? 'exclamation-triangle' : 'info-circle'); ?> me-2"></i>
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="stats-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h3 class="mb-0"><?php echo $stats['total']['count'] ?? 0; ?></h3>
                                <small>Total Orders</small>
                            </div>
                            <i class="bi bi-receipt fs-1 opacity-75"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h3 class="mb-0"><?php echo $stats['pending']['count'] ?? 0; ?></h3>
                                <small>Pending Orders</small>
                            </div>
                            <i class="bi bi-clock fs-1 opacity-75"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h3 class="mb-0"><?php echo ($stats['sent']['count'] ?? 0) + ($stats['waiting_for_delivery']['count'] ?? 0); ?></h3>
                                <small>In Transit</small>
                            </div>
                            <i class="bi bi-truck fs-1 opacity-75"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h3 class="mb-0"><?php echo $stats['received']['count'] ?? 0; ?></h3>
                                <small>Received</small>
                            </div>
                            <i class="bi bi-check-circle fs-1 opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <?php if ($filter !== 'receivable'): ?>
            <div class="filters-section">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label for="search" class="form-label">Search Orders</label>
                        <input type="text" class="form-control" id="search" name="search"
                               value="<?php echo htmlspecialchars($search); ?>"
                               placeholder="Order #, Supplier, User...">
                    </div>
                    <div class="col-md-2">
                        <label for="status" class="form-label">Status</label>
                        <select class="form-select" id="status" name="status">
                            <option value="">All Status</option>
                            <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="sent" <?php echo $status_filter === 'sent' ? 'selected' : ''; ?>>Sent</option>
                            <option value="waiting_for_delivery" <?php echo $status_filter === 'waiting_for_delivery' ? 'selected' : ''; ?>>Waiting for Delivery</option>
                            <option value="received" <?php echo $status_filter === 'received' ? 'selected' : ''; ?>>Received</option>
                            <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="supplier" class="form-label">Supplier</label>
                        <select class="form-select" id="supplier" name="supplier">
                            <option value="">All Suppliers</option>
                            <?php foreach ($suppliers as $supplier): ?>
                            <option value="<?php echo $supplier['id']; ?>"
                                    <?php echo $supplier_filter == $supplier['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($supplier['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="date_from" class="form-label">Date From</label>
                        <input type="date" class="form-control" id="date_from" name="date_from"
                               value="<?php echo htmlspecialchars($date_from); ?>">
                    </div>
                    <div class="col-md-2">
                        <label for="date_to" class="form-label">Date To</label>
                        <input type="date" class="form-control" id="date_to" name="date_to"
                               value="<?php echo htmlspecialchars($date_to); ?>">
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="bi bi-search me-2"></i>Search
                        </button>
                        <a href="view_orders.php" class="btn btn-outline-secondary">
                            <i class="bi bi-x-circle me-2"></i>Clear Filters
                        </a>
                        <a href="view_orders.php?status=received" class="btn btn-outline-success">
                            <i class="bi bi-check-circle me-2"></i>Show Received Orders
                        </a>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <!-- Bulk Actions -->
            <form method="POST" id="bulkForm">
                <div class="bulk-actions">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="selectAll">
                                <label class="form-check-label fw-semibold" for="selectAll">
                                    Select All Orders
                                </label>
                            </div>
                        </div>
                        <div class="col-md-4 text-end">
                            <select name="bulk_action" class="form-select d-inline-block w-auto me-2" required>
                                <option value="">Choose Action</option>
                                <option value="mark_sent">Mark as Sent</option>
                                <option value="mark_waiting_delivery">Mark as Waiting for Delivery</option>
                                <option value="mark_received">Mark as Received</option>
                                <option value="cancel_orders">Cancel Orders</option>
                                <option value="print_invoices">Print Invoices</option>
                            </select>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-circle me-2"></i>Apply
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Orders Table -->
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th width="40">
                                    <input class="form-check-input" type="checkbox" id="selectAllHeader">
                                </th>
                                <th>Order Number</th>
                                <th>Supplier</th>
                                <th>Order Date</th>
                                <th>Expected Date</th>
                                <th>Status</th>
                                <th class="text-end">Items</th>
                                <th class="text-end">Total Amount</th>
                                <th class="text-end">Received</th>
                                <th>Created By</th>
                                <th width="120">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($orders)): ?>
                            <tr>
                                <td colspan="11" class="text-center py-5">
                                    <i class="bi bi-receipt-x fs-1 text-muted"></i>
                                    <p class="mt-2 text-muted">No orders found matching your criteria.</p>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($orders as $order): ?>
                                <tr>
                                    <td>
                                        <input class="form-check-input order-checkbox" type="checkbox"
                                               name="order_ids[]" value="<?php echo $order['id']; ?>">
                                    </td>
                                    <td>
                                        <?php if ($order['status'] === 'received'): ?>
                                        <a href="view_invoice.php?id=<?php echo htmlspecialchars($order['invoice_number'] ?? $order['order_number']); ?>"
                                           class="order-number">
                                            <?php echo htmlspecialchars($order['invoice_number'] ?? $order['order_number']); ?>
                                        </a>
                                        <?php else: ?>
                                        <a href="view_order.php?id=<?php echo htmlspecialchars($order['order_number']); ?>"
                                           class="order-number">
                                            <?php echo htmlspecialchars($order['order_number']); ?>
                                        </a>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($order['supplier_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo date('M j, Y', strtotime($order['order_date'])); ?></td>
                                    <td>
                                        <?php if ($order['expected_date']): ?>
                                            <?php echo date('M j, Y', strtotime($order['expected_date'])); ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $order['status']; ?>">
                                            <?php echo ucfirst($order['status']); ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <?php echo $order['total_received_items']; ?> / <?php echo $order['total_items']; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?>
                                        <?php echo number_format($order['total_amount'], 2); ?>
                                    </td>
                                    <td class="text-end">
                                        <?php
                                        $received_percentage = $order['total_items'] > 0 ?
                                            round(($order['total_received_items'] / $order['total_items']) * 100, 1) : 0;
                                        echo $received_percentage . '%';
                                        ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($order['created_by_name'] ?? 'System'); ?></td>
                                    <td>
                                        <?php if ($filter === 'receivable'): ?>
                                            <?php if ($order['status'] === 'received'): ?>
                                                <!-- Order already received - show invoice actions -->
                                                <div class="btn-group" role="group">
                                                    <a href="view_invoice.php?id=<?php echo htmlspecialchars($order['invoice_number'] ?? $order['order_number']); ?>"
                                                       class="btn btn-sm btn-primary" title="View Invoice">
                                                        <i class="bi bi-eye me-1"></i>View Invoice
                                                    </a>
                                                    <a href="invoice_print.php?id=<?php echo urlencode($order['order_number']); ?>"
                                                       class="btn btn-sm btn-info" title="Print Invoice" target="_blank">
                                                        <i class="bi bi-printer"></i>
                                                    </a>
                                                    <a href="generate_pdf.php?id=<?php echo urlencode($order['order_number']); ?>&download=1"
                                                       class="btn btn-sm btn-success" title="Download Invoice" target="_blank">
                                                        <i class="bi bi-file-earmark-pdf"></i>
                                                    </a>
                                                </div>
                                            <?php else: ?>
                                                <!-- Order not yet received - show receive button -->
                                                <a href="receive_order.php?id=<?php echo urlencode($order['order_number']); ?>"
                                                   class="btn btn-sm btn-success" title="Receive Order">
                                                    <i class="bi bi-box-arrow-in-down me-1"></i>Receive Order
                                                </a>
                                            <?php endif; ?>
                                        <?php else: ?>
                                        <div class="btn-group" role="group">
                                            <?php if ($order['status'] === 'received'): ?>
                                            <a href="view_invoice.php?id=<?php echo htmlspecialchars($order['invoice_number'] ?? $order['order_number']); ?>"
                                               class="btn btn-sm btn-outline-primary" title="View Invoice">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <?php else: ?>
                                            <a href="view_order.php?id=<?php echo htmlspecialchars($order['order_number']); ?>"
                                               class="btn btn-sm btn-outline-primary" title="View Order">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <?php endif; ?>
                                            <?php if ($order['status'] === 'pending'): ?>
                                            <button type="button" class="btn btn-sm btn-outline-success"
                                                    onclick="quickUpdateStatus(<?php echo $order['id']; ?>, 'sent')"
                                                    title="Mark as Sent">
                                                <i class="bi bi-send"></i>
                                            </button>
                                            <?php endif; ?>
                                            <?php if ($order['status'] === 'received'): ?>
                                            <a href="generate_pdf.php?id=<?php echo urlencode($order['order_number']); ?>&download=1"
                                               class="btn btn-sm btn-success" title="Download Invoice" target="_blank">
                                                <i class="bi bi-file-earmark-pdf"></i>
                                            </a>
                                            <button type="button" class="btn btn-sm btn-primary" title="Print Invoice"
                                                    onclick="printInvoice('<?php echo urlencode($order['order_number']); ?>')">
                                                <i class="bi bi-printer"></i>
                                            </button>
                                            <a href="order_print_preview.php?id=<?php echo urlencode($order['order_number']); ?>"
                                               class="btn btn-sm btn-outline-secondary" title="Print Preview" target="_blank">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <?php endif; ?>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </form>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <nav aria-label="Orders pagination" class="mt-4">
                <ul class="pagination justify-content-center">
                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                            <i class="bi bi-chevron-left"></i>
                        </a>
                    </li>

                    <?php
                    $start_page = max(1, $page - 2);
                    $end_page = min($total_pages, $page + 2);

                    if ($start_page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>">1</a>
                        </li>
                        <?php if ($start_page > 2): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                            <?php echo $i; ?>
                        </a>
                    </li>
                    <?php endfor; ?>

                    <?php if ($end_page < $total_pages): ?>
                        <?php if ($end_page < $total_pages - 1): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                        <?php endif; ?>
                        <li class="page-item">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>">
                                <?php echo $total_pages; ?>
                            </a>
                        </li>
                    <?php endif; ?>

                    <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    </li>
                </ul>
            </nav>
            <?php endif; ?>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Select All functionality
        document.getElementById('selectAll').addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.order-checkbox');
            checkboxes.forEach(cb => cb.checked = this.checked);
        });

        document.getElementById('selectAllHeader').addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.order-checkbox');
            checkboxes.forEach(cb => cb.checked = this.checked);
            document.getElementById('selectAll').checked = this.checked;
        });

        // Quick status update
        function quickUpdateStatus(orderId, newStatus) {
            let statusText = '';
            switch(newStatus) {
                case 'sent': statusText = 'Sent'; break;
                case 'waiting_for_delivery': statusText = 'Waiting for Delivery'; break;
                case 'received': statusText = 'Received'; break;
                case 'cancelled': statusText = 'Cancelled'; break;
                default: statusText = newStatus;
            }

            if (confirm(`Are you sure you want to mark this order as "${statusText}"?`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="bulk_action" value="mark_${newStatus.replace('_', '')}">
                    <input type="hidden" name="order_ids[]" value="${orderId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Print invoice functionality
        window.printInvoice = function(orderId) {
            // Open PDF in new window for printing
            const printWindow = window.open('generate_pdf.php?id=' + encodeURIComponent(orderId) + '&print=1', '_blank');
            if (printWindow) {
                // Wait for PDF to load then trigger print
                printWindow.onload = function() {
                    setTimeout(function() {
                        printWindow.print();
                    }, 1000);
                };
            } else {
                alert('Please allow popups for this website to print invoices.');
            }
        };

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
