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

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get products for the POS interface
$stmt = $conn->query("
    SELECT p.*, c.name as category_name 
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.status = 'active'
    ORDER BY p.name
");
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get categories
$stmt = $conn->query("SELECT * FROM categories WHERE status = 'active' ORDER BY name");
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle new sale request
if (isset($_GET['new_sale']) && $_GET['new_sale'] === 'true') {
    // Clear the cart for new sale
    unset($_SESSION['cart']);
}

// Initialize cart if not exists
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

$cart = $_SESSION['cart'];
$cart_count = count($cart);
$subtotal = 0;
$tax_rate = $settings['tax_rate'] ?? 16.0;

// Calculate cart totals
foreach ($cart as $item) {
    $subtotal += $item['price'] * $item['quantity'];
}
$tax_amount = $subtotal * ($tax_rate / 100);
$total_amount = $subtotal + $tax_amount;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enhanced POS - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/dashboard.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }

        .pos-container {
            height: 80vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .pos-sidebar {
            background: var(--sidebar-color);
            color: white;
            height: 80vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .pos-main {
            height: 80vh;
            overflow: hidden;
            background: #f8fafc;
            display: flex;
            flex-direction: column;
        }

        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 0.5rem;
            padding: 0.5rem;
            flex: 1;
            overflow-y: auto;
            max-height: calc(80vh - 200px);
        }

        .product-card {
            background: white;
            border-radius: 6px;
            padding: 0.5rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            cursor: pointer;
            border: 2px solid transparent;
            height: fit-content;
        }

        .product-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            border-color: var(--primary-color);
        }

        .product-card.selected {
            border-color: var(--primary-color);
            background: #f0f9ff;
        }

        .cart-container {
            background: white;
            border-radius: 6px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            height: calc(120vh - 1rem);
            margin: 0.25rem;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            position: relative;
        }

        .cart-container .cart-header {
            flex-shrink: 0;
        }

        .cart-container .cart-items {
            flex: 1;
            overflow-y: auto;
        }

        .cart-container .cart-totals {
            flex-shrink: 0;
        }

        .cart-container .cart-actions {
            flex-shrink: 0;
        }

        .cart-header {
            background: var(--primary-color);
            color: white;
            padding: 0.5rem;
            border-radius: 6px 6px 0 0;
        }

        .cart-items {
            flex: 1;
            overflow-y: auto;
            padding: 0.75rem;
            padding-bottom: 20px;
            min-height: 60px;
            display: flex;
            flex-direction: column;
        }

        .cart-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px solid #e5e7eb;
            min-height: 50px;
            flex-shrink: 0;
            transition: all 0.2s ease;
        }

        .cart-item:hover {
            background: #f8fafc;
            border-radius: 4px;
            margin: 0 -0.25rem;
            padding: 0.5rem 0.25rem;
        }

        .cart-item .product-name {
            color: #000000 !important;
            font-weight: 600;
        }

        .cart-item .product-price {
            color: #000000 !important;
        }

        .cart-item .product-number {
            color: #000000 !important;
            font-weight: bold;
            margin-right: 0.5rem;
        }

        .cart-item .product-sku {
            color: #4b5563 !important;
            font-size: 0.7rem;
            font-weight: 600;
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            border: 1px solid #cbd5e1;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        .cart-item .product-sku:hover {
            background: linear-gradient(135deg, #e2e8f0 0%, #cbd5e1 100%);
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .cart-item:last-child {
            border-bottom: none;
        }

        .cart-totals {
            padding: 0.5rem;
            border-top: 2px solid #e5e7eb;
            background: #f9fafb;
            flex-shrink: 0;
            position: absolute;
            bottom: 120px;
            left: 0;
            right: 0;
        }

        .cart-totals .fw-bold {
            color: #1f2937 !important;
            font-weight: 600 !important;
        }

        .cart-totals span {
            color: #374151 !important;
        }

        .cart-actions {
            padding: 0.5rem;
            border-top: 1px solid #e5e7eb;
            flex-shrink: 0;
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
        }

        .category-btn {
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.2);
            color: white;
            margin: 0.125rem;
            transition: all 0.3s ease;
            font-size: 0.8rem;
        }

        .category-btn:hover,
        .category-btn.active {
            background: white;
            color: var(--sidebar-color);
        }

        .search-box {
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.2);
            color: white;
        }

        .search-box::placeholder {
            color: rgba(255,255,255,0.7);
        }

        .quantity-controls {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .quantity-btn {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            border: 1px solid #d1d5db;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .quantity-btn:hover {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .quantity-display {
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            border: 2px solid #e2e8f0;
            border-radius: 6px;
            padding: 0.3rem 0.6rem;
            min-width: 45px;
            text-align: center;
            font-weight: bold;
            color: #1e293b !important;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .quantity-display:hover {
            border-color: var(--primary-color);
            background: linear-gradient(135deg, #ffffff 0%, #f0f9ff 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }

        .quantity-display:focus {
            outline: none;
            border-color: var(--primary-color);
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2), 0 4px 8px rgba(0, 0, 0, 0.15);
            transform: translateY(-1px);
        }

        .payment-btn {
            width: 100%;
            padding: 0.2rem;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .top-bar {
            background: var(--primary-color);
            color: white;
            padding: 0.3rem 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }

        .time-display {
            font-family: 'Courier New', monospace;
            font-size: 0.8rem;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .avatar {
            width: 25px;
            height: 25px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 0.7rem;
        }

        /* Custom scrollbar styling */
        .product-grid::-webkit-scrollbar {
            width: 4px;
        }

        .product-grid::-webkit-scrollbar-track {
            background: transparent;
        }

        .product-grid::-webkit-scrollbar-thumb {
            background: rgba(0,0,0,0.2);
            border-radius: 2px;
        }

        .product-grid::-webkit-scrollbar-thumb:hover {
            background: rgba(0,0,0,0.3);
        }

        /* Cart items scrollbar - more visible */
        .cart-items::-webkit-scrollbar {
            width: 6px;
        }

        .cart-items::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 3px;
        }

        .cart-items::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 3px;
        }

        .cart-items::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }

        /* For Firefox */
        .product-grid {
            scrollbar-width: thin;
            scrollbar-color: rgba(0,0,0,0.2) transparent;
        }

        .cart-items {
            scrollbar-width: thin;
            scrollbar-color: #c1c1c1 #f1f1f1;
        }
    </style>
</head>
<body>
    <!-- Top Bar -->
    <div class="top-bar">
        <div class="d-flex align-items-center">
            <h4 class="mb-0 me-3"><?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></h4>
            <span class="badge bg-light text-dark">Enhanced POS</span>
                    </div>
        <div class="time-display" id="currentTime"></div>
        <div class="user-info">
            <div class="avatar"><?php echo strtoupper(substr($username, 0, 1)); ?></div>
            <div>
                <div class="fw-bold"><?php echo htmlspecialchars($username); ?></div>
                <small class="opacity-75">Cashier</small>
                                </div>
                            </div>
                        </div>
                        
    <div class="pos-container">
        <div class="row g-0 h-100">
            <!-- Left Sidebar - Products -->
            <div class="col-md-8">
            <div class="pos-main">
                    <!-- Search and Categories -->
                    <div class="p-1 bg-white border-bottom flex-shrink-0">
                        <div class="row g-1">
                            <div class="col-md-6">
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                                    <input type="text" class="form-control" id="productSearch" placeholder="Search products...">
                                    </div>
                                </div>
                            <div class="col-md-6">
                                <div class="d-flex flex-wrap">
                                    <button class="btn btn-sm category-btn active" data-category="all">All</button>
                        <?php foreach ($categories as $category): ?>
                                        <button class="btn btn-sm category-btn" data-category="<?php echo $category['id']; ?>">
                                <?php echo htmlspecialchars($category['name']); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                                </div>
                    </div>
                </div>

                    <!-- Products Grid -->
                    <div class="product-grid" id="productGrid">
                        <?php foreach ($products as $product): ?>
                        <div class="product-card" data-product-id="<?php echo $product['id']; ?>" data-category-id="<?php echo $product['category_id']; ?>">
                            <div class="text-center">
                                <div class="mb-2">
                                    <?php if ($product['image_url']): ?>
                                        <img src="<?php echo htmlspecialchars($product['image_url']); ?>" 
                                             alt="<?php echo htmlspecialchars($product['name']); ?>" 
                                             class="img-fluid rounded" style="max-height: 80px;">
                        <?php else: ?>
                                        <div class="bg-light rounded d-flex align-items-center justify-content-center" 
                                             style="height: 80px;">
                                            <i class="bi bi-box text-muted fs-1"></i>
                            </div>
                                    <?php endif; ?>
                            </div>
                                <h6 class="fw-bold mb-1"><?php echo htmlspecialchars($product['name']); ?></h6>
                                <p class="text-muted small mb-2"><?php echo htmlspecialchars($product['category_name']); ?></p>
                                <div class="fw-bold text-success">
                                    <?php echo $settings['currency_symbol'] ?? 'KES'; ?> <?php echo number_format($product['price'], 2); ?>
                        </div>
                                <?php if ($product['quantity'] <= 0): ?>
                                <div class="badge bg-danger mt-1">Out of Stock</div>
                        <?php endif; ?>
                    </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                            </div>
                        </div>

            <!-- Right Sidebar - Cart -->
            <div class="col-md-4">
                <div class="pos-sidebar">
                    <div class="cart-container">
                        <!-- Cart Header -->
                        <div class="cart-header">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Cart (<span id="cartCount"><?php echo $cart_count; ?></span>)</h5>
                                <button class="btn btn-outline-light btn-sm" onclick="clearCart()">
                                    <i class="bi bi-trash"></i> Clear
                            </button>
                        </div>
                            <div class="mt-2">
                                <small class="customer-display" style="cursor: pointer; color: #007bff;" onclick="openCustomerModal()">
                                    <i class="bi bi-person me-1"></i>CUSTOMER: <span id="selectedCustomerName">Walk-in Customer</span>
                                    <i class="bi bi-chevron-down ms-1"></i>
                                </small>
                    </div>
    </div>

                        <!-- Cart Items -->
                        <div class="cart-items" id="cartItems">
                            <?php if (empty($cart)): ?>
                                <div class="text-center text-muted py-4">
                                    <i class="bi bi-cart-x fs-1"></i>
                                    <p class="mt-2 mb-1">No items in cart</p>
                                    <small>Add products to get started</small>
                </div>
                            <?php else: ?>
                                <?php foreach ($cart as $index => $item): ?>
                                    <div class="cart-item" data-index="<?php echo $index; ?>">
                                        <div class="flex-grow-1 d-flex align-items-center">
                                            <span class="product-number"><?php echo $index + 1; ?>.</span>
                                            <div class="flex-grow-1">
                                                <div class="d-flex justify-content-between align-items-start mb-1">
                                                    <div class="product-name"><?php echo htmlspecialchars($item['name']); ?></div>
                                                    <?php if (!empty($item['sku'])): ?>
                                                        <span class="product-sku">
                                                            <?php echo htmlspecialchars($item['sku']); ?>
                                                        </span>
                                                    <?php endif; ?>
                    </div>
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <small class="product-price">
                                                        <?php echo $settings['currency_symbol'] ?? 'KES'; ?> <?php echo number_format($item['price'], 2); ?> each
                                                    </small>
                </div>
                </div>
            </div>
                                        <div class="quantity-controls">
                                            <button class="quantity-btn" onclick="updateQuantity(<?php echo $index; ?>, -1)">
                                                <i class="bi bi-dash"></i>
                    </button>
                                            <input type="number" class="quantity-display" value="<?php echo $item['quantity']; ?>" 
                                                   min="1" max="999" data-index="<?php echo $index; ?>"
                                                   onchange="updateQuantityDirect(<?php echo $index; ?>, this.value)"
                                                   onkeypress="handleQuantityKeypress(event, <?php echo $index; ?>, this)"
                                                   oninput="filterQuantityInput(this)"
                                                   onpaste="setTimeout(() => filterQuantityInput(this), 10)">
                                            <button class="quantity-btn" onclick="updateQuantity(<?php echo $index; ?>, 1)">
                                                <i class="bi bi-plus"></i>
                            </button>
                                            <button class="btn btn-outline-danger btn-sm ms-2" onclick="removeItem(<?php echo $index; ?>)">
                                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
    </div>

                        <!-- Cart Totals -->
                        <div class="cart-totals">
                            <div class="d-flex justify-content-between mb-0">
                                <span class="fw-bold small">Subtotal:</span>
                                <span class="fw-bold small" id="cartSubtotal"><?php echo $settings['currency_symbol'] ?? 'KES'; ?> <?php echo number_format($subtotal, 2); ?></span>
                </div>
                            <div class="d-flex justify-content-between mb-0">
                                <span class="fw-bold small">Tax (<?php echo $tax_rate; ?>%):</span>
                                <span class="fw-bold small" id="cartTax"><?php echo $settings['currency_symbol'] ?? 'KES'; ?> <?php echo number_format($tax_amount, 2); ?></span>
                    </div>
                            <hr class="my-0" style="margin: 0.1rem 0;">
                            <div class="d-flex justify-content-between fw-bold small text-primary">
                            <span>TOTAL:</span>
                                <span id="cartTotal"><?php echo $settings['currency_symbol'] ?? 'KES'; ?> <?php echo number_format($total_amount, 2); ?></span>
                        </div>
                    </div>
                    
                        <!-- Cart Actions -->
                        <div class="cart-actions">
                            <div class="row g-2 mb-2">
                                <div class="col-6">
                                    <button class="btn btn-outline-warning w-100 btn-sm" onclick="holdTransaction()">
                                        <i class="bi bi-pause-circle"></i> Hold
                    </button>
                </div>
                                <div class="col-6">
                                    <button class="btn btn-outline-info w-100 btn-sm" onclick="loadHeldTransactions()">
                                        <i class="bi bi-clock-history"></i> Held
                            </button>
                        </div>
                    </div>
                            <button class="btn btn-success payment-btn" onclick="processPayment()" <?php echo $cart_count == 0 ? 'disabled' : ''; ?>>
                                <i class="bi bi-credit-card"></i> Process Payment
                        </button>
                    </div>
                    </div>
                        </div>
            </div>
        </div>
    </div>

    <!-- Include Payment Modal -->
    <?php include 'payment_modal.php'; ?>

    <!-- Customer Selection Modal -->
    <div class="modal fade" id="customerModal" tabindex="-1" aria-labelledby="customerModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="customerModalLabel">
                        <i class="bi bi-person me-2"></i>Select Customer
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Search Bar -->
                    <div class="mb-3">
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="bi bi-search"></i>
                            </span>
                            <input type="text" class="form-control" id="customerSearch" placeholder="Search by name, phone number, or email address...">
                        </div>
                    </div>
                    
                    <!-- Customer List -->
                    <div class="customer-list" id="customerList" style="max-height: 400px; overflow-y: auto;">
                        <div class="text-center py-4">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                            <p class="mt-2">Loading customers...</p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="selectCustomerBtn" disabled>Select Customer</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/enhanced_payment.js"></script>
    <script>
        // POS Configuration
            window.POSConfig = {
            currencySymbol: '<?php echo $settings['currency_symbol'] ?? 'KES'; ?>',
            companyName: '<?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?>',
            companyAddress: '<?php echo htmlspecialchars($settings['company_address'] ?? ''); ?>',
            taxRate: <?php echo $tax_rate; ?>
        };

        // Cart data
        window.cartData = <?php echo json_encode($cart); ?>;
        window.paymentTotals = {
            subtotal: <?php echo $subtotal; ?>,
            tax: <?php echo $tax_amount; ?>,
            total: <?php echo $total_amount; ?>
        };

        // Global error handler for unhandled Promise rejections
        window.addEventListener('unhandledrejection', function(event) {
            console.error('Unhandled promise rejection:', event.reason);
            // Prevent the default behavior (which would log to console)
            event.preventDefault();
        });

        // Global error handler for general errors
        window.addEventListener('error', function(event) {
            console.error('Global error:', event.error);
        });

        // Update time display
        function updateTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit', second:'2-digit'});
            const dateString = now.toLocaleDateString([], {weekday: 'short', month: 'short', day: 'numeric', year: 'numeric'});
            document.getElementById('currentTime').textContent = `${timeString} ${dateString}`;
        }

        // Update time every second
        setInterval(updateTime, 1000);
        updateTime();

        // Product search and filtering
        document.getElementById('productSearch').addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const productCards = document.querySelectorAll('.product-card');
            
            productCards.forEach(card => {
                const productName = card.querySelector('h6').textContent.toLowerCase();
                const categoryName = card.querySelector('p').textContent.toLowerCase();
                
                if (productName.includes(searchTerm) || categoryName.includes(searchTerm)) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        });

        // Category filtering
        document.querySelectorAll('.category-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                // Update active button
                document.querySelectorAll('.category-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                
                const categoryId = this.dataset.category;
                const productCards = document.querySelectorAll('.product-card');
                
                productCards.forEach(card => {
                    if (categoryId === 'all' || card.dataset.categoryId === categoryId) {
                        card.style.display = 'block';
                    } else {
                        card.style.display = 'none';
                    }
                });
            });
        });

        // Product selection
        document.querySelectorAll('.product-card').forEach(card => {
            card.addEventListener('click', function() {
                const productId = this.dataset.productId;
                addToCart(productId);
            });
        });

        // Async function to add item to cart
        async function addToCartAsync(productId, quantity, fallbackCart) {
            try {
                const response = await fetch('add_to_cart.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `product_id=${productId}&quantity=${quantity}`
                });

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const data = await response.json();
                
                if (data.success) {
                    // Server response successful, update with server data
                    updateCartDisplay(data.cart);
                } else {
                    // Server error, revert to previous state
                    console.error('Server error:', data.error);
                    updateCartDisplay(fallbackCart);
                    alert('Error adding product to cart: ' + (data.error || 'Unknown error'));
                }
            } catch (error) {
                console.error('Error:', error);
                // Revert the cart to previous state
                updateCartDisplay(fallbackCart);
                alert('Error adding product to cart: ' + error.message);
            }
        }

        // Async function to update cart item
        async function updateCartItemAsync(index, change, fallbackCart) {
            try {
                const response = await fetch('update_cart_item.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `index=${index}&change=${change}`
                });

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const data = await response.json();
                
                if (data.success) {
                    // Server response successful, update with server data
                    updateCartDisplay(data.cart);
                } else {
                    // Server error, revert to previous state
                    console.error('Server error:', data.error);
                    updateCartDisplay(fallbackCart);
                    alert('Error updating quantity: ' + (data.error || 'Unknown error'));
                }
            } catch (error) {
                console.error('Error:', error);
                // Revert the cart to previous state
                updateCartDisplay(fallbackCart);
                alert('Error updating quantity: ' + error.message);
            }
        }

        // Async function to remove cart item
        async function removeCartItemAsync(index, fallbackCart) {
            try {
                const response = await fetch('remove_cart_item.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `index=${index}`
                });

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const data = await response.json();
                
                if (data.success) {
                    // Server response successful, update with server data
                    updateCartDisplay(data.cart);
                } else {
                    // Server error, revert to previous state
                    console.error('Server error:', data.error);
                    updateCartDisplay(fallbackCart);
                    alert('Error removing item: ' + (data.error || 'Unknown error'));
                }
            } catch (error) {
                console.error('Error:', error);
                // Revert the cart to previous state
                updateCartDisplay(fallbackCart);
                alert('Error removing item: ' + error.message);
            }
        }

        // Async function to clear cart
        async function clearCartAsync() {
            try {
                const response = await fetch('clear_cart.php', {
                    method: 'POST'
                });

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const data = await response.json();
                
                if (data.success) {
                    updateCartDisplay([]);
                } else {
                    alert('Error clearing cart: ' + (data.error || 'Unknown error'));
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Error clearing cart: ' + error.message);
            }
        }

        // Async function to search customers
        async function searchCustomersAsync(search) {
            const customerList = document.getElementById('customerList');
            
            try {
                const response = await fetch(`../api/get_customers.php?search=${encodeURIComponent(search)}`);
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const data = await response.json();
                console.log('Customer API response:', data);
                
                if (data.success) {
                    displayCustomers(data.customers);
                } else {
                    customerList.innerHTML = `
                        <div class="text-center py-4 text-danger">
                            <i class="bi bi-exclamation-triangle fs-1"></i>
                            <p class="mt-2">Error loading customers: ${data.error || 'Unknown error'}</p>
                        </div>
                    `;
                }
            } catch (error) {
                console.error('Customer search error:', error);
                customerList.innerHTML = `
                    <div class="text-center py-4 text-danger">
                        <i class="bi bi-exclamation-triangle fs-1"></i>
                        <p class="mt-2">Error loading customers</p>
                        <small>Please try again</small>
                    </div>
                `;
            }
        }

        // Add to cart function with instant UI update
        function addToCart(productId) {
            // Find the clicked product card to get product data
            const productCard = document.querySelector(`[data-product-id="${productId}"]`);
            if (!productCard) {
                console.error('Product card not found');
                return;
            }

            // Extract product data from the card
            const productName = productCard.querySelector('h6').textContent;
            const productPrice = parseFloat(productCard.querySelector('.fw-bold.text-success').textContent.replace(/[^\d.-]/g, ''));
            const productSku = productCard.querySelector('.product-sku')?.textContent || '';
            const categoryName = productCard.querySelector('p').textContent;

            // Check if product is out of stock
            if (productCard.querySelector('.badge.bg-danger')) {
                alert('This product is out of stock');
                return;
            }

            // Create temporary cart item for instant display
            const tempCartItem = {
                id: productId,
                name: productName,
                price: productPrice,
                quantity: 1,
                sku: productSku,
                category_name: categoryName,
                image_url: productCard.querySelector('img')?.src || ''
            };

            // Update cart instantly with temporary item
            const currentCart = window.cartData || [];
            const existingItemIndex = currentCart.findIndex(item => item.id == productId);
            
            let updatedCart;
            if (existingItemIndex >= 0) {
                // Item exists, increase quantity
                updatedCart = [...currentCart];
                updatedCart[existingItemIndex].quantity += 1;
            } else {
                // New item, add to cart
                updatedCart = [...currentCart, tempCartItem];
            }

            // Update UI instantly
            updateCartDisplay(updatedCart);

            // Send request to server in background
            addToCartAsync(productId, 1, currentCart);
        }

        // Update cart display
        function updateCartDisplay(cart) {
            const cartItems = document.getElementById('cartItems');
            const cartCount = document.getElementById('cartCount');
            const cartSubtotal = document.getElementById('cartSubtotal');
            const cartTax = document.getElementById('cartTax');
            const cartTotal = document.getElementById('cartTotal');
            const paymentBtn = document.querySelector('.payment-btn');

            // Update cart count
            cartCount.textContent = cart.length;

            // Update totals
            let subtotal = 0;
            cart.forEach(item => {
                subtotal += item.price * item.quantity;
            });
            const tax = subtotal * (window.POSConfig.taxRate / 100);
            const total = subtotal + tax;

            cartSubtotal.textContent = `${window.POSConfig.currencySymbol} ${subtotal.toFixed(2)}`;
            cartTax.textContent = `${window.POSConfig.currencySymbol} ${tax.toFixed(2)}`;
            cartTotal.textContent = `${window.POSConfig.currencySymbol} ${total.toFixed(2)}`;
            
            // Ensure proper styling
            cartSubtotal.className = 'fw-bold small';
            cartTax.className = 'fw-bold small';
            cartTotal.className = 'fw-bold small text-primary';

            // Update payment totals for payment processor
            window.paymentTotals = { subtotal, tax, total };
            window.cartData = cart;

            // Enable/disable payment button
            paymentBtn.disabled = cart.length === 0;

            // Update cart items display
            if (cart.length === 0) {
                cartItems.innerHTML = `
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-cart-x fs-1"></i>
                        <p class="mt-2 mb-1">No items in cart</p>
                        <small>Add products to get started</small>
                        </div>
                `;
            } else {
                let itemsHtml = '';
                cart.forEach((item, index) => {
                    itemsHtml += `
                        <div class="cart-item" data-index="${index}">
                            <div class="flex-grow-1 d-flex align-items-center">
                                <span class="product-number">${index + 1}.</span>
                                <div class="flex-grow-1">
                                    <div class="d-flex justify-content-between align-items-start mb-1">
                                        <div class="product-name">${item.name}</div>
                                        ${item.sku ? `<span class="product-sku">${item.sku}</span>` : ''}
                            </div>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <small class="product-price">
                                            ${window.POSConfig.currencySymbol} ${item.price.toFixed(2)} each
                                        </small>
                                    </div>
                                    </div>
                                </div>
                            <div class="quantity-controls">
                                <button class="quantity-btn" onclick="updateQuantity(${index}, -1)">
                                    <i class="bi bi-dash"></i>
                                </button>
                                <input type="number" class="quantity-display" value="${item.quantity}" 
                                       min="1" max="999" data-index="${index}"
                                       onchange="updateQuantityDirect(${index}, this.value)"
                                       onkeypress="handleQuantityKeypress(event, ${index}, this)"
                                       oninput="filterQuantityInput(this)"
                                       onpaste="setTimeout(() => filterQuantityInput(this), 10)">
                                <button class="quantity-btn" onclick="updateQuantity(${index}, 1)">
                                    <i class="bi bi-plus"></i>
                                </button>
                                <button class="btn btn-outline-danger btn-sm ms-2" onclick="removeItem(${index})">
                                    <i class="bi bi-trash"></i>
                                </button>
                        </div>
                    </div>
                `;
            });
                cartItems.innerHTML = itemsHtml;
            }
        }

        // Update quantity
        function updateQuantity(index, change) {
            // Update UI instantly
            const currentCart = window.cartData || [];
            if (currentCart[index]) {
                const updatedCart = [...currentCart];
                const newQuantity = updatedCart[index].quantity + change;
                
                if (newQuantity <= 0) {
                    // Remove item if quantity becomes 0 or negative
                    updatedCart.splice(index, 1);
                } else {
                    updatedCart[index].quantity = newQuantity;
                }
                
                // Update UI instantly
                updateCartDisplay(updatedCart);
            }

            // Send request to server in background
            updateCartItemAsync(index, change, currentCart);
        }

        // Update quantity directly from input
        function updateQuantityDirect(index, newQuantity) {
            // Sanitize input
            const sanitizedInput = sanitizeQuantityInput(newQuantity);
            
            if (sanitizedInput === null) {
                // Reset to current quantity if invalid
                const currentQuantity = window.cartData[index] ? window.cartData[index].quantity : 1;
                document.querySelector(`[data-index="${index}"] .quantity-display`).value = currentQuantity;
                return;
            }

            // Update UI instantly
            const currentCart = window.cartData || [];
            if (currentCart[index]) {
                const updatedCart = [...currentCart];
                const newQuantityValue = sanitizedInput;
                
                if (newQuantityValue <= 0) {
                    // Remove item if quantity becomes 0 or negative
                    updatedCart.splice(index, 1);
                } else {
                    updatedCart[index].quantity = newQuantityValue;
                }
                
                // Update UI instantly
                updateCartDisplay(updatedCart);
            }

            // Calculate the change needed for server sync
            const currentQuantity = currentCart[index] ? currentCart[index].quantity : 1;
            const change = sanitizedInput - currentQuantity;
            
            if (change !== 0) {
                // Send request to server in background
                updateCartItemAsync(index, change, currentCart);
            }
        }

        // Sanitize quantity input
        function sanitizeQuantityInput(input) {
            // Remove any non-numeric characters except minus sign
            let cleaned = String(input).replace(/[^0-9-]/g, '');
            
            // Remove leading zeros
            cleaned = cleaned.replace(/^0+/, '');
            
            // Handle empty string
            if (cleaned === '' || cleaned === '-') {
                return null;
            }
            
            // Convert to integer
            const quantity = parseInt(cleaned, 10);
            
            // Validate range
            if (isNaN(quantity) || quantity < 1 || quantity > 999) {
                return null;
            }
            
            return quantity;
        }

        // Handle keypress events for quantity input
        function handleQuantityKeypress(event, index, inputElement) {
            // Allow only numeric characters, backspace, delete, arrow keys, and Enter
            const allowedKeys = ['Backspace', 'Delete', 'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Enter', 'Tab'];
            const isNumeric = /^[0-9]$/.test(event.key);
            
            if (!isNumeric && !allowedKeys.includes(event.key)) {
                event.preventDefault();
                return false;
            }
            
            if (event.key === 'Enter') {
                event.preventDefault();
                inputElement.blur(); // This will trigger onchange
            }
        }

        // Real-time input filtering
        function filterQuantityInput(inputElement) {
            const originalValue = inputElement.value;
            const sanitized = sanitizeQuantityInput(originalValue);
            
            if (sanitized === null && originalValue !== '') {
                // If input is invalid, revert to previous valid value
                const currentQuantity = window.cartData[inputElement.dataset.index] ? 
                    window.cartData[inputElement.dataset.index].quantity : 1;
                inputElement.value = currentQuantity;
            } else if (sanitized !== null) {
                inputElement.value = sanitized;
            }
        }

        // Remove item
        function removeItem(index) {
            // Update UI instantly
            const currentCart = window.cartData || [];
            if (currentCart[index]) {
                const updatedCart = [...currentCart];
                updatedCart.splice(index, 1);
                
                // Update UI instantly
                updateCartDisplay(updatedCart);
            }

            // Send request to server in background
            removeCartItemAsync(index, currentCart);
        }

        // Clear cart
        function clearCart() {
            if (confirm('Are you sure you want to clear the cart?')) {
                clearCartAsync();
            }
        }

        // Process payment
        function processPayment() {
            if (window.cartData.length === 0) {
                alert('Cart is empty');
                return;
            }

            // Refresh cart data in payment processor before showing modal
            if (window.paymentProcessor) {
                window.paymentProcessor.refreshCartData();
            }

            // Show payment modal
            const paymentModal = new bootstrap.Modal(document.getElementById('paymentModal'));
            paymentModal.show();
        }

        // Hold transaction
        function holdTransaction() {
            if (window.cartData.length === 0) {
                alert('Cart is empty');
                    return;
                }
            alert('Hold transaction functionality will be implemented');
        }

        // Load held transactions
        function loadHeldTransactions() {
            alert('Load held transactions functionality will be implemented');
        }

        // Customer Selection Functions
        let selectedCustomerId = null;
        let selectedCustomerData = null;

        function openCustomerModal() {
            const customerModal = new bootstrap.Modal(document.getElementById('customerModal'));
            customerModal.show();
            
            // Clear search and reset selection
            document.getElementById('customerSearch').value = '';
            selectedCustomerId = null;
            selectedCustomerData = null;
            document.getElementById('selectCustomerBtn').disabled = true;
            
            loadCustomers();
        }

        function loadCustomers(search = '') {
            const customerList = document.getElementById('customerList');
            customerList.innerHTML = `
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2">Loading customers...</p>
                </div>
            `;

            // Use async/await for customer search
            searchCustomersAsync(search);
        }

        function displayCustomers(customers) {
            const customerList = document.getElementById('customerList');
            const searchTerm = document.getElementById('customerSearch').value.trim();
            
            if (customers.length === 0) {
                if (searchTerm) {
                    customerList.innerHTML = `
                        <div class="text-center py-4 text-muted">
                            <i class="bi bi-search fs-1"></i>
                            <p class="mt-2">No customers found for "${searchTerm}"</p>
                            <small>Try searching by name, phone number, or email address</small>
                        </div>
                    `;
                } else {
                    customerList.innerHTML = `
                        <div class="text-center py-4 text-muted">
                            <i class="bi bi-person-x fs-1"></i>
                            <p class="mt-2">No customers found</p>
                            <small>No active customers in the database</small>
                        </div>
                    `;
                }
                return;
            }

            let customersHtml = '';
            
            // Add results count header
            if (searchTerm) {
                customersHtml += `
                    <div class="mb-3">
                        <small class="text-muted">
                            <i class="bi bi-search me-1"></i>
                            Found ${customers.length} customer${customers.length !== 1 ? 's' : ''} for "${searchTerm}"
                        </small>
                    </div>
                `;
            }
            
            customers.forEach(customer => {
                const isSelected = selectedCustomerId === customer.id;
                const customerTypeClass = customer.customer_type === 'walk_in' ? 'text-muted' : 
                                        customer.customer_type === 'vip' ? 'text-warning' : 
                                        customer.customer_type === 'business' ? 'text-info' : 'text-dark';
                
                customersHtml += `
                    <div class="customer-item card mb-2 ${isSelected ? 'border-primary' : ''}" 
                         style="cursor: pointer; transition: all 0.3s;" 
                         onclick="selectCustomer(${customer.id}, '${customer.display_name}', ${JSON.stringify(customer).replace(/"/g, '&quot;')})">
                        <div class="card-body p-3">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="flex-grow-1">
                                    <h6 class="mb-1 ${customerTypeClass}">
                                        ${customer.display_name}
                                        ${customer.customer_type === 'vip' ? '<i class="bi bi-star-fill text-warning ms-1"></i>' : ''}
                                        ${customer.tax_exempt ? '<i class="bi bi-shield-check text-success ms-1"></i>' : ''}
                                    </h6>
                                    <small class="text-muted">
                                        ${customer.customer_number} • ${customer.customer_type}
                                        ${customer.membership_level ? ' • ' + customer.membership_level : ''}
                                    </small>
                                </div>
                                <div class="text-end">
                                    ${isSelected ? '<i class="bi bi-check-circle-fill text-primary fs-4"></i>' : ''}
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            });
            
            customerList.innerHTML = customersHtml;
        }

        function selectCustomer(customerId, customerName, customerData) {
            selectedCustomerId = customerId;
            selectedCustomerData = customerData;
            
            // Update UI
            document.getElementById('selectedCustomerName').textContent = customerName;
            document.getElementById('selectCustomerBtn').disabled = false;
            
            // Update visual selection
            document.querySelectorAll('.customer-item').forEach(item => {
                item.classList.remove('border-primary');
            });
            event.currentTarget.classList.add('border-primary');
        }

        function confirmCustomerSelection() {
            if (selectedCustomerId && selectedCustomerData) {
                // Show confirmation dialog
                const customerName = selectedCustomerData.display_name;
                const customerType = selectedCustomerData.customer_type;
                const customerNumber = selectedCustomerData.customer_number;
                
                const confirmMessage = `Are you sure you want to select this customer?\n\n` +
                                    `Name: ${customerName}\n` +
                                    `Type: ${customerType}\n` +
                                    `Number: ${customerNumber}`;
                
                if (confirm(confirmMessage)) {
                    // Update global customer data
                    window.selectedCustomer = selectedCustomerData;
                    
                    // Close modal
                    const customerModal = bootstrap.Modal.getInstance(document.getElementById('customerModal'));
                    customerModal.hide();
                    
                    // Update customer display
                    document.getElementById('selectedCustomerName').textContent = selectedCustomerData.display_name;
                    
                    // Show success message
                    showCustomerSelectionSuccess(selectedCustomerData);
                    
                    console.log('Customer selected:', selectedCustomerData);
                }
            }
        }

        function showCustomerSelectionSuccess(customerData) {
            // Create a temporary success message
            const successDiv = document.createElement('div');
            successDiv.className = 'alert alert-success alert-dismissible fade show position-fixed';
            successDiv.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
            successDiv.innerHTML = `
                <i class="bi bi-check-circle me-2"></i>
                <strong>Customer Selected:</strong> ${customerData.display_name}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            `;
            
            document.body.appendChild(successDiv);
            
            // Auto-remove after 3 seconds
            setTimeout(() => {
                if (successDiv.parentNode) {
                    successDiv.parentNode.removeChild(successDiv);
                }
            }, 3000);
        }

        // Search functionality
        document.getElementById('customerSearch').addEventListener('input', function() {
            const searchTerm = this.value;
            clearTimeout(this.searchTimeout);
            this.searchTimeout = setTimeout(() => {
                loadCustomers(searchTerm);
            }, 300);
        });

        // Event listeners
        document.getElementById('selectCustomerBtn').addEventListener('click', confirmCustomerSelection);
    </script>
</body>
</html>
