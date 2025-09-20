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

// Check if user has permission to view products
if (!hasPermission('view_products', $permissions)) {
    header("Location: ../dashboard/dashboard.php");
    exit();
}

// Get product ID
$product_id = (int)($_GET['id'] ?? 0);
if ($product_id <= 0) {
    header("Location: products.php");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get product data with category, brand, and supplier
$stmt = $conn->prepare("
    SELECT p.*, c.name as category_name, b.name as brand_name, s.name as supplier_name
    FROM products p 
    LEFT JOIN categories c ON p.category_id = c.id 
    LEFT JOIN brands b ON p.brand_id = b.id
    LEFT JOIN suppliers s ON p.supplier_id = s.id
    WHERE p.id = :id
");
$stmt->bindParam(':id', $product_id);
$stmt->execute();
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    $_SESSION['error'] = 'Product not found.';
    header("Location: products.php");
    exit();
}

// Pagination parameters
$sales_page = isset($_GET['sales_page']) ? max(1, (int)$_GET['sales_page']) : 1;
$logs_page = isset($_GET['logs_page']) ? max(1, (int)$_GET['logs_page']) : 1;
$sales_per_page = 5;
$logs_per_page = 10;

// Get sales history for this product with pagination
$sales_offset = ($sales_page - 1) * $sales_per_page;
$sales_stmt = $conn->prepare("
    SELECT s.id, s.sale_date, s.customer_name, si.quantity, si.price, 
           (si.quantity * si.price) as total, u.username as cashier
    FROM sale_items si
    JOIN sales s ON si.sale_id = s.id
    LEFT JOIN users u ON s.user_id = u.id
    WHERE si.product_id = :product_id
    ORDER BY s.sale_date DESC
    LIMIT :limit OFFSET :offset
");
$sales_stmt->bindParam(':product_id', $product_id);
$sales_stmt->bindParam(':limit', $sales_per_page, PDO::PARAM_INT);
$sales_stmt->bindParam(':offset', $sales_offset, PDO::PARAM_INT);
$sales_stmt->execute();
$sales_history = $sales_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total sales count for pagination
$sales_count_stmt = $conn->prepare("
    SELECT COUNT(*) as total
    FROM sale_items si
    JOIN sales s ON si.sale_id = s.id
    WHERE si.product_id = :product_id
");
$sales_count_stmt->bindParam(':product_id', $product_id);
$sales_count_stmt->execute();
$total_sales = $sales_count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_sales_pages = ceil($total_sales / $sales_per_page);

// Get sales statistics
$stats_stmt = $conn->prepare("
    SELECT 
        COUNT(si.id) as total_sales,
        SUM(si.quantity) as total_quantity_sold,
        SUM(si.quantity * si.price) as total_revenue,
        AVG(si.price) as avg_selling_price,
        MIN(si.price) as min_price,
        MAX(si.price) as max_price
    FROM sale_items si
    JOIN sales s ON si.sale_id = s.id
    WHERE si.product_id = :product_id
");
$stats_stmt->bindParam(':product_id', $product_id);
$stats_stmt->execute();
$sales_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Get product activity log (using existing tables only)
$product_logs = [];

try {
    // Get sales activities
    $sales_log_stmt = $conn->prepare("
        SELECT 
            'sale' as activity_type,
            'Product Sold' as activity_name,
            CONCAT('Sold ', si.quantity, ' units for ', 
                CONCAT('$', FORMAT(si.price, 2)), 
                ' each (Total: $', FORMAT(si.quantity * si.price, 2), ')'
            ) as description,
            s.sale_date as activity_date,
            COALESCE(u.username, 'Unknown') as performed_by,
            'sales' as category
        FROM sale_items si
        JOIN sales s ON si.sale_id = s.id
        LEFT JOIN users u ON s.user_id = u.id
        WHERE si.product_id = :product_id
        ORDER BY s.sale_date DESC
        LIMIT 20
    ");
    $sales_log_stmt->bindParam(':product_id', $product_id);
    $sales_log_stmt->execute();
    $sales_logs = $sales_log_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get product update activity (simplified - no user tracking for now)
    $product_update_stmt = $conn->prepare("
        SELECT 
            'product_edit' as activity_type,
            'Product Updated' as activity_name,
            CONCAT('Product information updated on ', DATE_FORMAT(updated_at, '%M %d, %Y at %h:%i %p')) as description,
            updated_at as activity_date,
            'System' as performed_by,
            'general' as category
        FROM products
        WHERE id = :product_id AND updated_at != created_at
        ORDER BY updated_at DESC
        LIMIT 5
    ");
    $product_update_stmt->bindParam(':product_id', $product_id);
    $product_update_stmt->execute();
    $product_updates = $product_update_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get product creation activity (simplified)
    $product_creation_stmt = $conn->prepare("
        SELECT 
            'product_created' as activity_type,
            'Product Created' as activity_name,
            CONCAT('Product was created on ', DATE_FORMAT(created_at, '%M %d, %Y at %h:%i %p')) as description,
            created_at as activity_date,
            'System' as performed_by,
            'general' as category
        FROM products
        WHERE id = :product_id
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $product_creation_stmt->bindParam(':product_id', $product_id);
    $product_creation_stmt->execute();
    $product_creation = $product_creation_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Always add at least the product creation log
    if (empty($product_creation)) {
        $product_creation = [[
            'activity_type' => 'product_created',
            'activity_name' => 'Product Created',
            'description' => 'Product was created on ' . date('M d, Y \a\t h:i A', strtotime($product['created_at'])),
            'activity_date' => $product['created_at'],
            'performed_by' => 'System',
            'category' => 'general'
        ]];
    }
    
    // Combine all logs
    $product_logs = array_merge($sales_logs, $product_updates, $product_creation);
    
    // Add some basic product information as log entries
    $basic_logs = [];
    
    // Add product creation log
    $basic_logs[] = [
        'activity_type' => 'product_created',
        'activity_name' => 'Product Created',
        'description' => 'Product was created on ' . date('M d, Y \a\t h:i A', strtotime($product['created_at'])),
        'activity_date' => $product['created_at'],
        'performed_by' => 'System',
        'category' => 'general'
    ];
    
    // Add product update log if it was updated
    if ($product['updated_at'] != $product['created_at']) {
        $basic_logs[] = [
            'activity_type' => 'product_edit',
            'activity_name' => 'Product Updated',
            'description' => 'Product information updated on ' . date('M d, Y \a\t h:i A', strtotime($product['updated_at'])),
            'activity_date' => $product['updated_at'],
            'performed_by' => 'System',
            'category' => 'general'
        ];
    }
    
    // Add current stock status
    $stock_status = '';
    if ($product['quantity'] == 0) {
        $stock_status = 'Out of Stock';
    } elseif ($product['quantity'] <= 10) {
        $stock_status = 'Low Stock Alert';
    } else {
        $stock_status = 'Well Stocked';
    }
    
    $basic_logs[] = [
        'activity_type' => 'stock_status',
        'activity_name' => 'Current Stock Status',
        'description' => 'Current stock: ' . number_format($product['quantity']) . ' units - ' . $stock_status,
        'activity_date' => $product['updated_at'],
        'performed_by' => 'System',
        'category' => 'general'
    ];
    
    // Combine all logs
    $product_logs = array_merge($sales_logs, $product_updates, $product_creation, $basic_logs);
    
    // Ensure we always have at least one log entry
    if (empty($product_logs)) {
        $product_logs = $basic_logs;
    }
    
    // Sort by date (most recent first)
    usort($product_logs, function($a, $b) {
        return strtotime($b['activity_date']) - strtotime($a['activity_date']);
    });
    
    // Apply pagination to logs
    $logs_offset = ($logs_page - 1) * $logs_per_page;
    $total_logs = count($product_logs);
    $total_logs_pages = ceil($total_logs / $logs_per_page);
    $product_logs = array_slice($product_logs, $logs_offset, $logs_per_page);
    
} catch (PDOException $e) {
    // If there's an error, create a basic log entry
    $product_logs = [[
        'activity_type' => 'product_created',
        'activity_name' => 'Product Created',
        'description' => 'Product was created on ' . date('M d, Y \a\t h:i A', strtotime($product['created_at'])),
        'activity_date' => $product['created_at'],
        'performed_by' => 'System',
        'category' => 'general'
    ]];
}

// Handle success message
$success = $_SESSION['success'] ?? '';
unset($_SESSION['success']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($product['name'] ?? 'Product'); ?> - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/products.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
        }
        
        /* Product Log Styles */
        .log-container {
            position: relative;
            scrollbar-width: thin;
            scrollbar-color: #cbd5e0 #f7fafc;
        }
        
        .log-container::-webkit-scrollbar {
            width: 6px;
        }
        
        .log-container::-webkit-scrollbar-track {
            background: #f7fafc;
            border-radius: 3px;
        }
        
        .log-container::-webkit-scrollbar-thumb {
            background: #cbd5e0;
            border-radius: 3px;
        }
        
        .log-container::-webkit-scrollbar-thumb:hover {
            background: #a0aec0;
        }
        
        .log-timeline {
            padding: 20px;
        }
        
        .log-item {
            display: flex;
            margin-bottom: 20px;
            position: relative;
        }
        
        .log-item:not(:last-child)::after {
            content: '';
            position: absolute;
            left: 15px;
            top: 35px;
            bottom: -20px;
            width: 2px;
            background: #e2e8f0;
        }
        
        .log-timeline-marker {
            margin-right: 15px;
            flex-shrink: 0;
        }
        
        .log-icon {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            color: white;
            position: relative;
            z-index: 1;
        }
        
        .log-content {
            flex: 1;
            min-width: 0;
        }
        
        .log-header {
            background: white;
            padding: 12px 16px;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            border-left: 3px solid var(--primary-color);
        }
        
        .log-title {
            margin-bottom: 8px;
        }
        
        .log-description {
            font-weight: 500;
            color: #2d3748;
        }
        
        .log-meta {
            font-size: 0.875rem;
        }
        
        .badge-primary { background-color: #3182ce; }
        .badge-warning { background-color: #d69e2e; }
        .badge-success { background-color: #38a169; }
        .badge-secondary { background-color: #718096; }
        .badge-light { background-color: #e2e8f0; color: #4a5568; }
        
        .log-item[data-category="sales"] .log-header {
            border-left-color: #38a169;
        }
        
        .log-item[data-category="general"] .log-header {
            border-left-color: #718096;
        }
        
        .log-item.hidden {
            display: none;
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .section-title {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 600;
            color: #2d3748;
        }
        
        /* Section Dividers */
        .section-divider {
            display: flex;
            align-items: center;
            margin: 40px 0 30px 0;
            position: relative;
        }
        
        .divider-line {
            flex: 1;
            height: 2px;
            background: linear-gradient(90deg, transparent, #e2e8f0, transparent);
            border: none;
            margin: 0;
        }
        
        .divider-text {
            background: white;
            padding: 8px 20px;
            font-weight: 600;
            color: #4a5568;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border: 2px solid #e2e8f0;
            border-radius: 20px;
            margin: 0 20px;
            white-space: nowrap;
        }
        
        /* Enhanced Data Sections */
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
        
        /* Enhanced Log Items */
        .log-item {
            margin-bottom: 16px;
            transition: all 0.2s ease;
        }
        
        .log-item:hover {
            transform: translateX(4px);
        }
        
        .log-header {
            background: white;
            padding: 16px 20px;
            border-radius: 10px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.06);
            border-left: 4px solid var(--primary-color);
            transition: all 0.2s ease;
        }
        
        .log-header:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .log-title {
            margin-bottom: 10px;
        }
        
        .log-description {
            font-weight: 500;
            color: #2d3748;
            line-height: 1.5;
        }
        
        .log-meta {
            font-size: 0.875rem;
            color: #718096;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .log-meta i {
            font-size: 0.75rem;
        }
        
        /* Enhanced Badges */
        .badge-primary { 
            background: linear-gradient(135deg, #3182ce, #2c5aa0);
            color: white;
            font-weight: 500;
        }
        
        .badge-warning { 
            background: linear-gradient(135deg, #d69e2e, #b7791f);
            color: white;
            font-weight: 500;
        }
        
        .badge-success { 
            background: linear-gradient(135deg, #38a169, #2f855a);
            color: white;
            font-weight: 500;
        }
        
        .badge-secondary { 
            background: linear-gradient(135deg, #718096, #4a5568);
            color: white;
            font-weight: 500;
        }
        
        .badge-info { 
            background: linear-gradient(135deg, #4299e1, #3182ce);
            color: white;
            font-weight: 500;
        }
        
        /* Enhanced Log Icons */
        .log-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            color: white;
            position: relative;
            z-index: 1;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }
        
        .log-item[data-category="sales"] .log-header {
            border-left-color: #38a169;
        }
        
        .log-item[data-category="general"] .log-header {
            border-left-color: #d69e2e;
        }
        
        .log-item[data-category="product_created"] .log-header {
            border-left-color: #3182ce;
        }
        
        /* Enhanced Timeline */
        .log-timeline {
            padding: 25px;
            background: #f8fafc;
        }
        
        .log-container {
            background: #f8fafc;
            border-radius: 12px;
        }
        
        /* Pagination Styles */
        .pagination {
            margin: 0;
        }
        
        .pagination .page-link {
            color: #4a5568;
            border: 1px solid #e2e8f0;
            padding: 0.375rem 0.75rem;
            font-size: 0.875rem;
            transition: all 0.2s ease;
        }
        
        .pagination .page-link:hover {
            color: var(--primary-color);
            background-color: #f8fafc;
            border-color: var(--primary-color);
        }
        
        .pagination .page-item.active .page-link {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
        }
        
        .pagination .page-item.disabled .page-link {
            color: #a0aec0;
            background-color: #f7fafc;
            border-color: #e2e8f0;
        }
        
        .pagination-info {
            font-size: 0.875rem;
            color: #718096;
        }
        
        .pagination-sm .page-link {
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
        }
        
        /* Enhanced pagination layout */
        .d-flex.justify-content-between {
            background: #f8fafc;
            padding: 15px 20px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <?php
    $current_page = 'products';
    include __DIR__ . '/../include/navmenu.php';
    ?>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1><?php echo htmlspecialchars($product['name'] ?? 'Product'); ?></h1>
                    <div class="header-subtitle">Product details and sales information</div>
                </div>
                <div class="header-actions">
                    <a href="edit.php?id=<?php echo $product_id; ?>" class="btn btn-warning">
                        <i class="bi bi-pencil"></i>
                        Edit Product
                    </a>
                    <!-- toggle button moved below into action buttons area -->
                    <a href="products.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i>
                        Back to Products
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

            <!-- Product Information Card -->
            <div class="product-form mb-4">
                <div class="form-row">
                    <div class="col-md-3">
                        <div class="product-image-placeholder" style="width: 200px; height: 200px; font-size: 3rem;">
                            <i class="bi bi-image"></i>
                        </div>
                    </div>
                    <div class="col-md-9">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <h3 class="mb-2"><?php echo htmlspecialchars($product['name'] ?? 'Product'); ?></h3>
                                <p class="text-muted mb-3"><?php echo htmlspecialchars($product['description'] ?? 'No description available'); ?></p>
                                
                                <div class="mb-2">
                                    <strong>Category:</strong> 
                                    <span class="badge badge-secondary"><?php echo htmlspecialchars($product['category_name'] ?? 'Uncategorized'); ?></span>
                                </div>
                                
                                <div class="mb-2">
                                    <strong>Barcode:</strong> 
                                    <code><?php echo htmlspecialchars($product['barcode'] ?? 'Not set'); ?></code>
                                </div>
                                
                                <div class="mb-2">
                                    <strong>Product ID:</strong> 
                                    <span class="text-muted">#<?php echo $product['id']; ?></span>
                                </div>
                                <!-- Additional product metadata -->
                                <div class="mb-2">
                                    <strong>SKU:</strong>
                                    <span class="ms-2">
                                        <code id="sku-value"><?php echo htmlspecialchars($product['sku'] ?? ''); ?></code>
                                        <button type="button" class="copy-sku btn-sm" onclick="copySKU()" title="Copy SKU">
                                            <i class="bi bi-clipboard"></i>
                                            <small class="ms-1">Copy</small>
                                        </button>
                                    </span>
                                </div>
                                <div class="mb-2">
                                    <strong>Product Number:</strong>
                                    <span class="text-muted ms-2"><?php echo htmlspecialchars($product['product_number'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="mb-2">
                                    <strong>Product Type:</strong>
                                    <span class="text-muted ms-2"><?php echo htmlspecialchars(ucfirst($product['product_type'] ?? 'physical')); ?></span>
                                </div>
                                <div class="mb-2">
                                    <strong>Weight / Dimensions:</strong>
                                    <span class="text-muted ms-2"><?php echo htmlspecialchars(($product['weight'] ?? '') . ($product['weight'] ? ' kg' : '')); ?> <?php if (!empty($product['length']) || !empty($product['width']) || !empty($product['height'])): ?>(<?php echo htmlspecialchars(($product['length'] ?? '') . 'x' . ($product['width'] ?? '') . 'x' . ($product['height'] ?? '')); ?> cm)<?php endif; ?></span>
                                </div>
                                <div class="mb-2">
                                    <strong>Tags:</strong>
                                    <span class="text-muted ms-2"><?php echo htmlspecialchars($product['tags'] ?? ''); ?></span>
                                </div>
                                <div class="mb-2">
                                    <strong>Warranty:</strong>
                                    <span class="text-muted ms-2"><?php echo htmlspecialchars($product['warranty_period'] ?? 'N/A'); ?></span>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="stat-card">
                                    <?php if (isProductOnSale($product)): ?>
                                        <?php $sale_info = getProductSaleInfo($product); ?>
                                        <div class="stat-value currency text-danger">
                                            <?php echo $settings['currency_symbol']; ?> <?php echo number_format($sale_info['sale_price'], 2); ?>
                                            <span class="badge badge-danger ms-2">ON SALE</span>
                                        </div>
                                        <div class="stat-label">
                                            <del class="text-muted"><?php echo $settings['currency_symbol']; ?> <?php echo number_format($product['price'], 2); ?></del>
                                            <span class="text-success fw-bold ms-2">-<?php echo $sale_info['discount_percentage']; ?>%</span>
                                        </div>
                                        <div class="mt-1">
                                            <small class="text-muted">
                                                Save <?php echo $settings['currency_symbol']; ?> <?php echo number_format($sale_info['savings'], 2); ?>
                                                <?php if (!empty($sale_info['end_date'])): ?>
                                                    <br>Ends: <?php echo formatSaleDate($sale_info['end_date']); ?>
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                    <?php else: ?>
                                        <div class="stat-value currency"><?php echo $settings['currency_symbol']; ?> <?php echo number_format($product['price'], 2); ?></div>
                                        <div class="stat-label">Current Price</div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="stat-card mt-3">
                                    <div class="stat-value">
                                        <?php echo number_format($product['quantity']); ?>
                                        <?php if ($product['quantity'] == 0): ?>
                                            <span class="badge badge-danger ms-2">Out of Stock</span>
                                        <?php elseif ($product['quantity'] <= 10): ?>
                                            <span class="badge badge-warning ms-2">Low Stock</span>
                                        <?php else: ?>
                                            <span class="badge badge-success ms-2">In Stock</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="stat-label">Current Stock</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="d-flex gap-2">
                            <a href="edit.php?id=<?php echo $product_id; ?>" class="btn btn-warning">
                                <i class="bi bi-pencil"></i>
                                Edit Product
                            </a>
                            <form action="toggle_status.php" method="post" style="display:inline-block; margin-right:6px;">
                                <input type="hidden" name="product_id" value="<?php echo $product_id; ?>">
                                <?php 
                                    $isActive = ($product['status'] ?? 'active') === 'active';
                                    // Admin always allowed; otherwise require manage_products permission
                                    $canToggle = (isset(
                                        $role_name) && $role_name === 'Admin') || hasPermission('manage_products', $permissions);
                                ?>
                                <button type="submit" name="toggle_product" class="btn <?php echo $isActive ? 'btn-warning' : 'btn-success'; ?> btn-rounded" aria-label="<?php echo $isActive ? 'Suspend Product' : 'Activate Product'; ?>" title="<?php echo $canToggle ? ($isActive ? 'Suspend Product' : 'Activate Product') : 'Only Admins or users with Manage Products permission can perform this action'; ?>" <?php echo $canToggle ? '' : 'disabled'; ?>>
                                    <i class="bi <?php echo $isActive ? 'bi-pause-fill' : 'bi-play-fill'; ?>"></i>
                                    <span class="ms-1"><?php echo $isActive ? 'Suspend Product' : 'Activate Product'; ?></span>
                                </button>
                            </form>
                            <a href="delete.php?id=<?php echo $product_id; ?>" 
                               class="btn btn-danger btn-delete" 
                               data-product-name="<?php echo htmlspecialchars($product['name'] ?? 'Product'); ?>">
                                <i class="bi bi-trash"></i>
                                Delete Product
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sale Information -->
            <?php if (!empty($product['sale_price'])): ?>
            <div class="data-section">
                <div class="section-header">
                    <h3 class="section-title">
                        <i class="bi bi-tag me-2"></i>
                        Sale Information
                    </h3>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="info-list">
                            <div class="info-item">
                                <div class="info-label">Sale Price:</div>
                                <div class="info-value currency text-danger fw-bold">
                                    <?php echo $settings['currency_symbol']; ?> <?php echo number_format($product['sale_price'], 2); ?>
                                </div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Regular Price:</div>
                                <div class="info-value currency text-muted">
                                    <del><?php echo $settings['currency_symbol']; ?> <?php echo number_format($product['price'], 2); ?></del>
                                </div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Discount:</div>
                                <div class="info-value text-success fw-bold">
                                    -<?php echo number_format((($product['price'] - $product['sale_price']) / $product['price']) * 100, 1); ?>%
                                    (Save <?php echo $settings['currency_symbol']; ?> <?php echo number_format($product['price'] - $product['sale_price'], 2); ?>)
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="info-list">
                            <div class="info-item">
                                <div class="info-label">Sale Status:</div>
                                <div class="info-value">
                                    <?php if (isProductOnSale($product)): ?>
                                        <span class="badge badge-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary">
                                            <?php if (!empty($product['sale_start_date']) && strtotime($product['sale_start_date']) > time()): ?>
                                                Scheduled - Starts <?php echo formatSaleDate($product['sale_start_date']); ?>
                                            <?php elseif (!empty($product['sale_end_date']) && strtotime($product['sale_end_date']) < time()): ?>
                                                Expired - Ended <?php echo formatSaleDate($product['sale_end_date']); ?>
                                            <?php else: ?>
                                                Inactive
                                            <?php endif; ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if (!empty($product['sale_start_date'])): ?>
                            <div class="info-item">
                                <div class="info-label">Sale Start:</div>
                                <div class="info-value"><?php echo formatSaleDate($product['sale_start_date']); ?></div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($product['sale_end_date'])): ?>
                            <div class="info-item">
                                <div class="info-label">Sale End:</div>
                                <div class="info-value"><?php echo formatSaleDate($product['sale_end_date']); ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Sales Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-primary">
                            <i class="bi bi-graph-up"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($sales_stats['total_sales'] ?? 0); ?></div>
                    <div class="stat-label">Total Sales</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-success">
                            <i class="bi bi-box-seam"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($sales_stats['total_quantity_sold'] ?? 0); ?></div>
                    <div class="stat-label">Units Sold</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-warning">
                            <i class="bi bi-currency-dollar"></i>
                        </div>
                    </div>
                    <div class="stat-value currency"><?php echo $settings['currency_symbol']; ?> <?php echo number_format($sales_stats['total_revenue'] ?? 0, 2); ?></div>
                    <div class="stat-label">Total Revenue</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-info">
                            <i class="bi bi-calculator"></i>
                        </div>
                    </div>
                    <div class="stat-value currency"><?php echo $settings['currency_symbol']; ?> <?php echo number_format($sales_stats['avg_selling_price'] ?? 0, 2); ?></div>
                    <div class="stat-label">Avg. Selling Price</div>
                </div>
            </div>

            <!-- Divider -->
            <div class="section-divider">
                <hr class="divider-line">
                <span class="divider-text">Product Details</span>
                <hr class="divider-line">
            </div>

            <!-- Product Details -->
            <div class="data-section">
                <div class="section-header">
                    <h3 class="section-title">
                        <i class="bi bi-info-circle me-2"></i>
                        Product Information
                    </h3>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <table class="table">
                            <tr>
                                <td><strong>Category:</strong></td>
                                <td><?php echo htmlspecialchars($product['category_name'] ?? 'Uncategorized'); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Brand:</strong></td>
                                <td><?php echo htmlspecialchars($product['brand_name'] ?? 'Not specified'); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Supplier:</strong></td>
                                <td><?php echo htmlspecialchars($product['supplier_name'] ?? 'Not specified'); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Created Date:</strong></td>
                                <td><?php echo date('F j, Y \a\t g:i A', strtotime($product['created_at'])); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Last Updated:</strong></td>
                                <td><?php echo date('F j, Y \a\t g:i A', strtotime($product['updated_at'])); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Current Value:</strong></td>
                                <td class="currency"><?php echo $settings['currency_symbol']; ?> <?php echo number_format($product['price'] * $product['quantity'], 2); ?></td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table">
                            <tr>
                                <td><strong>Price Range:</strong></td>
                                <td>
                                    <?php if ($sales_stats['min_price'] && $sales_stats['max_price']): ?>
                                        <?php echo $settings['currency_symbol']; ?> <?php echo number_format($sales_stats['min_price'], 2); ?> - 
                                        <?php echo $settings['currency_symbol']; ?> <?php echo number_format($sales_stats['max_price'], 2); ?>
                                    <?php else: ?>
                                        <span class="text-muted">No sales data</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Stock Status:</strong></td>
                                <td>
                                    <?php if ($product['quantity'] == 0): ?>
                                        <span class="badge badge-danger">Out of Stock</span>
                                    <?php elseif ($product['quantity'] <= 10): ?>
                                        <span class="badge badge-warning">Low Stock Alert</span>
                                    <?php else: ?>
                                        <span class="badge badge-success">Well Stocked</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Reorder Status:</strong></td>
                                <td>
                                    <?php if ($product['quantity'] <= 5): ?>
                                        <span class="text-danger">Reorder Needed</span>
                                    <?php elseif ($product['quantity'] <= 15): ?>
                                        <span class="text-warning">Monitor Stock</span>
                                    <?php else: ?>
                                        <span class="text-success">Stock OK</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Divider -->
            <div class="section-divider">
                <hr class="divider-line">
                <span class="divider-text">Sales Information</span>
                <hr class="divider-line">
            </div>

            <!-- Recent Sales History -->
            <div class="data-section" data-section="sales">
                <div class="section-header">
                    <h3 class="section-title">
                        <i class="bi bi-clock-history me-2"></i>
                        Recent Sales History
                    </h3>
                    <?php if (hasPermission('manage_sales', $permissions)): ?>
                    <a href="../sales/index.php?product_id=<?php echo $product_id; ?>" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-eye"></i>
                        View All Sales
                    </a>
                    <?php endif; ?>
                </div>
                
                <?php if (empty($sales_history)): ?>
                <div class="text-center py-4">
                    <i class="bi bi-receipt" style="font-size: 3rem; color: #9ca3af;"></i>
                    <p class="text-muted mt-2">No sales recorded for this product yet</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Sale Date</th>
                                <th>Customer</th>
                                <th>Quantity</th>
                                <th>Unit Price</th>
                                <th>Total</th>
                                <th>Cashier</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sales_history as $sale): ?>
                            <tr>
                                <td>
                                    <small><?php echo date('M j, Y g:i A', strtotime($sale['sale_date'])); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($sale['customer_name']); ?></td>
                                <td><?php echo number_format($sale['quantity']); ?></td>
                                <td class="currency"><?php echo $settings['currency_symbol']; ?> <?php echo number_format($sale['price'], 2); ?></td>
                                <td class="currency font-weight-bold"><?php echo $settings['currency_symbol']; ?> <?php echo number_format($sale['total'], 2); ?></td>
                                <td><?php echo htmlspecialchars($sale['cashier'] ?? 'Unknown'); ?></td>
                                <td>
                                    <?php if (hasPermission('manage_sales', $permissions)): ?>
                                    <a href="../sales/view.php?id=<?php echo $sale['id']; ?>" class="btn btn-outline-secondary btn-sm">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Sales Pagination -->
                <?php if ($total_sales_pages > 1): ?>
                <div class="d-flex justify-content-between align-items-center mt-3">
                    <div class="pagination-info">
                        <small class="text-muted">
                            Showing <?php echo (($sales_page - 1) * $sales_per_page) + 1; ?> to 
                            <?php echo min($sales_page * $sales_per_page, $total_sales); ?> of 
                            <?php echo $total_sales; ?> sales
                        </small>
                    </div>
                    <nav aria-label="Sales pagination">
                        <ul class="pagination pagination-sm mb-0">
                            <?php if ($sales_page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?id=<?php echo $product_id; ?>&sales_page=<?php echo $sales_page - 1; ?>&logs_page=<?php echo $logs_page; ?>">
                                    <i class="bi bi-chevron-left"></i>
                                </a>
                            </li>
                            <?php endif; ?>
                            
                            <?php
                            $start_page = max(1, $sales_page - 2);
                            $end_page = min($total_sales_pages, $sales_page + 2);
                            
                            for ($i = $start_page; $i <= $end_page; $i++):
                            ?>
                            <li class="page-item <?php echo $i == $sales_page ? 'active' : ''; ?>">
                                <a class="page-link" href="?id=<?php echo $product_id; ?>&sales_page=<?php echo $i; ?>&logs_page=<?php echo $logs_page; ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                            <?php endfor; ?>
                            
                            <?php if ($sales_page < $total_sales_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?id=<?php echo $product_id; ?>&sales_page=<?php echo $sales_page + 1; ?>&logs_page=<?php echo $logs_page; ?>">
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

            <!-- Divider -->
            <div class="section-divider">
                <hr class="divider-line">
                <span class="divider-text">Activity Log</span>
                <hr class="divider-line">
            </div>

            <!-- Product Activity Log -->
            <div class="data-section" data-section="logs">
                <div class="section-header">
                    <h3 class="section-title">
                        <i class="bi bi-list-ul me-2"></i>
                        Product Activity Log
                    </h3>
                    <div class="d-flex gap-2">
                        <select class="form-select form-select-sm" id="logFilter" style="width: auto;">
                            <option value="all">All Activities</option>
                            <option value="sales">Sales</option>
                            <option value="general">Product Updates</option>
                            <option value="product_created">Product Creation</option>
                        </select>
                        <button class="btn btn-outline-secondary btn-sm" onclick="refreshLog()">
                            <i class="bi bi-arrow-clockwise"></i>
                            Refresh
                        </button>
                    </div>
                </div>
                
                <div class="log-container" style="max-height: 400px; overflow-y: auto; border: 1px solid #e9ecef; border-radius: 8px; background: #f8f9fa;">
                    <?php if (empty($product_logs)): ?>
                    <div class="text-center py-4">
                        <i class="bi bi-journal-text" style="font-size: 2rem; color: #9ca3af;"></i>
                        <p class="text-muted mt-2">No activity recorded for this product yet</p>
                    </div>
                    <?php else: ?>
                    <div class="log-timeline">
                        <?php foreach ($product_logs as $index => $log): ?>
                        <div class="log-item" data-category="<?php echo htmlspecialchars($log['category']); ?>" data-activity-type="<?php echo htmlspecialchars($log['activity_type']); ?>">
                            <div class="log-timeline-marker">
                                <?php
                                $icon_class = '';
                                $badge_class = '';
                                switch($log['activity_type']) {
                                    case 'sale':
                                        $icon_class = 'bi-cart-check';
                                        $badge_class = 'badge-success';
                                        break;
                                    case 'product_edit':
                                        $icon_class = 'bi-pencil-square';
                                        $badge_class = 'badge-warning';
                                        break;
                                    case 'product_created':
                                        $icon_class = 'bi-plus-circle';
                                        $badge_class = 'badge-primary';
                                        break;
                                    case 'stock_status':
                                        $icon_class = 'bi-box-seam';
                                        $badge_class = 'badge-info';
                                        break;
                                    default:
                                        $icon_class = 'bi-circle';
                                        $badge_class = 'badge-light';
                                }
                                ?>
                                <div class="log-icon <?php echo $badge_class; ?>">
                                    <i class="bi <?php echo $icon_class; ?>"></i>
                                </div>
                            </div>
                            <div class="log-content">
                                <div class="log-header">
                                    <div class="log-title">
                                        <span class="badge <?php echo $badge_class; ?> me-2"><?php echo htmlspecialchars($log['activity_name']); ?></span>
                                        <span class="log-description"><?php echo htmlspecialchars($log['description']); ?></span>
                                    </div>
                                    <div class="log-meta">
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
                    <?php endif; ?>
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
                                <a class="page-link" href="?id=<?php echo $product_id; ?>&sales_page=<?php echo $sales_page; ?>&logs_page=<?php echo $logs_page - 1; ?>">
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
                                <a class="page-link" href="?id=<?php echo $product_id; ?>&sales_page=<?php echo $sales_page; ?>&logs_page=<?php echo $i; ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                            <?php endfor; ?>
                            
                            <?php if ($logs_page < $total_logs_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?id=<?php echo $product_id; ?>&sales_page=<?php echo $sales_page; ?>&logs_page=<?php echo $logs_page + 1; ?>">
                                    <i class="bi bi-chevron-right"></i>
                                </a>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>
                
                <div class="mt-3">
                    <small class="text-muted">
                        <i class="bi bi-info-circle me-1"></i>
                        Log entries are automatically generated when product information changes.
                        <?php if (empty($product_logs)): ?>
                        <br><strong>Debug:</strong> No logs found. Product created: <?php echo $product['created_at']; ?>, Updated: <?php echo $product['updated_at']; ?>
                        <?php endif; ?>
                    </small>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/products.js"></script>
    <script>
        // Prevent browser extension conflicts
        if (typeof chrome !== 'undefined' && chrome.runtime && chrome.runtime.onMessage) {
            // Override any problematic message listeners
            const originalAddListener = chrome.runtime.onMessage.addListener;
            if (originalAddListener) {
                chrome.runtime.onMessage.addListener = function(callback) {
                    return originalAddListener.call(this, function(message, sender, sendResponse) {
                        try {
                            const result = callback(message, sender, sendResponse);
                            // Ensure we always send a response to prevent the async error
                            if (result === true) {
                                sendResponse({});
                            }
                            return result;
                        } catch (error) {
                            sendResponse({});
                            return false;
                        }
                    });
                };
            }
        }
    </script>
    <script>
        // Performance optimization: Batch DOM operations to prevent forced reflow
        function batchDOMOperations(operations) {
            // Use requestAnimationFrame to batch all DOM operations
            requestAnimationFrame(() => {
                operations.forEach(operation => {
                    try {
                        operation();
                    } catch (error) {
                        // Silent error handling
                    }
                });
            });
        }

        // Performance optimization: Defer scroll operations
        function deferScrollOperation(operation) {
            requestAnimationFrame(() => {
                requestAnimationFrame(operation);
            });
        }

        function copySKU() {
            const skuEl = document.getElementById('sku-value');
            if (!skuEl) return;
            const sku = skuEl.textContent.trim();
            if (!sku) return alert('No SKU available to copy');

            navigator.clipboard.writeText(sku).then(() => {
                // small visual feedback - use requestAnimationFrame to prevent forced reflow
                requestAnimationFrame(() => {
                    const btn = document.querySelector('.copy-sku');
                    if (btn) {
                        const original = btn.innerHTML;
                        btn.innerHTML = '<i class="bi bi-check2"></i> <small class="ms-1">Copied</small>';
                        setTimeout(() => {
                            requestAnimationFrame(() => {
                                btn.innerHTML = original;
                            });
                        }, 1500);
                    }
                });
            }).catch(() => {
                alert('Unable to copy SKU to clipboard');
            });
        }

        // Product Log functionality
        document.addEventListener('DOMContentLoaded', function() {
            const logFilter = document.getElementById('logFilter');
            const logItems = document.querySelectorAll('.log-item');
            
            if (logFilter) {
                logFilter.addEventListener('change.productLogFilter', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    try {
                        const selectedFilter = this.value;
                        
                        // Use requestAnimationFrame to batch DOM operations and prevent forced reflow
                        requestAnimationFrame(() => {
                            logItems.forEach(item => {
                                const activityType = item.dataset.activityType;
                                
                                let shouldShow = false;
                                
                                if (selectedFilter === 'all') {
                                    shouldShow = true;
                                } else if (selectedFilter === 'sales' && activityType === 'sale') {
                                    shouldShow = true;
                                } else if (selectedFilter === 'general' && activityType === 'product_edit') {
                                    shouldShow = true;
                                } else if (selectedFilter === 'product_created' && activityType === 'product_created') {
                                    shouldShow = true;
                                }
                                
                                if (shouldShow) {
                                    item.classList.remove('hidden');
                                } else {
                                    item.classList.add('hidden');
                                }
                            });
                            
                            // Update scroll position in a separate frame to avoid forced reflow
                            requestAnimationFrame(() => {
                                const logContainer = document.querySelector('.log-container');
                                if (logContainer) {
                                    logContainer.scrollTop = 0;
                                }
                            });
                        });
                    } catch (error) {
                        // Silent error handling
                    }
                });
            }
        });

        function refreshLog() {
            // Reload the page to refresh the log data
            window.location.reload();
        }

        // Enhanced pagination functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Add smooth scrolling to top when pagination is clicked
            const paginationLinks = document.querySelectorAll('.pagination a');
            paginationLinks.forEach(link => {
                link.addEventListener('click.productPagination', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    try {
                        // Add loading state using requestAnimationFrame to prevent forced reflow
                        requestAnimationFrame(() => {
                            const paginationContainer = this.closest('.pagination');
                            if (paginationContainer) {
                                paginationContainer.style.opacity = '0.6';
                            }
                        });
                        
                        // Navigate to the URL
                        window.location.href = this.href;
                    } catch (error) {
                        // Fallback to normal navigation
                        window.location.href = this.href;
                    }
                });
            });
            
            // Add keyboard navigation for pagination
            document.addEventListener('keydown.productPagination', function(e) {
                if (e.ctrlKey || e.metaKey) {
                    try {
                        const currentSalesPage = <?php echo $sales_page; ?>;
                        const currentLogsPage = <?php echo $logs_page; ?>;
                        const totalSalesPages = <?php echo $total_sales_pages; ?>;
                        const totalLogsPages = <?php echo $total_logs_pages; ?>;
                        
                        if (e.key === 'ArrowLeft') {
                            e.preventDefault();
                            e.stopPropagation();
                            if (currentSalesPage > 1) {
                                window.location.href = `?id=<?php echo $product_id; ?>&sales_page=${currentSalesPage - 1}&logs_page=${currentLogsPage}`;
                            }
                        } else if (e.key === 'ArrowRight') {
                            e.preventDefault();
                            e.stopPropagation();
                            if (currentSalesPage < totalSalesPages) {
                                window.location.href = `?id=<?php echo $product_id; ?>&sales_page=${currentSalesPage + 1}&logs_page=${currentLogsPage}`;
                            }
                        }
                    } catch (error) {
                        // Silent error handling
                    }
                }
            });
        });

        // Auto-scroll to bottom of log on page load (optional) - optimized to prevent forced reflow
        document.addEventListener('DOMContentLoaded', function() {
            try {
                // Use requestAnimationFrame to defer scroll operations and prevent forced reflow
                requestAnimationFrame(() => {
                    const logContainer = document.querySelector('.log-container');
                    if (logContainer && logContainer.scrollHeight > logContainer.clientHeight) {
                        // Uncomment the line below if you want to auto-scroll to bottom
                        // logContainer.scrollTop = logContainer.scrollHeight;
                    }
                });
            } catch (error) {
                // Silent error handling
            }
        });

        // Cleanup function to remove event listeners
        function cleanupEventListeners() {
            try {
                // Remove namespaced event listeners
                document.removeEventListener('change.productLogFilter', function() {});
                document.removeEventListener('click.productPagination', function() {});
                document.removeEventListener('keydown.productPagination', function() {});
            } catch (error) {
                // Silent error handling
            }
        }

        // Cleanup on page unload
        window.addEventListener('beforeunload', function() {
            cleanupEventListeners();
        });
    </script>
</body>
</html>