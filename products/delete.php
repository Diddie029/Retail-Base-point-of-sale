<?php
session_start();
require_once __DIR__ . '/../include/db.php';

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

// Helper function to check permissions
function hasPermission($permission, $userPermissions) {
    return in_array($permission, $userPermissions);
}

// Check if user has permission to manage products
if (!hasPermission('manage_products', $permissions)) {
    $_SESSION['error'] = 'You do not have permission to delete products.';
    header("Location: index.php");
    exit();
}

// Handle bulk delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_delete'])) {
    $product_ids = $_POST['product_ids'] ?? [];
    
    if (empty($product_ids)) {
        $_SESSION['error'] = 'No products selected for deletion.';
        header("Location: index.php");
        exit();
    }
    
    $deleted_count = 0;
    $errors = [];
    
    foreach ($product_ids as $product_id) {
        $product_id = (int)$product_id;
        
        // Check if product has sales
        $sales_check = $conn->prepare("SELECT COUNT(*) as count FROM sale_items WHERE product_id = :id");
        $sales_check->bindParam(':id', $product_id);
        $sales_check->execute();
        $has_sales = $sales_check->fetch(PDO::FETCH_ASSOC)['count'] > 0;
        
        if ($has_sales) {
            // Get product name for error message
            $name_stmt = $conn->prepare("SELECT name FROM products WHERE id = :id");
            $name_stmt->bindParam(':id', $product_id);
            $name_stmt->execute();
            $product_name = $name_stmt->fetch(PDO::FETCH_ASSOC)['name'] ?? "Product #$product_id";
            
            $errors[] = "Cannot delete '$product_name' - it has sales history.";
            continue;
        }
        
        // Delete the product
        try {
            $delete_stmt = $conn->prepare("DELETE FROM products WHERE id = :id");
            $delete_stmt->bindParam(':id', $product_id);
            
            if ($delete_stmt->execute()) {
                $deleted_count++;
            }
        } catch (PDOException $e) {
            $errors[] = "Error deleting product #$product_id.";
        }
    }
    
    if ($deleted_count > 0) {
        $_SESSION['success'] = "$deleted_count product(s) deleted successfully.";
    }
    
    if (!empty($errors)) {
        $_SESSION['error'] = implode(' ', $errors);
    }
    
    header("Location: index.php");
    exit();
}

// Handle single product delete
$product_id = (int)($_GET['id'] ?? 0);
if ($product_id <= 0) {
    $_SESSION['error'] = 'Invalid product ID.';
    header("Location: index.php");
    exit();
}

// Get product information
$stmt = $conn->prepare("SELECT * FROM products WHERE id = :id");
$stmt->bindParam(':id', $product_id);
$stmt->execute();
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    $_SESSION['error'] = 'Product not found.';
    header("Location: index.php");
    exit();
}

// Check if product has sales history
$sales_check = $conn->prepare("SELECT COUNT(*) as count FROM sale_items WHERE product_id = :id");
$sales_check->bindParam(':id', $product_id);
$sales_check->execute();
$has_sales = $sales_check->fetch(PDO::FETCH_ASSOC)['count'] > 0;

