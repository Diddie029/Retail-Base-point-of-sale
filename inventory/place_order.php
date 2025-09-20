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

// Function to generate order number
function generateOrderNumber($settings) {
    $autoGenerate = isset($settings['auto_generate_order_number']) && $settings['auto_generate_order_number'] == '1';

    if (!$autoGenerate) {
        return 'ORD-' . date('YmdHis') . '-' . str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
    }

    $prefix = $settings['order_number_prefix'] ?? 'ORD';
    $length = intval($settings['order_number_length'] ?? 6);
    $separator = $settings['order_number_separator'] ?? '-';
    $format = $settings['order_number_format'] ?? 'prefix-date-number';

    $sequentialNumber = getNextOrderNumber($length);
    $currentDate = date('Ymd');

    switch ($format) {
        case 'prefix-date-number':
            return $prefix . $separator . $currentDate . $separator . $sequentialNumber;
        case 'prefix-number':
            return $prefix . $separator . $sequentialNumber;
        case 'date-number':
            return $currentDate . $separator . $sequentialNumber;
        case 'number-only':
            return $sequentialNumber;
        default:
            return $prefix . $separator . $currentDate . $separator . $sequentialNumber;
    }
}

function getNextOrderNumber($length) {
    global $conn;

    try {
        // Get the highest order number from all orders
        $stmt = $conn->prepare("
            SELECT order_number
            FROM inventory_orders
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute();
        $lastOrder = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($lastOrder) {
            $lastNumber = intval(preg_replace('/[^0-9]/', '', $lastOrder['order_number']));
            return str_pad($lastNumber + 1, $length, '0', STR_PAD_LEFT);
        } else {
            return str_pad(1, $length, '0', STR_PAD_LEFT);
        }
    } catch (PDOException $e) {
        error_log("Error getting next order number: " . $e->getMessage());
        return str_pad(1, $length, '0', STR_PAD_LEFT);
    }
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
                INSERT INTO inventory_orders (
                    order_number, supplier_id, user_id, order_date, status,
                    notes, created_at, updated_at
                ) VALUES (
                    :order_number, :supplier_id, :user_id, CURDATE(), 'pending',
                    :special_instructions, NOW(), NOW()
                )
            ");

            $stmt->bindParam(':order_number', $order_number);
            $stmt->bindParam(':supplier_id', $supplier_id);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->bindParam(':special_instructions', $special_instructions);
            $stmt->execute();

            $order_id = $conn->lastInsertId();

            // Insert order items
            $stmt = $conn->prepare("
                INSERT INTO inventory_order_items (
                    order_id, product_id, quantity, cost_price, total_amount, created_at
                ) VALUES (
                    :order_id, :product_id, :quantity, :unit_price, :total_amount, NOW()
                )
            ");

            foreach ($order_items as $item) {
                if (!empty($item['product_id']) && !empty($item['quantity']) && $item['quantity'] > 0) {
                    $quantity = intval($item['quantity']);
                    $unit_price = floatval($item['unit_price'] ?? 0);
                    $total_amount = $quantity * $unit_price;
                    
                    $stmt->bindParam(':order_id', $order_id);
                    $stmt->bindParam(':product_id', $item['product_id']);
                    $stmt->bindParam(':quantity', $quantity);
                    $stmt->bindParam(':unit_price', $unit_price);
                    $stmt->bindParam(':total_amount', $total_amount);
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
                $success .= "Order placed with supplier for delivery.";
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

        .search-results {
            position: relative;
            z-index: 1000;
        }

        .search-results .list-group {
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
        }

        .search-results .list-group-item {
            cursor: pointer;
            border: none;
            border-bottom: 1px solid #dee2e6;
        }

        .search-results .list-group-item:hover {
            background-color: #f8f9fa;
        }

        .search-results .list-group-item:last-child {
            border-bottom: none;
        }

        .product-search-section {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 1rem;
        }

        .low-stock-item {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 0.5rem;
        }

        .low-stock-item:hover {
            background: #fff8e1;
        }

        .search-results-list {
            max-height: 400px;
            overflow-y: auto;
        }

        .search-result-item {
            transition: all 0.2s ease;
            cursor: pointer;
        }

        .search-result-item:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .product-name {
            color: #212529;
            font-weight: 600;
        }

        .search-results .list-group-item {
            border-left: 3px solid transparent;
        }

        .search-results .list-group-item:hover {
            border-left-color: #0d6efd;
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

                <!-- Order Items - Only show after supplier selection -->
                <div class="order-card" id="orderItemsSection" style="display: none;">
                    <h4 class="mb-3">
                        <i class="bi bi-cart-plus me-2"></i>Order Items
                    </h4>

                    <!-- Product Search and Selection -->
                    <div class="product-search-section mb-4">
                        <div class="row">
                            <div class="col-md-8">
                                <div class="input-group">
                                    <input type="text" class="form-control" id="productSearchInput" 
                                           placeholder="Search products by name, SKU, or barcode..." 
                                           onkeyup="searchProducts()" autocomplete="off">
                                    <button class="btn btn-outline-secondary" type="button" onclick="clearSearch()">
                                        <i class="bi bi-x-circle"></i>
                                    </button>
                                </div>
                                <div id="productSearchResults" class="search-results mt-2" style="display: none;"></div>
                            </div>
                            <div class="col-md-4">
                                <div class="d-flex gap-2">
                                    <button type="button" class="btn btn-outline-success" onclick="toggleBarcodeScanner()">
                                        <i class="bi bi-upc-scan me-2"></i>Scan Barcode
                                    </button>
                                    <button type="button" class="btn btn-outline-info" onclick="showLowStockProducts()">
                                        <i class="bi bi-exclamation-triangle me-2"></i>Low Stock
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Barcode Scanner Input -->
                        <div id="barcodeScanner" class="card mt-3" style="display: none;">
                            <div class="card-body">
                                <h6 class="card-title">
                                    <i class="bi bi-upc-scan me-2"></i>Barcode Scanner
                                </h6>
                                <div class="input-group">
                                    <input type="text" class="form-control" id="barcodeInput" 
                                           placeholder="Scan or enter barcode..." 
                                           onkeypress="handleBarcodeInput(event)">
                                    <button class="btn btn-success" type="button" onclick="scanBarcode()">
                                        <i class="bi bi-search"></i> Search
                                    </button>
                                </div>
                                <small class="text-muted">Press Enter after scanning or entering barcode</small>
                            </div>
                        </div>
                    </div>

                    <!-- Selected Order Items -->
                    <div id="orderItems">
                        <!-- Order items will be added here -->
                    </div>

                    <!-- Low Stock Products (Hidden by default) -->
                    <div id="lowStockProducts" style="display: none;">
                            <div class="alert alert-warning">
                                <strong>Recommended items (Low Stock):</strong>
                            </div>
                        <?php if (!empty($low_stock_products)): ?>
                            <?php foreach ($low_stock_products as $index => $product): ?>
                                <div class="product-row low-stock-item" data-product-id="<?php echo $product['id']; ?>">
                                    <div class="product-info">
                                        <strong><?php echo htmlspecialchars($product['name']); ?></strong>
                                        <br><small class="text-muted">
                                            <?php if (!empty($product['sku'])): ?>
                                                <span class="badge bg-primary me-1">SKU: <?php echo htmlspecialchars($product['sku']); ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($product['barcode'])): ?>
                                                <span class="badge bg-info me-1">Barcode: <?php echo htmlspecialchars($product['barcode']); ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($product['product_number'])): ?>
                                                <span class="badge bg-secondary me-1">#<?php echo htmlspecialchars($product['product_number']); ?></span>
                                            <?php endif; ?>
                                            <span class="text-muted">| Stock: <?php echo $product['quantity']; ?></span>
                                        </small>
                                    </div>

                                    <div class="quantity-controls">
                                        <label class="form-label">Quantity</label>
                                        <input type="number" class="form-control quantity-input" min="1" max="1000"
                                               placeholder="Qty" value="">
                                    </div>

                                    <div class="unit-price">
                                        <label class="form-label">Unit Price</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><?php echo getCurrencySymbol($settings); ?></span>
                                            <input type="number" class="form-control" step="0.01" min="0"
                                                   value="<?php echo number_format($product['cost_price'], 2); ?>" readonly>
                                        </div>
                                    </div>

                                    <button type="button" class="btn btn-success" onclick="addLowStockProduct(this)">
                                        <i class="bi bi-plus-circle"></i> Add
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
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
            const orderItemsSection = document.getElementById('orderItemsSection');

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

            // Show order items section when supplier is selected
            if (selectedOption.value) {
                orderItemsSection.style.display = 'block';
                // Load products for the selected supplier
                loadSupplierProducts(selectedOption.value);
            } else {
                orderItemsSection.style.display = 'none';
            }
        }

        // Load products for selected supplier
        function loadSupplierProducts(supplierId) {
            // This will be used to filter products by supplier
            window.currentSupplierId = supplierId;
        }

        // Product search functionality
        let searchTimeout;
        let currentSearchRequest = null;
        
        function searchProducts() {
            clearTimeout(searchTimeout);
            const searchTerm = document.getElementById('productSearchInput').value.trim();
            const resultsDiv = document.getElementById('productSearchResults');
            
            if (searchTerm.length < 2) {
                resultsDiv.style.display = 'none';
                return;
            }

            // Cancel previous request if still pending
            if (currentSearchRequest) {
                currentSearchRequest.abort();
            }

            searchTimeout = setTimeout(() => {
                const supplierId = document.getElementById('supplier_id').value;
                if (!supplierId) {
                    resultsDiv.innerHTML = '<div class="alert alert-warning">Please select a supplier first</div>';
                    resultsDiv.style.display = 'block';
                    return;
                }

                // Show loading state
                resultsDiv.innerHTML = '<div class="alert alert-info"><i class="bi bi-hourglass-split me-2"></i>Searching products...</div>';
                resultsDiv.style.display = 'block';

                // Create abort controller for request cancellation
                const controller = new AbortController();
                currentSearchRequest = controller;

                fetch(`../api/search_products.php?search=${encodeURIComponent(searchTerm)}&supplier_id=${supplierId}`, {
                    signal: controller.signal
                })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok');
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success && data.suggestions.length > 0) {
                            displaySearchResults(data.suggestions);
                        } else {
                            resultsDiv.innerHTML = '<div class="alert alert-info"><i class="bi bi-search me-2"></i>No products found</div>';
                            resultsDiv.style.display = 'block';
                        }
                    })
                    .catch(error => {
                        if (error.name !== 'AbortError') {
                            console.error('Search error:', error);
                            resultsDiv.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>Error searching products</div>';
                            resultsDiv.style.display = 'block';
                        }
                    })
                    .finally(() => {
                        currentSearchRequest = null;
                    });
            }, 200); // Reduced timeout for faster response
        }

        function displaySearchResults(products) {
            const resultsDiv = document.getElementById('productSearchResults');
            let html = '<div class="list-group search-results-list">';
            
            products.forEach((product, index) => {
                const identifiers = [];
                if (product.sku) identifiers.push(`SKU: ${product.sku}`);
                if (product.barcode) identifiers.push(`Barcode: ${product.barcode}`);
                
                html += `
                    <div class="list-group-item list-group-item-action search-result-item" 
                         data-index="${index}"
                         onclick="addProductFromSearch(${product.id}, '${product.name.replace(/'/g, "\\'")}', '${product.sku || ''}', '${product.barcode || ''}', ${product.cost_price}, ${product.quantity})"
                         onmouseover="highlightSearchResult(this)"
                         onmouseout="unhighlightSearchResult(this)">
                        <div class="d-flex w-100 justify-content-between align-items-start">
                            <div class="flex-grow-1">
                                <h6 class="mb-1 product-name">${product.name}</h6>
                                <div class="mb-1">
                                    ${identifiers.map(id => `<span class="badge bg-primary me-1">${id}</span>`).join('')}
                                    <span class="badge bg-${product.stock_status === 'low' ? 'warning' : 'success'}">Stock: ${product.quantity}</span>
                                </div>
                                <small class="text-muted">
                                    ${product.category_name || ''} ${product.supplier_name ? '| ' + product.supplier_name : ''}
                                </small>
                            </div>
                            <div class="text-end">
                                <div class="fw-bold text-success">$${product.cost_price}</div>
                                <button class="btn btn-sm btn-outline-success" onclick="event.stopPropagation(); addProductFromSearch(${product.id}, '${product.name.replace(/'/g, "\\'")}', '${product.sku || ''}', '${product.barcode || ''}', ${product.cost_price}, ${product.quantity})">
                                    <i class="bi bi-plus-circle"></i> Add
                                </button>
                            </div>
                        </div>
                    </div>
                `;
            });
            
            html += '</div>';
            resultsDiv.innerHTML = html;
            resultsDiv.style.display = 'block';
        }

        function highlightSearchResult(element) {
            element.style.backgroundColor = '#f8f9fa';
            element.style.borderColor = '#0d6efd';
        }

        function unhighlightSearchResult(element) {
            element.style.backgroundColor = '';
            element.style.borderColor = '';
        }

        function clearSearch() {
            document.getElementById('productSearchInput').value = '';
            document.getElementById('productSearchResults').style.display = 'none';
        }

        function addProductFromSearch(productId, productName, sku, barcode, costPrice, quantity) {
            // Check if product is already in the order
            const existingItems = document.querySelectorAll('input[name*="[product_id]"]');
            for (let item of existingItems) {
                if (item.value == productId) {
                    alert('Product is already in the order');
                    return;
                }
            }

            addProductToOrder(productId, productName, sku, barcode, costPrice, quantity);
            clearSearch();
        }

        function addLowStockProduct(button) {
            const productRow = button.closest('.low-stock-item');
            const productId = productRow.dataset.productId;
            const productName = productRow.querySelector('strong').textContent;
            const sku = productRow.querySelector('.badge.bg-primary')?.textContent.replace('SKU: ', '') || '';
            const barcode = productRow.querySelector('.badge.bg-info')?.textContent.replace('Barcode: ', '') || '';
            const costPrice = productRow.querySelector('input[type="number"]').value;
            const quantity = productRow.querySelector('.quantity-input').value || 1;

            if (!quantity || quantity < 1) {
                alert('Please enter a valid quantity');
                return;
            }

            addProductToOrder(productId, productName, sku, barcode, costPrice, quantity);
        }

        function addProductToOrder(productId, productName, sku, barcode, costPrice, currentStock) {
            const container = document.getElementById('orderItems');
            const productRow = document.createElement('div');
            productRow.className = 'product-row';
            
            const identifiers = [];
            if (sku) identifiers.push(`SKU: ${sku}`);
            if (barcode) identifiers.push(`Barcode: ${barcode}`);
            
            productRow.innerHTML = `
                <div class="product-info">
                    <strong>${productName}</strong>
                    <br><small class="text-muted">
                        ${identifiers.map(id => `<span class="badge bg-primary me-1">${id}</span>`).join('')}
                        <span class="text-muted">| Stock: ${currentStock}</span>
                    </small>
                </div>

                <div class="quantity-controls">
                    <label class="form-label">Quantity</label>
                    <input type="number" class="form-control quantity-input"
                           name="order_items[${itemIndex}][quantity]" min="1" max="1000"
                           placeholder="Qty" onchange="updateSummary()" value="1">
                </div>

                <div class="unit-price">
                    <label class="form-label">Unit Price</label>
                    <div class="input-group">
                        <span class="input-group-text"><?php echo getCurrencySymbol($settings); ?></span>
                        <input type="number" class="form-control" step="0.01" min="0"
                               name="order_items[${itemIndex}][unit_price]" value="${costPrice}" readonly>
                    </div>
                </div>

                <button type="button" class="btn btn-outline-danger" onclick="removeItem(this)">
                    <i class="bi bi-trash"></i>
                </button>
            `;

            // Add hidden input for product_id
            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = `order_items[${itemIndex}][product_id]`;
            hiddenInput.value = productId;
            productRow.appendChild(hiddenInput);

            container.appendChild(productRow);
            itemIndex++;
            updateSummary();
        }

        function showLowStockProducts() {
            const lowStockDiv = document.getElementById('lowStockProducts');
            if (lowStockDiv.style.display === 'none') {
                lowStockDiv.style.display = 'block';
            } else {
                lowStockDiv.style.display = 'none';
            }
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

        // Prevent form submission on Enter key in search input
        document.addEventListener('keydown', function(e) {
            if (e.target.id === 'productSearchInput' && e.key === 'Enter') {
                e.preventDefault();
                return false;
            }
        });

        // Close search results when clicking outside
        document.addEventListener('click', function(e) {
            const searchContainer = document.querySelector('.product-search-section');
            const searchResults = document.getElementById('productSearchResults');
            
            if (searchContainer && !searchContainer.contains(e.target) && searchResults) {
                searchResults.style.display = 'none';
            }
        });

        // Initialize search input behavior
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('productSearchInput');
            if (searchInput) {
                // Clear search when supplier changes
                const supplierSelect = document.getElementById('supplier_id');
                if (supplierSelect) {
                    supplierSelect.addEventListener('change', function() {
                        clearSearch();
                    });
                }
            }
        });

        // Barcode scanner functionality
        function toggleBarcodeScanner() {
            const scanner = document.getElementById('barcodeScanner');
            const input = document.getElementById('barcodeInput');
            
            if (scanner.style.display === 'none') {
                scanner.style.display = 'block';
                input.focus();
            } else {
                scanner.style.display = 'none';
                input.value = '';
            }
        }

        function handleBarcodeInput(event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                scanBarcode();
            }
        }

        function scanBarcode() {
            const barcode = document.getElementById('barcodeInput').value.trim();
            
            if (!barcode) {
                alert('Please enter a barcode');
                return;
            }

            // Show loading state
            const searchBtn = document.querySelector('#barcodeScanner .btn');
            const originalText = searchBtn.innerHTML;
            searchBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Searching...';
            searchBtn.disabled = true;

            // Call the barcode scan API
            fetch(`../api/scan_barcode.php?barcode=${encodeURIComponent(barcode)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        if (data.exact_match) {
                            // Add the product directly
                            addProductFromBarcode(data.product);
                        } else {
                            // Show multiple matches for selection
                            showBarcodeMatches(data.products, barcode);
                        }
                    } else {
                        alert('Product not found: ' + data.error);
                    }
                })
                .catch(error => {
                    console.error('Barcode scan error:', error);
                    alert('Error scanning barcode. Please try again.');
                })
                .finally(() => {
                    // Reset button state
                    searchBtn.innerHTML = originalText;
                    searchBtn.disabled = false;
                    document.getElementById('barcodeInput').value = '';
                });
        }

        function addProductFromBarcode(product) {
            // Check if product is already in the order
            const existingItems = document.querySelectorAll('input[name*="[product_id]"]');
            for (let item of existingItems) {
                if (item.value == product.id) {
                    alert('Product is already in the order');
                    return;
                }
            }

            // Add the product to the order
            const container = document.getElementById('orderItems');
            const productRow = document.createElement('div');
            productRow.className = 'product-row';
            
            const identifiers = [];
            if (product.sku) identifiers.push('SKU: ' + product.sku);
            if (product.barcode) identifiers.push('Barcode: ' + product.barcode);
            
            productRow.innerHTML = `
                <div class="product-info">
                    <strong>${product.name}</strong>
                    <br><small class="text-muted">
                        ${identifiers.map(id => `<span class="badge bg-primary me-1">${id}</span>`).join('')}
                        <span class="text-muted">| Stock: ${product.quantity}</span>
                    </small>
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
                               name="order_items[${itemIndex}][unit_price]" value="${product.price}" readonly>
                    </div>
                </div>

                <button type="button" class="btn btn-outline-danger" onclick="removeItem(this)">
                    <i class="bi bi-trash"></i>
                </button>
            `;

            // Add hidden input for product_id
            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = `order_items[${itemIndex}][product_id]`;
            hiddenInput.value = product.id;
            productRow.appendChild(hiddenInput);

            container.appendChild(productRow);
            itemIndex++;
            updateSummary();
            
            // Hide barcode scanner
            document.getElementById('barcodeScanner').style.display = 'none';
        }

        function showBarcodeMatches(products, barcode) {
            let message = `Multiple products found for barcode "${barcode}":\n\n`;
            products.forEach((product, index) => {
                message += `${index + 1}. ${product.name} (${product.sku || 'No SKU'})\n`;
            });
            message += '\nPlease use the "Add Another Product" dropdown to select the correct one.';
            
            alert(message);
        }
    </script>
</body>
</html>
