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

// Check if user has permission to create returns
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

// Function to generate return number
function generateReturnNumber($settings) {
    $prefix = $settings['return_number_prefix'] ?? 'RTN';
    $length = intval($settings['return_number_length'] ?? 6);
    $separator = $settings['return_number_separator'] ?? '-';

    $sequentialNumber = getNextReturnNumber($conn, $length);
    $currentDate = date('Ymd');

    return $prefix . $separator . $currentDate . $separator . $sequentialNumber;
}

// Function to get next return number
function getNextReturnNumber($conn, $length) {
    try {
        // Get the highest return number for today
        $today = date('Ymd');
        $stmt = $conn->prepare("
            SELECT return_number 
            FROM returns 
            WHERE return_number LIKE ? 
            ORDER BY return_number DESC 
            LIMIT 1
        ");
        $stmt->execute(["RTN-{$today}-%"]);
        $lastReturn = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($lastReturn) {
            // Extract the sequential number and increment
            $parts = explode('-', $lastReturn['return_number']);
            $lastNumber = intval(end($parts));
            $nextNumber = $lastNumber + 1;
        } else {
            // First return of the day
            $nextNumber = 1;
        }
        
        return str_pad($nextNumber, $length, '0', STR_PAD_LEFT);
    } catch (PDOException $e) {
        error_log("Error getting next return number: " . $e->getMessage());
        // Fallback to random number
        return str_pad(mt_rand(1, pow(10, $length) - 1), $length, '0', STR_PAD_LEFT);
    }
}

// Get suppliers for dropdown
$suppliers = [];
try {
    $stmt = $conn->query("SELECT id, name FROM suppliers ORDER BY name ASC");
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error loading suppliers: " . $e->getMessage());
}

// Handle form submission
$message = '';
$message_type = '';
$return_data = [];

// Handle AJAX requests for order data
if (isset($_GET['action']) && $_GET['action'] === 'get_order_data') {
    header('Content-Type: application/json');

    $order_id = $_GET['order_id'] ?? '';
    if (empty($order_id)) {
        echo json_encode(['success' => false, 'message' => 'Order ID is required']);
        exit();
    }

    try {
        // Get order details with items that have been received
        $stmt = $conn->prepare("
            SELECT io.*,
                   s.name as supplier_name, s.contact_person, s.phone, s.email, s.address,
                   u.username as created_by_name
            FROM inventory_orders io
            LEFT JOIN suppliers s ON io.supplier_id = s.id
            LEFT JOIN users u ON io.user_id = u.id
            WHERE (io.id = :order_id OR io.order_number = :order_number)
            AND io.status = 'received'
        ");
        $stmt->execute([
            ':order_id' => is_numeric($order_id) ? $order_id : 0,
            ':order_number' => $order_id
        ]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            echo json_encode(['success' => false, 'message' => 'Order not found or not yet received']);
            exit();
        }

        // Get order items that have been received
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
            AND ioi.received_quantity > 0
            ORDER BY ioi.id ASC
        ");
        $stmt->bindParam(':order_id', $order['id']);
        $stmt->execute();
        $order['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'order' => $order]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}

// Handle AJAX requests for product search
if (isset($_GET['action']) && $_GET['action'] === 'search_products') {
    header('Content-Type: application/json');

    $query = $_GET['q'] ?? '';
    $supplier_id = $_GET['supplier_id'] ?? '';

    if (empty($query)) {
        echo json_encode(['success' => false, 'message' => 'Search query is required']);
        exit();
    }

    try {
        // Debug logging
        error_log("Search query: " . $query);
        error_log("Supplier ID: " . $supplier_id);
        
        // First try to search products from received orders for this supplier
        $sql = "
            SELECT DISTINCT p.id, p.name, p.sku, p.barcode, p.description, p.image_url,
                   p.quantity as current_stock, p.cost_price,
                   c.name as category_name, b.name as brand_name,
                   MAX(ioi.received_quantity) as max_return_qty,
                   MAX(ioi.cost_price) as order_cost_price
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN brands b ON p.brand_id = b.id
            INNER JOIN inventory_order_items ioi ON p.id = ioi.product_id
            INNER JOIN inventory_orders io ON ioi.order_id = io.id
            WHERE io.status = 'received'
            AND io.supplier_id = :supplier_id
            AND ioi.received_quantity > 0
            AND (p.name LIKE :query 
                 OR p.sku LIKE :query 
                 OR p.barcode LIKE :query 
                 OR p.description LIKE :query
                 OR c.name LIKE :query
                 OR b.name LIKE :query
                 OR CONCAT(p.name, ' ', p.sku, ' ', COALESCE(p.barcode, ''), ' ', COALESCE(p.description, '')) LIKE :query)
            GROUP BY p.id, p.name, p.sku, p.barcode, p.description, p.image_url, p.quantity, p.cost_price, c.name, b.name
            ORDER BY p.name ASC
            LIMIT 20
        ";

        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':supplier_id' => $supplier_id,
            ':query' => '%' . $query . '%'
        ]);

        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // If no products found from received orders, search all products
        if (empty($products)) {
            error_log("No products found from received orders, searching all products");
            
            $fallback_sql = "
                SELECT DISTINCT p.id, p.name, p.sku, p.barcode, p.description, p.image_url,
                       p.quantity as current_stock, p.cost_price,
                       c.name as category_name, b.name as brand_name,
                       p.quantity as max_return_qty,
                       p.cost_price as order_cost_price
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                LEFT JOIN brands b ON p.brand_id = b.id
                WHERE (p.name LIKE :query 
                     OR p.sku LIKE :query 
                     OR p.barcode LIKE :query 
                     OR p.description LIKE :query
                     OR c.name LIKE :query
                     OR b.name LIKE :query
                     OR CONCAT(p.name, ' ', p.sku, ' ', COALESCE(p.barcode, ''), ' ', COALESCE(p.description, '')) LIKE :query)
                ORDER BY p.name ASC
                LIMIT 20
            ";
            
            $stmt = $conn->prepare($fallback_sql);
            $stmt->execute([
                ':query' => '%' . $query . '%'
            ]);
            
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // Debug logging
        error_log("Found " . count($products) . " products");
        
        echo json_encode(['success' => true, 'products' => $products, 'debug' => [
            'query' => $query,
            'supplier_id' => $supplier_id,
            'count' => count($products)
        ]]);
    } catch (PDOException $e) {
        error_log("Search error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}

// Handle return creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_return') {
    $supplier_id = $_POST['supplier_id'] ?? '';
    $return_reason = $_POST['return_reason'] ?? '';
    $return_notes = $_POST['return_notes'] ?? '';
    $return_items = json_decode($_POST['return_items'] ?? '[]', true);

    // Validation
    if (empty($supplier_id)) {
        $message = "Please select a supplier";
        $message_type = 'danger';
    } elseif (empty($return_reason)) {
        $message = "Please select a return reason";
        $message_type = 'danger';
    } elseif (empty($return_items)) {
        $message = "Please add at least one item to return";
        $message_type = 'danger';
    } else {
        try {
            $conn->beginTransaction();

            // Generate return number
            $return_number = generateReturnNumber($settings);

            // Calculate totals
            $total_items = 0;
            $total_amount = 0;
            foreach ($return_items as $item) {
                $total_items += $item['quantity'];
                $total_amount += ($item['quantity'] * $item['cost_price']);
            }

            // Insert return record (you may need to create a returns table)
            // For now, we'll use a simple approach - you should create a proper returns table
            $stmt = $conn->prepare("
                INSERT INTO returns (
                    return_number, supplier_id, user_id, return_reason,
                    return_notes, total_items, total_amount,
                    status, created_at, updated_at
                ) VALUES (
                    :return_number, :supplier_id, :user_id, :return_reason,
                    :return_notes, :total_items, :total_amount,
                    'pending', NOW(), NOW()
                )
            ");

            $stmt->execute([
                ':return_number' => $return_number,
                ':supplier_id' => $supplier_id,
                ':user_id' => $user_id,
                ':return_reason' => $return_reason,
                ':return_notes' => $return_notes,
                ':total_items' => $total_items,
                ':total_amount' => $total_amount
            ]);

            $return_id = $conn->lastInsertId();

            // Insert return items
            $stmt = $conn->prepare("
                INSERT INTO return_items (
                    return_id, product_id, quantity, cost_price,
                    return_reason, notes
                ) VALUES (
                    :return_id, :product_id, :quantity, :cost_price,
                    :return_reason, :notes
                )
            ");

            foreach ($return_items as $item) {
                $stmt->execute([
                    ':return_id' => $return_id,
                    ':product_id' => $item['product_id'],
                    ':quantity' => $item['quantity'],
                    ':cost_price' => $item['cost_price'],
                    ':return_reason' => $item['return_reason'] ?? '',
                    ':notes' => $item['notes'] ?? ''
                ]);

                // Update product stock (decrease quantity)
                $update_stmt = $conn->prepare("
                    UPDATE products
                    SET quantity = quantity - :return_qty,
                        updated_at = NOW()
                    WHERE id = :product_id
                ");
                $update_stmt->execute([
                    ':return_qty' => $item['quantity'],
                    ':product_id' => $item['product_id']
                ]);
            }

            // Log activity
            logActivity($conn, $user_id, 'return_created',
                "Created return {$return_number} for supplier ID {$supplier_id}");

            $conn->commit();

            // Redirect to return view or success page
            header("Location: view_return.php?id=" . $return_id . "&success=return_created");
            exit();

        } catch (PDOException $e) {
            $conn->rollBack();
            $message = "Error creating return: " . $e->getMessage();
            $message_type = 'danger';
            error_log("Return creation error: " . $e->getMessage());
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Return - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
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

        .return-form {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }

        .return-form-header {
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 1rem;
            margin-bottom: 1.5rem;
        }

        .return-form-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.5rem;
        }

        .return-form-subtitle {
            color: #64748b;
            font-size: 0.875rem;
        }

        .step-indicator {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-bottom: 2rem;
        }

        .step {
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            z-index: 1;
        }

        .step-circle {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #e9ecef;
            color: #6c757d;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .step.active .step-circle {
            background: var(--primary-color);
            color: white;
        }

        .step.completed .step-circle {
            background: #28a745;
            color: white;
        }

        .step-label {
            font-size: 0.875rem;
            margin-top: 0.5rem;
            color: #6c757d;
            font-weight: 500;
            text-align: center;
            min-width: 80px;
        }

        .step.active .step-label {
            color: var(--primary-color);
            font-weight: 600;
        }

        .step.completed .step-label {
            color: #28a745;
        }

        .step-line {
            flex: 1;
            height: 2px;
            background: #e9ecef;
            margin: 0 1rem;
            position: relative;
            top: -20px;
        }

        .step.completed + .step-line {
            background: #28a745;
        }

        .step.active ~ .step-line {
            background: var(--primary-color);
        }

        .product-selection {
            max-height: 400px;
            overflow-y: auto;
        }

        .product-item {
            display: flex;
            align-items: center;
            padding: 1rem;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            margin-bottom: 0.5rem;
            background: white;
            transition: all 0.2s ease;
        }

        .product-item:hover {
            border-color: var(--primary-color);
            box-shadow: 0 2px 8px rgba(99, 102, 241, 0.1);
        }

        .product-info {
            flex: 1;
            margin-left: 1rem;
        }

        .product-name {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.25rem;
        }

        .product-details {
            color: #64748b;
            font-size: 0.875rem;
        }

        .return-cart {
            background: #f8fafc;
            border: 2px solid #10b981;
            border-radius: 8px;
            padding: 1.5rem;
            margin-top: 2rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .return-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            margin-bottom: 0.5rem;
            background: white;
        }

        .return-item-info {
            flex: 1;
        }

        .return-item-controls {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .quantity-controls {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .quantity-btn {
            width: 30px;
            height: 30px;
            border: 1px solid #d1d5db;
            background: white;
            border-radius: 4px;
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

        .quantity-input {
            width: 60px;
            text-align: center;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            padding: 0.25rem;
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
                    <h2>Create Product Return</h2>
                    <p class="header-subtitle">Process returns for defective or unwanted products</p>
                </div>
                <div class="header-actions">
                    <a href="inventory.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-2"></i>Back to Inventory
                    </a>
                    <a href="view_returns.php" class="btn btn-outline-primary">
                        <i class="bi bi-list me-2"></i>View Returns
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

            <!-- Step Indicator -->
            <div class="step-indicator">
                <div class="step active" id="step1">
                    <div class="step-circle">1</div>
                    <div class="step-label">Select Supplier</div>
                </div>
                <div class="step-line"></div>
                <div class="step" id="step2">
                    <div class="step-circle">2</div>
                    <div class="step-label">Choose Products</div>
                </div>
                <div class="step-line"></div>
                <div class="step" id="step3">
                    <div class="step-circle">3</div>
                    <div class="step-label">Review & Submit</div>
                </div>
            </div>

            <form id="returnForm" method="POST">
                <input type="hidden" name="action" value="create_return">
                <input type="hidden" name="return_items" id="returnItemsInput" value="[]">

                <!-- Step 1: Supplier Selection -->
                <div class="return-form" id="supplierStep">
                    <div class="return-form-header">
                        <h3 class="return-form-title"><i class="bi bi-building me-2"></i>Step 1: Select Supplier</h3>
                        <p class="return-form-subtitle">Choose the supplier you're returning products to</p>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <label for="supplierSelect" class="form-label">Supplier <span class="text-danger">*</span></label>
                            <select class="form-select" id="supplierSelect" name="supplier_id" required>
                                <option value="">Choose a supplier...</option>
                                <?php foreach ($suppliers as $supplier): ?>
                                <option value="<?php echo $supplier['id']; ?>">
                                    <?php echo htmlspecialchars($supplier['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="returnReason" class="form-label">Return Reason <span class="text-danger">*</span></label>
                            <select class="form-select" id="returnReason" name="return_reason" required>
                                <option value="">Select return reason...</option>
                                <option value="defective">Defective Products</option>
                                <option value="wrong_item">Wrong Items Received</option>
                                <option value="damaged">Damaged in Transit</option>
                                <option value="expired">Expired Products</option>
                                <option value="overstock">Overstock/Excess Inventory</option>
                                <option value="quality">Quality Issues</option>
                                <option value="recall">Product Recall</option>
                                <option value="customer_return">Customer Return</option>
                                <option value="warranty">Warranty Claim</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>

                    <div class="row mt-3">
                        <div class="col-12">
                            <label for="returnNotes" class="form-label">Return Notes</label>
                            <textarea class="form-control" id="returnNotes" name="return_notes" rows="3"
                                      placeholder="Additional notes about this return..."></textarea>
                        </div>
                    </div>

                    <div class="mt-4">
                        <button type="button" class="btn btn-primary" id="nextToProductsBtn" disabled>
                            <i class="bi bi-arrow-right me-2"></i>Next: Select Products
                        </button>
                    </div>
                </div>

                <!-- Step 2: Product Selection -->
                <div class="return-form d-none" id="productsStep">
                    <div class="return-form-header">
                        <h3 class="return-form-title"><i class="bi bi-box-seam me-2"></i>Step 2: Select Products to Return</h3>
                        <p class="return-form-subtitle">Choose products from received orders to return to the supplier</p>
                    </div>

                    <!-- Product Search -->
                    <div class="mb-4">
                        <label for="productSearch" class="form-label">Search Products</label>
                        <div class="input-group">
                            <input type="text" class="form-control" id="productSearch"
                                   placeholder="Search by name, SKU, barcode, description, category, or brand...">
                            <button class="btn btn-outline-secondary" type="button" id="searchBtn">
                                <i class="bi bi-search"></i>
                            </button>
                            <button class="btn btn-outline-info" type="button" id="testSearchBtn" title="Test search with sample data">
                                <i class="bi bi-bug"></i>
                            </button>
                        </div>
                        <div class="form-text">Search by product name, SKU, barcode, description, category, or brand. Only products from received orders for the selected supplier will be shown.</div>
                    </div>

                    <!-- Product List -->
                    <div id="productList" class="product-selection">
                        <div class="text-center text-muted py-4">
                            <i class="bi bi-search fs-1 mb-3"></i>
                            <p>Select a supplier and search for products to get started.</p>
                        </div>
                    </div>

                    <!-- Return Cart -->
                    <div class="return-cart" id="returnCart" style="display: none;">
                        <h5 class="mb-3"><i class="bi bi-cart me-2"></i>Return Items</h5>
                        <div id="returnItemsList"></div>
                        <div class="mt-3">
                            <button type="button" class="btn btn-success" id="proceedToReviewBtn">
                                <i class="bi bi-check-circle me-2"></i>Review Return
                            </button>
                        </div>
                    </div>

                    <div class="mt-4 d-flex justify-content-between">
                        <button type="button" class="btn btn-outline-secondary" id="backToSupplierBtn">
                            <i class="bi bi-arrow-left me-2"></i>Back to Supplier
                        </button>
                        <button type="button" class="btn btn-primary btn-lg" id="nextToReviewBtn" style="display: none;">
                            <i class="bi bi-arrow-right me-2"></i>Next: Review & Submit
                        </button>
                    </div>
                </div>

                <!-- Step 3: Review and Submit -->
                <div class="return-form d-none" id="reviewStep">
                    <div class="return-form-header">
                        <h3 class="return-form-title"><i class="bi bi-check-circle me-2"></i>Step 3: Review Return</h3>
                        <p class="return-form-subtitle">Review your return details before submitting</p>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header">
                                    <h6 class="mb-0">Supplier Information</h6>
                                </div>
                                <div class="card-body">
                                    <p class="mb-1"><strong>Supplier:</strong> <span id="reviewSupplierName">Not selected</span></p>
                                    <p class="mb-1"><strong>Reason:</strong> <span id="reviewReturnReason">Not selected</span></p>
                                    <p class="mb-0"><strong>Notes:</strong> <span id="reviewReturnNotes">None</span></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header">
                                    <h6 class="mb-0">Return Summary</h6>
                                </div>
                                <div class="card-body">
                                    <p class="mb-1"><strong>Total Items:</strong> <span id="reviewTotalItems">0</span></p>
                                    <p class="mb-1"><strong>Total Value:</strong> <span id="reviewTotalValue">$0.00</span></p>
                                    <p class="mb-1"><strong>Return Number:</strong> <span id="reviewReturnNumber" class="text-primary fw-bold">Will be generated</span></p>
                                    <p class="mb-0"><strong>Created By:</strong> <span id="reviewCreatedBy"><?php echo htmlspecialchars($username); ?></span></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4">
                        <h6>Items to Return:</h6>
                        <div id="reviewItemsList" class="mt-3">
                            <div class="text-center text-muted py-4">
                                <i class="bi bi-cart-x fs-1 mb-3"></i>
                                <p>No items selected for return.</p>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 d-flex gap-2">
                        <button type="button" class="btn btn-outline-secondary" id="backToProductsBtn">
                            <i class="bi bi-arrow-left me-2"></i>Back to Products
                        </button>
                        <button type="submit" class="btn btn-success" id="submitReturnBtn" disabled>
                            <i class="bi bi-send me-2"></i>Create Return
                        </button>
                        <button type="button" class="btn btn-warning" id="debugSubmitBtn" onclick="debugSubmit()">
                            <i class="bi bi-bug me-2"></i>Debug Submit
                        </button>
                    </div>
                </div>
            </form>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Global variables
        let selectedSupplier = null;
        let returnItems = [];
        let currentStep = 1;

        // Step management
        function showStep(stepNumber) {
            console.log('Showing step:', stepNumber);
            
            // Hide all steps
            document.getElementById('supplierStep').classList.add('d-none');
            document.getElementById('productsStep').classList.add('d-none');
            document.getElementById('reviewStep').classList.add('d-none');

            // Update step indicators
            document.getElementById('step1').classList.remove('active', 'completed');
            document.getElementById('step2').classList.remove('active', 'completed');
            document.getElementById('step3').classList.remove('active', 'completed');

            // Show current step
            if (stepNumber === 1) {
                document.getElementById('supplierStep').classList.remove('d-none');
                document.getElementById('step1').classList.add('active');
            } else if (stepNumber === 2) {
                document.getElementById('supplierStep').classList.remove('d-none');
                document.getElementById('productsStep').classList.remove('d-none');
                document.getElementById('step1').classList.add('completed');
                document.getElementById('step2').classList.add('active');
            } else if (stepNumber === 3) {
                document.getElementById('supplierStep').classList.remove('d-none');
                document.getElementById('productsStep').classList.remove('d-none');
                document.getElementById('reviewStep').classList.remove('d-none');
                document.getElementById('step1').classList.add('completed');
                document.getElementById('step2').classList.add('completed');
                document.getElementById('step3').classList.add('active');
            }

            currentStep = stepNumber;
            console.log('Current step set to:', currentStep);
        }

        // Supplier selection
        document.getElementById('supplierSelect').addEventListener('change', function() {
            selectedSupplier = this.value;
            document.getElementById('nextToProductsBtn').disabled = !selectedSupplier;

            if (selectedSupplier) {
                // Clear product search and list
                document.getElementById('productSearch').value = '';
                document.getElementById('productList').innerHTML = `
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-search fs-1 mb-3"></i>
                        <p>Search for products to add to your return.</p>
                    </div>
                `;
            }
        });

        // Next to products button
        document.getElementById('nextToProductsBtn').addEventListener('click', function() {
            if (!selectedSupplier) {
                alert('Please select a supplier first.');
                return;
            }
            showStep(2);
        });

        // Back to supplier button
        document.getElementById('backToSupplierBtn').addEventListener('click', function() {
            showStep(1);
        });

        // Product search
        document.getElementById('productSearch').addEventListener('input', function() {
            const query = this.value.trim();
            if (query.length >= 2) {
                searchProducts(query);
            } else {
                document.getElementById('productList').innerHTML = `
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-search fs-1 mb-3"></i>
                        <p>Type at least 2 characters to search for products.</p>
                    </div>
                `;
            }
        });

        document.getElementById('searchBtn').addEventListener('click', function() {
            const query = document.getElementById('productSearch').value.trim();
            if (query.length >= 2) {
                searchProducts(query);
            }
        });

        // Test search button
        document.getElementById('testSearchBtn').addEventListener('click', function() {
            if (!selectedSupplier) {
                alert('Please select a supplier first.');
                return;
            }
            
            // Test with a simple query
            document.getElementById('productSearch').value = 'test';
            searchProducts('test');
        });

        // Search products function
        function searchProducts(query) {
            if (!selectedSupplier) {
                alert('Please select a supplier first.');
                return;
            }

            // Show loading state
            document.getElementById('productList').innerHTML = `
                <div class="text-center text-muted py-4">
                    <i class="bi bi-hourglass-split fs-1 mb-3"></i>
                    <p>Searching for products...</p>
                </div>
            `;

            console.log('Searching for:', query, 'Supplier:', selectedSupplier);

            fetch(`?action=search_products&q=${encodeURIComponent(query)}&supplier_id=${selectedSupplier}`)
                .then(response => {
                    console.log('Response status:', response.status);
                    return response.json();
                })
                .then(data => {
                    console.log('Search response:', data);
                    if (data.success) {
                        displayProducts(data.products);
                    } else {
                        document.getElementById('productList').innerHTML = `
                            <div class="alert alert-danger">
                                <i class="bi bi-exclamation-triangle me-2"></i>${data.message}
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Search error:', error);
                    document.getElementById('productList').innerHTML = `
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle me-2"></i>Search failed. Please try again.
                        </div>
                    `;
                });
        }

        // Display products
        function displayProducts(products) {
            if (products.length === 0) {
                document.getElementById('productList').innerHTML = `
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-search fs-1 mb-3"></i>
                        <p>No products found matching your search.</p>
                    </div>
                `;
                return;
            }

            let html = '';
            products.forEach(product => {
                const existingItem = returnItems.find(item => item.product_id === product.id);
                const maxReturnQty = product.max_return_qty || 0;

                html += `
                    <div class="product-item">
                        ${product.image_url ? `<img src="${product.image_url}" alt="${product.name}" class="rounded" style="width: 50px; height: 50px; object-fit: cover; margin-right: 1rem;">` : '<div style="width: 50px; height: 50px; background: #f8f9fa; border-radius: 8px; margin-right: 1rem; display: flex; align-items: center; justify-content: center;"><i class="bi bi-image text-muted"></i></div>'}
                        <div class="product-info">
                            <div class="product-name">${product.name}</div>
                            <div class="product-details">
                                SKU: ${product.sku || 'N/A'} |
                                ${product.barcode ? `Barcode: ${product.barcode} |` : ''}
                                Category: ${product.category_name || 'N/A'} |
                                Brand: ${product.brand_name || 'N/A'} |
                                Current Stock: ${product.current_stock}
                                ${maxReturnQty > 0 ? ` | Max Return: ${maxReturnQty}` : ''}
                            </div>
                        </div>
                        <div class="ms-auto">
                            ${existingItem ?
                                `<button type="button" class="btn btn-success btn-sm" disabled>
                                    <i class="bi bi-check-circle me-1"></i>Added
                                </button>` :
                                `<button type="button" class="btn btn-primary btn-sm" onclick="addToReturn(${product.id}, '${product.name.replace(/'/g, "\\'")}', '${(product.sku || '').replace(/'/g, "\\'")}', '${(product.barcode || '').replace(/'/g, "\\'")}', ${product.order_cost_price || product.cost_price}, ${maxReturnQty})">
                                    <i class="bi bi-plus-circle me-1"></i>Add to Return
                                </button>`
                            }
                        </div>
                    </div>
                `;
            });

            document.getElementById('productList').innerHTML = html;
        }

        // Add product to return
        function addToReturn(productId, productName, sku, barcode, costPrice, maxQty) {
            // If maxQty is 0, allow any quantity (no limit)
            const maxLimit = maxQty > 0 ? maxQty : 'unlimited';
            const quantity = prompt(`Enter quantity to return for "${productName}"${maxQty > 0 ? ` (max: ${maxQty})` : ' (no limit)'}:`, '1');

            if (quantity === null) return;

            const qty = parseInt(quantity);
            if (isNaN(qty) || qty <= 0) {
                alert('Please enter a valid quantity.');
                return;
            }

            // Only enforce max quantity limit if maxQty > 0
            if (maxQty > 0 && qty > maxQty) {
                alert(`You can return maximum ${maxQty} units of this product.`);
                return;
            }

            // Add to return items
            returnItems.push({
                product_id: productId,
                product_name: productName,
                sku: sku,
                barcode: barcode,
                quantity: qty,
                cost_price: costPrice,
                return_reason: '',
                notes: ''
            });

            console.log('Added item to return:', returnItems[returnItems.length - 1]);
            console.log('Total return items:', returnItems.length);
            
            updateReturnCart();
            document.getElementById('returnCart').style.display = 'block';
        }

        // Update return cart
        function updateReturnCart() {
            if (returnItems.length === 0) {
                document.getElementById('returnItemsList').innerHTML = '<p class="text-muted">No items added yet.</p>';
                document.getElementById('returnCart').style.display = 'none';
                document.getElementById('nextToReviewBtn').style.display = 'none';
                return;
            }

            let html = '';
            let totalItems = 0;
            let totalValue = 0;

            returnItems.forEach((item, index) => {
                totalItems += item.quantity;
                totalValue += (item.quantity * item.cost_price);

                html += `
                    <div class="return-item">
                        <div class="return-item-info">
                            <div class="fw-semibold">${item.product_name}</div>
                            <div class="text-muted small">
                                Quantity: ${item.quantity} | Unit Price: $${item.cost_price.toFixed(2)} | Total: $${(item.quantity * item.cost_price).toFixed(2)}
                            </div>
                        </div>
                        <div class="return-item-controls">
                            <div class="quantity-controls">
                                <button type="button" class="quantity-btn" onclick="updateReturnQuantity(${index}, ${item.quantity - 1})">-</button>
                                <input type="number" class="quantity-input" value="${item.quantity}" min="1" onchange="updateReturnQuantity(${index}, this.value)">
                                <button type="button" class="quantity-btn" onclick="updateReturnQuantity(${index}, ${item.quantity + 1})">+</button>
                            </div>
                            <button type="button" class="btn btn-outline-danger btn-sm" onclick="removeFromReturn(${index})">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                `;
            });

            document.getElementById('returnItemsList').innerHTML = html;
            document.getElementById('proceedToReviewBtn').innerHTML = `
                <i class="bi bi-check-circle me-2"></i>Review Return (${totalItems} items - $${totalValue.toFixed(2)})
            `;
            
            // Show the return cart and next step button when there are items
            document.getElementById('returnCart').style.display = 'block';
            document.getElementById('nextToReviewBtn').style.display = 'block';
            console.log('Return cart shown with', returnItems.length, 'items');
        }

        // Update return quantity
        function updateReturnQuantity(index, newQty) {
            newQty = parseInt(newQty);
            if (isNaN(newQty) || newQty <= 0) return;

            returnItems[index].quantity = newQty;
            updateReturnCart();
        }

        // Remove from return
        function removeFromReturn(index) {
            returnItems.splice(index, 1);
            updateReturnCart();
        }

        // Proceed to review
        document.getElementById('proceedToReviewBtn').addEventListener('click', function() {
            if (returnItems.length === 0) {
                alert('Please add at least one item to return.');
                return;
            }
            console.log('Proceeding to review with items:', returnItems);
            updateReview();
            showStep(3);
        });

        // Back to products
        document.getElementById('backToProductsBtn').addEventListener('click', function() {
            showStep(2);
        });

        // Next to review button
        document.getElementById('nextToReviewBtn').addEventListener('click', function() {
            if (returnItems.length === 0) {
                alert('Please add at least one item to return.');
                return;
            }
            console.log('Proceeding to review with items:', returnItems);
            updateReview();
            showStep(3);
        });

        // Update review
        function updateReview() {
            // Update supplier info
            const supplierSelect = document.getElementById('supplierSelect');
            const supplierName = supplierSelect.options[supplierSelect.selectedIndex].text;
            document.getElementById('reviewSupplierName').textContent = supplierName;

            // Update return reason
            const reasonSelect = document.getElementById('returnReason');
            const reasonText = reasonSelect.options[reasonSelect.selectedIndex].text;
            document.getElementById('reviewReturnReason').textContent = reasonText;

            // Update notes
            const notes = document.getElementById('returnNotes').value;
            document.getElementById('reviewReturnNotes').textContent = notes || 'None';

            // Update totals
            let totalItems = 0;
            let totalValue = 0;
            returnItems.forEach(item => {
                totalItems += item.quantity;
                totalValue += (item.quantity * item.cost_price);
            });

            document.getElementById('reviewTotalItems').textContent = totalItems;
            document.getElementById('reviewTotalValue').textContent = `$${totalValue.toFixed(2)}`;

            // Generate return number preview
            const today = new Date().toISOString().slice(0, 10).replace(/-/g, '');
            const returnNumber = `RTN-${today}-${String(Math.floor(Math.random() * 1000000)).padStart(6, '0')}`;
            document.getElementById('reviewReturnNumber').textContent = returnNumber;

            // Update items list
            let itemsHtml = '';
            returnItems.forEach(item => {
                itemsHtml += `
                    <div class="card mb-2">
                        <div class="card-body py-2">
                            <div class="row align-items-center">
                                <div class="col-md-6">
                                    <strong>${item.product_name}</strong>
                                    <br><small class="text-muted">SKU: ${item.sku || 'N/A'} ${item.barcode ? `| Barcode: ${item.barcode}` : ''}</small>
                                </div>
                                <div class="col-md-2 text-center">
                                    <span class="badge bg-primary">${item.quantity}</span>
                                </div>
                                <div class="col-md-2 text-end">
                                    $${item.cost_price.toFixed(2)}
                                </div>
                                <div class="col-md-2 text-end">
                                    <strong>$${(item.quantity * item.cost_price).toFixed(2)}</strong>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            });
            document.getElementById('reviewItemsList').innerHTML = itemsHtml;

            // Update form data
            document.getElementById('returnItemsInput').value = JSON.stringify(returnItems);
            document.getElementById('submitReturnBtn').disabled = false;
            
            console.log('Review updated, submit button enabled:', document.getElementById('submitReturnBtn').disabled);
        }

        // Form submission
        document.getElementById('returnForm').addEventListener('submit', function(e) {
            document.getElementById('submitReturnBtn').disabled = true;
            document.getElementById('submitReturnBtn').innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Creating Return...';
        });

        // Debug submit function
        function debugSubmit() {
            console.log('Debug Submit clicked');
            console.log('Current step:', currentStep);
            console.log('Return items:', returnItems);
            console.log('Submit button disabled:', document.getElementById('submitReturnBtn').disabled);
            console.log('Form data:', document.getElementById('returnItemsInput').value);
            
            // Force enable submit button for testing
            document.getElementById('submitReturnBtn').disabled = false;
            console.log('Submit button enabled for testing');
        }

        // Auto-hide alerts
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
