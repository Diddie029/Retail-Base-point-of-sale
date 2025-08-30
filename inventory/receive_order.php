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

// Check if user has permission to manage inventory
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

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Function to generate invoice number
function generateInvoiceNumber($conn, $settings) {
    if (!isset($settings['invoice_auto_generate']) || $settings['invoice_auto_generate'] != '1') {
        return null;
    }

    $prefix = $settings['invoice_prefix'] ?? 'INV';
    $length = intval($settings['invoice_length'] ?? 6);
    $separator = $settings['invoice_separator'] ?? '-';
    $format = $settings['invoice_format'] ?? 'prefix-date-number';

    // Get the next invoice number
    $currentDate = date('Ymd');
    $stmt = $conn->prepare("
        SELECT MAX(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(invoice_number, '-', -1), '-', -1) AS UNSIGNED)) as max_num
        FROM inventory_orders
        WHERE invoice_number LIKE ?
    ");
    $stmt->execute([$prefix . $separator . $currentDate . $separator . '%']);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $nextNumber = ($result['max_num'] ?? 0) + 1;
    $paddedNumber = str_pad($nextNumber, $length, '0', STR_PAD_LEFT);

    switch ($format) {
        case 'prefix-date-number':
            return $prefix . $separator . $currentDate . $separator . $paddedNumber;
        case 'prefix-number':
            return $prefix . $separator . $paddedNumber;
        case 'date-prefix-number':
            return $currentDate . $separator . $prefix . $separator . $paddedNumber;
        case 'number-only':
            return $paddedNumber;
        default:
            return $prefix . $separator . $currentDate . $separator . $paddedNumber;
    }
}

