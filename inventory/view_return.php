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

// Check if user has permission to view returns
if (!in_array('manage_products', $permissions) && !in_array('process_sales', $permissions)) {
    header("Location: inventory.php?error=permission_denied");
    exit();
}

// Get return ID from URL
$return_id = isset($_GET['id']) ? trim($_GET['id']) : null;
if (!$return_id) {
    header("Location: view_returns.php?error=invalid_return");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Handle status updates
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'update_status':
            $new_status = $_POST['status'] ?? '';
            $valid_statuses = ['pending', 'approved', 'shipped', 'received', 'completed', 'cancelled'];
            $reason = $_POST['status_reason'] ?? '';

            if (in_array($new_status, $valid_statuses)) {
                try {
                    $conn->beginTransaction();

                    // Update return status
                    $stmt = $conn->prepare("
                        UPDATE returns
                        SET status = :status, updated_at = NOW()
                        WHERE id = :return_id
                    ");
                    $stmt->execute([
                        ':status' => $new_status,
                        ':return_id' => $return_id
                    ]);

                    // Add approval info if approving
                    if ($new_status === 'approved') {
                        $stmt = $conn->prepare("
                            UPDATE returns
                            SET approved_by = :approved_by, approved_at = NOW()
                            WHERE id = :return_id
                        ");
                        $stmt->execute([
                            ':approved_by' => $user_id,
                            ':return_id' => $return_id
                        ]);
                    }

                    // Set timestamps for other statuses
                    if ($new_status === 'shipped') {
                        $stmt = $conn->prepare("
                            UPDATE returns
                            SET shipped_at = NOW()
                            WHERE id = :return_id
                        ");
                        $stmt->execute([':return_id' => $return_id]);
                    } elseif ($new_status === 'completed') {
                        $stmt = $conn->prepare("
                            UPDATE returns
                            SET completed_at = NOW()
                            WHERE id = :return_id
                        ");
                        $stmt->execute([':return_id' => $return_id]);
                    }

                    // Log status change
                    logReturnStatusChange($conn, $return_id, $new_status, $user_id, $reason);

                    // If cancelling, restore inventory
                    if ($new_status === 'cancelled') {
                        restoreInventoryForReturn($conn, $return_id);
                    }

                    $conn->commit();

                    // Refresh return data
                    $return = getReturnData($conn, $return_id);
                    $message = "Return status updated successfully to " . ucfirst($new_status);
                    $message_type = 'success';

                } catch (PDOException $e) {
                    $conn->rollBack();
                    $message = "Error updating return status: " . $e->getMessage();
                    $message_type = 'danger';
                }
            }
            break;

        case 'update_tracking':
            $carrier = $_POST['shipping_carrier'] ?? '';
            $tracking = $_POST['tracking_number'] ?? '';

            try {
                $stmt = $conn->prepare("
                    UPDATE returns
                    SET shipping_carrier = :carrier,
                        tracking_number = :tracking,
                        updated_at = NOW()
                    WHERE id = :return_id
                ");
                $stmt->execute([
                    ':carrier' => $carrier,
                    ':tracking' => $tracking,
                    ':return_id' => $return_id
                ]);

                $return = getReturnData($conn, $return_id);
                $message = "Shipping information updated successfully";
                $message_type = 'success';
            } catch (PDOException $e) {
                $message = "Error updating shipping information: " . $e->getMessage();
                $message_type = 'danger';
            }
            break;

        case 'item_action':
            $item_id = $_POST['item_id'] ?? '';
            $item_action = $_POST['item_action'] ?? '';
            $action_quantity = intval($_POST['action_quantity'] ?? 0);
            $action_notes = trim($_POST['action_notes'] ?? '');

            if (!$item_id || !$item_action || $action_quantity <= 0) {
                $message = "Invalid item action parameters";
                $message_type = 'danger';
                break;
            }

            try {
                $conn->beginTransaction();

                // Get item details
                $stmt = $conn->prepare("
                    SELECT ri.*, p.name as product_name, p.quantity as current_stock
                    FROM return_items ri
                    JOIN products p ON ri.product_id = p.id
                    WHERE ri.id = :item_id AND ri.return_id = :return_id
                ");
                $stmt->execute([
                    ':item_id' => $item_id,
                    ':return_id' => $return_id
                ]);
                $item = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$item) {
                    throw new Exception("Item not found");
                }

                // Process the action
                switch ($item_action) {
                    case 'accept_partial':
                    case 'accept_all':
                        // Update return item with accepted quantity
                        $stmt = $conn->prepare("
                            UPDATE return_items 
                            SET accepted_quantity = :quantity,
                                action_taken = 'accepted',
                                action_notes = :notes,
                                updated_at = NOW()
                            WHERE id = :item_id
                        ");
                        $stmt->execute([
                            ':quantity' => $action_quantity,
                            ':notes' => $action_notes,
                            ':item_id' => $item_id
                        ]);

                        // Update inventory (reduce returned quantity)
                        $stmt = $conn->prepare("
                            UPDATE products 
                            SET quantity = quantity - :quantity
                            WHERE id = :product_id
                        ");
                        $stmt->execute([
                            ':quantity' => $action_quantity,
                            ':product_id' => $item['product_id']
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
                            ':notes' => $action_notes,
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
                            ':notes' => $action_notes,
                            ':item_id' => $item_id
                        ]);
                        break;
                }

                // Check if all items have been processed
                $stmt = $conn->prepare("
                    SELECT COUNT(*) as total, 
                           COUNT(CASE WHEN action_taken IS NOT NULL THEN 1 END) as processed
                    FROM return_items 
                    WHERE return_id = :return_id
                ");
                $stmt->execute([':return_id' => $return_id]);
                $item_stats = $stmt->fetch(PDO::FETCH_ASSOC);

                // If all items processed, update return status
                if ($item_stats['processed'] == $item_stats['total']) {
                    $stmt = $conn->prepare("
                        UPDATE returns 
                        SET status = 'processed', 
                            updated_at = NOW()
                        WHERE id = :return_id
                    ");
                    $stmt->execute([':return_id' => $return_id]);

                    // Log status change
                    logReturnStatusChange($conn, $return_id, 'processed', $user_id, 'All items processed');
                }

                $conn->commit();

                // Refresh return data
                $return = getReturnData($conn, $return_id);
                $message = "Item action processed successfully";
                $message_type = 'success';

            } catch (Exception $e) {
                $conn->rollBack();
                $message = "Error processing item action: " . $e->getMessage();
                $message_type = 'danger';
            }
            break;
    }
}

// Function to get return data
function getReturnData($conn, $return_id) {
    try {
        // Get return details
        $stmt = $conn->prepare("
            SELECT r.*,
                   s.name as supplier_name, s.contact_person, s.phone, s.email, s.address,
                   u.username as created_by_name,
                   COALESCE(au.username, 'System') as approved_by_name
            FROM returns r
            LEFT JOIN suppliers s ON r.supplier_id = s.id
            LEFT JOIN users u ON r.user_id = u.id
            LEFT JOIN users au ON r.approved_by = au.id
            WHERE r.id = :return_id
        ");
        $stmt->execute([':return_id' => $return_id]);
        $return = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$return) {
            return null;
        }

        // Get return items
        $stmt = $conn->prepare("
            SELECT ri.*,
                   p.name as product_name, p.sku, p.description, p.image_url,
                   c.name as category_name, b.name as brand_name
            FROM return_items ri
            LEFT JOIN products p ON ri.product_id = p.id
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN brands b ON p.brand_id = b.id
            WHERE ri.return_id = :return_id
            ORDER BY ri.id ASC
        ");
        $stmt->bindParam(':return_id', $return_id);
        $stmt->execute();
        $return['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get status history
        $stmt = $conn->prepare("
            SELECT rsh.*,
                   u.username as changed_by_name
            FROM return_status_history rsh
            LEFT JOIN users u ON rsh.changed_by = u.id
            WHERE rsh.return_id = :return_id
            ORDER BY rsh.created_at DESC
        ");
        $stmt->bindParam(':return_id', $return_id);
        $stmt->execute();
        $return['status_history'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $return;
    } catch (PDOException $e) {
        error_log("Error getting return data: " . $e->getMessage());
        return null;
    }
}

// Get return data
$return = getReturnData($conn, $return_id);
if (!$return) {
    header("Location: view_returns.php?error=return_not_found");
    exit();
}

// Helper functions
function logReturnStatusChange($conn, $return_id, $new_status, $changed_by, $reason = '') {
    try {
        // Get current status
        $stmt = $conn->prepare("SELECT status FROM returns WHERE id = :return_id");
        $stmt->execute([':return_id' => $return_id]);
        $old_status = $stmt->fetch(PDO::FETCH_ASSOC)['status'];

        // Insert status history
        $stmt = $conn->prepare("
            INSERT INTO return_status_history (return_id, old_status, new_status, changed_by, change_reason)
            VALUES (:return_id, :old_status, :new_status, :changed_by, :change_reason)
        ");
        $stmt->execute([
            ':return_id' => $return_id,
            ':old_status' => $old_status,
            ':new_status' => $new_status,
            ':changed_by' => $changed_by,
            ':change_reason' => $reason
        ]);
    } catch (PDOException $e) {
        error_log("Error logging return status change: " . $e->getMessage());
    }
}

function restoreInventoryForReturn($conn, $return_id) {
    try {
        // Get return items and restore inventory
        $stmt = $conn->prepare("
            SELECT product_id, quantity
            FROM return_items
            WHERE return_id = :return_id
        ");
        $stmt->execute([':return_id' => $return_id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($items as $item) {
            $stmt = $conn->prepare("
                UPDATE products
                SET quantity = quantity + :quantity,
                    updated_at = NOW()
                WHERE id = :product_id
            ");
            $stmt->execute([
                ':quantity' => $item['quantity'],
                ':product_id' => $item['product_id']
            ]);
        }
    } catch (PDOException $e) {
        error_log("Error restoring inventory: " . $e->getMessage());
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Return <?php echo htmlspecialchars($return['return_number']); ?> - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
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

        .return-header {
            background: linear-gradient(135deg, var(--primary-color), #8b5cf6);
            color: white;
            border-radius: 0.5rem;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .status-badge {
            padding: 0.375rem 0.75rem;
            border-radius: 0.375rem;
            font-size: 0.875rem;
            font-weight: 600;
        }

        .status-pending { background-color: #fff3cd; color: #856404; }
        .status-approved { background-color: #cce7ff; color: #0066cc; }
        .status-shipped { background-color: #e0e7ff; color: #3730a3; }
        .status-received { background-color: #d1edff; color: #0c5460; }
        .status-completed { background-color: #d1edff; color: #0c5460; }
        .status-cancelled { background-color: #f8d7da; color: #721c24; }

        .timeline {
            position: relative;
            padding: 1rem 0;
        }

        .timeline-item {
            position: relative;
            padding-left: 2.5rem;
            margin-bottom: 1rem;
        }

        .timeline-item:before {
            content: '';
            position: absolute;
            left: 0.75rem;
            top: 0.25rem;
            width: 0.75rem;
            height: 0.75rem;
            border-radius: 50%;
            background-color: var(--primary-color);
            border: 0.125rem solid white;
            box-shadow: 0 0 0 0.25rem rgba(99, 102, 241, 0.1);
        }

        .timeline-item:after {
            content: '';
            position: absolute;
            left: 1rem;
            top: 2rem;
            width: 0.125rem;
            height: calc(100% - 1rem);
            background-color: #e9ecef;
        }

        .timeline-item:last-child:after {
            display: none;
        }

        .product-image {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 0.375rem;
        }

        .action-buttons .btn {
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
        }

        .small {
            font-size: 0.875rem;
        }

        .form-label.small {
            font-size: 0.8rem;
            font-weight: 600;
            color: #6c757d;
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
                    <h2>Return <?php echo htmlspecialchars($return['return_number']); ?></h2>
                    <p class="header-subtitle small">View return details and manage return status</p>
                </div>
                <div class="header-actions">
                    <a href="print_return.php?id=<?php echo urlencode($return_id); ?>" class="btn btn-outline-secondary" target="_blank">
                        <i class="bi bi-printer me-2"></i>Print Return
                    </a>
                    <a href="create_return.php" class="btn btn-outline-primary">
                        <i class="bi bi-plus-circle me-2"></i>New Return
                    </a>
                    <a href="view_returns.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-2"></i>Back to Returns
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

            <!-- Return Header -->
            <div class="return-header">
                <div class="row">
                    <div class="col-md-6">
                        <h4 class="mb-1">Return <?php echo htmlspecialchars($return['return_number']); ?></h4>
                        <p class="mb-0 opacity-75 small">Created on <?php echo date('M j, Y g:i A', strtotime($return['created_at'])); ?></p>
                    </div>
                    <div class="col-md-6 text-end">
                        <span class="status-badge status-<?php echo $return['status']; ?>">
                            <?php echo ucfirst($return['status']); ?>
                        </span>
                    </div>
                </div>
            </div>

            <div class="row">
                <!-- Return Details -->
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>Return Details</h5>
                        </div>
                        <div class="card-body">
                            <div class="row return-details-row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold small">Supplier Information</label>
                                        <div class="fw-bold"><?php echo htmlspecialchars($return['supplier_name'] ?? 'N/A'); ?></div>
                                        <?php if ($return['contact_person']): ?>
                                        <small class="text-muted d-block">Contact: <?php echo htmlspecialchars($return['contact_person']); ?></small>
                                        <?php endif; ?>
                                        <?php if ($return['phone']): ?>
                                        <small class="text-muted d-block">Phone: <?php echo htmlspecialchars($return['phone']); ?></small>
                                        <?php endif; ?>
                                        <?php if ($return['email']): ?>
                                        <small class="text-muted d-block">Email: <?php echo htmlspecialchars($return['email']); ?></small>
                                        <?php endif; ?>
                                        <?php if ($return['address']): ?>
                                        <small class="text-muted d-block">Address: <?php echo nl2br(htmlspecialchars($return['address'])); ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3 company-details">
                                        <label class="form-label fw-semibold small">Company Details</label>
                                        <div class="fw-bold"><?php echo htmlspecialchars($settings['company_name'] ?? 'Liza Point Of Sale'); ?></div>
                                        <?php if ($settings['company_address']): ?>
                                        <small class="text-muted d-block"><?php echo nl2br(htmlspecialchars($settings['company_address'])); ?></small>
                                        <?php endif; ?>
                                        <?php if ($settings['company_phone']): ?>
                                        <small class="text-muted d-block">Phone: <?php echo htmlspecialchars($settings['company_phone']); ?></small>
                                        <?php endif; ?>
                                        <?php if ($settings['company_email']): ?>
                                        <small class="text-muted d-block">Email: <?php echo htmlspecialchars($settings['company_email']); ?></small>
                                        <?php endif; ?>
                                        <?php if ($settings['company_website']): ?>
                                        <small class="text-muted d-block">Website: <?php echo htmlspecialchars($settings['company_website']); ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="row mt-3">
                                <div class="col-12">
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold small">Return Information</label>
                                        <div class="row">
                                            <div class="col-md-4">
                                                <small class="text-muted">Return Reason:</small>
                                                <div><?php echo ucfirst(str_replace('_', ' ', $return['return_reason'])); ?></div>
                                            </div>
                                            <div class="col-md-4">
                                                <small class="text-muted">Created By:</small>
                                                <div><?php echo htmlspecialchars($return['created_by_name'] ?? 'System'); ?></div>
                                            </div>
                                            <div class="col-md-4">
                                                <small class="text-muted">Created:</small>
                                                <div><?php echo date('M j, Y g:i A', strtotime($return['created_at'])); ?></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <?php if ($return['approved_by_name'] && $return['approved_by_name'] !== 'System'): ?>
                            <div class="row mt-2">
                                <div class="col-md-6">
                                    <small class="text-muted">Approved By:</small>
                                    <div><?php echo htmlspecialchars($return['approved_by_name']); ?></div>
                                </div>
                                <div class="col-md-6">
                                    <small class="text-muted">Approved At:</small>
                                    <div><?php echo $return['approved_at'] ? date('M j, Y g:i A', strtotime($return['approved_at'])) : 'N/A'; ?></div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if ($return['return_notes']): ?>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Return Notes</label>
                                <div><?php echo nl2br(htmlspecialchars($return['return_notes'])); ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Return Items -->
                    <div class="card mt-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>Return Items</h5>
                            <span class="badge bg-primary"><?php echo count($return['items']); ?> items</span>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Product</th>
                                            <th>SKU</th>
                                            <th class="text-center">Quantity</th>
                                            <th class="text-end">Unit Cost</th>
                                            <th class="text-end">Total</th>
                                            <th class="text-center">Reason</th>
                                            <th class="text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($return['items'] as $item): ?>
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <?php if ($item['image_url']): ?>
                                                    <img src="<?php echo htmlspecialchars($item['image_url']); ?>"
                                                         alt="<?php echo htmlspecialchars($item['product_name']); ?>"
                                                         class="product-image me-3">
                                                    <?php endif; ?>
                                                    <div>
                                                        <div class="fw-semibold"><?php echo htmlspecialchars($item['product_name']); ?></div>
                                                        <?php if ($item['category_name']): ?>
                                                        <small class="text-muted"><?php echo htmlspecialchars($item['category_name']); ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($item['sku'] ?? 'N/A'); ?></td>
                                            <td class="text-center"><?php echo $item['quantity']; ?></td>
                                            <td class="text-end"><?php echo formatCurrency($item['cost_price'], $settings['currency_symbol'] ?? 'KES'); ?></td>
                                            <td class="text-end"><?php echo formatCurrency($item['quantity'] * $item['cost_price'], $settings['currency_symbol'] ?? 'KES'); ?></td>
                                            <td class="text-center">
                                                <small><?php echo htmlspecialchars($item['return_reason'] ?? 'N/A'); ?></small>
                                            </td>
                                            <td class="text-center">
                                                <?php if ($return['status'] === 'pending' || $return['status'] === 'approved'): ?>
                                                <button type="button" class="btn btn-sm btn-outline-primary" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#itemActionModal<?php echo $item['id']; ?>">
                                                    <i class="bi bi-gear"></i> Actions
                                                </button>
                                                <?php else: ?>
                                                <span class="badge bg-secondary">No Actions</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        
                                        <!-- Item Action Modal -->
                                        <div class="modal fade" id="itemActionModal<?php echo $item['id']; ?>" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Item Actions - <?php echo htmlspecialchars($item['product_name']); ?></h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <form method="POST">
                                                        <input type="hidden" name="action" value="item_action">
                                                        <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                                                        <div class="modal-body">
                                                            <div class="mb-3">
                                                                <label class="form-label">Action Type *</label>
                                                                <select class="form-control" name="item_action" required>
                                                                    <option value="">Select Action</option>
                                                                    <option value="accept_partial">Accept Partial</option>
                                                                    <option value="accept_all">Accept All</option>
                                                                    <option value="reject">Reject</option>
                                                                    <option value="exchange">Exchange</option>
                                                                </select>
                                                            </div>
                                                            
                                                            <div class="mb-3" id="quantityGroup<?php echo $item['id']; ?>">
                                                                <label class="form-label">Quantity to Process</label>
                                                                <input type="number" class="form-control" name="action_quantity" 
                                                                       min="1" max="<?php echo $item['quantity']; ?>" 
                                                                       value="<?php echo $item['quantity']; ?>">
                                                                <div class="form-text">Maximum: <?php echo $item['quantity']; ?> pieces</div>
                                                            </div>
                                                            
                                                            <div class="mb-3">
                                                                <label class="form-label">Action Notes</label>
                                                                <textarea class="form-control" name="action_notes" rows="3" 
                                                                          placeholder="Enter notes about this action..."></textarea>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" class="btn btn-primary">Process Action</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sidebar -->
                <div class="col-lg-4">
                    <!-- Return Summary -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-calculator me-2"></i>Return Summary</h5>
                        </div>
                        <div class="card-body">
                            <div class="row mb-2">
                                <div class="col-6">Total Items:</div>
                                <div class="col-6 text-end fw-semibold"><?php echo $return['total_items']; ?></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-6">Total Amount:</div>
                                <div class="col-6 text-end fw-semibold"><?php echo formatCurrency($return['total_amount'], $settings['currency_symbol'] ?? 'KES'); ?></div>
                            </div>
                            <?php if ($return['shipping_carrier']): ?>
                            <div class="row mb-2">
                                <div class="col-6">Carrier:</div>
                                <div class="col-6 text-end"><?php echo htmlspecialchars($return['shipping_carrier']); ?></div>
                            </div>
                            <?php endif; ?>
                            <?php if ($return['tracking_number']): ?>
                            <div class="row mb-3">
                                <div class="col-6">Tracking:</div>
                                <div class="col-6 text-end"><?php echo htmlspecialchars($return['tracking_number']); ?></div>
                            </div>
                            <?php endif; ?>
                            <hr>
                            <div class="row">
                                <div class="col-6 fw-semibold">Return Total:</div>
                                <div class="col-6 text-end fw-bold">
                                    <?php echo formatCurrency($return['total_amount'], $settings['currency_symbol'] ?? 'KES'); ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Status Management -->
                    <div class="card mt-4">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-gear me-2"></i>Status Management</h5>
                        </div>
                        <div class="card-body">
                            <div class="action-buttons">
                                <?php if ($return['status'] === 'pending'): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="status" value="approved">
                                    <button type="submit" class="btn btn-success">
                                        <i class="bi bi-check-circle me-2"></i>Approve Return
                                    </button>
                                </form>
                                <?php endif; ?>

                                <?php if ($return['status'] === 'approved'): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="status" value="shipped">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-truck me-2"></i>Mark as Shipped
                                    </button>
                                </form>
                                <?php endif; ?>

                                <?php if ($return['status'] === 'shipped'): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="status" value="received">
                                    <button type="submit" class="btn btn-info">
                                        <i class="bi bi-box-arrow-in-down me-2"></i>Mark as Received
                                    </button>
                                </form>
                                <?php endif; ?>

                                <?php if (in_array($return['status'], ['received', 'shipped'])): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="status" value="completed">
                                    <button type="submit" class="btn btn-success">
                                        <i class="bi bi-check-circle me-2"></i>Complete Return
                                    </button>
                                </form>
                                <?php endif; ?>

                                <?php if (in_array($return['status'], ['pending', 'approved'])): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="status" value="cancelled">
                                    <button type="submit" class="btn btn-danger" onclick="return confirm('Are you sure you want to cancel this return? This will restore inventory.')">
                                        <i class="bi bi-x-circle me-2"></i>Cancel Return
                                    </button>
                                </form>
                                <?php endif; ?>

                                <!-- Shipping Information Form -->
                                <?php if (in_array($return['status'], ['approved', 'shipped'])): ?>
                                <div class="mt-3">
                                    <button type="button" class="btn btn-outline-secondary btn-sm w-100" data-bs-toggle="collapse" data-bs-target="#shippingForm">
                                        <i class="bi bi-truck me-2"></i>Update Shipping Info
                                    </button>
                                    <div class="collapse mt-2" id="shippingForm">
                                        <form method="POST">
                                            <input type="hidden" name="action" value="update_tracking">
                                            <div class="mb-2">
                                                <input type="text" class="form-control form-control-sm"
                                                       name="shipping_carrier" placeholder="Carrier"
                                                       value="<?php echo htmlspecialchars($return['shipping_carrier'] ?? ''); ?>">
                                            </div>
                                            <div class="mb-2">
                                                <input type="text" class="form-control form-control-sm"
                                                       name="tracking_number" placeholder="Tracking Number"
                                                       value="<?php echo htmlspecialchars($return['tracking_number'] ?? ''); ?>">
                                            </div>
                                            <button type="submit" class="btn btn-primary btn-sm">Update</button>
                                        </form>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Return Timeline -->
                    <div class="card mt-4">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Return Timeline</h5>
                        </div>
                        <div class="card-body">
                            <div class="timeline">
                                <div class="timeline-item">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <div class="fw-semibold">Return Created</div>
                                            <small class="text-muted"><?php echo htmlspecialchars($return['created_by_name'] ?? 'System'); ?></small>
                                        </div>
                                        <small class="text-muted"><?php echo date('M j, g:i A', strtotime($return['created_at'])); ?></small>
                                    </div>
                                </div>

                                <?php if ($return['approved_at']): ?>
                                <div class="timeline-item">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <div class="fw-semibold">Return Approved</div>
                                            <small class="text-muted"><?php echo htmlspecialchars($return['approved_by_name'] ?? 'System'); ?></small>
                                        </div>
                                        <small class="text-muted"><?php echo date('M j, g:i A', strtotime($return['approved_at'])); ?></small>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <?php if ($return['shipped_at']): ?>
                                <div class="timeline-item">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <div class="fw-semibold">Return Shipped</div>
                                            <small class="text-muted">System</small>
                                        </div>
                                        <small class="text-muted"><?php echo date('M j, g:i A', strtotime($return['shipped_at'])); ?></small>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <?php if ($return['completed_at']): ?>
                                <div class="timeline-item">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <div class="fw-semibold">Return Completed</div>
                                            <small class="text-muted">System</small>
                                        </div>
                                        <small class="text-muted"><?php echo date('M j, g:i A', strtotime($return['completed_at'])); ?></small>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <?php foreach ($return['status_history'] as $history): ?>
                                <div class="timeline-item">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <div class="fw-semibold">Status changed to <?php echo ucfirst($history['new_status']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($history['changed_by_name'] ?? 'System'); ?></small>
                                            <?php if ($history['change_reason']): ?>
                                            <div class="small text-muted"><?php echo htmlspecialchars($history['change_reason']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                        <small class="text-muted"><?php echo date('M j, g:i A', strtotime($history['created_at'])); ?></small>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);

        // Item Action Modal functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Handle action type changes
            document.querySelectorAll('select[name="item_action"]').forEach(select => {
                select.addEventListener('change', function() {
                    const modal = this.closest('.modal');
                    const quantityGroup = modal.querySelector('[id^="quantityGroup"]');
                    const quantityInput = modal.querySelector('input[name="action_quantity"]');
                    const action = this.value;
                    
                    if (action === 'reject') {
                        quantityInput.value = '0';
                        quantityInput.disabled = true;
                        quantityGroup.style.display = 'none';
                    } else {
                        quantityInput.disabled = false;
                        quantityGroup.style.display = 'block';
                        
                        // Reset to max quantity for accept actions
                        if (action === 'accept_all') {
                            quantityInput.value = quantityInput.getAttribute('max');
                        }
                    }
                });
            });

            // Form validation for item actions
            document.querySelectorAll('form[action="item_action"]').forEach(form => {
                form.addEventListener('submit', function(e) {
                    const actionSelect = this.querySelector('select[name="item_action"]');
                    const quantityInput = this.querySelector('input[name="action_quantity"]');
                    const notesInput = this.querySelector('textarea[name="action_notes"]');
                    
                    if (!actionSelect.value) {
                        e.preventDefault();
                        alert('Please select an action type.');
                        actionSelect.focus();
                        return;
                    }
                    
                    if (actionSelect.value !== 'reject' && (!quantityInput.value || quantityInput.value <= 0)) {
                        e.preventDefault();
                        alert('Please enter a valid quantity.');
                        quantityInput.focus();
                        return;
                    }
                    
                    // Confirm action
                    const action = actionSelect.value;
                    const quantity = quantityInput.value;
                    const productName = this.closest('.modal').querySelector('.modal-title').textContent.replace('Item Actions - ', '');
                    
                    let confirmMessage = `Are you sure you want to ${action.replace('_', ' ')} `;
                    if (action === 'accept_partial' || action === 'accept_all') {
                        confirmMessage += `${quantity} pieces of ${productName}?`;
                    } else {
                        confirmMessage += `${productName}?`;
                    }
                    
                    if (!confirm(confirmMessage)) {
                        e.preventDefault();
                        return;
                    }
                });
            });

            // Auto-fill notes based on action
            document.querySelectorAll('select[name="item_action"]').forEach(select => {
                select.addEventListener('change', function() {
                    const modal = this.closest('.modal');
                    const notesInput = modal.querySelector('textarea[name="action_notes"]');
                    const action = this.value;
                    
                    if (!notesInput.value.trim()) {
                        switch(action) {
                            case 'accept_partial':
                                notesInput.value = 'Partially accepted return items';
                                break;
                            case 'accept_all':
                                notesInput.value = 'All return items accepted';
                                break;
                            case 'reject':
                                notesInput.value = 'Return items rejected';
                                break;
                            case 'exchange':
                                notesInput.value = 'Return items marked for exchange';
                                break;
                        }
                    }
                });
            });
        });
    </script>
</body>
</html>
