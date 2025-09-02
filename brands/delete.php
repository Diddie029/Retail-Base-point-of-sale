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

// Check if user has permission to manage product brands
if (!hasPermission('manage_product_brands', $permissions)) {
    header("Location: ../dashboard/dashboard.php");
    exit();
}

// Get brand ID from URL
$brand_id = (int)($_GET['id'] ?? 0);
if (!$brand_id) {
    header("Location: brands.php");
    exit();
}

// Get brand data
$stmt = $conn->prepare("
    SELECT b.*,
           COUNT(p.id) as product_count
    FROM brands b
    LEFT JOIN products p ON b.id = p.brand_id
    WHERE b.id = :id
    GROUP BY b.id
");
$stmt->bindParam(':id', $brand_id);
$stmt->execute();
$brand = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$brand) {
    $_SESSION['error'] = 'Brand not found.';
    header("Location: brands.php");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Handle delete confirmation
if (isset($_POST['confirm_delete'])) {
    try {
        // Check if brand is being used by any products
        if ($brand['product_count'] > 0) {
            $_SESSION['error'] = 'Cannot delete brand "' . $brand['name'] . '" because it is being used by ' . $brand['product_count'] . ' product(s). Please reassign or remove these products first.';
            header("Location: brands.php");
            exit();
        }

        // Delete the brand
        $stmt = $conn->prepare("DELETE FROM brands WHERE id = :id");
        $stmt->bindParam(':id', $brand_id);

        if ($stmt->execute()) {
            // Log the activity
            logActivity($conn, $user_id, 'brand_deleted', "Deleted brand: {$brand['name']} (ID: $brand_id)");

            $_SESSION['success'] = 'Brand "' . $brand['name'] . '" has been deleted successfully.';
        } else {
            $_SESSION['error'] = 'Failed to delete the brand. Please try again.';
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = 'An error occurred while deleting the brand. Please try again.';
        error_log("Brand deletion error: " . $e->getMessage());
    }

    header("Location: brands.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delete Brand - <?php echo htmlspecialchars($brand['name']); ?> - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
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
                <a href="../products/products.php" class="nav-link">
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
                <a href="brands.php" class="nav-link active">
                    <i class="bi bi-star"></i>
                    Brands
                </a>
            </div>
            <div class="nav-item">
                <a href="../suppliers/suppliers.php" class="nav-link">
                    <i class="bi bi-truck"></i>
                    Suppliers
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
                    <h1>Delete Brand</h1>
                    <div class="header-subtitle">Confirm deletion of <?php echo htmlspecialchars($brand['name']); ?></div>
                </div>
                <div class="header-actions">
                    <a href="view.php?id=<?php echo $brand_id; ?>" class="btn btn-outline-primary">
                        <i class="bi bi-eye"></i>
                        View Brand
                    </a>
                    <a href="brands.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i>
                        Back to Brands
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
            <div class="product-form">
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <strong>Warning!</strong> This action cannot be undone. Are you sure you want to delete this brand?
                </div>

                <!-- Brand Information -->
                <div class="form-section">
                    <h4 class="section-title">
                        <i class="bi bi-info-circle me-2"></i>
                        Brand Information
                    </h4>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Brand Name</label>
                                <p class="mb-0"><?php echo htmlspecialchars($brand['name']); ?></p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Status</label>
                                <p class="mb-0">
                                    <span class="badge <?php echo $brand['is_active'] ? 'badge-success' : 'badge-secondary'; ?>">
                                        <?php echo $brand['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </p>
                            </div>
                        </div>
                    </div>

                    <?php if ($brand['description']): ?>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Description</label>
                        <p class="mb-0"><?php echo nl2br(htmlspecialchars($brand['description'])); ?></p>
                    </div>
                    <?php endif; ?>

                    <?php if ($brand['website']): ?>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Website</label>
                        <p class="mb-0">
                            <a href="<?php echo htmlspecialchars($brand['website']); ?>" target="_blank">
                                <?php echo htmlspecialchars($brand['website']); ?>
                            </a>
                        </p>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Impact Assessment -->
                <div class="form-section">
                    <h4 class="section-title">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        Impact Assessment
                    </h4>

                    <?php if ($brand['product_count'] > 0): ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong>Cannot Delete:</strong> This brand is currently being used by <?php echo $brand['product_count']; ?> product(s).
                        You must reassign or remove these products before deleting this brand.
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Affected Products:</label>
                        <div class="border rounded p-3 bg-light">
                            <?php
                            $stmt = $conn->prepare("
                                SELECT p.name, p.id
                                FROM products p
                                WHERE p.brand_id = :brand_id
                                ORDER BY p.name
                                LIMIT 10
                            ");
                            $stmt->bindParam(':brand_id', $brand['id']);
                            $stmt->execute();
                            $affected_products = $stmt->fetchAll(PDO::FETCH_ASSOC);

                            if (!empty($affected_products)): ?>
                                <ul class="mb-0">
                                    <?php foreach ($affected_products as $product): ?>
                                        <li>
                                            <a href="../products/view.php?id=<?php echo $product['id']; ?>" target="_blank">
                                                <?php echo htmlspecialchars($product['name']); ?>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                    <?php if ($brand['product_count'] > 10): ?>
                                        <li><em>... and <?php echo $brand['product_count'] - 10; ?> more products</em></li>
                                    <?php endif; ?>
                                </ul>
                            <?php else: ?>
                                <p class="mb-0 text-muted">No products found.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="text-center">
                        <a href="../products/products.php?search=&brand=<?php echo urlencode($brand['name']); ?>" class="btn btn-warning" target="_blank">
                            <i class="bi bi-eye"></i>
                            View Affected Products
                        </a>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Safe to Delete:</strong> This brand is not currently being used by any products.
                        Deleting it will not affect your inventory.
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Action Buttons -->
                <div class="form-group">
                    <div class="d-flex gap-3 justify-content-center">
                        <?php if ($brand['product_count'] == 0): ?>
                        <form method="POST" class="d-inline">
                            <button type="submit" name="confirm_delete" class="btn btn-danger"
                                    onclick="return confirm('Are you absolutely sure you want to delete this brand? This action cannot be undone.')">
                                <i class="bi bi-trash"></i>
                                Yes, Delete Brand
                            </button>
                        </form>
                        <?php else: ?>
                        <button type="button" class="btn btn-secondary" disabled>
                            <i class="bi bi-x-circle"></i>
                            Cannot Delete (In Use)
                        </button>
                        <?php endif; ?>

                        <a href="view.php?id=<?php echo $brand_id; ?>" class="btn btn-outline-primary">
                            <i class="bi bi-eye"></i>
                            View Brand
                        </a>

                        <a href="edit.php?id=<?php echo $brand_id; ?>" class="btn btn-outline-secondary">
                            <i class="bi bi-pencil"></i>
                            Edit Brand
                        </a>

                        <a href="brands.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left"></i>
                            Cancel
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
