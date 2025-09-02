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

// Check if user has permission to manage product suppliers
if (!hasPermission('manage_product_suppliers', $permissions)) {
    header("Location: ../dashboard/dashboard.php");
    exit();
}

// Get supplier ID from URL
$supplier_id = (int)($_GET['id'] ?? 0);
if (!$supplier_id) {
    header("Location: suppliers.php");
    exit();
}

// Get comprehensive supplier data with analytics
$stmt = $conn->prepare("
    SELECT s.*,
           COUNT(p.id) as total_products,
           COUNT(CASE WHEN p.status = 'active' THEN 1 END) as active_products,
           COUNT(CASE WHEN p.status = 'inactive' THEN 1 END) as inactive_products,
           COUNT(CASE WHEN p.minimum_stock > 0 AND p.quantity <= p.minimum_stock THEN 1 END) as low_stock_products,
           COUNT(CASE WHEN p.quantity = 0 THEN 1 END) as out_of_stock_products,
           AVG(p.price) as avg_product_price,
           SUM(p.quantity * p.price) as total_inventory_value,
           MAX(p.created_at) as latest_product_date,
           MIN(p.created_at) as first_product_date
    FROM suppliers s
    LEFT JOIN products p ON s.id = p.supplier_id
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

// Get recent products for this supplier
$stmt = $conn->prepare("
    SELECT p.id, p.name, p.price, p.quantity, p.status, p.minimum_stock,
           c.name as category_name, b.name as brand_name, p.created_at,
           CASE 
               WHEN p.quantity = 0 THEN 'out_of_stock'
               WHEN p.minimum_stock > 0 AND p.quantity <= p.minimum_stock THEN 'low_stock'
               ELSE 'normal'
           END as stock_status
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN brands b ON p.brand_id = b.id
    WHERE p.supplier_id = :supplier_id
    ORDER BY p.created_at DESC
    LIMIT 10
");
$stmt->bindParam(':supplier_id', $supplier_id);
$stmt->execute();
$recent_products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get supplier performance metrics
$stmt = $conn->prepare("
    SELECT 
        AVG(quality_score) as avg_quality_score,
        AVG(ROUND((on_time_deliveries / NULLIF(total_orders, 0)) * 100, 2)) as avg_on_time_percentage,
        AVG(return_rate) as avg_return_rate,
        AVG(average_delivery_days) as avg_delivery_days,
        SUM(total_orders) as total_orders_90days,
        SUM(total_order_value) as total_order_value_90days,
        MAX(metric_date) as latest_metric_date,
        COUNT(*) as metric_records
    FROM supplier_performance_metrics
    WHERE supplier_id = :supplier_id
      AND metric_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
");
$stmt->bindParam(':supplier_id', $supplier_id);
$stmt->execute();
$performance = $stmt->fetch(PDO::FETCH_ASSOC);

// Get category breakdown
$stmt = $conn->prepare("
    SELECT c.name as category_name, 
           COUNT(p.id) as product_count,
           SUM(p.quantity * p.price) as category_value,
           AVG(p.price) as avg_price
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.supplier_id = :supplier_id AND p.status = 'active'
    GROUP BY c.id, c.name
    ORDER BY product_count DESC
    LIMIT 10
");
$stmt->bindParam(':supplier_id', $supplier_id);
$stmt->execute();
$category_breakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get monthly performance trends (last 12 months)
$stmt = $conn->prepare("
    SELECT 
        DATE_FORMAT(metric_date, '%Y-%m') as month,
        AVG(quality_score) as quality_score,
        AVG(ROUND((on_time_deliveries / NULLIF(total_orders, 0)) * 100, 2)) as on_time_rate,
        AVG(return_rate) as return_rate,
        SUM(total_orders) as orders,
        SUM(total_order_value) as order_value
    FROM supplier_performance_metrics
    WHERE supplier_id = :supplier_id
      AND metric_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(metric_date, '%Y-%m')
    ORDER BY month ASC
");
$stmt->bindParam(':supplier_id', $supplier_id);
$stmt->execute();
$monthly_trends = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get top performing products
$stmt = $conn->prepare("
    SELECT p.id, p.name, p.price, p.quantity,
           c.name as category_name, b.name as brand_name,
           (p.quantity * p.price) as inventory_value
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN brands b ON p.brand_id = b.id
    WHERE p.supplier_id = :supplier_id AND p.status = 'active'
    ORDER BY inventory_value DESC
    LIMIT 5
");
$stmt->bindParam(':supplier_id', $supplier_id);
$stmt->execute();
$top_products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate performance rating
$performance_rating = 'New Supplier';
$rating_class = 'secondary';
if ($performance && $performance['avg_quality_score']) {
    $score = $performance['avg_quality_score'];
    if ($score >= 90) {
        $performance_rating = 'Excellent';
        $rating_class = 'success';
    } elseif ($score >= 80) {
        $performance_rating = 'Very Good';
        $rating_class = 'success';
    } elseif ($score >= 70) {
        $performance_rating = 'Good';
        $rating_class = 'primary';
    } elseif ($score >= 60) {
        $performance_rating = 'Fair';
        $rating_class = 'warning';
    } else {
        $performance_rating = 'Poor';
        $rating_class = 'danger';
    }
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Handle success/error messages
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($supplier['name']); ?> - Supplier Details - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/products.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
        }
        
        /* Analytics specific styles */
        .metric-card {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 0.5rem;
        }
        
        .metric-value {
            font-size: 1.5rem;
            font-weight: 600;
        }
        
        .metric-label {
            font-size: 0.875rem;
            color: #6c757d;
        }
        
        .financial-card {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1rem;
        }
        
        .mini-metric {
            text-align: center;
            padding: 0.75rem;
        }
        
        .mini-metric .value {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-color);
        }
        
        .mini-metric .label {
            font-size: 0.75rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .top-product-item {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 0.75rem;
        }
        
        .rank-badge {
            min-width: 30px;
        }
        
        .chart-container {
            background: white;
            border-radius: 8px;
            padding: 1rem;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
        }
        
        .stat-card {
            background: linear-gradient(135deg, #fff 0%, #f8f9fa 100%);
            border: 1px solid #e9ecef;
            border-radius: 12px;
            padding: 1.25rem;
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }
        
        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            font-size: 0.875rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 500;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <?php
    $current_page = 'suppliers';
    include __DIR__ . '/../include/navmenu.php';
    ?>

    <div class="main-content">
        <!-- Header -->
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1><?php echo htmlspecialchars($supplier['name']); ?></h1>
                    <div class="header-subtitle">Supplier Details & Statistics</div>
                </div>
                <div class="header-actions">
                    <a href="edit.php?id=<?php echo $supplier_id; ?>" class="btn btn-primary">
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
            <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle me-2"></i>
                <?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-circle me-2"></i>
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Supplier Overview -->
            <div class="row mb-4">
                <div class="col-lg-8">
                    <div class="product-form">
                        <div class="form-section">
                            <h4 class="section-title">
                                <i class="bi bi-info-circle me-2"></i>
                                Supplier Information
                            </h4>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Supplier Name</label>
                                        <p class="mb-0"><?php echo htmlspecialchars($supplier['name']); ?></p>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Status</label>
                                        <p class="mb-0">
                                            <span class="badge <?php echo $supplier['is_active'] ? 'badge-success' : 'badge-secondary'; ?>">
                                                <?php echo $supplier['is_active'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <?php if ($supplier['contact_person']): ?>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Contact Person</label>
                                        <p class="mb-0"><?php echo htmlspecialchars($supplier['contact_person']); ?></p>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <?php if ($supplier['email']): ?>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Email</label>
                                        <p class="mb-0">
                                            <a href="mailto:<?php echo htmlspecialchars($supplier['email']); ?>" class="text-decoration-none">
                                                <?php echo htmlspecialchars($supplier['email']); ?>
                                            </a>
                                        </p>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>

                            <div class="row">
                                <?php if ($supplier['phone']): ?>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Phone</label>
                                        <p class="mb-0">
                                            <a href="tel:<?php echo htmlspecialchars($supplier['phone']); ?>" class="text-decoration-none">
                                                <?php echo htmlspecialchars($supplier['phone']); ?>
                                            </a>
                                        </p>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <?php if ($supplier['payment_terms']): ?>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Payment Terms</label>
                                        <p class="mb-0"><?php echo htmlspecialchars($supplier['payment_terms']); ?></p>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>

                            <?php if ($supplier['address']): ?>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Address</label>
                                <p class="mb-0"><?php echo nl2br(htmlspecialchars($supplier['address'])); ?></p>
                            </div>
                            <?php endif; ?>

                            <?php if ($supplier['notes']): ?>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Notes</label>
                                <p class="mb-0"><?php echo nl2br(htmlspecialchars($supplier['notes'])); ?></p>
                            </div>
                            <?php endif; ?>

                            <!-- In-Store Pickup Information -->
                            <?php if (($supplier['pickup_available'] ?? false)): ?>
                            <div class="mb-4">
                                <h5 class="section-title mb-3">
                                    <i class="bi bi-shop me-2"></i>In-Store Pickup
                                </h5>
                                <div class="alert alert-success">
                                    <i class="bi bi-check-circle me-2"></i>
                                    <strong>Pickup Available</strong> - Customers can pick up orders directly from this supplier's store
                                </div>

                                <?php if ($supplier['pickup_address']): ?>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">
                                        <i class="bi bi-geo-alt me-2"></i>Pickup Address
                                    </label>
                                    <p class="mb-0"><?php echo nl2br(htmlspecialchars($supplier['pickup_address'])); ?></p>
                                </div>
                                <?php endif; ?>

                                <div class="row">
                                    <?php if ($supplier['pickup_hours']): ?>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">
                                                <i class="bi bi-clock me-2"></i>Store Hours
                                            </label>
                                            <p class="mb-0"><?php echo htmlspecialchars($supplier['pickup_hours']); ?></p>
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                    <?php if ($supplier['pickup_contact_person'] || $supplier['pickup_contact_phone']): ?>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">
                                                <i class="bi bi-person me-2"></i>Pickup Contact
                                            </label>
                                            <p class="mb-0">
                                                <?php if ($supplier['pickup_contact_person']): ?>
                                                    <?php echo htmlspecialchars($supplier['pickup_contact_person']); ?><br>
                                                <?php endif; ?>
                                                <?php if ($supplier['pickup_contact_phone']): ?>
                                                    <a href="tel:<?php echo htmlspecialchars($supplier['pickup_contact_phone']); ?>" class="text-decoration-none">
                                                        <?php echo htmlspecialchars($supplier['pickup_contact_phone']); ?>
                                                    </a>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <?php if ($supplier['pickup_instructions']): ?>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">
                                        <i class="bi bi-info-circle me-2"></i>Pickup Instructions
                                    </label>
                                    <p class="mb-0"><?php echo nl2br(htmlspecialchars($supplier['pickup_instructions'])); ?></p>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php else: ?>
                            <div class="mb-3">
                                <h5 class="section-title mb-2">
                                    <i class="bi bi-truck me-2"></i>Ordering Method
                                </h5>
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle me-2"></i>
                                    <strong>Delivery Only</strong> - This supplier does not offer in-store pickup. Orders will be delivered.
                                </div>
                            </div>
                            <?php endif; ?>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Created</label>
                                        <p class="mb-0"><?php echo date('M d, Y H:i', strtotime($supplier['created_at'])); ?></p>
                                    </div>
                                </div>
                                <?php if ($supplier['updated_at'] && $supplier['updated_at'] !== $supplier['created_at']): ?>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Last Updated</label>
                                        <p class="mb-0"><?php echo date('M d, Y H:i', strtotime($supplier['updated_at'])); ?></p>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <!-- Statistics Cards -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-value"><?php echo $supplier['total_products']; ?></div>
                            <div class="stat-label">Total Products</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo $supplier['active_products']; ?></div>
                            <div class="stat-label">Active Products</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo $supplier['low_stock_products'] ?? 0; ?></div>
                            <div class="stat-label">Low Stock</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo $supplier['out_of_stock_products'] ?? 0; ?></div>
                            <div class="stat-label">Out of Stock</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo $settings['currency_symbol'] ?? 'KES'; ?> <?php echo number_format($supplier['total_inventory_value'] ?? 0, 2); ?></div>
                            <div class="stat-label">Inventory Value</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo $settings['currency_symbol'] ?? 'KES'; ?> <?php echo number_format($supplier['avg_product_price'] ?? 0, 2); ?></div>
                            <div class="stat-label">Avg Product Price</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Performance Analytics -->
            <?php if ($performance && $performance['metric_records'] > 0): ?>
            <div class="row mb-4">
                <div class="col-lg-6">
                    <div class="product-form">
                        <div class="form-section">
                            <h4 class="section-title">
                                <i class="bi bi-graph-up me-2"></i>
                                Performance Metrics (90 Days)
                            </h4>
                            
                            <!-- Performance Rating -->
                            <div class="mb-4">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5>Overall Performance Rating</h5>
                                    <span class="badge bg-<?php echo $rating_class; ?> fs-6"><?php echo $performance_rating; ?></span>
                                </div>
                                <div class="progress mb-2">
                                    <div class="progress-bar bg-<?php echo $rating_class; ?>" 
                                         style="width: <?php echo ($performance['avg_quality_score'] ?? 0); ?>%">
                                    </div>
                                </div>
                                <small class="text-muted">Quality Score: <?php echo number_format($performance['avg_quality_score'] ?? 0, 1); ?>/100</small>
                            </div>

                            <!-- Key Metrics -->
                            <div class="row">
                                <div class="col-6">
                                    <div class="metric-card text-center">
                                        <div class="metric-value text-primary"><?php echo number_format($performance['avg_on_time_percentage'] ?? 0, 1); ?>%</div>
                                        <div class="metric-label">On-Time Delivery</div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="metric-card text-center">
                                        <div class="metric-value text-success"><?php echo number_format($performance['avg_return_rate'] ?? 0, 1); ?>%</div>
                                        <div class="metric-label">Return Rate</div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="metric-card text-center">
                                        <div class="metric-value text-info"><?php echo number_format($performance['avg_delivery_days'] ?? 0, 1); ?></div>
                                        <div class="metric-label">Avg Delivery Days</div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="metric-card text-center">
                                        <div class="metric-value text-warning"><?php echo number_format($performance['total_orders_90days'] ?? 0); ?></div>
                                        <div class="metric-label">Total Orders</div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mt-3">
                                <small class="text-muted">
                                    <i class="bi bi-info-circle me-1"></i>
                                    Last updated: <?php echo $performance['latest_metric_date'] ? date('M d, Y', strtotime($performance['latest_metric_date'])) : 'No data'; ?>
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-6">
                    <div class="product-form">
                        <div class="form-section">
                            <h4 class="section-title">
                                <i class="bi bi-currency-exchange me-2"></i>
                                Financial Overview
                            </h4>
                            
                            <div class="row">
                                <div class="col-12">
                                    <div class="financial-card">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h6 class="mb-1">Total Order Value (90 Days)</h6>
                                                <h4 class="mb-0 text-success"><?php echo $settings['currency_symbol'] ?? 'KES'; ?> <?php echo number_format($performance['total_order_value_90days'] ?? 0, 2); ?></h4>
                                            </div>
                                            <div class="text-end">
                                                <i class="bi bi-cash-stack fs-2 text-success opacity-25"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row mt-3">
                                <div class="col-6">
                                    <div class="mini-metric">
                                        <div class="value"><?php echo $settings['currency_symbol'] ?? 'KES'; ?> <?php echo number_format(($performance['total_order_value_90days'] ?? 0) / max(($performance['total_orders_90days'] ?? 1), 1), 2); ?></div>
                                        <div class="label">Avg Order Value</div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="mini-metric">
                                        <div class="value"><?php echo number_format(($performance['total_orders_90days'] ?? 0) / 12, 1); ?></div>
                                        <div class="label">Orders/Month</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Category Breakdown -->
            <?php if (!empty($category_breakdown)): ?>
            <div class="row mb-4">
                <div class="col-lg-8">
                    <div class="product-form">
                        <div class="form-section">
                            <h4 class="section-title">
                                <i class="bi bi-pie-chart me-2"></i>
                                Product Categories
                            </h4>
                            
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Category</th>
                                            <th>Products</th>
                                            <th>Avg Price</th>
                                            <th>Total Value</th>
                                            <th>Share</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $total_category_value = array_sum(array_column($category_breakdown, 'category_value'));
                                        foreach ($category_breakdown as $category): 
                                        $share_percentage = $total_category_value > 0 ? ($category['category_value'] / $total_category_value) * 100 : 0;
                                        ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($category['category_name'] ?? 'Uncategorized'); ?></strong></td>
                                            <td><span class="badge bg-primary"><?php echo $category['product_count']; ?></span></td>
                                            <td><?php echo $settings['currency_symbol'] ?? 'KES'; ?> <?php echo number_format($category['avg_price'], 2); ?></td>
                                            <td><?php echo $settings['currency_symbol'] ?? 'KES'; ?> <?php echo number_format($category['category_value'], 2); ?></td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="progress flex-grow-1 me-2" style="height: 8px;">
                                                        <div class="progress-bar" style="width: <?php echo $share_percentage; ?>%"></div>
                                                    </div>
                                                    <small><?php echo number_format($share_percentage, 1); ?>%</small>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-4">
                    <!-- Top Products -->
                    <?php if (!empty($top_products)): ?>
                    <div class="product-form">
                        <div class="form-section">
                            <h4 class="section-title">
                                <i class="bi bi-trophy me-2"></i>
                                Top Products by Value
                            </h4>
                            
                            <?php foreach ($top_products as $index => $product): ?>
                            <div class="top-product-item d-flex align-items-center mb-3">
                                <div class="rank-badge me-3">
                                    <span class="badge bg-<?php echo $index === 0 ? 'warning' : ($index === 1 ? 'secondary' : 'dark'); ?> rounded-circle">
                                        <?php echo $index + 1; ?>
                                    </span>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="fw-bold"><?php echo htmlspecialchars(substr($product['name'], 0, 25)); ?><?php echo strlen($product['name']) > 25 ? '...' : ''; ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars($product['category_name'] ?? 'No Category'); ?></small>
                                    <div class="text-success fw-bold"><?php echo $settings['currency_symbol'] ?? 'KES'; ?> <?php echo number_format($product['inventory_value'], 2); ?></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Performance Trends -->
            <?php if (!empty($monthly_trends)): ?>
            <div class="product-form mb-4">
                <div class="form-section">
                    <h4 class="section-title">
                        <i class="bi bi-graph-up-arrow me-2"></i>
                        Performance Trends (12 Months)
                    </h4>
                    
                    <div class="row">
                        <div class="col-12">
                            <div class="chart-container" style="height: 300px; position: relative;">
                                <canvas id="trendsChart"></canvas>
                            </div>
                        </div>
                    </div>
                    
                    <div class="table-responsive mt-4">
                        <table class="table table-sm">
                            <thead class="table-light">
                                <tr>
                                    <th>Month</th>
                                    <th>Quality Score</th>
                                    <th>On-Time Rate</th>
                                    <th>Return Rate</th>
                                    <th>Orders</th>
                                    <th>Order Value</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_reverse($monthly_trends) as $trend): ?>
                                <tr>
                                    <td><?php echo date('M Y', strtotime($trend['month'] . '-01')); ?></td>
                                    <td><span class="badge bg-<?php echo ($trend['quality_score'] ?? 0) >= 80 ? 'success' : (($trend['quality_score'] ?? 0) >= 60 ? 'warning' : 'danger'); ?>"><?php echo number_format($trend['quality_score'] ?? 0, 1); ?></span></td>
                                    <td><?php echo number_format($trend['on_time_rate'] ?? 0, 1); ?>%</td>
                                    <td><?php echo number_format($trend['return_rate'] ?? 0, 1); ?>%</td>
                                    <td><?php echo number_format($trend['orders'] ?? 0); ?></td>
                                    <td><?php echo $settings['currency_symbol'] ?? 'KES'; ?> <?php echo number_format($trend['order_value'] ?? 0, 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Recent Products -->
            <?php if (!empty($recent_products)): ?>
            <div class="product-form">
                <div class="form-section">
                    <h4 class="section-title">
                        <i class="bi bi-box me-2"></i>
                        Recent Products (<?php echo htmlspecialchars($supplier['name']); ?>)
                    </h4>

                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Product Name</th>
                                    <th>Brand</th>
                                    <th>Category</th>
                                    <th>Price</th>
                                    <th>Stock</th>
                                    <th>Stock Status</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_products as $product): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($product['name']); ?></td>
                                    <td><?php echo htmlspecialchars($product['brand_name'] ?? 'No Brand'); ?></td>
                                    <td><?php echo htmlspecialchars($product['category_name'] ?? 'No Category'); ?></td>
                                    <td><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES') . ' ' . number_format($product['price'], 2); ?></td>
                                    <td><?php echo $product['quantity']; ?></td>
                                    <td>
                                        <?php 
                                        $stock_class = 'success';
                                        $stock_text = 'Normal';
                                        if ($product['stock_status'] === 'out_of_stock') {
                                            $stock_class = 'danger';
                                            $stock_text = 'Out of Stock';
                                        } elseif ($product['stock_status'] === 'low_stock') {
                                            $stock_class = 'warning';
                                            $stock_text = 'Low Stock';
                                        }
                                        ?>
                                        <span class="badge bg-<?php echo $stock_class; ?>"><?php echo $stock_text; ?></span>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $product['status'] === 'active' ? 'badge-success' : 'badge-secondary'; ?>">
                                            <?php echo ucfirst($product['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="../products/view.php?id=<?php echo $product['id']; ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <a href="../products/edit.php?id=<?php echo $product['id']; ?>" class="btn btn-sm btn-outline-secondary">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="text-center mt-3">
                        <a href="../products/products.php?search=&supplier=<?php echo urlencode($supplier['name']); ?>" class="btn btn-primary">
                            <i class="bi bi-eye"></i>
                            View All Products (<?php echo $supplier['total_products']; ?>)
                        </a>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle me-2"></i>
                <strong>No Products Found:</strong> This supplier doesn't have any products yet. 
                <a href="../products/add.php?supplier_id=<?php echo $supplier_id; ?>" class="alert-link">Add a product</a> for this supplier.
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <?php if (!empty($monthly_trends)): ?>
    <script>
        // Performance Trends Chart
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('trendsChart');
            if (ctx) {
                const chart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: <?php echo json_encode(array_map(function($trend) { return date('M Y', strtotime($trend['month'] . '-01')); }, $monthly_trends)); ?>,
                        datasets: [
                            {
                                label: 'Quality Score',
                                data: <?php echo json_encode(array_map(function($trend) { return $trend['quality_score'] ?? 0; }, $monthly_trends)); ?>,
                                borderColor: '#28a745',
                                backgroundColor: 'rgba(40, 167, 69, 0.1)',
                                tension: 0.3,
                                yAxisID: 'y'
                            },
                            {
                                label: 'On-Time Rate (%)',
                                data: <?php echo json_encode(array_map(function($trend) { return $trend['on_time_rate'] ?? 0; }, $monthly_trends)); ?>,
                                borderColor: '#007bff',
                                backgroundColor: 'rgba(0, 123, 255, 0.1)',
                                tension: 0.3,
                                yAxisID: 'y'
                            },
                            {
                                label: 'Return Rate (%)',
                                data: <?php echo json_encode(array_map(function($trend) { return $trend['return_rate'] ?? 0; }, $monthly_trends)); ?>,
                                borderColor: '#dc3545',
                                backgroundColor: 'rgba(220, 53, 69, 0.1)',
                                tension: 0.3,
                                yAxisID: 'y1'
                            },
                            {
                                label: 'Orders',
                                data: <?php echo json_encode(array_map(function($trend) { return $trend['orders'] ?? 0; }, $monthly_trends)); ?>,
                                borderColor: '#ffc107',
                                backgroundColor: 'rgba(255, 193, 7, 0.1)',
                                tension: 0.3,
                                yAxisID: 'y2'
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: {
                            mode: 'index',
                            intersect: false,
                        },
                        scales: {
                            x: {
                                display: true,
                                title: {
                                    display: true,
                                    text: 'Month'
                                }
                            },
                            y: {
                                type: 'linear',
                                display: true,
                                position: 'left',
                                title: {
                                    display: true,
                                    text: 'Quality Score / On-Time Rate (%)'
                                },
                                max: 100,
                                min: 0
                            },
                            y1: {
                                type: 'linear',
                                display: true,
                                position: 'right',
                                title: {
                                    display: true,
                                    text: 'Return Rate (%)'
                                },
                                grid: {
                                    drawOnChartArea: false,
                                },
                            },
                            y2: {
                                type: 'linear',
                                display: false,
                                position: 'right'
                            }
                        },
                        plugins: {
                            title: {
                                display: true,
                                text: 'Supplier Performance Trends'
                            },
                            legend: {
                                display: true,
                                position: 'top'
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        let label = context.dataset.label || '';
                                        if (label) {
                                            label += ': ';
                                        }
                                        if (context.datasetIndex === 3) {
                                            // Orders dataset
                                            label += context.parsed.y;
                                        } else {
                                            // Percentage datasets
                                            label += context.parsed.y.toFixed(1) + '%';
                                        }
                                        return label;
                                    }
                                }
                            }
                        }
                    }
                });
            }
        });
    </script>
    <?php endif; ?>
</body>
</html>
