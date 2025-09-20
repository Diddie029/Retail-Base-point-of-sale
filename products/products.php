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

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Handle search and filters
$search = $_GET['search'] ?? '';
$category_filter = $_GET['category'] ?? '';
$status_filter = $_GET['status'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));

// Get user preference for items per page, default to 50
$per_page_options = [10, 20, 50, 100];
$user_per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 50;

// Validate per_page value
if (!in_array($user_per_page, $per_page_options)) {
    $user_per_page = 50;
}

$per_page = $user_per_page;
$offset = ($page - 1) * $per_page;

// Build WHERE clause
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(p.name LIKE :search OR p.barcode LIKE :search)";
    $params[':search'] = "%$search%";
}

if (!empty($category_filter)) {
    $where_conditions[] = "p.category_id = :category_id";
    $params[':category_id'] = $category_filter;
}

if ($status_filter === 'low_stock') {
    $where_conditions[] = "p.quantity <= 10";
} elseif ($status_filter === 'out_of_stock') {
    $where_conditions[] = "p.quantity = 0";
} elseif ($status_filter === 'in_stock') {
    $where_conditions[] = "p.quantity > 10";
}

// Notice filter
$notice_filter = $_GET['notice'] ?? '';
if ($notice_filter === 'supplier_blocked') {
    $where_conditions[] = "s.is_active = 0 AND s.supplier_block_note IS NOT NULL";
} elseif ($notice_filter === 'supplier_notice') {
    $where_conditions[] = "s.is_active = 1 AND s.supplier_block_note LIKE 'Notice Issued:%'";
} elseif ($notice_filter === 'no_notices') {
    $where_conditions[] = "(s.is_active = 1 AND (s.supplier_block_note IS NULL OR s.supplier_block_note = ''))";
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get total count for pagination
$count_sql = "SELECT COUNT(*) as total FROM products p $where_clause";
$count_stmt = $conn->prepare($count_sql);
foreach ($params as $key => $value) {
    $count_stmt->bindValue($key, $value);
}
$count_stmt->execute();
$total_products = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_products / $per_page);

// Get products
$sql = "
    SELECT p.*, c.name as category_name, b.name as brand_name, s.name as supplier_name,
           s.is_active as supplier_active, s.supplier_block_note,
           p.is_auto_bom_enabled, p.auto_bom_type
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN brands b ON p.brand_id = b.id
    LEFT JOIN suppliers s ON p.supplier_id = s.id
    $where_clause
    ORDER BY p.created_at DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $conn->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle bulk operations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $bulk_action = $_POST['bulk_action'];
    $product_ids = isset($_POST['product_ids']) ? $_POST['product_ids'] : [];

    if (empty($product_ids)) {
        $_SESSION['error'] = 'No products selected for bulk operation.';
        header("Location: products.php");
        exit();
    }

    try {
        $conn->beginTransaction();
        $affected_count = 0;

        switch ($bulk_action) {
            case 'activate':
                $stmt = $conn->prepare("UPDATE products SET status = 'active', updated_at = NOW() WHERE id = ?");
                foreach ($product_ids as $product_id) {
                    $stmt->execute([$product_id]);
                    $affected_count += $stmt->rowCount();
                }
                $_SESSION['success'] = "Activated $affected_count products successfully.";
                logActivity($conn, $user_id, 'bulk_activate_products', "Activated $affected_count products");
                break;

            case 'deactivate':
                $stmt = $conn->prepare("UPDATE products SET status = 'inactive', updated_at = NOW() WHERE id = ?");
                foreach ($product_ids as $product_id) {
                    $stmt->execute([$product_id]);
                    $affected_count += $stmt->rowCount();
                }
                $_SESSION['success'] = "Deactivated $affected_count products successfully.";
                logActivity($conn, $user_id, 'bulk_deactivate_products', "Deactivated $affected_count products");
                break;

            case 'delete':
                // Check permissions
                if (!hasPermission('manage_products', $permissions)) {
                    throw new Exception('You do not have permission to delete products.');
                }

                $stmt = $conn->prepare("DELETE FROM products WHERE id = ?");
                foreach ($product_ids as $product_id) {
                    $stmt->execute([$product_id]);
                    $affected_count += $stmt->rowCount();
                }
                $_SESSION['success'] = "Deleted $affected_count products successfully.";
                logActivity($conn, $user_id, 'bulk_delete_products', "Deleted $affected_count products");
                break;

            case 'bulk_pricing':
                $new_price = isset($_POST['new_price']) ? (float) $_POST['new_price'] : null;
                if ($new_price === null || $new_price < 0) {
                    throw new Exception('Invalid price value.');
                }

                $stmt = $conn->prepare("UPDATE products SET price = ?, updated_at = NOW() WHERE id = ?");
                foreach ($product_ids as $product_id) {
                    $stmt->execute([$new_price, $product_id]);
                    $affected_count += $stmt->rowCount();
                }
                $_SESSION['success'] = "Updated pricing for $affected_count products successfully.";
                logActivity($conn, $user_id, 'bulk_update_pricing', "Updated pricing for $affected_count products to " . formatCurrency($new_price));
                break;

            case 'bulk_category':
                $new_category_id = isset($_POST['new_category_id']) ? (int) $_POST['new_category_id'] : null;
                if (!$new_category_id) {
                    throw new Exception('Please select a category.');
                }

                $stmt = $conn->prepare("UPDATE products SET category_id = ?, updated_at = NOW() WHERE id = ?");
                foreach ($product_ids as $product_id) {
                    $stmt->execute([$new_category_id, $product_id]);
                    $affected_count += $stmt->rowCount();
                }
                $_SESSION['success'] = "Updated category for $affected_count products successfully.";
                logActivity($conn, $user_id, 'bulk_update_category', "Updated category for $affected_count products");
                break;

            case 'bulk_auto_bom':
                $auto_bom_type = isset($_POST['auto_bom_type']) ? $_POST['auto_bom_type'] : 'unit_conversion';

                $stmt = $conn->prepare("UPDATE products SET is_auto_bom_enabled = 1, auto_bom_type = ?, updated_at = NOW() WHERE id = ?");
                foreach ($product_ids as $product_id) {
                    $stmt->execute([$auto_bom_type, $product_id]);
                    $affected_count += $stmt->rowCount();
                }
                $_SESSION['success'] = "Enabled Auto BOM for $affected_count products successfully.";
                logActivity($conn, $user_id, 'bulk_enable_auto_bom', "Enabled Auto BOM for $affected_count products");
                break;

            case 'export_selected':
                // Store selected product IDs in session for export
                $_SESSION['export_product_ids'] = $product_ids;
                header("Location: export_selected_products.php");
                exit();

            default:
                throw new Exception('Invalid bulk action.');
        }

        $conn->commit();

    } catch (Exception $e) {
        $conn->rollBack();
        $_SESSION['error'] = 'Bulk operation failed: ' . $e->getMessage();
        logActivity($conn, $user_id, 'bulk_operation_failed', 'Failed bulk operation: ' . $e->getMessage());
    }

    header("Location: products.php");
    exit();
}

