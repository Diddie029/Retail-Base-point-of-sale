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

// Check if user has permission to create orders
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
        case 'date-prefix-number':
            return $currentDate . $separator . $prefix . $separator . $sequentialNumber;
        case 'number-only':
            return $sequentialNumber;
        default:
            return $prefix . $separator . $currentDate . $separator . $sequentialNumber;
    }
}

function getNextOrderNumber($length) {
    global $conn;

    try {
        $today = date('Y-m-d');
        $stmt = $conn->prepare("
            SELECT order_number
            FROM inventory_orders
            WHERE DATE(created_at) = :today
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([':today' => $today]);
        $lastOrder = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($lastOrder) {
            if (preg_match('/(\d+)$/', $lastOrder['order_number'], $matches)) {
                $lastNumber = intval($matches[1]);
                $nextNumber = $lastNumber + 1;
            } else {
                $nextNumber = 1;
            }
        } else {
            $nextNumber = 1;
        }

        return str_pad($nextNumber, $length, '0', STR_PAD_LEFT);

    } catch (Exception $e) {
        return str_pad(mt_rand(1, pow(10, $length) - 1), $length, '0', STR_PAD_LEFT);
    }
}

// Get order creation status using db.php function
$orderStatus = getOrderCreationStatus($conn);

// Determine current step
$current_step = $_GET['step'] ?? 'supplier';
if (!in_array($current_step, ['supplier', 'products', 'review'])) {
    $current_step = 'supplier';
}

// Handle form submission
$success_message = '';
$error_message = '';
$order_data = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
                // Validate current step
                $current_step = $_POST['current_step'] ?? 'supplier';

                switch ($current_step) {
                    case 'supplier':
                        // Store supplier selection temporarily
                        $supplier_id = filter_input(INPUT_POST, 'supplier_id', FILTER_SANITIZE_NUMBER_INT);
        $order_date = filter_input(INPUT_POST, 'order_date', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $expected_date = filter_input(INPUT_POST, 'expected_date', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $notes = filter_input(INPUT_POST, 'notes', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
                        
                        // Validate required fields
                        if (!$supplier_id) {
                            throw new Exception('Please select a supplier');
                        }
        if (!$order_date) {
                            throw new Exception('Please select an order date');
                        }
                        
                        // Store in session
                        $_SESSION['order_supplier_id'] = $supplier_id;
                        $_SESSION['order_date'] = $order_date;
                        $_SESSION['order_expected_date'] = $expected_date;
                        $_SESSION['order_notes'] = $notes;

                        // Debug session storage
                        error_log("Stored in session - supplier_id: $supplier_id, order_date: $order_date");
                        error_log("Session data after storage: " . print_r($_SESSION, true));

                        // Redirect to next step
                        header("Location: create_order.php?step=products");
                        exit();
                        break;

                    case 'products':
                        // Store product selections
                        $products = $_POST['products'] ?? [];
        $validated_products = [];

        foreach ($products as $product) {
            $product_id = filter_var($product['id'] ?? '', FILTER_SANITIZE_NUMBER_INT);
            $quantity = filter_var($product['quantity'] ?? '', FILTER_SANITIZE_NUMBER_INT);

                            if ($product_id && $quantity && $quantity > 0) {
                                $validated_products[] = [
                                    'id' => $product_id,
                                    'quantity' => $quantity
                                ];
                            }
                        }

                        if (empty($validated_products)) {
                            throw new Exception('Please add at least one product to the order');
                        }

                        $_SESSION['order_products'] = $validated_products;
                        
                        // Redirect to next step
                        header("Location: create_order.php?step=review");
                        exit();
                        break;

                    case 'review':
                        // Final order creation
                        $supplier_id = $_SESSION['order_supplier_id'] ?? null;
                        $order_date = $_SESSION['order_date'] ?? null;
                        $expected_date = $_SESSION['order_expected_date'] ?? null;
                        $notes = $_SESSION['order_notes'] ?? '';
                        $products = $_SESSION['order_products'] ?? [];

                        if (!$supplier_id || empty($products)) {
                            throw new Exception('Missing required order information. Please complete all previous steps.');
                        }

                // Use db.php validation function
                $validation = validateOrderCreation($conn, $supplier_id, array_column($products, 'id'));
                if (!$validation['valid']) {
                    $error_details = implode('; ', $validation['errors']);
                    if (!empty($validation['warnings'])) {
                        $error_details .= '; Warnings: ' . implode('; ', $validation['warnings']);
                    }
                    throw new Exception("Order validation failed: $error_details");
                }

                $conn->beginTransaction();

                // Generate order number using settings
        $order_number = generateOrderNumber($settings);

                // Calculate totals
        $total_items = 0;
        $total_amount = 0;

                foreach ($products as $product) {
                    $stmt = $conn->prepare("SELECT cost_price, name FROM products WHERE id = ?");
                    $stmt->execute([$product['id']]);
                    $product_data = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($product_data && $product_data['cost_price'] !== null && is_numeric($product_data['cost_price']) && $product_data['cost_price'] > 0) {
                        $total_items += $product['quantity'];
                        $total_amount += $product['quantity'] * $product_data['cost_price'];
                    } else {
                        // Handle products with invalid cost prices
                        $product_name = $product_data['name'] ?? "ID {$product['id']}";
                        throw new Exception("Product '{$product_name}' has an invalid cost price. Please ensure all products have valid cost prices before creating orders.");
                    }
                }

        // Insert order
        $stmt = $conn->prepare("
            INSERT INTO inventory_orders (
                order_number, supplier_id, user_id, order_date, expected_date,
                total_items, total_amount, status, notes, created_at
            ) VALUES (
                :order_number, :supplier_id, :user_id, :order_date, :expected_date,
                :total_items, :total_amount, :status, :notes, NOW()
            )
        ");

        $stmt->execute([
            ':order_number' => $order_number,
            ':supplier_id' => $supplier_id,
            ':user_id' => $user_id,
            ':order_date' => $order_date,
                    ':expected_date' => $expected_date ?: null,
            ':total_items' => $total_items,
            ':total_amount' => $total_amount,
            ':status' => 'pending',
            ':notes' => $notes
        ]);

        $order_id = $conn->lastInsertId();

                // Insert order items
        $stmt = $conn->prepare("
            INSERT INTO inventory_order_items (
                order_id, product_id, quantity, cost_price, total_amount
            ) VALUES (
                :order_id, :product_id, :quantity, :cost_price, :total_amount
            )
        ");

                foreach ($products as $product) {
                    $stmt = $conn->prepare("SELECT cost_price, name FROM products WHERE id = ?");
                    $stmt->execute([$product['id']]);
                    $product_data = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($product_data && $product_data['cost_price'] !== null && is_numeric($product_data['cost_price']) && $product_data['cost_price'] > 0) {
                        $item_stmt = $conn->prepare("
                            INSERT INTO inventory_order_items (
                                order_id, product_id, quantity, cost_price, total_amount
                            ) VALUES (
                                :order_id, :product_id, :quantity, :cost_price, :total_amount
                            )
                        ");

                        $item_stmt->execute([
                            ':order_id' => $order_id,
                            ':product_id' => $product['id'],
                            ':quantity' => $product['quantity'],
                            ':cost_price' => $product_data['cost_price'],
                            ':total_amount' => $product['quantity'] * $product_data['cost_price']
                        ]);
                    } else {
                        $product_name = $product_data['name'] ?? "ID {$product['id']}";
                        throw new Exception("Cannot create order item for product '{$product_name}' - invalid cost price");
                    }
                }

        $conn->commit();

                // Clear session data
                unset($_SESSION['order_supplier_id']);
                unset($_SESSION['order_date']);
                unset($_SESSION['order_expected_date']);
                unset($_SESSION['order_notes']);
                unset($_SESSION['order_products']);

                $success_message = "Order #$order_number created successfully!";
                
                // Store success message in session for display after redirect
                $_SESSION['order_success_message'] = $success_message;
                $_SESSION['order_success_number'] = $order_number;
                
                // Redirect to show success message
                header("Location: create_order.php?step=supplier&success=1");
                exit();
                break;
        }

    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log("Order creation PDO error: " . $e->getMessage());
        error_log("Error code: " . $e->getCode());
        error_log("Stack trace: " . $e->getTraceAsString());

        // Provide more specific error messages
        if (strpos($e->getMessage(), 'foreign key constraint') !== false) {
            $error_message = "Data integrity error: Please ensure all selected suppliers and products exist.";
        } elseif (strpos($e->getMessage(), 'duplicate') !== false) {
            $error_message = "Duplicate order number generated. Please try again.";
        } elseif (strpos($e->getMessage(), 'cannot be null') !== false) {
            $error_message = "Required data is missing. Please check all form fields.";
        } else {
            $error_message = "Database error occurred: " . $e->getMessage();
        }
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log("Order creation general error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        $error_message = $e->getMessage();
    }
}

// Get suppliers for selection
$suppliers = [];
if ($orderStatus['ready']) {
    $stmt = $conn->query("SELECT id, name, contact_person, phone, email, is_active FROM suppliers ORDER BY name");
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get low stock products for suggestions
$low_stock_products = [];
$stmt = $conn->prepare("
    SELECT p.id, p.name, p.sku, p.quantity, p.minimum_stock, p.cost_price,
           s.name as supplier_name, c.name as category_name
    FROM products p
    LEFT JOIN suppliers s ON p.supplier_id = s.id
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.quantity <= p.minimum_stock AND p.status = 'active'
    ORDER BY p.quantity ASC
    LIMIT 10
");
$stmt->execute();
$low_stock_products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Debug session data
error_log("Session ID: " . session_id());
error_log("Current step: " . $current_step);
error_log("Session supplier_id: " . (isset($_SESSION['order_supplier_id']) ? $_SESSION['order_supplier_id'] : 'NOT SET'));
error_log("Session order_date: " . (isset($_SESSION['order_date']) ? $_SESSION['order_date'] : 'NOT SET'));
error_log("All session data: " . print_r($_SESSION, true));

// Test session functionality
if (!isset($_SESSION['test_session'])) {
    $_SESSION['test_session'] = time();
    error_log("Session test set: " . $_SESSION['test_session']);
} else {
    error_log("Session test exists: " . $_SESSION['test_session']);
}

// Handle step validation redirects before any HTML output
if ($current_step === 'products' && !isset($_SESSION['order_supplier_id'])) {
    error_log("Redirecting to supplier step - missing supplier_id for products step");
    header("Location: create_order.php?step=supplier&error=incomplete");
    exit();
}
if ($current_step === 'review' && (!isset($_SESSION['order_supplier_id']) || !isset($_SESSION['order_products']))) {
    error_log("Redirecting to supplier step - missing supplier_id or products for review step");
    header("Location: create_order.php?step=supplier&error=incomplete");
    exit();
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Purchase Order - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
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

        .step-progress {
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
            flex: 1;
        }

        .step-circle {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: #e9ecef;
            color: #6c757d;
            font-weight: 600;
            margin-bottom: 0.5rem;
            transition: all 0.3s ease;
        }

        .step.active .step-circle {
            background-color: var(--primary-color);
            color: white;
        }

        .step.completed .step-circle {
            background-color: #198754;
            color: white;
        }

        .step-line {
            position: absolute;
            top: 20px;
            left: 50%;
            right: -50%;
            height: 2px;
            background-color: #e9ecef;
            z-index: -1;
        }

        .step.completed + .step-line {
            background-color: #198754;
        }

        .step-label {
            font-size: 0.875rem;
            color: #6c757d;
            text-align: center;
        }

        .step.active .step-label {
            color: var(--primary-color);
            font-weight: 600;
        }

        .step.completed .step-label {
            color: #198754;
        }

        .order-form-section {
            display: none;
        }

        .order-form-section.active {
            display: block;
        }

        .product-item {
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
            padding: 1rem;
            margin-bottom: 1rem;
            background-color: #fff;
        }

        .product-item.selected {
            border-color: var(--primary-color);
            background-color: #f8f9ff;
        }

        .validation-status {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
            padding: 1rem;
            margin-bottom: 1rem;
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
                    <h1>Create Purchase Order</h1>
                    <p class="header-subtitle">Create orders to suppliers for restocking inventory</p>
                </div>
                <div class="header-actions">
                    <a href="inventory.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-2"></i>Back to Inventory
                    </a>
                </div>
            </div>
        </header>

        <!-- Content -->
        <main class="content">
            <?php 
            // Check for success message in session
            if (isset($_SESSION['order_success_message'])) {
                $success_message = $_SESSION['order_success_message'];
                $order_number = $_SESSION['order_success_number'] ?? '';
                unset($_SESSION['order_success_message']);
                unset($_SESSION['order_success_number']);
            }
            ?>
            
            <?php if ($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle me-2"></i>
                <strong>Success!</strong> <?php echo htmlspecialchars($success_message); ?>
                <div class="mt-2">
                    <a href="view_order.php?id=<?php echo $order_number; ?>" class="btn btn-sm btn-outline-success me-2">
                        <i class="bi bi-eye me-1"></i>View Order
                    </a>
                    <a href="create_order.php" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-plus-circle me-1"></i>Create Another Order
                    </a>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if (isset($_GET['error']) && $_GET['error'] === 'incomplete'): ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle me-2"></i>Please complete all previous steps before proceeding.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
            <?php endif; ?>

            <?php if (!$orderStatus['ready']): ?>
            <div class="alert alert-warning">
                <h5><i class="bi bi-exclamation-triangle me-2"></i>System Not Ready for Order Creation</h5>
                <?php if (!empty($orderStatus['issues'])): ?>
                <ul class="mb-0">
                    <?php foreach ($orderStatus['issues'] as $issue): ?>
                    <li><?php echo htmlspecialchars($issue); ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
                <div class="mt-3">
                    <a href="inventory.php" class="btn btn-outline-primary">Return to Inventory</a>
                </div>
            </div>
            <?php else: ?>
            


            <!-- Step Progress Indicator -->
            <div class="step-progress">
                <div class="step <?php echo $current_step === 'supplier' ? 'active' : ($current_step === 'products' || $current_step === 'review' ? 'completed' : ''); ?>" id="step-supplier">
                        <div class="step-circle">1</div>
                        <div class="step-label">Select Supplier</div>
                    </div>
                    <div class="step-line"></div>
                <div class="step <?php echo $current_step === 'products' ? 'active' : ($current_step === 'review' ? 'completed' : ''); ?>" id="step-products">
                        <div class="step-circle">2</div>
                        <div class="step-label">Add Products</div>
                    </div>
                    <div class="step-line"></div>
                <div class="step <?php echo $current_step === 'review' ? 'active' : ''; ?>" id="step-review">
                        <div class="step-circle">3</div>
                    <div class="step-label">Review & Create</div>
                </div>
            </div>

            <form method="POST" id="orderForm" action="create_order.php">
                <input type="hidden" name="current_step" id="currentStep" value="<?php echo $current_step; ?>">
                
                <!-- Hidden inputs for products data -->
                <div id="hiddenProductInputs"></div>

                <!-- Step 1: Supplier Selection -->
                <div class="order-form-section <?php echo $current_step === 'supplier' ? 'active' : ''; ?>" id="supplierSection">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-building me-2"></i>Step 1: Select Supplier</h5>
                        </div>
                        <div class="card-body">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Important:</strong> You can only order products from suppliers that are registered to those specific products. Select a supplier first, then you'll only see products available from that supplier.
                    </div>

                    <div class="row">
                                <div class="col-md-6">
                            <label for="supplier_id" class="form-label">Supplier *</label>
                            <select class="form-select" id="supplier_id" name="supplier_id" required>
                                <option value="">Select Supplier</option>
                                <?php foreach ($suppliers as $supplier): ?>
                                <option value="<?php echo $supplier['id']; ?>"
                                        data-contact="<?php echo htmlspecialchars($supplier['contact_person'] ?? ''); ?>"
                                        data-phone="<?php echo htmlspecialchars($supplier['phone'] ?? ''); ?>"
                                        data-email="<?php echo htmlspecialchars($supplier['email'] ?? ''); ?>"
                                                data-active="<?php echo $supplier['is_active'] ? '1' : '0'; ?>"
                                                <?php echo (isset($_SESSION['order_supplier_id']) && $_SESSION['order_supplier_id'] == $supplier['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($supplier['name']); ?>
                                            <?php if (!$supplier['is_active']): ?>
                                                <span class="text-danger">(Inactive)</span>
                                            <?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                                <div class="col-md-3">
                                    <label for="order_date" class="form-label">Order Date *</label>
                                    <input type="date" class="form-control" id="order_date" name="order_date"
                                           value="<?php echo $_SESSION['order_date'] ?? date('Y-m-d'); ?>" required>
                        </div>
                                <div class="col-md-3">
                                    <label for="expected_date" class="form-label">Expected Date</label>
                                    <input type="date" class="form-control" id="expected_date" name="expected_date"
                                           value="<?php echo $_SESSION['order_expected_date'] ?? ''; ?>">
                    </div>
                            </div>

                            <div class="row mt-3">
                                <div class="col-12">
                                    <label for="notes" class="form-label">Notes</label>
                                    <textarea class="form-control" id="notes" name="notes" rows="3"
                                              placeholder="Any special instructions or notes for this order"><?php echo htmlspecialchars($_SESSION['order_notes'] ?? ''); ?></textarea>
                                </div>
                            </div>

                    <div class="supplier-info mt-3" id="supplierInfo" style="display: none;">
                        <div class="card border-primary">
                            <div class="card-header bg-primary text-white">
                                <h6 class="mb-0"><i class="bi bi-info-circle me-2"></i>Supplier Information</h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-4">
                                        <strong>Contact:</strong><br>
                                        <span id="supplierContact">-</span>
                                    </div>
                                    <div class="col-md-4">
                                        <strong>Phone:</strong><br>
                                        <span id="supplierPhone">-</span>
                                    </div>
                                    <div class="col-md-4">
                                        <strong>Email:</strong><br>
                                        <span id="supplierEmail">-</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                            <div class="validation-status" id="supplierValidation" style="display: none;">
                                <!-- Validation messages will appear here -->
                        </div>
                        </div>
                        <div class="card-footer">
                            <button type="button" class="btn btn-primary" id="nextToProducts" disabled>
                                <i class="bi bi-arrow-right me-2"></i>Next: Add Products
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Step 2: Product Selection -->
                <div class="order-form-section <?php echo $current_step === 'products' ? 'active' : ''; ?>" id="productsSection">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-cart-plus me-2"></i>Step 2: Add Products</h5>
                        </div>
                        <div class="card-body">
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                                <strong>Note:</strong> Only products associated with the selected supplier are shown below.
                    </div>

                    <!-- Low Stock Suggestions -->
                    <?php if (!empty($low_stock_products)): ?>
                            <div class="mb-4">
                        <h6><i class="bi bi-lightbulb me-2"></i>Low Stock Suggestions</h6>
                        <div class="row">
                            <?php foreach (array_slice($low_stock_products, 0, 4) as $product): ?>
                            <div class="col-md-3 mb-2">
                                <button type="button" class="btn btn-sm btn-outline-warning w-100 add-suggested-product"
                                        data-product-id="<?php echo $product['id']; ?>"
                                        data-product-name="<?php echo htmlspecialchars($product['name']); ?>"
                                                data-cost-price="<?php echo $product['cost_price']; ?>">
                                    <?php echo htmlspecialchars($product['name']); ?>
                                    <small class="d-block">(<?php echo $product['quantity']; ?> left)</small>
                                </button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                            <!-- Product Search -->
                            <div class="row mb-3">
                                <div class="col-md-8">
                                    <input type="text" class="form-control" id="productSearch"
                                           placeholder="Search products by name, SKU, or barcode...">
                                </div>
                                <div class="col-md-4">
                                    <button type="button" class="btn btn-outline-primary" id="loadProducts">
                                        <i class="bi bi-search me-2"></i>Load Products
                                    </button>
                                </div>
                    </div>

                            <!-- Products List -->
                            <div id="productsList" class="mb-3">
                                <div class="text-center text-muted">
                                    <i class="bi bi-search" style="font-size: 2rem;"></i>
                                    <p class="mt-2">Click "Load Products" to search and add products from the selected supplier.</p>
                    </div>
                </div>

                            <!-- Selected Products -->
                            <div id="selectedProducts" class="mt-4">
                                <h6>Selected Products</h6>
                                <div id="selectedProductsList">
                                    <div class="text-center text-muted">
                                        <i class="bi bi-cart-x" style="font-size: 2rem;"></i>
                                        <p class="mt-2">No products selected yet.</p>
                    </div>
                    </div>
                    </div>
                </div>
                        <div class="card-footer">
                            <button type="button" class="btn btn-outline-secondary me-2" id="backToSupplier">
                                <i class="bi bi-arrow-left me-2"></i>Back
                        </button>
                            <button type="button" class="btn btn-primary" id="nextToReview" disabled>
                                <i class="bi bi-arrow-right me-2"></i>Next: Review Order
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Step 3: Review Order -->
                <div class="order-form-section <?php echo $current_step === 'review' ? 'active' : ''; ?>" id="reviewSection">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-check-circle me-2"></i>Step 3: Review & Create Order</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-8">
                                    <h6>Order Summary</h6>
                                    <div class="table-responsive">
                                        <table class="table table-bordered">
                                            <tbody>
                                                <tr>
                                                    <td><strong>Supplier:</strong></td>
                                                    <td id="reviewSupplier">-</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Order Date:</strong></td>
                                                    <td id="reviewOrderDate">-</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Expected Date:</strong></td>
                                                    <td id="reviewExpectedDate">-</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Order Number:</strong></td>
                                                    <td id="reviewOrderNumber"><?php echo htmlspecialchars(generateOrderNumber($settings)); ?></td>
                                                </tr>
                                            </tbody>
                                        </table>
                    </div>
                </div>
                                <div class="col-md-4">
                                    <div class="card bg-light">
                                        <div class="card-body">
                                            <h6>Totals</h6>
                                            <div class="d-flex justify-content-between">
                                                <span>Total Items:</span>
                                                <span id="reviewTotalItems">0</span>
                                            </div>
                                            <div class="d-flex justify-content-between">
                                                <span>Total Products:</span>
                                                <span id="reviewTotalProducts">0</span>
                                            </div>
                                            <div class="d-flex justify-content-between fw-bold">
                                                <span>Total Amount:</span>
                                                <span id="reviewTotalAmount"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> 0.00</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
    </div>

                            <div class="mt-4">
                                <h6>Products in Order</h6>
                                <div id="reviewProductsList">
                                    <div class="text-center text-muted">
                                        <i class="bi bi-list-ul" style="font-size: 2rem;"></i>
                                        <p class="mt-2">No products in order.</p>
                </div>
                    </div>
                    </div>
                </div>
                        <div class="card-footer">
                            <button type="button" class="btn btn-outline-secondary me-2" id="backToProducts">
                                <i class="bi bi-arrow-left me-2"></i>Back
                            </button>
                            <button type="submit" class="btn btn-success" id="createOrder" disabled>
                                <i class="bi bi-check-circle me-2"></i>Create Order
                            </button>
            </div>
        </div>
                </div>
            </form>
            <?php endif; ?>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Global variables
        let selectedProducts = <?php echo json_encode($_SESSION['order_products'] ?? []); ?>;
        let currentSupplierId = <?php echo json_encode($_SESSION['order_supplier_id'] ?? null); ?>;
        let currencySymbol = '<?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?>';

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            initializeEventListeners();
            updateSelectedProductsUI();
            updateReviewSection();

            // Restore form state if returning from previous step
            if (currentSupplierId) {
                document.getElementById('supplier_id').value = currentSupplierId;
                handleSupplierChange({ target: document.getElementById('supplier_id') });
            }

            // Update hidden inputs on page load
            updateHiddenProductInputs();
        });

        function initializeEventListeners() {
            // Supplier change
            document.getElementById('supplier_id').addEventListener('change', handleSupplierChange);

            // Step navigation - these will submit the form to move to next step
            document.getElementById('nextToProducts').addEventListener('click', () => {
                document.getElementById('currentStep').value = 'supplier';
                document.getElementById('orderForm').submit();
            });
            document.getElementById('backToSupplier').addEventListener('click', () => {
                window.location.href = 'create_order.php?step=supplier';
            });
            document.getElementById('nextToReview').addEventListener('click', () => {
                document.getElementById('currentStep').value = 'products';
                // Update hidden inputs before submitting
                updateHiddenProductInputs();
                document.getElementById('orderForm').submit();
            });
            document.getElementById('backToProducts').addEventListener('click', () => {
                window.location.href = 'create_order.php?step=products';
            });

            // Product search and loading
            document.getElementById('loadProducts').addEventListener('click', loadProductsForSupplier);

            // Add suggested products
            document.querySelectorAll('.add-suggested-product').forEach(btn => {
                btn.addEventListener('click', function() {
                    const productId = this.dataset.productId;
                    const productName = this.dataset.productName;
                    const costPrice = parseFloat(this.dataset.costPrice);
                    addProductToOrder(productId, productName, costPrice);
                });
            });

                    // Form submission
        document.getElementById('orderForm').addEventListener('submit', function(e) {
            const currentStep = document.getElementById('currentStep').value;
            
            if (currentStep === 'supplier') {
                // Validate supplier step
                const supplierId = document.getElementById('supplier_id').value;
            const orderDate = document.getElementById('order_date').value;
                
                if (!supplierId) {
                    e.preventDefault();
                    alert('Please select a supplier');
                return;
            }
                if (!orderDate) {
                    e.preventDefault();
                    alert('Please select an order date');
                    return;
                    }
            } else if (currentStep === 'products') {
                // Validate products step
                if (selectedProducts.length === 0) {
                    e.preventDefault();
                    alert('Please add at least one product to the order');
                    return;
                }
                
                // Check for invalid cost prices
                let hasInvalidCostPrice = false;
                let invalidProductNames = [];
                selectedProducts.forEach(product => {
                    if (!product.cost_price || isNaN(product.cost_price) || product.cost_price <= 0) {
                        hasInvalidCostPrice = true;
                        invalidProductNames.push(product.name);
                    }
                });
                
                if (hasInvalidCostPrice) {
                    e.preventDefault();
                    alert(`Cannot proceed: The following products have invalid cost prices:\n${invalidProductNames.join('\n')}\n\nPlease ensure all products have valid cost prices.`);
                    return;
                }
                
                // Update hidden inputs before submitting
                updateHiddenProductInputs();
            }
            
            // If validation passes, handle form submission
            handleFormSubmission(e);
        });
        }

        function handleSupplierChange(e) {
            const supplierSelect = e.target;
            const selectedOption = supplierSelect.options[supplierSelect.selectedIndex];
            const nextBtn = document.getElementById('nextToProducts');

            if (supplierSelect.value) {
                currentSupplierId = supplierSelect.value;

                // Show supplier info
                document.getElementById('supplierContact').textContent = selectedOption.getAttribute('data-contact') || '-';
                document.getElementById('supplierPhone').textContent = selectedOption.getAttribute('data-phone') || '-';
                document.getElementById('supplierEmail').textContent = selectedOption.getAttribute('data-email') || '-';
                document.getElementById('supplierInfo').style.display = 'block';

                // Enable next button
                nextBtn.disabled = false;

                // Validate supplier status
                validateSupplier();

            } else {
                currentSupplierId = null;
                document.getElementById('supplierInfo').style.display = 'none';
                nextBtn.disabled = true;
                document.getElementById('supplierValidation').style.display = 'none';
            }
        }

        function validateSupplier() {
            if (!currentSupplierId) return;

            fetch('../utils/order_validation.php?ajax=1&supplier_id=' + currentSupplierId)
                .then(response => response.json())
                .then(data => {
                    const validationDiv = document.getElementById('supplierValidation');
                    validationDiv.style.display = 'block';

                    if (data.success) {
                        if (data.valid) {
                            validationDiv.innerHTML = '<div class="alert alert-success mb-0"><i class="bi bi-check-circle me-2"></i>Supplier is ready for ordering</div>';
                        } else {
                            let errorMsg = '<div class="alert alert-danger mb-0"><i class="bi bi-exclamation-triangle me-2"></i>Validation issues:<ul class="mb-0 mt-2">';
                            data.errors.forEach(error => {
                                errorMsg += '<li>' + error + '</li>';
                            });
                            if (data.warnings.length > 0) {
                                errorMsg += '<li><strong>Warnings:</strong></li>';
                                data.warnings.forEach(warning => {
                                    errorMsg += '<li class="text-warning">' + warning + '</li>';
                                });
                            }
                            errorMsg += '</ul></div>';
                            validationDiv.innerHTML = errorMsg;
                        }
                    } else {
                        validationDiv.innerHTML = '<div class="alert alert-warning mb-0"><i class="bi bi-info-circle me-2"></i>Validation status unavailable</div>';
                    }
                })
                .catch(error => {
                    console.error('Validation error:', error);
                    document.getElementById('supplierValidation').innerHTML = '<div class="alert alert-warning mb-0"><i class="bi bi-info-circle me-2"></i>Unable to validate supplier</div>';
                });
        }



        function loadProductsForSupplier() {
            if (!currentSupplierId) {
                alert('Please select a supplier first');
                return;
            }

            const searchTerm = document.getElementById('productSearch').value;

            fetch('../api/get_products.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    'supplier_id': currentSupplierId,
                    'search': searchTerm,
                    'status_filter': 'active',
                    'exclude_blocked': 'true'
                })
            })
            .then(response => response.json())
            .then(data => {
                displayProducts(data.products || []);
            })
            .catch(error => {
                console.error('Error loading products:', error);
                document.getElementById('productsList').innerHTML = '<div class="alert alert-danger">Error loading products. Please try again.</div>';
            });
        }

        function displayProducts(products) {
            const container = document.getElementById('productsList');

            if (products.length === 0) {
                container.innerHTML = '<div class="alert alert-info">No products found for this supplier.</div>';
                return;
            }

            let html = '<div class="row">';

            products.forEach(product => {
                const isSelected = selectedProducts.find(p => p.id == product.id);
                const stockStatus = product.quantity <= product.minimum_stock ? 'text-danger' : 'text-success';
                const stockIcon = product.quantity <= product.minimum_stock ? '⚠️' : '✓';

                html += `
                    <div class="col-md-6 mb-3">
                        <div class="product-item ${isSelected ? 'selected' : ''}" data-product-id="${product.id}">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="flex-grow-1">
                                    <div class="fw-semibold">${sanitizeHtml(product.name)}</div>
                                    <div class="text-muted small">
                                        SKU: ${product.sku || 'N/A'} |
                                        Stock: <span class="${stockStatus}">${stockIcon} ${product.quantity}</span>
                                    </div>
                                    <div class="mt-2">
                                        <span class="fw-semibold text-primary">Cost: ${currencySymbol} ${parseFloat(product.cost_price).toFixed(2)}</span>
                                    </div>
                                </div>
                                <button type="button" class="btn btn-sm ${isSelected ? 'btn-danger' : 'btn-primary'} add-product-btn"
                                        data-product-id="${product.id}"
                                        data-product-name="${sanitizeHtml(product.name)}"
                                        data-cost-price="${product.cost_price}">
                                    <i class="bi ${isSelected ? 'bi-dash-circle' : 'bi-plus-circle'} me-1"></i>
                                    ${isSelected ? 'Remove' : 'Add'}
                                </button>
                            </div>
                        </div>
                    </div>
                `;
            });

            html += '</div>';
            container.innerHTML = html;

            // Add event listeners to product buttons
            document.querySelectorAll('.add-product-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const productId = this.dataset.productId;
                    const productName = this.dataset.productName;
                    const costPrice = parseFloat(this.dataset.costPrice);

                    if (selectedProducts.find(p => p.id == productId)) {
                        removeProductFromOrder(productId);
                    } else {
                        addProductToOrder(productId, productName, costPrice);
                    }
                });
            });
        }

        function addProductToOrder(productId, productName, costPrice) {
            if (selectedProducts.find(p => p.id == productId)) {
                return; // Already added
            }

            selectedProducts.push({
                id: productId,
                name: productName,
                cost_price: costPrice,
                quantity: 1
            });

            updateSelectedProductsUI();
            updateProductItemUI(productId, true);
            updateHiddenProductInputs();
        }

        function removeProductFromOrder(productId) {
            selectedProducts = selectedProducts.filter(p => p.id != productId);
            updateSelectedProductsUI();
            updateProductItemUI(productId, false);
            updateHiddenProductInputs();
        }

        function updateProductItemUI(productId, isSelected) {
            const productItem = document.querySelector(`.product-item[data-product-id="${productId}"]`);
            if (productItem) {
                productItem.classList.toggle('selected', isSelected);
                const btn = productItem.querySelector('.add-product-btn');
                if (btn) {
                    btn.classList.toggle('btn-danger', isSelected);
                    btn.classList.toggle('btn-primary', !isSelected);
                    btn.innerHTML = `<i class="bi ${isSelected ? 'bi-dash-circle' : 'bi-plus-circle'} me-1"></i>${isSelected ? 'Remove' : 'Add'}`;
                }
            }
        }

        function updateSelectedProductsUI() {
            const container = document.getElementById('selectedProductsList');

            if (selectedProducts.length === 0) {
                container.innerHTML = '<div class="text-center text-muted"><i class="bi bi-cart-x" style="font-size: 2rem;"></i><p class="mt-2">No products selected yet.</p></div>';
                document.getElementById('nextToReview').disabled = true;
                return;
            }

            document.getElementById('nextToReview').disabled = false;

            let html = '<div class="table-responsive"><table class="table table-bordered"><thead><tr><th>Product</th><th>Cost Price</th><th>Quantity</th><th>Total</th><th>Action</th></tr></thead><tbody>';

            selectedProducts.forEach(product => {
                const total = product.quantity * product.cost_price;
                html += `
                    <tr>
                        <td>${sanitizeHtml(product.name)}</td>
                        <td>${currencySymbol} ${parseFloat(product.cost_price).toFixed(2)}</td>
                        <td>
                            <input type="number" class="form-control form-control-sm quantity-input"
                                   value="${product.quantity}" min="1"
                                   data-product-id="${product.id}" style="width: 80px;">
                        </td>
                        <td>${currencySymbol} ${total.toFixed(2)}</td>
                        <td>
                            <button type="button" class="btn btn-sm btn-outline-danger remove-product-btn"
                                    data-product-id="${product.id}">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                `;
            });

            html += '</tbody></table></div>';
            container.innerHTML = html;

            // Add event listeners
            document.querySelectorAll('.quantity-input').forEach(input => {
                input.addEventListener('change', function() {
                    const productId = this.dataset.productId;
                    const newQuantity = parseInt(this.value) || 1;
                    updateProductQuantity(productId, newQuantity);
                });
            });

            document.querySelectorAll('.remove-product-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const productId = this.dataset.productId;
                    removeProductFromOrder(productId);
                });
            });

            // Update hidden inputs for form submission
            updateHiddenProductInputs();
            updateReviewSection();
        }

        function updateProductQuantity(productId, newQuantity) {
            const product = selectedProducts.find(p => p.id == productId);
            if (product) {
                product.quantity = Math.max(1, newQuantity);
            updateSelectedProductsUI();
                updateHiddenProductInputs();
            }
        }

        function updateReviewSection() {
            // Update supplier info
            const supplierSelect = document.getElementById('supplier_id');
            const selectedOption = supplierSelect.options[supplierSelect.selectedIndex];
            document.getElementById('reviewSupplier').textContent = selectedOption ? selectedOption.text : '-';

            // Update dates
            document.getElementById('reviewOrderDate').textContent = document.getElementById('order_date').value;
            document.getElementById('reviewExpectedDate').textContent = document.getElementById('expected_date').value || 'Not specified';

            // Calculate totals
            let totalItems = 0;
            let totalAmount = 0;
            let hasInvalidCostPrice = false;

            selectedProducts.forEach(product => {
                if (!product.cost_price || isNaN(product.cost_price) || product.cost_price <= 0) {
                    hasInvalidCostPrice = true;
                    console.error(`Product ${product.name} has invalid cost price: ${product.cost_price}`);
                } else {
                    totalItems += product.quantity;
                    totalAmount += product.quantity * product.cost_price;
                }
            });

            // Disable create order button if invalid cost prices
            const createOrderBtn = document.getElementById('createOrder');
            if (hasInvalidCostPrice) {
                createOrderBtn.disabled = true;
                createOrderBtn.title = 'Cannot create order: Some products have invalid cost prices';
                console.error('Order creation disabled due to invalid cost prices');
            } else {
                createOrderBtn.disabled = false;
                createOrderBtn.title = '';
            }

            document.getElementById('reviewTotalItems').textContent = totalItems;
            document.getElementById('reviewTotalProducts').textContent = selectedProducts.length;
            document.getElementById('reviewTotalAmount').textContent = `${currencySymbol} ${totalAmount.toFixed(2)}`;

            // Update products list
            const productsContainer = document.getElementById('reviewProductsList');

                if (selectedProducts.length === 0) {
                productsContainer.innerHTML = '<div class="text-center text-muted"><i class="bi bi-list-ul" style="font-size: 2rem;"></i><p class="mt-2">No products in order.</p></div>';
                document.getElementById('createOrder').disabled = true;
                return;
            }

            document.getElementById('createOrder').disabled = false;

            let html = '<div class="table-responsive"><table class="table table-bordered"><thead><tr><th>Product</th><th>Quantity</th><th>Cost Price</th><th>Total</th></tr></thead><tbody>';

            selectedProducts.forEach(product => {
                const total = product.quantity * product.cost_price;
                html += `
                    <tr>
                        <td>${sanitizeHtml(product.name)}</td>
                        <td>${product.quantity}</td>
                        <td>${currencySymbol} ${parseFloat(product.cost_price).toFixed(2)}</td>
                        <td>${currencySymbol} ${total.toFixed(2)}</td>
                    </tr>
                `;
            });

            html += '</tbody></table></div>';
            productsContainer.innerHTML = html;
        }

        function updateHiddenProductInputs() {
            const container = document.getElementById('hiddenProductInputs');
            container.innerHTML = '';

                selectedProducts.forEach((product, index) => {
                // Create hidden input for product ID
                const idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = `products[${index}][id]`;
                idInput.value = product.id;
                container.appendChild(idInput);

                // Create hidden input for product quantity
                const quantityInput = document.createElement('input');
                        quantityInput.type = 'hidden';
                        quantityInput.name = `products[${index}][quantity]`;
                    quantityInput.value = product.quantity;
                container.appendChild(quantityInput);
            });
        }

        function handleFormSubmission(e) {
            // Update hidden inputs with current product selections
            updateHiddenProductInputs();
            
            // Allow form submission to continue
        }

        function sanitizeHtml(text) {
            if (!text || typeof text !== 'string') return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>
</html>
