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

// Handle AJAX requests for Auto BOM functionality
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        switch ($_GET['action']) {
            case 'get_auto_bom_units':
                $base_product_id = (int) ($_GET['base_product_id'] ?? 0);
                if (!$base_product_id) {
                    throw new Exception('Base product ID is required');
                }

                $selling_units = $auto_bom_manager->getAvailableSellingUnits($base_product_id);

                // Calculate prices for each unit
                foreach ($selling_units as &$unit) {
                    try {
                        $unit['calculated_price'] = $auto_bom_manager->calculateSellingUnitPrice($unit['id']);
                        $unit['formatted_price'] = formatCurrency($unit['calculated_price'], $settings);
                    } catch (Exception $e) {
                        $unit['calculated_price'] = null;
                        $unit['formatted_price'] = 'Price unavailable';
                    }
                }

                echo json_encode([
                    'success' => true,
                    'selling_units' => array_values($selling_units)
                ]);
                break;

            case 'check_inventory':
                $product_id = (int) ($_GET['product_id'] ?? 0);
                $quantity = (float) ($_GET['quantity'] ?? 0);
                $selling_unit_id = isset($_GET['selling_unit_id']) ? (int) $_GET['selling_unit_id'] : null;

                if (!$product_id || !$quantity) {
                    throw new Exception('Product ID and quantity are required');
                }

                $inventory_check = $auto_bom_manager->checkBaseStockAvailability($product_id, $quantity, $selling_unit_id);

                echo json_encode([
                    'success' => true,
                    'inventory_check' => $inventory_check
                ]);
                break;

            default:
                throw new Exception('Invalid action');
        }
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit();
}

