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

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

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
    
    // Check if category name already exists
    if (empty($errors['name'])) {
        $check_stmt = $conn->prepare("SELECT id FROM categories WHERE name = :name");
        $check_stmt->bindParam(':name', $name);
        $check_stmt->execute();
        if ($check_stmt->fetch()) {
            $errors['name'] = 'A category with this name already exists';
        }
    }
    
    // If no errors, save the category
    if (empty($errors)) {
        try {
            $insert_stmt = $conn->prepare("
                INSERT INTO categories (name, description) 
                VALUES (:name, :description)
            ");
            
            $insert_stmt->bindParam(':name', $name);
            $insert_stmt->bindParam(':description', $description);
            
            if ($insert_stmt->execute()) {
                $_SESSION['success'] = "Category '$name' has been created successfully!";
                header("Location: categories.php");
                exit();
            }
        } catch (PDOException $e) {
            $errors['general'] = 'An error occurred while saving the category. Please try again.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Category - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
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
                    <h1>Add Category</h1>
                    <div class="header-subtitle">Create a new product category</div>
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

            <div class="category-form">
                <form method="POST" id="categoryForm">
                    <div class="form-group">
                        <label for="name" class="form-label">Category Name *</label>
                        <input type="text" class="form-control <?php echo isset($errors['name']) ? 'is-invalid' : ''; ?>" 
                               id="name" name="name" value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" 
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
                                  placeholder="Optional description of the category"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
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
                                Create Category
                            </button>
                            <a href="categories.php" class="btn btn-outline-secondary">
                                <i class="bi bi-x"></i>
                                Cancel
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Help Section -->
            <div class="data-section mt-4">
                <div class="section-header">
                    <h3 class="section-title">
                        <i class="bi bi-question-circle me-2"></i>
                        Category Guidelines
                    </h3>
                </div>
                <div class="row">
                    <div class="col-md-4">
                        <h5><i class="bi bi-tag me-2"></i>Category Name</h5>
                        <p class="text-muted">Choose a clear, descriptive name that helps organize your products effectively. Category names must be unique.</p>
                    </div>
                    <div class="col-md-4">
                        <h5><i class="bi bi-text-paragraph me-2"></i>Description</h5>
                        <p class="text-muted">Optionally provide more details about what types of products belong in this category.</p>
                    </div>
                    <div class="col-md-4">
                        <h5><i class="bi bi-info-circle me-2"></i>Best Practices</h5>
                        <p class="text-muted">Use consistent naming conventions and keep categories broad enough to group multiple related products.</p>
                    </div>
                </div>
            </div>

            <!-- Examples Section -->
            <div class="data-section">
                <div class="section-header">
                    <h3 class="section-title">
                        <i class="bi bi-lightbulb me-2"></i>
                        Category Examples
                    </h3>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <h6>Common Category Types:</h6>
                        <ul class="list-unstyled">
                            <li><i class="bi bi-check text-success me-2"></i>Electronics & Technology</li>
                            <li><i class="bi bi-check text-success me-2"></i>Clothing & Apparel</li>
                            <li><i class="bi bi-check text-success me-2"></i>Food & Beverages</li>
                            <li><i class="bi bi-check text-success me-2"></i>Home & Garden</li>
                            <li><i class="bi bi-check text-success me-2"></i>Health & Beauty</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <h6>Tips for Organization:</h6>
                        <ul class="list-unstyled">
                            <li><i class="bi bi-info-circle text-info me-2"></i>Keep categories broad, not too specific</li>
                            <li><i class="bi bi-info-circle text-info me-2"></i>Use consistent naming conventions</li>
                            <li><i class="bi bi-info-circle text-info me-2"></i>Consider your inventory size</li>
                            <li><i class="bi bi-info-circle text-info me-2"></i>Plan for future product additions</li>
                            <li><i class="bi bi-info-circle text-info me-2"></i>Avoid duplicate or overlapping categories</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/categories.js"></script>
</body>
</html>