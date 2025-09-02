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

// Check if user has permission to manage products
if (!hasPermission('manage_products', $permissions)) {
    header("Location: ../dashboard/dashboard.php");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get categories
$categories_stmt = $conn->query("SELECT * FROM categories ORDER BY name");
$categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get brands
$brands_stmt = $conn->query("SELECT * FROM brands WHERE is_active = 1 ORDER BY name");
$brands = $brands_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get suppliers
$suppliers_stmt = $conn->query("SELECT * FROM suppliers WHERE is_active = 1 ORDER BY name");
$suppliers = $suppliers_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get product families
$families_stmt = $conn->query("SELECT * FROM product_families WHERE status = 'active' ORDER BY name");
$families = $families_stmt->fetchAll(PDO::FETCH_ASSOC);

$errors = [];
$success = '';

// Input sanitization function - using centralized function from functions.php

// Custom SKU generation function
function generateCustomSKU($conn, $pattern = '', $prefix = '', $length = 6) {
    if (empty($pattern)) {
        // Default patterns
        $patterns = ['N000000', 'LIZ000000', 'PROD000000', 'ITEM000000'];
        $pattern = $patterns[array_rand($patterns)];
    }

    do {
        $sku = $pattern;
        if (strpos($pattern, '000') !== false) {
            // Replace zeros with random numbers
            $sku = preg_replace_callback('/0+/', function($matches) use ($length) {
                $zeros = strlen($matches[0]);
                return str_pad(rand(0, pow(10, $zeros) - 1), $zeros, '0', STR_PAD_LEFT);
            }, $pattern);
        } else {
            // Append random numbers
            $sku = $pattern . str_pad(rand(1, pow(10, $length) - 1), $length, '0', STR_PAD_LEFT);
        }

        if (!empty($prefix)) {
            $sku = $prefix . $sku;
        }

        // Check if SKU already exists
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM products WHERE sku = :sku");
        $stmt->bindParam(':sku', $sku);
        $stmt->execute();
        $exists = $stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0;

    } while ($exists);

    return $sku;
}

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
    $publication_status = sanitizeProductInput($_POST['publication_status'] ?? 'publish_now');
    $scheduled_date = !empty($_POST['scheduled_date']) ? $_POST['scheduled_date'] : null;
    $tax_rate = !empty($_POST['tax_rate']) ? (float)$_POST['tax_rate'] : null;
    $tags = sanitizeProductInput($_POST['tags'] ?? '');
    $warranty_period = sanitizeProductInput($_POST['warranty_period'] ?? '');

    // Sale information
    $sale_price = !empty($_POST['sale_price']) ? (float)$_POST['sale_price'] : null;
    $sale_start_date = !empty($_POST['sale_start_date']) ? $_POST['sale_start_date'] : null;
    $sale_end_date = !empty($_POST['sale_end_date']) ? $_POST['sale_end_date'] : null;

    // Dimensions and weight
    $weight = !empty($_POST['weight']) ? (float)$_POST['weight'] : null;
    $length = !empty($_POST['length']) ? (float)$_POST['length'] : null;
    $width = !empty($_POST['width']) ? (float)$_POST['width'] : null;
    $height = !empty($_POST['height']) ? (float)$_POST['height'] : null;

    // Settings
    $is_serialized = isset($_POST['is_serialized']) ? 1 : 0;
    $allow_backorders = isset($_POST['allow_backorders']) ? 1 : 0;
    $track_inventory = isset($_POST['track_inventory']) ? 1 : 0;

    // Validation
    if (empty($name)) {
        $errors['name'] = 'Product name is required';
    }

    if ($category_id <= 0) {
        $errors['category_id'] = 'Please select a category';
    }

    // Validate brand_id and supplier_id if provided
    if (!empty($brand_id)) {
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM brands WHERE id = ?");
        $stmt->execute([$brand_id]);
        if ($stmt->fetch(PDO::FETCH_ASSOC)['count'] == 0) {
            $errors['brand_id'] = 'Selected brand does not exist';
        }
    }

    if (!empty($supplier_id)) {
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM suppliers WHERE id = ?");
        $stmt->execute([$supplier_id]);
        if ($stmt->fetch(PDO::FETCH_ASSOC)['count'] == 0) {
            $errors['supplier_id'] = 'Selected supplier does not exist';
        }
    }

    if ($price < 0) {
        $errors['price'] = 'Price must be a positive number';
    }

    if ($cost_price < 0) {
        $errors['cost_price'] = 'Cost price must be a positive number';
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

    // Validate publication status
    if ($publication_status === 'scheduled') {
        if (empty($scheduled_date)) {
            $errors['scheduled_date'] = 'Scheduled publication date is required';
        } elseif (!strtotime($scheduled_date)) {
            $errors['scheduled_date'] = 'Invalid scheduled publication date format';
        } elseif (strtotime($scheduled_date) <= time()) {
            $errors['scheduled_date'] = 'Scheduled publication date must be in the future';
        }
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
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM products WHERE sku = :sku");
        $stmt->bindParam(':sku', $sku);
        $stmt->execute();
        if ($stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0) {
            $errors['sku'] = 'This SKU already exists';
        }
    }

    // Check Product Number uniqueness if provided
    if (!empty($product_number)) {
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM products WHERE product_number = :product_number");
        $stmt->bindParam(':product_number', $product_number);
        $stmt->execute();
        if ($stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0) {
            $errors['product_number'] = 'This Product Number already exists';
        }
    }

    // Check barcode uniqueness if provided
    if (!empty($barcode)) {
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM products WHERE barcode = :barcode");
        $stmt->bindParam(':barcode', $barcode);
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

    // If no errors, save the product
    if (empty($errors)) {
        try {
            // Auto-generate SKU if not provided
            if (empty($sku)) {
                // Check if auto-generate SKU is enabled in system settings
                $sku_settings = getSKUSettings($conn);
                if ($sku_settings['auto_generate_sku']) {
                    $sku = generateSystemSKU($conn);
                } else {
                    $sku = generateCustomSKU($conn);
                }
            }

            // Auto-generate Product Number if not provided
            if (empty($product_number)) {
                // Check if auto-generate Product Number is enabled in system settings
                if (isset($settings['auto_generate_product_number']) && $settings['auto_generate_product_number'] == '1') {
                    $product_number = generateProductNumber($conn);
                }
            }

            // Determine actual status based on publication status
            $actual_status = $status;
            if ($publication_status === 'draft') {
                $actual_status = 'draft';
            } elseif ($publication_status === 'publish_now') {
                $actual_status = 'active';
            } elseif ($publication_status === 'scheduled') {
                $actual_status = 'scheduled';
            }

            $insert_stmt = $conn->prepare("
                INSERT INTO products (
                    name, description, category_id, sku, product_number, product_type, price, cost_price,
                    quantity, minimum_stock, maximum_stock, reorder_point, barcode, brand_id,
                    supplier_id, weight, length, width, height, status, tax_rate, tags,
                    warranty_period, is_serialized, allow_backorders, track_inventory,
                    sale_price, sale_start_date, sale_end_date, publication_status, scheduled_date,
                    created_at, updated_at
                ) VALUES (
                    :name, :description, :category_id, :sku, :product_number, :product_type, :price, :cost_price,
                    :quantity, :minimum_stock, :maximum_stock, :reorder_point, :barcode, :brand_id,
                    :supplier_id, :weight, :length, :width, :height, :status, :tax_rate, :tags,
                    :warranty_period, :is_serialized, :allow_backorders, :track_inventory,
                    :sale_price, :sale_start_date, :sale_end_date, :publication_status, :scheduled_date,
                    NOW(), NOW()
                )
            ");

            $insert_stmt->bindParam(':name', $name);
            $insert_stmt->bindParam(':description', $description);
            $insert_stmt->bindParam(':category_id', $category_id);
            $insert_stmt->bindParam(':sku', $sku);
            $insert_stmt->bindParam(':product_number', $product_number);
            $insert_stmt->bindParam(':product_type', $product_type);
            $insert_stmt->bindParam(':price', $price);
            $insert_stmt->bindParam(':cost_price', $cost_price);
            $insert_stmt->bindParam(':quantity', $quantity);
            $insert_stmt->bindParam(':minimum_stock', $minimum_stock);
            $insert_stmt->bindParam(':maximum_stock', $maximum_stock);
            $insert_stmt->bindParam(':reorder_point', $reorder_point);
            $insert_stmt->bindParam(':barcode', $barcode);
            $insert_stmt->bindParam(':brand_id', $brand_id, PDO::PARAM_INT);
            $insert_stmt->bindParam(':supplier_id', $supplier_id, PDO::PARAM_INT);
            $insert_stmt->bindParam(':weight', $weight);
            $insert_stmt->bindParam(':length', $length);
            $insert_stmt->bindParam(':width', $width);
            $insert_stmt->bindParam(':height', $height);
            $insert_stmt->bindParam(':status', $actual_status);
            $insert_stmt->bindParam(':tax_rate', $tax_rate);
            $insert_stmt->bindParam(':tags', $tags);
            $insert_stmt->bindParam(':warranty_period', $warranty_period);
            $insert_stmt->bindParam(':is_serialized', $is_serialized, PDO::PARAM_INT);
            $insert_stmt->bindParam(':allow_backorders', $allow_backorders, PDO::PARAM_INT);
            $insert_stmt->bindParam(':track_inventory', $track_inventory, PDO::PARAM_INT);
            $insert_stmt->bindParam(':sale_price', $sale_price);
            $insert_stmt->bindParam(':sale_start_date', $sale_start_date);
            $insert_stmt->bindParam(':sale_end_date', $sale_end_date);
            $insert_stmt->bindParam(':publication_status', $publication_status);
            $insert_stmt->bindParam(':scheduled_date', $scheduled_date);

            // Debug: Log the SQL and parameters
            error_log("Product Insert SQL: " . $insert_stmt->queryString);
            error_log("Product Insert Params: " . print_r([
                'name' => $name,
                'category_id' => $category_id,
                'sku' => $sku,
                'brand_id' => $brand_id,
                'supplier_id' => $supplier_id,
                'publication_status' => $publication_status,
                'scheduled_date' => $scheduled_date
            ], true));

            if ($insert_stmt->execute()) {
                $product_id = $conn->lastInsertId();

                // Log the activity
                $activity_message = "Created product: $name (SKU: $sku) - Status: $publication_status";
                if ($publication_status === 'scheduled' && $scheduled_date) {
                    $activity_message .= " - Scheduled for: $scheduled_date";
                }
                logActivity($conn, $user_id, 'product_created', $activity_message);

                $success_message = "Product '$name' has been ";
                if ($publication_status === 'draft') {
                    $success_message .= "saved as draft!";
                } elseif ($publication_status === 'publish_now') {
                    $success_message .= "published successfully!";
                } elseif ($publication_status === 'scheduled') {
                    $success_message .= "scheduled for publication!";
                }
                $_SESSION['success'] = $success_message;
                header("Location: view.php?id=$product_id");
                exit();
            }
        } catch (PDOException $e) {
            $errors['general'] = 'An error occurred while saving the product. Please try again.';
            error_log("Product creation error: " . $e->getMessage());
            error_log("SQL Error Code: " . $e->getCode());
            error_log("SQL Error Info: " . print_r($e->errorInfo, true));
        }
    }
}

// Handle AJAX requests for SKU generation
if (isset($_GET['action']) && $_GET['action'] === 'generate_sku') {
    $pattern = $_GET['pattern'] ?? '';
    $prefix = $_GET['prefix'] ?? '';
    
    // Check if auto-generate SKU is enabled in system settings
    $sku_settings = getSKUSettings($conn);
    if ($sku_settings['auto_generate_sku']) {
        $sku = generateSystemSKU($conn);
    } else {
        $sku = generateCustomSKU($conn, $pattern, $prefix);
    }
    
    header('Content-Type: application/json');
    echo json_encode(['sku' => $sku]);
    exit();
}

// Handle AJAX requests for Product Number generation
if (isset($_GET['action']) && $_GET['action'] === 'generate_product_number') {
    $product_number = generateProductNumber($conn);
    
    header('Content-Type: application/json');
    echo json_encode(['product_number' => $product_number]);
    exit();
}

// Handle AJAX requests for barcode generation
if (isset($_GET['action']) && $_GET['action'] === 'generate_barcode') {
    $timestamp = time();
    $random = rand(100, 999);
    $barcode = $timestamp . $random;
    header('Content-Type: application/json');
    echo json_encode(['barcode' => $barcode]);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Product - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/products.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
        }

        #publicationSection {
            background-color: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 1.5rem;
            margin-top: 2rem;
        }

        #publicationSection .section-title {
            color: var(--primary-color);
            margin-bottom: 1.5rem;
        }

        #scheduledDateGroup {
            background-color: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            padding: 1rem;
            margin-top: 0.5rem;
        }

        #saveBtn {
            min-width: 150px;
            transition: all 0.2s ease;
        }

        #saveBtn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
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
                    <h1>Add Product</h1>
                    <div class="header-subtitle">Create a new product in your inventory</div>
                </div>
                <div class="header-actions">
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
                                       id="name" name="name" value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>"
                                       required placeholder="Enter product name">
                                <?php if (isset($errors['name'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['name']); ?></div>
                                <?php endif; ?>
                            </div>

                            <div class="form-group">
                                <label for="product_type" class="form-label">Product Type *</label>
                                <select class="form-control" id="product_type" name="product_type">
                                    <option value="physical" <?php echo ($_POST['product_type'] ?? 'physical') === 'physical' ? 'selected' : ''; ?>>Physical Product</option>
                                    <option value="digital" <?php echo ($_POST['product_type'] ?? '') === 'digital' ? 'selected' : ''; ?>>Digital Product</option>
                                    <option value="service" <?php echo ($_POST['product_type'] ?? '') === 'service' ? 'selected' : ''; ?>>Service</option>
                                    <option value="subscription" <?php echo ($_POST['product_type'] ?? '') === 'subscription' ? 'selected' : ''; ?>>Subscription</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="4"
                                      placeholder="Detailed product description"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <!-- Identifiers -->
                    <div class="form-section">
                        <h4 class="section-title">
                            <i class="bi bi-tag me-2"></i>
                            Product Identifiers
                        </h4>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="sku" class="form-label">SKU (Stock Keeping Unit)</label>
                                <div class="input-group">
                                    <input type="text" class="form-control <?php echo isset($errors['sku']) ? 'is-invalid' : ''; ?>"
                                           id="sku" name="sku" value="<?php echo htmlspecialchars($_POST['sku'] ?? ''); ?>"
                                           placeholder="Leave empty for auto-generation">
                                    <button type="button" class="btn btn-outline-secondary" id="generateSKU">
                                        <i class="bi bi-magic"></i>
                                        Generate
                                    </button>
                                </div>
                                <?php if (isset($errors['sku'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['sku']); ?></div>
                                <?php endif; ?>
                                <div class="form-text">
                                    Unique identifier for inventory tracking. Leave empty to auto-generate.
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="product_number" class="form-label">Product Number</label>
                                <div class="input-group">
                                    <input type="text" class="form-control <?php echo isset($errors['product_number']) ? 'is-invalid' : ''; ?>"
                                           id="product_number" name="product_number" value="<?php echo htmlspecialchars($_POST['product_number'] ?? ''); ?>"
                                           placeholder="Leave empty for auto-generation" 
                                           <?php echo (isset($settings['auto_generate_product_number']) && $settings['auto_generate_product_number'] == '1') ? 'readonly' : ''; ?>>
                                    <button type="button" class="btn btn-outline-secondary" id="generateProductNumber">
                                        <i class="bi bi-magic"></i>
                                        Generate
                                    </button>
                                </div>
                                <?php if (isset($errors['product_number'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['product_number']); ?></div>
                                <?php endif; ?>
                                <div class="form-text">
                                    Internal product number for tracking. Leave empty to auto-generate.
                                    <?php if (isset($settings['auto_generate_product_number']) && $settings['auto_generate_product_number'] == '1'): ?>
                                    <span class="text-info"><i class="bi bi-info-circle"></i> Auto-generation is enabled in settings.</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="barcode" class="form-label">Barcode</label>
                                <div class="input-group">
                                    <input type="text" class="form-control <?php echo isset($errors['barcode']) ? 'is-invalid' : ''; ?>"
                                           id="barcode" name="barcode" value="<?php echo htmlspecialchars($_POST['barcode'] ?? ''); ?>"
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
                                    Barcode for product scanning (optional but recommended)
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Pricing -->
                    <div class="form-section">
                        <h4 class="section-title">
                            <i class="bi bi-currency-dollar me-2"></i>
                            Pricing & Cost
                        </h4>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="price" class="form-label">Selling Price (<?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?>) *</label>
                                <input type="number" class="form-control <?php echo isset($errors['price']) ? 'is-invalid' : ''; ?>"
                                       id="price" name="price" value="<?php echo htmlspecialchars($_POST['price'] ?? ''); ?>"
                                       step="0.01" min="0" required placeholder="0.00">
                                <?php if (isset($errors['price'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['price']); ?></div>
                                <?php endif; ?>
                            </div>

                            <div class="form-group">
                                <label for="cost_price" class="form-label">Cost Price (<?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?>)</label>
                                <input type="number" class="form-control <?php echo isset($errors['cost_price']) ? 'is-invalid' : ''; ?>"
                                       id="cost_price" name="cost_price" value="<?php echo htmlspecialchars($_POST['cost_price'] ?? ''); ?>"
                                       step="0.01" min="0" placeholder="0.00">
                                <?php if (isset($errors['cost_price'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['cost_price']); ?></div>
                                <?php endif; ?>
                                <div class="form-text">
                                    Your cost to acquire this product (used for profit calculations)
                                </div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="tax_rate" class="form-label">Tax Rate (%)</label>
                                <input type="number" class="form-control <?php echo isset($errors['tax_rate']) ? 'is-invalid' : ''; ?>"
                                       id="tax_rate" name="tax_rate" value="<?php echo htmlspecialchars($_POST['tax_rate'] ?? ($settings['tax_rate'] ?? '')); ?>"
                                       step="0.01" min="0" max="100" placeholder="Leave empty for default">
                                <?php if (isset($errors['tax_rate'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['tax_rate']); ?></div>
                                <?php endif; ?>
                                <div class="form-text">
                                    Product-specific tax rate (shows system default from settings if empty)
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="warranty_period" class="form-label">Warranty Period</label>
                                <input type="text" class="form-control" id="warranty_period" name="warranty_period"
                                       value="<?php echo htmlspecialchars($_POST['warranty_period'] ?? ''); ?>"
                                       placeholder="e.g., 1 year, 6 months, 30 days">
                                <div class="form-text">
                                    Warranty period for this product
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
                                       id="sale_price" name="sale_price" value="<?php echo htmlspecialchars($_POST['sale_price'] ?? ''); ?>"
                                       step="0.01" min="0" placeholder="Leave empty if not on sale">
                                <?php if (isset($errors['sale_price'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['sale_price']); ?></div>
                                <?php endif; ?>
                                <div class="form-text">
                                    Special sale price (must be less than regular price)
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="sale_start_date" class="form-label">Sale Start Date</label>
                                <input type="datetime-local" class="form-control" id="sale_start_date" name="sale_start_date"
                                       value="<?php echo htmlspecialchars($_POST['sale_start_date'] ?? ''); ?>">
                                <div class="form-text">
                                    When the sale should start (leave empty for immediate)
                                </div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="sale_end_date" class="form-label">Sale End Date</label>
                                <input type="datetime-local" class="form-control <?php echo isset($errors['sale_dates']) ? 'is-invalid' : ''; ?>"
                                       id="sale_end_date" name="sale_end_date" value="<?php echo htmlspecialchars($_POST['sale_end_date'] ?? ''); ?>">
                                <?php if (isset($errors['sale_dates'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['sale_dates']); ?></div>
                                <?php endif; ?>
                                <div class="form-text">
                                    When the sale should end (leave empty for indefinite)
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

                    <!-- Inventory -->
                    <div class="form-section">
                        <h4 class="section-title">
                            <i class="bi bi-boxes me-2"></i>
                            Inventory Management
                        </h4>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="quantity" class="form-label">Initial Quantity *</label>
                                <input type="number" class="form-control <?php echo isset($errors['quantity']) ? 'is-invalid' : ''; ?>"
                                       id="quantity" name="quantity" value="<?php echo htmlspecialchars($_POST['quantity'] ?? ''); ?>"
                                       min="0" required placeholder="0">
                                <?php if (isset($errors['quantity'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['quantity']); ?></div>
                                <?php endif; ?>
                            </div>

                            <div class="form-group">
                                <label for="minimum_stock" class="form-label">Minimum Stock Level</label>
                                <input type="number" class="form-control <?php echo isset($errors['minimum_stock']) ? 'is-invalid' : ''; ?>"
                                       id="minimum_stock" name="minimum_stock" value="<?php echo htmlspecialchars($_POST['minimum_stock'] ?? ''); ?>"
                                       min="0" placeholder="0">
                                <?php if (isset($errors['minimum_stock'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['minimum_stock']); ?></div>
                                <?php endif; ?>
                                <div class="form-text">
                                    Alert when stock falls below this level
                                </div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="maximum_stock" class="form-label">Maximum Stock Level</label>
                                <input type="number" class="form-control <?php echo isset($errors['maximum_stock']) ? 'is-invalid' : ''; ?>"
                                       id="maximum_stock" name="maximum_stock" value="<?php echo htmlspecialchars($_POST['maximum_stock'] ?? ''); ?>"
                                       min="0" placeholder="Leave empty for unlimited">
                                <?php if (isset($errors['maximum_stock'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['maximum_stock']); ?></div>
                                <?php endif; ?>
                                <div class="form-text">
                                    Maximum stock level (optional)
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="reorder_point" class="form-label">Reorder Point</label>
                                <input type="number" class="form-control <?php echo isset($errors['reorder_point']) ? 'is-invalid' : ''; ?>"
                                       id="reorder_point" name="reorder_point" value="<?php echo htmlspecialchars($_POST['reorder_point'] ?? ''); ?>"
                                       min="0" placeholder="0">
                                <?php if (isset($errors['reorder_point'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['reorder_point']); ?></div>
                                <?php endif; ?>
                                <div class="form-text">
                                    Point at which you should reorder this product
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Classification -->
                    <div class="form-section">
                        <h4 class="section-title">
                            <i class="bi bi-folder me-2"></i>
                            Classification & Organization
                        </h4>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="category_id" class="form-label">Category *</label>
                                <select class="form-control <?php echo isset($errors['category_id']) ? 'is-invalid' : ''; ?>"
                                        id="category_id" name="category_id" required>
                                    <option value="">Select Category</option>
                                    <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo $category['id']; ?>"
                                            <?php echo ($_POST['category_id'] ?? '') == $category['id'] ? 'selected' : ''; ?>>
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

                            <div class="form-group">
                                <label for="brand_id" class="form-label">Brand</label>
                                <select class="form-control" id="brand_id" name="brand_id">
                                    <option value="">Select Brand</option>
                                    <?php foreach ($brands as $brand_item): ?>
                                    <option value="<?php echo $brand_item['id']; ?>"
                                            <?php echo ($_POST['brand_id'] ?? '') == $brand_item['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($brand_item['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">
                                    <a href="../brands/add.php" target="_blank">Add new brand</a>
                                </div>
                            </div>
                        </div>



                        <div class="form-row">
                            <div class="form-group">
                                <label for="product_family_id" class="form-label">Product Family</label>
                                <select class="form-control" id="product_family_id" name="product_family_id">
                                    <option value="">Select Product Family</option>
                                    <?php foreach ($families as $family): ?>
                                    <option value="<?php echo $family['id']; ?>"
                                            <?php echo ($_POST['product_family_id'] ?? '') == $family['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($family['name']); ?> (<?php echo htmlspecialchars($family['base_unit']); ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">
                                    <a href="../product_families/add.php" target="_blank">Add new family</a>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="supplier_id" class="form-label">Supplier</label>
                                <select class="form-control" id="supplier_id" name="supplier_id">
                                    <option value="">Select Supplier</option>
                                    <?php foreach ($suppliers as $supplier_item): ?>
                                    <option value="<?php echo $supplier_item['id']; ?>"
                                            <?php echo ($_POST['supplier_id'] ?? '') == $supplier_item['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($supplier_item['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">
                                    <a href="../suppliers/add.php" target="_blank">Add new supplier</a>
                                </div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-control" id="status" name="status">
                                    <option value="active" <?php echo ($_POST['status'] ?? 'active') === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo ($_POST['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    <option value="discontinued" <?php echo ($_POST['status'] ?? '') === 'discontinued' ? 'selected' : ''; ?>>Discontinued</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <!-- Empty for balance -->
                            </div>
                        </div>
                    </div>

                    <!-- Physical Properties -->
                    <div class="form-section" id="physicalProperties" style="display: none;">
                        <h4 class="section-title">
                            <i class="bi bi-rulers me-2"></i>
                            Physical Properties
                        </h4>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="weight" class="form-label">Weight (kg)</label>
                                <input type="number" class="form-control <?php echo isset($errors['weight']) ? 'is-invalid' : ''; ?>"
                                       id="weight" name="weight" value="<?php echo htmlspecialchars($_POST['weight'] ?? ''); ?>"
                                       step="0.001" min="0" placeholder="0.000">
                                <?php if (isset($errors['weight'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['weight']); ?></div>
                                <?php endif; ?>
                            </div>

                            <div class="form-group">
                                <label for="length" class="form-label">Length (cm)</label>
                                <input type="number" class="form-control <?php echo isset($errors['length']) ? 'is-invalid' : ''; ?>"
                                       id="length" name="length" value="<?php echo htmlspecialchars($_POST['length'] ?? ''); ?>"
                                       step="0.01" min="0" placeholder="0.00">
                                <?php if (isset($errors['length'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['length']); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="width" class="form-label">Width (cm)</label>
                                <input type="number" class="form-control <?php echo isset($errors['width']) ? 'is-invalid' : ''; ?>"
                                       id="width" name="width" value="<?php echo htmlspecialchars($_POST['width'] ?? ''); ?>"
                                       step="0.01" min="0" placeholder="0.00">
                                <?php if (isset($errors['width'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['width']); ?></div>
                                <?php endif; ?>
                            </div>

                            <div class="form-group">
                                <label for="height" class="form-label">Height (cm)</label>
                                <input type="number" class="form-control <?php echo isset($errors['height']) ? 'is-invalid' : ''; ?>"
                                       id="height" name="height" value="<?php echo htmlspecialchars($_POST['height'] ?? ''); ?>"
                                       step="0.01" min="0" placeholder="0.00">
                                <?php if (isset($errors['height'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['height']); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Additional Settings -->
                    <div class="form-section">
                        <h4 class="section-title">
                            <i class="bi bi-gear me-2"></i>
                            Additional Settings
                        </h4>
                        <div class="form-group">
                            <label for="tags" class="form-label">Tags</label>
                            <input type="text" class="form-control" id="tags" name="tags"
                                   value="<?php echo htmlspecialchars($_POST['tags'] ?? ''); ?>"
                                   placeholder="Enter tags separated by commas">
                            <div class="form-text">
                                Tags help with product search and organization (e.g., electronics, wireless, portable)
                            </div>
                        </div>

                        <div class="form-check-group">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="is_serialized" name="is_serialized" value="1"
                                               <?php echo isset($_POST['is_serialized']) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="is_serialized">
                                            Serialized Product
                                        </label>
                                        <div class="form-text">
                                            Requires unique serial number tracking
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="allow_backorders" name="allow_backorders" value="1"
                                               <?php echo isset($_POST['allow_backorders']) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="allow_backorders">
                                            Allow Backorders
                                        </label>
                                        <div class="form-text">
                                            Allow sales when out of stock
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="track_inventory" name="track_inventory" value="1"
                                               <?php echo !isset($_POST['track_inventory']) || $_POST['track_inventory'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="track_inventory">
                                            Track Inventory
                                        </label>
                                        <div class="form-text">
                                            Enable inventory tracking for this product
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Publication Status Section -->
                    <div class="form-section" id="publicationSection">
                        <h4 class="section-title">
                            <i class="bi bi-globe me-2"></i>
                            Publication Settings
                        </h4>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="publication_status" class="form-label">Publication Status</label>
                                <select class="form-control" id="publication_status" name="publication_status" style="width: auto; min-width: 250px;">
                                    <option value="publish_now" <?php echo (($_POST['publication_status'] ?? 'publish_now') === 'publish_now') ? 'selected' : ''; ?>>Publish Immediately</option>
                                    <option value="draft" <?php echo (($_POST['publication_status'] ?? '') === 'draft') ? 'selected' : ''; ?>>Save as Draft</option>
                                    <option value="scheduled" <?php echo (($_POST['publication_status'] ?? '') === 'scheduled') ? 'selected' : ''; ?>>Schedule Publication</option>
                                </select>
                                <div class="form-text">
                                    Choose when this product should be available for sale
                                </div>
                            </div>
                        </div>

                        <!-- Scheduled Date Field -->
                        <div class="form-group" id="scheduledDateGroup" style="display: <?php echo (($_POST['publication_status'] ?? 'publish_now') === 'scheduled') ? 'block' : 'none'; ?>;">
                            <label for="scheduled_date" class="form-label">Scheduled Publication Date & Time *</label>
                            <input type="datetime-local" class="form-control <?php echo isset($errors['scheduled_date']) ? 'is-invalid' : ''; ?>"
                                   id="scheduled_date" name="scheduled_date"
                                   value="<?php echo htmlspecialchars($_POST['scheduled_date'] ?? ''); ?>"
                                   min="<?php echo date('Y-m-d\TH:i'); ?>"
                                   style="width: auto; min-width: 280px;">
                            <?php if (isset($errors['scheduled_date'])): ?>
                            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['scheduled_date']); ?></div>
                            <?php endif; ?>
                            <div class="form-text">
                                Select when you want this product to be automatically published and become available for sale.
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="d-flex gap-3">
                            <button type="submit" class="btn btn-primary" id="saveBtn">
                                <i class="bi bi-check"></i>
                                <span id="saveBtnText">Publish Product</span>
                            </button>
                            <a href="products.php" class="btn btn-outline-secondary">
                                <i class="bi bi-x"></i>
                                Cancel
                            </a>
                        </div>
                        <div class="form-text mt-2">
                            <small class="text-muted">
                                Choose your publication option above, then click save. You can always change the publication status later.
                            </small>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Help Section -->
            <div class="data-section mt-4">
                <div class="section-header">
                    <h3 class="section-title">
                        <i class="bi bi-question-circle me-2"></i>
                        Need Help?
                    </h3>
                </div>
                <div class="row">
                    <div class="col-md-4">
                        <h5><i class="bi bi-tag me-2"></i>Product Name</h5>
                        <p class="text-muted">Enter a clear and descriptive name for your product that customers will easily recognize.</p>
                    </div>
                    <div class="col-md-4">
                        <h5><i class="bi bi-folder me-2"></i>Category</h5>
                        <p class="text-muted">Choose the appropriate category to help organize your products and make them easier to find.</p>
                    </div>
                    <div class="col-md-4">
                        <h5><i class="bi bi-upc-scan me-2"></i>Barcode</h5>
                        <p class="text-muted">Use the existing barcode from your product or generate a new unique one for inventory tracking.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/products.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const publicationSelect = document.getElementById('publication_status');
            const scheduledDateGroup = document.getElementById('scheduledDateGroup');
            const saveBtnText = document.getElementById('saveBtnText');

            function updatePublicationUI() {
                const selectedValue = publicationSelect.value;

                // Update button text based on selection
                switch(selectedValue) {
                    case 'draft':
                        saveBtnText.textContent = 'Save as Draft';
                        if (scheduledDateGroup) scheduledDateGroup.style.display = 'none';
                        break;
                    case 'publish_now':
                        saveBtnText.textContent = 'Publish Product';
                        if (scheduledDateGroup) scheduledDateGroup.style.display = 'none';
                        break;
                    case 'scheduled':
                        saveBtnText.textContent = 'Schedule Product';
                        if (scheduledDateGroup) scheduledDateGroup.style.display = 'block';
                        break;
                }
            }

            // Add event listener to dropdown
            publicationSelect.addEventListener('change', updatePublicationUI);

            // Initialize UI on page load
            updatePublicationUI();

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

            // Form validation for scheduled date
            const scheduledDateInput = document.getElementById('scheduled_date');
            if (scheduledDateInput) {
                scheduledDateInput.addEventListener('change', function() {
                    const selectedDate = new Date(this.value);
                    const now = new Date();

                    if (selectedDate <= now) {
                        this.setCustomValidity('Scheduled date must be in the future');
                    } else {
                        this.setCustomValidity('');
                    }
                });
            }
        });
    </script>
</body>
</html>