// Handle delete confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete'])) {
    if ($has_sales) {
        $_SESSION['error'] = "Cannot delete '{$product['name']}' - it has sales history. Products with sales history cannot be deleted to maintain data integrity.";
        header("Location: view.php?id=$product_id");
        exit();
    }
    
    try {
        $delete_stmt = $conn->prepare("DELETE FROM products WHERE id = :id");
        $delete_stmt->bindParam(':id', $product_id);
        
        if ($delete_stmt->execute()) {
            $_SESSION['success'] = "Product '{$product['name']}' has been deleted successfully.";
            header("Location: index.php");
            exit();
        } else {
            $_SESSION['error'] = 'Failed to delete the product. Please try again.';
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = 'An error occurred while deleting the product. Please try again.';
    }
    
    header("Location: view.php?id=$product_id");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delete Product - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/products.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <nav class="sidebar">
        <div class="sidebar-header">
            <h4><i class="bi bi-shop me-2"></i><?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></h4>
            <small>Point of Sale System</small>
        </div>
        <div class="sidebar-nav">
            <div class="nav-item">
                <a href="../dashboard/dashboard.php" class="nav-link">
                    <i class="bi bi-speedometer2"></i>
                    Dashboard
                </a>
            </div>
            
            <?php if (hasPermission('process_sales', $permissions)): ?>
            <div class="nav-item">
                <a href="../pos/index.php" class="nav-link">
                    <i class="bi bi-cart-plus"></i>
                    Point of Sale
                </a>
            </div>
            <?php endif; ?>

            <?php if (hasPermission('manage_products', $permissions)): ?>
            <div class="nav-item">
                <a href="index.php" class="nav-link active">
                    <i class="bi bi-box"></i>
                    Products
                </a>
            </div>
            <div class="nav-item">
                <a href="../categories/categories.php" class="nav-link">
                    <i class="bi bi-tags"></i>
                    Categories
                </a>
            </div>
            <div class="nav-item">
                <a href="../inventory/index.php" class="nav-link">
                    <i class="bi bi-boxes"></i>
                    Inventory
                </a>
            </div>
            <?php endif; ?>

            <?php if (hasPermission('manage_sales', $permissions)): ?>
            <div class="nav-item">
                <a href="../sales/index.php" class="nav-link">
                    <i class="bi bi-receipt"></i>
                    Sales History
                </a>
            </div>
            <?php endif; ?>

            <div class="nav-item">
                <a href="../customers/index.php" class="nav-link">
                    <i class="bi bi-people"></i>
                    Customers
                </a>
            </div>

            <div class="nav-item">

            </div>

            <?php if (hasPermission('manage_users', $permissions)): ?>
            <div class="nav-item">
                <a href="../admin/users/index.php" class="nav-link">
                    <i class="bi bi-person-gear"></i>
                    User Management
                </a>
            </div>
            <?php endif; ?>

            <?php if (hasPermission('manage_settings', $permissions)): ?>
            <div class="nav-item">
                <a href="../admin/settings/adminsetting.php" class="nav-link">
                    <i class="bi bi-gear"></i>
                    Settings
                </a>
            </div>
            <?php endif; ?>

            <div class="nav-item">
                <a href="../auth/logout.php" class="nav-link">
                    <i class="bi bi-box-arrow-right"></i>
                    Logout
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1>Delete Product</h1>
                    <div class="header-subtitle">Confirm product deletion</div>
                </div>
                <div class="header-actions">
                    <a href="view.php?id=<?php echo $product_id; ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i>
                        Back to Product
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
            <div class="data-section">
                <div class="section-header">
                    <h3 class="section-title text-danger">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        Delete Product Confirmation
                    </h3>
                </div>

                <?php if ($has_sales): ?>
                <!-- Cannot Delete Warning -->
                <div class="alert alert-danger">
                    <h5><i class="bi bi-shield-exclamation me-2"></i>Cannot Delete Product</h5>
                    <p class="mb-0">This product cannot be deleted because it has sales history. Deleting products with sales data would compromise your business records and reporting accuracy.</p>
                </div>

                <div class="product-form">
                    <div class="form-row">
                        <div class="col-md-3">
                            <div class="product-image-placeholder">
                                <i class="bi bi-image"></i>
                            </div>
                        </div>
                        <div class="col-md-9">
                            <h4><?php echo htmlspecialchars($product['name']); ?></h4>
                            <p class="text-muted"><?php echo htmlspecialchars($product['description'] ?? 'No description'); ?></p>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <strong>Product ID:</strong> #<?php echo $product['id']; ?><br>
                                    <strong>Barcode:</strong> <code><?php echo htmlspecialchars($product['barcode']); ?></code><br>
                                    <strong>Current Price:</strong> <?php echo $settings['currency_symbol']; ?> <?php echo number_format($product['price'], 2); ?>
                                </div>
                                <div class="col-md-6">
                                    <strong>Current Stock:</strong> <?php echo number_format($product['quantity']); ?> units<br>
                                    <strong>Created:</strong> <?php echo date('M j, Y', strtotime($product['created_at'])); ?><br>
                                    <strong>Last Updated:</strong> <?php echo date('M j, Y', strtotime($product['updated_at'])); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-flex gap-3 justify-content-center">
                        <a href="view.php?id=<?php echo $product_id; ?>" class="btn btn-primary">
                            <i class="bi bi-eye"></i>
                            View Product Details
                        </a>
                        <a href="edit.php?id=<?php echo $product_id; ?>" class="btn btn-warning">
                            <i class="bi bi-pencil"></i>
                            Edit Product Instead
                        </a>
                        <a href="index.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left"></i>
                            Back to Products
                        </a>
                    </div>
                </div>

                <!-- Alternative Actions -->
                <div class="data-section mt-4">
                    <div class="section-header">
                        <h4 class="section-title">
                            <i class="bi bi-lightbulb me-2"></i>
                            Alternative Actions
                        </h4>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="text-center p-3">
                                <i class="bi bi-eye-slash" style="font-size: 2rem; color: var(--warning-color);"></i>
                                <h5 class="mt-2">Discontinue Product</h5>
                                <p class="text-muted">Set stock to 0 to stop selling without losing history</p>
                                <a href="edit.php?id=<?php echo $product_id; ?>" class="btn btn-warning btn-sm">Discontinue</a>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-center p-3">
                                <i class="bi bi-pencil" style="font-size: 2rem; color: var(--primary-color);"></i>
                                <h5 class="mt-2">Update Information</h5>
                                <p class="text-muted">Modify product details, price, or description</p>
                                <a href="edit.php?id=<?php echo $product_id; ?>" class="btn btn-primary btn-sm">Edit Product</a>
                            </div>
                        </div>

                    </div>
                </div>

                <?php else: ?>
                <!-- Delete Confirmation -->
                <div class="alert alert-warning">
                    <h5><i class="bi bi-exclamation-triangle me-2"></i>Warning: This Action Cannot Be Undone</h5>
                    <p class="mb-0">You are about to permanently delete this product. This action cannot be reversed. Make sure you want to proceed.</p>
                </div>

                <div class="product-form">
                    <div class="form-row">
                        <div class="col-md-3">
                            <div class="product-image-placeholder">
                                <i class="bi bi-image"></i>
                            </div>
                        </div>
                        <div class="col-md-9">
                            <h4><?php echo htmlspecialchars($product['name']); ?></h4>
                            <p class="text-muted"><?php echo htmlspecialchars($product['description'] ?? 'No description'); ?></p>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <strong>Product ID:</strong> #<?php echo $product['id']; ?><br>
                                    <strong>Barcode:</strong> <code><?php echo htmlspecialchars($product['barcode']); ?></code><br>
                                    <strong>Current Price:</strong> <?php echo $settings['currency_symbol']; ?> <?php echo number_format($product['price'], 2); ?>
                                </div>
                                <div class="col-md-6">
                                    <strong>Current Stock:</strong> <?php echo number_format($product['quantity']); ?> units<br>
                                    <strong>Created:</strong> <?php echo date('M j, Y', strtotime($product['created_at'])); ?><br>
                                    <strong>Last Updated:</strong> <?php echo date('M j, Y', strtotime($product['updated_at'])); ?>
                                </div>
                            </div>
                            
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i>
                                This product has no sales history, so it can be safely deleted.
                            </div>
                        </div>
                    </div>
                    
                    <form method="POST" class="mt-4">
                        <div class="d-flex gap-3 justify-content-center">
                            <button type="submit" name="confirm_delete" class="btn btn-danger">
                                <i class="bi bi-trash"></i>
                                Yes, Delete This Product
                            </button>
                            <a href="view.php?id=<?php echo $product_id; ?>" class="btn btn-outline-secondary">
                                <i class="bi bi-x"></i>
                                Cancel
                            </a>
                        </div>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/products.js"></script>
</body>
</html>