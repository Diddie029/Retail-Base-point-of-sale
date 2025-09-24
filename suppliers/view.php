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

// Check if user has permission to view suppliers
if (!hasPermission('view_suppliers', $permissions)) {
    header("Location: ../dashboard/dashboard.php");
    exit();
}

// Get supplier ID
$supplier_id = (int)($_GET['id'] ?? 0);
if ($supplier_id <= 0) {
    header("Location: suppliers.php");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get supplier data with performance metrics
$stmt = $conn->prepare("
    SELECT s.*,
           COUNT(DISTINCT p.id) as total_products,
           COUNT(DISTINCT CASE WHEN p.status = 'active' THEN p.id END) as active_products,
           COUNT(DISTINCT po.id) as total_orders,
           AVG(pm.quality_score) as avg_quality_score,
           AVG(CASE WHEN pm.total_orders > 0 THEN (pm.on_time_deliveries / pm.total_orders) * 100 ELSE 0 END) as avg_on_time_delivery,
           AVG(pm.average_delivery_days) as avg_delivery_days,
           MAX(pm.metric_date) as last_performance_date
    FROM suppliers s
    LEFT JOIN products p ON s.id = p.supplier_id
    LEFT JOIN inventory_orders po ON s.id = po.supplier_id
    LEFT JOIN supplier_performance_metrics pm ON s.id = pm.supplier_id
    WHERE s.id = :id
    GROUP BY s.id
");
$stmt->bindParam(':id', $supplier_id);
$stmt->execute();
$supplier = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$supplier) {
    $_SESSION['error'] = 'Supplier not found.';
    header("Location: suppliers.php");
    exit();
}

// Pagination parameters
$products_page = isset($_GET['products_page']) ? max(1, (int)$_GET['products_page']) : 1;
$orders_page = isset($_GET['orders_page']) ? max(1, (int)$_GET['orders_page']) : 1;
$logs_page = isset($_GET['logs_page']) ? max(1, (int)$_GET['logs_page']) : 1;
$products_per_page = 10;
$orders_per_page = 5;
$logs_per_page = 10;

// Get products supplied by this supplier with pagination
$products_offset = ($products_page - 1) * $products_per_page;
$products_stmt = $conn->prepare("
    SELECT p.id, p.name, p.sku, p.price, p.quantity, p.status, c.name as category_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.supplier_id = :supplier_id
    ORDER BY p.name ASC
    LIMIT :limit OFFSET :offset
");
$products_stmt->bindParam(':supplier_id', $supplier_id);
$products_stmt->bindParam(':limit', $products_per_page, PDO::PARAM_INT);
$products_stmt->bindParam(':offset', $products_offset, PDO::PARAM_INT);
$products_stmt->execute();
$supplier_products = $products_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total products count for pagination
$products_count_stmt = $conn->prepare("
    SELECT COUNT(*) as total
    FROM products p
    WHERE p.supplier_id = :supplier_id
");
$products_count_stmt->bindParam(':supplier_id', $supplier_id);
$products_count_stmt->execute();
$total_products = $products_count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_products_pages = ceil($total_products / $products_per_page);

// Get recent orders from this supplier with pagination
$orders_offset = ($orders_page - 1) * $orders_per_page;
$orders_stmt = $conn->prepare("
    SELECT io.id, io.order_number, io.order_date, io.total_amount, io.status,
           io.expected_date, io.received_date as actual_delivery_date,
           COUNT(ioi.id) as items_count
    FROM inventory_orders io
    LEFT JOIN inventory_order_items ioi ON io.id = ioi.order_id
    WHERE io.supplier_id = :supplier_id
    GROUP BY io.id
    ORDER BY io.order_date DESC
    LIMIT :limit OFFSET :offset
");
$orders_stmt->bindParam(':supplier_id', $supplier_id);
$orders_stmt->bindParam(':limit', $orders_per_page, PDO::PARAM_INT);
$orders_stmt->bindParam(':offset', $orders_offset, PDO::PARAM_INT);
$orders_stmt->execute();
$supplier_orders = $orders_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total orders count for pagination
$orders_count_stmt = $conn->prepare("
    SELECT COUNT(*) as total
    FROM inventory_orders io
    WHERE io.supplier_id = :supplier_id
");
$orders_count_stmt->bindParam(':supplier_id', $supplier_id);
$orders_count_stmt->execute();
$total_orders = $orders_count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_orders_pages = ceil($total_orders / $orders_per_page);

// Get supplier activity log
$supplier_logs = [];

// Get order activities
$order_logs_stmt = $conn->prepare("
    SELECT
        'order' as activity_type,
        'Order Created' as activity_name,
        CONCAT('Purchase order ', io.order_number, ' created for $', FORMAT(io.total_amount, 2)) as description,
        io.order_date as activity_date,
        'System' as performed_by,
        'orders' as category
    FROM inventory_orders io
    WHERE io.supplier_id = :supplier_id
    ORDER BY io.order_date DESC
    LIMIT 10
");
$order_logs_stmt->bindParam(':supplier_id', $supplier_id);
$order_logs_stmt->execute();
$order_logs = $order_logs_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get supplier status change activities
$status_logs_stmt = $conn->prepare("
    SELECT
        'status_change' as activity_type,
        CASE WHEN is_active = 1 THEN 'Supplier Activated' ELSE 'Supplier Deactivated' END as activity_name,
        CASE WHEN is_active = 1
             THEN 'Supplier was activated and is now available for orders'
             ELSE CONCAT('Supplier was deactivated: ', COALESCE(supplier_block_note, 'No reason specified'))
        END as description,
        updated_at as activity_date,
        'System' as performed_by,
        'status' as category
    FROM suppliers
    WHERE id = :supplier_id AND updated_at != created_at
    ORDER BY updated_at DESC
    LIMIT 5
");
$status_logs_stmt->bindParam(':supplier_id', $supplier_id);
$status_logs_stmt->execute();
$status_logs = $status_logs_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get supplier creation activity
$creation_log_stmt = $conn->prepare("
    SELECT
        'supplier_created' as activity_type,
        'Supplier Created' as activity_name,
        'Supplier account was created in the system' as description,
        created_at as activity_date,
        'System' as performed_by,
        'general' as category
    FROM suppliers
    WHERE id = :supplier_id
    ORDER BY created_at DESC
    LIMIT 1
");
$creation_log_stmt->bindParam(':supplier_id', $supplier_id);
$creation_log_stmt->execute();
$creation_log = $creation_log_stmt->fetchAll(PDO::FETCH_ASSOC);

// Combine all logs
$supplier_logs = array_merge($order_logs, $status_logs, $creation_log);

// Sort by date (most recent first)
usort($supplier_logs, function($a, $b) {
    return strtotime($b['activity_date']) - strtotime($a['activity_date']);
});

// Apply pagination to logs
$logs_offset = ($logs_page - 1) * $logs_per_page;
$total_logs = count($supplier_logs);
$total_logs_pages = ceil($total_logs / $logs_per_page);
$supplier_logs = array_slice($supplier_logs, $logs_offset, $logs_per_page);

// Get performance metrics for the last 6 months
$performance_stmt = $conn->prepare("
    SELECT metric_date, quality_score, on_time_deliveries, total_orders, average_delivery_days
    FROM supplier_performance_metrics
    WHERE supplier_id = :supplier_id
    AND metric_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    ORDER BY metric_date ASC
");
$performance_stmt->bindParam(':supplier_id', $supplier_id);
$performance_stmt->execute();
$performance_data = $performance_stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle success message
$success = $_SESSION['success'] ?? '';
unset($_SESSION['success']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($supplier['name'] ?? 'Supplier'); ?> - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/suppliers.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
        }

        /* Supplier-specific styles */
        .supplier-header {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            border: 1px solid #e2e8f0;
        }

        .supplier-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color), #8b5cf6);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2rem;
            font-weight: 600;
            margin-right: 1.5rem;
            flex-shrink: 0;
        }

        .supplier-info h1 {
            margin-bottom: 0.5rem;
            font-size: 2rem;
            font-weight: 700;
            color: #1a202c;
        }

        .supplier-meta {
            display: flex;
            gap: 2rem;
            margin-top: 1rem;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #718096;
            font-size: 0.9rem;
        }

        .meta-item i {
            color: var(--primary-color);
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: #fff;
            border-radius: 16px;
            padding: 0;
            border: 1px solid #e5e7eb;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            overflow: hidden;
            position: relative;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--card-color, #6366f1) 0%, var(--card-color-light, #8b5cf6) 100%);
        }

        .card-success { --card-color: #10b981; --card-color-light: #34d399; }
        .card-info { --card-color: #3b82f6; --card-color-light: #60a5fa; }
        .card-warning { --card-color: #f59e0b; --card-color-light: #fbbf24; }
        .card-primary { --card-color: #6366f1; --card-color-light: #8b5cf6; }
        .card-danger { --card-color: #ef4444; --card-color-light: #f87171; }

        .stat-content {
            display: flex;
            align-items: center;
            padding: 1.5rem;
            gap: 1rem;
        }

        .stat-icon {
            width: 64px;
            height: 64px;
            border-radius: 16px;
            background: linear-gradient(135deg, var(--card-color, #6366f1), var(--card-color-light, #8b5cf6));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.75rem;
            flex-shrink: 0;
        }

        .stat-info {
            flex: 1;
            min-width: 0;
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            color: #111827;
            line-height: 1;
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.875rem;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        /* Data sections */
        .data-section {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            border: 1px solid #e2e8f0;
            margin-bottom: 30px;
            overflow: hidden;
        }

        .data-section .section-header {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            padding: 20px 25px;
            border-bottom: 1px solid #e2e8f0;
            margin-bottom: 0;
        }

        .data-section .section-content {
            padding: 25px;
        }

        .section-title {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 600;
            color: #2d3748;
        }

        /* Performance chart placeholder */
        .performance-chart {
            height: 200px;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border: 2px dashed #cbd5e0;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #718096;
            font-size: 1.1rem;
        }

        /* Contact info styles */
        .contact-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 0;
            border-bottom: 1px solid #f1f5f9;
        }

        .contact-item:last-child {
            border-bottom: none;
        }

        .contact-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--primary-color), #8b5cf6);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            flex-shrink: 0;
        }

        .contact-details {
            flex: 1;
        }

        .contact-label {
            font-size: 0.75rem;
            color: #718096;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.25rem;
        }

        .contact-value {
            font-weight: 500;
            color: #2d3748;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .supplier-meta {
                flex-direction: column;
                gap: 0.5rem;
            }

            .stat-content {
                padding: 1.25rem;
            }

            .stat-icon {
                width: 56px;
                height: 56px;
                font-size: 1.5rem;
            }

            .stat-value {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <?php
    $current_page = 'suppliers';
    include __DIR__ . '/../include/navmenu.php';
    ?>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1><?php echo htmlspecialchars($supplier['name'] ?? 'Supplier'); ?></h1>
                    <div class="header-subtitle">Supplier details and performance information</div>
                </div>
                <div class="header-actions">
                    <a href="edit.php?id=<?php echo $supplier_id; ?>" class="btn btn-warning">
                        <i class="bi bi-pencil"></i>
                        Edit Supplier
                    </a>
                    <a href="suppliers.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i>
                        Back to Suppliers
                    </a>
                    <div class="user-info">
                        <div class="user-avatar"><?php echo strtoupper(substr($username, 0, 1)); ?></div>
                        <span><?php echo htmlspecialchars($username); ?></span>
                    </div>
                </div>
            </div>
        </header>

        <!-- Content -->
        <div class="content">
            <?php if (!empty($success)): ?>
            <div class="alert alert-success">
                <i class="bi bi-check-circle me-2"></i>
                <?php echo htmlspecialchars($success); ?>
            </div>
            <?php endif; ?>

            <!-- Supplier Header -->
            <div class="supplier-header">
                <div class="d-flex align-items-start">
                    <div class="supplier-avatar">
                        <i class="bi bi-truck"></i>
                    </div>
                    <div class="supplier-info flex-grow-1">
                        <h1><?php echo htmlspecialchars($supplier['name'] ?? 'Supplier'); ?></h1>
                        <p class="text-muted mb-2"><?php echo htmlspecialchars($supplier['description'] ?? 'No description available'); ?></p>

                        <div class="d-flex gap-3 mb-3">
                            <span class="badge <?php echo $supplier['is_active'] ? 'bg-success' : 'bg-secondary'; ?> fs-6">
                                <i class="bi <?php echo $supplier['is_active'] ? 'bi-check-circle' : 'bi-pause-circle'; ?> me-1"></i>
                                <?php echo $supplier['is_active'] ? 'Active' : 'Inactive'; ?>
                            </span>
                            <?php if (!empty($supplier['supplier_block_note'])): ?>
                            <span class="badge bg-warning text-dark fs-6" title="<?php echo htmlspecialchars($supplier['supplier_block_note']); ?>">
                                <i class="bi bi-exclamation-triangle me-1"></i>
                                Notice
                            </span>
                            <?php endif; ?>
                        </div>

                        <div class="supplier-meta">
                            <div class="meta-item">
                                <i class="bi bi-calendar"></i>
                                <span>Added <?php echo date('M j, Y', strtotime($supplier['created_at'])); ?></span>
                            </div>
                            <div class="meta-item">
                                <i class="bi bi-box-seam"></i>
                                <span><?php echo number_format($supplier['total_products']); ?> Products</span>
                            </div>
                            <div class="meta-item">
                                <i class="bi bi-receipt"></i>
                                <span><?php echo number_format($supplier['total_orders']); ?> Orders</span>
                            </div>
                            <?php if ($supplier['last_performance_date']): ?>
                            <div class="meta-item">
                                <i class="bi bi-graph-up"></i>
                                <span>Last reviewed <?php echo date('M j, Y', strtotime($supplier['last_performance_date'])); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stats Overview -->
            <div class="stats-grid">
                <div class="stat-card card-primary">
                    <div class="stat-content">
                        <div class="stat-icon">
                            <i class="bi bi-box-seam-fill"></i>
                        </div>
                        <div class="stat-info">
                            <div class="stat-value"><?php echo number_format($supplier['total_products']); ?></div>
                            <div class="stat-label">Total Products</div>
                        </div>
                    </div>
                </div>

                <div class="stat-card card-success">
                    <div class="stat-content">
                        <div class="stat-icon">
                            <i class="bi bi-check-circle-fill"></i>
                        </div>
                        <div class="stat-info">
                            <div class="stat-value"><?php echo number_format($supplier['active_products']); ?></div>
                            <div class="stat-label">Active Products</div>
                        </div>
                    </div>
                </div>

                <div class="stat-card card-info">
                    <div class="stat-content">
                        <div class="stat-icon">
                            <i class="bi bi-receipt-fill"></i>
                        </div>
                        <div class="stat-info">
                            <div class="stat-value"><?php echo number_format($supplier['total_orders']); ?></div>
                            <div class="stat-label">Total Orders</div>
                        </div>
                    </div>
                </div>

                <div class="stat-card card-warning">
                    <div class="stat-content">
                        <div class="stat-icon">
                            <i class="bi bi-star-fill"></i>
                        </div>
                        <div class="stat-info">
                            <div class="stat-value">
                                <?php
                                $quality_score = $supplier['avg_quality_score'] ?? 0;
                                $delivery_rate = $supplier['avg_on_time_delivery'] ?? 0;
                                $avg_score = ($quality_score + $delivery_rate) / 20; // Convert from 100-scale to 5-scale
                                echo $avg_score > 0 ? number_format($avg_score, 1) : 'N/A';
                                ?>
                            </div>
                            <div class="stat-label">Avg Performance</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Contact Information -->
            <div class="data-section">
                <div class="section-header">
                    <h3 class="section-title">
                        <i class="bi bi-person-lines-fill me-2"></i>
                        Contact Information
                    </h3>
                </div>
                <div class="section-content">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="contact-item">
                                <div class="contact-icon">
                                    <i class="bi bi-person"></i>
                                </div>
                                <div class="contact-details">
                                    <div class="contact-label">Contact Person</div>
                                    <div class="contact-value"><?php echo htmlspecialchars($supplier['contact_person'] ?? 'Not specified'); ?></div>
                                </div>
                            </div>

                            <div class="contact-item">
                                <div class="contact-icon">
                                    <i class="bi bi-envelope"></i>
                                </div>
                                <div class="contact-details">
                                    <div class="contact-label">Email Address</div>
                                    <div class="contact-value">
                                        <?php if (!empty($supplier['email'])): ?>
                                            <a href="mailto:<?php echo htmlspecialchars($supplier['email']); ?>" class="text-decoration-none">
                                                <?php echo htmlspecialchars($supplier['email']); ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">Not provided</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="contact-item">
                                <div class="contact-icon">
                                    <i class="bi bi-telephone"></i>
                                </div>
                                <div class="contact-details">
                                    <div class="contact-label">Phone Number</div>
                                    <div class="contact-value">
                                        <?php if (!empty($supplier['phone'])): ?>
                                            <a href="tel:<?php echo htmlspecialchars($supplier['phone']); ?>" class="text-decoration-none">
                                                <?php echo htmlspecialchars($supplier['phone']); ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">Not provided</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="contact-item">
                                <div class="contact-icon">
                                    <i class="bi bi-geo-alt"></i>
                                </div>
                                <div class="contact-details">
                                    <div class="contact-label">Address</div>
                                    <div class="contact-value">
                                        <?php if (!empty($supplier['address'])): ?>
                                            <?php echo nl2br(htmlspecialchars($supplier['address'])); ?>
                                        <?php else: ?>
                                            <span class="text-muted">Not provided</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <?php if (!empty($supplier['pickup_available'])): ?>
                            <div class="contact-item">
                                <div class="contact-icon">
                                    <i class="bi bi-shop"></i>
                                </div>
                                <div class="contact-details">
                                    <div class="contact-label">Pickup Information</div>
                                    <div class="contact-value">
                                        <strong>In-store pickup available</strong>
                                        <?php if (!empty($supplier['pickup_address'])): ?>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($supplier['pickup_address']); ?></small>
                                        <?php endif; ?>
                                        <?php if (!empty($supplier['pickup_hours'])): ?>
                                            <br><small class="text-muted">Hours: <?php echo htmlspecialchars($supplier['pickup_hours']); ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Products Supplied -->
            <div class="data-section" data-section="products">
                <div class="section-header">
                    <h3 class="section-title">
                        <i class="bi bi-box-seam me-2"></i>
                        Products Supplied
                    </h3>
                    <?php if (hasPermission('manage_products', $permissions)): ?>
                    <a href="../products/add.php?supplier_id=<?php echo $supplier_id; ?>" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-plus"></i>
                        Add Product
                    </a>
                    <?php endif; ?>
                </div>

                <?php if (empty($supplier_products)): ?>
                <div class="text-center py-4">
                    <i class="bi bi-box-seam" style="font-size: 3rem; color: #9ca3af;"></i>
                    <p class="text-muted mt-2">No products found for this supplier</p>
                    <?php if (hasPermission('manage_products', $permissions)): ?>
                    <a href="../products/add.php?supplier_id=<?php echo $supplier_id; ?>" class="btn btn-primary">
                        <i class="bi bi-plus"></i>
                        Add First Product
                    </a>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Product Name</th>
                                <th>SKU</th>
                                <th>Category</th>
                                <th>Price</th>
                                <th>Stock</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($supplier_products as $product): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="product-image-placeholder me-3" style="width: 40px; height: 40px; font-size: 1rem;">
                                            <i class="bi bi-box"></i>
                                        </div>
                                        <div>
                                            <a href="../products/view.php?id=<?php echo $product['id']; ?>" class="text-decoration-none text-dark">
                                                <strong><?php echo htmlspecialchars($product['name']); ?></strong>
                                            </a>
                                        </div>
                                    </div>
                                </td>
                                <td><code><?php echo htmlspecialchars($product['sku'] ?? 'N/A'); ?></code></td>
                                <td><?php echo htmlspecialchars($product['category_name'] ?? 'Uncategorized'); ?></td>
                                <td class="currency"><?php echo $settings['currency_symbol']; ?> <?php echo number_format($product['price'], 2); ?></td>
                                <td>
                                    <span class="badge <?php echo $product['quantity'] > 10 ? 'bg-success' : ($product['quantity'] > 0 ? 'bg-warning' : 'bg-danger'); ?>">
                                        <?php echo number_format($product['quantity']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?php echo $product['status'] === 'active' ? 'bg-success' : 'bg-secondary'; ?>">
                                        <?php echo ucfirst($product['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (hasPermission('view_products', $permissions)): ?>
                                    <a href="../products/view.php?id=<?php echo $product['id']; ?>" class="btn btn-outline-secondary btn-sm">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <?php endif; ?>
                                    <?php if (hasPermission('manage_products', $permissions)): ?>
                                    <a href="../products/edit.php?id=<?php echo $product['id']; ?>" class="btn btn-outline-primary btn-sm">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Products Pagination -->
                <?php if ($total_products_pages > 1): ?>
                <div class="d-flex justify-content-between align-items-center mt-3">
                    <div class="pagination-info">
                        <small class="text-muted">
                            Showing <?php echo (($products_page - 1) * $products_per_page) + 1; ?> to
                            <?php echo min($products_page * $products_per_page, $total_products); ?> of
                            <?php echo $total_products; ?> products
                        </small>
                    </div>
                    <nav aria-label="Products pagination">
                        <ul class="pagination pagination-sm mb-0">
                            <?php if ($products_page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?id=<?php echo $supplier_id; ?>&products_page=<?php echo $products_page - 1; ?>&orders_page=<?php echo $orders_page; ?>&logs_page=<?php echo $logs_page; ?>">
                                    <i class="bi bi-chevron-left"></i>
                                </a>
                            </li>
                            <?php endif; ?>

                            <?php
                            $start_page = max(1, $products_page - 2);
                            $end_page = min($total_products_pages, $products_page + 2);

                            for ($i = $start_page; $i <= $end_page; $i++):
                            ?>
                            <li class="page-item <?php echo $i == $products_page ? 'active' : ''; ?>">
                                <a class="page-link" href="?id=<?php echo $supplier_id; ?>&products_page=<?php echo $i; ?>&orders_page=<?php echo $orders_page; ?>&logs_page=<?php echo $logs_page; ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                            <?php endfor; ?>

                            <?php if ($products_page < $total_products_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?id=<?php echo $supplier_id; ?>&products_page=<?php echo $products_page + 1; ?>&orders_page=<?php echo $orders_page; ?>&logs_page=<?php echo $logs_page; ?>">
                                    <i class="bi bi-chevron-right"></i>
                                </a>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Two-column layout for Recent Orders and Performance Metrics -->
            <div class="row">
                <div class="col-md-6">
                    <!-- Recent Orders -->
                    <div class="data-section" data-section="orders">
                <div class="section-header">
                    <h3 class="section-title">
                        <i class="bi bi-receipt me-2"></i>
                        Recent Orders
                    </h3>
                    <?php if (hasPermission('manage_inventory', $permissions)): ?>
                    <a href="../inventory/add_order.php?supplier_id=<?php echo $supplier_id; ?>" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-plus"></i>
                        New Order
                    </a>
                    <?php endif; ?>
                </div>

                <?php if (empty($supplier_orders)): ?>
                <div class="text-center py-4">
                    <i class="bi bi-receipt" style="font-size: 3rem; color: #9ca3af;"></i>
                    <p class="text-muted mt-2">No orders found for this supplier</p>
                    <?php if (hasPermission('manage_inventory', $permissions)): ?>
                    <a href="../inventory/add_order.php?supplier_id=<?php echo $supplier_id; ?>" class="btn btn-primary">
                        <i class="bi bi-plus"></i>
                        Create First Order
                    </a>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Order Number</th>
                                <th>Date</th>
                                <th>Items</th>
                                <th>Total Amount</th>
                                <th>Status</th>
                                <th>Expected Delivery</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($supplier_orders as $order): ?>
                            <tr>
                                <td>
                                    <a href="../inventory/view_order.php?id=<?php echo $order['id']; ?>" class="text-decoration-none">
                                        <strong><?php echo htmlspecialchars($order['order_number']); ?></strong>
                                    </a>
                                </td>
                                <td><?php echo date('M j, Y', strtotime($order['order_date'])); ?></td>
                                <td><?php echo number_format($order['items_count']); ?> items</td>
                                <td class="currency"><?php echo $settings['currency_symbol']; ?> <?php echo number_format($order['total_amount'], 2); ?></td>
                                <td>
                                    <span class="badge
                                        <?php
                                        switch($order['status']) {
                                            case 'delivered': echo 'bg-success'; break;
                                            case 'pending': echo 'bg-warning'; break;
                                            case 'shipped': echo 'bg-info'; break;
                                            case 'cancelled': echo 'bg-danger'; break;
                                            default: echo 'bg-secondary';
                                        }
                                        ?>">
                                        <?php echo ucfirst($order['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($order['expected_delivery_date'])): ?>
                                        <?php echo date('M j, Y', strtotime($order['expected_delivery_date'])); ?>
                                        <?php if (!empty($order['actual_delivery_date'])): ?>
                                            <br><small class="text-success">Delivered <?php echo date('M j, Y', strtotime($order['actual_delivery_date'])); ?></small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (hasPermission('manage_inventory', $permissions)): ?>
                                    <a href="../inventory/view_order.php?id=<?php echo $order['id']; ?>" class="btn btn-outline-secondary btn-sm">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <a href="../inventory/edit_order.php?id=<?php echo $order['id']; ?>" class="btn btn-outline-primary btn-sm">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Orders Pagination -->
                <?php if ($total_orders_pages > 1): ?>
                <div class="d-flex justify-content-between align-items-center mt-3">
                    <div class="pagination-info">
                        <small class="text-muted">
                            Showing <?php echo (($orders_page - 1) * $orders_per_page) + 1; ?> to
                            <?php echo min($orders_page * $orders_per_page, $total_orders); ?> of
                            <?php echo $total_orders; ?> orders
                        </small>
                    </div>
                    <nav aria-label="Orders pagination">
                        <ul class="pagination pagination-sm mb-0">
                            <?php if ($orders_page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?id=<?php echo $supplier_id; ?>&products_page=<?php echo $products_page; ?>&orders_page=<?php echo $orders_page - 1; ?>&logs_page=<?php echo $logs_page; ?>">
                                    <i class="bi bi-chevron-left"></i>
                                </a>
                            </li>
                            <?php endif; ?>

                            <?php
                            $start_page = max(1, $orders_page - 2);
                            $end_page = min($total_orders_pages, $orders_page + 2);

                            for ($i = $start_page; $i <= $end_page; $i++):
                            ?>
                            <li class="page-item <?php echo $i == $orders_page ? 'active' : ''; ?>">
                                <a class="page-link" href="?id=<?php echo $supplier_id; ?>&products_page=<?php echo $products_page; ?>&orders_page=<?php echo $i; ?>&logs_page=<?php echo $logs_page; ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                            <?php endfor; ?>

                            <?php if ($orders_page < $total_orders_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?id=<?php echo $supplier_id; ?>&products_page=<?php echo $products_page; ?>&orders_page=<?php echo $orders_page + 1; ?>&logs_page=<?php echo $logs_page; ?>">
                                    <i class="bi bi-chevron-right"></i>
                                </a>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>
                <?php endif; ?>
                    </div>
                </div>

                <div class="col-md-6">
                    <!-- Performance Metrics -->
            <div class="data-section" data-section="performance">
                <div class="section-header">
                    <h3 class="section-title">
                        <i class="bi bi-graph-up me-2"></i>
                        Performance Metrics
                    </h3>
                    <?php if (hasPermission('manage_supplier_performance', $permissions)): ?>
                    <a href="supplier_performance.php?id=<?php echo $supplier_id; ?>" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-pencil"></i>
                        Manage Performance
                    </a>
                    <?php endif; ?>
                </div>

                <?php if (empty($performance_data)): ?>
                <div class="text-center py-4">
                    <i class="bi bi-graph-up" style="font-size: 3rem; color: #9ca3af;"></i>
                    <p class="text-muted mt-2">No performance data available for this supplier</p>
                    <?php if (hasPermission('manage_supplier_performance', $permissions)): ?>
                    <a href="supplier_performance.php?id=<?php echo $supplier_id; ?>" class="btn btn-primary">
                        <i class="bi bi-plus"></i>
                        Add Performance Data
                    </a>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="row">
                    <div class="col-md-6">
                        <div class="performance-chart">
                            <div class="text-center">
                                <i class="bi bi-bar-chart-line" style="font-size: 2rem; color: #9ca3af;"></i>
                                <p class="mt-2 mb-0">Performance Chart</p>
                                <small class="text-muted">Last 6 months data</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                                <div class="info-list">
                                    <div class="info-item">
                                        <div class="info-label">Quality Score:</div>
                                        <div class="info-value">
                                            <span class="badge
                                                <?php
                                                $quality_score = $supplier['avg_quality_score'];
                                                if ($quality_score >= 90) echo 'bg-success';
                                                elseif ($quality_score >= 75) echo 'bg-warning';
                                                else echo 'bg-danger';
                                                ?>">
                                                <?php echo $quality_score > 0 ? number_format($quality_score, 1) : 'N/A'; ?>/100
                                            </span>
                                        </div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">On-Time Delivery:</div>
                                        <div class="info-value">
                                            <span class="badge
                                                <?php
                                                $on_time_rate = $supplier['avg_on_time_delivery'];
                                                if ($on_time_rate >= 95) echo 'bg-success';
                                                elseif ($on_time_rate >= 85) echo 'bg-warning';
                                                else echo 'bg-danger';
                                                ?>">
                                                <?php echo $on_time_rate > 0 ? number_format($on_time_rate, 1) : 'N/A'; ?>%
                                            </span>
                                        </div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Avg Delivery Days:</div>
                                        <div class="info-value">
                                            <span class="badge
                                                <?php
                                                $delivery_days = $supplier['avg_delivery_days'];
                                                if ($delivery_days <= 3) echo 'bg-success';
                                                elseif ($delivery_days <= 7) echo 'bg-warning';
                                                else echo 'bg-danger';
                                                ?>">
                                                <?php echo $delivery_days > 0 ? number_format($delivery_days, 1) : 'N/A'; ?> days
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-list">
                                    <div class="info-item">
                                        <div class="info-label">Last Review:</div>
                                        <div class="info-value">
                                            <?php echo $supplier['last_performance_date'] ? date('M j, Y', strtotime($supplier['last_performance_date'])) : 'Never'; ?>
                                        </div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Overall Rating:</div>
                                        <div class="info-value">
                                            <?php
                                            // Calculate overall rating based on quality score (out of 100) and on-time delivery (out of 100)
                                            $quality_component = $supplier['avg_quality_score'] ?? 0;
                                            $delivery_component = $supplier['avg_on_time_delivery'] ?? 0;
                                            $overall_score = ($quality_component + $delivery_component) / 20; // Convert to 5-star scale

                                            $stars = '';
                                            if ($overall_score > 0) {
                                                $full_stars = floor($overall_score);
                                                $half_star = ($overall_score - $full_stars) >= 0.5;
                                                for ($i = 0; $i < $full_stars; $i++) {
                                                    $stars .= '<i class="bi bi-star-fill text-warning"></i>';
                                                }
                                                if ($half_star) {
                                                    $stars .= '<i class="bi bi-star-half text-warning"></i>';
                                                }
                                                for ($i = $full_stars + ($half_star ? 1 : 0); $i < 5; $i++) {
                                                    $stars .= '<i class="bi bi-star text-muted"></i>';
                                                }
                                            } else {
                                                $stars = '<span class="text-muted">Not rated</span>';
                                            }
                                            echo $stars;
                                            ?>
                                        </div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Performance Status:</div>
                                        <div class="info-value">
                                            <?php
                                            // Calculate performance status based on quality score and on-time delivery
                                            $quality_score = $supplier['avg_quality_score'] ?? 0;
                                            $delivery_rate = $supplier['avg_on_time_delivery'] ?? 0;

                                            if ($quality_score >= 90 && $delivery_rate >= 95) {
                                                echo '<span class="badge bg-success">Excellent</span>';
                                            } elseif ($quality_score >= 75 && $delivery_rate >= 85) {
                                                echo '<span class="badge bg-warning">Good</span>';
                                            } elseif ($quality_score > 0 || $delivery_rate > 0) {
                                                echo '<span class="badge bg-danger">Needs Improvement</span>';
                                            } else {
                                                echo '<span class="badge bg-secondary">Not Evaluated</span>';
                                            }
                                            ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Activity Log -->
            <div class="data-section" data-section="logs">
                <div class="section-header">
                    <h3 class="section-title">
                        <i class="bi bi-list-ul me-2"></i>
                        Activity Log
                    </h3>
                    <div class="d-flex gap-2">
                        <select class="form-select form-select-sm" id="logFilter" style="width: auto;">
                            <option value="all">All Activities</option>
                            <option value="orders">Orders</option>
                            <option value="status">Status Changes</option>
                            <option value="general">General</option>
                        </select>
                        <button class="btn btn-outline-secondary btn-sm" onclick="refreshLog()">
                            <i class="bi bi-arrow-clockwise"></i>
                            Refresh
                        </button>
                    </div>
                </div>

                <?php if (empty($supplier_logs)): ?>
                <div class="text-center py-4">
                    <i class="bi bi-journal-text" style="font-size: 2rem; color: #9ca3af;"></i>
                    <p class="text-muted mt-2">No activity recorded for this supplier yet</p>
                </div>
                <?php else: ?>
                <div class="log-container" style="max-height: 400px; overflow-y: auto; border: 1px solid #e9ecef; border-radius: 8px; background: #f8f9fa;">
                    <div class="log-timeline" style="padding: 20px;">
                        <?php foreach ($supplier_logs as $index => $log): ?>
                        <div class="log-item" data-category="<?php echo htmlspecialchars($log['category']); ?>" data-activity-type="<?php echo htmlspecialchars($log['activity_type']); ?>">
                            <div class="log-timeline-marker">
                                <?php
                                $icon_class = '';
                                $badge_class = '';
                                switch($log['activity_type']) {
                                    case 'order':
                                        $icon_class = 'bi-receipt';
                                        $badge_class = 'badge-info';
                                        break;
                                    case 'status_change':
                                        $icon_class = 'bi-toggle-on';
                                        $badge_class = 'badge-warning';
                                        break;
                                    case 'supplier_created':
                                        $icon_class = 'bi-plus-circle';
                                        $badge_class = 'badge-success';
                                        break;
                                    default:
                                        $icon_class = 'bi-circle';
                                        $badge_class = 'badge-secondary';
                                }
                                ?>
                                <div class="log-icon <?php echo $badge_class; ?>">
                                    <i class="bi <?php echo $icon_class; ?>"></i>
                                </div>
                            </div>
                            <div class="log-content">
                                <div class="log-header" style="background: white; padding: 16px 20px; border-radius: 10px; box-shadow: 0 2px 6px rgba(0, 0, 0, 0.06); border-left: 4px solid var(--primary-color);">
                                    <div class="log-title">
                                        <span class="badge <?php echo $badge_class; ?> me-2"><?php echo htmlspecialchars($log['activity_name']); ?></span>
                                        <span class="log-description"><?php echo htmlspecialchars($log['description']); ?></span>
                                    </div>
                                    <div class="log-meta" style="font-size: 0.875rem; color: #718096; display: flex; align-items: center; gap: 8px;">
                                        <small class="text-muted">
                                            <i class="bi bi-person me-1"></i>
                                            <?php echo htmlspecialchars($log['performed_by']); ?>
                                            <span class="mx-2">•</span>
                                            <i class="bi bi-clock me-1"></i>
                                            <?php echo date('M j, Y g:i A', strtotime($log['activity_date'])); ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Logs Pagination -->
                <?php if ($total_logs_pages > 1): ?>
                <div class="d-flex justify-content-between align-items-center mt-3">
                    <div class="pagination-info">
                        <small class="text-muted">
                            Showing <?php echo (($logs_page - 1) * $logs_per_page) + 1; ?> to
                            <?php echo min($logs_page * $logs_per_page, $total_logs); ?> of
                            <?php echo $total_logs; ?> activities
                        </small>
                    </div>
                    <nav aria-label="Logs pagination">
                        <ul class="pagination pagination-sm mb-0">
                            <?php if ($logs_page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?id=<?php echo $supplier_id; ?>&products_page=<?php echo $products_page; ?>&orders_page=<?php echo $orders_page; ?>&logs_page=<?php echo $logs_page - 1; ?>">
                                    <i class="bi bi-chevron-left"></i>
                                </a>
                            </li>
                            <?php endif; ?>

                            <?php
                            $start_page = max(1, $logs_page - 2);
                            $end_page = min($total_logs_pages, $logs_page + 2);

                            for ($i = $start_page; $i <= $end_page; $i++):
                            ?>
                            <li class="page-item <?php echo $i == $logs_page ? 'active' : ''; ?>">
                                <a class="page-link" href="?id=<?php echo $supplier_id; ?>&products_page=<?php echo $products_page; ?>&orders_page=<?php echo $orders_page; ?>&logs_page=<?php echo $i; ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                            <?php endfor; ?>

                            <?php if ($logs_page < $total_logs_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?id=<?php echo $supplier_id; ?>&products_page=<?php echo $products_page; ?>&orders_page=<?php echo $orders_page; ?>&logs_page=<?php echo $logs_page + 1; ?>">
                                    <i class="bi bi-chevron-right"></i>
                                </a>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>
                <?php endif; ?>

                <div class="mt-3">
                    <small class="text-muted">
                        <i class="bi bi-info-circle me-1"></i>
                        Activity logs are automatically generated when supplier information changes or orders are created.
                    </small>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/suppliers.js"></script>
    <script>
        // Activity log filtering functionality
        document.addEventListener('DOMContentLoaded', function() {
            const logFilter = document.getElementById('logFilter');
            const logItems = document.querySelectorAll('.log-item');

            if (logFilter) {
                logFilter.addEventListener('change', function() {
                    const selectedFilter = this.value;

                    logItems.forEach(item => {
                        const category = item.dataset.category;

                        let shouldShow = false;

                        if (selectedFilter === 'all') {
                            shouldShow = true;
                        } else if (selectedFilter === 'orders' && category === 'orders') {
                            shouldShow = true;
                        } else if (selectedFilter === 'status' && category === 'status') {
                            shouldShow = true;
                        } else if (selectedFilter === 'general' && category === 'general') {
                            shouldShow = true;
                        }

                        if (shouldShow) {
                            item.style.display = 'flex';
                        } else {
                            item.style.display = 'none';
                        }
                    });
                });
            }
        });

        function refreshLog() {
            window.location.reload();
        }
    </script>
</body>
</html>
