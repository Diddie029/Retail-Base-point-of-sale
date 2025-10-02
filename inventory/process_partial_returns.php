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

// Check if user has permission to manage returns
if (!in_array('manage_products', $permissions) && !in_array('process_sales', $permissions)) {
    header("Location: view_returns.php?error=permission_denied");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get return IDs from URL
$return_ids = [];
if (isset($_GET['return_ids'])) {
    $return_ids = explode(',', $_GET['return_ids']);
    $return_ids = array_map('intval', $return_ids);
    $return_ids = array_filter($return_ids);
}

if (empty($return_ids)) {
    header("Location: view_returns.php?error=no_returns_selected");
    exit();
}

// Get return details
$returns = [];
try {
    $placeholders = str_repeat('?,', count($return_ids) - 1) . '?';
    $stmt = $conn->prepare("
        SELECT r.*, s.name as supplier_name, u.username as created_by_name
        FROM returns r
        LEFT JOIN suppliers s ON r.supplier_id = s.id
        LEFT JOIN users u ON r.user_id = u.id
        WHERE r.id IN ($placeholders)
        ORDER BY r.created_at DESC
    ");
    $stmt->execute($return_ids);
    $returns = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    header("Location: view_returns.php?error=db_error");
    exit();
}

// Handle form submission
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->beginTransaction();
        
        foreach ($_POST['return_items'] as $return_id => $items) {
            foreach ($items as $item_id => $item_data) {
                $action = $item_data['action'];
                $quantity = intval($item_data['quantity']);
                $reason = trim($item_data['reason']);
                $notes = trim($item_data['notes']);
                
                if ($quantity <= 0) continue;
                
                switch ($action) {
                    case 'accept_partial':
                        // Update return item quantity and mark as partially accepted
                        $stmt = $conn->prepare("
                            UPDATE return_items 
                            SET accepted_quantity = :quantity, 
                                action_taken = 'partial_accept',
                                action_notes = :notes,
                                updated_at = NOW()
                            WHERE id = :item_id
                        ");
                        $stmt->execute([
                            ':quantity' => $quantity,
                            ':notes' => $notes,
                            ':item_id' => $item_id
                        ]);
                        
                        // Update inventory (reduce returned quantity)
                        $stmt = $conn->prepare("
                            UPDATE products p
                            JOIN return_items ri ON p.id = ri.product_id
                            SET p.quantity = p.quantity - :quantity
                            WHERE ri.id = :item_id
                        ");
                        $stmt->execute([
                            ':quantity' => $quantity,
                            ':item_id' => $item_id
                        ]);
                        break;
                        
                    case 'reject':
                        // Mark item as rejected
                        $stmt = $conn->prepare("
                            UPDATE return_items 
                            SET action_taken = 'rejected',
                                action_notes = :notes,
                                updated_at = NOW()
                            WHERE id = :item_id
                        ");
                        $stmt->execute([
                            ':notes' => $notes,
                            ':item_id' => $item_id
                        ]);
                        break;
                        
                    case 'exchange':
                        // Mark item for exchange
                        $stmt = $conn->prepare("
                            UPDATE return_items 
                            SET action_taken = 'exchange',
                                action_notes = :notes,
                                updated_at = NOW()
                            WHERE id = :item_id
                        ");
                        $stmt->execute([
                            ':notes' => $notes,
                            ':item_id' => $item_id
                        ]);
                        break;
                }
            }
            
            // Update return status if all items processed
            $stmt = $conn->prepare("
                SELECT COUNT(*) as total, 
                       COUNT(CASE WHEN action_taken IS NOT NULL THEN 1 END) as processed
                FROM return_items 
                WHERE return_id = :return_id
            ");
            $stmt->execute([':return_id' => $return_id]);
            $item_stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($item_stats['processed'] == $item_stats['total']) {
                $stmt = $conn->prepare("
                    UPDATE returns 
                    SET status = 'processed', 
                        updated_at = NOW()
                    WHERE id = :return_id
                ");
                $stmt->execute([':return_id' => $return_id]);
                
                // Log status change
                logReturnStatusChange($conn, $return_id, 'processed', $user_id, 'Partial return processing completed');
            }
        }
        
        $conn->commit();
        $message = "Partial returns processed successfully!";
        $message_type = 'success';
        
    } catch (PDOException $e) {
        $conn->rollBack();
        $message = "Error processing partial returns: " . $e->getMessage();
        $message_type = 'danger';
    }
}

$page_title = "Process Partial Returns";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    
    <!-- Custom CSS -->
    <link href="../assets/css/dashboard.css" rel="stylesheet">
    
    <style>
        .return-card {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            overflow: hidden;
        }
        
        .return-header {
            background: #f8fafc;
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .return-title {
            font-weight: 600;
            color: #1e293b;
        }
        
        .item-row {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            background: #fff;
        }
        
        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        
        .btn-action {
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
            border-radius: 6px;
        }
    </style>
</head>
<body>
    <?php include '../include/navmenu.php'; ?>

    <div class="main-content">
        <div class="container-fluid">
            <!-- Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h3 mb-0">
                        <i class="bi bi-arrow-left-right text-warning me-2"></i>
                        Process Partial Returns
                    </h1>
                    <p class="text-muted">Handle partial returns and item-specific actions</p>
                </div>
                <div>
                    <a href="view_returns.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-2"></i>Back to Returns
                    </a>
                </div>
            </div>

            <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show">
                <i class="bi bi-<?php echo $message_type == 'success' ? 'check-circle' : 'exclamation-circle'; ?> me-2"></i>
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <form method="POST" id="partialReturnsForm">
                <?php foreach ($returns as $return): ?>
                <div class="return-card">
                    <div class="return-header">
                        <div class="row align-items-center">
                            <div class="col-md-6">
                                <h5 class="mb-0"><?php echo htmlspecialchars($return['return_number']); ?></h5>
                                <small class="text-muted">
                                    Supplier: <?php echo htmlspecialchars($return['supplier_name'] ?? 'N/A'); ?> | 
                                    Created: <?php echo date('M j, Y', strtotime($return['created_at'])); ?>
                                </small>
                            </div>
                            <div class="col-md-6 text-end">
                                <span class="badge bg-<?php echo $return['status'] == 'pending' ? 'warning' : 'info'; ?> fs-6">
                                    <?php echo ucfirst($return['status']); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card-body">
                        <?php
                        // Get return items
                        $stmt = $conn->prepare("
                            SELECT ri.*, p.name as product_name, p.sku, p.image_url
                            FROM return_items ri
                            JOIN products p ON ri.product_id = p.id
                            WHERE ri.return_id = :return_id
                            ORDER BY ri.id ASC
                        ");
                        $stmt->execute([':return_id' => $return['id']]);
                        $return_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        ?>
                        
                        <?php foreach ($return_items as $item): ?>
                        <div class="item-row">
                            <div class="row align-items-center">
                                <div class="col-md-3">
                                    <div class="d-flex align-items-center">
                                        <?php if ($item['image_url']): ?>
                                        <img src="../<?php echo htmlspecialchars($item['image_url']); ?>" 
                                             alt="Product" class="img-thumbnail me-2" style="width: 50px; height: 50px;">
                                        <?php endif; ?>
                                        <div>
                                            <h6 class="mb-1"><?php echo htmlspecialchars($item['product_name']); ?></h6>
                                            <small class="text-muted">SKU: <?php echo htmlspecialchars($item['sku']); ?></small>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-md-2">
                                    <div class="text-center">
                                        <strong>Return Qty:</strong><br>
                                        <span class="badge bg-warning fs-6"><?php echo $item['quantity']; ?></span>
                                    </div>
                                </div>
                                
                                <div class="col-md-2">
                                    <label class="form-label">Action Quantity</label>
                                    <input type="number" class="form-control" 
                                           name="return_items[<?php echo $return['id']; ?>][<?php echo $item['id']; ?>][quantity]"
                                           min="0" max="<?php echo $item['quantity']; ?>" 
                                           value="<?php echo $item['quantity']; ?>" required>
                                </div>
                                
                                <div class="col-md-2">
                                    <label class="form-label">Action</label>
                                    <select class="form-control" 
                                            name="return_items[<?php echo $return['id']; ?>][<?php echo $item['id']; ?>][action]"
                                            required>
                                        <option value="">Select Action</option>
                                        <option value="accept_partial">Accept Partial</option>
                                        <option value="reject">Reject</option>
                                        <option value="exchange">Exchange</option>
                                    </select>
                                </div>
                                
                                <div class="col-md-3">
                                    <label class="form-label">Notes</label>
                                    <textarea class="form-control" rows="2" 
                                              name="return_items[<?php echo $return['id']; ?>][<?php echo $item['id']; ?>][notes]"
                                              placeholder="Action notes..."></textarea>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>

                <div class="text-center mt-4">
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="bi bi-check-circle me-2"></i>
                        Process Partial Returns
                    </button>
                    <a href="view_returns.php" class="btn btn-outline-secondary btn-lg ms-2">
                        <i class="bi bi-x-circle me-2"></i>
                        Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Custom JS -->
    <script src="../assets/js/dashboard.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Form validation
            const form = document.getElementById('partialReturnsForm');
            form.addEventListener('submit', function(e) {
                const requiredFields = form.querySelectorAll('select[required], input[required]');
                let isValid = true;
                
                requiredFields.forEach(field => {
                    if (!field.value.trim()) {
                        field.classList.add('is-invalid');
                        isValid = false;
                    } else {
                        field.classList.remove('is-invalid');
                    }
                });
                
                if (!isValid) {
                    e.preventDefault();
                    alert('Please fill in all required fields.');
                }
            });
            
            // Auto-update quantity when action changes
            document.querySelectorAll('select[name*="[action]"]').forEach(select => {
                select.addEventListener('change', function() {
                    const row = this.closest('.item-row');
                    const quantityInput = row.querySelector('input[name*="[quantity]"]');
                    const action = this.value;
                    
                    if (action === 'reject') {
                        quantityInput.value = '0';
                        quantityInput.disabled = true;
                    } else {
                        quantityInput.disabled = false;
                        if (quantityInput.value === '0') {
                            const maxQuantity = quantityInput.getAttribute('max');
                            quantityInput.value = maxQuantity;
                        }
                    }
                });
            });
        });
    </script>
</body>
</html>
