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

// Get supplier data with product count
$stmt = $conn->prepare("
    SELECT s.*,
           COUNT(p.id) as total_products,
           COUNT(CASE WHEN p.status = 'active' THEN 1 END) as active_products
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
    SELECT p.id, p.name, p.price, p.quantity, p.status, c.name as category_name, b.name as brand_name
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
                            <div class="stat-value"><?php echo $supplier['total_products'] - $supplier['active_products']; ?></div>
                            <div class="stat-label">Inactive Products</div>
                        </div>
                    </div>
                </div>
            </div>

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
                            View All Products
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
