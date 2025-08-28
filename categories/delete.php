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

// Get product count and products in this category
$product_stmt = $conn->prepare("
    SELECT p.id, p.name, p.price, p.quantity 
    FROM products p 
    WHERE p.category_id = :id 
    ORDER BY p.name
");
$product_stmt->bindParam(':id', $category_id);
$product_stmt->execute();
$products = $product_stmt->fetchAll(PDO::FETCH_ASSOC);
$product_count = count($products);

// Get other categories for reassignment
$other_categories_stmt = $conn->prepare("SELECT id, name FROM categories WHERE id != :id ORDER BY name");
$other_categories_stmt->bindParam(':id', $category_id);
$other_categories_stmt->execute();
$other_categories = $other_categories_stmt->fetchAll(PDO::FETCH_ASSOC);

$errors = [];
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'delete') {
        if ($product_count > 0) {
            $new_category_id = (int)($_POST['new_category_id'] ?? 0);
            
            if ($new_category_id <= 0) {
                $errors['reassign'] = 'Please select a category to move the products to';
            } else {
                // Check if the new category exists
                $check_stmt = $conn->prepare("SELECT id FROM categories WHERE id = :id");
                $check_stmt->bindParam(':id', $new_category_id);
                $check_stmt->execute();
                if (!$check_stmt->fetch()) {
                    $errors['reassign'] = 'Selected category does not exist';
                }
            }
            
            if (empty($errors)) {
                try {
                    $conn->beginTransaction();
                    
                    // Move all products to the new category
                    $move_stmt = $conn->prepare("UPDATE products SET category_id = :new_id WHERE category_id = :old_id");
                    $move_stmt->bindParam(':new_id', $new_category_id);
                    $move_stmt->bindParam(':old_id', $category_id);
                    $move_stmt->execute();
                    
                    // Delete the category
                    $delete_stmt = $conn->prepare("DELETE FROM categories WHERE id = :id");
                    $delete_stmt->bindParam(':id', $category_id);
                    $delete_stmt->execute();
                    
                    $conn->commit();
                    
                    $_SESSION['success'] = "Category '{$category['name']}' has been deleted successfully. {$product_count} products were moved to the selected category.";
                    header("Location: categories.php");
                    exit();
                    
                } catch (PDOException $e) {
                    $conn->rollBack();
                    $errors['general'] = 'An error occurred while deleting the category. Please try again.';
                }
            }
        } else {
            // No products, safe to delete
            try {
                $delete_stmt = $conn->prepare("DELETE FROM categories WHERE id = :id");
                $delete_stmt->bindParam(':id', $category_id);
                
                if ($delete_stmt->execute()) {
                    $_SESSION['success'] = "Category '{$category['name']}' has been deleted successfully.";
                    header("Location: categories.php");
                    exit();
                }
            } catch (PDOException $e) {
                $errors['general'] = 'An error occurred while deleting the category. Please try again.';
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delete Category - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/categories.css">
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
                    <h1>Delete Category</h1>
                    <div class="header-subtitle">Remove category from system</div>
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
                    <span class="badge badge-danger">Delete Request</span>
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
                            <span class="badge badge-warning">Contains Products</span>
                        <?php else: ?>
                            <span class="badge badge-success">Safe to Delete</span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php if (!empty($category['description'])): ?>
                <div class="mt-3">
                    <strong>Description:</strong> <?php echo htmlspecialchars($category['description']); ?>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($product_count > 0): ?>
            <!-- Warning and Reassignment -->
            <div class="alert alert-warning">
                <h5><i class="bi bi-exclamation-triangle me-2"></i>Warning: Category Contains Products</h5>
                <p>This category contains <strong><?php echo $product_count; ?> product(s)</strong>. 
                You must reassign these products to another category before deletion.</p>
            </div>

            <div class="category-form">
                <form method="POST" id="deleteForm">
                    <input type="hidden" name="action" value="delete">
                    
                    <div class="form-group">
                        <label for="new_category_id" class="form-label">Move Products To *</label>
                        <select class="form-control <?php echo isset($errors['reassign']) ? 'is-invalid' : ''; ?>" 
                                id="new_category_id" name="new_category_id" required>
                            <option value="">Select a category for reassignment</option>
                            <?php foreach ($other_categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>" 
                                    <?php echo ($_POST['new_category_id'] ?? '') == $cat['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['reassign'])): ?>
                        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['reassign']); ?></div>
                        <?php endif; ?>
                        <div class="form-text">
                            All <?php echo $product_count; ?> product(s) will be moved to the selected category
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="d-flex gap-3">
                            <button type="submit" class="btn btn-danger">
                                <i class="bi bi-trash"></i>
                                Delete Category & Move Products
                            </button>
                            <a href="categories.php" class="btn btn-outline-secondary">
                                <i class="bi bi-x"></i>
                                Cancel
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Products List -->
            <div class="data-section">
                <div class="section-header">
                    <h3 class="section-title">
                        <i class="bi bi-box me-2"></i>
                        Products to be Reassigned
                    </h3>
                </div>
                
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
                            <?php foreach ($products as $product): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($product['name']); ?></td>
                                <td class="currency"><?php echo $settings['currency_symbol']; ?> <?php echo number_format($product['price'], 2); ?></td>
                                <td><?php echo number_format($product['quantity']); ?></td>
                                <td>
                                    <a href="../products/edit.php?id=<?php echo $product['id']; ?>" class="btn btn-outline-secondary btn-sm">
                                        <i class="bi bi-pencil"></i>
                                        Edit
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php else: ?>
            <!-- Safe to Delete -->
            <div class="alert alert-success">
                <h5><i class="bi bi-check-circle me-2"></i>Safe to Delete</h5>
                <p>This category is empty and can be safely deleted without affecting any products.</p>
            </div>

            <div class="category-form">
                <form method="POST" id="deleteForm">
                    <input type="hidden" name="action" value="delete">
                    
                    <div class="alert alert-danger">
                        <h6><i class="bi bi-exclamation-triangle me-2"></i>Confirm Deletion</h6>
                        <p class="mb-0">Are you sure you want to delete the category "<strong><?php echo htmlspecialchars($category['name']); ?></strong>"? 
                        This action cannot be undone.</p>
                    </div>

                    <div class="form-group">
                        <div class="d-flex gap-3">
                            <button type="submit" class="btn btn-danger">
                                <i class="bi bi-trash"></i>
                                Delete Category
                            </button>
                            <a href="categories.php" class="btn btn-outline-secondary">
                                <i class="bi bi-x"></i>
                                Cancel
                            </a>
                            <a href="edit.php?id=<?php echo $category_id; ?>" class="btn btn-warning">
                                <i class="bi bi-pencil"></i>
                                Edit Instead
                            </a>
                        </div>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <!-- Safety Information -->
            <div class="data-section">
                <div class="section-header">
                    <h3 class="section-title">
                        <i class="bi bi-shield-check me-2"></i>
                        Safety Information
                    </h3>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <h6>What happens when you delete:</h6>
                        <ul class="list-unstyled">
                            <?php if ($product_count > 0): ?>
                            <li><i class="bi bi-arrow-right text-primary me-2"></i>All products will be moved to selected category</li>
                            <li><i class="bi bi-arrow-right text-primary me-2"></i>Product data remains unchanged</li>
                            <li><i class="bi bi-arrow-right text-primary me-2"></i>Sales history is preserved</li>
                            <?php else: ?>
                            <li><i class="bi bi-check text-success me-2"></i>Category is permanently removed</li>
                            <li><i class="bi bi-check text-success me-2"></i>No products affected</li>
                            <li><i class="bi bi-check text-success me-2"></i>No data loss</li>
                            <?php endif; ?>
                            <li><i class="bi bi-exclamation-triangle text-warning me-2"></i>This action cannot be undone</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <h6>Alternative actions:</h6>
                        <ul class="list-unstyled">
                            <li><i class="bi bi-pencil text-info me-2"></i><a href="edit.php?id=<?php echo $category_id; ?>">Edit category instead</a></li>
                            <li><i class="bi bi-arrow-left text-secondary me-2"></i><a href="categories.php">Return to categories</a></li>
                            <?php if ($product_count > 0): ?>
                            <li><i class="bi bi-box text-info me-2"></i><a href="../products/products.php?category=<?php echo $category_id; ?>">Manage products first</a></li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/categories.js"></script>
    <script>
        document.getElementById('deleteForm').addEventListener('submit', function(e) {
            const categoryName = '<?php echo addslashes($category['name']); ?>';
            const productCount = <?php echo $product_count; ?>;
            
            let confirmMessage;
            if (productCount > 0) {
                confirmMessage = `Are you sure you want to delete "${categoryName}" and move ${productCount} product(s) to another category? This action cannot be undone.`;
            } else {
                confirmMessage = `Are you sure you want to delete "${categoryName}"? This action cannot be undone.`;
            }
            
            if (!confirm(confirmMessage)) {
                e.preventDefault();
            }
        });
    </script>
</body>
</html>