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

// Check if user has permission to handle expired items
if (!in_array('handle_expired_items', $permissions)) {
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
            p.image_url,
            c.name as category_name,
            s.name as supplier_name
        FROM product_expiry_dates ped
        JOIN products p ON ped.product_id = p.id
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN suppliers s ON ped.supplier_id = s.id
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
    // Debug: Log POST data
    error_log("POST data received: " . print_r($_POST, true));
    
    $action_type = $_POST['action_type'] ?? '';
    $quantity_affected = $_POST['quantity_affected'] ?? 0;
    $reason = $_POST['reason'] ?? '';
    $cost = $_POST['cost'] ?? 0;
    $revenue = $_POST['revenue'] ?? 0;
    $disposal_method = $_POST['disposal_method'] ?? '';
    $return_reference = $_POST['return_reference'] ?? '';
    $notes = $_POST['notes'] ?? '';
    
    // Debug: Log processed data
    error_log("Processed data - Action: $action_type, Quantity: $quantity_affected, Reason: $reason");
    
    // Validation
    $errors = [];
    
    if (empty($action_type)) {
        $errors[] = "Action type is required";
    }
    
    if (empty($quantity_affected) || $quantity_affected <= 0) {
        $errors[] = "Quantity affected must be greater than 0";
    }
    
    if ($quantity_affected > $expiry_item['remaining_quantity']) {
        $errors[] = "Quantity affected cannot exceed remaining quantity";
    }
    
    if (empty($reason)) {
        $errors[] = "Reason is required";
    }
    
    if (empty($errors)) {
        try {
            $conn->beginTransaction();
            
            // Insert expiry action record
            error_log("Attempting to insert expiry action record...");
            $stmt = $conn->prepare("
                INSERT INTO expiry_actions (
                    product_expiry_id, action_type, action_date, quantity_affected,
                    user_id, reason, cost, revenue, disposal_method, return_reference, notes
                ) VALUES (
                    :product_expiry_id, :action_type, NOW(), :quantity_affected,
                    :user_id, :reason, :cost, :revenue, :disposal_method, :return_reference, :notes
                )
            ");
            
            $params = [
                ':product_expiry_id' => $expiry_id,
                ':action_type' => $action_type,
                ':quantity_affected' => $quantity_affected,
                ':user_id' => $user_id,
                ':reason' => $reason,
                ':cost' => $cost,
                ':revenue' => $revenue,
                ':disposal_method' => $disposal_method,
                ':return_reference' => $return_reference,
                ':notes' => $notes
            ];
            
            error_log("Execute parameters: " . print_r($params, true));
            $stmt->execute($params);
            error_log("Expiry action record inserted successfully");
            
            // Update remaining quantity
            $new_remaining = $expiry_item['remaining_quantity'] - $quantity_affected;
            
            if ($new_remaining <= 0) {
                // All items processed, update status
                $stmt = $conn->prepare("
                    UPDATE product_expiry_dates 
                    SET status = 'disposed', remaining_quantity = 0, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$expiry_id]);
                
                // Update product quantity
                $stmt = $conn->prepare("
                    UPDATE products 
                    SET quantity = GREATEST(0, quantity - ?)
                    WHERE id = ?
                ");
                $stmt->execute([$quantity_affected, $expiry_item['product_id']]);
            } else {
                // Update remaining quantity
                $stmt = $conn->prepare("
                    UPDATE product_expiry_dates 
                    SET remaining_quantity = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$new_remaining, $expiry_id]);
                
                // Update product quantity
                $stmt = $conn->prepare("
                    UPDATE products 
                    SET quantity = GREATEST(0, quantity - ?)
                    WHERE id = ?
                ");
                $stmt->execute([$quantity_affected, $expiry_item['product_id']]);
            }
            
            // Log the action
            $stmt = $conn->prepare("
                INSERT INTO activity_logs (user_id, action, details, created_at)
                VALUES (:user_id, 'handle_expiry', :details, NOW())
            ");
            
            $details = "Handled expiry for product: {$expiry_item['product_name']}, Action: $action_type, Quantity: $quantity_affected";
            $stmt->execute([
                ':user_id' => $user_id,
                ':details' => $details
            ]);
            
            $conn->commit();
            
            $message = "Expiry item handled successfully!";
            $message_type = "success";
            
            // If this is an AJAX request, return JSON success
            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => $message, 'id' => $expiry_id]);
                exit();
            }
            
            // Redirect back to tracker for non-AJAX requests
            header("Location: expiry_tracker.php?success=handled&id=$expiry_id");
            exit();
            
        } catch (PDOException $e) {
            $conn->rollBack();
            $message = "Error handling expiry item: " . $e->getMessage();
            $message_type = "error";
            
            // Log detailed error for debugging
            error_log("Handle Expiry Error: " . $e->getMessage());
            error_log("SQL State: " . $e->getCode());
            error_log("File: " . $e->getFile() . " Line: " . $e->getLine());
            
            // If this is an AJAX request, return JSON error
            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $message]);
                exit();
            }
        }
    } else {
        $message = implode("<br>", $errors);
        $message_type = "error";
        
        // If this is an AJAX request, return JSON validation error
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $message, 'errors' => $errors]);
            exit();
        }
    }
}

