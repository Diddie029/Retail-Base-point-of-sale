<?php
session_start();
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';
require_once __DIR__ . '/../include/classes/AutoBOMManager.php';

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

// Check if user has permission to process sales
if (!hasPermission('process_sales', $permissions)) {
    header("Location: ../dashboard/dashboard.php");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Initialize Auto BOM Manager
$auto_bom_manager = new AutoBOMManager($conn, $user_id);

// Handle form submission
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Start transaction
        if (!$conn->inTransaction()) {
            $conn->beginTransaction();
            error_log("Transaction started for checkout");
        } else {
            error_log("Transaction already active, skipping beginTransaction");
        }

        // Get cart data from POST
        $cart_items = json_decode($_POST['cart_data'], true);
        $payment_method = sanitizeInput($_POST['payment_method']);
        $customer_notes = sanitizeInput($_POST['customer_notes'] ?? '');

        if (empty($cart_items)) {
            throw new Exception('No items in cart');
        }

        // Validate cart data structure
        if (!is_array($cart_items)) {
            throw new Exception('Invalid cart data format');
        }

        // Handle different cart data structures
        if (isset($cart_items['items']) && is_array($cart_items['items'])) {
            $cart_items = $cart_items['items'];
        }

        // Validate that we have valid items
        $valid_items = [];
        foreach ($cart_items as $item) {
            if (is_array($item) && isset($item['name'], $item['price'], $item['quantity'])) {
                $valid_items[] = $item;
            }
        }

        if (empty($valid_items)) {
            throw new Exception('No valid items found in cart');
        }

        $cart_items = $valid_items;

        // Calculate totals
        $subtotal = 0;
        $tax_rate = (float) ($settings['tax_rate'] ?? 0);
        $tax_amount = 0;
        $total_amount = 0;

        // Process each cart item
        $sale_items = [];
        foreach ($cart_items as $item) {
            // Validate item structure before processing
            if (!is_array($item) || !isset($item['price'], $item['quantity'], $item['name'])) {
                throw new Exception("Invalid cart item structure");
            }

            $item_price = (float)($item['price'] ?? 0);
            $item_quantity = (int)($item['quantity'] ?? 0);
            $item_name = $item['name'] ?? 'Unknown Product';
            $item_id = $item['id'] ?? null;

            // Validate required data
            if ($item_price <= 0 || $item_quantity <= 0) {
                throw new Exception("Invalid price or quantity for item: " . $item_name);
            }

            $item_total = $item_price * $item_quantity;
            $subtotal += $item_total;

            // Handle Auto BOM items
            if (isset($item['is_auto_bom']) && $item['is_auto_bom']) {
                // Validate Auto BOM required fields
                if (!isset($item['selling_unit_id'], $item['base_product_id'])) {
                    throw new Exception("Missing Auto BOM data for item: " . $item_name);
                }

                // Process Auto BOM sale
                $result = $auto_bom_manager->processSale(
                    $item['selling_unit_id'],
                    $item_quantity,
                    $item_price
                );

                if (!$result['success']) {
                    throw new Exception("Failed to process Auto BOM item: " . ($result['error'] ?? 'Unknown error'));
                }

                $sale_items[] = [
                    'product_id' => $item['base_product_id'],
                    'selling_unit_id' => $item['selling_unit_id'],
                    'product_name' => $item_name,
                    'quantity' => $item_quantity,
                    'unit_price' => $item_price,
                    'total_price' => $item_total,
                    'is_auto_bom' => true,
                    'base_quantity_deducted' => $result['base_quantity_deducted']
                ];
            } else {
                // Validate regular product required fields
                if (!$item_id) {
                    throw new Exception("Missing product ID for item: " . $item_name);
                }

                // Handle regular product sale
                // Deduct from inventory
                $stmt = $conn->prepare("
                    UPDATE products
                    SET quantity = quantity - :quantity, updated_at = NOW()
                    WHERE id = :product_id AND quantity >= :quantity
                ");
                $stmt->execute([
                    ':quantity' => $item_quantity,
                    ':product_id' => $item_id,
                    ':quantity' => $item_quantity
                ]);

                if ($stmt->rowCount() === 0) {
                    throw new Exception("Insufficient stock for product: " . $item_name);
                }

                $sale_items[] = [
                    'product_id' => $item_id,
                    'selling_unit_id' => null,
                    'product_name' => $item_name,
                    'quantity' => $item_quantity,
                    'unit_price' => $item_price,
                    'total_price' => $item_total,
                    'is_auto_bom' => false,
                    'base_quantity_deducted' => $item_quantity
                ];
            }
        }

        // Calculate tax and total
        $tax_amount = $subtotal * ($tax_rate / 100);
        $total_amount = $subtotal + $tax_amount;

        // Create sale record
        $stmt = $conn->prepare("
            INSERT INTO sales (
                user_id, subtotal, tax_rate, tax_amount, total_amount,
                payment_method, customer_notes, created_at
            ) VALUES (
                :user_id, :subtotal, :tax_rate, :tax_amount, :total_amount,
                :payment_method, :customer_notes, NOW()
            )
        ");
        $stmt->execute([
            ':user_id' => $user_id,
            ':subtotal' => $subtotal,
            ':tax_rate' => $tax_rate,
            ':tax_amount' => $tax_amount,
            ':total_amount' => $total_amount,
            ':payment_method' => $payment_method,
            ':customer_notes' => $customer_notes
        ]);

        $sale_id = $conn->lastInsertId();

        // Insert sale items
        foreach ($sale_items as $item) {
            $stmt = $conn->prepare("
                INSERT INTO sale_items (
                    sale_id, product_id, selling_unit_id, product_name,
                    quantity, unit_price, total_price, is_auto_bom, base_quantity_deducted
                ) VALUES (
                    :sale_id, :product_id, :selling_unit_id, :product_name,
                    :quantity, :unit_price, :total_price, :is_auto_bom, :base_quantity_deducted
                )
            ");
            $stmt->execute([
                ':sale_id' => $sale_id,
                ':product_id' => $item['product_id'],
                ':selling_unit_id' => $item['selling_unit_id'],
                ':product_name' => $item['product_name'],
                ':quantity' => $item['quantity'],
                ':unit_price' => $item['unit_price'],
                ':total_price' => $item['total_price'],
                ':is_auto_bom' => $item['is_auto_bom'] ? 1 : 0,
                ':base_quantity_deducted' => $item['base_quantity_deducted']
            ]);
        }

        // Log the sale
        logActivity($conn, $user_id, 'sale_completed', "Completed sale #$sale_id with total: " . formatCurrency($total_amount));

        // Commit transaction
        if ($conn->inTransaction()) {
            $conn->commit();
            error_log("Transaction committed successfully for sale #$sale_id");
        } else {
            error_log("No active transaction to commit for sale #$sale_id");
        }

        // Redirect to receipt or success page
        header("Location: receipt.php?sale_id=$sale_id&success=1");
        exit();

    } catch (Exception $e) {
        // Only rollback if there's an active transaction
        if ($conn->inTransaction()) {
            try {
                $conn->rollBack();
            } catch (PDOException $rollbackError) {
                error_log("Rollback failed: " . $rollbackError->getMessage());
            }
        }
        $error = $e->getMessage();
        error_log("Checkout error: " . $e->getMessage());
    }
}

// Get cart data from session or redirect back to POS
$cart_data = $_SESSION['pos_cart'] ?? null;
if (!$cart_data) {
    header("Location: sale.php");
    exit();
}

// Decode the cart data
$cart_items = json_decode($cart_data, true);

// Debug: Log the decoded cart data
error_log("Cart data received: " . print_r($cart_items, true));

// Validate cart data structure
if (!is_array($cart_items) || empty($cart_items)) {
    error_log("Invalid cart data structure");
    header("Location: sale.php");
    exit();
}

// Handle different cart data structures
if (isset($cart_items['items']) && is_array($cart_items['items'])) {
    // Cart data has items array
    $cart_items = $cart_items['items'];
} elseif (isset($cart_items[0]) && is_array($cart_items[0])) {
    // Cart data is already an array of items
    // Keep as is
} else {
    // Invalid structure, redirect back
    header("Location: sale.php");
    exit();
}

// Additional validation - ensure all items have required fields
$valid_cart_items = [];
foreach ($cart_items as $item) {
    if (is_array($item) && isset($item['name'], $item['price'], $item['quantity'])) {
        $valid_cart_items[] = $item;
    }
}

if (empty($valid_cart_items)) {
    header("Location: sale.php");
    exit();
}

$cart_items = $valid_cart_items;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }

        /* POS Full Width Layout */
        .pos-container-full {
            width: 100vw;
            height: 100vh;
            background: #f8f9fa;
            overflow: hidden;
        }

        .pos-main-content {
            width: 100%;
            height: 100vh;
            padding: 0;
            overflow-y: auto;
        }

        .pos-top-bar {
            background: white;
            border-bottom: 1px solid #e9ecef;
            padding: 1rem 1.5rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .pos-brand h5 {
            font-weight: 600;
        }

        .pos-user-info {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .checkout-container {
            max-width: 800px;
            margin: 0 auto;
        }

        /* Floating Dashboard Button */
        .floating-dashboard-btn {
            position: fixed;
            bottom: 30px;
            right: 30px;
            z-index: 1000;
        }

        .btn-floating {
            background: linear-gradient(45deg, #667eea, #764ba2);
            border: none;
            border-radius: 50px;
            padding: 15px 25px;
            color: white;
            font-weight: 600;
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }

        .btn-floating:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 35px rgba(102, 126, 234, 0.6);
            color: white;
        }

        .btn-floating i {
            font-size: 1.2rem;
        }

        .btn-text {
            font-size: 0.9rem;
        }

        .checkout-summary {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }

        .checkout-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .checkout-item:last-child {
            border-bottom: none;
        }

        .item-info {
            flex: 1;
        }

        .item-name {
            font-weight: 500;
            margin-bottom: 4px;
        }

        .item-details {
            font-size: 0.8rem;
            color: #64748b;
        }

        .item-total {
            font-weight: 600;
            color: var(--primary-color);
        }

        .checkout-totals {
            background: #f8fafc;
            border-radius: 8px;
            padding: 20px;
            margin-top: 20px;
        }

        .total-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }

        .total-row.final {
            border-top: 2px solid #e2e8f0;
            padding-top: 15px;
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--primary-color);
        }

        .payment-section {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }

        .payment-methods {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }

        .payment-method {
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .payment-method:hover {
            border-color: var(--primary-color);
        }

        .payment-method.selected {
            border-color: var(--primary-color);
            background: #f0f9ff;
        }

        .payment-method-icon {
            font-size: 2rem;
            margin-bottom: 10px;
            color: var(--primary-color);
        }

        .auto-bom-indicator {
            background: #06b6d4;
            color: white;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-left: 8px;
        }

        .inventory-warning {
            background: #fef3c7;
            border: 1px solid #f59e0b;
            color: #92400e;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 15px;
        }

        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
    </style>
</head>
<body>
    <div class="pos-container-full">
        <main class="pos-main-content">
            <!-- POS Top Bar -->
            <div class="pos-top-bar">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="pos-brand">
                        <h5 class="mb-0 text-primary">
                            <i class="bi bi-shop me-2"></i><?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?>
                        </h5>
                        <small class="text-muted">Point of Sale - Checkout</small>
                    </div>
                    <div class="pos-user-info">
                        <span class="text-muted">Welcome, </span>
                        <strong><?php echo htmlspecialchars($username); ?></strong>
                        <span class="badge bg-primary ms-2"><?php echo htmlspecialchars($role_name); ?></span>
                    </div>
                </div>
            </div>

            <div class="container-fluid px-4 py-3">
                <div class="checkout-container">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h1 class="h3 mb-0">Checkout</h1>
                        <a href="sale.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left"></i> Back to POS
                        </a>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>

                    <!-- Order Summary -->
                    <div class="checkout-summary">
                        <h5 class="mb-3"><i class="bi bi-receipt"></i> Order Summary</h5>

                        <?php
                        $subtotal = 0;
                        $has_inventory_warnings = false;
                        $inventory_warnings = [];
                        $valid_items_count = 0;

                        foreach ($cart_items as $item):
                            // Validate item structure
                            if (!is_array($item) || !isset($item['price']) || !isset($item['quantity']) || !isset($item['name'])) {
                                error_log("Invalid cart item: " . print_r($item, true));
                                continue; // Skip invalid items
                            }

                            $item_price = (float)($item['price'] ?? 0);
                            $item_quantity = (int)($item['quantity'] ?? 0);
                            $item_name = $item['name'] ?? 'Unknown Product';
                            
                            // Skip items with invalid data
                            if ($item_price <= 0 || $item_quantity <= 0) {
                                error_log("Invalid item price or quantity: " . print_r($item, true));
                                continue;
                            }
                            
                            $item_total = $item_price * $item_quantity;
                            $subtotal += $item_total;
                            $valid_items_count++;

                            // Check inventory for Auto BOM items
                            if (isset($item['is_auto_bom']) && $item['is_auto_bom']) {
                                try {
                                    $inventory_check = $auto_bom_manager->checkBaseStockAvailability(
                                        $item['base_product_id'] ?? 0,
                                        $item_quantity,
                                        $item['selling_unit_id'] ?? null
                                    );

                                    if (!$inventory_check['available']) {
                                        $has_inventory_warnings = true;
                                        $inventory_warnings[] = "Insufficient stock for " . htmlspecialchars($item_name);
                                    }
                                } catch (Exception $e) {
                                    $has_inventory_warnings = true;
                                    $inventory_warnings[] = "Error checking inventory for " . htmlspecialchars($item_name);
                                }
                            }
                        ?>

                            <div class="checkout-item">
                                <div class="item-info">
                                    <div class="item-name">
                                        <?php echo htmlspecialchars($item_name); ?>
                                        <?php if (isset($item['is_auto_bom']) && $item['is_auto_bom']): ?>
                                            <span class="auto-bom-indicator">Auto BOM</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="item-details">
                                        Quantity: <?php echo $item_quantity; ?> ×
                                        <?php echo $settings['currency_symbol']; ?> <?php echo number_format($item_price, 2); ?>
                                    </div>
                                </div>
                                <div class="item-total">
                                    <?php echo $settings['currency_symbol']; ?> <?php echo number_format($item_total, 2); ?>
                                </div>
                            </div>

                        <?php endforeach; ?>

                        <?php if ($valid_items_count === 0): ?>
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle"></i>
                                No valid items found in cart. Please return to the POS and add items to your cart.
                            </div>
                        <?php endif; ?>

                        <!-- Inventory Warnings -->
                        <?php if ($has_inventory_warnings): ?>
                            <div class="inventory-warning">
                                <i class="bi bi-exclamation-triangle"></i>
                                <strong>Inventory Warning:</strong>
                                <ul class="mb-0 mt-2">
                                    <?php foreach ($inventory_warnings as $warning): ?>
                                        <li><?php echo htmlspecialchars($warning); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <!-- Order Totals -->
                        <div class="checkout-totals">
                            <?php
                            $tax_rate = (float) ($settings['tax_rate'] ?? 0);
                            $tax_amount = $subtotal * ($tax_rate / 100);
                            $total_amount = $subtotal + $tax_amount;
                            ?>

                            <div class="total-row">
                                <span>Subtotal:</span>
                                <span><?php echo $settings['currency_symbol']; ?> <?php echo number_format($subtotal, 2); ?></span>
                            </div>

                            <?php if ($tax_rate > 0): ?>
                                <div class="total-row">
                                    <span>Tax (<?php echo $tax_rate; ?>%):</span>
                                    <span><?php echo $settings['currency_symbol']; ?> <?php echo number_format($tax_amount, 2); ?></span>
                                </div>
                            <?php endif; ?>

                            <div class="total-row final">
                                <span>Total:</span>
                                <span><?php echo $settings['currency_symbol']; ?> <?php echo number_format($total_amount, 2); ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Method -->
                    <form method="POST" id="checkoutForm">
                        <input type="hidden" name="cart_data" value="<?php echo htmlspecialchars($cart_data); ?>">

                        <div class="payment-section">
                            <h5 class="mb-3"><i class="bi bi-credit-card"></i> Payment Method</h5>

                            <div class="payment-methods">
                                <div class="payment-method" data-method="cash">
                                    <div class="payment-method-icon">
                                        <i class="bi bi-cash"></i>
                                    </div>
                                    <div class="payment-method-name">Cash</div>
                                </div>

                                <div class="payment-method" data-method="card">
                                    <div class="payment-method-icon">
                                        <i class="bi bi-credit-card"></i>
                                    </div>
                                    <div class="payment-method-name">Card</div>
                                </div>

                                <div class="payment-method" data-method="mobile">
                                    <div class="payment-method-icon">
                                        <i class="bi bi-phone"></i>
                                    </div>
                                    <div class="payment-method-name">Mobile Money</div>
                                </div>

                                <div class="payment-method" data-method="bank_transfer">
                                    <div class="payment-method-icon">
                                        <i class="bi bi-bank"></i>
                                    </div>
                                    <div class="payment-method-name">Bank Transfer</div>
                                </div>
                            </div>

                            <input type="hidden" name="payment_method" id="paymentMethodInput" required>

                            <div class="mt-3">
                                <label for="customerNotes" class="form-label">Customer Notes (Optional)</label>
                                <textarea class="form-control" id="customerNotes" name="customer_notes"
                                          rows="3" placeholder="Any special notes or customer requests..."></textarea>
                            </div>
                        </div>

                        <!-- Checkout Actions -->
                        <div class="d-flex justify-content-between align-items-center">
                            <a href="sale.php" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-left"></i> Back to POS
                            </a>

                            <button type="submit" class="btn btn-success btn-lg"
                                    <?php echo ($has_inventory_warnings || $valid_items_count === 0) ? 'disabled' : ''; ?>>
                                <i class="bi bi-check-circle"></i>
                                Complete Sale (<?php echo $settings['currency_symbol']; ?> <?php echo number_format($total_amount, 2); ?>)
                            </button>
                        </div>

                        <?php if ($has_inventory_warnings): ?>
                            <div class="alert alert-warning mt-3">
                                <i class="bi bi-exclamation-triangle"></i>
                                <strong>Cannot complete sale:</strong> Please resolve inventory issues before proceeding.
                            </div>
                        <?php endif; ?>

                        <?php if ($valid_items_count === 0): ?>
                            <div class="alert alert-danger mt-3">
                                <i class="bi bi-exclamation-triangle"></i>
                                <strong>Cannot complete sale:</strong> No valid items in cart. Please return to POS and add items.
                            </div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </main>
    </div>

    <!-- Floating Dashboard Button -->
    <div class="floating-dashboard-btn">
        <a href="../dashboard/dashboard.php" class="btn btn-primary btn-floating" title="Go to Dashboard">
            <i class="bi bi-speedometer2"></i>
            <span class="btn-text">Dashboard</span>
        </a>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/dashboard.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Payment method selection
            const paymentMethods = document.querySelectorAll('.payment-method');
            const paymentMethodInput = document.getElementById('paymentMethodInput');

            paymentMethods.forEach(method => {
                method.addEventListener('click', function() {
                    // Remove selected class from all methods
                    paymentMethods.forEach(m => m.classList.remove('selected'));

                    // Add selected class to clicked method
                    this.classList.add('selected');

                    // Set the hidden input value
                    paymentMethodInput.value = this.dataset.method;
                });
            });

            // Form validation
            document.getElementById('checkoutForm').addEventListener('submit', function(e) {
                if (!paymentMethodInput.value) {
                    e.preventDefault();
                    alert('Please select a payment method.');
                    return false;
                }

                // Show loading state
                const submitBtn = this.querySelector('button[type="submit"]');
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Processing...';
            });
        });
    </script>
</body>
</html>
