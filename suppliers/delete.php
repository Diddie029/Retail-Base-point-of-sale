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

// Check if user has permission to manage products (includes suppliers)
if (!hasPermission('manage_products', $permissions)) {
    header("Location: ../dashboard/dashboard.php");
    exit();
}

// Get supplier ID from URL
$supplier_id = (int)($_GET['id'] ?? 0);
if (!$supplier_id) {
    header("Location: suppliers.php");
    exit();
}

// Get supplier data
$stmt = $conn->prepare("
    SELECT s.*,
           COUNT(p.id) as product_count
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

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Handle delete confirmation
if (isset($_POST['confirm_delete'])) {
    try {
        // Check if supplier is being used by any products
        if ($supplier['product_count'] > 0) {
            $_SESSION['error'] = 'Cannot delete supplier "' . $supplier['name'] . '" because it is being used by ' . $supplier['product_count'] . ' product(s). Please reassign or remove these products first.';
            header("Location: suppliers.php");
            exit();
        }

        // Delete the supplier
        $stmt = $conn->prepare("DELETE FROM suppliers WHERE id = :id");
        $stmt->bindParam(':id', $supplier_id);

        if ($stmt->execute()) {
            // Log the activity
            logActivity($conn, $user_id, 'supplier_deleted', "Deleted supplier: {$supplier['name']} (ID: $supplier_id)");

            $_SESSION['success'] = 'Supplier "' . $supplier['name'] . '" has been deleted successfully.';
        } else {
            $_SESSION['error'] = 'Failed to delete the supplier. Please try again.';
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = 'An error occurred while deleting the supplier. Please try again.';
        error_log("Supplier deletion error: " . $e->getMessage());
    }

    header("Location: suppliers.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delete Supplier - <?php echo htmlspecialchars($supplier['name']); ?> - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
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
                <a href="../brands/brands.php" class="nav-link">
                    <i class="bi bi-star"></i>
                    Brands
                </a>
            </div>
            <div class="nav-item">
                <a href="suppliers.php" class="nav-link active">
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
                    <h1>Delete Supplier</h1>
                    <div class="header-subtitle">Confirm deletion of <?php echo htmlspecialchars($supplier['name']); ?></div>
                </div>
                <div class="header-actions">
                    <a href="view.php?id=<?php echo $supplier_id; ?>" class="btn btn-outline-primary">
                        <i class="bi bi-eye"></i>
                        View Supplier
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
            <div class="product-form">
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <strong>Warning!</strong> This action cannot be undone. Are you sure you want to delete this supplier?
                </div>

                <!-- Supplier Information -->
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

                    <?php if ($supplier['contact_person']): ?>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Contact Person</label>
                        <p class="mb-0"><?php echo htmlspecialchars($supplier['contact_person']); ?></p>
                    </div>
                    <?php endif; ?>

                    <div class="row">
                        <?php if ($supplier['email']): ?>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Email</label>
                                <p class="mb-0"><?php echo htmlspecialchars($supplier['email']); ?></p>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($supplier['phone']): ?>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Phone</label>
                                <p class="mb-0"><?php echo htmlspecialchars($supplier['phone']); ?></p>
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

                    <?php if ($supplier['payment_terms']): ?>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Payment Terms</label>
                        <p class="mb-0"><?php echo htmlspecialchars($supplier['payment_terms']); ?></p>
                    </div>
                    <?php endif; ?>

                    <?php if ($supplier['notes']): ?>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Notes</label>
                        <p class="mb-0"><?php echo nl2br(htmlspecialchars($supplier['notes'])); ?></p>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Impact Assessment -->
                <div class="form-section">
                    <h4 class="section-title">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        Impact Assessment
                    </h4>

                    <?php if ($supplier['product_count'] > 0): ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong>Cannot Delete:</strong> This supplier is currently being used by <?php echo $supplier['product_count']; ?> product(s).
                        You must reassign or remove these products before deleting this supplier.
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Affected Products:</label>
                        <div class="border rounded p-3 bg-light">
                            <?php
                            $stmt = $conn->prepare("
                                SELECT p.name, p.id
                                FROM products p
                                WHERE p.supplier_id = :supplier_id
                                ORDER BY p.name
                                LIMIT 10
                            ");
                            $stmt->bindParam(':supplier_id', $supplier_id);
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
                                    <?php if ($supplier['product_count'] > 10): ?>
                                        <li><em>... and <?php echo $supplier['product_count'] - 10; ?> more products</em></li>
                                    <?php endif; ?>
                                </ul>
                            <?php else: ?>
                                <p class="mb-0 text-muted">No products found.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="text-center">
                        <a href="../products/products.php?search=&supplier=<?php echo urlencode($supplier['name']); ?>" class="btn btn-warning" target="_blank">
                            <i class="bi bi-eye"></i>
                            View Affected Products
                        </a>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Safe to Delete:</strong> This supplier is not currently being used by any products.
                        Deleting it will not affect your inventory.
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Action Buttons -->
                <div class="form-group">
                    <div class="d-flex gap-3 justify-content-center">
                        <?php if ($supplier['product_count'] == 0): ?>
                        <form method="POST" class="d-inline">
                            <button type="submit" name="confirm_delete" class="btn btn-danger"
                                    onclick="return confirm('Are you absolutely sure you want to delete this supplier? This action cannot be undone.')">
                                <i class="bi bi-trash"></i>
                                Yes, Delete Supplier
                            </button>
                        </form>
                        <?php else: ?>
                        <button type="button" class="btn btn-secondary" disabled>
                            <i class="bi bi-x-circle"></i>
                            Cannot Delete (In Use)
                        </button>
                        <?php endif; ?>

                        <a href="view.php?id=<?php echo $supplier_id; ?>" class="btn btn-outline-primary">
                            <i class="bi bi-eye"></i>
                            View Supplier
                        </a>

                        <a href="edit.php?id=<?php echo $supplier_id; ?>" class="btn btn-outline-secondary">
                            <i class="bi bi-pencil"></i>
                            Edit Supplier
                        </a>

                        <a href="suppliers.php" class="btn btn-outline-secondary">
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
