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
    
    $action_type = trim($_POST['action_type'] ?? '');
    $quantity_affected = trim($_POST['quantity_affected'] ?? '');
    $reason = trim($_POST['reason'] ?? '');
    $cost = trim($_POST['cost'] ?? '');
    $revenue = trim($_POST['revenue'] ?? '');
    $disposal_method = trim($_POST['disposal_method'] ?? '');
    $return_reference = trim($_POST['return_reference'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    
    // Debug: Log processed data
    error_log("Processed data - Action: $action_type, Quantity: $quantity_affected, Reason: $reason");
    
    // Validation
    $errors = [];
    
    if (empty($action_type)) {
        $errors[] = "Action type is required";
    }
    
    if (empty($quantity_affected)) {
        $errors[] = "Quantity affected is required";
    } elseif (!is_numeric($quantity_affected) || floatval($quantity_affected) <= 0) {
        $errors[] = "Quantity affected must be a positive number";
    } else {
        $quantity_affected = floatval($quantity_affected);
    }
    
    if ($quantity_affected > $expiry_item['remaining_quantity']) {
        $errors[] = "Quantity affected cannot exceed remaining quantity";
    }
    
    if (empty($reason)) {
        $errors[] = "Reason is required";
    }
    
    // Validate cost - allow empty but ensure it's numeric if provided
    if (!empty($cost)) {
        if (!is_numeric($cost) || floatval($cost) < 0) {
            $errors[] = "Cost must be a non-negative number";
        } else {
            $cost = floatval($cost);
        }
    } else {
        $cost = 0.00; // Set default value for empty input
    }
    
    // Validate revenue - allow empty but ensure it's numeric if provided
    if (!empty($revenue)) {
        if (!is_numeric($revenue) || floatval($revenue) < 0) {
            $errors[] = "Revenue must be a non-negative number";
        } else {
            $revenue = floatval($revenue);
        }
    } else {
        $revenue = 0.00; // Set default value for empty input
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
                // All items processed, update status based on action type
                $status = 'disposed'; // Default status
                switch ($action_type) {
                    case 'return':
                        $status = 'returned';
                        break;
                    case 'dispose':
                        $status = 'disposed';
                        break;
                    case 'sell_at_discount':
                    case 'donate':
                    case 'recall':
                    case 'other':
                        $status = 'disposed'; // These actions result in disposal
                        break;
                }
                
                $stmt = $conn->prepare("
                    UPDATE product_expiry_dates 
                    SET status = ?, remaining_quantity = 0, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$status, $expiry_id]);
                
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
            
            // Enhanced error handling with specific error messages
            $error_code = $e->getCode();
            $error_message = $e->getMessage();
            
            switch ($error_code) {
                case '1366': // Incorrect decimal value
                    $message = "Invalid numeric value provided. Please check cost and revenue fields.";
                    break;
                case '1452': // Foreign key constraint failure
                    $message = "Invalid reference data. Please check product or user selection.";
                    break;
                case '1062': // Duplicate entry
                    $message = "A record with this information already exists.";
                    break;
                default:
                    $message = "Database error occurred. Please try again or contact support.";
                    // Log the actual error for debugging
                    error_log("Handle Expiry Error: " . $error_message);
            }
            
            $message_type = "error";
            
            // Log detailed error for debugging
            error_log("Handle Expiry Error: " . $error_message);
            error_log("SQL State: " . $error_code);
            error_log("File: " . $e->getFile() . " Line: " . $e->getLine());
            
            // If this is an AJAX request, return JSON error
            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $message]);
                exit();
            }
        } catch (Exception $e) {
            $conn->rollBack();
            $message = "An unexpected error occurred. Please try again.";
            $message_type = "error";
            error_log("Unexpected error in handle expiry: " . $e->getMessage());
            
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
                                <option value="dispose" <?php echo (isset($_POST['action_type']) && $_POST['action_type'] === 'dispose') ? 'selected' : ''; ?> data-description="Permanently remove expired items from inventory. Requires disposal method and cost tracking.">Dispose</option>
                                <option value="return" <?php echo (isset($_POST['action_type']) && $_POST['action_type'] === 'return') ? 'selected' : ''; ?> data-description="Return items to supplier for credit or replacement. Requires return reference and cost tracking.">Return to Supplier</option>
                                <option value="sell_at_discount" <?php echo (isset($_POST['action_type']) && $_POST['action_type'] === 'sell_at_discount') ? 'selected' : ''; ?> data-description="Sell items at reduced price before expiry. Track revenue and any associated costs.">Sell at Discount</option>
                                <option value="donate" <?php echo (isset($_POST['action_type']) && $_POST['action_type'] === 'donate') ? 'selected' : ''; ?> data-description="Donate items to charity or organization. May have associated costs.">Donate</option>
                                <option value="recall" <?php echo (isset($_POST['action_type']) && $_POST['action_type'] === 'recall') ? 'selected' : ''; ?> data-description="Recall items due to safety or quality issues. Track costs and reasons.">Recall</option>
                                <option value="other" <?php echo (isset($_POST['action_type']) && $_POST['action_type'] === 'other') ? 'selected' : ''; ?> data-description="Other actions not covered by standard options. Provide detailed notes.">Other</option>
                            </select>
                            <small class="form-help">
                                <i class="fas fa-question-circle"></i> 
                                <span id="action-description">Select an action type to see detailed description</span>
                            </small>
                        </div>
                        
                        <div class="form-group">
                            <label>&nbsp;</label>
                            <div class="auto-generate-section">
                                <button type="button" id="auto-generate-btn" class="btn btn-outline-primary">
                                    <i class="fas fa-magic"></i> Auto-Generate Return
                                </button>
                                <small class="form-help">
                                    <i class="fas fa-lightbulb"></i> 
                                    Automatically fill all fields for return action
                                </small>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="quantity_affected">Quantity to Process *</label>
                            <div class="quantity-info">
                                <span class="remaining-quantity">Remaining: <strong><?php echo number_format($expiry_item['remaining_quantity']); ?></strong></span>
                                <span class="unit-info">Unit Cost: <strong>KES <?php echo number_format($expiry_item['unit_cost'], 2); ?></strong></span>
                            </div>
                            <input type="number" name="quantity_affected" id="quantity_affected" 
                                   value="<?php echo $_POST['quantity_affected'] ?? ''; ?>"
                                   min="1" max="<?php echo $expiry_item['remaining_quantity']; ?>" 
                                   step="1" required
                                   placeholder="Enter quantity to process">
                            <small class="form-help">
                                <i class="fas fa-info-circle"></i> 
                                Maximum: <?php echo number_format($expiry_item['remaining_quantity']); ?> units. 
                                Total value: KES <?php echo number_format($expiry_item['remaining_quantity'] * $expiry_item['unit_cost'], 2); ?>
                            </small>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="reason">Reason *</label>
                            <textarea name="reason" id="reason" rows="3" required
                                      placeholder="Explain why this action is being taken (e.g., 'Product expired on <?php echo date('M d, Y', strtotime($expiry_item['expiry_date'])); ?>', 'Quality issues detected', 'Safety concerns')"><?php echo htmlspecialchars($_POST['reason'] ?? ''); ?></textarea>
                            <small class="form-help">
                                <i class="fas fa-lightbulb"></i> 
                                Be specific about why this action is necessary. Include any relevant dates, quality issues, or safety concerns.
                            </small>
                        </div>
                        
                        <div class="form-group">
                            <label for="notes">Additional Notes</label>
                            <textarea name="notes" id="notes" rows="3"
                                      placeholder="Any additional information, special instructions, or details about this action"><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
                            <small class="form-help">
                                <i class="fas fa-sticky-note"></i> 
                                Include any special instructions, contact information, or additional details that might be helpful for tracking or future reference.
                            </small>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="cost">Cost (if applicable)</label>
                            <div class="cost-info">
                                <span class="unit-cost">Unit Cost: <strong>KES <?php echo number_format($expiry_item['unit_cost'], 2); ?></strong></span>
                                <span class="total-cost" id="total-cost-display">Total Cost: <strong>KES 0.00</strong></span>
                            </div>
                            <input type="number" name="cost" id="cost" 
                                   value="<?php echo htmlspecialchars($_POST['cost'] ?? ''); ?>"
                                   min="0" step="0.01" placeholder="0.00">
                            <small class="form-help">
                                <i class="fas fa-calculator"></i> 
                                Enter any additional costs (disposal fees, return shipping, etc.). 
                                <span class="cost-tip">Tip: This is separate from the product's unit cost</span>
                            </small>
                        </div>
                        
                        <div class="form-group">
                            <label for="revenue">Revenue (if applicable)</label>
                            <div class="revenue-info">
                                <span class="potential-revenue" id="potential-revenue-display">Potential Revenue: <strong>KES 0.00</strong></span>
                            </div>
                            <input type="number" name="revenue" id="revenue" 
                                   value="<?php echo htmlspecialchars($_POST['revenue'] ?? ''); ?>"
                                   min="0" step="0.01" placeholder="0.00">
                            <small class="form-help">
                                <i class="fas fa-dollar-sign"></i> 
                                Enter expected revenue from selling at discount or other actions. 
                                <span class="revenue-tip">Tip: This helps track financial impact of expiry actions</span>
                            </small>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="disposal_method">Disposal Method</label>
                            <select name="disposal_method" id="disposal_method">
                                <option value="">Select Method</option>
                                <option value="incineration" <?php echo (isset($_POST['disposal_method']) && $_POST['disposal_method'] === 'incineration') ? 'selected' : ''; ?> data-description="High-temperature burning for complete destruction. Suitable for hazardous materials.">Incineration</option>
                                <option value="landfill" <?php echo (isset($_POST['disposal_method']) && $_POST['disposal_method'] === 'landfill') ? 'selected' : ''; ?> data-description="Burial in designated waste disposal sites. Least environmentally friendly option.">Landfill</option>
                                <option value="recycling" <?php echo (isset($_POST['disposal_method']) && $_POST['disposal_method'] === 'recycling') ? 'selected' : ''; ?> data-description="Process materials for reuse. Most environmentally friendly option.">Recycling</option>
                                <option value="composting" <?php echo (isset($_POST['disposal_method']) && $_POST['disposal_method'] === 'composting') ? 'selected' : ''; ?> data-description="Organic decomposition for soil enrichment. Suitable for biodegradable items.">Composting</option>
                                <option value="other" <?php echo (isset($_POST['disposal_method']) && $_POST['disposal_method'] === 'other') ? 'selected' : ''; ?> data-description="Other disposal methods not listed above. Specify in notes.">Other</option>
                            </select>
                            <small class="form-help">
                                <i class="fas fa-leaf"></i> 
                                <span id="disposal-description">Select disposal method to see environmental impact</span>
                            </small>
                        </div>
                        
                        <div class="form-group">
                            <label for="return_reference">Return Reference</label>
                            <input type="text" name="return_reference" id="return_reference" 
                                   value="<?php echo htmlspecialchars($_POST['return_reference'] ?? ''); ?>"
                                   placeholder="Return authorization number (RMA, PO#, etc.)">
                            <small class="form-help">
                                <i class="fas fa-exchange-alt"></i> 
                                Enter the supplier's return authorization number, purchase order reference, or any tracking number for the return.
                            </small>
                        </div>
                    </div>

                    <!-- Financial Summary -->
                    <div class="financial-summary">
                        <div class="summary-header">
                            <h4><i class="fas fa-chart-line"></i> Financial Impact Summary</h4>
                        </div>
                        <div class="summary-content">
                            <div class="summary-row">
                                <div class="summary-item">
                                    <label>Product Value Lost:</label>
                                    <span class="value-lost" id="value-lost">KES 0.00</span>
                                </div>
                                <div class="summary-item">
                                    <label>Additional Costs:</label>
                                    <span class="additional-costs" id="additional-costs">KES 0.00</span>
                                </div>
                            </div>
                            <div class="summary-row">
                                <div class="summary-item">
                                    <label>Potential Revenue:</label>
                                    <span class="potential-revenue-summary" id="potential-revenue-summary">KES 0.00</span>
                                </div>
                                <div class="summary-item">
                                    <label>Net Impact:</label>
                                    <span class="net-impact" id="net-impact">KES 0.00</span>
                                </div>
                            </div>
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
            
            // Update action description
            updateActionDescription();
        });

        // Update action description based on selection
        function updateActionDescription() {
            const actionSelect = document.getElementById('action_type');
            const descriptionSpan = document.getElementById('action-description');
            const selectedOption = actionSelect.options[actionSelect.selectedIndex];
            
            if (selectedOption && selectedOption.dataset.description) {
                descriptionSpan.textContent = selectedOption.dataset.description;
            } else {
                descriptionSpan.textContent = 'Select an action type to see detailed description';
            }
        }

        // Update disposal method description
        document.getElementById('disposal_method').addEventListener('change', function() {
            const disposalSelect = this;
            const descriptionSpan = document.getElementById('disposal-description');
            const selectedOption = disposalSelect.options[disposalSelect.selectedIndex];
            
            if (selectedOption && selectedOption.dataset.description) {
                descriptionSpan.textContent = selectedOption.dataset.description;
            } else {
                descriptionSpan.textContent = 'Select disposal method to see environmental impact';
            }
        });
        
        // Validate quantity and update calculations
        document.getElementById('quantity_affected').addEventListener('input', function() {
            const maxQuantity = <?php echo $expiry_item['remaining_quantity']; ?>;
            const inputQuantity = parseInt(this.value) || 0;
            const unitCost = <?php echo $expiry_item['unit_cost']; ?>;
            
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
            
            // Update financial calculations
            updateFinancialSummary();
        });
        
        // Validate cost field
        document.getElementById('cost').addEventListener('blur', function() {
            const value = this.value.trim();
            if (value !== '' && (isNaN(value) || parseFloat(value) < 0)) {
                this.setCustomValidity('Cost must be a non-negative number');
                this.classList.add('error');
            } else {
                this.setCustomValidity('');
                this.classList.remove('error');
            }
        });

        // Validate revenue field
        document.getElementById('revenue').addEventListener('blur', function() {
            const value = this.value.trim();
            if (value !== '' && (isNaN(value) || parseFloat(value) < 0)) {
                this.setCustomValidity('Revenue must be a non-negative number');
                this.classList.add('error');
            } else {
                this.setCustomValidity('');
                this.classList.remove('error');
            }
        });

        // Handle empty cost/revenue - convert to 0.00 and update calculations
        document.getElementById('cost').addEventListener('input', function() {
            if (this.value === '') {
                this.value = '0.00';
            }
            updateFinancialSummary();
        });

        document.getElementById('revenue').addEventListener('input', function() {
            if (this.value === '') {
                this.value = '0.00';
            }
            updateFinancialSummary();
        });

        // Update financial summary calculations
        function updateFinancialSummary() {
            const quantity = parseInt(document.getElementById('quantity_affected').value) || 0;
            const unitCost = <?php echo $expiry_item['unit_cost']; ?>;
            const additionalCost = parseFloat(document.getElementById('cost').value) || 0;
            const revenue = parseFloat(document.getElementById('revenue').value) || 0;
            
            // Calculate values
            const valueLost = quantity * unitCost;
            const totalCost = valueLost + additionalCost;
            const netImpact = revenue - totalCost;
            
            // Update displays
            document.getElementById('value-lost').textContent = `KES ${valueLost.toFixed(2)}`;
            document.getElementById('additional-costs').textContent = `KES ${additionalCost.toFixed(2)}`;
            document.getElementById('potential-revenue-summary').textContent = `KES ${revenue.toFixed(2)}`;
            document.getElementById('net-impact').textContent = `KES ${netImpact.toFixed(2)}`;
            
            // Update cost info display
            document.getElementById('total-cost-display').textContent = `Total Cost: KES ${additionalCost.toFixed(2)}`;
            
            // Update potential revenue display
            document.getElementById('potential-revenue-display').textContent = `Potential Revenue: KES ${revenue.toFixed(2)}`;
            
            // Style net impact based on value
            const netImpactElement = document.getElementById('net-impact');
            if (netImpact > 0) {
                netImpactElement.className = 'net-impact positive';
            } else if (netImpact < 0) {
                netImpactElement.className = 'net-impact negative';
            } else {
                netImpactElement.className = 'net-impact neutral';
            }
        }

        // Auto-generate return functionality
        document.getElementById('auto-generate-btn').addEventListener('click', function() {
            // Check if all required fields can be auto-filled
            const canAutoFill = checkAutoFillAvailability();
            
            if (canAutoFill) {
                autoFillReturnFields();
                showToast('Return fields auto-filled successfully!', 'success');
            } else {
                if (confirm('Some fields are missing. Would you like to auto-fill the available fields and be prompted for the missing ones?')) {
                    autoFillReturnFields();
                    showToast('Available fields auto-filled. Please complete the missing fields.', 'info');
                }
            }
        });

        function checkAutoFillAvailability() {
            // Check if we have all the data needed for auto-fill
            const hasProductInfo = true; // We have product info from PHP
            const hasSupplierInfo = <?php echo $expiry_item['supplier_id'] ? 'true' : 'false'; ?>;
            const hasQuantity = true; // We have remaining quantity
            
            return hasProductInfo && hasSupplierInfo && hasQuantity;
        }

        function autoFillReturnFields() {
            // Set action type to return
            document.getElementById('action_type').value = 'return';
            updateActionDescription();
            
            // Set quantity to remaining quantity
            const remainingQuantity = <?php echo $expiry_item['remaining_quantity']; ?>;
            document.getElementById('quantity_affected').value = remainingQuantity;
            
            // Set reason
            const expiryDate = '<?php echo $expiry_item['expiry_date']; ?>';
            const productName = '<?php echo addslashes($expiry_item['product_name']); ?>';
            document.getElementById('reason').value = `Product ${productName} expired on ${expiryDate}. Returning to supplier for credit.`;
            
            // Set return reference (generate one)
            const returnRef = 'RMA-' + Date.now().toString().slice(-6);
            document.getElementById('return_reference').value = returnRef;
            
            // Set cost (unit cost * quantity)
            const unitCost = <?php echo $expiry_item['unit_cost']; ?>;
            const totalCost = unitCost * remainingQuantity;
            document.getElementById('cost').value = totalCost.toFixed(2);
            
            // Set notes
            document.getElementById('notes').value = `Auto-generated return for expired product. Batch: <?php echo $expiry_item['batch_number'] ?: 'N/A'; ?>, Location: <?php echo $expiry_item['location'] ?: 'N/A'; ?>`;
            
            // Show/hide relevant fields
            const disposalMethodGroup = document.querySelector('label[for="disposal_method"]').parentElement;
            const returnReferenceGroup = document.querySelector('label[for="return_reference"]').parentElement;
            const costGroup = document.querySelector('label[for="cost"]').parentElement;
            
            disposalMethodGroup.style.display = 'none';
            returnReferenceGroup.style.display = 'block';
            costGroup.style.display = 'block';
            
            // Update financial summary
            updateFinancialSummary();
        }

        function showToast(message, type = 'info') {
            // Create toast element
            const toast = document.createElement('div');
            toast.className = `toast align-items-center text-white bg-${type === 'success' ? 'success' : type === 'error' ? 'danger' : 'info'} border-0`;
            toast.setAttribute('role', 'alert');
            toast.innerHTML = `
                <div class="d-flex">
                    <div class="toast-body">${message}</div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            `;
            
            // Add to page
            document.body.appendChild(toast);
            
            // Show toast
            const bsToast = new bootstrap.Toast(toast);
            bsToast.show();
            
            // Remove after hidden
            toast.addEventListener('hidden.bs.toast', () => {
                toast.remove();
            });
        }

        // Form submission validation
        document.querySelector('.handle-expiry-form').addEventListener('submit', function(e) {
            let isValid = true;
            
            // Clear previous error states
            document.querySelectorAll('.form-group input, .form-group select, .form-group textarea').forEach(input => {
                input.classList.remove('error');
            });
            
            // Validate required fields
            const requiredFields = ['action_type', 'quantity_affected', 'reason'];
            requiredFields.forEach(fieldName => {
                const field = document.getElementById(fieldName);
                if (!field.value.trim()) {
                    field.classList.add('error');
                    isValid = false;
                }
            });
            
            // Validate numeric fields
            const quantityInput = document.getElementById('quantity_affected');
            const costInput = document.getElementById('cost');
            const revenueInput = document.getElementById('revenue');
            
            if (quantityInput.value && (isNaN(quantityInput.value) || parseFloat(quantityInput.value) <= 0)) {
                quantityInput.classList.add('error');
                isValid = false;
            }
            
            if (costInput.value && costInput.value !== '0.00' && (isNaN(costInput.value) || parseFloat(costInput.value) < 0)) {
                costInput.classList.add('error');
                isValid = false;
            }
            
            if (revenueInput.value && revenueInput.value !== '0.00' && (isNaN(revenueInput.value) || parseFloat(revenueInput.value) < 0)) {
                revenueInput.classList.add('error');
                isValid = false;
            }
            
            if (!isValid) {
                e.preventDefault();
                alert('Please fix the errors before submitting the form.');
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
