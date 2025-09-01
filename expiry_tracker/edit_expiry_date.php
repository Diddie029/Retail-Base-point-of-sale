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
            p.sku
        FROM product_expiry_dates ped
        JOIN products p ON ped.product_id = p.id
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
    $unit_cost = trim($_POST['unit_cost'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $supplier_id = trim($_POST['supplier_id'] ?? '');
    $purchase_order_id = trim($_POST['purchase_order_id'] ?? '');
    $alert_days_before = trim($_POST['alert_days_before'] ?? '30');
    $notes = trim($_POST['notes'] ?? '');
    
    // Enhanced validation with proper type checking
    $errors = [];
    
    if (empty($expiry_date)) {
        $errors[] = "Expiry date is required";
    } elseif (strtotime($expiry_date) <= time()) {
        $errors[] = "Expiry date must be in the future";
    }
    
    if (empty($quantity)) {
        $errors[] = "Quantity is required";
    } elseif (!is_numeric($quantity) || floatval($quantity) <= 0) {
        $errors[] = "Quantity must be a positive number";
    } else {
        $quantity = floatval($quantity);
    }
    
    // Validate unit_cost - allow empty but ensure it's numeric if provided
    if (!empty($unit_cost)) {
        if (!is_numeric($unit_cost) || floatval($unit_cost) < 0) {
            $errors[] = "Unit cost must be a non-negative number";
        } else {
            $unit_cost = floatval($unit_cost);
        }
    } else {
        $unit_cost = 0.00; // Set default value for empty input
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
            
            // Calculate quantity difference
            $quantity_difference = $quantity - $expiry_item['quantity'];
            
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
                    updated_at = NOW()
                WHERE id = :id
            ");
            
            $stmt->execute([
                ':batch_number' => $batch_number ?: null,
                ':expiry_date' => $expiry_date,
                ':manufacturing_date' => $manufacturing_date ?: null,
                ':quantity' => $quantity,
                ':quantity_difference' => $quantity_difference,
                ':unit_cost' => $unit_cost,
                ':location' => $location ?: null,
                ':supplier_id' => $supplier_id ?: null,
                ':purchase_order_id' => $purchase_order_id ?: null,
                ':alert_days_before' => $alert_days_before,
                ':notes' => $notes ?: null,
                ':id' => $expiry_id
            ]);
            
            // Update product quantity if quantity changed
            if ($quantity_difference != 0) {
                $stmt = $conn->prepare("
                    UPDATE products 
                    SET quantity = quantity + :quantity_difference 
                    WHERE id = :product_id
                ");
                $stmt->execute([
                    ':quantity_difference' => $quantity_difference,
                    ':product_id' => $expiry_item['product_id']
                ]);
            }
            
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
            
            $message = "Expiry date updated successfully!";
            $message_type = "success";
            
            // Redirect to view the updated item
            header("Location: view_expiry_item.php?id=$expiry_id&success=updated");
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
                            <label>Product Name:</label>
                            <span><?php echo htmlspecialchars($expiry_item['product_name']); ?></span>
                        </div>
                        <div class="detail-group">
                            <label>SKU:</label>
                            <span><?php echo htmlspecialchars($expiry_item['sku']); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="form-container">
            <form method="POST" class="expiry-form">
                <div class="form-row">
                    <div class="form-group">
                        <label for="batch_number">Batch Number</label>
                        <input type="text" name="batch_number" id="batch_number" 
                               value="<?php echo htmlspecialchars($expiry_item['batch_number'] ?? ''); ?>"
                               placeholder="Enter batch number (optional)">
                    </div>
                    
                    <div class="form-group">
                        <label for="expiry_date">Expiry Date *</label>
                        <input type="date" name="expiry_date" id="expiry_date" 
                               value="<?php echo $expiry_item['expiry_date']; ?>"
                               min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" required>
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
                        <label for="quantity">Quantity *</label>
                        <input type="number" name="quantity" id="quantity" 
                               value="<?php echo $expiry_item['quantity']; ?>"
                               min="1" step="1" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="unit_cost">Unit Cost</label>
                        <input type="number" name="unit_cost" id="unit_cost" 
                               value="<?php echo $expiry_item['unit_cost'] ?? ''; ?>"
                               min="0" step="0.01">
                    </div>
                    
                    <div class="form-group">
                        <label for="location">Storage Location</label>
                        <input type="text" name="location" id="location" 
                               value="<?php echo htmlspecialchars($expiry_item['location'] ?? ''); ?>"
                               placeholder="e.g., Warehouse A, Shelf 3">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="supplier_id">Supplier</label>
                        <select name="supplier_id" id="supplier_id">
                            <option value="">Select Supplier</option>
                            <?php foreach ($suppliers as $supplier): ?>
                                <option value="<?php echo $supplier['id']; ?>" <?php echo ($expiry_item['supplier_id'] == $supplier['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($supplier['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="purchase_order_id">Purchase Order ID</label>
                        <input type="text" name="purchase_order_id" id="purchase_order_id" 
                               value="<?php echo htmlspecialchars($expiry_item['purchase_order_id'] ?? ''); ?>"
                               placeholder="Enter PO number (optional)">
                    </div>
                </div>

                <div class="form-row">
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

                <div class="form-group">
                    <label for="notes">Notes</label>
                    <textarea name="notes" id="notes" rows="3" 
                              placeholder="Additional notes about this expiry batch"><?php echo htmlspecialchars($expiry_item['notes'] ?? ''); ?></textarea>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Update Expiry Date
                    </button>
                    <a href="view_expiry_item.php?id=<?php echo $expiry_id; ?>" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </a>
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

        // Auto-calculate total cost
        document.getElementById('quantity').addEventListener('input', calculateTotalCost);
        document.getElementById('unit_cost').addEventListener('input', calculateTotalCost);

        function calculateTotalCost() {
            const quantity = parseFloat(document.getElementById('quantity').value) || 0;
            const unitCost = parseFloat(document.getElementById('unit_cost').value) || 0;
            const totalCost = quantity * unitCost;
            
            console.log('Total cost:', totalCost);
        }
    </script>
</body>
</html>
