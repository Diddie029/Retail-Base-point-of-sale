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

// Get expiry item ID
$expiry_id = isset($_GET['id']) ? trim($_GET['id']) : null;
if (!$expiry_id) {
    header("Location: expiry_tracker.php?error=invalid_item");
    exit();
}

// Get expiry item details
try {
    $stmt = $conn->prepare("
        SELECT 
            ped.*,
            p.name as product_name,
            p.sku,
            p.cost_price,
            p.price,
            p.quantity as product_total_quantity,
            s.name as supplier_name,
            submitted_user.username as submitted_by_name,
            approved_user.username as approved_by_name
        FROM product_expiry_dates ped
        JOIN products p ON ped.product_id = p.id
        LEFT JOIN suppliers s ON ped.supplier_id = s.id
        LEFT JOIN users submitted_user ON ped.submitted_by = submitted_user.id
        LEFT JOIN users approved_user ON ped.approved_by = approved_user.id
        WHERE ped.id = ?
    ");
    $stmt->execute([$expiry_id]);
    $expiry_item = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$expiry_item) {
        header("Location: expiry_tracker.php?error=item_not_found");
        exit();
    }
} catch (PDOException $e) {
    header("Location: expiry_tracker.php?error=db_error");
    exit();
}

$message = '';
$message_type = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $batch_number = trim($_POST['batch_number'] ?? '');
    $expiry_date = trim($_POST['expiry_date'] ?? '');
    $manufacturing_date = trim($_POST['manufacturing_date'] ?? '');
    $quantity = trim($_POST['quantity'] ?? '');
    $product_quantity = trim($_POST['product_quantity'] ?? '');
    $unit_cost = trim($_POST['unit_cost'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $supplier_id = trim($_POST['supplier_id'] ?? '');
    $purchase_order_id = trim($_POST['purchase_order_id'] ?? '');
    $alert_days_before = trim($_POST['alert_days_before'] ?? '30');
    $notes = trim($_POST['notes'] ?? '');
    $action = trim($_POST['action'] ?? '');
    
    // Enhanced validation with proper type checking
    $errors = [];
    
    if (empty($expiry_date)) {
        $errors[] = "Expiry date is required";
    } elseif (strtotime($expiry_date) <= time()) {
        $errors[] = "Expiry date must be in the future";
    }
    
    if (empty($quantity)) {
        $errors[] = "Expiry batch quantity is required";
    } elseif (!is_numeric($quantity) || floatval($quantity) <= 0) {
        $errors[] = "Expiry batch quantity must be a positive number";
    } else {
        $quantity = floatval($quantity);
    }
    
    if (empty($product_quantity)) {
        $errors[] = "Product total quantity is required";
    } elseif (!is_numeric($product_quantity) || floatval($product_quantity) < 0) {
        $errors[] = "Product total quantity must be a non-negative number";
    } else {
        $product_quantity = floatval($product_quantity);
    }
    
    // Validate that expiry batch quantity doesn't exceed product total quantity
    if ($quantity > $product_quantity) {
        $errors[] = "Expiry batch quantity cannot exceed product total quantity";
    }
    
    // Validate supplier_id - now required
    if (empty($supplier_id)) {
        $errors[] = "Supplier is required";
    } elseif (!is_numeric($supplier_id) || intval($supplier_id) <= 0) {
        $errors[] = "Please select a valid supplier";
    } else {
        $supplier_id = intval($supplier_id);
    }
    
    // Auto-sync unit cost from product data - no manual entry allowed
    $unit_cost = $expiry_item['cost_price'] ?: $expiry_item['price'] ?: 0.00;
    
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
            
            // Calculate quantity differences
            $expiry_quantity_difference = $quantity - $expiry_item['quantity'];
            $product_quantity_difference = $product_quantity - $expiry_item['product_total_quantity'];
            
            // Generate tracking number if not exists
            $tracking_number = $expiry_item['expiry_tracking_number'];
            if (empty($tracking_number)) {
                $tracking_number = generateExpiryTrackingNumber($conn);
            }
            
            // Determine approval status based on action
            $approval_status = $expiry_item['approval_status'] ?? 'draft';
            $submitted_by = $expiry_item['submitted_by'];
            $approved_by = $expiry_item['approved_by'];
            $submitted_at = $expiry_item['submitted_at'];
            $approved_at = $expiry_item['approved_at'];
            
            if ($action === 'approve') {
                $approval_status = 'approved';
                $approved_by = $user_id;
                $approved_at = date('Y-m-d H:i:s');
            } elseif ($action === 'submit') {
                $approval_status = 'submitted';
                $submitted_by = $user_id;
                $submitted_at = date('Y-m-d H:i:s');
            } elseif ($action === 'save') {
                $approval_status = 'draft';
            }
            
            // Update expiry date record
            $stmt = $conn->prepare("
                UPDATE product_expiry_dates SET
                    batch_number = :batch_number,
                    expiry_date = :expiry_date,
                    manufacturing_date = :manufacturing_date,
                    quantity = :quantity,
                    remaining_quantity = remaining_quantity + :quantity_difference,
                    unit_cost = :unit_cost,
                    location = :location,
                    supplier_id = :supplier_id,
                    purchase_order_id = :purchase_order_id,
                    alert_days_before = :alert_days_before,
                    notes = :notes,
                    expiry_tracking_number = :tracking_number,
                    approval_status = :approval_status,
                    submitted_by = :submitted_by,
                    approved_by = :approved_by,
                    submitted_at = :submitted_at,
                    approved_at = :approved_at,
                    updated_at = NOW()
                WHERE id = :id
            ");
            
            $stmt->execute([
                ':batch_number' => $batch_number ?: null,
                ':expiry_date' => $expiry_date,
                ':manufacturing_date' => $manufacturing_date ?: null,
                ':quantity' => $quantity,
                ':quantity_difference' => $expiry_quantity_difference,
                ':unit_cost' => $unit_cost,
                ':location' => $location ?: null,
                ':supplier_id' => $supplier_id ?: null,
                ':purchase_order_id' => $purchase_order_id ?: null,
                ':alert_days_before' => $alert_days_before,
                ':notes' => $notes ?: null,
                ':tracking_number' => $tracking_number,
                ':approval_status' => $approval_status,
                ':submitted_by' => $submitted_by ?: null,
                ':approved_by' => $approved_by ?: null,
                ':submitted_at' => $submitted_at ?: null,
                ':approved_at' => $approved_at ?: null,
                ':id' => $expiry_id
            ]);
            
            // Update product total quantity
            $stmt = $conn->prepare("
                UPDATE products 
                SET quantity = :product_quantity 
                WHERE id = :product_id
            ");
            $stmt->execute([
                ':product_quantity' => $product_quantity,
                ':product_id' => $expiry_item['product_id']
            ]);
            
            // Log the action
            $stmt = $conn->prepare("
                INSERT INTO activity_logs (user_id, action, details, created_at)
                VALUES (:user_id, 'edit_expiry_date', :details, NOW())
            ");
            
            $details = "Edited expiry date for product: {$expiry_item['product_name']}, ID: $expiry_id";
            $stmt->execute([
                ':user_id' => $user_id,
                ':details' => $details
            ]);
            
            $conn->commit();
            
            // Set success message based on action
            if ($action === 'approve') {
                $message = "Expiry date approved successfully!";
            } elseif ($action === 'submit') {
                $message = "Expiry date submitted for approval successfully!";
            } else {
                $message = "Expiry date updated successfully!";
            }
            $message_type = "success";
            
            // Redirect to view the updated item
            header("Location: view_expiry_item.php?id=$expiry_id&success=" . ($action ?: 'updated'));
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
                    $message = "Invalid reference data. Please check supplier or purchase order selection.";
                    break;
                case '1062': // Duplicate entry
                    $message = "A record with this information already exists.";
                    break;
                default:
                    $message = "Database error occurred. Please try again or contact support.";
                    // Log the actual error for debugging
                    error_log("Expiry tracker edit error: " . $error_message);
            }
            
            $message_type = "error";
        } catch (Exception $e) {
            $conn->rollBack();
            $message = "An unexpected error occurred. Please try again.";
            $message_type = "error";
            error_log("Unexpected error in expiry tracker edit: " . $e->getMessage());
        }
    } else {
        $message = implode("<br>", $errors);
        $message_type = "error";
    }
}

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

$page_title = "Edit Expiry Date";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - POS System</title>
    <link rel="stylesheet" href="../assets/css/expiry_tracker.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
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
        .item-details {
            background: #ffffff;
            border-radius: 8px;
            box-shadow: 0 2px 4px -1px rgba(0, 0, 0, 0.1);
            margin-bottom: 1rem;
        }
        .detail-card {
            padding: 1rem;
        }
        .detail-header h3 {
            margin: 0 0 1rem 0;
            color: #1e293b;
            font-size: 1rem;
            font-weight: 700;
        }
        .detail-content {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        .detail-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        .detail-group {
            display: flex;
            flex-direction: column;
        }
        .detail-group label {
            font-weight: 600;
            color: #64748b;
            margin-bottom: 0.25rem;
            font-size: 0.8rem;
        }
        .detail-group span {
            color: #1e293b;
            font-size: 0.9rem;
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
        .quantity-display {
            font-weight: 600;
            color: #1e293b;
            background: #f1f5f9;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            display: inline-block;
        }
        .form-help {
            font-size: 0.875rem;
            color: #64748b;
            margin-top: 0.25rem;
        }
        .form-help i {
            color: #3b82f6;
        }
        .supplier-display {
            font-weight: 600;
            color: #059669;
            background: #ecfdf5;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            display: inline-block;
        }
        .form-container {
            background: #ffffff;
            border-radius: 8px;
            box-shadow: 0 2px 4px -1px rgba(0, 0, 0, 0.1);
            padding: 1.25rem;
            margin-top: 1rem;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        .form-group {
            display: flex;
            flex-direction: column;
        }
        .form-group label {
            font-weight: 600;
            color: #374151;
            margin-bottom: 0.25rem;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 0.5rem;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 0.875rem;
            transition: all 0.2s ease;
            background: #ffffff;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.1);
        }
        .form-group input:disabled,
        .form-group select:disabled {
            background: #f9fafb;
            color: #6b7280;
            cursor: not-allowed;
            border-color: #d1d5db;
        }
        .input-group {
            display: flex;
            align-items: center;
        }
        .input-group .form-control {
            border-top-right-radius: 0;
            border-bottom-right-radius: 0;
            border-right: none;
        }
        .input-group-text {
            background: #f3f4f6;
            border: 1px solid #d1d5db;
            border-left: none;
            border-top-right-radius: 6px;
            border-bottom-right-radius: 6px;
            padding: 0.5rem;
            color: #6b7280;
        }
        .form-help {
            font-size: 0.7rem;
            color: #6b7280;
            margin-top: 0.2rem;
            display: flex;
            align-items: center;
            gap: 0.2rem;
        }
        .form-help i {
            color: #3b82f6;
            font-size: 0.875rem;
        }
        .required-asterisk {
            color: #ef4444;
            margin-left: 0.25rem;
        }
        .readonly-field {
            background: #f8fafc !important;
            color: #64748b !important;
            border-color: #e2e8f0 !important;
            cursor: not-allowed !important;
        }
        .supplier-readonly {
            background: #f0fdf4 !important;
            color: #166534 !important;
            border-color: #bbf7d0 !important;
            font-weight: 600;
        }
        .cost-summary {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 1rem;
            margin: 1.5rem 0;
            box-shadow: 0 1px 3px -1px rgba(0, 0, 0, 0.06);
        }
        .summary-header h4 {
            margin: 0 0 1rem 0;
            color: #1e293b;
            font-size: 1rem;
            font-weight: 700;
        }
        .summary-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
            margin-bottom: 0.75rem;
        }
        .summary-item {
            text-align: center;
            padding: 0.75rem;
            background: #ffffff;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
        }
        .summary-item label {
            font-size: 0.7rem;
            color: #64748b;
            margin-bottom: 0.25rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 600;
        }
        .summary-item span {
            font-size: 1rem;
            font-weight: 700;
            color: #1e293b;
        }
        .total-value {
            color: #059669 !important;
            font-size: 1.25rem !important;
        }
        .status-text.ready {
            color: #059669;
            font-weight: 700;
        }
        .status-text.warning {
            color: #d97706;
            font-weight: 700;
        }
        .form-actions {
            display: flex;
            gap: 0.75rem;
            justify-content: flex-end;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid #e5e7eb;
        }
        .btn {
            padding: 0.6rem 1.25rem;
            border-radius: 6px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            transition: all 0.2s ease;
            border: none;
            cursor: pointer;
            font-size: 0.8rem;
        }
        .btn-primary {
            background: #3b82f6;
            color: #ffffff;
        }
        .btn-primary:hover {
            background: #2563eb;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px -2px rgba(59, 130, 246, 0.3);
        }
        .btn-secondary {
            background: #6b7280;
            color: #ffffff;
        }
        .btn-secondary:hover {
            background: #4b5563;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px -2px rgba(107, 114, 128, 0.3);
        }
        .form-section {
            margin-bottom: 1.5rem;
            padding-bottom: 1.25rem;
            border-bottom: 1px solid #f1f5f9;
        }
        .form-section:last-of-type {
            border-bottom: none;
            margin-bottom: 0;
        }
        .section-title {
            color: #1e293b;
            font-size: 1rem;
            font-weight: 700;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #3b82f6;
            display: flex;
            align-items: center;
        }
        .section-title i {
            color: #3b82f6;
            font-size: 0.9rem;
        }
        .tracking-number {
            font-weight: 700;
            color: #1e40af;
            background: #dbeafe;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            display: inline-block;
            font-family: 'Courier New', monospace;
        }
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .status-badge.draft {
            background: #f3f4f6;
            color: #6b7280;
        }
        .status-badge.submitted {
            background: #fef3c7;
            color: #d97706;
        }
        .status-badge.approved {
            background: #d1fae5;
            color: #059669;
        }
        .status-badge.rejected {
            background: #fee2e2;
            color: #dc2626;
        }
        .form-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid #e5e7eb;
        }
        .action-group {
            display: flex;
            gap: 0.75rem;
        }
        .cancel-group {
            display: flex;
            gap: 0.75rem;
        }
        .btn-warning {
            background: #f59e0b;
            color: #ffffff;
        }
        .btn-warning:hover {
            background: #d97706;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px -2px rgba(245, 158, 11, 0.3);
        }
        .btn-success {
            background: #10b981;
            color: #ffffff;
        }
        .btn-success:hover {
            background: #059669;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px -2px rgba(16, 185, 129, 0.3);
        }
        .btn-outline {
            background: transparent;
            color: #6b7280;
            border: 1px solid #d1d5db;
        }
        .btn-outline:hover {
            background: #f9fafb;
            color: #374151;
            border-color: #9ca3af;
        }
    </style>
</head>
<body>
    <?php include '../include/navmenu.php'; ?>
    
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-edit"></i> <?php echo $page_title; ?></h1>
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

        <!-- Product Info -->
        <div class="item-details">
            <div class="detail-card">
                <div class="detail-header">
                    <h3>Product Information</h3>
                </div>
                <div class="detail-content">
                    <div class="detail-row">
                        <div class="detail-group">
                            <label>Expiry Tracking Number:</label>
                            <span class="tracking-number"><?php echo htmlspecialchars($expiry_item['expiry_tracking_number'] ?? 'Not assigned'); ?></span>
                        </div>
                        <div class="detail-group">
                            <label>Approval Status:</label>
                            <span class="status-badge <?php echo strtolower($expiry_item['approval_status'] ?? 'draft'); ?>">
                                <?php echo ucfirst($expiry_item['approval_status'] ?? 'Draft'); ?>
                            </span>
                        </div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-group">
                            <label>Product Name:</label>
                            <span><?php echo htmlspecialchars($expiry_item['product_name']); ?></span>
                        </div>
                        <div class="detail-group">
                            <label>SKU:</label>
                            <span><?php echo htmlspecialchars($expiry_item['sku']); ?></span>
                        </div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-group">
                            <label>Current Product Quantity:</label>
                            <span class="quantity-display"><?php echo number_format($expiry_item['quantity']); ?> units</span>
                        </div>
                        <div class="detail-group">
                            <label>Product Cost Price:</label>
                            <span>KES <?php echo number_format($expiry_item['cost_price'] ?: 0, 2); ?></span>
                        </div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-group">
                            <label>Supplier:</label>
                            <span class="supplier-display"><?php echo htmlspecialchars($expiry_item['supplier_name'] ?? 'Not specified'); ?></span>
                        </div>
                        <div class="detail-group">
                            <label>Product Selling Price:</label>
                            <span>KES <?php echo number_format($expiry_item['price'] ?: 0, 2); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="form-container">
            <form method="POST" class="expiry-form">
                <!-- Supplier Information Section -->
                <div class="form-section">
                    <h3 class="section-title">
                        <i class="fas fa-truck me-2"></i>Supplier Information
                    </h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="supplier_display">Supplier (Read-only)</label>
                            <input type="text" id="supplier_display" 
                                   value="<?php echo htmlspecialchars($expiry_item['supplier_name'] ?? 'Not specified'); ?>"
                                   readonly class="supplier-readonly">
                            <small class="form-help">
                                <i class="bi bi-lock me-1"></i>
                                Supplier cannot be changed after expiry date creation
                            </small>
                            <input type="hidden" name="supplier_id" value="<?php echo $expiry_item['supplier_id']; ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="purchase_order_id">Purchase Order ID</label>
                            <input type="text" name="purchase_order_id" id="purchase_order_id" 
                                   value="<?php echo htmlspecialchars($expiry_item['purchase_order_id'] ?? ''); ?>"
                                   placeholder="Enter PO number (optional)">
                        </div>
                    </div>
                </div>

                <!-- Expiry Details Section -->
                <div class="form-section">
                    <h3 class="section-title">
                        <i class="fas fa-calendar-alt me-2"></i>Expiry Details
                    </h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="batch_number">Batch Number</label>
                            <input type="text" name="batch_number" id="batch_number" 
                                   value="<?php echo htmlspecialchars($expiry_item['batch_number'] ?? ''); ?>"
                                   placeholder="Enter batch number (optional)">
                        </div>
                        
                        <div class="form-group">
                            <label for="expiry_date">Expiry Date<span class="required-asterisk">*</span></label>
                            <input type="date" name="expiry_date" id="expiry_date" 
                                   value="<?php echo $expiry_item['expiry_date']; ?>"
                                   min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" required>
                        </div>
                    </div>
                </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="manufacturing_date">Manufacturing Date</label>
                            <input type="date" name="manufacturing_date" id="manufacturing_date" 
                                   value="<?php echo $expiry_item['manufacturing_date'] ?? ''; ?>"
                                   max="<?php echo date('Y-m-d'); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="alert_days_before">Alert Days Before Expiry</label>
                            <select name="alert_days_before" id="alert_days_before">
                                <?php foreach ($expiry_categories as $category): ?>
                                    <option value="<?php echo $category['alert_threshold_days']; ?>" 
                                            <?php echo ($expiry_item['alert_days_before'] == $category['alert_threshold_days']) ? 'selected' : ''; ?>>
                                        <?php echo $category['alert_threshold_days']; ?> days - 
                                        <?php echo htmlspecialchars($category['category_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Quantity Information Section -->
                <div class="form-section">
                    <h3 class="section-title">
                        <i class="fas fa-boxes me-2"></i>Quantity Information
                    </h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="quantity">Expiry Batch Quantity<span class="required-asterisk">*</span></label>
                            <input type="number" name="quantity" id="quantity" 
                                   value="<?php echo $expiry_item['quantity']; ?>"
                                   min="1" step="1" required>
                            <small class="form-help">
                                <i class="bi bi-info-circle me-1"></i>
                                Quantity for this specific expiry batch
                            </small>
                        </div>
                        
                        <div class="form-group">
                            <label for="product_quantity">Product Total Quantity<span class="required-asterisk">*</span></label>
                            <input type="number" name="product_quantity" id="product_quantity" 
                                   value="<?php echo $expiry_item['product_total_quantity']; ?>"
                                   min="0" step="1" required>
                            <small class="form-help">
                                <i class="bi bi-info-circle me-1"></i>
                                Total quantity available for this product in inventory
                            </small>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="remaining_quantity">Remaining Quantity (Read-only)</label>
                            <input type="number" id="remaining_quantity" 
                                   value="<?php echo $expiry_item['remaining_quantity']; ?>"
                                   readonly class="readonly-field">
                            <small class="form-help">
                                <i class="bi bi-calculator me-1"></i>
                                Calculated as: Product Quantity - Expiry Batch Quantity
                            </small>
                        </div>
                        
                        <div class="form-group">
                            <label for="location">Storage Location</label>
                            <input type="text" name="location" id="location" 
                                   value="<?php echo htmlspecialchars($expiry_item['location'] ?? ''); ?>"
                                   placeholder="e.g., Warehouse A, Shelf 3">
                        </div>
                    </div>
                </div>

                <!-- Pricing & Additional Information Section -->
                <div class="form-section">
                    <h3 class="section-title">
                        <i class="fas fa-dollar-sign me-2"></i>Pricing & Additional Information
                    </h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="unit_cost">Unit Cost (Auto-Synced)</label>
                            <div class="input-group">
                                <input type="text" id="unit_cost_display" 
                                       value="KES <?php echo number_format($expiry_item['cost_price'] ?: $expiry_item['price'] ?: 0, 2); ?>"
                                       readonly class="form-control">
                                <span class="input-group-text">
                                    <i class="bi bi-check-circle text-success" title="Auto-synced from product data"></i>
                                </span>
                            </div>
                            <small class="form-help text-success">
                                <i class="bi bi-info-circle me-1"></i>
                                Automatically synced from product cost price (KES <?php echo number_format($expiry_item['cost_price'] ?: 0, 2); ?>)
                            </small>
                            <input type="hidden" name="unit_cost" value="<?php echo $expiry_item['cost_price'] ?: $expiry_item['price'] ?: 0; ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="notes">Notes</label>
                            <textarea name="notes" id="notes" rows="3" 
                                      placeholder="Additional notes about this expiry batch"><?php echo htmlspecialchars($expiry_item['notes'] ?? ''); ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- Cost Summary -->
                <div class="cost-summary">
                    <div class="summary-header">
                        <h4><i class="fas fa-calculator me-2"></i>Cost Summary</h4>
                    </div>
                    <div class="summary-content">
                        <div class="summary-row">
                            <div class="summary-item">
                                <label>Unit Cost</label>
                                <span id="summary-unit-cost">KES 0.00</span>
                            </div>
                            <div class="summary-item">
                                <label>Quantity</label>
                                <span id="summary-quantity">0</span>
                            </div>
                        </div>
                        <div class="summary-row">
                            <div class="summary-item">
                                <label>Total Value</label>
                                <span id="summary-total-value" class="total-value">KES 0.00</span>
                            </div>
                            <div class="summary-item">
                                <label>Status</label>
                                <span id="summary-status" class="status-text">Ready to update</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <div class="action-group">
                        <button type="submit" name="action" value="save" class="btn btn-secondary">
                            <i class="fas fa-save"></i> Save Draft
                        </button>
                        <button type="submit" name="action" value="submit" class="btn btn-warning">
                            <i class="fas fa-paper-plane"></i> Submit for Review
                        </button>
                        <?php if (in_array('approve_expiry_items', $permissions)): ?>
                        <button type="submit" name="action" value="approve" class="btn btn-success">
                            <i class="fas fa-check-circle"></i> Approve
                        </button>
                        <?php endif; ?>
                    </div>
                    <div class="cancel-group">
                        <a href="view_expiry_item.php?id=<?php echo $expiry_id; ?>" class="btn btn-outline">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Validate expiry date
        document.getElementById('expiry_date').addEventListener('blur', function() {
            const expiryDate = new Date(this.value);
            const today = new Date();
            
            if (expiryDate <= today) {
                this.setCustomValidity('Expiry date must be in the future');
                this.style.borderColor = '#ef4444';
            } else {
                this.setCustomValidity('');
                this.style.borderColor = '#e2e8f0';
            }
        });

        // Auto-synced unit cost - no manual input needed
        const unitCostValue = <?php echo $expiry_item['cost_price'] ?: $expiry_item['price'] ?: 0; ?>;
        
        // Initialize cost summary on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Calculate initial cost summary
            calculateTotalCost();
            // Calculate initial remaining quantity
            calculateRemainingQuantity();
        });
        
        // Auto-calculate total cost and remaining quantity
        document.getElementById('quantity').addEventListener('input', calculateTotalCost);
        document.getElementById('product_quantity').addEventListener('input', calculateRemainingQuantity);
        document.getElementById('quantity').addEventListener('input', calculateRemainingQuantity);

        function calculateTotalCost() {
            const quantity = parseFloat(document.getElementById('quantity').value) || 0;
            const unitCost = unitCostValue; // Use auto-synced value
            const totalCost = quantity * unitCost;
            
            // Debug logging
            console.log('Calculating cost summary:');
            console.log('Quantity:', quantity);
            console.log('Unit Cost:', unitCost);
            console.log('Total Cost:', totalCost);
            
            // Update cost summary
            updateCostSummary(unitCost, quantity, totalCost);
        }

        function calculateRemainingQuantity() {
            const productQuantity = parseFloat(document.getElementById('product_quantity').value) || 0;
            const expiryQuantity = parseFloat(document.getElementById('quantity').value) || 0;
            const remainingQuantity = productQuantity - expiryQuantity;
            
            document.getElementById('remaining_quantity').value = Math.max(0, remainingQuantity);
        }

        function updateCostSummary(unitCost, quantity, totalCost) {
            // Update summary display with error checking
            const unitCostElement = document.getElementById('summary-unit-cost');
            const quantityElement = document.getElementById('summary-quantity');
            const totalValueElement = document.getElementById('summary-total-value');
            const statusElement = document.getElementById('summary-status');
            
            if (unitCostElement) {
                unitCostElement.textContent = `KES ${unitCost.toFixed(2)}`;
            }
            if (quantityElement) {
                quantityElement.textContent = quantity.toString();
            }
            if (totalValueElement) {
                totalValueElement.textContent = `KES ${totalCost.toFixed(2)}`;
            }
            
            // Update status
            if (statusElement) {
                if (quantity > 0 && unitCost > 0) {
                    statusElement.textContent = 'Ready to update';
                    statusElement.className = 'status-text ready';
                } else if (quantity > 0) {
                    statusElement.textContent = 'Unit cost needed';
                    statusElement.className = 'status-text warning';
                } else {
                    statusElement.textContent = 'Quantity needed';
                    statusElement.className = 'status-text warning';
                }
            }
            
            console.log('Cost summary updated successfully');
        }
    </script>
</body>
</html>
