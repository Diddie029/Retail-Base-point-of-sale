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

// Get products with low stock and their suppliers
$stmt = $conn->prepare("
    SELECT p.*, s.name as supplier_name, s.pickup_available, s.pickup_address, s.pickup_hours,
           s.pickup_contact_person, s.pickup_contact_phone, s.pickup_instructions,
           (p.quantity <= COALESCE(p.minimum_stock, 0)) as low_stock
    FROM products p
    LEFT JOIN suppliers s ON p.supplier_id = s.id
    WHERE p.status = 'active' AND p.quantity <= COALESCE(p.minimum_stock, 10)
    ORDER BY p.quantity ASC, p.name ASC
");
$stmt->execute();
$low_stock_products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all suppliers with pickup information
$stmt = $conn->prepare("
    SELECT * FROM suppliers
    WHERE is_active = 1
    ORDER BY name ASC
");
$stmt->execute();
$suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $supplier_id = sanitizeProductInput($_POST['supplier_id'] ?? '');
    $order_items = $_POST['order_items'] ?? [];
    $order_type = sanitizeProductInput($_POST['order_type'] ?? 'delivery'); // delivery or pickup
    $special_instructions = sanitizeProductInput($_POST['special_instructions'] ?? '', 'text');

    // Validation
    if (empty($supplier_id)) {
        $errors['supplier'] = 'Please select a supplier';
    }

    if (empty($order_items) || !is_array($order_items)) {
        $errors['items'] = 'Please add at least one item to the order';
    } else {
        $valid_items = 0;
        foreach ($order_items as $item) {
            if (!empty($item['product_id']) && !empty($item['quantity']) && $item['quantity'] > 0) {
                $valid_items++;
            }
        }
        if ($valid_items === 0) {
            $errors['items'] = 'Please add at least one valid item with quantity';
        }
    }

    // If no errors, process the order
    if (empty($errors)) {
        try {
            $conn->beginTransaction();

            // Generate order number
            $order_number = generateOrderNumber($settings);

            // Get supplier info
            $stmt = $conn->prepare("SELECT * FROM suppliers WHERE id = :id");
            $stmt->bindParam(':id', $supplier_id);
            $stmt->execute();
            $supplier = $stmt->fetch(PDO::FETCH_ASSOC);

            // Insert order header
            $stmt = $conn->prepare("
                INSERT INTO orders (
                    order_number, supplier_id, user_id, order_type, status,
                    special_instructions, created_at, updated_at
                ) VALUES (
                    :order_number, :supplier_id, :user_id, :order_type, 'pending',
                    :special_instructions, NOW(), NOW()
                )
            ");

            $stmt->bindParam(':order_number', $order_number);
            $stmt->bindParam(':supplier_id', $supplier_id);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->bindParam(':order_type', $order_type);
            $stmt->bindParam(':special_instructions', $special_instructions);
            $stmt->execute();

            $order_id = $conn->lastInsertId();

            // Insert order items
            $stmt = $conn->prepare("
                INSERT INTO order_items (
                    order_id, product_id, quantity_ordered, unit_price, created_at
                ) VALUES (
                    :order_id, :product_id, :quantity, :unit_price, NOW()
                )
            ");

            foreach ($order_items as $item) {
                if (!empty($item['product_id']) && !empty($item['quantity']) && $item['quantity'] > 0) {
                    $stmt->bindParam(':order_id', $order_id);
                    $stmt->bindParam(':product_id', $item['product_id']);
                    $stmt->bindParam(':quantity', $item['quantity']);
                    $stmt->bindParam(':unit_price', $item['unit_price'] ?? 0);
                    $stmt->execute();
                }
            }

            // Log the activity
            logActivity($conn, $user_id, 'order_created', "Created order: $order_number for supplier: " . $supplier['name']);

            $conn->commit();

            $success = "Order $order_number created successfully! ";

            if ($order_type === 'pickup' && $supplier['pickup_available']) {
                $success .= "Ready for in-store pickup at: " . $supplier['pickup_address'];
            } else {
                $success .= "Delivery order placed with supplier.";
            }

        } catch (PDOException $e) {
            $conn->rollBack();
            $errors['general'] = 'Failed to create order: ' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Place Order - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        .order-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border: 1px solid #e2e8f0;
        }

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

        .product-info {
            flex: 1;
        }

        .quantity-input {
            width: 80px;
        }

        .pickup-info {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1rem;
            border-radius: 8px;
            margin-top: 1rem;
        }

        .supplier-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .supplier-pickup {
            background: #10b981;
            color: white;
        }

        .supplier-delivery {
            background: #f59e0b;
            color: white;
        }

        .low-stock-alert {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }

        .order-summary {
            background: #f0f9ff;
            border: 1px solid #0ea5e9;
            border-radius: 8px;
            padding: 1rem;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../include/navmenu.php'; ?>

    <div class="main-content">
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1>Place Order</h1>
                    <p class="header-subtitle">Simplified ordering - no complex order creation process</p>
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

            <!-- Low Stock Alert -->
            <?php if (!empty($low_stock_products)): ?>
                <div class="low-stock-alert">
                    <h5><i class="bi bi-exclamation-triangle me-2"></i>Low Stock Alert</h5>
                    <p>You have <?php echo count($low_stock_products); ?> products running low on stock. Consider adding them to your order.</p>
                </div>
            <?php endif; ?>

            <form method="POST" id="orderForm">
                <!-- Supplier Selection -->
                <div class="order-card">
                    <h4 class="mb-3">
                        <i class="bi bi-building me-2"></i>Select Supplier
                    </h4>

                    <div class="row">
                        <div class="col-md-6">
                            <label for="supplier_id" class="form-label">Supplier *</label>
                            <select class="form-select <?php echo isset($errors['supplier']) ? 'is-invalid' : ''; ?>"
                                    id="supplier_id" name="supplier_id" onchange="updateSupplierInfo()" required>
                                <option value="">Choose a supplier...</option>
                                <?php foreach ($suppliers as $supplier): ?>
                                    <option value="<?php echo $supplier['id']; ?>"
                                            data-pickup="<?php echo $supplier['pickup_available'] ? 'true' : 'false'; ?>"
                                            data-address="<?php echo htmlspecialchars($supplier['pickup_address'] ?? ''); ?>"
                                            data-hours="<?php echo htmlspecialchars($supplier['pickup_hours'] ?? ''); ?>"
                                            data-contact="<?php echo htmlspecialchars($supplier['pickup_contact_person'] ?? ''); ?>"
                                            data-phone="<?php echo htmlspecialchars($supplier['pickup_contact_phone'] ?? ''); ?>"
                                            data-instructions="<?php echo htmlspecialchars($supplier['pickup_instructions'] ?? ''); ?>">
                                        <?php echo htmlspecialchars($supplier['name']); ?>
                                        <?php if ($supplier['pickup_available']): ?>
                                            <span class="supplier-badge supplier-pickup ms-2">Pickup Available</span>
                                        <?php else: ?>
                                            <span class="supplier-badge supplier-delivery ms-2">Delivery Only</span>
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($errors['supplier'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['supplier']); ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Order Type</label>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="order_type" id="order_delivery" value="delivery" checked>
                                <label class="form-check-label" for="order_delivery">
                                    <i class="bi bi-truck me-2"></i>Delivery Order
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="order_type" id="order_pickup" value="pickup">
                                <label class="form-check-label" for="order_pickup">
                                    <i class="bi bi-shop me-2"></i>In-Store Pickup
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Pickup Information -->
                    <div id="pickupInfo" class="pickup-info" style="display: none;">
                        <h6><i class="bi bi-geo-alt me-2"></i>Pickup Location</h6>
                        <div id="pickupDetails"></div>
                    </div>
                </div>

                <!-- Order Items -->
                <div class="order-card">
                    <h4 class="mb-3">
                        <i class="bi bi-cart-plus me-2"></i>Order Items
                    </h4>

                    <div id="orderItems">
                        <!-- Low stock products will be added here -->
                        <?php if (!empty($low_stock_products)): ?>
                            <div class="alert alert-warning">
                                <strong>Recommended items (Low Stock):</strong>
                            </div>
                            <?php foreach ($low_stock_products as $index => $product): ?>
                                <div class="product-row">
                                    <input type="hidden" name="order_items[<?php echo $index; ?>][product_id]" value="<?php echo $product['id']; ?>">
                                    <input type="hidden" name="order_items[<?php echo $index; ?>][unit_price]" value="<?php echo $product['cost_price']; ?>">

                                    <div class="product-info">
                                        <strong><?php echo htmlspecialchars($product['name']); ?></strong>
                                        <br><small class="text-muted">SKU: <?php echo htmlspecialchars($product['sku']); ?> | Current Stock: <?php echo $product['quantity']; ?></small>
                                    </div>

                                    <div class="quantity-controls">
                                        <label class="form-label">Quantity</label>
                                        <input type="number" class="form-control quantity-input"
                                               name="order_items[<?php echo $index; ?>][quantity]" min="1" max="1000"
                                               placeholder="Qty" value="">
                                    </div>

                                    <div class="unit-price">
                                        <label class="form-label">Unit Price</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><?php echo getCurrencySymbol($settings); ?></span>
                                            <input type="number" class="form-control" step="0.01" min="0"
                                                   name="order_items[<?php echo $index; ?>][unit_price]"
                                                   value="<?php echo number_format($product['cost_price'], 2); ?>" readonly>
                                        </div>
                                    </div>

                                    <button type="button" class="btn btn-outline-danger" onclick="removeItem(this)">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <button type="button" class="btn btn-outline-primary" onclick="addProduct()">
                        <i class="bi bi-plus-circle me-2"></i>Add Another Product
                    </button>
                </div>

                <!-- Special Instructions -->
                <div class="order-card">
                    <h4 class="mb-3">
                        <i class="bi bi-sticky me-2"></i>Special Instructions
                    </h4>
                    <textarea class="form-control" name="special_instructions" rows="3"
                              placeholder="Any special delivery instructions, timing requirements, or notes for the supplier..."></textarea>
                </div>

                <!-- Order Summary -->
                <div class="order-card order-summary">
                    <h4 class="mb-3">
                        <i class="bi bi-receipt me-2"></i>Order Summary
                    </h4>
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>Items:</strong> <span id="itemCount">0</span></p>
                            <p><strong>Total Quantity:</strong> <span id="totalQuantity">0</span></p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Estimated Total:</strong> <span id="estimatedTotal"><?php echo formatCurrency(0, $settings); ?></span></p>
                        </div>
                    </div>
                </div>

                <!-- Submit Button -->
                <div class="text-center">
                    <button type="submit" class="btn btn-success btn-lg">
                        <i class="bi bi-send me-2"></i>Place Order
                    </button>
                    <a href="inventory.php" class="btn btn-secondary btn-lg ms-2">
                        <i class="bi bi-arrow-left me-2"></i>Back to Inventory
                    </a>
                </div>
            </form>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let itemIndex = <?php echo count($low_stock_products); ?>;

        function updateSupplierInfo() {
            const supplierSelect = document.getElementById('supplier_id');
            const selectedOption = supplierSelect.options[supplierSelect.selectedIndex];
            const pickupInfo = document.getElementById('pickupInfo');
            const pickupDetails = document.getElementById('pickupDetails');
            const orderPickup = document.getElementById('order_pickup');

            if (selectedOption.value && selectedOption.dataset.pickup === 'true') {
                pickupDetails.innerHTML = `
                    <p><strong>Address:</strong> ${selectedOption.dataset.address || 'Not specified'}</p>
                    <p><strong>Hours:</strong> ${selectedOption.dataset.hours || 'Not specified'}</p>
                    <p><strong>Contact:</strong> ${selectedOption.dataset.contact || 'Not specified'} (${selectedOption.dataset.phone || 'Not specified'})</p>
                    ${selectedOption.dataset.instructions ? `<p><strong>Instructions:</strong> ${selectedOption.dataset.instructions}</p>` : ''}
                `;
                pickupInfo.style.display = 'block';
                orderPickup.disabled = false;
            } else {
                pickupInfo.style.display = 'none';
                orderPickup.disabled = true;
                document.getElementById('order_delivery').checked = true;
            }
        }

        function addProduct() {
            const container = document.getElementById('orderItems');

            const productRow = document.createElement('div');
            productRow.className = 'product-row';
            productRow.innerHTML = `
                <div class="product-info">
                    <select class="form-select" name="order_items[${itemIndex}][product_id]" onchange="updateProductInfo(this, ${itemIndex})" required>
                        <option value="">Choose a product...</option>
                        <?php
                        $stmt = $conn->query("SELECT id, name, sku, cost_price FROM products WHERE status = 'active' ORDER BY name ASC");
                        while ($product = $stmt->fetch(PDO::FETCH_ASSOC)) {
                            echo '<option value="' . $product['id'] . '" data-price="' . $product['cost_price'] . '">' . htmlspecialchars($product['name'] . ' (' . $product['sku'] . ')') . '</option>';
                        }
                        ?>
                    </select>
                </div>

                <div class="quantity-controls">
                    <label class="form-label">Quantity</label>
                    <input type="number" class="form-control quantity-input"
                           name="order_items[${itemIndex}][quantity]" min="1" max="1000"
                           placeholder="Qty" onchange="updateSummary()">
                </div>

                <div class="unit-price">
                    <label class="form-label">Unit Price</label>
                    <div class="input-group">
                        <span class="input-group-text"><?php echo getCurrencySymbol($settings); ?></span>
                        <input type="number" class="form-control" step="0.01" min="0"
                               name="order_items[${itemIndex}][unit_price]" readonly>
                    </div>
                </div>

                <button type="button" class="btn btn-outline-danger" onclick="removeItem(this)">
                    <i class="bi bi-trash"></i>
                </button>
            `;

            container.appendChild(productRow);
            itemIndex++;
        }

        function updateProductInfo(select, index) {
            const selectedOption = select.options[select.selectedIndex];
            const priceInput = select.closest('.product-row').querySelector(`input[name="order_items[${index}][unit_price]"]`);

            if (selectedOption.value) {
                priceInput.value = selectedOption.dataset.price || '0.00';
            } else {
                priceInput.value = '0.00';
            }

            updateSummary();
        }

        function removeItem(button) {
            button.closest('.product-row').remove();
            updateSummary();
        }

        function updateSummary() {
            const quantities = document.querySelectorAll('input[name*="[quantity]"]');
            const prices = document.querySelectorAll('input[name*="[unit_price]"]');

            let itemCount = 0;
            let totalQuantity = 0;
            let estimatedTotal = 0;

            quantities.forEach((qtyInput, index) => {
                const quantity = parseFloat(qtyInput.value) || 0;
                const priceInput = prices[index];
                const price = parseFloat(priceInput ? priceInput.value : 0) || 0;

                if (quantity > 0) {
                    itemCount++;
                    totalQuantity += quantity;
                    estimatedTotal += quantity * price;
                }
            });

            document.getElementById('itemCount').textContent = itemCount;
            document.getElementById('totalQuantity').textContent = totalQuantity;
            document.getElementById('estimatedTotal').textContent = '<?php echo formatCurrency(0, $settings); ?>'.replace('0.00', estimatedTotal.toFixed(2));
        }

        // Update summary on quantity changes
        document.addEventListener('input', function(e) {
            if (e.target.name && e.target.name.includes('[quantity]')) {
                updateSummary();
            }
        });
    </script>
</body>
</html>