// Get products for POS (both regular and Auto BOM enabled)
$products = [];
$stmt = $conn->query("
    SELECT p.*, c.name as category_name,
           p.is_auto_bom_enabled, p.auto_bom_type,
           COUNT(CASE WHEN su.status = 'active' THEN 1 END) as selling_units_count
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN auto_bom_configs abc ON p.id = abc.product_id
    LEFT JOIN auto_bom_selling_units su ON abc.id = su.auto_bom_config_id
    WHERE p.status = 'active'
    GROUP BY p.id
    ORDER BY p.name ASC
");
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get categories for filtering
$categories = [];
$stmt = $conn->query("SELECT id, name FROM categories WHERE status = 'active' ORDER BY name");
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Point of Sale - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }

        .pos-container {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            height: calc(100vh - 200px);
        }

        .products-section {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow-y: auto;
        }

        .cart-section {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: flex;
            flex-direction: column;
        }

        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }

        .product-card {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }

        .product-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            border-color: var(--primary-color);
        }

        .product-card.selected {
            border-color: var(--primary-color);
            background: #f0f9ff;
        }

        .product-name {
            font-weight: 600;
            margin-bottom: 8px;
            color: #1e293b;
        }

        .product-price {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--primary-color);
        }

        .product-stock {
            font-size: 0.8rem;
            color: #64748b;
            margin-top: 4px;
        }

        .auto-bom-indicator {
            position: absolute;
            top: 10px;
            right: 10px;
            background: #06b6d4;
            color: white;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .cart-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
        }

        .cart-item-info {
            flex: 1;
        }

        .cart-item-name {
            font-weight: 500;
            margin-bottom: 4px;
        }

        .cart-item-details {
            font-size: 0.8rem;
            color: #64748b;
        }

        .cart-item-controls {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .quantity-controls {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .quantity-btn {
            width: 24px;
            height: 24px;
            border: 1px solid #d1d5db;
            background: white;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 12px;
        }

        .quantity-btn:hover {
            background: #f3f4f6;
        }

        .cart-total {
            border-top: 2px solid #e2e8f0;
            padding-top: 15px;
            margin-top: auto;
        }

        .total-amount {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
        }

        .selling-units-modal .modal-dialog {
            max-width: 600px;
        }

        .selling-unit-option {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .selling-unit-option:hover {
            border-color: var(--primary-color);
            background: #f0f9ff;
        }

        .selling-unit-option.selected {
            border-color: var(--primary-color);
            background: #e0f2fe;
        }

        .unit-name {
            font-weight: 600;
            margin-bottom: 4px;
        }

        .unit-details {
            font-size: 0.8rem;
            color: #64748b;
        }

        .unit-price {
            font-weight: 700;
            color: var(--primary-color);
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include '../include/navmenu.php'; ?>

        <main class="main-content">
            <div class="container-fluid">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1 class="h3 mb-0">Point of Sale</h1>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-secondary" id="clearCart">
                            <i class="bi bi-trash"></i> Clear Cart
                        </button>
                        <button type="button" class="btn btn-primary" id="checkoutBtn" disabled>
                            <i class="bi bi-credit-card"></i> Checkout
                        </button>
                    </div>
                </div>

                <div class="pos-container">
                    <!-- Products Section -->
                    <div class="products-section">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="mb-0">Products</h5>
                            <div class="d-flex gap-2">
                                <select class="form-select form-select-sm" id="categoryFilter">
                                    <option value="">All Categories</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="text" class="form-control form-control-sm" id="productSearch" placeholder="Search products...">
                            </div>
                        </div>

                        <div class="product-grid" id="productGrid">
                            <?php foreach ($products as $product): ?>
                                <div class="product-card"
                                     data-product-id="<?php echo $product['id']; ?>"
                                     data-product-name="<?php echo htmlspecialchars($product['name']); ?>"
                                     data-product-price="<?php echo $product['price']; ?>"
                                     data-product-stock="<?php echo $product['quantity']; ?>"
                                     data-is-auto-bom="<?php echo $product['is_auto_bom_enabled'] ? 'true' : 'false'; ?>"
                                     data-selling-units-count="<?php echo $product['selling_units_count']; ?>">
                                    <?php if ($product['is_auto_bom_enabled']): ?>
                                        <div class="auto-bom-indicator">
                                            <i class="bi bi-gear"></i> Auto BOM
                                        </div>
                                    <?php endif; ?>
                                    <div class="product-name"><?php echo htmlspecialchars($product['name']); ?></div>
                                    <div class="product-price"><?php echo $settings['currency_symbol']; ?> <?php echo number_format($product['price'], 2); ?></div>
                                    <div class="product-stock">Stock: <?php echo $product['quantity']; ?></div>
                                    <?php if ($product['is_auto_bom_enabled']): ?>
                                        <div class="product-stock" style="color: #06b6d4;">
                                            <?php echo $product['selling_units_count']; ?> selling units available
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Cart Section -->
                    <div class="cart-section">
                        <h5 class="mb-3">Current Sale</h5>

                        <div id="cartItems" class="flex-grow-1">
                            <!-- Cart items will be populated here -->
                            <div class="text-center text-muted mt-5">
                                <i class="bi bi-cart" style="font-size: 3rem;"></i>
                                <p class="mt-2">No items in cart</p>
                            </div>
                        </div>

                        <div class="cart-total">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <span class="fw-bold">Total:</span>
                                <span class="total-amount" id="cartTotal"><?php echo $settings['currency_symbol']; ?> 0.00</span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <span class="text-muted">Items:</span>
                                <span id="cartItemCount">0</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Auto BOM Selling Units Modal -->
    <div class="modal fade selling-units-modal" id="sellingUnitsModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Select Selling Unit</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="sellingUnitsList">
                        <!-- Selling units will be loaded here -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="confirmSellingUnit">Add to Cart</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/dashboard.js"></script>

    <script>
        // POS functionality
        document.addEventListener('DOMContentLoaded', function() {
            let cart = [];
            let selectedProductForModal = null;
            const currencySymbol = '<?php echo $settings['currency_symbol']; ?>';

            // Product search and filtering
            const productSearch = document.getElementById('productSearch');
            const categoryFilter = document.getElementById('categoryFilter');
            const productGrid = document.getElementById('productGrid');

            function filterProducts() {
                const searchTerm = productSearch.value.toLowerCase();
                const categoryId = categoryFilter.value;
                const productCards = productGrid.querySelectorAll('.product-card');

                productCards.forEach(card => {
                    const productName = card.dataset.productName.toLowerCase();
                    const productCategory = card.closest('.product-card')?.dataset?.categoryId || '';
                    const matchesSearch = productName.includes(searchTerm);
                    const matchesCategory = !categoryId || productCategory === categoryId;

                    card.style.display = matchesSearch && matchesCategory ? 'block' : 'none';
                });
            }

            productSearch.addEventListener('input', filterProducts);
            categoryFilter.addEventListener('change', filterProducts);

            // Product selection
            productGrid.addEventListener('click', function(e) {
                const productCard = e.target.closest('.product-card');
                if (!productCard) return;

                const productId = productCard.dataset.productId;
                const productName = productCard.dataset.productName;
                const productPrice = parseFloat(productCard.dataset.productPrice);
                const productStock = parseInt(productCard.dataset.productStock);
                const isAutoBom = productCard.dataset.isAutoBom === 'true';

                if (isAutoBom) {
                    // Show Auto BOM selling units modal
                    showSellingUnitsModal(productId, productName);
                } else {
                    // Add regular product to cart
                    addToCart({
                        id: productId,
                        name: productName,
                        price: productPrice,
                        quantity: 1,
                        stock: productStock,
                        is_auto_bom: false
                    });
                }
            });

            // Auto BOM selling units modal
            const sellingUnitsModal = new bootstrap.Modal(document.getElementById('sellingUnitsModal'));

            function showSellingUnitsModal(productId, productName) {
                selectedProductForModal = { id: productId, name: productName };

                // Load selling units
                fetch(`?action=get_auto_bom_units&base_product_id=${productId}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            displaySellingUnits(data.selling_units);
                            sellingUnitsModal.show();
                        } else {
                            alert('Error loading selling units: ' + data.error);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('Error loading selling units');
                    });
            }

            function displaySellingUnits(sellingUnits) {
                const container = document.getElementById('sellingUnitsList');
                let html = '';

                if (sellingUnits.length === 0) {
                    html = '<div class="text-center text-muted"><p>No selling units available</p></div>';
                } else {
                    sellingUnits.forEach(unit => {
                        html += `
                            <div class="selling-unit-option" data-unit-id="${unit.id}" data-unit-price="${unit.calculated_price || unit.fixed_price}">
                                <div class="unit-name">${unit.unit_name} (${unit.unit_quantity} units)</div>
                                <div class="unit-details">
                                    SKU: ${unit.unit_sku || 'N/A'} |
                                    Price: ${currencySymbol} ${parseFloat(unit.calculated_price || unit.fixed_price).toFixed(2)}
                                </div>
                            </div>
                        `;
                    });
                }

                container.innerHTML = html;

                // Add click handlers for unit selection
                container.querySelectorAll('.selling-unit-option').forEach(option => {
                    option.addEventListener('click', function() {
                        container.querySelectorAll('.selling-unit-option').forEach(opt => opt.classList.remove('selected'));
                        this.classList.add('selected');
                    });
                });
            }

            // Confirm selling unit selection
            document.getElementById('confirmSellingUnit').addEventListener('click', function() {
                const selectedUnit = document.querySelector('.selling-unit-option.selected');
                if (!selectedUnit || !selectedProductForModal) return;

                const unitId = selectedUnit.dataset.unitId;
                const unitPrice = parseFloat(selectedUnit.dataset.unitPrice);
                const unitName = selectedUnit.querySelector('.unit-name').textContent;

                addToCart({
                    id: selectedProductForModal.id + '_' + unitId, // Create unique ID
                    name: selectedProductForModal.name + ' - ' + unitName,
                    price: unitPrice,
                    quantity: 1,
                    stock: 999, // Auto BOM units don't have traditional stock limits
                    is_auto_bom: true,
                    selling_unit_id: unitId,
                    base_product_id: selectedProductForModal.id
                });

                sellingUnitsModal.hide();
                selectedProductForModal = null;
            });

            // Cart management
            function addToCart(product) {
                const existingItem = cart.find(item => item.id === product.id);

                if (existingItem) {
                    existingItem.quantity += product.quantity;
                } else {
                    cart.push(product);
                }

                updateCartDisplay();
            }

            function removeFromCart(productId) {
                cart = cart.filter(item => item.id !== productId);
                updateCartDisplay();
            }

            function updateCartQuantity(productId, newQuantity) {
                const item = cart.find(item => item.id === productId);
                if (item) {
                    item.quantity = Math.max(1, newQuantity);
                    updateCartDisplay();
                }
            }

            function updateCartDisplay() {
                const cartContainer = document.getElementById('cartItems');
                const cartTotal = document.getElementById('cartTotal');
                const cartItemCount = document.getElementById('cartItemCount');
                const checkoutBtn = document.getElementById('checkoutBtn');

                if (cart.length === 0) {
                    cartContainer.innerHTML = `
                        <div class="text-center text-muted mt-5">
                            <i class="bi bi-cart" style="font-size: 3rem;"></i>
                            <p class="mt-2">No items in cart</p>
                        </div>
                    `;
                    cartTotal.textContent = currencySymbol + ' 0.00';
                    cartItemCount.textContent = '0';
                    checkoutBtn.disabled = true;
                    return;
                }

                checkoutBtn.disabled = false;

                let html = '';
                let total = 0;
                let itemCount = 0;

                cart.forEach(item => {
                    const itemTotal = item.price * item.quantity;
                    total += itemTotal;
                    itemCount += item.quantity;

                    html += `
                        <div class="cart-item">
                            <div class="cart-item-info">
                                <div class="cart-item-name">${item.name}</div>
                                <div class="cart-item-details">
                                    ${currencySymbol} ${item.price.toFixed(2)} each
                                    ${item.is_auto_bom ? ' <span class="badge bg-info">Auto BOM</span>' : ''}
                                </div>
                            </div>
                            <div class="cart-item-controls">
                                <div class="quantity-controls">
                                    <button class="quantity-btn" onclick="updateCartQuantity('${item.id}', ${item.quantity - 1})">-</button>
                                    <span class="mx-2">${item.quantity}</span>
                                    <button class="quantity-btn" onclick="updateCartQuantity('${item.id}', ${item.quantity + 1})">+</button>
                                </div>
                                <div class="ms-3">
                                    <strong>${currencySymbol} ${itemTotal.toFixed(2)}</strong>
                                </div>
                                <button class="btn btn-sm btn-outline-danger ms-2" onclick="removeFromCart('${item.id}')">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>
                    `;
                });

                cartContainer.innerHTML = html;
                cartTotal.textContent = currencySymbol + ' ' + total.toFixed(2);
                cartItemCount.textContent = itemCount;
            }

            // Clear cart
            document.getElementById('clearCart').addEventListener('click', function() {
                if (confirm('Are you sure you want to clear the cart?')) {
                    cart = [];
                    updateCartDisplay();
                }
            });

            // Checkout
            document.getElementById('checkoutBtn').addEventListener('click', function() {
                if (cart.length === 0) return;

                // Here you would typically submit the cart to a checkout process
                // For now, we'll just show an alert
                alert('Checkout functionality would be implemented here.\n\nCart contains ' + cart.length + ' items with total: ' + document.getElementById('cartTotal').textContent);

                // Clear cart after "checkout"
                cart = [];
                updateCartDisplay();
            });

            // Make functions global for onclick handlers
            window.removeFromCart = removeFromCart;
            window.updateCartQuantity = updateCartQuantity;
        });
    </script>
</body>
</html>
