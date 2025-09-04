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

// Define comprehensive permission checks for different operations
$salesPermissions = [
    // Cart Management
    'canViewCart' => hasPermission('view_cart', $permissions) || hasPermission('manage_cart', $permissions),
    'canEditCart' => hasPermission('edit_cart', $permissions) || hasPermission('manage_cart', $permissions),
    'canManageCart' => hasPermission('manage_cart', $permissions),
    'canClearCart' => hasPermission('clear_cart', $permissions),
    'canApplyCartDiscounts' => hasPermission('apply_cart_discounts', $permissions),
    'canModifyCartPrices' => hasPermission('modify_cart_prices', $permissions),

    // Held Transactions
    'canViewHeldTransactions' => hasPermission('view_held_transactions', $permissions),
    'canCreateHeldTransactions' => hasPermission('create_held_transactions', $permissions),
    'canResumeHeldTransactions' => hasPermission('resume_held_transactions', $permissions),
    'canCancelHeldTransactions' => hasPermission('cancel_held_transactions', $permissions),
    'canDeleteHeldTransactions' => hasPermission('delete_held_transactions', $permissions),
    'canManageHeldTransactions' => hasPermission('manage_held_transactions', $permissions),

    // Sales Processing
    'canCompleteSales' => hasPermission('complete_sales', $permissions),

    // Customer Management
    'canManageSalesCustomers' => hasPermission('manage_sales_customers', $permissions),
    'canViewCustomerPurchaseHistory' => hasPermission('view_customer_purchase_history', $permissions),
    'canApplyCustomerDiscounts' => hasPermission('apply_customer_discounts', $permissions),
    'canCreateCustomerAccounts' => hasPermission('create_customer_accounts', $permissions),

    // POS Settings and Features
    'canManagePosSettings' => hasPermission('manage_pos_settings', $permissions),
    'canUseBarcodeScanner' => hasPermission('manage_product_barcodes', $permissions),

    // Receipt Management
    'canPrintReceipts' => hasPermission('print_receipts', $permissions),
    'canEmailReceipts' => hasPermission('email_receipts', $permissions),
    'canReprintReceipts' => hasPermission('reprint_receipts', $permissions),

    // Transaction Management
    'canViewTransactionHistory' => hasPermission('view_transaction_history', $permissions),
    'canSearchTransactions' => hasPermission('search_transactions', $permissions),

    // Advanced Features
    'canCompleteSales' => hasPermission('complete_sales', $permissions),
    'canManageSalesPromotions' => hasPermission('manage_sales_promotions', $permissions),
    'canViewSalesDashboard' => hasPermission('view_sales_dashboard', $permissions)
];

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Initialize Auto BOM Manager
$auto_bom_manager = new AutoBOMManager($conn, $user_id);

// Handle new sale parameter to clear cart
if (isset($_GET['new_sale'])) {
    unset($_SESSION['pos_cart']);
    // Redirect to clean URL with success message
    header("Location: sale.php?cart_cleared=1");
    exit();
}

// Check if cart was just cleared
$cartCleared = isset($_GET['cart_cleared']) && $_GET['cart_cleared'] == '1';

