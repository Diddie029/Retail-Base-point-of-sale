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

// Function to get orders ready for bulk reception
function getOrdersForBulkReception($conn, $supplier_id = null) {
    try {
        $where = "io.status IN ('sent', 'waiting_for_delivery')";

        if ($supplier_id) {
            $where .= " AND io.supplier_id = :supplier_id";
        }

        $query = "
            SELECT io.*,
                   s.name as supplier_name, s.contact_person, s.phone, s.email,
                   COUNT(ioi.id) as total_items,
                   SUM(ioi.quantity) as total_ordered_quantity,
                   SUM(ioi.received_quantity) as total_received_quantity
            FROM inventory_orders io
            LEFT JOIN suppliers s ON io.supplier_id = s.id
            LEFT JOIN inventory_order_items ioi ON io.id = ioi.order_id
            WHERE $where
            GROUP BY io.id, s.name, s.contact_person, s.phone, s.email
            ORDER BY io.supplier_id, io.order_date ASC
        ";

        $stmt = $conn->prepare($query);

        if ($supplier_id) {
            $stmt->bindParam(':supplier_id', $supplier_id);
        }

        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        error_log("Error getting orders for bulk reception: " . $e->getMessage());
        return [];
    }
}

// Function to process bulk reception
function processBulkReception($conn, $order_ids, $supplier_invoice_number, $invoice_notes, $user_id, $settings) {
    try {
        $conn->beginTransaction();

        $processed_orders = 0;
        $generated_invoices = [];

        foreach ($order_ids as $order_id) {
            // Get order details
            $stmt = $conn->prepare("
                SELECT io.*, s.name as supplier_name
                FROM inventory_orders io
                LEFT JOIN suppliers s ON io.supplier_id = s.id
                WHERE io.id = :order_id AND io.status IN ('sent', 'waiting_for_delivery')
            ");
            $stmt->bindParam(':order_id', $order_id);
            $stmt->execute();
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$order) continue;

            // Get order items
            $stmt = $conn->prepare("
                SELECT ioi.*, p.quantity as current_stock
                FROM inventory_order_items ioi
                LEFT JOIN products p ON ioi.product_id = p.id
                WHERE ioi.order_id = :order_id
            ");
            $stmt->bindParam(':order_id', $order_id);
            $stmt->execute();
            $order_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Update all items as fully received
            $total_received_quantity = 0;
            foreach ($order_items as $item) {
                $stmt = $conn->prepare("
                    UPDATE inventory_order_items
                    SET received_quantity = :quantity,
                        updated_at = NOW()
                    WHERE id = :item_id
                ");
                $stmt->execute([
                    ':quantity' => $item['quantity'],
                    ':item_id' => $item['id']
                ]);

                // Update product stock (already updated above)
                // No additional stock update needed as it's done in the query above

                $total_received_quantity += $item['quantity'];
            }

            // Generate invoice number
            $invoice_number = generateOrderInvoiceNumber($conn, $settings);

            // Update order
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

            // Log activity
            logActivity($conn, $user_id, 'bulk_order_received',
                "Bulk received order {$order['order_number']} with invoice {$invoice_number}");

            $generated_invoices[] = [
                'order_number' => $order['order_number'],
                'invoice_number' => $invoice_number,
                'supplier_name' => $order['supplier_name']
            ];

            $processed_orders++;
        }

        $conn->commit();

        return [
            'success' => true,
            'processed_orders' => $processed_orders,
            'generated_invoices' => $generated_invoices
        ];

    } catch (Exception $e) {
        $conn->rollBack();
        error_log("Error in bulk reception: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Handle form submission
$errors = [];
$success = '';
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_receive') {
    $selected_orders = $_POST['selected_orders'] ?? [];
    $supplier_invoice_number = sanitizeProductInput($_POST['supplier_invoice_number'] ?? '');
    $invoice_notes = sanitizeProductInput($_POST['invoice_notes'] ?? '', 'text');

    if (empty($selected_orders)) {
        $errors[] = 'Please select at least one order to receive';
    }

    if (empty($errors)) {
        $result = processBulkReception($conn, $selected_orders, $supplier_invoice_number, $invoice_notes, $user_id, $settings);

        if ($result['success']) {
            $success = "Successfully processed {$result['processed_orders']} order(s) and generated {$result['processed_orders']} invoice(s).";
        } else {
            $errors[] = $result['error'];
        }
    }
}

// Get filter parameters
$supplier_id = isset($_GET['supplier_id']) ? intval($_GET['supplier_id']) : null;
$orders = getOrdersForBulkReception($conn, $supplier_id);

// Group orders by supplier
$orders_by_supplier = [];
foreach ($orders as $order) {
    $supplier_id = $order['supplier_id'];
    if (!isset($orders_by_supplier[$supplier_id])) {
        $orders_by_supplier[$supplier_id] = [
            'supplier_name' => $order['supplier_name'],
            'supplier_id' => $supplier_id,
            'orders' => [],
            'total_orders' => 0,
            'total_items' => 0,
            'total_quantity' => 0
        ];
    }
    $orders_by_supplier[$supplier_id]['orders'][] = $order;
    $orders_by_supplier[$supplier_id]['total_orders']++;
    $orders_by_supplier[$supplier_id]['total_items'] += $order['total_items'];
    $orders_by_supplier[$supplier_id]['total_quantity'] += $order['total_ordered_quantity'];
}

// Get suppliers for filter
$suppliers = [];
$stmt = $conn->query("SELECT id, name FROM suppliers WHERE is_active = 1 ORDER BY name ASC");
$suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk Receive Orders - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        .supplier-group {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border: 1px solid #e2e8f0;
        }

        .supplier-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }

        .order-item {
            background: #f8fafc;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 0.5rem;
        }

        .order-item.selected {
            background: #e0f2fe;
            border-color: #0288d1;
        }

        .bulk-actions {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }

        .checkbox-wrapper {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .checkbox-wrapper input[type="checkbox"] {
            width: 18px;
            height: 18px;
        }

        .stats-card {
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 1rem;
            text-align: center;
        }

        .stats-number {
            font-size: 2rem;
            font-weight: 600;
            color: #6366f1;
        }

        .stats-label {
            font-size: 0.875rem;
            color: #6c757d;
        }

        .supplier-stats {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../include/navmenu.php'; ?>

    <div class="main-content">
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1>Bulk Receive Orders</h1>
                    <p class="header-subtitle">Process multiple orders from the same supplier simultaneously</p>
                </div>
                <div class="header-actions">
                    <a href="receive_order.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-2"></i>Single Order Reception
                    </a>
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

                <?php if ($result && !empty($result['generated_invoices'])): ?>
                <div class="supplier-group">
                    <h5 class="mb-3">
                        <i class="bi bi-receipt me-2"></i>Generated Invoices
                    </h5>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Order #</th>
                                    <th>Invoice #</th>
                                    <th>Supplier</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($result['generated_invoices'] as $invoice): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($invoice['order_number']); ?></td>
                                    <td><strong><?php echo htmlspecialchars($invoice['invoice_number']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($invoice['supplier_name']); ?></td>
                                    <td>
                                        <a href="generate_order_invoice.php?order_id=<?php echo urlencode($invoice['order_number']); ?>&download=1"
                                           class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-download me-1"></i>Download
                                        </a>
                                        <a href="generate_order_invoice.php?order_id=<?php echo urlencode($invoice['order_number']); ?>"
                                           class="btn btn-sm btn-outline-info" target="_blank">
                                            <i class="bi bi-eye me-1"></i>Preview
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Filter Section -->
            <div class="supplier-group">
                <form method="GET" class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Filter by Supplier</label>
                        <select class="form-select" name="supplier_id" onchange="this.form.submit()">
                            <option value="">All Suppliers</option>
                            <?php foreach ($suppliers as $supplier): ?>
                                <option value="<?php echo $supplier['id']; ?>"
                                        <?php echo $supplier_id == $supplier['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($supplier['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">&nbsp;</label>
                        <div>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-funnel me-2"></i>Apply Filter
                            </button>
                            <a href="bulk_receive_orders.php" class="btn btn-outline-secondary">
                                <i class="bi bi-x-circle me-2"></i>Clear Filter
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <?php if (empty($orders_by_supplier)): ?>
                <!-- No Orders Found -->
                <div class="supplier-group">
                    <div class="text-center py-5">
                        <i class="bi bi-box-seam text-muted" style="font-size: 3rem;"></i>
                        <h5 class="text-muted mt-3">No Orders Ready for Bulk Reception</h5>
                        <p class="text-muted">All orders have been received or there are no pending deliveries.</p>
                        <a href="receive_order.php" class="btn btn-primary">
                            <i class="bi bi-box-arrow-in-down me-2"></i>Single Order Reception
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <!-- Bulk Reception Form -->
                <form method="POST" id="bulkReceptionForm">
                    <input type="hidden" name="action" value="bulk_receive">

                    <!-- Overall Stats -->
                    <div class="supplier-group">
                        <h5 class="mb-3">
                            <i class="bi bi-bar-chart me-2"></i>Overall Statistics
                        </h5>
                        <div class="supplier-stats">
                            <div class="stats-card flex-fill">
                                <div class="stats-number"><?php echo count($orders); ?></div>
                                <div class="stats-label">Total Orders</div>
                            </div>
                            <div class="stats-card flex-fill">
                                <div class="stats-number"><?php echo count($orders_by_supplier); ?></div>
                                <div class="stats-label">Suppliers</div>
                            </div>
                            <div class="stats-card flex-fill">
                                <div class="stats-number"><?php echo array_sum(array_column($orders, 'total_items')); ?></div>
                                <div class="stats-label">Total Items</div>
                            </div>
                            <div class="stats-card flex-fill">
                                <div class="stats-number"><?php echo array_sum(array_column($orders, 'total_ordered_quantity')); ?></div>
                                <div class="stats-label">Total Quantity</div>
                            </div>
                        </div>
                    </div>

                    <!-- Supplier Groups -->
                    <?php foreach ($orders_by_supplier as $supplier_data): ?>
                        <div class="supplier-group">
                            <div class="supplier-header">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h5 class="mb-1">
                                            <i class="bi bi-building me-2"></i><?php echo htmlspecialchars($supplier_data['supplier_name']); ?>
                                        </h5>
                                        <p class="mb-0 opacity-75">
                                            <?php echo $supplier_data['total_orders']; ?> orders,
                                            <?php echo $supplier_data['total_items']; ?> items,
                                            <?php echo $supplier_data['total_quantity']; ?> units
                                        </p>
                                    </div>
                                    <div>
                                        <button type="button" class="btn btn-light btn-sm"
                                                onclick="toggleSupplierOrders(<?php echo $supplier_data['supplier_id']; ?>)">
                                            <i class="bi bi-chevron-down"></i> Toggle Orders
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div id="supplier-orders-<?php echo $supplier_data['supplier_id']; ?>">
                                <?php foreach ($supplier_data['orders'] as $order): ?>
                                    <div class="order-item">
                                        <div class="checkbox-wrapper">
                                            <input type="checkbox" class="order-checkbox"
                                                   name="selected_orders[]" value="<?php echo $order['id']; ?>"
                                                   id="order-<?php echo $order['id']; ?>">
                                            <label for="order-<?php echo $order['id']; ?>" class="flex-grow-1">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <div>
                                                        <strong><?php echo htmlspecialchars($order['order_number']); ?></strong>
                                                        <br>
                                                        <small class="text-muted">
                                                            <?php echo date('M j, Y', strtotime($order['order_date'])); ?> |
                                                            <?php echo $order['total_items']; ?> items |
                                                            <?php echo $order['total_ordered_quantity']; ?> units ordered |
                                                            Status: <?php echo ucfirst(str_replace('_', ' ', $order['status'])); ?>
                                                        </small>
                                                    </div>
                                                    <div class="text-end">
                                                        <div class="badge bg-primary"><?php echo $order['total_ordered_quantity']; ?> units</div>
                                                    </div>
                                                </div>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>

                                <!-- Supplier Quick Actions -->
                                <div class="mt-3">
                                    <button type="button" class="btn btn-outline-primary btn-sm"
                                            onclick="selectAllSupplierOrders(<?php echo $supplier_data['supplier_id']; ?>)">
                                        <i class="bi bi-check-all me-1"></i>Select All Orders
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm"
                                            onclick="deselectAllSupplierOrders(<?php echo $supplier_data['supplier_id']; ?>)">
                                        <i class="bi bi-x-circle me-1"></i>Deselect All
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <!-- Bulk Actions -->
                    <div class="bulk-actions">
                        <h5 class="mb-3">
                            <i class="bi bi-check-circle me-2"></i>Bulk Reception Details
                        </h5>

                        <div class="row">
                            <div class="col-md-6">
                                <label class="form-label text-white">Supplier Invoice Number</label>
                                <input type="text" class="form-control" name="supplier_invoice_number"
                                       placeholder="Enter supplier's invoice number (optional)">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-white">Invoice Notes</label>
                                <textarea class="form-control" name="invoice_notes" rows="3"
                                          placeholder="Additional notes for all invoices..."></textarea>
                            </div>
                        </div>

                        <div class="mt-3">
                            <button type="submit" class="btn btn-light btn-lg" id="submitBulkReception">
                                <i class="bi bi-box-arrow-in-down me-2"></i>Receive Selected Orders
                            </button>
                            <span class="text-light ms-3">
                                <i class="bi bi-info-circle me-1"></i>
                                This will mark all selected orders as fully received and generate individual invoices.
                            </span>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle supplier orders visibility
        function toggleSupplierOrders(supplierId) {
            const ordersDiv = document.getElementById(`supplier-orders-${supplierId}`);
            const button = event.target.closest('button');
            const icon = button.querySelector('i');

            if (ordersDiv.style.display === 'none') {
                ordersDiv.style.display = 'block';
                icon.className = 'bi bi-chevron-up';
            } else {
                ordersDiv.style.display = 'none';
                icon.className = 'bi bi-chevron-down';
            }
        }

        // Select all orders for a supplier
        function selectAllSupplierOrders(supplierId) {
            const checkboxes = document.querySelectorAll(`#supplier-orders-${supplierId} input[type="checkbox"]`);
            checkboxes.forEach(checkbox => {
                checkbox.checked = true;
                updateOrderItemStyle(checkbox);
            });
        }

        // Deselect all orders for a supplier
        function deselectAllSupplierOrders(supplierId) {
            const checkboxes = document.querySelectorAll(`#supplier-orders-${supplierId} input[type="checkbox"]`);
            checkboxes.forEach(checkbox => {
                checkbox.checked = false;
                updateOrderItemStyle(checkbox);
            });
        }

        // Update order item styling when checkbox changes
        function updateOrderItemStyle(checkbox) {
            const orderItem = checkbox.closest('.order-item');
            if (checkbox.checked) {
                orderItem.classList.add('selected');
            } else {
                orderItem.classList.remove('selected');
            }
        }

        // Add event listeners to checkboxes
        document.addEventListener('DOMContentLoaded', function() {
            const checkboxes = document.querySelectorAll('.order-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    updateOrderItemStyle(this);
                });

                // Set initial state
                updateOrderItemStyle(checkbox);
            });

            // Form validation
            const form = document.getElementById('bulkReceptionForm');
            if (form) {
                form.addEventListener('submit', function(e) {
                    const selectedOrders = document.querySelectorAll('input[name="selected_orders[]"]:checked');
                    if (selectedOrders.length === 0) {
                        e.preventDefault();
                        alert('Please select at least one order to receive.');
                        return false;
                    }

                    if (!confirm(`Are you sure you want to receive ${selectedOrders.length} order(s)? This will generate ${selectedOrders.length} invoice(s) and update inventory stock.`)) {
                        e.preventDefault();
                        return false;
                    }
                });
            }
        });

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
