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

// Check if user has permission to edit products
if (!hasPermission('edit_products', $permissions)) {
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

// Get product data
$stmt = $conn->prepare("SELECT * FROM products WHERE id = :id");
$stmt->bindParam(':id', $product_id);
$stmt->execute();
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    $_SESSION['error'] = 'Product not found.';
    header("Location: products.php");
    exit();
}

// Get categories
$categories_stmt = $conn->query("SELECT * FROM categories ORDER BY name");
$categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get brands
$brands_stmt = $conn->query("SELECT * FROM brands ORDER BY name");
$brands = $brands_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get suppliers
$suppliers_stmt = $conn->query("SELECT * FROM suppliers ORDER BY name");
$suppliers = $suppliers_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get tax categories
$tax_categories_stmt = $conn->query("SELECT * FROM tax_categories WHERE is_active = 1 ORDER BY name");
$tax_categories = $tax_categories_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get product families
$families_stmt = $conn->query("SELECT * FROM product_families WHERE status = 'active' ORDER BY name");
$families = $families_stmt->fetchAll(PDO::FETCH_ASSOC);

$errors = [];
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Basic product information
    $name = sanitizeProductInput($_POST['name'] ?? '');
    $description = sanitizeProductInput($_POST['description'] ?? '', 'text');
    $category_id = (int)($_POST['category_id'] ?? 0);

    // SKU and identifiers
    $sku = sanitizeProductInput($_POST['sku'] ?? '');
    $product_number = sanitizeProductInput($_POST['product_number'] ?? '');
    $barcode = sanitizeProductInput($_POST['barcode'] ?? '');

    // Product type and pricing
    $product_type = sanitizeProductInput($_POST['product_type'] ?? 'physical');
    $price = (float)($_POST['price'] ?? 0);
    $cost_price = (float)($_POST['cost_price'] ?? 0);

    // Inventory
    $quantity = (int)($_POST['quantity'] ?? 0);
    $minimum_stock = (int)($_POST['minimum_stock'] ?? 0);
    $maximum_stock = !empty($_POST['maximum_stock']) ? (int)$_POST['maximum_stock'] : null;
    $reorder_point = (int)($_POST['reorder_point'] ?? 0);

    // Additional details
    $brand_id = !empty($_POST['brand_id']) ? (int)$_POST['brand_id'] : null;
    $supplier_id = !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null;
    $status = sanitizeProductInput($_POST['status'] ?? 'active');
    $tax_rate = !empty($_POST['tax_rate']) ? (float)$_POST['tax_rate'] : null;
    $tax_category_id = !empty($_POST['tax_category_id']) ? (int)$_POST['tax_category_id'] : null;
    $tags = sanitizeProductInput($_POST['tags'] ?? '');
    $warranty_period = sanitizeProductInput($_POST['warranty_period'] ?? '');

    // Dimensions and weight
    $weight = !empty($_POST['weight']) ? (float)$_POST['weight'] : null;
    $length = !empty($_POST['length']) ? (float)$_POST['length'] : null;
    $width = !empty($_POST['width']) ? (float)$_POST['width'] : null;
    $height = !empty($_POST['height']) ? (float)$_POST['height'] : null;

    // Settings
    $is_serialized = isset($_POST['is_serialized']) ? 1 : 0;
    $allow_backorders = isset($_POST['allow_backorders']) ? 1 : 0;
    $track_inventory = isset($_POST['track_inventory']) ? 1 : 0;

    // Sale information
    $sale_price = !empty($_POST['sale_price']) ? (float)$_POST['sale_price'] : null;
    $sale_start_date = !empty($_POST['sale_start_date']) ? $_POST['sale_start_date'] : null;
    $sale_end_date = !empty($_POST['sale_end_date']) ? $_POST['sale_end_date'] : null;
    
    // Validation
    if (empty($name)) {
        $errors['name'] = 'Product name is required';
    }
    
    if (empty($description)) {
        $errors['description'] = 'Product description is required';
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
    
    if ($minimum_stock < 0) {
        $errors['minimum_stock'] = 'Minimum stock must be a positive number';
    }

    if ($maximum_stock !== null && $maximum_stock < 0) {
        $errors['maximum_stock'] = 'Maximum stock must be a positive number';
    }

    if ($reorder_point < 0) {
        $errors['reorder_point'] = 'Reorder point must be a positive number';
    }

    if ($tax_rate !== null && ($tax_rate < 0 || $tax_rate > 100)) {
        $errors['tax_rate'] = 'Tax rate must be between 0 and 100';
    }

    // Validate sale information
    if ($sale_price !== null) {
        if ($sale_price < 0) {
            $errors['sale_price'] = 'Sale price must be a positive number';
        } elseif ($sale_price >= $price) {
            $errors['sale_price'] = 'Sale price must be less than regular price';
        }
    }

    if ($sale_start_date && $sale_end_date) {
        $start = strtotime($sale_start_date);
        $end = strtotime($sale_end_date);
        if ($start >= $end) {
            $errors['sale_dates'] = 'Sale end date must be after start date';
        }
    }

    // Check SKU uniqueness if provided
    if (!empty($sku)) {
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM products WHERE sku = :sku AND id != :id");
        $stmt->bindParam(':sku', $sku);
        $stmt->bindParam(':id', $product_id);
        $stmt->execute();
        if ($stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0) {
            $errors['sku'] = 'This SKU already exists';
        }
    }

    // Check Product Number uniqueness if provided
    if (!empty($product_number)) {
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM products WHERE product_number = :product_number AND id != :id");
        $stmt->bindParam(':product_number', $product_number);
        $stmt->bindParam(':id', $product_id);
        $stmt->execute();
        if ($stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0) {
            $errors['product_number'] = 'This Product Number already exists';
        }
    }

    // Check barcode uniqueness if provided
    if (!empty($barcode)) {
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM products WHERE barcode = :barcode AND id != :id");
        $stmt->bindParam(':barcode', $barcode);
        $stmt->bindParam(':id', $product_id);
        $stmt->execute();
        if ($stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0) {
            $errors['barcode'] = 'This barcode already exists';
        }
    }

    // Validate dimensions
    if ($weight !== null && $weight < 0) {
        $errors['weight'] = 'Weight must be a positive number';
    }
    if ($length !== null && $length < 0) {
        $errors['length'] = 'Length must be a positive number';
    }
    if ($width !== null && $width < 0) {
        $errors['width'] = 'Width must be a positive number';
    }
    if ($height !== null && $height < 0) {
        $errors['height'] = 'Height must be a positive number';
    }
    
    // If no errors, update the product
    if (empty($errors)) {
        try {
            $update_stmt = $conn->prepare("
                UPDATE products 
                SET name = :name, description = :description, category_id = :category_id,
                    sku = :sku, product_number = :product_number, product_type = :product_type, price = :price, cost_price = :cost_price,
                    quantity = :quantity, minimum_stock = :minimum_stock, maximum_stock = :maximum_stock,
                    reorder_point = :reorder_point, barcode = :barcode, brand_id = :brand_id,
                    supplier_id = :supplier_id, weight = :weight, length = :length, width = :width,
                    height = :height, status = :status, tax_rate = :tax_rate, tax_category_id = :tax_category_id, tags = :tags,
                    warranty_period = :warranty_period, is_serialized = :is_serialized,
                    allow_backorders = :allow_backorders, track_inventory = :track_inventory,
                    sale_price = :sale_price, sale_start_date = :sale_start_date, sale_end_date = :sale_end_date,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            
            $update_stmt->bindParam(':name', $name);
            $update_stmt->bindParam(':description', $description);
            $update_stmt->bindParam(':category_id', $category_id);
            $update_stmt->bindParam(':sku', $sku);
            $update_stmt->bindParam(':product_number', $product_number);
            $update_stmt->bindParam(':product_type', $product_type);
            $update_stmt->bindParam(':price', $price);
            $update_stmt->bindParam(':cost_price', $cost_price);
            $update_stmt->bindParam(':quantity', $quantity);
            $update_stmt->bindParam(':minimum_stock', $minimum_stock);
            $update_stmt->bindParam(':maximum_stock', $maximum_stock);
            $update_stmt->bindParam(':reorder_point', $reorder_point);
            $update_stmt->bindParam(':barcode', $barcode);
            $update_stmt->bindParam(':brand_id', $brand_id, PDO::PARAM_INT);
            $update_stmt->bindParam(':supplier_id', $supplier_id, PDO::PARAM_INT);
            $update_stmt->bindParam(':weight', $weight);
            $update_stmt->bindParam(':length', $length);
            $update_stmt->bindParam(':width', $width);
            $update_stmt->bindParam(':height', $height);
            $update_stmt->bindParam(':status', $status);
            $update_stmt->bindParam(':tax_rate', $tax_rate);
            $update_stmt->bindParam(':tax_category_id', $tax_category_id);
            $update_stmt->bindParam(':tags', $tags);
            $update_stmt->bindParam(':warranty_period', $warranty_period);
            $update_stmt->bindParam(':is_serialized', $is_serialized, PDO::PARAM_INT);
            $update_stmt->bindParam(':allow_backorders', $allow_backorders, PDO::PARAM_INT);
            $update_stmt->bindParam(':track_inventory', $track_inventory, PDO::PARAM_INT);
            $update_stmt->bindParam(':sale_price', $sale_price);
            $update_stmt->bindParam(':sale_start_date', $sale_start_date);
            $update_stmt->bindParam(':sale_end_date', $sale_end_date);
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
    $product['description'] = $description;
    $product['category_id'] = $category_id;
    $product['sku'] = $sku;
    $product['product_number'] = $product_number;
    $product['product_type'] = $product_type;
    $product['price'] = $price;
    $product['cost_price'] = $cost_price;
    $product['quantity'] = $quantity;
    $product['minimum_stock'] = $minimum_stock;
    $product['maximum_stock'] = $maximum_stock;
    $product['reorder_point'] = $reorder_point;
    $product['barcode'] = $barcode;
    $product['brand_id'] = $brand_id;
    $product['supplier_id'] = $supplier_id;
    $product['status'] = $status;
    $product['tax_rate'] = $tax_rate;
    $product['tags'] = $tags;
    $product['warranty_period'] = $warranty_period;
    $product['weight'] = $weight;
    $product['length'] = $length;
    $product['width'] = $width;
    $product['height'] = $height;
    $product['is_serialized'] = $is_serialized;
    $product['allow_backorders'] = $allow_backorders;
    $product['track_inventory'] = $track_inventory;
    $product['sale_price'] = $sale_price;
    $product['sale_start_date'] = $sale_start_date;
    $product['sale_end_date'] = $sale_end_date;
}

// Handle AJAX requests for Product Number generation
if (isset($_GET['action']) && $_GET['action'] === 'generate_product_number') {
    $product_number = generateProductNumber($conn);
    
    header('Content-Type: application/json');
    echo json_encode(['product_number' => $product_number]);
    exit();
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
    <?php
    $current_page = 'products';
    include __DIR__ . '/../include/navmenu.php';
    ?>

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
            <?php if (isset($errors['general'])): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-circle me-2"></i>
                <?php echo htmlspecialchars($errors['general']); ?>
            </div>
            <?php endif; ?>

            <!-- Product Info Card -->
            <div class="data-section mb-4 sticky-top" style="top: 20px; z-index: 1000; background: white; box-shadow: 0 2px 10px rgba(0,0,0,0.1); border-radius: 8px; padding: 20px;">
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
                <form method="POST" id="productForm" enctype="multipart/form-data">
                    <!-- Basic Information -->
                    <div class="form-section">
                        <h4 class="section-title">
                            <i class="bi bi-info-circle me-2"></i>
                            Basic Information
                        </h4>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="name" class="form-label">Product Name *</label>
                            <input type="text" class="form-control <?php echo isset($errors['name']) ? 'is-invalid' : ''; ?>" 
                                       id="name" name="name" value="<?php echo htmlspecialchars($product['name'] ?? ''); ?>"
                                   required placeholder="Enter product name">
                            <?php if (isset($errors['name'])): ?>
                            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['name']); ?></div>
                            <?php endif; ?>
                            <div class="form-text">
                                <i class="bi bi-lightbulb me-1"></i>
                                <strong>Example:</strong> "Samsung Galaxy S23 128GB" or "Premium Coffee Beans 500g"
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="category_id" class="form-label">Category *</label>
                            <select class="form-control <?php echo isset($errors['category_id']) ? 'is-invalid' : ''; ?>" 
                                    id="category_id" name="category_id" required>
                                <option value="">Select Category</option>
                                <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>" 
                                            <?php echo ($product['category_id'] ?? '') == $category['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($category['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($errors['category_id'])): ?>
                            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['category_id']); ?></div>
                            <?php endif; ?>
                            <div class="form-text">
                                <i class="bi bi-lightbulb me-1"></i>
                                <strong>Purpose:</strong> Groups similar products together for better organization and reporting. 
                                <a href="../categories/add.php" target="_blank">Add new category</a>
                            </div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                                <label for="description" class="form-label">Description *</label>
                                <textarea class="form-control <?php echo isset($errors['description']) ? 'is-invalid' : ''; ?>" 
                                          id="description" name="description" rows="3" required
                                          placeholder="Enter product description"><?php echo htmlspecialchars($product['description'] ?? ''); ?></textarea>
                                <?php if (isset($errors['description'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['description']); ?></div>
                                <?php endif; ?>
                                <div class="form-text">
                                    <i class="bi bi-lightbulb me-1"></i>
                                    <strong>Purpose:</strong> Detailed description helps customers understand the product. Include key features, specifications, or usage instructions.
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="product_type" class="form-label">Product Type</label>
                                <select class="form-control" id="product_type" name="product_type">
                                    <option value="physical" <?php echo ($product['product_type'] ?? 'physical') === 'physical' ? 'selected' : ''; ?>>Physical Product</option>
                                    <option value="digital" <?php echo ($product['product_type'] ?? '') === 'digital' ? 'selected' : ''; ?>>Digital Product</option>
                                    <option value="service" <?php echo ($product['product_type'] ?? '') === 'service' ? 'selected' : ''; ?>>Service</option>
                                    <option value="subscription" <?php echo ($product['product_type'] ?? '') === 'subscription' ? 'selected' : ''; ?>>Subscription</option>
                                </select>
                                <div class="form-text">
                                    <i class="bi bi-lightbulb me-1"></i>
                                    <strong>Types:</strong> Physical (tangible items), Digital (downloadable), Service (consulting/repairs), Subscription (recurring billing)
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Identifiers -->
                    <div class="form-section">
                        <h4 class="section-title">
                            <i class="bi bi-upc me-2"></i>
                            Product Identifiers
                        </h4>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="sku" class="form-label">SKU</label>
                                <div class="input-group">
                                    <input type="text" class="form-control <?php echo isset($errors['sku']) ? 'is-invalid' : ''; ?>"
                                           id="sku" name="sku" value="<?php echo htmlspecialchars($product['sku'] ?? ''); ?>"
                                           placeholder="Enter or generate SKU">
                                    <button type="button" class="btn btn-outline-secondary" id="generateSKU">
                                        <i class="bi bi-magic"></i>
                                        Generate
                                    </button>
                                </div>
                                <?php if (isset($errors['sku'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['sku']); ?></div>
                                <?php endif; ?>
                                <div class="form-text">
                                    <i class="bi bi-lightbulb me-1"></i>
                                    <strong>SKU (Stock Keeping Unit):</strong> Unique identifier for inventory tracking. Use format like "PROD-001" or "SAMSUNG-S23-128". Leave empty to auto-generate.
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="product_number" class="form-label">Product Number</label>
                                <div class="input-group">
                                    <input type="text" class="form-control <?php echo isset($errors['product_number']) ? 'is-invalid' : ''; ?>"
                                           id="product_number" name="product_number" value="<?php echo htmlspecialchars($product['product_number'] ?? ''); ?>"
                                           placeholder="Enter or generate product number">
                                    <button type="button" class="btn btn-outline-secondary" id="generateProductNumber">
                                        <i class="bi bi-magic"></i>
                                        Generate
                                    </button>
                                </div>
                                <?php if (isset($errors['product_number'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['product_number']); ?></div>
                                <?php endif; ?>
                                <div class="form-text">
                                    <i class="bi bi-lightbulb me-1"></i>
                                    <strong>Product Number:</strong> Internal reference number for your business. Use format like "PN-2024-001" or "ITEM-12345". Leave empty to auto-generate.
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="barcode" class="form-label">Barcode</label>
                                <div class="input-group">
                                    <input type="text" class="form-control <?php echo isset($errors['barcode']) ? 'is-invalid' : ''; ?>"
                                           id="barcode" name="barcode" value="<?php echo htmlspecialchars($product['barcode'] ?? ''); ?>"
                                           placeholder="Enter or generate barcode">
                                    <button type="button" class="btn btn-outline-secondary" id="generateBarcode">
                                        <i class="bi bi-magic"></i>
                                        Generate
                                    </button>
                                </div>
                                <?php if (isset($errors['barcode'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['barcode']); ?></div>
                                <?php endif; ?>
                                <div class="form-text">
                                    <i class="bi bi-lightbulb me-1"></i>
                                    <strong>Barcode:</strong> Used for quick product scanning at checkout. Can be UPC, EAN, or custom format. Leave empty to auto-generate.
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Pricing & Cost -->
                    <div class="form-section">
                        <h4 class="section-title">
                            <i class="bi bi-currency-dollar me-2"></i>
                            Pricing & Cost
                        </h4>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="price" class="form-label">Selling Price (<?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?>) *</label>
                            <input type="number" class="form-control <?php echo isset($errors['price']) ? 'is-invalid' : ''; ?>" 
                                       id="price" name="price" value="<?php echo htmlspecialchars($product['price'] ?? ''); ?>"
                                   step="0.01" min="0" required placeholder="0.00">
                            <?php if (isset($errors['price'])): ?>
                            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['price']); ?></div>
                            <?php endif; ?>
                            <div class="form-text">
                                <i class="bi bi-lightbulb me-1"></i>
                                <strong>Customer Price:</strong> The price customers pay for this product. This is the main selling price.
                            </div>
                        </div>

                        <div class="form-group">
                                <label for="cost_price" class="form-label">Cost Price (<?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?>)</label>
                                <input type="number" class="form-control <?php echo isset($errors['cost_price']) ? 'is-invalid' : ''; ?>"
                                       id="cost_price" name="cost_price" value="<?php echo htmlspecialchars($product['cost_price'] ?? ''); ?>"
                                       step="0.01" min="0" placeholder="0.00">
                                <?php if (isset($errors['cost_price'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['cost_price']); ?></div>
                                <?php endif; ?>
                                <div class="form-text">
                                    <i class="bi bi-lightbulb me-1"></i>
                                    <strong>Cost Price:</strong> What you paid to acquire this product. Used for profit margin calculations and inventory valuation.
                                </div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="tax_category_id" class="form-label">Tax Category</label>
                                <select class="form-control" id="tax_category_id" name="tax_category_id">
                                    <option value="">Select Tax Category (Optional)</option>
                                    <?php foreach ($tax_categories as $category): ?>
                                    <option value="<?php echo $category['id']; ?>" 
                                            <?php echo ($product['tax_category_id'] == $category['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($category['name']); ?>
                                        <?php if (!empty($category['description'])): ?>
                                        - <?php echo htmlspecialchars($category['description']); ?>
                                        <?php endif; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">
                                    Select a tax category for automatic tax calculation
                                </div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="tax_rate" class="form-label">Tax Rate (%)</label>
                                <input type="number" class="form-control <?php echo isset($errors['tax_rate']) ? 'is-invalid' : ''; ?>"
                                       id="tax_rate" name="tax_rate" value="<?php echo htmlspecialchars($product['tax_rate'] ?? ''); ?>"
                                       step="0.01" min="0" max="100" placeholder="Leave empty for default">
                                <?php if (isset($errors['tax_rate'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['tax_rate']); ?></div>
                                <?php endif; ?>
                                <div class="form-text">
                                    Product-specific tax rate (overrides tax category rates if set)
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="warranty_period" class="form-label">Warranty Period</label>
                                <input type="text" class="form-control" id="warranty_period" name="warranty_period"
                                       value="<?php echo htmlspecialchars($product['warranty_period'] ?? ''); ?>"
                                       placeholder="e.g., 1 year, 6 months, 30 days">
                                <div class="form-text">
                                    Warranty period for this product
                                </div>
                            </div>
                        </div>
                    </div>

                    </div>

                    <!-- Sale Information -->
                    <div class="form-section">
                        <h4 class="section-title">
                            <i class="bi bi-tag me-2"></i>
                            Sale Information
                        </h4>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="sale_price" class="form-label">Sale Price (<?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?>)</label>
                                <input type="number" class="form-control <?php echo isset($errors['sale_price']) ? 'is-invalid' : ''; ?>"
                                       id="sale_price" name="sale_price" value="<?php echo htmlspecialchars($product['sale_price'] ?? ''); ?>"
                                       step="0.01" min="0" placeholder="Leave empty if not on sale">
                                <?php if (isset($errors['sale_price'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['sale_price']); ?></div>
                                <?php endif; ?>
                                <div class="form-text">
                                    <i class="bi bi-lightbulb me-1"></i>
                                    <strong>Sale Price:</strong> Special discounted price for promotions. Must be less than the regular selling price.
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="sale_start_date" class="form-label">Sale Start Date</label>
                                <input type="datetime-local" class="form-control" id="sale_start_date" name="sale_start_date"
                                       value="<?php echo htmlspecialchars($product['sale_start_date'] ?? ''); ?>">
                                <div class="form-text">
                                    <i class="bi bi-lightbulb me-1"></i>
                                    <strong>Sale Start:</strong> When the sale should begin. Leave empty for immediate activation.
                                </div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="sale_end_date" class="form-label">Sale End Date</label>
                                <input type="datetime-local" class="form-control <?php echo isset($errors['sale_dates']) ? 'is-invalid' : ''; ?>"
                                       id="sale_end_date" name="sale_end_date" value="<?php echo htmlspecialchars($product['sale_end_date'] ?? ''); ?>">
                                <?php if (isset($errors['sale_dates'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['sale_dates']); ?></div>
                                <?php endif; ?>
                                <div class="form-text">
                                    <i class="bi bi-lightbulb me-1"></i>
                                    <strong>Sale End:</strong> When the sale should end. Leave empty for indefinite duration.
                                </div>
                            </div>

                            <div class="form-group">
                                <div class="mt-4 pt-2">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="clear_sale" name="clear_sale">
                                        <label class="form-check-label" for="clear_sale">
                                            Clear sale information
                                        </label>
                                    </div>
                                    <div class="form-text">
                                        Check to remove sale pricing from this product
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Inventory Management -->
                    <div class="form-section">
                        <h4 class="section-title">
                            <i class="bi bi-boxes me-2"></i>
                            Inventory Management
                        </h4>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="quantity" class="form-label">Current Quantity *</label>
                            <input type="number" class="form-control <?php echo isset($errors['quantity']) ? 'is-invalid' : ''; ?>" 
                                       id="quantity" name="quantity" value="<?php echo htmlspecialchars($product['quantity'] ?? ''); ?>"
                                   min="0" required placeholder="0">
                            <?php if (isset($errors['quantity'])): ?>
                            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['quantity']); ?></div>
                            <?php endif; ?>
                            <div class="form-text">
                                <i class="bi bi-lightbulb me-1"></i>
                                <strong>Current Stock:</strong> Number of units currently available in inventory.
                            </div>
                            </div>

                            <div class="form-group">
                                <label for="minimum_stock" class="form-label">Minimum Stock</label>
                                <input type="number" class="form-control <?php echo isset($errors['minimum_stock']) ? 'is-invalid' : ''; ?>"
                                       id="minimum_stock" name="minimum_stock" value="<?php echo htmlspecialchars($product['minimum_stock'] ?? ''); ?>"
                                       min="0" placeholder="0">
                                <?php if (isset($errors['minimum_stock'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['minimum_stock']); ?></div>
                                <?php endif; ?>
                                <div class="form-text">
                                    <i class="bi bi-lightbulb me-1"></i>
                                    <strong>Minimum Stock:</strong> Alert threshold - you'll be notified when stock falls below this level.
                                </div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="maximum_stock" class="form-label">Maximum Stock</label>
                                <input type="number" class="form-control <?php echo isset($errors['maximum_stock']) ? 'is-invalid' : ''; ?>"
                                       id="maximum_stock" name="maximum_stock" value="<?php echo htmlspecialchars($product['maximum_stock'] ?? ''); ?>"
                                       min="0" placeholder="0">
                                <?php if (isset($errors['maximum_stock'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['maximum_stock']); ?></div>
                                <?php endif; ?>
                                <div class="form-text">
                                    <i class="bi bi-lightbulb me-1"></i>
                                    <strong>Maximum Stock:</strong> Storage capacity limit. Leave empty for unlimited storage.
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="reorder_point" class="form-label">Reorder Point</label>
                                <input type="number" class="form-control <?php echo isset($errors['reorder_point']) ? 'is-invalid' : ''; ?>"
                                       id="reorder_point" name="reorder_point" value="<?php echo htmlspecialchars($product['reorder_point'] ?? ''); ?>"
                                       min="0" placeholder="0">
                                <?php if (isset($errors['reorder_point'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['reorder_point']); ?></div>
                                <?php endif; ?>
                            <div class="form-text">
                                    <i class="bi bi-lightbulb me-1"></i>
                                    <strong>Reorder Point:</strong> When stock reaches this level, consider placing a new order to avoid stockouts.
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Additional Details -->
                    <div class="form-section">
                        <h4 class="section-title">
                            <i class="bi bi-gear me-2"></i>
                            Additional Details
                        </h4>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="brand_id" class="form-label">Brand</label>
                                <select class="form-control" id="brand_id" name="brand_id">
                                    <option value="">Select Brand</option>
                                    <?php foreach ($brands as $brand_item): ?>
                                    <option value="<?php echo $brand_item['id']; ?>"
                                            <?php echo ($product['brand_id'] ?? '') == $brand_item['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($brand_item['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">
                                    <i class="bi bi-lightbulb me-1"></i>
                                    <strong>Brand:</strong> Manufacturer or brand name (e.g., Samsung, Nike, Apple). 
                                    <a href="../brands/add.php" target="_blank">Add new brand</a>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="product_family_id" class="form-label">Product Family</label>
                                <select class="form-control" id="product_family_id" name="product_family_id">
                                    <option value="">Select Product Family</option>
                                    <?php foreach ($families as $family): ?>
                                    <option value="<?php echo $family['id']; ?>"
                                            <?php echo ($product['product_family_id'] ?? '') == $family['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($family['name']); ?> (<?php echo htmlspecialchars($family['base_unit']); ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">
                                    <i class="bi bi-lightbulb me-1"></i>
                                    <strong>Product Family:</strong> Groups related products with shared pricing and units (e.g., "Cooking Oils", "Rice Products"). 
                                    <a href="../product_families/add.php" target="_blank">Add new family</a>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="supplier_id" class="form-label">Supplier</label>
                                <select class="form-control" id="supplier_id" name="supplier_id">
                                    <option value="">Select Supplier</option>
                                    <?php foreach ($suppliers as $supplier_item): ?>
                                    <option value="<?php echo $supplier_item['id']; ?>"
                                            <?php echo ($product['supplier_id'] ?? '') == $supplier_item['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($supplier_item['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">
                                    <i class="bi bi-lightbulb me-1"></i>
                                    <strong>Supplier:</strong> Company or vendor who supplies this product. Required for purchase orders and cost tracking. 
                                    <a href="../suppliers/add.php" target="_blank">Add new supplier</a>
                                </div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-control" id="status" name="status">
                                    <option value="active" <?php echo ($product['status'] ?? 'active') === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo ($product['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    <option value="discontinued" <?php echo ($product['status'] ?? '') === 'discontinued' ? 'selected' : ''; ?>>Discontinued</option>
                                </select>
                        <div class="form-text">
                                    <i class="bi bi-lightbulb me-1"></i>
                                    <strong>Status:</strong> Active (available for sale), Inactive (temporarily unavailable), Discontinued (no longer sold)
                        </div>
                    </div>

                    <div class="form-group">
                                <label for="tags" class="form-label">Tags</label>
                                <input type="text" class="form-control" id="tags" name="tags"
                                       value="<?php echo htmlspecialchars($product['tags'] ?? ''); ?>"
                                       placeholder="Comma-separated tags">
                        <div class="form-text">
                                    <i class="bi bi-lightbulb me-1"></i>
                                    <strong>Tags:</strong> Keywords for search and filtering (e.g., "electronics, smartphone, android"). Separate multiple tags with commas.
                                </div>
                            </div>
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
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Product Number Generation
            const generateProductNumberBtn = document.getElementById('generateProductNumber');
            if (generateProductNumberBtn) {
                generateProductNumberBtn.addEventListener('click', function() {
                    fetch('?action=generate_product_number')
                        .then(response => response.json())
                        .then(data => {
                            document.getElementById('product_number').value = data.product_number;
                        })
                        .catch(error => {
                            console.error('Error generating product number:', error);
                        });
                });
            }
        });
    </script>
</body>
</html>