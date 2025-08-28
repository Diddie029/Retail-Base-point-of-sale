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
    header("Location: ../dashboard/dashboard.php");
    exit();
}

// Get product ID
$product_id = (int)($_GET['id'] ?? 0);
if ($product_id <= 0) {
    header("Location: index.php");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get product data
$stmt = $conn->prepare("SELECT * FROM products WHERE id = :id");
$stmt->bindParam(':id', $product_id);
$stmt->execute();
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    $_SESSION['error'] = 'Product not found.';
    header("Location: index.php");
    exit();
}

// Get categories
$categories_stmt = $conn->query("SELECT * FROM categories ORDER BY name");
$categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);

$errors = [];
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $category_id = (int)($_POST['category_id'] ?? 0);
    $price = (float)($_POST['price'] ?? 0);
    $quantity = (int)($_POST['quantity'] ?? 0);
    $barcode = trim($_POST['barcode'] ?? '');
    $description = trim($_POST['description'] ?? '');
    
    // Validation
    if (empty($name)) {
        $errors['name'] = 'Product name is required';
    }
    
    if ($category_id <= 0) {
        $errors['category_id'] = 'Please select a category';
    }
    
    if ($price < 0) {
        $errors['price'] = 'Price must be a positive number';
    }
    
    if ($quantity < 0) {
        $errors['quantity'] = 'Quantity must be a positive number';
    }
    
    if (empty($barcode)) {
        $errors['barcode'] = 'Barcode is required';
    } else {
        // Check if barcode already exists (excluding current product)
        $check_stmt = $conn->prepare("SELECT id FROM products WHERE barcode = :barcode AND id != :id");
        $check_stmt->bindParam(':barcode', $barcode);
        $check_stmt->bindParam(':id', $product_id);
        $check_stmt->execute();
        if ($check_stmt->fetch()) {
            $errors['barcode'] = 'This barcode already exists';
        }
    }
    
    // If no errors, update the product
    if (empty($errors)) {
        try {
            $update_stmt = $conn->prepare("
                UPDATE products 
                SET name = :name, category_id = :category_id, price = :price, 
                    quantity = :quantity, barcode = :barcode, description = :description,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            
            $update_stmt->bindParam(':name', $name);
            $update_stmt->bindParam(':category_id', $category_id);
            $update_stmt->bindParam(':price', $price);
            $update_stmt->bindParam(':quantity', $quantity);
            $update_stmt->bindParam(':barcode', $barcode);
            $update_stmt->bindParam(':description', $description);
            $update_stmt->bindParam(':id', $product_id);
            
            if ($update_stmt->execute()) {
                $_SESSION['success'] = "Product '$name' has been updated successfully!";
                header("Location: view.php?id=$product_id");
                exit();
            }
        } catch (PDOException $e) {
            $errors['general'] = 'An error occurred while updating the product. Please try again.';
        }
    }
    
    // Update product array with POST data for form repopulation
    $product['name'] = $name;
    $product['category_id'] = $category_id;
    $product['price'] = $price;
    $product['quantity'] = $quantity;
    $product['barcode'] = $barcode;
    $product['description'] = $description;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Product - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
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
                    <h1>Edit Product</h1>
                    <div class="header-subtitle">Update product information</div>
                </div>
                <div class="header-actions">
                    <a href="view.php?id=<?php echo $product_id; ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-eye"></i>
                        View Product
                    </a>
                    <a href="index.php" class="btn btn-outline-secondary">
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
            <?php if (isset($errors['general'])): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-circle me-2"></i>
                <?php echo htmlspecialchars($errors['general']); ?>
            </div>
            <?php endif; ?>

            <!-- Product Info Card -->
            <div class="data-section mb-4">
                <div class="section-header">
                    <h3 class="section-title">
                        <i class="bi bi-box me-2"></i>
                        <?php echo htmlspecialchars($product['name']); ?>
                    </h3>
                    <span class="badge badge-secondary">ID: <?php echo $product['id']; ?></span>
                </div>
                <div class="row">
                    <div class="col-md-3">
                        <strong>Created:</strong> <?php echo date('M j, Y g:i A', strtotime($product['created_at'])); ?>
                    </div>
                    <div class="col-md-3">
                        <strong>Last Updated:</strong> <?php echo date('M j, Y g:i A', strtotime($product['updated_at'])); ?>
                    </div>
                    <div class="col-md-3">
                        <strong>Current Stock:</strong> <?php echo number_format($product['quantity']); ?> units
                    </div>
                    <div class="col-md-3">
                        <strong>Current Price:</strong> <?php echo $settings['currency_symbol']; ?> <?php echo number_format($product['price'], 2); ?>
                    </div>
                </div>
            </div>

            <div class="product-form">
                <form method="POST" id="productForm">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="name" class="form-label">Product Name *</label>
                            <input type="text" class="form-control <?php echo isset($errors['name']) ? 'is-invalid' : ''; ?>" 
                                   id="name" name="name" value="<?php echo htmlspecialchars($product['name']); ?>" 
                                   required placeholder="Enter product name">
                            <?php if (isset($errors['name'])): ?>
                            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['name']); ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="category_id" class="form-label">Category *</label>
                            <select class="form-control <?php echo isset($errors['category_id']) ? 'is-invalid' : ''; ?>" 
                                    id="category_id" name="category_id" required>
                                <option value="">Select Category</option>
                                <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>" 
                                        <?php echo $product['category_id'] == $category['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($category['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($errors['category_id'])): ?>
                            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['category_id']); ?></div>
                            <?php endif; ?>
                            <div class="form-text">
                                <a href="../categories/add.php" target="_blank">Add new category</a>
                            </div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="price" class="form-label">Price (<?php echo htmlspecialchars($settings['currency_symbol']); ?>) *</label>
                            <input type="number" class="form-control <?php echo isset($errors['price']) ? 'is-invalid' : ''; ?>" 
                                   id="price" name="price" value="<?php echo htmlspecialchars($product['price']); ?>" 
                                   step="0.01" min="0" required placeholder="0.00">
                            <?php if (isset($errors['price'])): ?>
                            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['price']); ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="quantity" class="form-label">Quantity *</label>
                            <input type="number" class="form-control <?php echo isset($errors['quantity']) ? 'is-invalid' : ''; ?>" 
                                   id="quantity" name="quantity" value="<?php echo htmlspecialchars($product['quantity']); ?>" 
                                   min="0" required placeholder="0">
                            <?php if (isset($errors['quantity'])): ?>
                            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['quantity']); ?></div>
                            <?php endif; ?>
                            <div class="form-text">
                                Update the current stock quantity
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="barcode" class="form-label">Barcode *</label>
                        <div class="input-group">
                            <input type="text" class="form-control <?php echo isset($errors['barcode']) ? 'is-invalid' : ''; ?>" 
                                   id="barcode" name="barcode" value="<?php echo htmlspecialchars($product['barcode']); ?>" 
                                   required placeholder="Enter or generate barcode">
                            <button type="button" class="btn btn-outline-secondary" id="generateBarcode">
                                <i class="bi bi-magic"></i>
                                Generate New
                            </button>
                        </div>
                        <?php if (isset($errors['barcode'])): ?>
                        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['barcode']); ?></div>
                        <?php endif; ?>
                        <div class="form-text">
                            Barcode must be unique for each product
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="4" 
                                  placeholder="Optional product description"><?php echo htmlspecialchars($product['description'] ?? ''); ?></textarea>
                        <div class="form-text">
                            Provide additional details about the product (optional)
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="d-flex gap-3">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check"></i>
                                Update Product
                            </button>
                            <a href="view.php?id=<?php echo $product_id; ?>" class="btn btn-outline-secondary">
                                <i class="bi bi-x"></i>
                                Cancel
                            </a>
                            <a href="delete.php?id=<?php echo $product_id; ?>" 
                               class="btn btn-danger btn-delete ml-auto" 
                               data-product-name="<?php echo htmlspecialchars($product['name']); ?>">
                                <i class="bi bi-trash"></i>
                                Delete Product
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Change History -->
            <div class="data-section mt-4">
                <div class="section-header">
                    <h3 class="section-title">
                        <i class="bi bi-clock-history me-2"></i>
                        Product History
                    </h3>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="d-flex align-items-center mb-3">
                            <div class="stat-icon stat-primary me-3">
                                <i class="bi bi-plus-circle"></i>
                            </div>
                            <div>
                                <div class="font-weight-bold">Product Created</div>
                                <small class="text-muted"><?php echo date('F j, Y \a\t g:i A', strtotime($product['created_at'])); ?></small>
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
                                <small class="text-muted"><?php echo date('F j, Y \a\t g:i A', strtotime($product['updated_at'])); ?></small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/products.js"></script>
</body>
</html>