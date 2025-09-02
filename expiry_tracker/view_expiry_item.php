<?php
session_start();
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
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

// Check permissions
if (!hasPermission('view_expiry_alerts', $permissions) && !hasPermission('manage_expiry_tracker', $permissions)) {
    header("Location: ../dashboard/dashboard.php");
    exit();
}

// Get expiry item ID
$expiry_id = isset($_GET['id']) ? trim($_GET['id']) : null;
if (!$expiry_id) {
    header("Location: expiry_tracker.php?error=invalid_item");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
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
    <title><?php echo htmlspecialchars($expiry_item['product_name']); ?> - Expiry Details - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --primary-rgb: <?php echo implode(',', sscanf($settings['theme_color'] ?? '#6366f1', '#%02x%02x%02x')); ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
            --sidebar-rgb: <?php echo implode(',', sscanf($settings['sidebar_color'] ?? '#1e293b', '#%02x%02x%02x')); ?>;
        }
        
        .expiry-header {
            background: linear-gradient(135deg, <?php echo $is_expired ? '#dc2626' : ($is_critical ? '#f59e0b' : 'var(--primary-color)'); ?>, <?php echo $is_expired ? '#b91c1c' : ($is_critical ? '#d97706' : '#8b5cf6'); ?>);
            color: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
        }
        
        .expiry-header::before {
            content: '';
            position: absolute;
            top: -50px;
            right: -50px;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
        }
        
        .expiry-icon {
            width: 80px;
            height: 80px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            margin-bottom: 1rem;
        }
        
        .info-card {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            margin-bottom: 2rem;
            border: 1px solid #e2e8f0;
        }
        
        .info-card:hover {
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        
        .stat-box {
            text-align: center;
            padding: 1.5rem;
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 12px;
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: white;
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            color: rgba(255, 255, 255, 0.8);
            font-weight: 500;
        }
        
        .detail-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
            margin-bottom: 1.5rem;
        }
        
        .detail-group {
            display: flex;
            flex-direction: column;
        }
        
        .detail-group label {
            font-weight: 600;
            color: #64748b;
            margin-bottom: 0.5rem;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .detail-group span {
            font-weight: 500;
            color: #1e293b;
            padding: 0.75rem;
            background: #f8fafc;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }
        
        .product-overview {
            display: flex;
            gap: 2rem;
            margin-bottom: 2rem;
            align-items: flex-start;
        }
        
        .product-image-large {
            width: 120px;
            height: 120px;
            border-radius: 12px;
            object-fit: cover;
            border: 2px solid #e2e8f0;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .product-basic-info h2 {
            margin: 0 0 1rem 0;
            color: #1e293b;
            font-size: 1.75rem;
            font-weight: 700;
        }
        
        .product-sku, .product-category {
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-weight: 500;
            font-size: 0.875rem;
            display: inline-block;
            margin: 0.25rem 0.5rem 0.25rem 0;
        }
        
        .product-sku {
            background: #f1f5f9;
            color: #475569;
        }
        
        .product-category {
            background: linear-gradient(135deg, var(--primary-color), #8b5cf6);
            color: white;
        }
        
        .expiry-date, .days-left {
            font-weight: 600;
        }
        
        .expiry-date.critical, .days-left.critical {
            color: #f59e0b;
        }
        
        .expiry-date.expired, .days-left.expired {
            color: #dc2626;
        }
        
        .status-badge {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .status-active {
            background: #dcfce7;
            color: #166534;
        }
        
        .status-expired {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .status-disposed {
            background: #f3f4f6;
            color: #374151;
        }
        
        .actions-timeline {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }
        
        .action-item {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        
        .action-item:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        
        .action-header {
            background: linear-gradient(135deg, #f8fafc, #e2e8f0);
            padding: 1rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .action-type {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .action-dispose { background: #fee2e2; color: #991b1b; }
        .action-return { background: #dbeafe; color: #1e40af; }
        .action-sell_at_discount { background: #dcfce7; color: #166534; }
        .action-donate { background: #fef3c7; color: #92400e; }
        .action-recall { background: #fecaca; color: #7f1d1d; }
        .action-other { background: #e2e8f0; color: #475569; }
        
        .action-date {
            font-size: 0.875rem;
            color: #64748b;
            font-weight: 500;
        }
        
        .action-details {
            padding: 1.5rem;
        }
        
        .action-details p {
            margin: 0.75rem 0;
            font-size: 0.875rem;
            line-height: 1.5;
        }
        
        .action-details strong {
            color: #1e293b;
        }
        
        .btn {
            transition: all 0.3s ease;
            font-weight: 500;
            border-radius: 8px;
            padding: 0.75rem 1.5rem;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), #8b5cf6);
            border: none;
            box-shadow: 0 2px 8px rgba(var(--primary-rgb), 0.3);
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, var(--primary-color), #7c3aed);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(var(--primary-rgb), 0.4);
        }
        
        .btn-warning {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            border: none;
            color: white;
        }
        
        .btn-warning:hover {
            background: linear-gradient(135deg, #d97706, #b45309);
            transform: translateY(-2px);
        }
        
        .btn-outline-secondary {
            background: transparent;
            border: 2px solid #e2e8f0;
            color: #64748b;
        }
        
        .btn-outline-secondary:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
            color: #475569;
            transform: translateY(-1px);
        }
        
        /* Page Header Styling */
        .page-header {
            background: #fff;
            border-bottom: 1px solid #e2e8f0;
            padding: 1.5rem 0;
            margin-bottom: 2rem;
        }
        
        .page-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--primary-color), #8b5cf6);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            box-shadow: 0 4px 12px rgba(var(--primary-rgb), 0.15);
            flex-shrink: 0;
        }
        
        .page-title {
            font-size: 1.75rem;
            font-weight: 700;
            color: #1e293b;
            margin: 0;
        }
        
        .page-subtitle {
            color: #64748b;
            font-size: 0.9rem;
            margin: 0;
        }
        
        .breadcrumb {
            background: transparent;
            padding: 0;
            margin: 0;
            font-size: 0.875rem;
        }
        
        .breadcrumb-item {
            color: #64748b;
        }
        
        .breadcrumb-item.active {
            color: var(--primary-color);
            font-weight: 500;
        }
        
        .breadcrumb-item + .breadcrumb-item::before {
            content: '/';
            color: #cbd5e1;
        }
        
        .page-actions .btn {
            font-size: 0.875rem;
            padding: 0.625rem 1.25rem;
        }
        
        @media (max-width: 768px) {
            .page-header {
                padding: 1rem 0;
            }
            
            .page-title {
                font-size: 1.5rem;
            }
            
            .page-icon {
                width: 50px;
                height: 50px;
                font-size: 1.25rem;
            }
            
            .d-flex.justify-content-between {
                flex-direction: column !important;
                gap: 1rem;
            }
            
            .page-actions {
                justify-content: center;
            }
            
            .product-overview {
                flex-direction: column;
                text-align: center;
            }
            
            .product-image-large {
                width: 100px;
                height: 100px;
                margin: 0 auto 1rem;
            }
            
            .detail-row {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            
            .action-header {
                flex-direction: column;
                gap: 0.75rem;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <?php
    $current_page = 'expiry_tracker';
    include __DIR__ . '/../include/navmenu.php';
    ?>

    <div class="main-content">
        <!-- Header -->
        <div class="page-header">
            <div class="container-fluid">
                <!-- Breadcrumb -->
                <nav aria-label="breadcrumb" class="mb-3">
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><a href="../dashboard/dashboard.php" class="text-decoration-none">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="expiry_tracker.php" class="text-decoration-none">Expiry Tracker</a></li>
                        <li class="breadcrumb-item active" aria-current="page">View Item</li>
                    </ol>
                </nav>
                
                <!-- Page Title and Actions -->
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                    <div class="page-title-section">
                        <div class="d-flex align-items-center mb-2">
                            <div class="page-icon me-3">
                                <i class="bi bi-calendar-x"></i>
                            </div>
                            <div>
                                <h1 class="page-title mb-1">Expiry Item Details</h1>
                                <p class="page-subtitle mb-0">View detailed information about this expiry item</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="page-actions d-flex flex-wrap gap-2">
                        <?php if (hasPermission('handle_expired_items', $permissions) && $expiry_item['status'] === 'active'): ?>
                            <a href="handle_expiry.php?id=<?php echo $expiry_id; ?>" class="btn btn-warning">
                                <i class="bi bi-tools me-2"></i>Handle Expiry
                            </a>
                        <?php endif; ?>
                        <?php if (hasPermission('manage_expiry_tracker', $permissions)): ?>
                            <a href="edit_expiry_date.php?id=<?php echo $expiry_id; ?>" class="btn btn-primary">
                                <i class="bi bi-pencil me-2"></i>Edit
                            </a>
                        <?php endif; ?>
                        <a href="expiry_tracker.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left me-2"></i>Back to Tracker
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Content -->
        <main class="content">

            <!-- Alert Messages -->
            <?php if ($show_success): ?>
                <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
                    <i class="bi bi-check-circle me-2"></i>
                    <strong>Success!</strong> Expiry date added successfully! You can now view the details below.
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['success']) && $_GET['success'] === 'updated'): ?>
                <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
                    <i class="bi bi-check-circle me-2"></i>
                    <strong>Success!</strong> Expiry date updated successfully! The changes are reflected below.
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Product Information -->
            <div class="info-card">
                <h5 class="mb-4"><i class="bi bi-box-seam me-2"></i>Product Information</h5>
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
            <div class="info-card">
                <h5 class="mb-4"><i class="bi bi-truck me-2"></i>Supplier Information</h5>
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
            <div class="info-card">
                <h5 class="mb-4"><i class="bi bi-clock-history me-2"></i>Actions History</h5>
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
            <div class="info-card">
                <h5 class="mb-3"><i class="bi bi-journal-text me-2"></i>Notes</h5>
                <p class="mb-0"><?php echo nl2br(htmlspecialchars($expiry_item['notes'])); ?></p>
            </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

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