// Function to get order data
function getOrderData($conn, $order_id) {
    try {
        // Get order details
        $stmt = $conn->prepare("
            SELECT io.*,
                   s.name as supplier_name, s.contact_person, s.phone, s.email, s.address,
                   u.username as created_by_name
            FROM inventory_orders io
            LEFT JOIN suppliers s ON io.supplier_id = s.id
            LEFT JOIN users u ON io.user_id = u.id
            WHERE io.id = :order_id OR io.order_number = :order_number
        ");
        $stmt->execute([
            ':order_id' => is_numeric($order_id) ? $order_id : 0,
            ':order_number' => $order_id
        ]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            return null;
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

// Only allow receiving if order is in waiting_for_delivery or sent status
if (!in_array($order['status'], ['waiting_for_delivery', 'sent'])) {
    header("Location: view_order.php?id=" . urlencode($order_id) . "&error=invalid_status");
    exit();
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'generate_invoice_number') {
        header('Content-Type: application/json');

        try {
            $invoice_number = generateInvoiceNumber($conn, $settings);
            echo json_encode([
                'success' => true,
                'invoice_number' => $invoice_number
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
        exit;
    }
}

// Handle form submission
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'receive_order':
            $invoice_number = $_POST['invoice_number'] ?? '';
            $supplier_invoice_number = trim($_POST['supplier_invoice_number'] ?? '');
            $received_date = $_POST['received_date'] ?? date('Y-m-d');
            $notes = $_POST['notes'] ?? '';
            $item_quantities = $_POST['item_quantities'] ?? [];
            $update_quantities = $_POST['update_quantities'] ?? [];

            // Validate supplier invoice number
            $errors = [];
            if (empty($supplier_invoice_number)) {
                $errors[] = "Supplier invoice number is required.";
            }

            if (!empty($errors)) {
                $message = implode('<br>', $errors);
                $message_type = 'danger';
                break;
            }

            try {
                $conn->beginTransaction();

                // Generate our invoice number if not provided and auto-generate is enabled
                if (empty($invoice_number) && isset($settings['invoice_auto_generate']) && $settings['invoice_auto_generate'] == '1') {
                    $invoice_number = generateInvoiceNumber($conn, $settings);
                }

                // Update order status and invoice details
                $stmt = $conn->prepare("
                    UPDATE inventory_orders
                    SET status = 'received',
                        invoice_number = :invoice_number,
                        supplier_invoice_number = :supplier_invoice_number,
                        received_date = :received_date,
                        invoice_notes = :notes,
                        updated_at = NOW()
                    WHERE id = :order_id
                ");

                try {
                    $stmt->execute([
                        ':invoice_number' => $invoice_number,
                        ':supplier_invoice_number' => $supplier_invoice_number,
                        ':received_date' => $received_date,
                        ':notes' => $notes,
                        ':order_id' => $order['id']
                    ]);
                } catch (PDOException $statusError) {
                    // If status update fails due to enum constraint, try to fix it
                    if (strpos($statusError->getMessage(), 'Data truncated') !== false) {
                        error_log("Status enum issue detected, attempting to fix...");
                        try {
                            // Try to update the enum definition
                            $conn->exec("ALTER TABLE inventory_orders MODIFY COLUMN status ENUM('pending', 'sent', 'waiting_for_delivery', 'received', 'cancelled') DEFAULT 'pending'");
                            error_log("Fixed inventory_orders status enum");

                            // Retry the update
                            $stmt->execute([
                                ':invoice_number' => $invoice_number,
                                ':supplier_invoice_number' => $supplier_invoice_number,
                                ':received_date' => $received_date,
                                ':notes' => $notes,
                                ':order_id' => $order['id']
                            ]);
                        } catch (PDOException $fixError) {
                            throw new PDOException("Failed to update order status. Database schema may need manual update: " . $fixError->getMessage());
                        }
                    } else {
                        throw $statusError;
                    }
                }

                // Update ordered quantities and received quantities
                foreach ($item_quantities as $item_id => $quantity) {
                    $received_qty = intval($quantity);

                    // Update ordered quantity if changed
                    if (isset($update_quantities[$item_id])) {
                        $new_ordered_qty = intval($update_quantities[$item_id]);
                        if ($new_ordered_qty >= 0) {
                            $stmt = $conn->prepare("
                                UPDATE inventory_order_items
                                SET quantity = :new_quantity
                                WHERE id = :item_id AND order_id = :order_id
                            ");
                            $stmt->execute([
                                ':new_quantity' => $new_ordered_qty,
                                ':item_id' => $item_id,
                                ':order_id' => $order['id']
                            ]);
                        }
                    }

                    // Update received quantity (allow 0 quantity receives)
                    $stmt = $conn->prepare("
                        UPDATE inventory_order_items
                        SET received_quantity = received_quantity + :received_qty,
                            status = CASE
                                WHEN received_quantity + :received_qty >= quantity THEN 'received'
                                ELSE 'partial'
                            END
                        WHERE id = :item_id AND order_id = :order_id
                    ");
                    $stmt->execute([
                        ':received_qty' => $received_qty,
                        ':item_id' => $item_id,
                        ':order_id' => $order['id']
                    ]);

                    // Update product stock only if quantity received > 0
                    if ($received_qty > 0) {
                        $stmt = $conn->prepare("
                            UPDATE products
                            SET quantity = quantity + :received_qty,
                                updated_at = NOW()
                            WHERE id = (SELECT product_id FROM inventory_order_items WHERE id = :item_id)
                        ");
                        $stmt->execute([
                            ':received_qty' => $received_qty,
                            ':item_id' => $item_id
                        ]);
                    }
                }

                // Log activity
                logActivity($conn, $user_id, 'order_received',
                    "Received order {$order['order_number']} with invoice {$invoice_number}");

                $conn->commit();

                // Refresh order data
                $order = getOrderData($conn, $order_id);
                $message = "Order received successfully! Redirecting to invoice view...";
                $message_type = 'success';

            } catch (PDOException $e) {
                $conn->rollBack();
                $message = "Error receiving order: " . $e->getMessage();
                $message_type = 'danger';
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
    <title><?php echo $order['status'] === 'received' ? 'View Received Order' : 'Receive Order'; ?> <?php echo htmlspecialchars($order['order_number']); ?> - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
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

        .receive-header {
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

        .product-image {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 0.375rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .receive-summary {
            background-color: #f8f9fa;
            border-radius: 0.5rem;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .invoice-preview {
            border: 1px solid #dee2e6;
            border-radius: 0.5rem;
            padding: 1rem;
            background-color: #f8f9fa;
        }

        .invoice-header {
            text-align: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--primary-color);
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
                    <?php if ($order['status'] === 'received'): ?>
                        <h2>View Received Order <?php echo htmlspecialchars($order['order_number']); ?></h2>
                        <p class="header-subtitle small">Order received from <?php echo htmlspecialchars($order['supplier_name']); ?> - Invoice available</p>
                    <?php else: ?>
                        <h2>Receive Order <?php echo htmlspecialchars($order['order_number']); ?></h2>
                        <p class="header-subtitle small">Process order receiving and generate invoice</p>
                    <?php endif; ?>
                </div>
                <div class="header-actions">
                    <?php if ($order['status'] === 'received'): ?>
                    <a href="view_invoice.php?id=<?php echo urlencode($order['invoice_number'] ?? $order_id); ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-2"></i>Back to Invoice
                    </a>
                    <?php else: ?>
                    <a href="view_order.php?id=<?php echo urlencode($order_id); ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-2"></i>Back to Order
                    </a>
                    <?php endif; ?>
                    <a href="inventory.php" class="btn btn-outline-secondary">
                        <i class="bi bi-list me-2"></i>All Orders
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

            <?php if ($message && $message_type === 'success' && $order['status'] === 'received'): ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <i class="bi bi-file-earmark-pdf me-2"></i>
                <strong>Order Received!</strong> Your invoice has been generated and is ready for download.
                <div class="mt-2">
                    <a href="generate_pdf.php?id=<?php echo urlencode($order_id); ?>&download=1" class="btn btn-success btn-sm">
                        <i class="bi bi-download me-1"></i>Download Invoice
                    </a>
                    <a href="view_invoice.php?id=<?php echo urlencode($order['invoice_number'] ?? $order_id); ?>" class="btn btn-primary btn-sm ms-2">
                        <i class="bi bi-eye me-1"></i>View Invoice Now
                    </a>
                    <a href="invoice_print.php?id=<?php echo urlencode($order['invoice_number'] ?? $order_id); ?>" class="btn btn-info btn-sm ms-2" target="_blank">
                        <i class="bi bi-printer me-1"></i>Print Invoice
                    </a>
                    <a href="view_invoices.php" class="btn btn-outline-secondary btn-sm ms-2">
                        <i class="bi bi-list me-1"></i>All Invoices
                    </a>
                    <small class="text-muted ms-2">Auto-redirecting in 2 seconds...</small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Order Header -->
            <div class="receive-header">
                <div class="row">
                    <div class="col-md-6">
                        <h4 class="mb-1">Order <?php echo htmlspecialchars($order['order_number']); ?></h4>
                        <?php if ($order['status'] === 'received'): ?>
                            <p class="mb-0 opacity-75 small">Order received from <?php echo htmlspecialchars($order['supplier_name']); ?></p>
                        <?php else: ?>
                            <p class="mb-0 opacity-75 small">Receiving order from <?php echo htmlspecialchars($order['supplier_name']); ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6 text-end">
                        <?php if ($order['status'] === 'received'): ?>
                            <span class="status-badge bg-success text-white">
                                <i class="bi bi-check-circle me-1"></i>Received
                            </span>
                        <?php elseif ($order['status'] === 'pending'): ?>
                            <span class="status-badge bg-warning text-dark">
                                <i class="bi bi-clock me-1"></i>Pending
                            </span>
                        <?php elseif ($order['status'] === 'in_transit'): ?>
                            <span class="status-badge bg-info text-white">
                                <i class="bi bi-truck me-1"></i>In Transit
                            </span>
                        <?php else: ?>
                            <span class="status-badge bg-secondary text-white">
                                <?php echo ucfirst(str_replace('_', ' ', $order['status'])); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="row">
                <!-- Left Side - Supplier Details -->
                <div class="col-lg-6">
                    <!-- Supplier Information -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-building me-2"></i>Supplier Information</h5>
                        </div>
                        <div class="card-body">
                            <div class="fw-bold mb-2"><?php echo htmlspecialchars($order['supplier_name'] ?? 'N/A'); ?></div>
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
                            <small class="text-muted d-block">Address: <?php echo nl2br(htmlspecialchars($order['address'] ?? '')); ?></small>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Company Details -->
                    <div class="card mt-4">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-briefcase me-2"></i>Company Details</h5>
                        </div>
                        <div class="card-body">
                            <div class="fw-bold mb-2"><?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></div>
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

                    <!-- Invoice Details -->
                    <div class="card mt-4">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-receipt me-2"></i>Invoice Details</h5>
                        </div>
                        <div class="card-body">
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="supplier_invoice_number" class="form-label">Supplier Invoice Number *</label>
                                        <input type="text" class="form-control" id="supplier_invoice_number" name="supplier_invoice_number"
                                               value="<?php echo htmlspecialchars($order['supplier_invoice_number'] ?? ''); ?>"
                                               placeholder="Enter supplier's invoice number" required>
                                        <div class="form-text">Invoice number provided by the supplier (required)</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="received_date" class="form-label">Received Date</label>
                                        <input type="date" class="form-control" id="received_date" name="received_date"
                                               value="<?php echo date('Y-m-d'); ?>" required>
                                        <div class="form-text">Date when items were received</div>
                                    </div>
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="invoice_number" class="form-label">Our Invoice Number</label>
                                        <input type="text" class="form-control" id="invoice_number" name="invoice_number"
                                               value="<?php echo htmlspecialchars($order['invoice_number'] ?? ''); ?>"
                                               placeholder="Auto-generated or enter manually">
                                        <div class="form-text">
                                            <?php if (isset($settings['invoice_auto_generate']) && $settings['invoice_auto_generate'] == '1'): ?>
                                                Leave blank for auto-generation
                                            <?php else: ?>
                                                Our internal invoice number
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="invoice_preview" class="form-label">Invoice Preview</label>
                                        <div class="alert alert-info">
                                            <small>
                                                <strong>Supplier Invoice:</strong> <span id="supplier_invoice_preview">-</span><br>
                                                <strong>Our Invoice:</strong> <span id="our_invoice_preview">-</span>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="notes" class="form-label">Receiving Notes</label>
                                <textarea class="form-control" id="notes" name="notes" rows="3"
                                          placeholder="Add any notes about the receiving process..."><?php echo htmlspecialchars($order['invoice_notes'] ?? ''); ?></textarea>
                                <div class="form-text">Optional notes about the receiving process</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Side - Order Items and Actions -->
                <div class="col-lg-6">
                    <!-- Order Items Table -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>Order Items</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Product</th>
                                            <th>SKU</th>
                                            <th class="text-center">Ordered</th>
                                            <th class="text-center">Update Ordered</th>
                                            <th class="text-center">Previously Received</th>
                                            <th class="text-center">Receiving Now</th>
                                            <th class="text-end">Cost Price</th>
                                            <th class="text-end">Line Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $total_receiving = 0; ?>
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
                                                <input type="number" class="form-control form-control-sm"
                                                       name="update_quantities[<?php echo $item['id']; ?>]"
                                                       value="<?php echo $item['quantity']; ?>" min="0"
                                                       style="width: 80px; display: inline-block;"
                                                       title="Update the ordered quantity">
                                            </td>
                                            <td class="text-center"><?php echo $item['received_quantity']; ?></td>
                                            <td class="text-center">
                                                <?php $remaining = $item['quantity'] - $item['received_quantity']; ?>
                                                <input type="number" class="form-control form-control-sm"
                                                       name="item_quantities[<?php echo $item['id']; ?>]"
                                                       value="0" min="0"
                                                       style="width: 80px; display: inline-block;"
                                                       title="Enter quantity being received now (0 allowed)">
                                            </td>
                                            <td class="text-end"><?php echo formatCurrency($item['cost_price'], $settings['currency_symbol'] ?? 'KES'); ?></td>
                                            <td class="text-end" id="line-total-<?php echo $item['id']; ?>">
                                                <?php echo formatCurrency(0, $settings['currency_symbol'] ?? 'KES'); ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Receive Summary -->
                    <div class="card mt-4">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-calculator me-2"></i>Receive Summary</h5>
                        </div>
                        <div class="card-body">
                            <div class="row mb-2">
                                <div class="col-6">Total Items:</div>
                                <div class="col-6 text-end fw-semibold"><?php echo $order['total_items']; ?></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-6">Previously Received:</div>
                                <div class="col-6 text-end"><?php echo $order['total_received_items']; ?></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-6">Receiving Now:</div>
                                <div class="col-6 text-end fw-bold" id="receiving-now">0</div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-6">Outstanding:</div>
                                <div class="col-6 text-end"><?php echo $order['total_items'] - $order['total_received_items']; ?></div>
                            </div>
                            <hr>
                            <div class="row">
                                <div class="col-6 fw-semibold">Total Value:</div>
                                <div class="col-6 text-end fw-bold" id="total-value">
                                    <?php echo formatCurrency(0, $settings['currency_symbol'] ?? 'KES'); ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="card mt-4">
                        <div class="card-body">
                            <?php if ($order['status'] === 'received'): ?>
                                <!-- Order already received - show invoice actions -->
                                <div class="text-center">
                                    <h5 class="text-success mb-3">
                                        <i class="bi bi-check-circle me-2"></i>Order Already Received
                                    </h5>
                                    <div class="d-grid gap-2">
                                        <a href="view_invoice.php?id=<?php echo urlencode($order['invoice_number'] ?? $order_id); ?>" class="btn btn-primary btn-lg">
                                            <i class="bi bi-eye me-2"></i>View Invoice
                                        </a>
                                        <a href="invoice_print.php?id=<?php echo urlencode($order['invoice_number'] ?? $order_id); ?>" class="btn btn-info btn-lg" target="_blank">
                                            <i class="bi bi-printer me-2"></i>Print Invoice
                                        </a>
                                        <a href="generate_pdf.php?id=<?php echo urlencode($order_id); ?>&download=1" class="btn btn-success btn-lg">
                                            <i class="bi bi-download me-2"></i>Download Invoice PDF
                                        </a>
                                        <a href="view_invoices.php" class="btn btn-outline-secondary btn-lg">
                                            <i class="bi bi-list me-2"></i>View All Invoices
                                        </a>
                                    </div>
                                </div>
                            <?php else: ?>
                                <!-- Order not yet received - show receiving form -->
                                <form method="POST" action="" id="receiveForm">
                                    <input type="hidden" name="action" value="receive_order">
                                    <button type="submit" class="btn btn-success btn-lg w-100 mb-2">
                                        <i class="bi bi-check-circle me-2"></i>Complete Order Receiving
                                    </button>
                                    <button type="button" class="btn btn-info btn-lg w-100" onclick="previewInvoice()">
                                        <i class="bi bi-file-earmark-text me-2"></i>Preview Invoice
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Invoice Preview -->
                    <div class="card mt-4" id="invoicePreviewCard" style="display: none;">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-receipt me-2"></i>Invoice Preview</h5>
                        </div>
                        <div class="card-body">
                            <div class="invoice-preview" id="invoicePreview">
                        <div class="invoice-header">
                            <h4><?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></h4>
                            <p>Purchase Invoice</p>
                        </div>

                        <div class="row mb-3">
                            <div class="col-6">
                                <strong>Our Invoice #:</strong> <span id="preview_our_invoice">-</span><br>
                                <strong>Supplier Invoice #:</strong> <span id="preview_supplier_invoice">-</span><br>
                                <strong>Date:</strong> <span id="preview_date"><?php echo date('M d, Y'); ?></span><br>
                                <strong>Order #:</strong> <?php echo htmlspecialchars($order['order_number']); ?>
                            </div>
                            <div class="col-6 text-end">
                                <strong>Supplier:</strong><br>
                                <?php echo htmlspecialchars($order['supplier_name'] ?? 'N/A'); ?><br>
                                <strong>Status:</strong> Received
                            </div>
                        </div>

                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th class="text-center">Qty</th>
                                    <th class="text-end">Price</th>
                                    <th class="text-end">Total</th>
                                </tr>
                            </thead>
                            <tbody id="preview_items">
                                <!-- Items will be populated by JavaScript -->
                            </tbody>
                        </table>

                        <div class="text-end mt-3">
                            <strong>Total: <span id="preview_total"><?php echo formatCurrency(0, $settings['currency_symbol'] ?? 'KES'); ?></span></strong>
                        </div>

                        <div class="mt-3" id="preview_notes" style="display: none;">
                            <strong>Notes:</strong> <span id="preview_notes_text"></span>
                        </div>
                    </div>
                            <div class="mt-3">
                                <button type="button" class="btn btn-primary btn-sm" onclick="printInvoice()">
                                    <i class="bi bi-printer me-1"></i>Print Invoice
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Calculate totals when quantity inputs change
        document.addEventListener('DOMContentLoaded', function() {
            const quantityInputs = document.querySelectorAll('input[name^="item_quantities"]');
            const updateQuantityInputs = document.querySelectorAll('input[name^="update_quantities"]');

            function updateTotals() {
                let totalReceiving = 0;
                let totalValue = 0;

                quantityInputs.forEach(input => {
                    const quantity = parseInt(input.value) || 0;
                    const itemId = input.name.match(/\[(\d+)\]/)[1];
                    const costPrice = <?php echo json_encode(array_column($order['items'], 'cost_price', 'id')); ?>[itemId];

                    totalReceiving += quantity;
                    totalValue += quantity * costPrice;

                    // Update line total
                    const lineTotalElement = document.getElementById('line-total-' + itemId);
                    if (lineTotalElement) {
                        lineTotalElement.textContent = formatCurrency(quantity * costPrice);
                    }
                });

                document.getElementById('receiving-now').textContent = totalReceiving;
                document.getElementById('total-value').textContent = formatCurrency(totalValue);
            }

            // Add event listeners for both quantity inputs
            quantityInputs.forEach(input => {
                input.addEventListener('input', updateTotals);
                input.addEventListener('change', updateTotals);
            });

            updateQuantityInputs.forEach(input => {
                input.addEventListener('input', updateTotals);
                input.addEventListener('change', updateTotals);
            });

            function formatCurrency(amount) {
                return '<?php echo $settings['currency_symbol'] ?? 'KES'; ?> ' + amount.toFixed(2);
            }

            // Invoice preview functionality
            window.previewInvoice = function() {
                const ourInvoiceNumber = document.getElementById('invoice_number').value || 'Auto-generated';
                const supplierInvoiceNumber = document.getElementById('supplier_invoice_number').value || '-';
                const receivedDate = document.getElementById('received_date').value;
                const notes = document.getElementById('notes').value;

                // Update preview fields
                document.getElementById('preview_our_invoice').textContent = ourInvoiceNumber;
                document.getElementById('preview_supplier_invoice').textContent = supplierInvoiceNumber;
                document.getElementById('preview_date').textContent = new Date(receivedDate).toLocaleDateString();

                // Clear existing items
                const itemsContainer = document.getElementById('preview_items');
                itemsContainer.innerHTML = '';

                // Add items to preview
                let totalAmount = 0;
                quantityInputs.forEach(input => {
                    const quantity = parseInt(input.value) || 0;
                    if (quantity > 0) {
                        const itemId = input.name.match(/\[(\d+)\]/)[1];
                        const item = <?php echo json_encode($order['items']); ?>.find(item => item.id == itemId);
                        const lineTotal = quantity * item.cost_price;
                        totalAmount += lineTotal;

                        const row = document.createElement('tr');
                        row.innerHTML = `
                            <td>${item.product_name}</td>
                            <td class="text-center">${quantity}</td>
                            <td class="text-end"><?php echo $settings['currency_symbol'] ?? 'KES'; ?> ${item.cost_price.toFixed(2)}</td>
                            <td class="text-end"><?php echo $settings['currency_symbol'] ?? 'KES'; ?> ${lineTotal.toFixed(2)}</td>
                        `;
                        itemsContainer.appendChild(row);
                    }
                });

                // Update total
                document.getElementById('preview_total').textContent = '<?php echo $settings['currency_symbol'] ?? 'KES'; ?> ' + totalAmount.toFixed(2);

                // Update notes
                if (notes.trim()) {
                    document.getElementById('preview_notes_text').textContent = notes;
                    document.getElementById('preview_notes').style.display = 'block';
                } else {
                    document.getElementById('preview_notes').style.display = 'none';
                }

                document.getElementById('invoicePreviewCard').style.display = 'block';
            };

            // Print invoice functionality
            window.printInvoice = function() {
                const printWindow = window.open('', '_blank');
                const invoiceContent = document.getElementById('invoicePreview').innerHTML;

                printWindow.document.write(`
                    <!DOCTYPE html>
                    <html>
                    <head>
                        <title>Invoice - <?php echo htmlspecialchars($order['order_number']); ?></title>
                        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
                        <style>
                            body { font-family: Arial, sans-serif; margin: 20px; }
                            .invoice-header { text-align: center; margin-bottom: 30px; }
                            .no-print { display: none; }
                            @media print {
                                body { margin: 0; }
                                .invoice-preview { box-shadow: none; border: none; }
                            }
                        </style>
                    </head>
                    <body>
                        <div class="container">
                            ${invoiceContent}
                        </div>
                    </body>
                    </html>
                `);

                printWindow.document.close();
                printWindow.print();
            };

            // Auto-generate our invoice number if enabled
            <?php if (isset($settings['invoice_auto_generate']) && $settings['invoice_auto_generate'] == '1' && empty($order['invoice_number'])): ?>
            document.getElementById('invoice_number').value = '<?php echo htmlspecialchars(generateInvoiceNumber($conn, $settings) ?? ''); ?>';
            <?php endif; ?>

            // Update invoice preview in real-time
            function updateInvoicePreview() {
                const supplierInvoice = document.getElementById('supplier_invoice_number').value.trim();
                const ourInvoice = document.getElementById('invoice_number').value.trim();

                document.getElementById('supplier_invoice_preview').textContent = supplierInvoice || '-';
                document.getElementById('our_invoice_preview').textContent = ourInvoice || '-';
            }

            // Add event listeners for real-time preview updates
            document.getElementById('supplier_invoice_number').addEventListener('input', updateInvoicePreview);
            document.getElementById('invoice_number').addEventListener('input', updateInvoicePreview);

            // Initial preview update
            updateInvoicePreview();

            // Auto-generate our invoice number when supplier invoice is entered (if auto-generate is enabled)
            document.getElementById('supplier_invoice_number').addEventListener('blur', function() {
                const supplierInvoice = this.value.trim();
                const ourInvoiceField = document.getElementById('invoice_number');

                <?php if (isset($settings['invoice_auto_generate']) && $settings['invoice_auto_generate'] == '1'): ?>
                if (supplierInvoice && !ourInvoiceField.value) {
                    // Auto-generate our invoice number
                    fetch(window.location.href, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'action=generate_invoice_number'
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.invoice_number) {
                            ourInvoiceField.value = data.invoice_number;
                            updateInvoicePreview();
                        }
                    })
                    .catch(error => console.error('Error generating invoice number:', error));
                }
                <?php endif; ?>
            });

        });

        // Auto-redirect to invoice view after successful order receiving
        <?php if ($message && $message_type === 'success' && isset($order) && $order['status'] === 'received'): ?>
        setTimeout(function() {
            window.location.href = 'view_invoice.php?id=<?php echo urlencode($order['invoice_number'] ?? $order_id); ?>';
        }, 2000);
        <?php endif; ?>

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