// Handle individual toggle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_product'])) {
    $product_id = intval($_POST['product_id']);

    // Get current status
    $stmt = $conn->prepare("SELECT status FROM products WHERE id = :id");
    $stmt->bindParam(':id', $product_id);
    $stmt->execute();
    $current_status = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($current_status) {
        $new_status = $current_status['status'] === 'active' ? 'inactive' : 'active';

        // Update product status
        $update_stmt = $conn->prepare("UPDATE products SET status = :status WHERE id = :id");
        $update_stmt->bindParam(':status', $new_status);
        $update_stmt->bindParam(':id', $product_id);

        if ($update_stmt->execute()) {
            $_SESSION['success'] = 'Product status updated successfully.';
        } else {
            $_SESSION['error'] = 'Failed to update product status.';
        }
    }

    header("Location: products.php");
    exit();
}

// Get categories for filter
$categories_stmt = $conn->query("SELECT * FROM categories ORDER BY name");
$categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);



// Handle bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $action = sanitizeProductInput($_POST['bulk_action']);
    $product_ids = $_POST['product_ids'] ?? [];

    if (!empty($product_ids) && is_array($product_ids)) {
        $placeholders = str_repeat('?,', count($product_ids) - 1) . '?';

        if ($action === 'activate') {
            $stmt = $conn->prepare("UPDATE products SET status = 'active' WHERE id IN ($placeholders)");
            $stmt->execute($product_ids);
            $_SESSION['success'] = 'Selected products have been activated.';
        } elseif ($action === 'deactivate') {
            $stmt = $conn->prepare("UPDATE products SET status = 'inactive' WHERE id IN ($placeholders)");
            $stmt->execute($product_ids);
            $_SESSION['success'] = 'Selected products have been deactivated.';
        } elseif ($action === 'delete') {
            // Check if products are being used in sales
            $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM sale_items WHERE product_id IN ($placeholders)");
            $check_stmt->execute($product_ids);
            $usage_count = $check_stmt->fetch(PDO::FETCH_ASSOC)['count'];

            if ($usage_count > 0) {
                $_SESSION['error'] = 'Cannot delete products that are being used in sales.';
            } else {
                $stmt = $conn->prepare("DELETE FROM products WHERE id IN ($placeholders)");
                $stmt->execute($product_ids);
                $_SESSION['success'] = 'Selected products have been deleted.';
            }
        }

        header("Location: products.php");
        exit();
    }
}

// Handle export request
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    // Set headers for CSV download
    $filename = 'products_export_' . date('Y-m-d_H-i-s') . '.csv';
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    
    $output = fopen('php://output', 'w');
    
    // CSV Headers
    $headers = ['ID', 'Name', 'Category', 'Price', 'Sale Price', 'Sale Start Date', 'Sale End Date', 'Tax Rate', 'Quantity', 'Barcode', 'SKU', 'Description', 'Created Date', 'Updated Date'];
    fputcsv($output, $headers);
    
    // Get all products for export
    $export_sql = "
        SELECT p.*, c.name as category_name 
        FROM products p 
        LEFT JOIN categories c ON p.category_id = c.id 
        ORDER BY p.name
    ";
    
    $export_stmt = $conn->prepare($export_sql);
    $export_stmt->execute();
    $export_products = $export_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // CSV Data
    foreach ($export_products as $product) {
        $row = [
            $product['id'],
            $product['name'],
            $product['category_name'] ?? 'Uncategorized',
            $product['price'],
            !empty($product['sale_price']) ? $product['sale_price'] : '',
            $product['sale_start_date'] ?? '',
            $product['sale_end_date'] ?? '',
            !empty($product['tax_rate']) ? $product['tax_rate'] : '',
            $product['quantity'],
            $product['barcode'],
            $product['sku'] ?? '',
            $product['description'] ?? '',
            $product['created_at'],
            $product['updated_at']
        ];

        fputcsv($output, $row);
    }
    
    fclose($output);
    exit();
}

// Get statistics
$stats = [];

