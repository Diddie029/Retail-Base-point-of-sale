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

// Check if user has permission to manage expiry tracker
if (!in_array('manage_expiry_tracker', $permissions)) {
    header("Location: expiry_tracker.php?error=permission_denied");
    exit();
}

$message = '';
$message_type = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $batch_number = trim($_POST['batch_number'] ?? '');
    $expiry_date = trim($_POST['expiry_date'] ?? '');
    $manufacturing_date = trim($_POST['manufacturing_date'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $supplier_id = trim($_POST['supplier_id'] ?? '');
    $purchase_order_id = trim($_POST['purchase_order_id'] ?? '');
    $alert_days_before = trim($_POST['alert_days_before'] ?? '30');
    $notes = trim($_POST['notes'] ?? '');
    $products = $_POST['products'] ?? [];
    
    // Enhanced validation with proper type checking
    $errors = [];
    
    if (empty($expiry_date)) {
        $errors[] = "Expiry date is required";
    } elseif (strtotime($expiry_date) <= time()) {
        $errors[] = "Expiry date must be in the future";
    }
    
    // Validate supplier_id - now required
    if (empty($supplier_id)) {
        $errors[] = "Supplier is required";
    } elseif (!is_numeric($supplier_id) || intval($supplier_id) <= 0) {
        $errors[] = "Please select a valid supplier";
    } else {
        $supplier_id = intval($supplier_id);
    }
    
    // Validate products array
    if (empty($products) || !is_array($products)) {
        $errors[] = "At least one product is required";
        } else {
        foreach ($products as $index => $product) {
            if (empty($product['product_id']) || empty($product['quantity']) || empty($product['unit_cost'])) {
                $errors[] = "Invalid product data at position " . ($index + 1);
            } elseif (!is_numeric($product['quantity']) || floatval($product['quantity']) <= 0) {
                $errors[] = "Invalid quantity for product at position " . ($index + 1);
            } elseif (!is_numeric($product['unit_cost']) || floatval($product['unit_cost']) < 0) {
                $errors[] = "Invalid unit cost for product at position " . ($index + 1);
            }
        }
    }
    
    // Validate alert_days_before
    if (!empty($alert_days_before)) {
        if (!is_numeric($alert_days_before) || intval($alert_days_before) < 1) {
            $errors[] = "Alert days must be a positive number";
        } else {
            $alert_days_before = intval($alert_days_before);
        }
    } else {
        $alert_days_before = 30; // Default value
    }
    
    // Validate manufacturing date if provided
    if (!empty($manufacturing_date)) {
        if (strtotime($manufacturing_date) > strtotime($expiry_date)) {
            $errors[] = "Manufacturing date cannot be after expiry date";
        }
    }
    
    if (empty($errors)) {
        try {
            $conn->beginTransaction();
            
            $expiry_ids = [];
            $total_products = count($products);
            
            // Insert expiry date records for each product
            foreach ($products as $product) {
                $product_id = intval($product['product_id']);
                $quantity = floatval($product['quantity']);
                $unit_cost = floatval($product['unit_cost']);
                
            $stmt = $conn->prepare("
                INSERT INTO product_expiry_dates (
                    product_id, batch_number, expiry_date, manufacturing_date,
                    quantity, remaining_quantity, unit_cost, location, supplier_id,
                    purchase_order_id, alert_days_before, notes, status, created_at
                ) VALUES (
                    :product_id, :batch_number, :expiry_date, :manufacturing_date,
                    :quantity, :quantity, :unit_cost, :location, :supplier_id,
                    :purchase_order_id, :alert_days_before, :notes, 'active', NOW()
                )
            ");
            
            $stmt->execute([
                ':product_id' => $product_id,
                ':batch_number' => $batch_number ?: null,
                ':expiry_date' => $expiry_date,
                ':manufacturing_date' => $manufacturing_date ?: null,
                ':quantity' => $quantity,
                ':unit_cost' => $unit_cost,
                ':location' => $location ?: null,
                    ':supplier_id' => $supplier_id,
                ':purchase_order_id' => $purchase_order_id ?: null,
                ':alert_days_before' => $alert_days_before,
                ':notes' => $notes ?: null
            ]);
            
                $expiry_ids[] = $conn->lastInsertId();
            
            // Update product quantity if this is a new batch
            if (empty($batch_number)) {
                $stmt = $conn->prepare("
                    UPDATE products 
                    SET quantity = quantity + :quantity 
                    WHERE id = :product_id
                ");
                $stmt->execute([
                    ':quantity' => $quantity,
                    ':product_id' => $product_id
                ]);
                }
            }
            
            // Log the action
            $stmt = $conn->prepare("
                INSERT INTO activity_logs (user_id, action, details, created_at)
                VALUES (:user_id, 'add_expiry_dates', :details, NOW())
            ");
            
            $details = "Added expiry dates for {$total_products} products, Supplier ID: {$supplier_id}, Expiry: {$expiry_date}";
            $stmt->execute([
                ':user_id' => $user_id,
                ':details' => $details
            ]);
            
            $conn->commit();
            
            $message = "Successfully added {$total_products} expiry date(s)!";
            $message_type = "success";
            
            // Redirect to the first added item
            header("Location: view_expiry_item.php?id={$expiry_ids[0]}&success=1");
            exit();
            
        } catch (PDOException $e) {
            $conn->rollBack();
            
            // Enhanced error handling with specific error messages
            $error_code = $e->getCode();
            $error_message = $e->getMessage();
            
            switch ($error_code) {
                case '1366': // Incorrect decimal value
                    $message = "Invalid numeric value provided. Please check quantity and unit cost fields.";
                    break;
                case '1452': // Foreign key constraint failure
                    $message = "Invalid reference data. Please check product, supplier, or purchase order selection.";
                    break;
                case '1062': // Duplicate entry
                    $message = "A record with this information already exists.";
                    break;
                default:
                    $message = "Database error occurred. Please try again or contact support.";
                    // Log the actual error for debugging
                    error_log("Expiry tracker error: " . $error_message);
            }
            
            $message_type = "error";
        } catch (Exception $e) {
            $conn->rollBack();
            $message = "An unexpected error occurred. Please try again.";
            $message_type = "error";
            error_log("Unexpected error in expiry tracker: " . $e->getMessage());
        }
    } else {
        $message = implode("<br>", $errors);
        $message_type = "error";
    }
}

// Get products with supplier information for dropdown
$products = $conn->query("
    SELECT p.id, p.name, p.sku, p.barcode, p.cost_price, p.price, c.name as category_name, s.id as supplier_id, s.name as supplier_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN suppliers s ON p.supplier_id = s.id
    WHERE p.status = 'active' AND s.is_active = 1
    ORDER BY s.name, p.name
")->fetchAll(PDO::FETCH_ASSOC);

// Get suppliers for dropdown
$suppliers = $conn->query("
    SELECT id, name FROM suppliers 
    WHERE is_active = 1 
    ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

// Get expiry categories for alert threshold
$expiry_categories = $conn->query("
    SELECT id, category_name, alert_threshold_days, color_code
    FROM expiry_categories 
    WHERE is_active = 1 
    ORDER BY alert_threshold_days
")->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Add Expiry Date";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/expiry_tracker.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .status-text.ready {
            color: #10b981;
            font-weight: 600;
        }
        .status-text.warning {
            color: #f59e0b;
            font-weight: 600;
        }
        .total-value {
            font-weight: 700;
            color: #1e293b;
        }
        .cost-summary {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 1rem;
            margin: 1rem 0;
        }
        .summary-header h4 {
            margin: 0 0 1rem 0;
            color: #1e293b;
        }
        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
        }
        .summary-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            flex: 1;
        }
        .summary-item label {
            font-size: 0.875rem;
            color: #64748b;
            margin-bottom: 0.25rem;
        }
        .summary-item span {
            font-size: 1.125rem;
            font-weight: 600;
        }
        
        /* Selected Products Table Styling */
        .selected-products-container {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 1.5rem;
            margin: 1rem 0;
        }
        
        .selected-products-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .selected-products-header h5 {
            margin: 0;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .selected-products-table {
            margin-bottom: 0;
            background: white;
            border-radius: 6px;
            overflow: hidden;
        }
        
        .selected-products-table thead th {
            background: #f8fafc;
            border-bottom: 2px solid #e2e8f0;
            font-weight: 600;
            color: #374151;
            padding: 0.75rem;
            font-size: 0.875rem;
        }
        
        .selected-products-table tbody td {
            padding: 0.75rem;
            vertical-align: middle;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .selected-products-table tbody tr:hover {
            background-color: #f8fafc;
        }
        
        .selected-products-table tbody tr:last-child td {
            border-bottom: none;
        }
        
        .product-name-cell {
            font-weight: 600;
            color: #1e293b;
        }
        
        .product-sku-cell {
            font-family: 'Courier New', monospace;
            font-size: 0.875rem;
            color: #059669;
            background: #f0fdf4;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            display: inline-block;
        }
        
        .product-category-cell {
            color: #64748b;
            font-size: 0.875rem;
        }
        
        .product-cost-cell {
            font-weight: 600;
            color: #1e293b;
        }
        
        .product-quantity-cell {
            font-weight: 600;
            color: #059669;
        }
        
        .product-total-cell {
            font-weight: 700;
            color: #1e293b;
            font-size: 1.1rem;
        }
        
        .product-actions-cell {
            text-align: center;
        }
        
        .remove-product-btn {
            background: #ef4444;
            color: white;
            border: none;
            border-radius: 4px;
            padding: 0.375rem 0.75rem;
            font-size: 0.875rem;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .remove-product-btn:hover {
            background: #dc2626;
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(239, 68, 68, 0.3);
        }
        
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: #64748b;
        }
        
        .empty-state i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            opacity: 0.5;
        }
        
        .table-responsive {
            border-radius: 6px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        /* Modern Product Section Styling */
        .product-section {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 2rem;
            margin: 1.5rem 0;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .header-content {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .header-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }
        
        .header-text h5 {
            margin: 0 0 0.25rem 0;
            color: #1e293b;
            font-size: 1.5rem;
            font-weight: 700;
        }
        
        .header-subtitle {
            margin: 0;
            color: #64748b;
            font-size: 0.875rem;
            font-weight: 500;
        }
        
        .btn-modern {
            padding: 0.75rem 1.5rem;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.875rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            border: 2px solid #e2e8f0;
            background: white;
            color: #374151;
        }
        
        .btn-modern:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
            border-color: #3b82f6;
            color: #3b82f6;
        }
        
        .btn-modern:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        /* Modern Search Container */
        .search-container {
            margin-bottom: 1.5rem;
        }
        
        .search-input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 16px;
            padding: 0.75rem 1rem;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }
        
        .search-input-wrapper:focus-within {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .search-icon {
            color: #9ca3af;
            font-size: 1.25rem;
            margin-right: 0.75rem;
        }
        
        .search-input {
            flex: 1;
            border: none;
            outline: none;
            font-size: 1rem;
            color: #374151;
            background: transparent;
        }
        
        .search-input::placeholder {
            color: #9ca3af;
        }
        
        .search-btn {
            background: #3b82f6;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 0.5rem;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .search-btn:hover {
            background: #2563eb;
            transform: scale(1.05);
        }
        
        .search-btn:disabled {
            background: #9ca3af;
            cursor: not-allowed;
            transform: none;
        }
        
        .search-hint {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 0.75rem;
            color: #6b7280;
            font-size: 0.875rem;
        }
        
        .search-hint i {
            color: #f59e0b;
        }
        
        /* Modern Product Results */
        .product-results-modern {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            margin-top: 1rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        
        .results-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 1.5rem;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .results-count {
            font-weight: 600;
            color: #374151;
        }
        
        .btn-clear-search {
            background: none;
            border: none;
            color: #6b7280;
            font-size: 0.875rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.5rem;
            border-radius: 6px;
            transition: all 0.2s ease;
        }
        
        .btn-clear-search:hover {
            background: #f3f4f6;
            color: #374151;
        }
        
        .results-list {
            max-height: 300px;
            overflow-y: auto;
        }
        
        .product-result-item {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #f1f5f9;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .product-result-item:hover {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            transform: translateX(4px);
        }
        
        .product-result-item:last-child {
            border-bottom: none;
        }
        
        .product-result-info {
            flex: 1;
        }
        
        .product-result-name {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.25rem;
            font-size: 1rem;
        }
        
        .product-result-sku {
            font-family: 'Courier New', monospace;
            font-size: 0.875rem;
            color: #059669;
            background: #f0fdf4;
            padding: 0.25rem 0.5rem;
            border-radius: 6px;
            display: inline-block;
            font-weight: 500;
        }
        
        /* Modern Selected Product */
        .selected-product-modern {
            margin-top: 1.5rem;
        }
        
        .selected-product-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        
        .product-preview {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .product-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.25rem;
        }
        
        .product-details {
            flex: 1;
        }
        
        .product-title {
            margin: 0 0 0.5rem 0;
            color: #1e293b;
            font-size: 1.125rem;
            font-weight: 600;
        }
        
        .product-sku {
            font-family: 'Courier New', monospace;
            font-size: 0.875rem;
            color: #059669;
            background: #f0fdf4;
            padding: 0.25rem 0.5rem;
            border-radius: 6px;
            display: inline-block;
            font-weight: 500;
        }
        
        .cost-amount {
            font-weight: 700;
            color: #059669;
            font-size: 1.25rem;
            margin-top: 0.5rem;
            display: block;
        }
        
        .quantity-controls {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .quantity-label {
            font-weight: 600;
            color: #374151;
            font-size: 0.875rem;
        }
        
        .quantity-input-modern {
            display: flex;
            align-items: center;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .qty-btn {
            background: #f1f5f9;
            border: none;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            color: #6b7280;
        }
        
        .qty-btn:hover {
            background: #e2e8f0;
            color: #374151;
        }
        
        .qty-input {
            width: 60px;
            text-align: center;
            border: none;
            background: transparent;
            font-weight: 600;
            color: #374151;
        }
        
        .btn-add-to-list {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            color: white;
            border: none;
            border-radius: 12px;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            font-size: 0.875rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }
        
        .btn-add-to-list:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(59, 130, 246, 0.4);
        }
        
        .search-loading {
            text-align: center;
            padding: 1rem;
            color: #64748b;
        }
        
        .search-loading i {
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <?php include '../include/navmenu.php'; ?>
    
    <div class="container" style="width: 100%; padding: 0 32px; position: relative;">
        <div class="header">
            <h1><i class="fas fa-plus"></i> <?php echo $page_title; ?></h1>
            <div class="header-actions">
                <a href="expiry_tracker.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Tracker
                </a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

    <div class="form-container" style="width: 100%; margin: 0; margin-right: 340px;">
        <!-- Right Sidebar -->
        <aside id="right-sidebar" style="position: fixed; top: 70px; right: 0; width: 320px; height: calc(100vh - 70px); background: #f8fafc; border-left: 1px solid #e2e8f0; box-shadow: -2px 0 8px rgba(0,0,0,0.04); padding: 24px 18px; z-index: 1000; overflow-y: auto;">
            <nav style="margin-bottom: 2rem;">
                <h5 style="color: #1e293b; font-weight: 700; margin-bottom: 1rem;">Expiry Navigation</h5>
                <ul style="list-style: none; padding: 0; margin: 0;">
                    <li style="margin-bottom: 0.75rem;"><a href="expiry_tracker.php" class="btn btn-outline-primary w-100"><i class="bi bi-clock-history"></i> Expiry Tracker</a></li>
                    <li style="margin-bottom: 0.75rem;"><a href="view_expiry_item.php" class="btn btn-outline-secondary w-100"><i class="bi bi-eye"></i> View Expiry Items</a></li>
                    <li style="margin-bottom: 0.75rem;"><a href="add_expiry_date.php" class="btn btn-outline-secondary w-100"><i class="bi bi-plus-circle"></i> Add Expiry Date</a></li>
                </ul>
            </nav>

            <div>
                <h5 style="color: #1e293b; font-weight: 700; margin-bottom: 1rem;">Info / Stats</h5>
                <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.04); padding: 1rem; margin-bottom: 1rem;">
                    <div style="font-size: 1.1rem; color: #374151; margin-bottom: 0.5rem;"><i class="bi bi-collection"></i> Total Batches</div>
                    <div style="font-weight: 700; color: #059669; font-size: 1.3rem;">--</div>
                </div>
                <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.04); padding: 1rem; margin-bottom: 1rem;">
                    <div style="font-size: 1.1rem; color: #374151; margin-bottom: 0.5rem;"><i class="bi bi-calendar-exclamation"></i> Upcoming Expiries</div>
                    <div style="font-weight: 700; color: #f59e0b; font-size: 1.3rem;">--</div>
                </div>
                <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.04); padding: 1rem;">
                    <div style="font-size: 1.1rem; color: #374151; margin-bottom: 0.5rem;"><i class="bi bi-bell"></i> Alerts</div>
                    <div style="font-weight: 700; color: #ef4444; font-size: 1.3rem;">--</div>
                </div>
            </div>
        </aside>
            <style>
                .form-container .form-row {
                    display: flex;
                    gap: 32px;
                }
                .form-container .form-group {
                    flex: 1;
                    min-width: 300px;
                }
                .form-container .form-group input,
                .form-container .form-group select,
                .form-container .form-group textarea {
                    width: 100%;
                }
                .product-section {
                    width: 100%;
                    min-width: 0;
                }
                .selected-products-container {
                    width: 100%;
                    min-width: 0;
                }
            </style>
            <form method="POST" class="expiry-form">
                <!-- Company Selection -->
                <div class="form-row">
                    <div class="form-group">
                        <label for="supplier_id">Company/Supplier *</label>
                        <select name="supplier_id" id="supplier_id" required>
                            <option value="">Select Company/Supplier</option>
                            <?php foreach ($suppliers as $supplier): ?>
                                <option value="<?php echo $supplier['id']; ?>" <?php echo (isset($_POST['supplier_id']) && $_POST['supplier_id'] == $supplier['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($supplier['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    </div>
                    
                <!-- Product Section -->
                <div class="product-section" style="width: 100%;">
                    <div class="section-header">
                        <div class="header-content">
                            <div class="header-icon">
                                <i class="bi bi-box-seam"></i>
                    </div>
                            <div class="header-text">
                                <h5>Products</h5>
                                <p class="header-subtitle">Search and add products to your expiry batch</p>
                            </div>
                        </div>
                        <button type="button" id="add-new-product-btn" class="btn btn-modern btn-outline" disabled>
                            <i class="bi bi-plus-lg"></i>
                            <span>Add New Product</span>
                        </button>
                </div>

                    <!-- Modern Search Container -->
                    <div class="search-container">
                        <div class="search-input-wrapper">
                            <div class="search-icon">
                                <i class="bi bi-search"></i>
                            </div>
                            <input type="text" id="product_search" class="search-input" 
                                   placeholder="Search by name, SKU, or barcode..." disabled>
                            <button type="button" id="search-btn" class="search-btn" disabled>
                                <i class="bi bi-arrow-right"></i>
                            </button>
                        </div>
                        <div class="search-hint">
                            <i class="bi bi-lightbulb"></i>
                            <span>Start typing to find products instantly</span>
                        </div>
                    </div>

                    <!-- Modern Product Results -->
                    <div id="product-results" class="product-results-modern" style="display: none;">
                        <div class="results-header">
                            <span class="results-count">0 results</span>
                            <div class="results-actions">
                                <button type="button" class="btn-clear-search" onclick="clearSearch()">
                                    <i class="bi bi-x"></i> Clear
                                </button>
                            </div>
                        </div>
                        <div class="results-list">
                            <!-- Search results will be populated here -->
                        </div>
                    </div>

                    <!-- Modern Selected Product Details -->
                    <div id="selected-product-details" class="selected-product-modern" style="display: none;">
                        <div class="selected-product-card">
                            <div class="product-preview">
                                <div class="product-icon">
                                    <i class="bi bi-box"></i>
                                </div>
                                <div class="product-details">
                                    <h6 id="selected-product-name" class="product-title"></h6>
                                    <span id="selected-product-sku" class="product-sku"></span>
                                    <div class="product-cost">
                                        <span id="selected-product-cost" class="cost-amount"></span>
                                    </div>
                                </div>
                            </div>
                            <div class="quantity-controls">
                                <div class="quantity-label">Quantity</div>
                                <div class="quantity-input-modern">
                                    <button type="button" id="quantity-minus" class="qty-btn qty-minus">
                                        <i class="bi bi-dash"></i>
                                    </button>
                                    <input type="number" id="quantity" class="qty-input" min="1" step="1" value="1">
                                    <button type="button" id="quantity-plus" class="qty-btn qty-plus">
                                        <i class="bi bi-plus"></i>
                                    </button>
                                </div>
                                <button type="button" id="add-to-list-btn" class="btn-add-to-list">
                                    <i class="bi bi-plus-circle"></i>
                                    <span>Add to List</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Selected Products List -->
                <div id="selected-products-container" class="selected-products-container" style="display: none; width: 100%;">
                    <div class="selected-products-header">
                        <h5><i class="bi bi-list-check"></i> Selected Products</h5>
                        <span id="selected-count" class="badge bg-primary">0 products</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover selected-products-table">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>SKU</th>
                                    <th>Category</th>
                                    <th>Unit Cost</th>
                                    <th>Quantity</th>
                                    <th>Total</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="selected-products-list">
                                <!-- Products will be added here dynamically -->
                            </tbody>
                            <tfoot id="selected-products-footer" style="display: none;">
                                <tr class="table-active">
                                    <td colspan="5" class="text-end fw-bold">Total:</td>
                                    <td id="selected-products-total" class="fw-bold text-primary">KES 0.00</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <!-- Step 3: Expiry Details -->
                <div class="form-row">
                    <div class="form-group">
                        <label for="expiry_date">Expiry Date *</label>
                        <input type="date" name="expiry_date" id="expiry_date" 
                               value="<?php echo $_POST['expiry_date'] ?? ''; ?>"
                               min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="manufacturing_date">Manufacturing Date</label>
                        <input type="date" name="manufacturing_date" id="manufacturing_date" 
                               value="<?php echo $_POST['manufacturing_date'] ?? ''; ?>"
                               max="<?php echo date('Y-m-d'); ?>">
                    </div>
                </div>

                <!-- Step 4: Additional Details -->
                <div class="form-row">
                    <div class="form-group">
                        <label for="batch_number">Batch Number</label>
                        <input type="text" name="batch_number" id="batch_number" 
                               value="<?php echo htmlspecialchars($_POST['batch_number'] ?? ''); ?>"
                               placeholder="Enter batch number (optional)">
                    </div>
                    
                    <div class="form-group">
                        <label for="location">Storage Location</label>
                        <input type="text" name="location" id="location" 
                               value="<?php echo htmlspecialchars($_POST['location'] ?? ''); ?>"
                               placeholder="e.g., Warehouse A, Shelf 3">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="purchase_order_id">Purchase Order ID</label>
                        <input type="text" name="purchase_order_id" id="purchase_order_id" 
                               value="<?php echo htmlspecialchars($_POST['purchase_order_id'] ?? ''); ?>"
                               placeholder="Enter PO number (optional)">
                    </div>
                    
                    <div class="form-group">
                        <label for="alert_days_before">Alert Days Before Expiry</label>
                        <select name="alert_days_before" id="alert_days_before">
                            <?php foreach ($expiry_categories as $category): ?>
                                <option value="<?php echo $category['alert_threshold_days']; ?>" 
                                        <?php echo (isset($_POST['alert_days_before']) && $_POST['alert_days_before'] == $category['alert_threshold_days']) ? 'selected' : ''; ?>>
                                    <?php echo $category['alert_threshold_days']; ?> days - 
                                    <?php echo htmlspecialchars($category['category_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="notes">Notes</label>
                    <textarea name="notes" id="notes" rows="3" 
                              placeholder="Additional notes about this expiry batch"><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
                </div>

                <!-- Cost Summary -->
                <div class="cost-summary">
                    <div class="summary-header">
                        <h4><i class="fas fa-calculator"></i> Cost Summary</h4>
                    </div>
                    <div class="summary-content">
                        <div class="summary-row">
                            <div class="summary-item">
                                <label>Total Products:</label>
                                <span id="summary-product-count">0</span>
                            </div>
                            <div class="summary-item">
                                <label>Total Quantity:</label>
                                <span id="summary-total-quantity">0</span>
                            </div>
                        </div>
                        <div class="summary-row">
                            <div class="summary-item">
                                <label>Total Value:</label>
                                <span id="summary-total-value" class="total-value">KES 0.00</span>
                            </div>
                            <div class="summary-item">
                                <label>Status:</label>
                                <span id="summary-status" class="status-text">Add products to continue</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Add Expiry Date
                    </button>
                    <a href="expiry_tracker.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Simplified multi-product selection functionality
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.querySelector('.expiry-form');
            const supplierSelect = document.getElementById('supplier_id');
            const productSearch = document.getElementById('product_search');
            const searchBtn = document.getElementById('search-btn');
            const addNewProductBtn = document.getElementById('add-new-product-btn');
            const productResults = document.getElementById('product-results');
            const selectedProductDetails = document.getElementById('selected-product-details');
            const selectedProductsContainer = document.getElementById('selected-products-container');
            const selectedProductsList = document.getElementById('selected-products-list');
            const allProducts = <?php echo json_encode($products); ?>;
            
            // Quantity controls
            const quantityInput = document.getElementById('quantity');
            const quantityMinus = document.getElementById('quantity-minus');
            const quantityPlus = document.getElementById('quantity-plus');
            const addToListBtn = document.getElementById('add-to-list-btn');
            
            // Array to store selected products
            let selectedProducts = [];
            let currentSupplierId = null;
            let filteredProducts = [];
            let searchTimeout = null;
            let currentSelectedProduct = null;
            
            // Initialize
            updateCostSummary();
            
            // Auto-fill manufacturing date if not provided
            document.getElementById('expiry_date').addEventListener('change', function() {
                const expiryDate = new Date(this.value);
                const manufacturingDate = document.getElementById('manufacturing_date');
                
                if (!manufacturingDate.value) {
                    const manufacturingDateValue = new Date(expiryDate);
                    manufacturingDateValue.setDate(expiryDate.getDate() - 30);
                    manufacturingDate.value = manufacturingDateValue.toISOString().split('T')[0];
                }
            });

            // Validate expiry date
            document.getElementById('expiry_date').addEventListener('blur', function() {
                const expiryDate = new Date(this.value);
                const today = new Date();
                
                if (expiryDate <= today) {
                    this.setCustomValidity('Expiry date must be in the future');
                    this.classList.add('error');
                } else {
                    this.setCustomValidity('');
                    this.classList.remove('error');
                }
            });

            // Filter products by supplier
            function filterProductsBySupplier(supplierId) {
                currentSupplierId = supplierId;
                filteredProducts = allProducts.filter(product => product.supplier_id == supplierId);
                
                // Enable search functionality
                productSearch.disabled = false;
                searchBtn.disabled = false;
                addNewProductBtn.disabled = false;
                
                // Clear previous results
                productResults.style.display = 'none';
                selectedProductDetails.style.display = 'none';
                productSearch.value = '';
            }
            
            // Search products
            function searchProducts(query) {
                if (query.length < 2) {
                    productResults.style.display = 'none';
                    return;
                }
                
                const searchTerm = query.toLowerCase();
                const results = filteredProducts.filter(product => 
                    product.name.toLowerCase().includes(searchTerm) ||
                    product.sku.toLowerCase().includes(searchTerm) ||
                    (product.barcode && product.barcode.toLowerCase().includes(searchTerm))
                );
                
                displaySearchResults(results);
            }
            
            // Display search results
            function displaySearchResults(results) {
                const resultsList = productResults.querySelector('.results-list');
                const resultsCount = productResults.querySelector('.results-count');
                
                resultsList.innerHTML = '';
                resultsCount.textContent = `${results.length} result${results.length !== 1 ? 's' : ''}`;
                
                if (results.length === 0) {
                    resultsList.innerHTML = '<div class="search-loading">No products found</div>';
                    productResults.style.display = 'block';
                    return;
                }
                
                results.forEach(product => {
                    const resultItem = document.createElement('div');
                    resultItem.className = 'product-result-item';
                    resultItem.innerHTML = `
                        <div class="product-result-info">
                            <div class="product-result-name">${product.name}</div>
                            <span class="product-result-sku">${product.sku}</span>
                        </div>
                    `;
                    resultItem.onclick = () => selectProduct(product);
                    resultsList.appendChild(resultItem);
                });
                
                productResults.style.display = 'block';
            }
            
            // Clear search function
            window.clearSearch = function() {
                productSearch.value = '';
                productResults.style.display = 'none';
                selectedProductDetails.style.display = 'none';
                currentSelectedProduct = null;
            };
            
            // Select product
            function selectProduct(product) {
                currentSelectedProduct = product;
                
                // Update selected product details
                document.getElementById('selected-product-name').textContent = product.name;
                document.getElementById('selected-product-sku').textContent = `SKU: ${product.sku} - ${product.category_name}`;
                document.getElementById('selected-product-cost').textContent = `KES ${(parseFloat(product.cost_price) || parseFloat(product.price) || 0).toFixed(2)}`;
                
                // Show selected product details
                selectedProductDetails.style.display = 'block';
                productResults.style.display = 'none';
                
                // Reset quantity
                quantityInput.value = 1;
            }
            
            // Quantity controls
            quantityMinus.addEventListener('click', function() {
                const currentValue = parseInt(quantityInput.value) || 1;
                if (currentValue > 1) {
                    quantityInput.value = currentValue - 1;
                }
            });
            
            quantityPlus.addEventListener('click', function() {
                const currentValue = parseInt(quantityInput.value) || 1;
                quantityInput.value = currentValue + 1;
            });
            
            // Add to list
            addToListBtn.addEventListener('click', function() {
                if (!currentSelectedProduct) {
                    alert('Please select a product first.');
                    return;
                }
                
                const quantity = parseInt(quantityInput.value) || 1;
                
                // Check if product already exists
                const existingProduct = selectedProducts.find(p => p.id == currentSelectedProduct.id);
                if (existingProduct) {
                    existingProduct.quantity += quantity;
                } else {
                    selectedProducts.push({
                        id: currentSelectedProduct.id,
                        name: currentSelectedProduct.name,
                        sku: currentSelectedProduct.sku,
                        category_name: currentSelectedProduct.category_name,
                        unit_cost: parseFloat(currentSelectedProduct.cost_price) > 0 ? parseFloat(currentSelectedProduct.cost_price) : parseFloat(currentSelectedProduct.price),
                        quantity: quantity
                    });
                }
                
                // Reset selection
                currentSelectedProduct = null;
                selectedProductDetails.style.display = 'none';
                productSearch.value = '';
                
                // Update display
                updateSelectedProductsList();
                updateCostSummary();
            });
            
            // Handle supplier selection
            supplierSelect.addEventListener('change', function() {
                const selectedSupplierId = this.value;
                
                if (selectedSupplierId) {
                    filterProductsBySupplier(selectedSupplierId);
                } else {
                    productSearch.disabled = true;
                    searchBtn.disabled = true;
                    addNewProductBtn.disabled = true;
                    productResults.style.display = 'none';
                    selectedProductDetails.style.display = 'none';
                }
            });
            
            // Handle product search
            productSearch.addEventListener('input', function() {
                const query = this.value.trim();
                
                // Clear previous timeout
                if (searchTimeout) {
                    clearTimeout(searchTimeout);
                }
                
                if (query.length < 2) {
                    productResults.style.display = 'none';
                    return;
                }
                
                // Show loading
                const resultsList = productResults.querySelector('.results-list');
                const resultsCount = productResults.querySelector('.results-count');
                resultsList.innerHTML = '<div class="search-loading"><i class="bi bi-hourglass-split"></i> Searching...</div>';
                resultsCount.textContent = 'Searching...';
                productResults.style.display = 'block';
                
                // Search with delay
                searchTimeout = setTimeout(() => {
                    searchProducts(query);
                }, 300);
            });
            
            // Handle search button
            searchBtn.addEventListener('click', function() {
                const query = productSearch.value.trim();
                if (query.length >= 2) {
                    searchProducts(query);
                }
            });
            
            // Handle Enter key in search
            productSearch.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    const query = this.value.trim();
                    if (query.length >= 2) {
                        searchProducts(query);
                    }
                }
            });
            
            // Handle add new product button
            addNewProductBtn.addEventListener('click', function() {
                // This would open a modal or redirect to add new product page
                alert('Add new product functionality would open here. This would typically open a modal or redirect to a product creation page.');
            });
            
            // Update selected products list
            function updateSelectedProductsList() {
                if (selectedProducts.length === 0) {
                    selectedProductsContainer.style.display = 'none';
                    return;
                }
                
                selectedProductsContainer.style.display = 'block';
                selectedProductsList.innerHTML = '';
                
                // Update product count badge
                document.getElementById('selected-count').textContent = `${selectedProducts.length} product${selectedProducts.length !== 1 ? 's' : ''}`;
                
                let grandTotal = 0;
                
                selectedProducts.forEach((product, index) => {
                    const row = document.createElement('tr');
                    const totalCost = (product.unit_cost * product.quantity).toFixed(2);
                    grandTotal += product.unit_cost * product.quantity;
                    
                    row.innerHTML = `
                        <td class="product-name-cell">${product.name}</td>
                        <td><span class="product-sku-cell">${product.sku}</span></td>
                        <td class="product-category-cell">${product.category_name}</td>
                        <td class="product-cost-cell">KES ${product.unit_cost.toFixed(2)}</td>
                        <td class="product-quantity-cell">${product.quantity}</td>
                        <td class="product-total-cell">KES ${totalCost}</td>
                        <td class="product-actions-cell">
                            <button type="button" class="remove-product-btn" onclick="removeProduct(${index})">
                                <i class="bi bi-trash"></i> Remove
                            </button>
                        </td>
                    `;
                    selectedProductsList.appendChild(row);
                });
                
                // Update footer total
                const footer = document.getElementById('selected-products-footer');
                const totalElement = document.getElementById('selected-products-total');
                
                if (selectedProducts.length > 0) {
                    footer.style.display = 'table-row-group';
                    totalElement.textContent = `KES ${grandTotal.toFixed(2)}`;
                } else {
                    footer.style.display = 'none';
                }
            }
            
            // Remove product function (global scope for onclick)
            window.removeProduct = function(index) {
                selectedProducts.splice(index, 1);
                updateSelectedProductsList();
                updateCostSummary();
            };
            
            // Update cost summary
            function updateCostSummary() {
                const productCount = selectedProducts.length;
                const totalQuantity = selectedProducts.reduce((sum, product) => sum + product.quantity, 0);
                const totalValue = selectedProducts.reduce((sum, product) => sum + (product.unit_cost * product.quantity), 0);
                
                document.getElementById('summary-product-count').textContent = productCount;
                document.getElementById('summary-total-quantity').textContent = totalQuantity;
                document.getElementById('summary-total-value').textContent = `KES ${totalValue.toFixed(2)}`;
                
                const statusElement = document.getElementById('summary-status');
                if (productCount > 0) {
                    statusElement.textContent = 'Ready to add';
                    statusElement.className = 'status-text ready';
                } else {
                    statusElement.textContent = 'Add products to continue';
                    statusElement.className = 'status-text warning';
                }
            }

            // Form submission validation
            form.addEventListener('submit', function(e) {
                let isValid = true;
                
                // Clear previous error states
                document.querySelectorAll('.form-group input, .form-group select, .form-group textarea').forEach(input => {
                    input.classList.remove('error');
                });
                
                // Validate required fields
                const requiredFields = ['supplier_id', 'expiry_date'];
                requiredFields.forEach(fieldName => {
                    const field = document.getElementById(fieldName);
                    if (!field.value.trim()) {
                        field.classList.add('error');
                        isValid = false;
                    }
                });
                
                // Validate selected products
                if (selectedProducts.length === 0) {
                    isValid = false;
                    alert('Please add at least one product before submitting.');
                }
                
                if (!isValid) {
                    e.preventDefault();
                    alert('Please fix the errors before submitting the form.');
                } else {
                    // Add selected products as hidden inputs
                    selectedProducts.forEach((product, index) => {
                        const productIdInput = document.createElement('input');
                        productIdInput.type = 'hidden';
                        productIdInput.name = `products[${index}][product_id]`;
                        productIdInput.value = product.id;
                        form.appendChild(productIdInput);
                        
                        const quantityInput = document.createElement('input');
                        quantityInput.type = 'hidden';
                        quantityInput.name = `products[${index}][quantity]`;
                        quantityInput.value = product.quantity;
                        form.appendChild(quantityInput);
                        
                        const unitCostInput = document.createElement('input');
                        unitCostInput.type = 'hidden';
                        unitCostInput.name = `products[${index}][unit_cost]`;
                        unitCostInput.value = product.unit_cost;
                        form.appendChild(unitCostInput);
                    });
                }
            });
        });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

