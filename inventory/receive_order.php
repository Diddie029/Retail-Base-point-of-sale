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

// Check if user has permission to manage inventory
if (!hasPermission('manage_inventory', $permissions)) {
    header("Location: ../dashboard/dashboard.php?error=permission_denied");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Function to get orders ready for reception
function getOrdersForReception($conn, $filters = []) {
    try {
        $where = "io.status IN ('sent', 'waiting_for_delivery')";

        if (!empty($filters['supplier_id'])) {
            $where .= " AND io.supplier_id = :supplier_id";
        }

        if (!empty($filters['date_from'])) {
            $where .= " AND DATE(io.order_date) >= :date_from";
        }

        if (!empty($filters['date_to'])) {
            $where .= " AND DATE(io.order_date) <= :date_to";
        }

        if (!empty($filters['search'])) {
            $where .= " AND (io.order_number LIKE :search OR s.name LIKE :search)";
        }

        $query = "
            SELECT io.*,
                   s.name as supplier_name, s.contact_person, s.phone, s.email,
                   COUNT(ioi.id) as total_items,
                   SUM(ioi.quantity) as total_ordered_quantity,
                   SUM(ioi.received_quantity) as total_received_quantity,
                   (SELECT COUNT(*) FROM inventory_invoice_attachments iia WHERE iia.order_id = io.id) as attachment_count
            FROM inventory_orders io
            LEFT JOIN suppliers s ON io.supplier_id = s.id
            LEFT JOIN inventory_order_items ioi ON io.id = ioi.order_id
            WHERE $where
            GROUP BY io.id, s.name, s.contact_person, s.phone, s.email
            ORDER BY io.order_date DESC, io.id DESC
        ";

        $stmt = $conn->prepare($query);

        if (!empty($filters['supplier_id'])) {
            $stmt->bindParam(':supplier_id', $filters['supplier_id']);
        }
        if (!empty($filters['date_from'])) {
            $stmt->bindParam(':date_from', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $stmt->bindParam(':date_to', $filters['date_to']);
        }
        if (!empty($filters['search'])) {
            $stmt->bindValue(':search', '%' . $filters['search'] . '%');
        }

        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        error_log("Error getting orders for reception: " . $e->getMessage());
        return [];
    }
}

// Function to get order details for reception
function getOrderForReception($conn, $order_id) {
    try {
        // Get order header
        $stmt = $conn->prepare("
            SELECT io.*,
                   s.name as supplier_name, s.contact_person, s.phone, s.email, s.address,
                   u.username as created_by_name
            FROM inventory_orders io
            LEFT JOIN suppliers s ON io.supplier_id = s.id
            LEFT JOIN users u ON io.user_id = u.id
            WHERE io.id = :order_id
        ");
        $stmt->bindParam(':order_id', $order_id);
        $stmt->execute();
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) return null;

        // Get order items with product details
        $stmt = $conn->prepare("
            SELECT ioi.*,
                   p.name as product_name, p.sku, p.description, p.image_url,
                   c.name as category_name, b.name as brand_name,
                   p.quantity as current_stock
            FROM inventory_order_items ioi
            LEFT JOIN products p ON ioi.product_id = p.id
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN brands b ON p.brand_id = b.id
            WHERE ioi.order_id = :order_id
            ORDER BY ioi.id ASC
        ");
        $stmt->bindParam(':order_id', $order_id);
        $stmt->execute();
        $order['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get invoice attachments
        $stmt = $conn->prepare("
            SELECT * FROM inventory_invoice_attachments
            WHERE order_id = :order_id
            ORDER BY created_at DESC
        ");
        $stmt->bindParam(':order_id', $order_id);
        $stmt->execute();
        $order['attachments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $order;

    } catch (PDOException $e) {
        error_log("Error getting order for reception: " . $e->getMessage());
        return null;
    }
}

// Function to generate order invoice number using settings
function generateOrderInvoiceNumber($conn, $settings) {
    try {
        $prefix = $settings['invoice_prefix'] ?? 'INV';
        $length = intval($settings['invoice_length'] ?? 6);
        $separator = $settings['invoice_separator'] ?? '-';
        $format = $settings['invoice_format'] ?? 'prefix-date-number';

        // Get the next sequential number
        $stmt = $conn->query("
            SELECT MAX(CAST(SUBSTRING(invoice_number, LENGTH('$prefix$separator') + 1) AS UNSIGNED)) as max_num
            FROM inventory_orders
            WHERE invoice_number LIKE '$prefix$separator%'
        ");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $nextNum = ($row['max_num'] ?? 0) + 1;
        $paddedNum = str_pad($nextNum, $length, '0', STR_PAD_LEFT);

        $currentDate = date('Ymd');

        switch ($format) {
            case 'prefix-date-number':
                return $prefix . $separator . $currentDate . $separator . $paddedNum;
            case 'prefix-number':
                return $prefix . $separator . $paddedNum;
            case 'date-prefix-number':
                return $currentDate . $separator . $prefix . $separator . $paddedNum;
            case 'number-only':
                return $paddedNum;
            default:
                return $prefix . $separator . $currentDate . $separator . $paddedNum;
        }

    } catch (PDOException $e) {
        error_log("Error generating order invoice number: " . $e->getMessage());
        return 'INV-' . date('YmdHis');
    }
}

// Handle success message from URL (after redirect)
$success = $_GET['success'] ?? '';

// Handle form submissions
$errors = [];
$order = null;

// Get filters from GET parameters
$filters = [
    'supplier_id' => $_GET['supplier_id'] ?? '',
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? '',
    'search' => $_GET['search'] ?? ''
];

// Get order ID from URL if viewing specific order
$order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : null;
if ($order_id) {
    $order = getOrderForReception($conn, $order_id);
}

// Get suppliers for filter
$suppliers = [];
$stmt = $conn->query("SELECT id, name FROM suppliers WHERE is_active = 1 ORDER BY name ASC");
$suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get orders list if not viewing specific order
$orders = [];
if (!$order_id) {
    $orders = getOrdersForReception($conn, $filters);
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'receive_order':
            // Check if order is already received to prevent double processing
            $check_order_id = intval($_POST['order_id']);
            $stmt = $conn->prepare("SELECT status FROM inventory_orders WHERE id = :order_id");
            $stmt->bindParam(':order_id', $check_order_id);
            $stmt->execute();
            $current_status = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($current_status && $current_status['status'] === 'received') {
                $errors[] = 'This order has already been received and cannot be processed again.';
                break;
            }
            try {
                $conn->beginTransaction();

                $order_id = intval($_POST['order_id']);
                $received_items = $_POST['received_items'] ?? [];
                $supplier_invoice_number = sanitizeProductInput($_POST['supplier_invoice_number'] ?? '');
                $invoice_notes = sanitizeProductInput($_POST['invoice_notes'] ?? '', 'text');

                // Validate order exists and is in correct status
                $stmt = $conn->prepare("SELECT * FROM inventory_orders WHERE id = :order_id AND status IN ('sent', 'waiting_for_delivery')");
                $stmt->bindParam(':order_id', $order_id);
                $stmt->execute();
                $order_header = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$order_header) {
                    throw new Exception('Order not found or not ready for reception');
                }

                // Process received items
                $total_received_quantity = 0;
                foreach ($received_items as $item_id => $item_data) {
                    $received_quantity = intval($item_data['quantity'] ?? 0);

                    if ($received_quantity >= 0) {
                        // Update received quantity
                        $stmt = $conn->prepare("
                            UPDATE inventory_order_items
                            SET received_quantity = :received_quantity,
                                updated_at = NOW()
                            WHERE id = :item_id AND order_id = :order_id
                        ");
                        $stmt->execute([
                            ':received_quantity' => $received_quantity,
                            ':item_id' => $item_id,
                            ':order_id' => $order_id
                        ]);

                        // Update product stock if quantity was received
                        if ($received_quantity > 0) {
                            $stmt = $conn->prepare("
                                UPDATE products p
                                INNER JOIN inventory_order_items ioi ON p.id = ioi.product_id
                                SET p.quantity = p.quantity + :received_quantity,
                                    p.updated_at = NOW()
                                WHERE ioi.id = :item_id
                            ");
                            $stmt->bindParam(':received_quantity', $received_quantity);
                            $stmt->bindParam(':item_id', $item_id);
                            $stmt->execute();
                        }

                        $total_received_quantity += $received_quantity;
                    }
                }

                // Generate invoice number and update order
                $invoice_number = generateOrderInvoiceNumber($conn, $settings);

                $stmt = $conn->prepare("
                    UPDATE inventory_orders
                    SET status = 'received',
                        invoice_number = :invoice_number,
                        supplier_invoice_number = :supplier_invoice_number,
                        invoice_notes = :invoice_notes,
                        received_date = CURDATE(),
                        received_by = :received_by,
                        updated_at = NOW()
                    WHERE id = :order_id
                ");
                $stmt->execute([
                    ':invoice_number' => $invoice_number,
                    ':supplier_invoice_number' => $supplier_invoice_number,
                    ':invoice_notes' => $invoice_notes,
                    ':received_by' => $user_id,
                    ':order_id' => $order_id
                ]);

                // Log the activity
                logActivity($conn, $user_id, 'order_received',
                    "Received order {$order_header['order_number']} with invoice {$invoice_number}");

                $conn->commit();

                $success = "Order {$order_header['order_number']} has been received successfully! Invoice {$invoice_number} generated.";

                // Redirect to prevent form resubmission on page reload
                header("Location: receive_order.php?order_id={$order_id}&success=" . urlencode($success));
                exit();

            } catch (Exception $e) {
                $conn->rollBack();
                $errors[] = 'Error receiving order: ' . $e->getMessage();
            }
            break;

        case 'upload_invoice_attachment':
            try {
                $order_id = intval($_POST['order_id']);
                $attachment_description = sanitizeProductInput($_POST['attachment_description'] ?? '');

                if (isset($_FILES['invoice_attachment']) && $_FILES['invoice_attachment']['error'] === UPLOAD_ERR_OK) {
                    $upload_dir = __DIR__ . '/../storage/invoice_attachments/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }

                    $file_name = uniqid() . '_' . basename($_FILES['invoice_attachment']['name']);
                    $file_path = $upload_dir . $file_name;
                    $original_name = $_FILES['invoice_attachment']['name'];

                    if (move_uploaded_file($_FILES['invoice_attachment']['tmp_name'], $file_path)) {
                        // Insert attachment record
                        $stmt = $conn->prepare("
                            INSERT INTO inventory_invoice_attachments (
                                order_id, file_name, original_name, file_path,
                                file_size, file_type, description, uploaded_by
                            ) VALUES (
                                :order_id, :file_name, :original_name, :file_path,
                                :file_size, :file_type, :description, :uploaded_by
                            )
                        ");
                        $stmt->execute([
                            ':order_id' => $order_id,
                            ':file_name' => $file_name,
                            ':original_name' => $original_name,
                            ':file_path' => $file_path,
                            ':file_size' => $_FILES['invoice_attachment']['size'],
                            ':file_type' => $_FILES['invoice_attachment']['type'],
                            ':description' => $attachment_description,
                            ':uploaded_by' => $user_id
                        ]);

                        $success = 'Invoice attachment uploaded successfully!';
                        
                        // Redirect to prevent form resubmission on page reload
                        header("Location: receive_order.php?order_id={$order_id}&success=" . urlencode($success));
                        exit();
                    } else {
                        $errors[] = 'Failed to upload attachment';
                    }
                } else {
                    $errors[] = 'No file uploaded or upload error';
                }
            } catch (PDOException $e) {
                $errors[] = 'Database error: ' . $e->getMessage();
            }
            break;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receive Order - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <style>
        .reception-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border: 1px solid #e2e8f0;
        }

        .status-badge {
            padding: 0.375rem 0.75rem;
            border-radius: 0.375rem;
            font-size: 0.875rem;
            font-weight: 600;
        }

        .status-sent { background-color: #cce7ff; color: #0066cc; }
        .status-waiting_for_delivery { background-color: #fff3cd; color: #856404; }
        .status-received { background-color: #d1edff; color: #0c5460; }

        .product-row {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            margin-bottom: 0.5rem;
            background: #f8fafc;
        }

        .product-info { flex: 1; }
        .quantity-input { width: 100px; }


        .received-badge {
            background: #10b981;
            color: white;
        }

        .shortage-badge {
            background: #ef4444;
            color: white;
        }


        .progress-summary {
            background: #f0f9ff;
            border: 1px solid #0ea5e9;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .invoice-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }

        .filter-section {
            background: #f8fafc;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .attachment-list {
            max-height: 300px;
            overflow-y: auto;
        }

        .attachment-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.5rem;
            border-bottom: 1px solid #dee2e6;
        }

        .attachment-item:last-child {
            border-bottom: none;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../include/navmenu.php'; ?>

    <div class="main-content">
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1><?php echo $order_id ? ($order['status'] === 'received' ? 'Order Received' : 'Receive Order') : 'Order Reception'; ?></h1>
                    <p class="header-subtitle">
                        <?php
                        if ($order_id) {
                            if ($order['status'] === 'received') {
                                echo 'Order has been received and invoice generated';
                            } else {
                                echo 'Process order reception and generate invoice';
                            }
                        } else {
                            echo 'Select orders ready for reception';
                        }
                        ?>
                    </p>
                </div>
                <div class="header-actions">
                    <?php if ($order_id): ?>
                        <a href="receive_order.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left me-2"></i>Back to Orders
                        </a>
                        <a href="bulk_receive_orders.php" class="btn btn-outline-primary">
                            <i class="bi bi-stack me-2"></i>Bulk Reception
                        </a>
                        <a href="view_order.php?id=<?php echo $order_id; ?>" class="btn btn-outline-info">
                            <i class="bi bi-eye me-2"></i>View Order Details
                        </a>
                    <?php else: ?>
                        <a href="bulk_receive_orders.php" class="btn btn-primary">
                            <i class="bi bi-stack me-2"></i>Bulk Reception
                        </a>
                        <a href="inventory.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left me-2"></i>Back to Inventory
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </header>

        <main class="content">
            <!-- Success/Error Messages -->
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <?php echo htmlspecialchars(implode('<br>', $errors)); ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="bi bi-check-circle me-2"></i>
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <?php if ($order_id && $order): ?>

                <!-- Order Status Alert -->
                <?php if ($order['status'] === 'received'): ?>
                    <div class="alert alert-success border-success">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-check-circle-fill fs-4 me-3"></i>
                            <div class="flex-grow-1">
                                <h5 class="alert-heading mb-1">Order Successfully Received!</h5>
                                <p class="mb-2">
                                    This order was received on <strong><?php echo date('M j, Y', strtotime($order['received_date'] ?? $order['updated_at'])); ?></strong>
                                    <?php if (!empty($order['invoice_number'])): ?>
                                        and has invoice number <strong><?php echo htmlspecialchars($order['invoice_number']); ?></strong>.
                                    <?php endif; ?>
                                </p>
                                <div class="d-flex gap-2">
                                    <a href="generate_order_invoice.php?order_id=<?php echo $order_id; ?>&download=1" class="btn btn-success btn-sm">
                                        <i class="bi bi-download me-1"></i>Download Invoice
                                    </a>
                                    <a href="generate_order_invoice.php?order_id=<?php echo $order_id; ?>" target="_blank" class="btn btn-outline-success btn-sm">
                                        <i class="bi bi-eye me-1"></i>Preview Invoice
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Order Reception Interface -->
                <div class="row">
                    <div class="col-lg-8">
                        <!-- Order Header -->
                        <div class="reception-card">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <h4 class="mb-1">Order <?php echo htmlspecialchars($order['order_number']); ?></h4>
                                    <p class="text-muted mb-2">Supplier: <?php echo htmlspecialchars($order['supplier_name']); ?></p>
                                    <p class="text-muted small">Order Date: <?php echo date('M j, Y', strtotime($order['order_date'])); ?></p>
                                    <?php if ($order['status'] === 'received'): ?>
                                        <div class="mb-2">
                                            <p class="text-success small mb-1">
                                                <i class="bi bi-check-circle-fill me-1"></i>
                                                Order was received on <?php echo date('M j, Y', strtotime($order['received_date'] ?? $order['updated_at'])); ?>
                                            </p>
                                            <?php if (!empty($order['invoice_number'])): ?>
                                                <p class="text-primary fw-bold mb-0 small">
                                                    <i class="bi bi-receipt me-1"></i>
                                                    Invoice: <?php echo htmlspecialchars($order['invoice_number']); ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="text-end">
                                    <span class="status-badge status-<?php echo $order['status']; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $order['status'])); ?>
                                    </span>
                                    <?php if ($order['status'] === 'received' && !empty($order['invoice_number'])): ?>
                                        <div class="mt-2">
                                            <span class="badge bg-success fs-6">
                                                <i class="bi bi-receipt me-1"></i>
                                                Invoice Generated
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Progress Summary -->
                        <?php
                        $total_items = count($order['items']);
                        $received_items = 0;
                        $total_ordered = 0;
                        $total_received = 0;

                        foreach ($order['items'] as $item) {
                            if ($item['received_quantity'] > 0) $received_items++;
                            $total_ordered += $item['quantity'];
                            $total_received += $item['received_quantity'];
                        }

                        $progress_percentage = $total_items > 0 ? round(($received_items / $total_items) * 100) : 0;
                        ?>
                        <div class="progress-summary">
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="text-center">
                                        <div class="h5 text-primary"><?php echo $received_items; ?>/<?php echo $total_items; ?></div>
                                        <small class="text-muted">Items Processed</small>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="text-center">
                                        <div class="h5 text-success"><?php echo $total_received; ?>/<?php echo $total_ordered; ?></div>
                                        <small class="text-muted">Quantity Received</small>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="text-center">
                                        <div class="h5 text-info"><?php echo $progress_percentage; ?>%</div>
                                        <small class="text-muted">Complete</small>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="text-center">
                                        <div class="progress" style="height: 20px;">
                                            <div class="progress-bar bg-success" style="width: <?php echo $progress_percentage; ?>%"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Reception Form -->
                        <form method="POST" enctype="multipart/form-data" id="receptionForm"
                              <?php echo ($order['status'] === 'received') ? 'onsubmit="alert(\'This order has already been received and cannot be processed again.\'); return false;"' : ''; ?>>
                            <input type="hidden" name="action" value="receive_order">
                            <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">

                            <!-- Order Items -->
                            <div class="reception-card">
                                <h5 class="mb-3">
                                    <i class="bi bi-boxes me-2"></i>Order Items Reception
                                </h5>

                                <?php foreach ($order['items'] as $item): ?>
                                    <div class="product-row">
                                        <div class="product-info">
                                            <div class="d-flex align-items-center">
                                                <?php if ($item['image_url']): ?>
                                                <img src="<?php echo htmlspecialchars($item['image_url']); ?>"
                                                     alt="<?php echo htmlspecialchars($item['product_name']); ?>"
                                                     style="width: 50px; height: 50px; object-fit: cover; border-radius: 5px; margin-right: 1rem;">
                                                <?php endif; ?>
                                                <div>
                                                    <strong><?php echo htmlspecialchars($item['product_name']); ?></strong>
                                                    <br>
                                                    <small class="text-muted">
                                                        <?php if ($item['sku']): ?>
                                                            <span class="badge bg-primary me-1">SKU: <?php echo htmlspecialchars($item['sku']); ?></span>
                                                        <?php endif; ?>
                                                        <span>Current Stock: <?php echo $item['current_stock']; ?></span>
                                                    </small>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="text-center">
                                            <label class="form-label small">Ordered</label>
                                            <div class="fw-bold"><?php echo $item['quantity']; ?></div>
                                        </div>

                                        <div class="text-center">
                                            <label class="form-label small">Received</label>
                                            <input type="number" class="form-control quantity-input"
                                                   name="received_items[<?php echo $item['id']; ?>][quantity]"
                                                   value="<?php echo $item['received_quantity']; ?>"
                                                   min="0" max="<?php echo $item['quantity']; ?>"
                                                   onchange="updateProgress()"
                                                   <?php echo ($order['status'] === 'received') ? 'disabled readonly' : ''; ?>>
                                        </div>

                                        <div>
                                            <label class="form-label small">Status</label>
                                            <div>
                                                <?php
                                                $received_qty = $item['received_quantity'];
                                                $ordered_qty = $item['quantity'];

                                                if ($received_qty == 0) {
                                                    echo '<span class="badge bg-secondary">Pending</span>';
                                                } elseif ($received_qty == $ordered_qty) {
                                                    echo '<span class="badge received-badge">Received</span>';
                                                } elseif ($received_qty < $ordered_qty) {
                                                    echo '<span class="badge shortage-badge">Partial</span>';
                                                }
                                                ?>
                                            </div>
                                        </div>

                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Invoice Details -->
                            <div class="invoice-section">
                                <h5 class="mb-3">
                                    <i class="bi bi-receipt me-2"></i>Invoice Information
                                </h5>

                                <div class="row">
                                    <div class="col-md-6">
                                        <label class="form-label text-white">Supplier Invoice Number</label>
                                        <input type="text" class="form-control" name="supplier_invoice_number"
                                               value="<?php echo htmlspecialchars($order['supplier_invoice_number'] ?? ''); ?>"
                                               placeholder="Enter supplier's invoice number"
                                               <?php echo ($order['status'] === 'received') ? 'disabled readonly' : ''; ?>>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label text-white">Invoice Notes</label>
                                        <textarea class="form-control" name="invoice_notes" rows="3"
                                                  placeholder="Additional invoice notes..."
                                                  <?php echo ($order['status'] === 'received') ? 'disabled readonly' : ''; ?>><?php echo htmlspecialchars($order['invoice_notes'] ?? ''); ?></textarea>
                                    </div>
                                </div>
                            </div>

                            <!-- Submit Button -->
                            <div class="text-center">
                                <?php if ($order['status'] === 'received'): ?>
                                    <button type="button" class="btn btn-secondary btn-lg" disabled>
                                        <i class="bi bi-check-circle-fill me-2"></i>Order Already Received
                                    </button>
                                <?php else: ?>
                                    <button type="submit" class="btn btn-success btn-lg" id="submitReception">
                                        <i class="bi bi-check-circle me-2"></i>Complete Order Reception
                                    </button>
                                <?php endif; ?>
                            </div>
                        </form>

                    </div>

                    <div class="col-lg-4">
                        <!-- Invoice Attachments -->
                        <div class="reception-card">
                            <h5 class="mb-3">
                                <i class="bi bi-paperclip me-2"></i>Invoice Attachments
                            </h5>

                            <?php if (!empty($order['attachments'])): ?>
                                <div class="attachment-list mb-3">
                                    <?php foreach ($order['attachments'] as $attachment): ?>
                                        <div class="attachment-item">
                                            <div class="d-flex align-items-center">
                                                <i class="bi bi-file-earmark me-2 text-primary"></i>
                                                <div>
                                                    <div class="fw-semibold small"><?php echo htmlspecialchars($attachment['original_name']); ?></div>
                                                    <small class="text-muted">
                                                        <?php echo date('M d, Y H:i', strtotime($attachment['created_at'])); ?>
                                                        (<?php echo formatFileSize($attachment['file_size']); ?>)
                                                    </small>
                                                </div>
                                            </div>
                                            <div class="btn-group btn-group-sm">
                                                <a href="download_attachment.php?id=<?php echo $attachment['id']; ?>"
                                                   class="btn btn-outline-primary btn-sm">
                                                    <i class="bi bi-download"></i>
                                                </a>
                                                <?php if (strpos($attachment['file_type'], 'image/') === 0 || strpos($attachment['file_type'], 'application/pdf') === 0): ?>
                                                <a href="view_attachment.php?id=<?php echo $attachment['id']; ?>"
                                                   class="btn btn-outline-info btn-sm" target="_blank">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <!-- Upload New Attachment -->
                            <?php if ($order['status'] === 'received'): ?>
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle me-2"></i>
                                    Order has been received. File uploads are disabled to maintain record integrity.
                                </div>
                            <?php else: ?>
                                <form method="POST" enctype="multipart/form-data">
                                    <input type="hidden" name="action" value="upload_invoice_attachment">
                                    <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">

                                    <div class="mb-3">
                                        <label class="form-label">Upload Invoice Document</label>
                                        <input type="file" class="form-control" name="invoice_attachment"
                                               accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Description (Optional)</label>
                                        <input type="text" class="form-control" name="attachment_description"
                                               placeholder="e.g., Supplier Invoice, Delivery Note">
                                    </div>

                                    <button type="submit" class="btn btn-outline-primary w-100">
                                        <i class="bi bi-upload me-2"></i>Upload Attachment
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>

                        <!-- Quick Actions -->
                        <div class="reception-card">
                            <h5 class="mb-3">
                                <i class="bi bi-lightning me-2"></i>Quick Actions
                            </h5>

                            <div class="d-grid gap-2">
                                <?php if ($order['status'] === 'received'): ?>
                                    <button type="button" class="btn btn-outline-secondary" disabled>
                                        <i class="bi bi-check-circle-fill me-2"></i>Order Already Received
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" disabled>
                                        <i class="bi bi-lock me-2"></i>Order Completed
                                    </button>
                                <?php else: ?>
                                    <button type="button" class="btn btn-outline-success" onclick="markAllAsReceived()">
                                        <i class="bi bi-check-all me-2"></i>Mark All as Received
                                    </button>
                                    <button type="button" class="btn btn-outline-warning" onclick="resetAllQuantities()">
                                        <i class="bi bi-arrow-counterclockwise me-2"></i>Reset All Quantities
                                    </button>
                                <?php endif; ?>
                                <a href="generate_pdf.php?id=<?php echo $order_id; ?>&download=1&type=order"
                                   class="btn btn-outline-secondary">
                                    <i class="bi bi-file-earmark-pdf me-2"></i>Download Order PDF
                                </a>
                                <?php if ($order['status'] === 'received'): ?>
                                <a href="generate_order_invoice.php?order_id=<?php echo $order_id; ?>&download=1"
                                   class="btn btn-success">
                                    <i class="bi bi-receipt me-2"></i>Download Invoice PDF
                                </a>
                                <a href="generate_order_invoice.php?order_id=<?php echo $order_id; ?>"
                                   class="btn btn-outline-info" target="_blank">
                                    <i class="bi bi-eye me-2"></i>Preview Invoice
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <!-- Orders List -->
                <div class="filter-section">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Supplier</label>
                            <select class="form-select" name="supplier_id">
                                <option value="">All Suppliers</option>
                                <?php foreach ($suppliers as $supplier): ?>
                                    <option value="<?php echo $supplier['id']; ?>"
                                            <?php echo $filters['supplier_id'] == $supplier['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($supplier['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-2">
                            <label class="form-label">From Date</label>
                            <input type="date" class="form-control" name="date_from"
                                   value="<?php echo $filters['date_from']; ?>">
                        </div>

                        <div class="col-md-2">
                            <label class="form-label">To Date</label>
                            <input type="date" class="form-control" name="date_to"
                                   value="<?php echo $filters['date_to']; ?>">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Search</label>
                            <input type="text" class="form-control" name="search"
                                   placeholder="Order # or Supplier"
                                   value="<?php echo htmlspecialchars($filters['search']); ?>">
                        </div>

                        <div class="col-md-2">
                            <label class="form-label">&nbsp;</label>
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-search me-2"></i>Filter
                                </button>
                                <a href="receive_order.php" class="btn btn-outline-secondary">
                                    <i class="bi bi-x-circle"></i>
                                </a>
                            </div>
                        </div>
                    </form>
                </div>

                <div class="reception-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">
                            <i class="bi bi-boxes me-2"></i>Orders Ready for Reception
                        </h5>
                        <span class="badge bg-primary"><?php echo count($orders); ?> orders</span>
                    </div>

                    <?php if (empty($orders)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-box-seam text-muted" style="font-size: 3rem;"></i>
                            <h5 class="text-muted mt-3">No Orders Ready for Reception</h5>
                            <p class="text-muted">All orders have been received or there are no pending deliveries.</p>
                            <a href="place_order.php" class="btn btn-primary">
                                <i class="bi bi-plus-circle me-2"></i>Place New Order
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Order #</th>
                                        <th>Supplier</th>
                                        <th>Order Date</th>
                                        <th>Items</th>
                                        <th>Status</th>
                                        <th>Attachments</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($orders as $order_item): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($order_item['order_number']); ?></strong>
                                            </td>
                                            <td><?php echo htmlspecialchars($order_item['supplier_name']); ?></td>
                                            <td><?php echo date('M j, Y', strtotime($order_item['order_date'])); ?></td>
                                            <td>
                                                <span class="badge bg-info"><?php echo $order_item['total_items']; ?> items</span>
                                                <br>
                                                <small class="text-muted">
                                                    <?php echo $order_item['total_ordered_quantity']; ?> ordered,
                                                    <?php echo $order_item['total_received_quantity']; ?> received
                                                </small>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?php echo $order_item['status']; ?>">
                                                    <?php echo ucfirst(str_replace('_', ' ', $order_item['status'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($order_item['attachment_count'] > 0): ?>
                                                    <span class="badge bg-success">
                                                        <i class="bi bi-paperclip me-1"></i><?php echo $order_item['attachment_count']; ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">None</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($order_item['status'] === 'received'): ?>
                                                    <a href="generate_order_invoice.php?order_id=<?php echo $order_item['id']; ?>&download=1"
                                                       class="btn btn-success btn-sm">
                                                        <i class="bi bi-download me-1"></i>Download Invoice
                                                    </a>
                                                    <a href="generate_order_invoice.php?order_id=<?php echo $order_item['id']; ?>"
                                                       target="_blank" class="btn btn-outline-info btn-sm">
                                                        <i class="bi bi-eye me-1"></i>Preview
                                                    </a>
                                                <?php else: ?>
                                                    <a href="receive_order.php?order_id=<?php echo $order_item['id']; ?>"
                                                       class="btn btn-success btn-sm">
                                                        <i class="bi bi-box-arrow-in-down me-1"></i>Receive
                                                    </a>
                                                    <a href="view_order.php?id=<?php echo $order_item['id']; ?>"
                                                       class="btn btn-outline-info btn-sm">
                                                        <i class="bi bi-eye me-1"></i>View
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
            <?php endif; ?>
        </main>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        // Initialize Select2 for supplier filter
        $(document).ready(function() {
            // Check if Select2 is available before initializing
            if (typeof $.fn.select2 !== 'undefined') {
                const supplierSelect = $('select[name="supplier_id"]');
                if (supplierSelect.length > 0) {
                    supplierSelect.select2({
                        placeholder: 'Select supplier...',
                        allowClear: true,
                        width: '100%'
                    });
                }
            } else {
                console.warn('Select2 not loaded, using regular select');
            }
        });

        function updateProgress() {
            // Update progress indicators based on form inputs
            const quantityInputs = document.querySelectorAll('input[name*="[quantity]"]');
            let totalReceived = 0;
            let totalOrdered = 0;
            let itemsProcessed = 0;

            quantityInputs.forEach(input => {
                const received = parseInt(input.value) || 0;
                const ordered = parseInt(input.getAttribute('max')) || 0;

                totalReceived += received;
                totalOrdered += ordered;

                if (received > 0) itemsProcessed++;
            });

            // Update progress display if elements exist
            const progressElement = document.querySelector('.progress-summary');
            if (progressElement) {
                const percentage = totalOrdered > 0 ? Math.round((totalReceived / totalOrdered) * 100) : 0;
                console.log(`Progress: ${itemsProcessed} items, ${totalReceived}/${totalOrdered} quantity (${percentage}%)`);
            }
        }

        function markAllAsReceived() {
            // Check if order is already received
            <?php if ($order['status'] === 'received'): ?>
                alert('This order has already been received and cannot be modified.');
                return;
            <?php endif; ?>

            if (confirm('Mark all items as fully received? This will set all quantities to their ordered amounts.')) {
                const quantityInputs = document.querySelectorAll('input[name*="[quantity]"]');
                quantityInputs.forEach(input => {
                    const maxQty = parseInt(input.getAttribute('max')) || 0;
                    input.value = maxQty;
                });
                updateProgress();
            }
        }

        function resetAllQuantities() {
            // Check if order is already received
            <?php if ($order['status'] === 'received'): ?>
                alert('This order has already been received and cannot be modified.');
                return;
            <?php endif; ?>

            if (confirm('Reset all quantities to zero?')) {
                const quantityInputs = document.querySelectorAll('input[name*="[quantity]"]');
                quantityInputs.forEach(input => {
                    input.value = 0;
                });
                updateProgress();
            }
        }

        // Auto-update progress on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateProgress();

            // Add change listeners to quantity inputs (only if not disabled)
            document.querySelectorAll('input[name*="[quantity]"]:not([disabled])').forEach(input => {
                input.addEventListener('change', updateProgress);
            });
        });

        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);

        // Prevent back button from resubmitting form
        window.addEventListener('pageshow', function(event) {
            if (event.persisted) {
                // Page was loaded from cache (back/forward button)
                window.location.reload();
            }
        });

        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    </script>
</body>
</html>
