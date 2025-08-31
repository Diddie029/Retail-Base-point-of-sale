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

// Check if user has permission to view expiry alerts
if (!in_array('view_expiry_alerts', $permissions) && !in_array('manage_expiry_tracker', $permissions)) {
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
            p.description as product_description,
            p.price,
            p.cost_price,
            c.name as category_name,
            s.name as supplier_name,
            s.email as supplier_email,
            s.phone as supplier_phone,
            ec.category_name as expiry_category_name,
            ec.color_code as expiry_color,
            ec.alert_threshold_days
        FROM product_expiry_dates ped
        JOIN products p ON ped.product_id = p.id
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN suppliers s ON ped.supplier_id = s.id
        LEFT JOIN expiry_categories ec ON p.expiry_category_id = ec.id
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

// Get expiry actions history
$actions_history = [];
try {
    $stmt = $conn->prepare("
        SELECT 
            ea.*,
            u.username as user_name
        FROM expiry_actions ea
        JOIN users u ON ea.user_id = u.id
        WHERE ea.product_expiry_id = ?
        ORDER BY ea.action_date DESC
    ");
    $stmt->execute([$expiry_id]);
    $actions_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Actions might not exist yet
}

// Calculate days until expiry
$days_until_expiry = (strtotime($expiry_item['expiry_date']) - time()) / (60 * 60 * 24);
$is_expired = $days_until_expiry < 0;
$is_critical = $days_until_expiry <= 7 && !$is_expired;

// Check if this is a success redirect
$show_success = isset($_GET['success']);

$page_title = "View Expiry Item";
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
            <h1><i class="fas fa-eye"></i> <?php echo $page_title; ?></h1>
            <div class="header-actions">
                <?php if (in_array('handle_expired_items', $permissions) && $expiry_item['status'] === 'active'): ?>
                    <a href="handle_expiry.php?id=<?php echo $expiry_id; ?>" class="btn btn-warning">
                        <i class="fas fa-tools"></i> Handle Expiry
                    </a>
                <?php endif; ?>
                <?php if (in_array('manage_expiry_tracker', $permissions)): ?>
                    <a href="edit_expiry_date.php?id=<?php echo $expiry_id; ?>" class="btn btn-primary">
                        <i class="fas fa-edit"></i> Edit
                    </a>
                <?php endif; ?>
                <a href="expiry_tracker.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Tracker
                </a>
            </div>
        </div>

        <?php if ($show_success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> Expiry date added successfully! You can now view the details below.
            </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['success']) && $_GET['success'] === 'updated'): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> Expiry date updated successfully! The changes are reflected below.
            </div>
        <?php endif; ?>

        <!-- Item Details -->
        <div class="item-details">
            <div class="detail-card">
                <div class="detail-header">
                    <h3>Product Information</h3>
                </div>
                <div class="detail-content">
                    <div class="product-overview">
                        <?php if ($expiry_item['image_url']): ?>
                            <img src="<?php echo htmlspecialchars($expiry_item['image_url']); ?>" 
                                 alt="<?php echo htmlspecialchars($expiry_item['product_name']); ?>" 
                                 class="product-image-large">
                        <?php endif; ?>
                        <div class="product-basic-info">
                            <h2><?php echo htmlspecialchars($expiry_item['product_name']); ?></h2>
                            <p class="product-sku">SKU: <?php echo htmlspecialchars($expiry_item['sku']); ?></p>
                            <p class="product-category">Category: <?php echo htmlspecialchars($expiry_item['category_name']); ?></p>
                            <?php if ($expiry_item['product_description']): ?>
                                <p class="product-description"><?php echo htmlspecialchars($expiry_item['product_description']); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="detail-row">
                        <div class="detail-group">
                            <label>Batch Number:</label>
                            <span><?php echo htmlspecialchars($expiry_item['batch_number'] ?: 'N/A'); ?></span>
                        </div>
                        <div class="detail-group">
                            <label>Storage Location:</label>
                            <span><?php echo htmlspecialchars($expiry_item['location'] ?: 'N/A'); ?></span>
                        </div>
                    </div>
                    
                    <div class="detail-row">
                        <div class="detail-group">
                            <label>Manufacturing Date:</label>
                            <span><?php echo $expiry_item['manufacturing_date'] ? date('M d, Y', strtotime($expiry_item['manufacturing_date'])) : 'N/A'; ?></span>
                        </div>
                        <div class="detail-group">
                            <label>Expiry Date:</label>
                            <span class="expiry-date <?php echo $is_critical ? 'critical' : ($is_expired ? 'expired' : ''); ?>">
                                <?php echo date('M d, Y', strtotime($expiry_item['expiry_date'])); ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="detail-row">
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
                        <div class="detail-group">
                            <label>Status:</label>
                            <span class="status-badge status-<?php echo $expiry_item['status']; ?>">
                                <?php echo ucfirst($expiry_item['status']); ?>
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
                    
                    <?php if ($expiry_item['expiry_category_name']): ?>
                    <div class="detail-row">
                        <div class="detail-group">
                            <label>Expiry Category:</label>
                            <span class="expiry-category" style="background-color: <?php echo $expiry_item['expiry_color']; ?>">
                                <?php echo htmlspecialchars($expiry_item['expiry_category_name']); ?>
                                (<?php echo $expiry_item['alert_threshold_days']; ?> days threshold)
                            </span>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Supplier Information -->
        <?php if ($expiry_item['supplier_name']): ?>
        <div class="supplier-info">
            <div class="detail-card">
                <div class="detail-header">
                    <h3>Supplier Information</h3>
                </div>
                <div class="detail-content">
                    <div class="detail-row">
                        <div class="detail-group">
                            <label>Supplier Name:</label>
                            <span><?php echo htmlspecialchars($expiry_item['supplier_name']); ?></span>
                        </div>
                        <div class="detail-group">
                            <label>Purchase Order ID:</label>
                            <span><?php echo htmlspecialchars($expiry_item['purchase_order_id'] ?: 'N/A'); ?></span>
                        </div>
                    </div>
                    
                    <?php if ($expiry_item['supplier_email'] || $expiry_item['supplier_phone']): ?>
                    <div class="detail-row">
                        <div class="detail-group">
                            <label>Contact Email:</label>
                            <span><?php echo htmlspecialchars($expiry_item['supplier_email'] ?: 'N/A'); ?></span>
                        </div>
                        <div class="detail-group">
                            <label>Contact Phone:</label>
                            <span><?php echo htmlspecialchars($expiry_item['supplier_phone'] ?: 'N/A'); ?></span>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Actions History -->
        <?php if (!empty($actions_history)): ?>
        <div class="actions-history">
            <div class="detail-card">
                <div class="detail-header">
                    <h3>Actions History</h3>
                </div>
                <div class="detail-content">
                    <div class="actions-timeline">
                        <?php foreach ($actions_history as $action): ?>
                            <div class="action-item">
                                <div class="action-header">
                                    <span class="action-type action-<?php echo $action['action_type']; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $action['action_type'])); ?>
                                    </span>
                                    <span class="action-date">
                                        <?php echo date('M d, Y H:i', strtotime($action['action_date'])); ?>
                                    </span>
                                </div>
                                <div class="action-details">
                                    <p><strong>Quantity:</strong> <?php echo number_format($action['quantity_affected']); ?></p>
                                    <p><strong>Reason:</strong> <?php echo htmlspecialchars($action['reason']); ?></p>
                                    <?php if ($action['notes']): ?>
                                        <p><strong>Notes:</strong> <?php echo htmlspecialchars($action['notes']); ?></p>
                                    <?php endif; ?>
                                    <?php if ($action['cost'] > 0): ?>
                                        <p><strong>Cost:</strong> KES <?php echo number_format($action['cost'], 2); ?></p>
                                    <?php endif; ?>
                                    <?php if ($action['revenue'] > 0): ?>
                                        <p><strong>Revenue:</strong> KES <?php echo number_format($action['revenue'], 2); ?></p>
                                    <?php endif; ?>
                                    <p><strong>Action by:</strong> <?php echo htmlspecialchars($action['user_name']); ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Notes -->
        <?php if ($expiry_item['notes']): ?>
        <div class="notes-section">
            <div class="detail-card">
                <div class="detail-header">
                    <h3>Notes</h3>
                </div>
                <div class="detail-content">
                    <p><?php echo nl2br(htmlspecialchars($expiry_item['notes'])); ?></p>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <style>
        .product-overview {
            display: flex;
            gap: 20px;
            margin-bottom: 30px;
            align-items: flex-start;
        }
        
        .product-image-large {
            width: 120px;
            height: 120px;
            border-radius: 10px;
            object-fit: cover;
            border: 2px solid var(--border-color);
        }
        
        .product-basic-info h2 {
            margin: 0 0 10px 0;
            color: var(--text-color);
            font-size: 1.5rem;
        }
        
        .product-sku {
            color: var(--text-muted);
            font-size: 14px;
            margin: 5px 0;
        }
        
        .product-category {
            color: var(--primary-color);
            font-weight: 500;
            margin: 5px 0;
        }
        
        .product-description {
            color: var(--text-muted);
            font-size: 14px;
            margin: 10px 0;
            line-height: 1.5;
        }
        
        .supplier-info,
        .actions-history,
        .notes-section {
            margin-bottom: 30px;
        }
        
        .actions-timeline {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        
        .action-item {
            border: 1px solid var(--border-color);
            border-radius: 8px;
            overflow: hidden;
        }
        
        .action-header {
            background: var(--light-gray);
            padding: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .action-type {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .action-dispose {
            background-color: #fee2e2;
            color: #991b1b;
        }
        
        .action-return {
            background-color: #dbeafe;
            color: #1e40af;
        }
        
        .action-sell_at_discount {
            background-color: #dcfce7;
            color: #166534;
        }
        
        .action-donate {
            background-color: #fef3c7;
            color: #92400e;
        }
        
        .action-recall {
            background-color: #fecaca;
            color: #7f1d1d;
        }
        
        .action-other {
            background-color: #e2e8f0;
            color: #475569;
        }
        
        .action-date {
            font-size: 12px;
            color: var(--text-muted);
        }
        
        .action-details {
            padding: 15px;
        }
        
        .action-details p {
            margin: 5px 0;
            font-size: 14px;
        }
        
        .action-details strong {
            color: var(--text-color);
        }
        
        @media (max-width: 768px) {
            .product-overview {
                flex-direction: column;
                text-align: center;
            }
            
            .product-image-large {
                width: 100px;
                height: 100px;
                margin: 0 auto;
            }
            
            .action-header {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }
        }
    </style>
</body>
</html>