// Handle POST requests for cart storage and hold transactions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'store_cart') {
        try {
            $cart_data = $_POST['cart_data'];
            
            // Validate that cart_data is valid JSON
            $decoded = json_decode($cart_data, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid cart data format');
            }
            
            // Store in session
            $_SESSION['pos_cart'] = $cart_data;
            
            // Log for debugging
            error_log("Cart stored in session: " . $cart_data);
            
            echo json_encode(['success' => true, 'message' => 'Cart saved successfully']);
        } catch (Exception $e) {
            error_log("Error storing cart: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Failed to save cart: ' . $e->getMessage()]);
        }
        exit();
    }
    
    if ($_POST['action'] === 'hold_transaction') {
        // Check permission to create held transactions
        if (!$salesPermissions['canCreateHeldTransactions']) {
            echo json_encode([
                'success' => false,
                'error' => 'You do not have permission to hold transactions'
            ]);
            exit();
        }

        try {
            $cart_data = json_decode($_POST['cart_data'], true);
            $reason = sanitizeInput($_POST['reason'] ?? '');
            $customer_reference = sanitizeInput($_POST['customer_reference'] ?? '');
            
            if (empty($cart_data['items'])) {
                throw new Exception('No items in cart to hold');
            }
            
            // Create held transaction record
            $stmt = $conn->prepare("
                INSERT INTO held_transactions (user_id, cart_data, reason, customer_reference, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $user_id,
                json_encode($cart_data),
                $reason,
                $customer_reference
            ]);
            
            $hold_id = $conn->lastInsertId();
            
            // Log the hold action
            logActivity($conn, $user_id, 'transaction_held', "Held transaction #$hold_id with " . count($cart_data['items']) . " items");
            
            echo json_encode([
                'success' => true,
                'hold_id' => $hold_id,
                'message' => 'Transaction held successfully'
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
        exit();
    }
    
    if ($_POST['action'] === 'get_held_transactions') {
        // Check permission to view held transactions
        if (!$salesPermissions['canViewHeldTransactions']) {
            echo json_encode([
                'success' => false,
                'error' => 'You do not have permission to view held transactions'
            ]);
            exit();
        }

        try {
            $stmt = $conn->prepare("
                SELECT ht.*, u.username as cashier_name
                FROM held_transactions ht
                JOIN users u ON ht.user_id = u.id
                WHERE ht.status = 'held'
                ORDER BY ht.created_at DESC
            ");
            $stmt->execute();
            $held_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Parse cart data for each transaction
            foreach ($held_transactions as &$transaction) {
                $cart_data = json_decode($transaction['cart_data'], true);
                $transaction['item_count'] = count($cart_data['items']);
                $transaction['total_amount'] = $cart_data['total'];
            }
            
            echo json_encode([
                'success' => true,
                'transactions' => $held_transactions
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
        exit();
    }
    
    if ($_POST['action'] === 'resume_held_transaction') {
        // Check permission to resume held transactions
        if (!$salesPermissions['canResumeHeldTransactions']) {
            echo json_encode([
                'success' => false,
                'error' => 'You do not have permission to resume held transactions'
            ]);
            exit();
        }

        try {
            $hold_id = (int)($_POST['hold_id'] ?? 0);
            
            if (!$hold_id) {
                throw new Exception('Hold ID is required');
            }
            
            $stmt = $conn->prepare("
                SELECT * FROM held_transactions 
                WHERE id = ? AND status = 'held'
            ");
            $stmt->execute([$hold_id]);
            $held_transaction = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$held_transaction) {
                throw new Exception('Held transaction not found');
            }
            
            echo json_encode([
                'success' => true,
                'cart_data' => $held_transaction['cart_data']
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
        exit();
    }
    
    if ($_POST['action'] === 'delete_held_transaction') {
        // Check permission to delete held transactions
        if (!$salesPermissions['canDeleteHeldTransactions']) {
            echo json_encode([
                'success' => false,
                'error' => 'You do not have permission to delete held transactions'
            ]);
            exit();
        }

        try {
            $hold_id = (int)($_POST['hold_id'] ?? 0);
            
            if (!$hold_id) {
                throw new Exception('Hold ID is required');
            }
            
            $stmt = $conn->prepare("
                UPDATE held_transactions 
                SET status = 'deleted', updated_at = NOW()
                WHERE id = ? AND status = 'held'
            ");
            $result = $stmt->execute([$hold_id]);
            
            if ($stmt->rowCount() === 0) {
                throw new Exception('Held transaction not found or already processed');
            }
            
            // Log the delete action
            logActivity($conn, $user_id, 'held_transaction_deleted', "Deleted held transaction #$hold_id");
            
            echo json_encode([
                'success' => true,
                'message' => 'Held transaction deleted successfully'
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
        exit();
    }
    
    if ($_POST['action'] === 'search_customers') {
        // Check permission to manage sales customers
        if (!$salesPermissions['canManageSalesCustomers'] && !$salesPermissions['canViewCustomerPurchaseHistory']) {
            echo json_encode([
                'success' => false,
                'error' => 'You do not have permission to search customers'
            ]);
            exit();
        }

        try {
            $search_term = sanitizeInput($_POST['search_term'] ?? '');
            $limit = (int)($_POST['limit'] ?? 20);
            
            $sql = "
                SELECT c.id, c.customer_number, c.first_name, c.last_name, 
                       c.email, c.phone, c.customer_type, c.membership_level,
                       c.created_at,
                       COALESCE(SUM(s.total_amount), 0) as total_purchases,
                       MAX(s.created_at) as last_visit
                FROM customers c
                LEFT JOIN sales s ON c.id = s.customer_id
                WHERE c.membership_status = 'active'
            ";
            
            $params = [];
            if (!empty($search_term)) {
                $sql .= " AND (
                    c.first_name LIKE :search OR 
                    c.last_name LIKE :search OR 
                    c.email LIKE :search OR 
                    c.phone LIKE :search OR 
                    c.customer_number LIKE :search OR
                    CONCAT(c.first_name, ' ', c.last_name) LIKE :search
                )";
                $params[':search'] = '%' . $search_term . '%';
            }
            
            $sql .= "
                GROUP BY c.id 
                ORDER BY 
                    CASE WHEN c.customer_type = 'walk_in' THEN 1 ELSE 0 END,
                    CASE WHEN c.customer_type = 'vip' THEN 0 ELSE 1 END,
                    total_purchases DESC, 
                    c.first_name, c.last_name
                LIMIT :limit
            ";
            
            $stmt = $conn->prepare($sql);
            
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value, PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            
            $stmt->execute();
            $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format the data for frontend
            foreach ($customers as &$customer) {
                $customer['total_purchases'] = (float)$customer['total_purchases'];
                $customer['last_visit'] = $customer['last_visit'] ? date('Y-m-d', strtotime($customer['last_visit'])) : null;
                $customer['full_name'] = trim($customer['first_name'] . ' ' . $customer['last_name']);
            }
            
            echo json_encode([
                'success' => true,
                'customers' => $customers
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
        exit();
    }
    
}

// Handle AJAX requests for Auto BOM functionality
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        switch ($_GET['action']) {
            case 'get_auto_bom_units':
                // Check permission to view Auto BOM units
                if (!hasPermission('view_auto_boms', $permissions)) {
                    echo json_encode([
                        'success' => false,
                        'error' => 'You do not have permission to view Auto BOM units'
                    ]);
                    exit();
                }

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
                // Check permission to view inventory during sale
                if (!hasPermission('view_inventory_during_sale', $permissions)) {
                    echo json_encode([
                        'success' => false,
                        'error' => 'You do not have permission to check inventory'
                    ]);
                    exit();
                }

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

// Get walk-in customer
$walk_in_customer = getWalkInCustomer($conn);
if (!$walk_in_customer) {
    // Try to create walk-in customer if it doesn't exist
    $stmt = $conn->prepare("
        INSERT INTO customers (
            customer_number, first_name, last_name, customer_type,
            membership_status, membership_level, notes, created_by
        ) VALUES (
            'WALK-IN-001', 'Walk-in', 'Customer', 'walk_in',
            'active', 'Bronze', 'Default customer for walk-in purchases', ?
        )
    ");
    $stmt->execute([$user_id]);
    $walk_in_customer = getWalkInCustomer($conn);
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

// Get categories for filtering with product counts
$categories = [];
$stmt = $conn->query("
    SELECT c.id, c.name, COUNT(p.id) as product_count
    FROM categories c
    LEFT JOIN products p ON c.id = p.category_id AND p.status = 'active'
    WHERE c.status = 'active'
    GROUP BY c.id, c.name
    ORDER BY c.name
");
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper function to format numbers
function formatCount($number) {
    if ($number >= 1000) {
        return round($number / 1000, 1) . 'k';
    }
    return $number;
}
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
    <link rel="stylesheet" href="../assets/css/pos.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }

        /* Notification System */
        .notification-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 10000;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .notification {
            background: white;
            border-radius: 8px;
            padding: 15px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            border-left: 4px solid #6c757d;
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 300px;
            max-width: 400px;
            transform: translateX(100%);
            transition: transform 0.3s ease;
        }

        .notification.show {
            transform: translateX(0);
        }

        .notification-success {
            border-left-color: #10b981;
        }

        .notification-success i {
            color: #10b981;
        }

        .notification-error {
            border-left-color: #ef4444;
        }

        .notification-error i {
            color: #ef4444;
        }

        .notification-warning {
            border-left-color: #f59e0b;
        }

        .notification-warning i {
            color: #f59e0b;
        }

        .notification-info {
            border-left-color: #3b82f6;
        }

        .notification-info i {
            color: #3b82f6;
        }

        .notification-close {
            background: none;
            border: none;
            color: #6c757d;
            cursor: pointer;
            margin-left: auto;
            padding: 5px;
        }

        .notification-close:hover {
            color: #495057;
        }

        /* Success feedback animations */
        .success-feedback {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: #10b981;
            color: white;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 600;
            z-index: 1000;
            pointer-events: none;
            animation: successFade 1.5s ease-out;
        }

        @keyframes successFade {
            0% { opacity: 0; transform: translate(-50%, -50%) scale(0.8); }
            20% { opacity: 1; transform: translate(-50%, -50%) scale(1.05); }
            80% { opacity: 1; transform: translate(-50%, -50%) scale(1); }
            100% { opacity: 0; transform: translate(-50%, -50%) scale(0.9); }
        }

        /* Cart item animations */
        .adding-to-cart {
            transform: scale(0.95);
            transition: transform 0.1s ease;
        }

        .just-added {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(16, 185, 129, 0.05));
            border: 1px solid rgba(16, 185, 129, 0.3) !important;
        }

        /* Held count badge */
        .held-count-badge {
            background: #dc3545;
            color: white;
            border-radius: 50%;
            padding: 2px 6px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-left: 5px;
            min-width: 18px;
            text-align: center;
            line-height: 1.2;
        }

        .held-count-badge:empty {
            display: none;
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
                        <h5>
                            <i class="bi bi-cart-plus"></i>
                            Point of Sale
                        </h5>
                        <small>Quick & Easy Sales Processing</small>
                    </div>
                    <div class="pos-top-actions">
                        <!-- Time and Date Display -->
                        <div class="pos-datetime">
                            <div class="datetime-display">
                                <div class="current-time" id="currentTime">
                                    <i class="bi bi-clock"></i>
                                    <span class="time-text">--:--:--</span>
                                </div>
                                <div class="current-date" id="currentDate">
                                    <i class="bi bi-calendar3"></i>
                                    <span class="date-text">--- --, ----</span>
                                </div>
                            </div>
                        </div>
                        
                        <a href="../dashboard/dashboard.php" class="pos-dashboard-btn" title="Go to Dashboard">
                            <i class="bi bi-speedometer2"></i>
                            <span>Dashboard</span>
                        </a>
                        
                        <div class="pos-user-info">
                            <div class="user-avatar">
                                <?php echo strtoupper(substr($username, 0, 1)); ?>
                            </div>
                            <div class="user-details">
                                <span class="user-welcome">Welcome back,</span>
                                <span class="user-name"><?php echo htmlspecialchars($username); ?></span>
                            </div>
                            <span class="user-role"><?php echo htmlspecialchars($role_name); ?></span>
                        </div>
                        
                        <!-- Instant Logout Button -->
                        <a href="../auth/logout.php" class="pos-logout-btn" title="Logout" 
                           onclick="return confirm('Are you sure you want to logout?')">
                            <i class="bi bi-box-arrow-right"></i>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Modern POS Layout -->
            <div class="pos-main">
                <!-- Products Section -->
                <div class="products-section">
                    <div class="products-header">
                        <h5><i class="bi bi-grid-3x3-gap-fill"></i> Point of Sale</h5>
                        <div class="products-filters">
                            <div class="search-input-enhanced">
                                <i class="bi bi-search"></i>
                                <input type="text" id="productSearch" placeholder="Search products by name, SKU, barcode..." autocomplete="off">
                                <?php if ($salesPermissions['canUseBarcodeScanner']): ?>
                                <button type="button" class="barcode-scan-btn" id="barcodeScanBtn" title="Scan Barcode">
                                    <i class="bi bi-upc-scan"></i>
                                </button>
                                <?php endif; ?>
                                <!-- Search Suggestions Dropdown -->
                                <div class="search-suggestions" id="searchSuggestions" style="display: none;">
                                    <div class="suggestions-list" id="suggestionsList">
                                        <!-- Dynamic suggestions will be added here -->
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Category Tabs -->
                    <div class="category-tabs">
                        <button class="category-tab active" data-category="">All <span class="category-count"><?php echo formatCount(count($products)); ?></span></button>
                        <?php foreach ($categories as $category): ?>
                            <button class="category-tab" data-category="<?php echo $category['id']; ?>">
                                <?php echo htmlspecialchars($category['name']); ?>
                                <span class="category-count"><?php echo formatCount($category['product_count']); ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <div class="products-grid" id="productGrid">
                        <?php foreach ($products as $product): ?>
                            <div class="product-card"
                                 data-product-id="<?php echo $product['id']; ?>"
                                 data-product-name="<?php echo htmlspecialchars($product['name']); ?>"
                                 data-product-sku="<?php echo htmlspecialchars($product['sku'] ?? ''); ?>"
                                 data-product-barcode="<?php echo htmlspecialchars($product['barcode'] ?? ''); ?>"
                                 data-product-price="<?php echo $product['price']; ?>"
                                 data-product-stock="<?php echo $product['quantity']; ?>"
                                 data-product-tax-rate="<?php echo $product['tax_rate'] ?? $settings['tax_rate'] ?? '0'; ?>"
                                 data-is-auto-bom="<?php echo $product['is_auto_bom_enabled'] ? 'true' : 'false'; ?>"
                                 data-selling-units-count="<?php echo $product['selling_units_count']; ?>"
                                 data-category-id="<?php echo $product['category_id']; ?>"
                                 data-search-text="<?php echo htmlspecialchars(strtolower($product['name'] . ' ' . ($product['sku'] ?? '') . ' ' . ($product['barcode'] ?? '') . ' ' . ($product['tags'] ?? ''))); ?>">
                                
                                <!-- Small Product Image Placeholder -->
                                <div class="product-image">
                                    <i class="bi bi-box"></i>
                                </div>
                                
                                <div class="product-name"><?php echo htmlspecialchars($product['name']); ?></div>
                                <div class="product-price"><?php echo $settings['currency_symbol']; ?> <?php echo number_format($product['price'], 2); ?></div>
                                
                                <div class="product-stock <?php echo $product['quantity'] <= 10 ? 'low-stock' : ''; ?> <?php echo $product['quantity'] <= 0 ? 'out-of-stock' : ''; ?>">
                                    <i class="bi bi-box-seam"></i>
                                    <?php echo $product['quantity']; ?> in stock
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Cart Section -->
                <div class="cart-section">
                    <div class="cart-header">
                        <h5 class="cart-title"><i class="bi bi-cart-check-fill"></i> Cart (<span id="cartItemCount">0</span>)</h5>
                        
                        <!-- Enhanced Customer Info Section -->
                        <?php if ($salesPermissions['canManageSalesCustomers']): ?>
                        <div class="customer-section" onclick="openCustomerModal()">
                            <div class="customer-avatar" id="customerAvatar">
                                <i class="bi bi-person-walking"></i>
                            </div>
                            <div class="customer-details">
                                <div class="customer-label">Customer:</div>
                                <div class="customer-name" id="currentCustomer"><?php echo htmlspecialchars($walk_in_customer['first_name'] . ' ' . $walk_in_customer['last_name']); ?></div>
                                <div class="customer-type" id="customerType">Walk-in Customer</div>
                            </div>
                            <div class="customer-actions">
                                <i class="bi bi-chevron-right"></i>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="customer-section">
                            <div class="customer-avatar">
                                <i class="bi bi-person-walking"></i>
                            </div>
                            <div class="customer-details">
                                <div class="customer-label">Customer:</div>
                                <div class="customer-name"><?php echo htmlspecialchars($walk_in_customer['first_name'] . ' ' . $walk_in_customer['last_name']); ?></div>
                                <div class="customer-type">Walk-in Customer</div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="cart-items" id="cartItems">
                        <div class="empty-cart">
                            <i class="bi bi-cart"></i>
                            <p>No items in cart</p>
                        </div>
                    </div>

                    <div class="cart-summary">
                        <div class="cart-totals">
                            <div class="cart-total-row">
                                <span>Subtotal:</span>
                                <span id="cartSubtotal"><?php echo $settings['currency_symbol']; ?> 0.00</span>
                            </div>
                            <div class="cart-total-row" id="cartTaxRow" style="display: none;">
                                <span id="cartTaxLabel"><?php echo htmlspecialchars($settings['tax_name'] ?? 'Tax'); ?> (0%):</span>
                                <span id="cartTax"><?php echo $settings['currency_symbol']; ?> 0.00</span>
                            </div>
                            <div id="cartMultipleTaxRows">
                                <!-- Multiple tax rates will be shown here if applicable -->
                            </div>
                            <div class="cart-total-row total">
                                <span>Total:</span>
                                <span id="cartTotal"><?php echo $settings['currency_symbol']; ?> 0.00</span>
                            </div>
                        </div>

                        <!-- Cart Actions -->
                        <div class="cart-actions">
                            <?php if ($salesPermissions['canCreateHeldTransactions']): ?>
                            <button class="cart-action-btn" id="holdTransactionBtn" disabled>
                                <i class="bi bi-pause-circle"></i>
                                <span>Hold</span>
                            </button>
                            <?php endif; ?>

                            <?php if ($salesPermissions['canViewHeldTransactions']): ?>
                            <button class="cart-action-btn" id="viewHeldBtn">
                                <i class="bi bi-clock-history"></i>
                                <span>Held</span>
                                <span class="held-count-badge" id="heldCountBadge" style="display: none;">0</span>
                            </button>
                            <?php endif; ?>

                            <?php if ($salesPermissions['canClearCart']): ?>
                            <button class="cart-action-btn clear" id="clearCart">
                                <i class="bi bi-trash3"></i>
                                <span>Clear</span>
                            </button>
                            <?php endif; ?>
                        </div>

                        <!-- Receipt Generation Button -->
                        <button class="checkout-btn" id="checkoutBtn" disabled>
                            <i class="bi bi-receipt"></i>
                            Generate Receipt
                        </button>
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

    <!-- Held Transactions Modal -->
    <div class="modal fade" id="heldTransactionsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Held Transactions</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="heldTransactionsList">
                        <!-- Held transactions will be loaded here -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Hold Transaction Modal -->
    <div class="modal fade" id="holdTransactionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Hold Transaction</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="holdReason" class="form-label">Reason for holding (optional)</label>
                        <input type="text" class="form-control" id="holdReason" placeholder="e.g., Customer will return later, Need to check stock, etc.">
                    </div>
                    <div class="mb-3">
                        <label for="customerReference" class="form-label">Customer Reference (optional)</label>
                        <input type="text" class="form-control" id="customerReference" placeholder="Customer name, phone, or reference">
                    </div>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i>
                        This transaction will be saved and can be resumed later from the "Held Sales" list.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-warning" id="confirmHoldTransaction">
                        <i class="bi bi-pause-circle"></i> Hold Transaction
                    </button>
                </div>
            </div>
        </div>
    </div>


    <!-- Barcode Scanner Modal -->
    <div class="modal fade" id="barcodeScanModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-upc-scan"></i> Barcode Scanner</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <!-- Camera View -->
                    <div id="cameraView" style="display: none;">
                        <video id="previewVideo" width="100%" height="300" autoplay muted></video>
                        <canvas id="scanCanvas" style="display: none;"></canvas>
                        <div class="mt-3">
                            <button class="btn btn-danger" id="stopScanBtn">
                                <i class="bi bi-stop-circle"></i> Stop Camera
                            </button>
                        </div>
                    </div>
                    
                    <!-- Manual Input -->
                    <div id="manualInput">
                        <div class="mb-3">
                            <label for="barcodeInput" class="form-label">Enter Barcode Manually</label>
                            <input type="text" class="form-control" id="barcodeInput" placeholder="Scan or type barcode..." autofocus>
                        </div>
                        <div class="mb-3">
                            <button class="btn btn-primary" id="startCameraBtn">
                                <i class="bi bi-camera"></i> Use Camera
                            </button>
                        </div>
                    </div>
                    
                    <!-- Scan Results -->
                    <div id="scanResults" style="display: none;">
                        <div class="alert alert-success">
                            <i class="bi bi-check-circle"></i>
                            <span id="scanResultText"></span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-success" id="addScannedProduct" disabled>
                        <i class="bi bi-plus-circle"></i> Add to Cart
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Receipt Modal -->
    <div class="modal fade" id="receiptModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered receipt-modal">
            <div class="modal-content">
                <div class="receipt-header">
                    <i class="bi bi-check-circle" style="font-size: 2rem; margin-bottom: 0.5rem;"></i>
                    <h5>Payment Successful</h5>
                    <p>Your transaction has been completed successfully.</p>
                </div>
                <div class="receipt-content">
                    <div class="receipt-shop-info">
                        <h6 class="receipt-shop-name"><?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></h6>
                        <p class="receipt-shop-address"><?php echo htmlspecialchars($settings['company_address'] ?? 'No address provided'); ?></p>
                    </div>
                    
                    <div class="receipt-transaction-info">
                        <div class="receipt-info-row">
                            <span>Transaction ID:</span>
                            <span class="receipt-transaction-id">--</span>
                        </div>
                        <div class="receipt-info-row">
                            <span>Date:</span>
                            <span class="receipt-date">--</span>
                        </div>
                        <div class="receipt-info-row">
                            <span>Time:</span>
                            <span class="receipt-time">--</span>
                        </div>
                        <div class="receipt-info-row">
                            <span>Payment:</span>
                            <span class="receipt-payment-method">--</span>
                        </div>
                    </div>
                    
                    <div class="receipt-items">
                        <!-- Items will be dynamically populated by JavaScript -->
                    </div>
                    
                    <div class="receipt-totals">
                        <div class="receipt-total-row">
                            <span>Subtotal:</span>
                            <span class="receipt-subtotal"><?php echo $settings['currency_symbol']; ?>0.00</span>
                        </div>
                        <div class="receipt-total-row">
                            <span>Tax (<?php echo number_format($settings['tax_rate'] ?? 0, 1); ?>%):</span>
                            <span class="receipt-tax"><?php echo $settings['currency_symbol']; ?>0.00</span>
                        </div>
                        <div class="receipt-total-row final">
                            <span>TOTAL:</span>
                            <span class="receipt-total"><?php echo $settings['currency_symbol']; ?>0.00</span>
                        </div>
                    </div>
                    
                    <!-- Cash Payment Details (shown only for cash payments) -->
                    <div class="receipt-cash-details" id="receiptCashDetails" style="display: none;">
                        <div class="receipt-total-row">
                            <span>Cash Received:</span>
                            <span class="receipt-cash-received"><?php echo $settings['currency_symbol']; ?> 0.00</span>
                        </div>
                        <div class="receipt-total-row">
                            <span>Change Due:</span>
                            <span class="receipt-change-due"><?php echo $settings['currency_symbol']; ?> 0.00</span>
                        </div>
                    </div>
                    
                    <div class="receipt-thank-you">
                        <p>Thank you for your business!<br>
                        Please keep this receipt for your records</p>
                    </div>
                </div>
                <div class="receipt-actions">
                    <button type="button" class="receipt-btn cancel">
                        <i class="bi bi-x-circle"></i> Cancel
                    </button>
                    <?php if ($salesPermissions['canPrintReceipts']): ?>
                    <button type="button" class="receipt-btn print">
                        <i class="bi bi-printer"></i> Print
                    </button>
                    <?php endif; ?>
                    <button type="button" class="receipt-btn download">
                        <i class="bi bi-download"></i> Download
                    </button>
                    <?php if ($salesPermissions['canEmailReceipts']): ?>
                    <button type="button" class="receipt-btn email" style="display: none;">
                        <i class="bi bi-envelope"></i> Email
                    </button>
                    <?php endif; ?>
                    <button type="button" class="receipt-btn new-transaction">
                        <i class="bi bi-plus-circle"></i> New Transaction
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Customer Selection Modal -->
    <div class="modal fade" id="customerSelectionModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-people-fill me-2"></i>Select Customer
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Customer Search -->
                    <div class="customer-search-section mb-3">
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="bi bi-search"></i>
                            </span>
                            <input type="text" class="form-control" id="customerSearchInput" 
                                   placeholder="Search customers by name, phone, email, or customer number...">
                            <button class="btn btn-outline-primary" type="button" id="customerSearchBtn">
                                <i class="bi bi-search"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="customer-quick-actions mb-4">
                        <button class="btn btn-outline-secondary" id="useWalkInBtn">
                            <i class="bi bi-person-walking me-1"></i> Use Walk-in Customer
                        </button>
                        <?php if ($salesPermissions['canCreateCustomerAccounts']): ?>
                        <button class="btn btn-primary" id="addNewCustomerBtn">
                            <i class="bi bi-person-plus me-1"></i> Add New Customer
                        </button>
                        <?php endif; ?>
                    </div>

                    <!-- Customer Results -->
                    <div id="customerResults">
                        <!-- Customer list will be loaded here -->
                    </div>

                    <!-- Loading State -->
                    <div id="customerLoading" class="text-center py-4" style="display: none;">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading customers...</span>
                        </div>
                        <p class="mt-2 text-muted">Searching customers...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="selectCustomerBtn" disabled>
                        <i class="bi bi-check-circle me-1"></i> Select Customer
                    </button>
                </div>
            </div>
        </div>
    </div>


    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- POS Configuration -->
    <script>
        // POS Configuration - Safe JavaScript object
        (function() {
            // Get stored cart data from session
            var storedCartData = <?php 
                if (isset($_SESSION['pos_cart'])) {
                    $decoded = json_decode($_SESSION['pos_cart'], true);
                    if ($decoded && isset($decoded['items'])) {
                        echo json_encode($decoded['items']);
                    } else {
                        echo 'null';
                    }
                } else {
                    echo 'null';
                }
            ?>;
            var storedCartItems = storedCartData || [];

            // Pass PHP variables to JavaScript with proper escaping
            window.POSConfig = {
                currencySymbol: '<?php echo isset($settings['currency_symbol']) ? addslashes($settings['currency_symbol']) : '$'; ?>',
                taxRate: <?php echo isset($settings['tax_rate']) ? (float)$settings['tax_rate'] : 0; ?>,
                companyName: '<?php echo isset($settings['company_name']) ? addslashes($settings['company_name']) : addslashes('POS System'); ?>',
                companyAddress: '<?php echo isset($settings['company_address']) ? addslashes($settings['company_address']) : addslashes('No address provided'); ?>',
                currentCustomer: {
                    id: <?php echo $walk_in_customer ? $walk_in_customer['id'] : 1; ?>,
                    name: '<?php echo $walk_in_customer ? addslashes($walk_in_customer['first_name'] . ' ' . $walk_in_customer['last_name']) : addslashes('Walk-in Customer'); ?>',
                    type: '<?php echo $walk_in_customer ? $walk_in_customer['customer_type'] : 'walk_in'; ?>',
                    is_walk_in: true
                },
                storedCart: storedCartItems,
                cartCleared: <?php echo $cartCleared ? 'true' : 'false'; ?>,
                permissions: {
                    // Cart Management
                    canViewCart: <?php echo isset($salesPermissions['canViewCart']) && $salesPermissions['canViewCart'] ? 'true' : 'false'; ?>,
                    canEditCart: <?php echo isset($salesPermissions['canEditCart']) && $salesPermissions['canEditCart'] ? 'true' : 'false'; ?>,
                    canManageCart: <?php echo isset($salesPermissions['canManageCart']) && $salesPermissions['canManageCart'] ? 'true' : 'false'; ?>,
                    canClearCart: <?php echo isset($salesPermissions['canClearCart']) && $salesPermissions['canClearCart'] ? 'true' : 'false'; ?>,
                    canApplyCartDiscounts: <?php echo isset($salesPermissions['canApplyCartDiscounts']) && $salesPermissions['canApplyCartDiscounts'] ? 'true' : 'false'; ?>,
                    canModifyCartPrices: <?php echo isset($salesPermissions['canModifyCartPrices']) && $salesPermissions['canModifyCartPrices'] ? 'true' : 'false'; ?>,

                    // Held Transactions
                    canViewHeldTransactions: <?php echo isset($salesPermissions['canViewHeldTransactions']) && $salesPermissions['canViewHeldTransactions'] ? 'true' : 'false'; ?>,
                    canCreateHeldTransactions: <?php echo isset($salesPermissions['canCreateHeldTransactions']) && $salesPermissions['canCreateHeldTransactions'] ? 'true' : 'false'; ?>,
                    canResumeHeldTransactions: <?php echo isset($salesPermissions['canResumeHeldTransactions']) && $salesPermissions['canResumeHeldTransactions'] ? 'true' : 'false'; ?>,
                    canCancelHeldTransactions: <?php echo isset($salesPermissions['canCancelHeldTransactions']) && $salesPermissions['canCancelHeldTransactions'] ? 'true' : 'false'; ?>,
                    canDeleteHeldTransactions: <?php echo isset($salesPermissions['canDeleteHeldTransactions']) && $salesPermissions['canDeleteHeldTransactions'] ? 'true' : 'false'; ?>,
                    canManageHeldTransactions: <?php echo isset($salesPermissions['canManageHeldTransactions']) && $salesPermissions['canManageHeldTransactions'] ? 'true' : 'false'; ?>,

                    // Sales Processing
                    canCompleteSales: <?php echo isset($salesPermissions['canCompleteSales']) && $salesPermissions['canCompleteSales'] ? 'true' : 'false'; ?>,

                    // Customer Management
                    canManageSalesCustomers: <?php echo isset($salesPermissions['canManageSalesCustomers']) && $salesPermissions['canManageSalesCustomers'] ? 'true' : 'false'; ?>,
                    canViewCustomerPurchaseHistory: <?php echo isset($salesPermissions['canViewCustomerPurchaseHistory']) && $salesPermissions['canViewCustomerPurchaseHistory'] ? 'true' : 'false'; ?>,
                    canApplyCustomerDiscounts: <?php echo isset($salesPermissions['canApplyCustomerDiscounts']) && $salesPermissions['canApplyCustomerDiscounts'] ? 'true' : 'false'; ?>,
                    canCreateCustomerAccounts: <?php echo isset($salesPermissions['canCreateCustomerAccounts']) && $salesPermissions['canCreateCustomerAccounts'] ? 'true' : 'false'; ?>,

                    // POS Features
                    canUseBarcodeScanner: <?php echo isset($salesPermissions['canUseBarcodeScanner']) && $salesPermissions['canUseBarcodeScanner'] ? 'true' : 'false'; ?>,
                    canManagePosSettings: <?php echo isset($salesPermissions['canManagePosSettings']) && $salesPermissions['canManagePosSettings'] ? 'true' : 'false'; ?>,

                    // Receipt Management
                    canPrintReceipts: <?php echo isset($salesPermissions['canPrintReceipts']) && $salesPermissions['canPrintReceipts'] ? 'true' : 'false'; ?>,
                    canEmailReceipts: <?php echo isset($salesPermissions['canEmailReceipts']) && $salesPermissions['canEmailReceipts'] ? 'true' : 'false'; ?>,
                    canReprintReceipts: <?php echo isset($salesPermissions['canReprintReceipts']) && $salesPermissions['canReprintReceipts'] ? 'true' : 'false'; ?>,

                    // Advanced Features
                    canCompleteSales: <?php echo isset($salesPermissions['canCompleteSales']) && $salesPermissions['canCompleteSales'] ? 'true' : 'false'; ?>,
                    canManageSalesPromotions: <?php echo isset($salesPermissions['canManageSalesPromotions']) && $salesPermissions['canManageSalesPromotions'] ? 'true' : 'false'; ?>,
                    canViewSalesDashboard: <?php echo isset($salesPermissions['canViewSalesDashboard']) && $salesPermissions['canViewSalesDashboard'] ? 'true' : 'false'; ?>
                }
            };

            // Debug logging
            console.log('POS Config loaded:', window.POSConfig);
        })();
    </script>
    <script src="../assets/js/pos.js?v=<?php echo time(); ?>"></script>
    <script>
        // Customer Selection Modal Functions
        let selectedCustomer = null;
        let currentSearchTimeout = null;
        
        function openCustomerModal() {
            console.log('Opening customer modal');
            const modal = new bootstrap.Modal(document.getElementById('customerSelectionModal'));
            modal.show();
            
            // Focus on search input when modal opens
            setTimeout(() => {
                document.getElementById('customerSearchInput').focus();
            }, 300);
            
            // Load recent customers by default
            loadCustomers('');
        }
        
        function loadCustomers(searchTerm = '') {
            console.log('Loading customers with search term:', searchTerm);
            
            const loading = document.getElementById('customerLoading');
            const results = document.getElementById('customerResults');
            
            // Show loading state
            loading.style.display = 'block';
            results.innerHTML = '';
            
            // Make AJAX call to search customers
            fetch('sale.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=search_customers&search_term=${encodeURIComponent(searchTerm)}&limit=20`
            })
            .then(response => response.json())
            .then(data => {
                loading.style.display = 'none';
                
                if (data.success) {
                    displayCustomerResults(data.customers);
                } else {
                    console.error('Error loading customers:', data.error);
                    results.innerHTML = `
                        <div class="text-center py-4">
                            <i class="bi bi-exclamation-triangle text-warning" style="font-size: 2rem;"></i>
                            <p class="text-muted mt-2">Error loading customers: ${data.error}</p>
                            <button class="btn btn-primary btn-sm" onclick="loadCustomers('${searchTerm}')">
                                <i class="bi bi-arrow-clockwise me-1"></i> Try Again
                            </button>
                        </div>
                    `;
                }
            })
            .catch(error => {
                loading.style.display = 'none';
                console.error('Network error:', error);
                results.innerHTML = `
                    <div class="text-center py-4">
                        <i class="bi bi-wifi-off text-danger" style="font-size: 2rem;"></i>
                        <p class="text-muted mt-2">Network error. Please check your connection and try again.</p>
                        <button class="btn btn-primary btn-sm" onclick="loadCustomers('${searchTerm}')">
                            <i class="bi bi-arrow-clockwise me-1"></i> Retry
                        </button>
                    </div>
                `;
            });
        }
        
        function displayCustomerResults(customers) {
            const results = document.getElementById('customerResults');
            
            if (customers.length === 0) {
                results.innerHTML = `
                    <div class="text-center py-4">
                        <i class="bi bi-search text-muted" style="font-size: 2rem;"></i>
                        <p class="text-muted mt-2">No customers found matching your search.</p>
                        <button class="btn btn-primary btn-sm" id="createCustomerFromSearch">
                            <i class="bi bi-person-plus me-1"></i> Create New Customer
                        </button>
                    </div>
                `;
                return;
            }
            
            let customersHtml = '<div class="customer-list">';
            
            customers.forEach(customer => {
                const membershipColor = {
                    'Bronze': '#cd7f32',
                    'Silver': '#c0c0c0',
                    'Gold': '#ffd700',
                    'Platinum': '#e5e4e2'
                }[customer.membership_level] || '#6c757d';
                
                const typeIcon = customer.customer_type === 'vip' ? 'star-fill' : 'person-fill';
                const typeColor = customer.customer_type === 'vip' ? '#ffc107' : '#6c757d';
                
                // Helper functions for masking contact info
                function maskEmail(email) {
                    if (!email || email === 'null') return 'No email';
                    const [username, domain] = email.split('@');
                    if (username.length <= 3) {
                        return username + '***@' + domain;
                    }
                    return username.substring(0, 3) + '***@' + domain;
                }
                
                function maskPhone(phone) {
                    if (!phone || phone === 'null') return 'No phone';
                    const cleanPhone = phone.replace(/\D/g, ''); // Remove non-digits
                    if (cleanPhone.length >= 7) {
                        const countryCode = cleanPhone.substring(0, 4); // e.g., +254 or 254
                        const maskedPart = '*'.repeat(6);
                        const lastDigits = cleanPhone.slice(-2);
                        return '+' + countryCode + maskedPart + lastDigits;
                    }
                    return phone.substring(0, 3) + '***' + phone.slice(-2);
                }
                
                // Format contact information safely with masking
                const emailDisplay = maskEmail(customer.email);
                const phoneDisplay = maskPhone(customer.phone);
                const lastVisitDisplay = customer.last_visit && customer.last_visit !== 'null' ? 
                    new Date(customer.last_visit).toLocaleDateString() : 'Never';
                
                customersHtml += `
                    <div class="customer-item" data-customer-id="${customer.id}">
                        <div class="customer-avatar">
                            <i class="bi bi-${typeIcon}" style="color: ${typeColor};"></i>
                        </div>
                        <div class="customer-info">
                            <div class="customer-name">
                                ${customer.first_name} ${customer.last_name}
                                <span class="customer-type-badge" style="background-color: ${membershipColor};">
                                    ${customer.membership_level}
                                </span>
                            </div>
                            <div class="customer-details">
                                <div class="customer-contact">
                                    <div class="contact-item">
                                        <i class="bi bi-envelope me-1"></i>
                                        <span class="contact-text" title="${emailDisplay}">${emailDisplay}</span>
                                    </div>
                                    <div class="contact-item">
                                        <i class="bi bi-telephone me-1"></i>
                                        <span class="contact-text" title="${phoneDisplay}">${phoneDisplay}</span>
                                    </div>
                                </div>
                                <div class="customer-stats">
                                    <span class="customer-number">${customer.customer_number}</span>
                                    <span class="customer-total"><?php echo $settings['currency_symbol']; ?>${customer.total_purchases.toFixed(2)} total</span>
                                    <span class="customer-last-visit">Last visit: ${lastVisitDisplay}</span>
                                </div>
                            </div>
                        </div>
                        <div class="customer-actions">
                            <i class="bi bi-check-circle customer-select-icon"></i>
                        </div>
                    </div>
                `;
            });
            
            customersHtml += '</div>';
            results.innerHTML = customersHtml;
            
            // Add click handlers to customer items
            document.querySelectorAll('.customer-item').forEach(item => {
                item.addEventListener('click', function() {
                    // Remove selection from other items
                    document.querySelectorAll('.customer-item').forEach(i => i.classList.remove('selected'));
                    
                    // Select this item
                    this.classList.add('selected');
                    
                    // Store selected customer data
                    const customerId = parseInt(this.dataset.customerId);
                    selectedCustomer = customers.find(c => c.id === customerId);
                    
                    // Enable select button
                    document.getElementById('selectCustomerBtn').disabled = false;
                });
            });
        }
        
        function selectCustomer(customer) {
            console.log('Selecting customer:', customer);

            // Check if POSConfig is available
            if (!window.POSConfig) {
                console.error('Cannot select customer - POSConfig not available');
                return;
            }

            // Update current customer
            window.POSConfig.currentCustomer = {
                id: customer.id,
                name: customer.first_name + ' ' + customer.last_name,
                type: customer.customer_type,
                is_walk_in: false,
                membership_level: customer.membership_level,
                customer_number: customer.customer_number
            };
            
            // Update UI
            updateCustomerDisplay();
            
            // Close modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('customerSelectionModal'));
            modal.hide();
            
            // Reset selection
            selectedCustomer = null;
            document.getElementById('selectCustomerBtn').disabled = true;
        }
        
        function useWalkInCustomer() {
            console.log('Using walk-in customer');

            // Check if POSConfig is available
            if (!window.POSConfig) {
                console.error('Cannot use walk-in customer - POSConfig not available');
                return;
            }

            // Reset to walk-in customer
            window.POSConfig.currentCustomer = {
                id: <?php echo $walk_in_customer['id']; ?>,
                name: '<?php echo addslashes($walk_in_customer['first_name'] . ' ' . $walk_in_customer['last_name']); ?>',
                type: 'walk_in',
                is_walk_in: true
            };
            
            // Update UI
            updateCustomerDisplay();
            
            // Close modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('customerSelectionModal'));
            modal.hide();
        }
        
        function updateCustomerDisplay() {
            // Check if POSConfig is available
            if (!window.POSConfig || !window.POSConfig.currentCustomer) {
                console.error('Cannot update customer display - POSConfig or currentCustomer not available');
                return;
            }

            const customer = window.POSConfig.currentCustomer;
            console.log('Updating customer display:', customer);
            
            // Update customer name
            document.getElementById('currentCustomer').textContent = customer.name;
            
            // Update customer type and avatar
            const customerType = document.getElementById('customerType');
            const customerAvatar = document.getElementById('customerAvatar');
            
            if (customer.is_walk_in) {
                customerType.textContent = 'Walk-in Customer';
                customerAvatar.innerHTML = '<i class="bi bi-person-walking"></i>';
            } else {
                const typeText = customer.membership_level ? 
                    `${customer.membership_level} Member` : 
                    (customer.type === 'vip' ? 'VIP Customer' : 'Regular Customer');
                customerType.textContent = typeText;
                
                const avatarIcon = customer.type === 'vip' ? 'star-fill' : 'person-fill';
                customerAvatar.innerHTML = `<i class="bi bi-${avatarIcon}"></i>`;
            }
        }
        
        // Apply permission-based UI restrictions
        function applyPermissionRestrictions() {
            if (!window.POSConfig || !window.POSConfig.permissions) {
                console.warn('Cannot apply permission restrictions - POSConfig.permissions not available');
                return;
            }

            const perms = window.POSConfig.permissions;
            console.log('Applying permission restrictions...', perms);

            // Cart Management Restrictions
            if (!perms.canEditCart) {
                // Disable cart item quantity editing
                document.querySelectorAll('.cart-item-quantity input').forEach(input => {
                    input.disabled = true;
                    input.style.opacity = '0.6';
                });
            }

            if (!perms.canClearCart) {
                const clearBtn = document.getElementById('clearCart');
                if (clearBtn) {
                    clearBtn.style.display = 'none';
                }
            }

            if (!perms.canApplyCartDiscounts) {
                // Hide discount input fields
                document.querySelectorAll('.cart-discount-input').forEach(input => {
                    input.style.display = 'none';
                });
            }

            if (!perms.canModifyCartPrices) {
                // Disable price modification
                document.querySelectorAll('.cart-item-price input').forEach(input => {
                    input.disabled = true;
                    input.style.opacity = '0.6';
                });
            }

            // Held Transactions Restrictions
            if (!perms.canViewHeldTransactions) {
                const viewHeldBtn = document.getElementById('viewHeldBtn');
                if (viewHeldBtn) {
                    viewHeldBtn.style.display = 'none';
                }
            }

            if (!perms.canCreateHeldTransactions) {
                const holdBtn = document.getElementById('holdTransactionBtn');
                if (holdBtn) {
                    holdBtn.style.display = 'none';
                }
            }

            // Sales Processing Restrictions
            if (!perms.canCompleteSales) {
                const checkoutBtn = document.getElementById('checkoutBtn');
                if (checkoutBtn) {
                    checkoutBtn.disabled = true;
                    checkoutBtn.textContent = 'Receipt Generation Not Allowed';
                    checkoutBtn.style.opacity = '0.5';
                }
            }

            // Customer Management Restrictions
            if (!perms.canManageSalesCustomers) {
                // Disable customer selection
                const customerSection = document.querySelector('.customer-section');
                if (customerSection) {
                    customerSection.style.cursor = 'default';
                    customerSection.onclick = null;
                }
            }

            // POS Features Restrictions
            if (!perms.canUseBarcodeScanner) {
                const barcodeBtn = document.getElementById('barcodeScanBtn');
                if (barcodeBtn) {
                    barcodeBtn.style.display = 'none';
                }
            }

            // Receipt Management Restrictions
            if (!perms.canPrintReceipts) {
                const printBtn = document.querySelector('.receipt-btn.print');
                if (printBtn) {
                    printBtn.style.display = 'none';
                }
            }

            if (!perms.canEmailReceipts) {
                // Hide email receipt options
                document.querySelectorAll('.email-receipt-option').forEach(option => {
                    option.style.display = 'none';
                });
            }

            // Advanced Features Restrictions
            if (!perms.canManageSalesPromotions) {
                // Hide promotion-related UI elements
                document.querySelectorAll('.promotion-element').forEach(element => {
                    element.style.display = 'none';
                });
            }

            console.log('Permission restrictions applied successfully');
        }

        // Make openCustomerModal globally available
        window.openCustomerModal = openCustomerModal;

        // Real-time clock and date functions
        function updateDateTime() {
            const now = new Date();
            
            // Format time (24-hour format)
            const timeOptions = {
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hour12: false
            };
            const timeString = now.toLocaleTimeString('en-US', timeOptions);
            
            // Format date
            const dateOptions = {
                weekday: 'short',
                month: 'short', 
                day: 'numeric',
                year: 'numeric'
            };
            const dateString = now.toLocaleDateString('en-US', dateOptions);
            
            // Update DOM elements
            const timeElement = document.querySelector('#currentTime .time-text');
            const dateElement = document.querySelector('#currentDate .date-text');
            
            if (timeElement) timeElement.textContent = timeString;
            if (dateElement) dateElement.textContent = dateString;
        }
        
        // Initialize date/time and set up interval
        function initializeClock() {
            updateDateTime(); // Update immediately
            setInterval(updateDateTime, 1000); // Update every second
        }

        // Simple checkout button handler for receipt generation
        function handleReceiptGeneration() {
            console.log('Generating receipt...');

            // Check if there are items in cart
            const cartItems = document.querySelectorAll('.cart-item');
            if (cartItems.length === 0) {
                alert('No items in cart to generate receipt for.');
                return;
            }

            // Show receipt modal
            const receiptModal = new bootstrap.Modal(document.getElementById('receiptModal'));
            receiptModal.show();

            // Here you could add logic to populate receipt data from cart
            // For now, we'll just show the modal
        }

        // Initialize POS UI with configuration
        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM loaded, initializing POS...');

            // Add click handler for receipt generation button
            const checkoutBtn = document.getElementById('checkoutBtn');
            if (checkoutBtn) {
                checkoutBtn.addEventListener('click', handleReceiptGeneration);
                console.log('Receipt generation handler attached');
            }

            // Wait for all scripts to load before initializing
            function initializePOS() {
                console.log('POSUI available:', typeof window.POSUI);
                console.log('POSConfig available:', typeof window.POSConfig);

                if (!window.POSConfig) {
                    console.warn('POSConfig not available, retrying...');
                    setTimeout(initializePOS, 50);
                    return;
                }

                if (window.POSConfig.permissions) {
                    console.log('Permissions loaded:', window.POSConfig.permissions);
                } else {
                    console.warn('POSConfig.permissions not available');
                }

                // Apply permission-based UI restrictions
                if (window.POSConfig.permissions) {
                    try {
                        applyPermissionRestrictions();
                        console.log('Permission restrictions applied successfully');
                    } catch (error) {
                        console.error('Error applying permission restrictions:', error);
                    }
                } else {
                    console.warn('Skipping permission restrictions - permissions not available');
                }

                // Initialize real-time clock
                try {
                    initializeClock();
                } catch (error) {
                    console.error('Error initializing clock:', error);
                }

                // Initialize POS UI
                if (window.POSUI && typeof window.POSUI.init === 'function') {
                    try {
                        window.POSUI.init({
                            currency: window.POSConfig.currencySymbol || '$',
                            customer: window.POSConfig.currentCustomer || null
                        });
                        console.log('POS UI initialized successfully');
                    } catch (error) {
                        console.error('Error initializing POS UI:', error);
                    }
                } else {
                    console.error('POSUI.init not available');
                }

                // Check if product cards exist
                const productCards = document.querySelectorAll('.product-card');
                console.log('Product cards found:', productCards.length);

                // Test product card functionality
                if (productCards.length > 0) {
                    const firstCard = productCards[0];
                    console.log('First product card:', firstCard);
                    console.log('Product data:', {
                        id: firstCard.dataset.productId,
                        name: firstCard.dataset.productName,
                        price: firstCard.dataset.productPrice,
                        stock: firstCard.dataset.productStock
                    });
                }
            }

            // Start initialization with a small delay to ensure all scripts are loaded
            setTimeout(initializePOS, 10);
            
            
            // Fallback: Direct click handler on product cards
            setTimeout(() => {
                console.log('Setting up fallback click handlers...');
                const productCards = document.querySelectorAll('.product-card');
                productCards.forEach(card => {
                    card.style.cursor = 'pointer';
                    card.addEventListener('click', function(e) {
                        console.log('Fallback click handler triggered on:', this);
                        
                        const productData = {
                            id: this.dataset.productId,
                            name: this.dataset.productName,
                            price: parseFloat(this.dataset.productPrice),
                            stock: parseInt(this.dataset.productStock)
                        };
                        
                        console.log('Product data:', productData);
                        
                        // Subtle success feedback for busy store
                        this.classList.add('adding-to-cart');
                        
                        // Add to cart animation
                        setTimeout(() => {
                            this.classList.remove('adding-to-cart');
                            this.classList.add('just-added');
                            
                            // Show minimal success feedback
                            const feedback = document.createElement('div');
                            feedback.className = 'success-feedback';
                            feedback.innerHTML = '<i class="bi bi-check-circle-fill"></i> Added';
                            feedback.style.position = 'absolute';
                            feedback.style.top = '50%';
                            feedback.style.left = '50%';
                            feedback.style.transform = 'translate(-50%, -50%)';
                            feedback.style.background = '#10b981';
                            feedback.style.color = 'white';
                            feedback.style.padding = '4px 8px';
                            feedback.style.borderRadius = '4px';
                            feedback.style.fontSize = '0.75rem';
                            feedback.style.fontWeight = '600';
                            feedback.style.zIndex = '1000';
                            feedback.style.pointerEvents = 'none';
                            feedback.style.animation = 'successFade 1.2s ease-out';
                            
                            this.style.position = 'relative';
                            this.appendChild(feedback);
                            
                            // Update cart count (fake for now)
                            const cartCount = document.getElementById('cartItemCount');
                            if (cartCount) {
                                const currentCount = parseInt(cartCount.textContent) || 0;
                                cartCount.textContent = currentCount + 1;
                            }
                            
                            // Remove feedback and reset state
                            setTimeout(() => {
                                this.classList.remove('just-added');
                                if (feedback.parentNode) {
                                    feedback.remove();
                                }
                            }, 1200);
                        }, 100);
                    });
                });
                console.log(`Added fallback click handlers to ${productCards.length} product cards`);
            }, 3000);
            
            // Customer modal event handlers
            
            // Search input with debounce
            document.getElementById('customerSearchInput').addEventListener('input', function(e) {
                const searchTerm = e.target.value;
                
                // Clear existing timeout
                if (currentSearchTimeout) {
                    clearTimeout(currentSearchTimeout);
                }
                
                // Set new timeout for debounced search
                currentSearchTimeout = setTimeout(() => {
                    loadCustomers(searchTerm);
                }, 300);
            });
            
            // Search button click
            document.getElementById('customerSearchBtn').addEventListener('click', function() {
                const searchTerm = document.getElementById('customerSearchInput').value;
                loadCustomers(searchTerm);
            });
            
            // Enter key in search input
            document.getElementById('customerSearchInput').addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    const searchTerm = e.target.value;
                    loadCustomers(searchTerm);
                }
            });
            
            // Use Walk-in Customer button
            document.getElementById('useWalkInBtn').addEventListener('click', function() {
                useWalkInCustomer();
            });
            
            // Add New Customer button
            document.getElementById('addNewCustomerBtn').addEventListener('click', function() {
                console.log('Add new customer clicked - would open customer creation form');
                // TODO: Implement customer creation modal/form
                alert('Customer creation feature will be implemented in a future update.');
            });
            
            // Select Customer button
            document.getElementById('selectCustomerBtn').addEventListener('click', function() {
                if (selectedCustomer) {
                    selectCustomer(selectedCustomer);
                }
            });
        });
    </script>
</body>
</html>