// Total products
$stmt = $conn->query("SELECT COUNT(*) as count FROM products");
$stats['total_products'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Low stock products
$stmt = $conn->query("SELECT COUNT(*) as count FROM products WHERE quantity <= 10 AND quantity > 0");
$stats['low_stock'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Out of stock products
$stmt = $conn->query("SELECT COUNT(*) as count FROM products WHERE quantity = 0");
$stats['out_of_stock'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Total inventory value
$stmt = $conn->query("SELECT SUM(price * quantity) as total FROM products");
$stats['inventory_value'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

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
    <title>Products - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/products.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
        
        .products-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            margin: -20px -20px 20px -20px;
            border-radius: 8px;
        }
        
        .products-header h1 {
            color: white;
            margin: 0;
            font-size: 2rem;
            font-weight: 600;
        }
        
        .products-header p {
            color: rgba(255, 255, 255, 0.9);
            margin: 0.5rem 0 0 0;
            font-size: 1.1rem;
        }
        
        .products-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .products-stat-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        
        .products-stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        }
        
        .products-stat-value {
            font-size: 2rem;
            font-weight: bold;
            color: #667eea;
            margin-bottom: 0.5rem;
        }
        
        .products-stat-label {
            color: #666;
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        .products-filters-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .products-filters-row {
            display: flex;
            gap: 15px;
            align-items: end;
            flex-wrap: wrap;
        }
        
        .products-filter-group {
            display: flex;
            flex-direction: column;
            min-width: 180px;
        }
        
        .products-filter-label {
            font-weight: 600;
            margin-bottom: 5px;
            color: #333;
            font-size: 0.9rem;
        }
        
        .products-filter-input {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 0.9rem;
        }
        
        .products-filter-input:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.25);
            outline: none;
        }
        
        .action-buttons-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }
        
        .btn-modern {
            padding: 8px 16px;
            border-radius: 6px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s ease;
        }
        
        .btn-modern:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        
        .product-table {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
            margin-bottom: 20px;
        }
        
        .product-table table {
            margin: 0;
        }
        
        .product-table thead {
            background: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
        }
        
        .product-table th {
            padding: 15px 12px;
            font-weight: 600;
            color: #333;
            border-bottom: none;
            font-size: 0.9rem;
        }
        
        .product-table td {
            padding: 12px;
            vertical-align: middle;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .product-table tr:last-child td {
            border-bottom: none;
        }
        
        .product-table tr:hover {
            background-color: #f8f9fa;
        }
        
        .category-selection {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .column-visibility {
            background: white;
            padding: 15px 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            margin-bottom: 15px;
        }
        
        .bulk-actions {
            background: white;
            padding: 15px 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .pagination-wrapper {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
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
                    <h1>Products</h1>
                    <div class="header-subtitle">Manage your product inventory</div>
                </div>
                <div class="header-actions">
                    <div class="user-info">
                        <div class="user-avatar"><?php echo strtoupper(substr($username, 0, 1)); ?></div>
                        <span><?php echo htmlspecialchars($username); ?></span>
                    </div>
                </div>
            </div>
        </header>

        <!-- Content -->
        <div class="content">
            <!-- Products Header -->
            <div class="products-header">
                <h1><i class="bi bi-box-fill"></i> Product Management</h1>
                <p>Manage your product inventory and track stock levels</p>
            </div>
            
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

            <!-- Statistics Cards -->
            <div class="products-stats">
                <div class="products-stat-card">
                    <div class="products-stat-value"><?php echo number_format($stats['total_products']); ?></div>
                    <div class="products-stat-label">Total Products</div>
                </div>
                
                <div class="products-stat-card">
                    <div class="products-stat-value" style="color: #f59e0b;"><?php echo number_format($stats['low_stock']); ?></div>
                    <div class="products-stat-label">Low Stock</div>
                </div>
                
                <div class="products-stat-card">
                    <div class="products-stat-value" style="color: #ef4444;"><?php echo number_format($stats['out_of_stock']); ?></div>
                    <div class="products-stat-label">Out of Stock</div>
                </div>
                
                <div class="products-stat-card">
                    <div class="products-stat-value" style="color: #10b981;"><?php echo $settings['currency_symbol']; ?> <?php echo number_format($stats['inventory_value'], 2); ?></div>
                    <div class="products-stat-label">Inventory Value</div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="action-buttons-row">
                <a href="add.php" class="btn btn-primary btn-modern">
                    <i class="bi bi-plus-circle"></i>
                    Create Product
                </a>
                <a href="../bom/auto_bom_index.php" class="btn btn-secondary btn-modern">
                    <i class="bi bi-gear"></i>
                    Manage Auto BOM
                </a>
                <a href="blocked.php" class="btn btn-warning btn-modern">
                    <i class="bi bi-x-circle"></i>
                    Blocked Products
                </a>
                <a href="import.php" class="btn btn-success btn-modern">
                    <i class="bi bi-upload"></i>
                    Import
                </a>
                <a href="export_products.php" class="btn btn-info btn-modern">
                    <i class="bi bi-download"></i>
                    Export
                </a>
            </div>

            <!-- Filters Section -->
            <div class="products-filters-section">
                <form method="GET" class="products-filters-row">
                    <div class="products-filter-group">
                        <label class="products-filter-label">Search</label>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                               placeholder="Search by name or barcode..." class="products-filter-input">
                    </div>

                    <div class="products-filter-group">
                        <label class="products-filter-label">Category</label>
                        <select name="category" class="products-filter-input">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>" <?php echo $category_filter == $category['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($category['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="products-filter-group">
                        <label class="products-filter-label">Stock Status</label>
                        <select name="status" class="products-filter-input">
                            <option value="">All Status</option>
                            <option value="in_stock" <?php echo $status_filter == 'in_stock' ? 'selected' : ''; ?>>In Stock</option>
                            <option value="low_stock" <?php echo $status_filter == 'low_stock' ? 'selected' : ''; ?>>Low Stock</option>
                            <option value="out_of_stock" <?php echo $status_filter == 'out_of_stock' ? 'selected' : ''; ?>>Out of Stock</option>
                        </select>
                    </div>

                    <div class="products-filter-group">
                        <label class="products-filter-label">Notice Status</label>
                        <select name="notice" class="products-filter-input">
                            <option value="">All Notices</option>
                            <option value="supplier_blocked" <?php echo ($_GET['notice'] ?? '') == 'supplier_blocked' ? 'selected' : ''; ?>>Supplier Blocked</option>
                            <option value="supplier_notice" <?php echo ($_GET['notice'] ?? '') == 'supplier_notice' ? 'selected' : ''; ?>>Supplier Notice</option>
                            <option value="no_notices" <?php echo ($_GET['notice'] ?? '') == 'no_notices' ? 'selected' : ''; ?>>No Notices</option>
                        </select>
                    </div>

                    <div class="products-filter-group">
                        <label class="products-filter-label">Per Page</label>
                        <select name="per_page" class="products-filter-input">
                            <?php foreach ($per_page_options as $option): ?>
                                <option value="<?php echo $option; ?>" <?php echo $per_page == $option ? 'selected' : ''; ?>>
                                    <?php echo $option; ?> items
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="products-filter-group">
                        <label class="products-filter-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-search"></i> Filter
                        </button>
                        <?php if (!empty($search) || !empty($category_filter) || !empty($status_filter) || !empty($_GET['notice']) || $per_page != 50): ?>
                        <a href="products.php" class="btn btn-secondary ms-2">
                            <i class="bi bi-x"></i> Clear
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Bulk Actions -->
            <form method="POST" id="bulkForm">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="bulk-actions" id="bulkActions" style="display: none;">
                        <div class="d-flex gap-2 align-items-center">
                            <span class="text-muted">Selected: <span id="selectedCount">0</span></span>

                            <select class="form-control form-control-sm d-inline-block w-auto" name="bulk_action" required>
                                <option value="">Choose action</option>
                                <option value="activate">Activate</option>
                                <option value="deactivate">Deactivate</option>
                                <option value="delete">Delete</option>
                                <option value="bulk_pricing">Update Pricing</option>
                                <option value="bulk_category">Change Category</option>
                                <option value="bulk_auto_bom">Setup Auto BOM</option>
                                <option value="export_selected">Export Selected</option>
                            </select>

                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="bi bi-check"></i>
                                Apply
                            </button>

                            <button type="button" class="btn btn-outline-secondary btn-sm" id="clearSelection">
                                <i class="bi bi-x-circle"></i>
                                Clear
                            </button>
                        </div>
                    </div>
                    <div class="text-muted">
                        Showing <?php echo count($products); ?> of <?php echo $total_products; ?> products
                    </div>
                </div>

                <!-- Category-Based Selection -->
                <div class="category-selection mb-3" style="display: none;">
                    <div class="alert alert-info">
                        <h6><i class="bi bi-tags me-2"></i>Category-Based Selection</h6>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Select Category</label>
                                <select class="form-control form-control-sm" id="categorySelector">
                                    <option value="">Choose Category</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Action</label>
                                <button type="button" class="btn btn-outline-primary btn-sm w-100" id="selectCategoryProducts">
                                    <i class="bi bi-check-circle"></i> Select All in Category
                                </button>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Filter</label>
                                <button type="button" class="btn btn-outline-secondary btn-sm w-100" id="filterByCategory">
                                    <i class="bi bi-funnel"></i> Filter by Category
                                </button>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">&nbsp;</label>
                                <button type="button" class="btn btn-outline-danger btn-sm w-100" id="hideCategorySelection">
                                    <i class="bi bi-x"></i> Close
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

            <!-- Column Visibility Controls -->
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div class="column-visibility">
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <span class="text-muted me-2 fw-bold">Columns:</span>
                        <div class="form-check form-check-inline">
                            <input type="checkbox" class="form-check-input" id="col-category" checked>
                            <label class="form-check-label small" for="col-category">Category</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input type="checkbox" class="form-check-input" id="col-brand" checked>
                            <label class="form-check-label small" for="col-brand">Brand</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input type="checkbox" class="form-check-input" id="col-supplier" checked>
                            <label class="form-check-label small" for="col-supplier">Supplier</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input type="checkbox" class="form-check-input" id="col-notices" checked>
                            <label class="form-check-label small" for="col-notices">Notices</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input type="checkbox" class="form-check-input" id="col-price" checked>
                            <label class="form-check-label small" for="col-price">Price</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input type="checkbox" class="form-check-input" id="col-quantity" checked>
                            <label class="form-check-label small" for="col-quantity">Quantity</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input type="checkbox" class="form-check-input" id="col-stock-status" checked>
                            <label class="form-check-label small" for="col-stock-status">Stock Status</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input type="checkbox" class="form-check-input" id="col-product-status" checked>
                            <label class="form-check-label small" for="col-product-status">Product Status</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input type="checkbox" class="form-check-input" id="col-serial-number">
                            <label class="form-check-label small" for="col-serial-number">Serial Number</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input type="checkbox" class="form-check-input" id="col-barcode" checked>
                            <label class="form-check-label small" for="col-barcode">Barcode</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input type="checkbox" class="form-check-input" id="col-auto-bom" checked>
                            <label class="form-check-label small" for="col-auto-bom">Auto BOM</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input type="checkbox" class="form-check-input" id="col-created" checked>
                            <label class="form-check-label small" for="col-created">Created</label>
                        </div>
                    </div>
                </div>
                <div class="text-muted">
                    Showing <?php echo count($products); ?> of <?php echo $total_products; ?> products
                </div>
            </div>

            <!-- Products Table -->
            <div class="product-table">
                <table class="table">
                    <thead>
                        <tr>
                            <th>
                                <input type="checkbox" id="selectAll" class="form-check-input">
                                <label for="selectAll" class="visually-hidden">Select All</label>
                            </th>
                            <th class="col-product">Product</th>
                            <th class="col-category">Category</th>
                            <th class="col-brand">Brand</th>
                            <th class="col-supplier">Supplier</th>
                            <th class="col-notices">Notices</th>
                            <th class="col-price">Price</th>
                            <th class="col-quantity">Quantity</th>
                            <th class="col-stock-status">Stock Status</th>
                            <th class="col-product-status">Product Status</th>
                            <th class="col-serial-number" style="display: none;">Serial Number</th>
                            <th class="col-barcode">Barcode</th>
                            <th class="col-auto-bom">Auto BOM</th>
                            <th class="col-created">Created</th>
                            <th class="col-actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($products)): ?>
                        <tr>
                            <td colspan="13" class="text-center">
                                <div class="py-4">
                                    <i class="bi bi-box" style="font-size: 3rem; color: #9ca3af;"></i>
                                    <p class="text-muted mt-2">No products found</p>
                                    <a href="add.php" class="btn btn-primary">Add Your First Product</a>
                                </div>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($products as $product): ?>
                        <tr>
                            <td>
                                <input type="checkbox" name="product_ids[]" value="<?php echo $product['id']; ?>" class="product-checkbox">
                            </td>
                            <td class="col-product">
                                <div class="d-flex align-items-center">
                                    <div class="product-image-placeholder me-3">
                                        <i class="bi bi-image"></i>
                                    </div>
                                    <div>
                                        <div class="font-weight-bold">
                                            <?php echo htmlspecialchars($product['name']); ?>
                                            <?php if (isProductOnSale($product)): ?>
                                                <span class="badge badge-danger ms-2">ON SALE</span>
                                            <?php endif; ?>
                                            <?php if ($product['supplier_id'] && $product['supplier_active'] == 0): ?>
                                                <span class="badge badge-warning ms-2" title="<?php echo htmlspecialchars($product['supplier_block_note'] ?? 'Supplier is blocked'); ?>">
                                                    <i class="bi bi-exclamation-triangle"></i>
                                                    Supplier Blocked
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <small class="text-muted">ID: <?php echo $product['id']; ?></small>
                                        <?php if ($product['supplier_id'] && $product['supplier_active'] == 0): ?>
                                            <br><small class="text-warning">
                                                <i class="bi bi-info-circle"></i>
                                                <?php echo htmlspecialchars($product['supplier_block_note'] ?? 'This product\'s supplier is currently blocked'); ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td class="col-category"><?php echo htmlspecialchars($product['category_name'] ?? 'Uncategorized'); ?></td>
                            <td class="col-brand"><?php echo htmlspecialchars($product['brand_name'] ?? '-'); ?></td>
                            <td class="col-supplier"><?php echo htmlspecialchars($product['supplier_name'] ?? '-'); ?></td>
                            <td class="col-notices">
                                <?php if ($product['supplier_id']): ?>
                                    <?php if ($product['supplier_active'] == 0): ?>
                                        <div class="notice-item">
                                            <span class="badge badge-warning" title="<?php echo htmlspecialchars($product['supplier_block_note'] ?? 'Supplier is blocked'); ?>">
                                                <i class="bi bi-exclamation-triangle"></i>
                                                Supplier Blocked
                                            </span>
                                            <small class="text-warning d-block mt-1">
                                                <i class="bi bi-info-circle"></i>
                                                Product can still be sold
                                            </small>
                                        </div>
                                    <?php elseif ($product['supplier_block_note'] && strpos($product['supplier_block_note'], 'Notice Issued:') !== false): ?>
                                        <div class="notice-item">
                                            <span class="badge badge-info" title="<?php echo htmlspecialchars($product['supplier_block_note']); ?>">
                                                <i class="bi bi-exclamation-circle"></i>
                                                Supplier Notice
                                            </span>
                                            <small class="text-info d-block mt-1">
                                                <?php echo htmlspecialchars(substr($product['supplier_block_note'], 0, 50)) . (strlen($product['supplier_block_note']) > 50 ? '...' : ''); ?>
                                            </small>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="col-price currency">
                                <?php if (isProductOnSale($product)): ?>
                                    <div class="d-flex flex-column">
                                        <span class="text-danger fw-bold"><?php echo $settings['currency_symbol']; ?> <?php echo number_format($product['sale_price'], 2); ?></span>
                                        <small class="text-muted text-decoration-line-through"><?php echo $settings['currency_symbol']; ?> <?php echo number_format($product['price'], 2); ?></small>
                                        <small class="text-success">Save <?php echo number_format((($product['price'] - $product['sale_price']) / $product['price']) * 100, 1); ?>%</small>
                                    </div>
                                <?php else: ?>
                                    <?php echo $settings['currency_symbol']; ?> <?php echo number_format($product['price'], 2); ?>
                                <?php endif; ?>
                            </td>
                            <td class="col-quantity stock-quantity">
                                <?php echo number_format($product['quantity']); ?>
                                <?php if ($product['quantity'] == 0): ?>
                                    <span class="badge badge-danger ms-1">Out of Stock</span>
                                <?php elseif ($product['quantity'] <= 10): ?>
                                    <span class="badge badge-warning ms-1">Low Stock</span>
                                <?php else: ?>
                                    <span class="badge badge-success ms-1">In Stock</span>
                                <?php endif; ?>
                            </td>
                            <td class="col-stock-status">
                                <?php if ($product['quantity'] == 0): ?>
                                    <span class="badge badge-danger">Out of Stock</span>
                                <?php elseif ($product['quantity'] <= 10): ?>
                                    <span class="badge badge-warning">Low Stock</span>
                                <?php else: ?>
                                    <span class="badge badge-success">In Stock</span>
                                <?php endif; ?>
                            </td>
                            <td class="col-product-status">
                                <span class="badge <?php echo $product['status'] === 'active' ? 'badge-success' : ($product['status'] === 'inactive' ? 'badge-secondary' : 'badge-warning'); ?>">
                                    <?php echo ucfirst($product['status']); ?>
                                </span>
                            </td>
                            <td class="col-serial-number" style="display: none;">
                                <?php if ($product['is_serialized']): ?>
                                    <span class="badge badge-info">
                                        <i class="bi bi-upc-scan"></i>
                                        Serialized
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="col-barcode">
                                <code><?php echo htmlspecialchars($product['barcode'] ?? 'Not set'); ?></code>
                            </td>
                            <td class="col-auto-bom">
                                <?php if ($product['is_auto_bom_enabled']): ?>
                                    <div class="auto-bom-indicator">
                                        <span class="badge badge-info" title="Auto BOM Enabled">
                                            <i class="fas fa-cogs"></i> <?php echo ucfirst(str_replace('_', ' ', $product['auto_bom_type'] ?? 'unit_conversion')); ?>
                                        </span>
                                        <?php
                                        // Get selling units count
                                        $stmt = $conn->prepare("
                                            SELECT COUNT(*) as units_count
                                            FROM auto_bom_configs abc
                                            INNER JOIN auto_bom_selling_units su ON abc.id = su.auto_bom_config_id
                                            WHERE abc.product_id = :product_id AND su.status = 'active'
                                        ");
                                        $stmt->execute([':product_id' => $product['id']]);
                                        $units_count = $stmt->fetch(PDO::FETCH_ASSOC)['units_count'];
                                        ?>
                                        <small class="text-muted d-block"><?php echo $units_count; ?> units</small>
                                    </div>
                                <?php else: ?>
                                    <button type="button" class="btn btn-sm btn-outline-primary create-auto-bom"
                                            data-product-id="<?php echo $product['id']; ?>"
                                            data-product-name="<?php echo htmlspecialchars($product['name']); ?>"
                                            title="Create Auto BOM">
                                        <i class="fas fa-plus"></i> Setup
                                    </button>
                                <?php endif; ?>
                            </td>
                            <td class="col-created">
                                <small class="text-muted">
                                    <?php echo date('M j, Y', strtotime($product['created_at'])); ?>
                                </small>
                            </td>
                            <td class="col-actions">
                                <div class="d-flex gap-1">
                                    <a href="view.php?id=<?php echo $product['id']; ?>" class="btn btn-outline-secondary btn-sm" title="View">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <a href="edit.php?id=<?php echo $product['id']; ?>" class="btn btn-warning btn-sm" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <button type="button" class="btn btn-sm <?php echo $product['status'] === 'active' ? 'btn-warning' : 'btn-success'; ?> toggle-status"
                                            data-id="<?php echo $product['id']; ?>"
                                            data-current-status="<?php echo $product['status']; ?>"
                                            title="<?php echo $product['status'] === 'active' ? 'Deactivate Product' : 'Activate Product'; ?>">
                                        <i class="bi <?php echo $product['status'] === 'active' ? 'bi-pause-fill' : 'bi-play-fill'; ?>"></i>
                                    </button>
                                    <a href="delete.php?id=<?php echo $product['id']; ?>"
                                       class="btn btn-danger btn-sm btn-delete"
                                       data-product-name="<?php echo htmlspecialchars($product['name']); ?>"
                                       title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </form>

            <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="pagination-wrapper">
                    <div class="pagination-info">
                        Showing <?php echo number_format($offset + 1); ?> to <?php echo number_format(min($offset + $per_page, $total_products)); ?> 
                        of <?php echo number_format($total_products); ?> products
                    </div>
                    <nav aria-label="Product pagination">
                        <ul class="pagination">
                            <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1, 'per_page' => $per_page])); ?>">
                                    <i class="bi bi-chevron-left"></i>
                                </a>
                            </li>
                            <?php endif; ?>

                            <?php
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);

                            for ($i = $start_page; $i <= $end_page; $i++):
                            ?>
                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i, 'per_page' => $per_page])); ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                            <?php endfor; ?>

                            <?php if ($page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1, 'per_page' => $per_page])); ?>">
                                    <i class="bi bi-chevron-right"></i>
                                </a>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/products.js"></script>
    <script>
        // Toggle product status functionality
        document.addEventListener('DOMContentLoaded', function() {
            const toggleButtons = document.querySelectorAll('.toggle-status');

            toggleButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const productId = this.getAttribute('data-id');
                    const currentStatus = this.getAttribute('data-current-status');
                    const newStatus = currentStatus === 'active' ? 'inactive' : 'active';

                    // Show loading state
                    const originalIcon = this.innerHTML;
                    this.innerHTML = '<i class="bi bi-hourglass-split"></i>';
                    this.disabled = true;

                    // Create form data
                    const formData = new FormData();
                    formData.append('toggle_product', '1');
                    formData.append('product_id', productId);

                    // Send AJAX request
                    fetch('products.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => {
                        if (response.ok) {
                            // Update button appearance
                            if (newStatus === 'active') {
                                this.className = 'btn btn-sm btn-warning toggle-status';
                                this.setAttribute('data-current-status', 'active');
                                this.setAttribute('title', 'Deactivate Product');
                                this.innerHTML = '<i class="bi bi-pause-fill"></i>';
                            } else {
                                this.className = 'btn btn-sm btn-success toggle-status';
                                this.setAttribute('data-current-status', 'inactive');
                                this.setAttribute('title', 'Activate Product');
                                this.innerHTML = '<i class="bi bi-play-fill"></i>';
                            }

                            // Update status badge in the same row
                            const row = this.closest('tr');
                            const statusBadge = row.querySelector('.col-product-status .badge');
                            if (statusBadge) {
                                if (newStatus === 'active') {
                                    statusBadge.className = 'badge badge-success';
                                    statusBadge.textContent = 'Active';
                                } else {
                                    statusBadge.className = 'badge badge-secondary';
                                    statusBadge.textContent = 'Inactive';
                                }
                            }

                            // Show success message
                            showNotification('Product status updated successfully!', 'success');
                        } else {
                            // Revert button state on error
                            this.innerHTML = originalIcon;
                            showNotification('Failed to update product status.', 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        // Revert button state on error
                        this.innerHTML = originalIcon;
                        showNotification('An error occurred while updating product status.', 'error');
                    })
                    .finally(() => {
                        this.disabled = false;
                    });
                });
            });

            // Notification function
            function showNotification(message, type) {
                // Remove existing notifications
                const existingNotifications = document.querySelectorAll('.alert');
                existingNotifications.forEach(notification => notification.remove());

                // Create new notification
                const notification = document.createElement('div');
                notification.className = `alert alert-${type === 'success' ? 'success' : 'danger'} alert-dismissible fade show`;
                notification.innerHTML = `
                    <i class="bi ${type === 'success' ? 'bi-check-circle' : 'bi-exclamation-circle'} me-2"></i>
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                `;

                // Insert at the top of the content area
                const content = document.querySelector('.content');
                if (content) {
                    content.insertBefore(notification, content.firstChild);
                }

                // Auto-dismiss after 3 seconds
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.remove();
                    }
                }, 3000);
            }

            // Column visibility functionality
            const columnCheckboxes = document.querySelectorAll('.column-visibility input[type="checkbox"]');

            columnCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    const column = this.id.replace('col-', '');
                    
                    // Update column visibility
                    toggleColumnVisibility(column, this.checked);
                    
                    // Save preferences
                    saveColumnPreferences();
                });
            });

            function toggleColumnVisibility(column, visible) {
                const tableHeaders = document.querySelectorAll(`th.col-${column}`);
                const tableCells = document.querySelectorAll(`td.col-${column}`);

                tableHeaders.forEach(header => {
                    header.style.display = visible ? '' : 'none';
                });

                tableCells.forEach(cell => {
                    cell.style.display = visible ? '' : 'none';
                });
            }

            // Load saved column preferences from localStorage
            loadColumnPreferences();

            function loadColumnPreferences() {
                const preferences = localStorage.getItem('productColumns');
                if (preferences) {
                    const columns = JSON.parse(preferences);
                    Object.keys(columns).forEach(column => {
                        const checkbox = document.getElementById(`col-${column}`);
                        if (checkbox) {
                            checkbox.checked = columns[column];
                            toggleColumnVisibility(column, columns[column]);
                        }
                    });
                }
            }

            function saveColumnPreferences() {
                const preferences = {};
                document.querySelectorAll('.column-visibility input[type="checkbox"]').forEach(cb => {
                    const column = cb.id.replace('col-', '');
                    preferences[column] = cb.checked;
                });
                localStorage.setItem('productColumns', JSON.stringify(preferences));
            }
        });

        // Auto BOM functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Handle Create Auto BOM button clicks
            document.querySelectorAll('.create-auto-bom').forEach(button => {
                button.addEventListener('click', function() {
                    const productId = this.getAttribute('data-product-id');
                    const productName = this.getAttribute('data-product-name');

                    if (confirm(`Create Auto BOM configuration for "${productName}"?\n\nThis will redirect you to the Auto BOM setup wizard.`)) {
                        window.location.href = `../bom/auto_bom_setup.php?product_id=${productId}`;
                    }
                });
            });
        });

        // Enhanced Multi-Selection and Bulk Operations
        document.addEventListener('DOMContentLoaded', function() {
            let selectedProducts = new Set();
            const selectAllCheckbox = document.getElementById('selectAll');
            const bulkActions = document.getElementById('bulkActions');
            const selectedCount = document.getElementById('selectedCount');
            const categorySelection = document.querySelector('.category-selection');

            // Individual product checkboxes
            document.querySelectorAll('.product-checkbox').forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    const productId = this.value;

                    if (this.checked) {
                        selectedProducts.add(productId);
                    } else {
                        selectedProducts.delete(productId);
                    }

                    updateBulkActionsVisibility();
                    updateSelectAllCheckbox();
                });
            });

            // Select all checkbox
            selectAllCheckbox.addEventListener('change', function() {
                const isChecked = this.checked;
                document.querySelectorAll('.product-checkbox').forEach(checkbox => {
                    checkbox.checked = isChecked;
                    const productId = checkbox.value;

                    if (isChecked) {
                        selectedProducts.add(productId);
                    } else {
                        selectedProducts.clear();
                    }
                });

                updateBulkActionsVisibility();
            });

            // Category-based selection
            document.getElementById('showCategorySelection').addEventListener('click', function() {
                categorySelection.style.display = 'block';
                this.style.display = 'none';
            });

            document.getElementById('hideCategorySelection').addEventListener('click', function() {
                categorySelection.style.display = 'none';
                document.getElementById('showCategorySelection').style.display = 'inline-block';
            });

            // Select all products in category
            document.getElementById('selectCategoryProducts').addEventListener('click', function() {
                const categoryId = document.getElementById('categorySelector').value;
                if (!categoryId) {
                    alert('Please select a category first.');
                    return;
                }

                // Select all visible products in the category
                document.querySelectorAll('.product-checkbox').forEach(checkbox => {
                    const row = checkbox.closest('tr');
                    const categoryCell = row.querySelector('.col-category');
                    if (categoryCell && categoryCell.textContent.trim() === getCategoryName(categoryId)) {
                        checkbox.checked = true;
                        selectedProducts.add(checkbox.value);
                    }
                });

                updateBulkActionsVisibility();
                updateSelectAllCheckbox();
                categorySelection.style.display = 'none';
                document.getElementById('showCategorySelection').style.display = 'inline-block';
            });

            // Filter by category
            document.getElementById('filterByCategory').addEventListener('click', function() {
                const categoryId = document.getElementById('categorySelector').value;
                if (!categoryId) {
                    alert('Please select a category first.');
                    return;
                }

                // Redirect with category filter
                const url = new URL(window.location);
                url.searchParams.set('category', categoryId);
                window.location.href = url.toString();
            });

            // Clear selection
            document.getElementById('clearSelection').addEventListener('click', function() {
                selectedProducts.clear();
                document.querySelectorAll('.product-checkbox').forEach(checkbox => {
                    checkbox.checked = false;
                });
                selectAllCheckbox.checked = false;
                updateBulkActionsVisibility();
            });

            // Bulk action handlers
            document.querySelector('select[name="bulk_action"]').addEventListener('change', function() {
                const action = this.value;
                const selectedIds = Array.from(selectedProducts);

                if (selectedIds.length === 0) {
                    alert('Please select products first.');
                    this.value = '';
                    return;
                }

                switch (action) {
                    case 'bulk_pricing':
                        showBulkPricingModal(selectedIds);
                        break;
                    case 'bulk_category':
                        showBulkCategoryModal(selectedIds);
                        break;
                    case 'bulk_auto_bom':
                        showBulkAutoBOMModal(selectedIds);
                        break;
                    case 'activate':
                    case 'deactivate':
                    case 'delete':
                        submitBulkAction(action, selectedIds);
                        break;
                    case 'export_selected':
                        submitBulkAction(action, selectedIds);
                        break;
                }
            });

            // Utility functions
            function updateBulkActionsVisibility() {
                if (selectedProducts.size > 0) {
                    bulkActions.style.display = 'block';
                    selectedCount.textContent = selectedProducts.size;
                } else {
                    bulkActions.style.display = 'none';
                }
            }

            function updateSelectAllCheckbox() {
                const totalCheckboxes = document.querySelectorAll('.product-checkbox').length;
                const checkedCheckboxes = document.querySelectorAll('.product-checkbox:checked').length;

                selectAllCheckbox.checked = checkedCheckboxes === totalCheckboxes && totalCheckboxes > 0;
                selectAllCheckbox.indeterminate = checkedCheckboxes > 0 && checkedCheckboxes < totalCheckboxes;
            }

            function getCategoryName(categoryId) {
                const categories = <?php echo json_encode($categories); ?>;
                const category = categories.find(cat => cat.id == categoryId);
                return category ? category.name : '';
            }

            function submitBulkAction(action, productIds) {
                const form = document.getElementById('bulkForm');
                const formData = new FormData(form);

                // Clear existing product_ids
                formData.delete('product_ids[]');

                // Add selected product IDs
                productIds.forEach(id => {
                    formData.append('product_ids[]', id);
                });

                // Submit form
                form.submit();
            }

            // Initialize
            updateBulkActionsVisibility();
            updateSelectAllCheckbox();
        });
    </script>

    <!-- Bulk Operation Modals -->
    <!-- Bulk Pricing Modal -->
    <div class="modal fade" id="bulkPricingModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-tags me-2"></i>Bulk Pricing Update</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        This will update the price for all selected products.
                    </div>
                    <div class="mb-3">
                        <label for="bulkPriceInput" class="form-label">New Price</label>
                        <div class="input-group">
                            <span class="input-group-text"><?php echo $settings['currency_symbol']; ?></span>
                            <input type="number" class="form-control" id="bulkPriceInput" name="new_price" step="0.01" min="0" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="confirmBulkPricing">Update Prices</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bulk Category Modal -->
    <div class="modal fade" id="bulkCategoryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-folder me-2"></i>Bulk Category Update</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        This will move all selected products to the chosen category.
                    </div>
                    <div class="mb-3">
                        <label for="bulkCategorySelect" class="form-label">New Category</label>
                        <select class="form-control" id="bulkCategorySelect" name="new_category_id" required>
                            <option value="">Select Category</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="confirmBulkCategory">Update Category</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bulk Auto BOM Modal -->
    <div class="modal fade" id="bulkAutoBOMModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-cogs me-2"></i>Bulk Auto BOM Setup</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        This will enable Auto BOM for all selected products. You can configure individual Auto BOM settings later.
                    </div>
                    <div class="mb-3">
                        <label for="bulkAutoBOMType" class="form-label">Auto BOM Type</label>
                        <select class="form-control" id="bulkAutoBOMType" name="auto_bom_type">
                            <option value="unit_conversion">Unit Conversion</option>
                            <option value="repackaging">Repackaging</option>
                            <option value="bulk_selling">Bulk Selling</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="confirmBulkAutoBOM">Enable Auto BOM</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal JavaScript -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Modal functions
            function showBulkPricingModal(productIds) {
                const modal = new bootstrap.Modal(document.getElementById('bulkPricingModal'));
                modal.show();

                document.getElementById('confirmBulkPricing').onclick = function() {
                    const newPrice = document.getElementById('bulkPriceInput').value;
                    if (!newPrice || newPrice < 0) {
                        alert('Please enter a valid price.');
                        return;
                    }
                    submitBulkAction('bulk_pricing', productIds, { new_price: newPrice });
                    modal.hide();
                };
            }

            function showBulkCategoryModal(productIds) {
                const modal = new bootstrap.Modal(document.getElementById('bulkCategoryModal'));
                modal.show();

                document.getElementById('confirmBulkCategory').onclick = function() {
                    const newCategoryId = document.getElementById('bulkCategorySelect').value;
                    if (!newCategoryId) {
                        alert('Please select a category.');
                        return;
                    }
                    submitBulkAction('bulk_category', productIds, { new_category_id: newCategoryId });
                    modal.hide();
                };
            }

            function showBulkAutoBOMModal(productIds) {
                const modal = new bootstrap.Modal(document.getElementById('bulkAutoBOMModal'));
                modal.show();

                document.getElementById('confirmBulkAutoBOM').onclick = function() {
                    const autoBOMType = document.getElementById('bulkAutoBOMType').value;
                    submitBulkAction('bulk_auto_bom', productIds, { auto_bom_type: autoBOMType });
                    modal.hide();
                };
            }

            // Make functions global for the main JavaScript
            window.showBulkPricingModal = showBulkPricingModal;
            window.showBulkCategoryModal = showBulkCategoryModal;
            window.showBulkAutoBOMModal = showBulkAutoBOMModal;
        });
    </script>

    <style>
        /* Auto BOM Styles */
        .auto-bom-indicator {
            text-align: center;
        }

        .auto-bom-indicator .badge {
            display: inline-block;
            margin-bottom: 4px;
        }

        .create-auto-bom {
            font-size: 0.75rem;
            padding: 2px 6px;
        }

        .create-auto-bom:hover {
            background-color: #667eea !important;
            border-color: #667eea !important;
            color: white !important;
        }
    </style>
</body>
</html>