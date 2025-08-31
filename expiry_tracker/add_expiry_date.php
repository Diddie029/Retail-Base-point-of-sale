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
    $product_id = $_POST['product_id'] ?? '';
    $batch_number = $_POST['batch_number'] ?? '';
    $expiry_date = $_POST['expiry_date'] ?? '';
    $manufacturing_date = $_POST['manufacturing_date'] ?? '';
    $quantity = $_POST['quantity'] ?? 0;
    $unit_cost = $_POST['unit_cost'] ?? 0;
    $location = $_POST['location'] ?? '';
    $supplier_id = $_POST['supplier_id'] ?? '';
    $purchase_order_id = $_POST['purchase_order_id'] ?? '';
    $alert_days_before = $_POST['alert_days_before'] ?? 30;
    $notes = $_POST['notes'] ?? '';
    
    // Validation
    $errors = [];
    
    if (empty($product_id)) {
        $errors[] = "Product is required";
    }
    
    if (empty($expiry_date)) {
        $errors[] = "Expiry date is required";
    } elseif (strtotime($expiry_date) <= time()) {
        $errors[] = "Expiry date must be in the future";
    }
    
    if (empty($quantity) || $quantity <= 0) {
        $errors[] = "Quantity must be greater than 0";
    }
    
    if (empty($errors)) {
        try {
            $conn->beginTransaction();
            
            // Insert expiry date record
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
                ':batch_number' => $batch_number,
                ':expiry_date' => $expiry_date,
                ':manufacturing_date' => $manufacturing_date ?: null,
                ':quantity' => $quantity,
                ':unit_cost' => $unit_cost,
                ':location' => $location,
                ':supplier_id' => $supplier_id ?: null,
                ':purchase_order_id' => $purchase_order_id ?: null,
                ':alert_days_before' => $alert_days_before,
                ':notes' => $notes
            ]);
            
            $expiry_id = $conn->lastInsertId();
            
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
            
            // Log the action
            $stmt = $conn->prepare("
                INSERT INTO activity_logs (user_id, action, details, created_at)
                VALUES (:user_id, 'add_expiry_date', :details, NOW())
            ");
            
            $details = "Added expiry date for product ID: $product_id, Quantity: $quantity, Expiry: $expiry_date";
            $stmt->execute([
                ':user_id' => $user_id,
                ':details' => $details
            ]);
            
            $conn->commit();
            
            $message = "Expiry date added successfully!";
            $message_type = "success";
            
            // Redirect to view the added item
            header("Location: view_expiry_item.php?id=$expiry_id&success=1");
            exit();
            
        } catch (PDOException $e) {
            $conn->rollBack();
            $message = "Error adding expiry date: " . $e->getMessage();
            $message_type = "error";
        }
    } else {
        $message = implode("<br>", $errors);
        $message_type = "error";
    }
}

// Get products for dropdown
$products = $conn->query("
    SELECT p.id, p.name, p.sku, c.name as category_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.status = 'active'
    ORDER BY p.name
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
    <link rel="stylesheet" href="../assets/css/expiry_tracker.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
    <?php include '../include/navmenu.php'; ?>
    
    <div class="container">
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

        <div class="form-container">
            <form method="POST" class="expiry-form">
                <div class="form-row">
                    <div class="form-group">
                        <label for="product_id">Product *</label>
                        <select name="product_id" id="product_id" required>
                            <option value="">Select Product</option>
                            <?php foreach ($products as $product): ?>
                                <option value="<?php echo $product['id']; ?>" <?php echo (isset($_POST['product_id']) && $_POST['product_id'] == $product['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($product['name']); ?> 
                                    (<?php echo htmlspecialchars($product['sku']); ?>) - 
                                    <?php echo htmlspecialchars($product['category_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="batch_number">Batch Number</label>
                        <input type="text" name="batch_number" id="batch_number" 
                               value="<?php echo htmlspecialchars($_POST['batch_number'] ?? ''); ?>"
                               placeholder="Enter batch number (optional)">
                    </div>
                </div>

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

                <div class="form-row">
                    <div class="form-group">
                        <label for="quantity">Quantity *</label>
                        <input type="number" name="quantity" id="quantity" 
                               value="<?php echo $_POST['quantity'] ?? ''; ?>"
                               min="1" step="1" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="unit_cost">Unit Cost</label>
                        <input type="number" name="unit_cost" id="unit_cost" 
                               value="<?php echo $_POST['unit_cost'] ?? ''; ?>"
                               min="0" step="0.01">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="location">Storage Location</label>
                        <input type="text" name="location" id="location" 
                               value="<?php echo htmlspecialchars($_POST['location'] ?? ''); ?>"
                               placeholder="e.g., Warehouse A, Shelf 3">
                    </div>
                    
                    <div class="form-group">
                        <label for="supplier_id">Supplier</label>
                        <select name="supplier_id" id="supplier_id">
                            <option value="">Select Supplier</option>
                            <?php foreach ($suppliers as $supplier): ?>
                                <option value="<?php echo $supplier['id']; ?>" <?php echo (isset($_POST['supplier_id']) && $_POST['supplier_id'] == $supplier['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($supplier['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
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
        // Auto-fill manufacturing date if not provided
        document.getElementById('expiry_date').addEventListener('change', function() {
            const expiryDate = new Date(this.value);
            const manufacturingDate = document.getElementById('manufacturing_date');
            
            if (!manufacturingDate.value) {
                // Set manufacturing date to 30 days before expiry (common for many products)
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
            
            // You could display this somewhere if needed
            console.log('Total cost:', totalCost);
        }
    </script>
</body>
</html>