// Calculate days until expiry
$days_until_expiry = (strtotime($expiry_item['expiry_date']) - time()) / (60 * 60 * 24);
$is_expired = $days_until_expiry < 0;
$is_critical = $days_until_expiry <= 7 && !$is_expired;

$page_title = "Handle Expiry Item";
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
            <h1><i class="fas fa-tools"></i> <?php echo $page_title; ?></h1>
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

        <!-- Item Details -->
        <div class="item-details">
            <div class="detail-card">
                <div class="detail-header">
                    <h3>Item Information</h3>
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
                    
                    <div class="detail-row">
                        <div class="detail-group">
                            <label>Category:</label>
                            <span><?php echo htmlspecialchars($expiry_item['category_name']); ?></span>
                        </div>
                        <div class="detail-group">
                            <label>Supplier:</label>
                            <span><?php echo htmlspecialchars($expiry_item['supplier_name'] ?: 'N/A'); ?></span>
                        </div>
                    </div>
                    
                    <div class="detail-row">
                        <div class="detail-group">
                            <label>Batch Number:</label>
                            <span><?php echo htmlspecialchars($expiry_item['batch_number'] ?: 'N/A'); ?></span>
                        </div>
                        <div class="detail-group">
                            <label>Location:</label>
                            <span><?php echo htmlspecialchars($expiry_item['location'] ?: 'N/A'); ?></span>
                        </div>
                    </div>
                    
                    <div class="detail-row">
                        <div class="detail-group">
                            <label>Expiry Date:</label>
                            <span class="expiry-date <?php echo $is_critical ? 'critical' : ($is_expired ? 'expired' : ''); ?>">
                                <?php echo date('M d, Y', strtotime($expiry_item['expiry_date'])); ?>
                            </span>
                        </div>
                        <div class="detail-group">
                            <label>Days Left:</label>
                            <span class="days-left <?php echo $is_critical ? 'critical' : ($is_expired ? 'expired' : ''); ?>">
                                <?php 
                                if ($is_expired) {
                                    echo '<span class="expired">Expired ' . abs(round($days_until_expiry)) . ' days ago</span>';
                                } else {
                                    echo round($days_until_expiry) . ' days';
                                }
                                ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="detail-row">
                        <div class="detail-group">
                            <label>Total Quantity:</label>
                            <span><?php echo number_format($expiry_item['quantity']); ?></span>
                        </div>
                        <div class="detail-group">
                            <label>Remaining Quantity:</label>
                            <span class="quantity-remaining"><?php echo number_format($expiry_item['remaining_quantity']); ?></span>
                        </div>
                    </div>
                    
                    <?php if ($expiry_item['unit_cost'] > 0): ?>
                    <div class="detail-row">
                        <div class="detail-group">
                            <label>Unit Cost:</label>
                            <span>KES <?php echo number_format($expiry_item['unit_cost'], 2); ?></span>
                        </div>
                        <div class="detail-group">
                            <label>Total Value:</label>
                            <span>KES <?php echo number_format($expiry_item['remaining_quantity'] * $expiry_item['unit_cost'], 2); ?></span>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Action Form -->
        <div class="action-form-container">
            <div class="action-form">
                <div class="form-header">
                    <h3>Take Action</h3>
                </div>
                
                <form method="POST" class="handle-expiry-form">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="action_type">Action Type *</label>
                            <select name="action_type" id="action_type" required>
                                <option value="">Select Action</option>
                                <option value="dispose" <?php echo (isset($_POST['action_type']) && $_POST['action_type'] === 'dispose') ? 'selected' : ''; ?>>Dispose</option>
                                <option value="return" <?php echo (isset($_POST['action_type']) && $_POST['action_type'] === 'return') ? 'selected' : ''; ?>>Return to Supplier</option>
                                <option value="sell_at_discount" <?php echo (isset($_POST['action_type']) && $_POST['action_type'] === 'sell_at_discount') ? 'selected' : ''; ?>>Sell at Discount</option>
                                <option value="donate" <?php echo (isset($_POST['action_type']) && $_POST['action_type'] === 'donate') ? 'selected' : ''; ?>>Donate</option>
                                <option value="recall" <?php echo (isset($_POST['action_type']) && $_POST['action_type'] === 'recall') ? 'selected' : ''; ?>>Recall</option>
                                <option value="other" <?php echo (isset($_POST['action_type']) && $_POST['action_type'] === 'other') ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="quantity_affected">Quantity to Process *</label>
                            <input type="number" name="quantity_affected" id="quantity_affected" 
                                   value="<?php echo $_POST['quantity_affected'] ?? ''; ?>"
                                   min="1" max="<?php echo $expiry_item['remaining_quantity']; ?>" required>
                            <small>Maximum: <?php echo number_format($expiry_item['remaining_quantity']); ?></small>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="reason">Reason *</label>
                            <textarea name="reason" id="reason" rows="3" required
                                      placeholder="Explain why this action is being taken"><?php echo htmlspecialchars($_POST['reason'] ?? ''); ?></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label for="notes">Additional Notes</label>
                            <textarea name="notes" id="notes" rows="3"
                                      placeholder="Any additional information"><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="cost">Cost (if applicable)</label>
                            <input type="number" name="cost" id="cost" 
                                   value="<?php echo $_POST['cost'] ?? ''; ?>"
                                   min="0" step="0.01" placeholder="0.00">
                        </div>
                        
                        <div class="form-group">
                            <label for="revenue">Revenue (if applicable)</label>
                            <input type="number" name="revenue" id="revenue" 
                                   value="<?php echo $_POST['revenue'] ?? ''; ?>"
                                   min="0" step="0.01" placeholder="0.00">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="disposal_method">Disposal Method</label>
                            <select name="disposal_method" id="disposal_method">
                                <option value="">Select Method</option>
                                <option value="incineration" <?php echo (isset($_POST['disposal_method']) && $_POST['disposal_method'] === 'incineration') ? 'selected' : ''; ?>>Incineration</option>
                                <option value="landfill" <?php echo (isset($_POST['disposal_method']) && $_POST['disposal_method'] === 'landfill') ? 'selected' : ''; ?>>Landfill</option>
                                <option value="recycling" <?php echo (isset($_POST['disposal_method']) && $_POST['disposal_method'] === 'recycling') ? 'selected' : ''; ?>>Recycling</option>
                                <option value="composting" <?php echo (isset($_POST['disposal_method']) && $_POST['disposal_method'] === 'composting') ? 'selected' : ''; ?>>Composting</option>
                                <option value="other" <?php echo (isset($_POST['disposal_method']) && $_POST['disposal_method'] === 'other') ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="return_reference">Return Reference</label>
                            <input type="text" name="return_reference" id="return_reference" 
                                   value="<?php echo htmlspecialchars($_POST['return_reference'] ?? ''); ?>"
                                   placeholder="Return authorization number">
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-check"></i> Submit Action
                        </button>
                        <a href="expiry_tracker.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Show/hide fields based on action type
        document.getElementById('action_type').addEventListener('change', function() {
            const actionType = this.value;
            const disposalMethodGroup = document.querySelector('label[for="disposal_method"]').parentElement;
            const returnReferenceGroup = document.querySelector('label[for="return_reference"]').parentElement;
            const costGroup = document.querySelector('label[for="cost"]').parentElement;
            const revenueGroup = document.querySelector('label[for="revenue"]').parentElement;
            
            // Hide all conditional fields first
            disposalMethodGroup.style.display = 'none';
            returnReferenceGroup.style.display = 'none';
            costGroup.style.display = 'none';
            revenueGroup.style.display = 'none';
            
            // Show relevant fields based on action type
            switch(actionType) {
                case 'dispose':
                    disposalMethodGroup.style.display = 'block';
                    costGroup.style.display = 'block';
                    break;
                case 'return':
                    returnReferenceGroup.style.display = 'block';
                    costGroup.style.display = 'block';
                    break;
                case 'sell_at_discount':
                    revenueGroup.style.display = 'block';
                    break;
                case 'recall':
                    costGroup.style.display = 'block';
                    break;
            }
        });
        
        // Validate quantity
        document.getElementById('quantity_affected').addEventListener('input', function() {
            const maxQuantity = <?php echo $expiry_item['remaining_quantity']; ?>;
            const inputQuantity = parseInt(this.value);
            
            if (inputQuantity > maxQuantity) {
                this.setCustomValidity(`Quantity cannot exceed ${maxQuantity}`);
                this.style.borderColor = '#ef4444';
            } else if (inputQuantity <= 0) {
                this.setCustomValidity('Quantity must be greater than 0');
                this.style.borderColor = '#ef4444';
            } else {
                this.setCustomValidity('');
                this.style.borderColor = '#e2e8f0';
            }
        });
        
        // Auto-calculate total cost
        document.getElementById('quantity_affected').addEventListener('input', calculateTotalCost);
        document.getElementById('cost').addEventListener('input', calculateTotalCost);
        
        function calculateTotalCost() {
            const quantity = parseInt(document.getElementById('quantity_affected').value) || 0;
            const unitCost = <?php echo $expiry_item['unit_cost']; ?>;
            const totalCost = quantity * unitCost;
            
            // You could display this somewhere if needed
            console.log('Total cost for quantity:', totalCost);
        }
    </script>
</body>
</html>
