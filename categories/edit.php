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

// Check if user has permission to manage categories
if (!hasPermission('manage_categories', $permissions)) {
    header("Location: ../dashboard/dashboard.php");
    exit();
}

// Get category ID
$category_id = (int)($_GET['id'] ?? 0);
if ($category_id <= 0) {
    header("Location: categories.php");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get category data
$stmt = $conn->prepare("SELECT * FROM categories WHERE id = :id");
$stmt->bindParam(':id', $category_id);
$stmt->execute();
$category = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$category) {
    $_SESSION['error'] = 'Category not found.';
    header("Location: categories.php");
    exit();
}

// Get product count for this category
$product_stmt = $conn->prepare("SELECT COUNT(*) as count FROM products WHERE category_id = :id");
$product_stmt->bindParam(':id', $category_id);
$product_stmt->execute();
$product_count = $product_stmt->fetch(PDO::FETCH_ASSOC)['count'];

$errors = [];
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    
    // Validation - name is required
    if (empty($name)) {
        $errors['name'] = 'Category name is required';
    } elseif (strlen($name) > 100) {
        $errors['name'] = 'Category name must be 100 characters or less';
    }
    
    // Description is optional but has length limit
    if (!empty($description) && strlen($description) > 500) {
        $errors['description'] = 'Description must be 500 characters or less';
    }
    
    // Check if category name already exists (excluding current category)
    if (empty($errors['name'])) {
        $check_stmt = $conn->prepare("SELECT id FROM categories WHERE name = :name AND id != :id");
        $check_stmt->bindParam(':name', $name);
        $check_stmt->bindParam(':id', $category_id);
        $check_stmt->execute();
        if ($check_stmt->fetch()) {
            $errors['name'] = 'A category with this name already exists';
        }
    }
    
    // If no errors, update the category
    if (empty($errors)) {
        try {
            $update_stmt = $conn->prepare("
                UPDATE categories 
                SET name = :name, description = :description, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            
            $update_stmt->bindParam(':name', $name);
            $update_stmt->bindParam(':description', $description);
            $update_stmt->bindParam(':id', $category_id);
            
            if ($update_stmt->execute()) {
                $_SESSION['success'] = "Category '$name' has been updated successfully!";
                header("Location: categories.php");
                exit();
            }
        } catch (PDOException $e) {
            $errors['general'] = 'An error occurred while updating the category. Please try again.';
        }
    }
    
    // Update category array with POST data for form repopulation
    $category['name'] = $name;
    $category['description'] = $description;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Category - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/categories.css">
    <!-- Debug: Current settings - Theme: <?php echo $settings['theme_color'] ?? 'not set'; ?>, Sidebar: <?php echo $settings['sidebar_color'] ?? 'not set'; ?> -->
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
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
                <a href="categories.php" class="nav-link active">
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
                <a href="../reports/index.php" class="nav-link">
                    <i class="bi bi-graph-up"></i>
                    Reports
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
                    <h1>Edit Category</h1>
                    <div class="header-subtitle">Update category information</div>
                </div>
                <div class="header-actions">
                    <a href="categories.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i>
                        Back to Categories
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
            <?php if (isset($errors['general'])): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-circle me-2"></i>
                <?php echo htmlspecialchars($errors['general']); ?>
            </div>
            <?php endif; ?>

            <!-- Category Info Card -->
            <div class="data-section mb-4">
                <div class="section-header">
                    <h3 class="section-title">
                        <i class="bi bi-tag me-2"></i>
                        <?php echo htmlspecialchars($category['name']); ?>
                    </h3>
                    <span class="badge badge-secondary">ID: <?php echo $category['id']; ?></span>
                </div>
                <div class="row">
                    <div class="col-md-3">
                        <strong>Created:</strong> <?php echo date('M j, Y g:i A', strtotime($category['created_at'])); ?>
                    </div>
                    <div class="col-md-3">
                        <strong>Last Updated:</strong> <?php echo date('M j, Y g:i A', strtotime($category['updated_at'])); ?>
                    </div>
                    <div class="col-md-3">
                        <strong>Products:</strong> <?php echo number_format($product_count); ?> items
                    </div>
                    <div class="col-md-3">
                        <strong>Status:</strong> 
                        <?php if ($product_count > 0): ?>
                            <span class="badge badge-success">Active</span>
                        <?php else: ?>
                            <span class="badge badge-warning">Empty</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="category-form">
                <form method="POST" id="categoryForm">
                    <div class="form-group">
                        <label for="name" class="form-label">Category Name *</label>
                        <input type="text" class="form-control <?php echo isset($errors['name']) ? 'is-invalid' : ''; ?>" 
                               id="name" name="name" value="<?php echo htmlspecialchars($category['name']); ?>" 
                               required maxlength="100" placeholder="Enter category name">
                        <?php if (isset($errors['name'])): ?>
                        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['name']); ?></div>
                        <?php endif; ?>
                        <div class="form-text">
                            Category name is required and must be unique
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control <?php echo isset($errors['description']) ? 'is-invalid' : ''; ?>" 
                                  id="description" name="description" rows="4" maxlength="500"
                                  placeholder="Optional description of the category"><?php echo htmlspecialchars($category['description'] ?? ''); ?></textarea>
                        <?php if (isset($errors['description'])): ?>
                        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['description']); ?></div>
                        <?php endif; ?>
                        <div class="form-text">
                            Provide additional details about the category (optional, max 500 characters)
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="d-flex gap-3">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check"></i>
                                Update Category
                            </button>
                            <a href="categories.php" class="btn btn-outline-secondary">
                                <i class="bi bi-x"></i>
                                Cancel
                            </a>
                            <a href="delete.php?id=<?php echo $category_id; ?>" 
                               class="btn btn-danger btn-delete ml-auto" 
                               data-category-name="<?php echo htmlspecialchars($category['name']); ?>"
                               data-product-count="<?php echo $product_count; ?>">
                                <i class="bi bi-trash"></i>
                                Delete Category
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Products in Category -->
            <?php if ($product_count > 0): ?>
            <div class="data-section">
                <div class="section-header">
                    <h3 class="section-title">
                        <i class="bi bi-box me-2"></i>
                        Products in this Category
                    </h3>
                    <a href="../products/products.php?category=<?php echo $category_id; ?>" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-eye"></i>
                        View All Products
                    </a>
                </div>
                
                <?php
                // Get some products in this category
                $products_stmt = $conn->prepare("
                    SELECT id, name, price, quantity 
                    FROM products 
                    WHERE category_id = :id 
                    ORDER BY name 
                    LIMIT 5
                ");
                $products_stmt->bindParam(':id', $category_id);
                $products_stmt->execute();
                $sample_products = $products_stmt->fetchAll(PDO::FETCH_ASSOC);
                ?>
                
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Product Name</th>
                                <th>Price</th>
                                <th>Quantity</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sample_products as $product): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($product['name']); ?></td>
                                <td class="currency"><?php echo $settings['currency_symbol']; ?> <?php echo number_format($product['price'], 2); ?></td>
                                <td><?php echo number_format($product['quantity']); ?></td>
                                <td>
                                    <a href="../products/edit.php?id=<?php echo $product['id']; ?>" class="btn btn-outline-secondary btn-sm">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <?php if ($product_count > 5): ?>
                    <div class="text-center mt-3">
                        <a href="../products/products.php?category=<?php echo $category_id; ?>" class="btn btn-outline-primary">
                            <i class="bi bi-eye"></i>
                            View All <?php echo $product_count; ?> Products
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Change History -->
            <div class="data-section">
                <div class="section-header">
                    <h3 class="section-title">
                        <i class="bi bi-clock-history me-2"></i>
                        Category History
                    </h3>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="d-flex align-items-center mb-3">
                            <div class="stat-icon stat-primary me-3">
                                <i class="bi bi-plus-circle"></i>
                            </div>
                            <div>
                                <div class="font-weight-bold">Category Created</div>
                                <small class="text-muted"><?php echo date('F j, Y \a\t g:i A', strtotime($category['created_at'])); ?></small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="d-flex align-items-center mb-3">
                            <div class="stat-icon stat-warning me-3">
                                <i class="bi bi-pencil-square"></i>
                            </div>
                            <div>
                                <div class="font-weight-bold">Last Modified</div>
                                <small class="text-muted"><?php echo date('F j, Y \a\t g:i A', strtotime($category['updated_at'])); ?></small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php if ($product_count > 0): ?>
                <div class="alert alert-info mt-3">
                    <i class="bi bi-info-circle me-2"></i>
                    <strong>Note:</strong> This category contains <?php echo $product_count; ?> product(s). 
                    Deleting this category will require reassigning these products to another category first.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/categories.js"></script>
</body>
</html>