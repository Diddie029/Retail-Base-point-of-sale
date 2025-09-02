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

// Check if user has permission to import products
if (!hasPermission('import_products', $permissions)) {
    header("Location: ../dashboard/dashboard.php");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get categories for mapping
$categories_stmt = $conn->query("SELECT * FROM categories ORDER BY name");
$categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);

$errors = [];
$success = '';
$import_results = [];

// SKU generation function
function generateCustomSKU($conn, $pattern = '', $prefix = '', $length = 6) {
    if (empty($pattern)) {
        // Default pattern: PROD + timestamp + random number
        $timestamp = date('ymd');
        $random = str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        $pattern = 'PROD' . $timestamp . $random;
    }

    // If pattern contains placeholders (0+), replace them with random numbers
    if (preg_match('/0+/', $pattern)) {
        $sku = preg_replace_callback('/0+/', function($matches) use ($length) {
            $zeros = strlen($matches[0]);
            return str_pad(rand(0, pow(10, $zeros) - 1), $zeros, '0', STR_PAD_LEFT);
        }, $pattern);
    } else {
        // Simple pattern replacement
        $sku = $pattern . str_pad(rand(1, pow(10, $length) - 1), $length, '0', STR_PAD_LEFT);
    }

    // Add prefix if specified
    if (!empty($prefix)) {
        $sku = $prefix . $sku;
    }

    // Check if SKU already exists and generate a new one if it does
    $original_sku = $sku;
    $counter = 1;
    while (true) {
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM products WHERE sku = :sku");
        $stmt->bindParam(':sku', $sku);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result['count'] == 0) {
            // SKU is unique
            break;
        }

        // Generate new SKU with counter
        $sku = $original_sku . '-' . $counter;
        $counter++;

        // Prevent infinite loop
        if ($counter > 1000) {
            $sku = $original_sku . '-' . time();
            break;
        }
    }

    return $sku;
}

// Handle CSV import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csvFile'])) {
    $file = $_FILES['csvFile'];

    // Enhanced security validation
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'File upload failed. Error code: ' . $file['error'];
    } elseif ($file['size'] > 1073741824) { // 1GB limit
        $errors[] = 'File size too large. Maximum size is 1GB.';
    } elseif ($file['size'] === 0) {
        $errors[] = 'Uploaded file is empty.';
    } elseif (!is_uploaded_file($file['tmp_name'])) {
        $errors[] = 'Invalid file upload detected.';
    } elseif (pathinfo($file['name'], PATHINFO_EXTENSION) !== 'csv') {
        $errors[] = 'Please upload a valid CSV file. Only .csv files are allowed.';
    } elseif (mime_content_type($file['tmp_name']) !== 'text/plain' &&
              mime_content_type($file['tmp_name']) !== 'text/csv' &&
              mime_content_type($file['tmp_name']) !== 'application/csv' &&
              mime_content_type($file['tmp_name']) !== 'application/vnd.ms-excel') {
        $errors[] = 'File type not allowed. Please upload a valid CSV file.';
    }

    if (empty($errors)) {
        // Process CSV file
        $handle = fopen($file['tmp_name'], 'r');
        if ($handle === false) {
            $errors[] = 'Could not read the CSV file.';
        } else {
            $row_count = 0;
            $success_count = 0;
            $error_count = 0;
            $skipped_count = 0;
            $import_errors = [];
            $duplicate_barcodes = [];
            $processed_barcodes = [];
            $duplicate_skus = [];
            $processed_skus = [];

            // Read header row
            $header = fgetcsv($handle);
            if (!$header || count($header) < 4) {
                $errors[] = 'Invalid CSV format. Expected at least 4 columns: Name, Category, Price, Quantity, Barcode (Description is optional)';
            } else {
                // Validate header format
                $header_lower = array_map('strtolower', array_map('trim', $header));
                $required_fields = ['name', 'category', 'price', 'quantity', 'barcode'];
                $optional_fields = ['sku', 'description', 'sale_price', 'sale_start_date', 'sale_end_date', 'tax_rate', 'brand', 'supplier'];

                $missing_fields = [];
                foreach ($required_fields as $field) {
                    if (!in_array($field, $header_lower)) {
                        $missing_fields[] = ucfirst($field);
                    }
                }

                if (!empty($missing_fields)) {
                    $errors[] = 'Missing required columns: ' . implode(', ', $missing_fields);
                } else {
                    // Expected columns mapping
                    $name_index = array_search('name', $header_lower);
                    $category_index = array_search('category', $header_lower);
                    $price_index = array_search('price', $header_lower);
                    $quantity_index = array_search('quantity', $header_lower);
                    $barcode_index = array_search('barcode', $header_lower);
                    $sku_index = array_search('sku', $header_lower);
                    $description_index = array_search('description', $header_lower);
                    $sale_price_index = array_search('sale_price', $header_lower);
                    $sale_start_date_index = array_search('sale_start_date', $header_lower);
                    $sale_end_date_index = array_search('sale_end_date', $header_lower);
                    $tax_rate_index = array_search('tax_rate', $header_lower);
                    $brand_index = array_search('brand', $header_lower);
                    $supplier_index = array_search('supplier', $header_lower);

                    // Process data rows
                    while (($data = fgetcsv($handle)) !== false) {
                        $row_count++;

                        // Skip empty rows or rows with insufficient data
                        if (empty(array_filter($data)) || count($data) < 5) {
                            $skipped_count++;
                            continue;
                        }

                        // Prevent processing too many rows (DOS protection)
                        if ($row_count > 10000) {
                            $errors[] = 'Import limit exceeded. Maximum 10,000 rows allowed per import.';
                            break;
                        }

                        // Extract and validate required fields using column mapping
                        $name = isset($data[$name_index]) ? trim($data[$name_index]) : '';
                        $category_name = isset($data[$category_index]) ? trim($data[$category_index]) : '';
                        $price = isset($data[$price_index]) ? (float)trim($data[$price_index]) : 0;
                        $quantity = isset($data[$quantity_index]) ? (int)trim($data[$quantity_index]) : 0;
                        $barcode = isset($data[$barcode_index]) ? trim($data[$barcode_index]) : '';
                        $sku = ($sku_index !== false && isset($data[$sku_index])) ? trim($data[$sku_index]) : '';
                        $description = ($description_index !== false && isset($data[$description_index])) ? trim($data[$description_index]) : '';
                        $sale_price = ($sale_price_index !== false && isset($data[$sale_price_index])) ? (float)trim($data[$sale_price_index]) : null;
                        $sale_start_date = ($sale_start_date_index !== false && isset($data[$sale_start_date_index])) ? trim($data[$sale_start_date_index]) : null;
                        $sale_end_date = ($sale_end_date_index !== false && isset($data[$sale_end_date_index])) ? trim($data[$sale_end_date_index]) : null;
                        $tax_rate = ($tax_rate_index !== false && isset($data[$tax_rate_index])) ? (float)trim($data[$tax_rate_index]) : null;
                        $brand_name = ($brand_index !== false && isset($data[$brand_index])) ? trim($data[$brand_index]) : null;
                        $supplier_name = ($supplier_index !== false && isset($data[$supplier_index])) ? trim($data[$supplier_index]) : null;

                        $row_errors = [];

                        // Enhanced field validation
                        if (empty($name)) {
                            $row_errors[] = 'Product name is required';
                        } elseif (strlen($name) > 255) {
                            $row_errors[] = 'Product name too long (maximum 255 characters)';
                        } elseif (preg_match('/[<>\"&\'\/\\\\]/', $name)) {
                            $row_errors[] = 'Product name contains invalid characters';
                        }

                        if (empty($category_name)) {
                            $row_errors[] = 'Category is required';
                        } elseif (strlen($category_name) > 100) {
                            $row_errors[] = 'Category name too long (maximum 100 characters)';
                        } elseif (preg_match('/[<>\"&\'\/\\\\]/', $category_name)) {
                            $row_errors[] = 'Category name contains invalid characters';
                        }

                        if ($price < 0) {
                            $row_errors[] = 'Price must be positive or zero';
                        } elseif ($price > 999999.99) {
                            $row_errors[] = 'Price too high (maximum 999,999.99)';
                        }

                        if ($quantity < 0) {
                            $row_errors[] = 'Quantity must be positive or zero';
                        } elseif ($quantity > 999999) {
                            $row_errors[] = 'Quantity too high (maximum 999,999)';
                        }

                        if (empty($barcode)) {
                            $row_errors[] = 'Barcode is required';
                        } elseif (strlen($barcode) > 100) {
                            $row_errors[] = 'Barcode too long (maximum 100 characters)';
                        } elseif (!preg_match('/^[a-zA-Z0-9\-_\.]+$/', $barcode)) {
                            $row_errors[] = 'Barcode contains invalid characters (only letters, numbers, hyphens, underscores, and dots allowed)';
                        } elseif (in_array($barcode, $processed_barcodes)) {
                            $row_errors[] = 'Duplicate barcode in import file';
                        }

                        // SKU validation (optional field, but if provided must be valid)
                        if (!empty($sku)) {
                            if (strlen($sku) > 100) {
                                $row_errors[] = 'SKU too long (maximum 100 characters)';
                            } elseif (!preg_match('/^[a-zA-Z0-9\-_\.]+$/', $sku)) {
                                $row_errors[] = 'SKU contains invalid characters (only letters, numbers, hyphens, underscores, and dots allowed)';
                            } elseif (in_array($sku, $processed_skus)) {
                                $row_errors[] = 'Duplicate SKU in import file';
                            }
                        }

                        if (strlen($description) > 1000) {
                            $row_errors[] = 'Description too long (maximum 1000 characters)';
                        }

                        // Sale information validation
                        if ($sale_price !== null) {
                            if ($sale_price < 0) {
                                $row_errors[] = 'Sale price must be positive or zero';
                            } elseif ($sale_price >= $price) {
                                $row_errors[] = 'Sale price must be less than regular price';
                            }
                        }

                        if ($sale_start_date && !strtotime($sale_start_date)) {
                            $row_errors[] = 'Invalid sale start date format';
                        }

                        if ($sale_end_date && !strtotime($sale_end_date)) {
                            $row_errors[] = 'Invalid sale end date format';
                        }

                        if ($sale_start_date && $sale_end_date) {
                            $start = strtotime($sale_start_date);
                            $end = strtotime($sale_end_date);
                            if ($start >= $end) {
                                $row_errors[] = 'Sale end date must be after start date';
                            }
                        }

                        if ($tax_rate !== null && ($tax_rate < 0 || $tax_rate > 100)) {
                            $row_errors[] = 'Tax rate must be between 0 and 100';
                        }

                        // Brand validation
                        if (!empty($brand_name)) {
                            if (strlen($brand_name) > 100) {
                                $row_errors[] = 'Brand name too long (maximum 100 characters)';
                            } elseif (preg_match('/[<>\"&\'\/\\\\]/', $brand_name)) {
                                $row_errors[] = 'Brand name contains invalid characters';
                            }
                        }

                        // Supplier validation
                        if (!empty($supplier_name)) {
                            if (strlen($supplier_name) > 100) {
                                $row_errors[] = 'Supplier name too long (maximum 100 characters)';
                            } elseif (preg_match('/[<>\"&\'\/\\\\]/', $supplier_name)) {
                                $row_errors[] = 'Supplier name contains invalid characters';
                            }
                        }
                    
                        if (!empty($row_errors)) {
                            $import_errors[] = "Row $row_count: " . implode(', ', $row_errors);
                            $error_count++;
                            continue;
                        }

                        // Track processed barcodes and SKUs to prevent duplicates in file
                        $processed_barcodes[] = $barcode;
                        if (!empty($sku)) {
                            $processed_skus[] = $sku;
                        }

                        // Find or create category with better error handling
                        $category_id = null;
                        foreach ($categories as $category) {
                            if (strcasecmp($category['name'], $category_name) === 0) {
                                $category_id = $category['id'];
                                break;
                            }
                        }

                        if (!$category_id) {
                            // Create new category with transaction safety
                            try {
                                $conn->beginTransaction();
                                $cat_stmt = $conn->prepare("INSERT INTO categories (name) VALUES (:name)");
                                $cat_stmt->bindParam(':name', $category_name);
                                if ($cat_stmt->execute()) {
                                    $category_id = $conn->lastInsertId();
                                    // Add to categories array for future rows
                                    $categories[] = ['id' => $category_id, 'name' => $category_name];
                                }
                                $conn->commit();
                            } catch (PDOException $e) {
                                $conn->rollBack();
                                if ($e->getCode() == 23000) { // Duplicate entry
                                    // Category might have been created by another concurrent process
                                    $cat_check = $conn->prepare("SELECT id FROM categories WHERE name = :name");
                                    $cat_check->bindParam(':name', $category_name);
                                    $cat_check->execute();
                                    $existing = $cat_check->fetch();
                                    if ($existing) {
                                        $category_id = $existing['id'];
                                        $categories[] = ['id' => $category_id, 'name' => $category_name];
                                    } else {
                                        $import_errors[] = "Row $row_count: Could not create or find category '$category_name'";
                                        $error_count++;
                                        continue;
                                    }
                                } else {
                                    $import_errors[] = "Row $row_count: Database error creating category '$category_name'";
                                    $error_count++;
                                    continue;
                                }
                            }
                        }

                        // Find or create brand
                        $brand_id = null;
                        if (!empty($brand_name)) {
                            $brand_check = $conn->prepare("SELECT id FROM brands WHERE name = :name");
                            $brand_check->bindParam(':name', $brand_name);
                            $brand_check->execute();
                            $existing_brand = $brand_check->fetch();
                            
                            if ($existing_brand) {
                                $brand_id = $existing_brand['id'];
                            } else {
                                try {
                                    $brand_stmt = $conn->prepare("INSERT INTO brands (name) VALUES (:name)");
                                    $brand_stmt->bindParam(':name', $brand_name);
                                    if ($brand_stmt->execute()) {
                                        $brand_id = $conn->lastInsertId();
                                    }
                                } catch (PDOException $e) {
                                    $import_errors[] = "Row $row_count: Could not create brand '$brand_name'";
                                    $error_count++;
                                    continue;
                                }
                            }
                        }

                        // Find or create supplier
                        $supplier_id = null;
                        if (!empty($supplier_name)) {
                            $supplier_check = $conn->prepare("SELECT id FROM suppliers WHERE name = :name");
                            $supplier_check->bindParam(':name', $supplier_name);
                            $supplier_check->execute();
                            $existing_supplier = $supplier_check->fetch();
                            
                            if ($existing_supplier) {
                                $supplier_id = $existing_supplier['id'];
                            } else {
                                try {
                                    $supplier_stmt = $conn->prepare("INSERT INTO suppliers (name) VALUES (:name)");
                                    $supplier_stmt->bindParam(':name', $supplier_name);
                                    if ($supplier_stmt->execute()) {
                                        $supplier_id = $conn->lastInsertId();
                                    }
                                } catch (PDOException $e) {
                                    $import_errors[] = "Row $row_count: Could not create supplier '$supplier_name'";
                                    $error_count++;
                                    continue;
                                }
                            }
                        }

                        // Check if barcode already exists in database
                        $barcode_check = $conn->prepare("SELECT id FROM products WHERE barcode = :barcode");
                        $barcode_check->bindParam(':barcode', $barcode);
                        $barcode_check->execute();
                        if ($barcode_check->fetch()) {
                            $import_errors[] = "Row $row_count: Barcode '$barcode' already exists in database";
                            $error_count++;
                            continue;
                        }

                        // Auto-generate SKU if not provided or check uniqueness if provided
                        if (empty($sku)) {
                            // Auto-generate SKU based on category
                            $category_prefix = '';
                            if ($category_name) {
                                // Create a short prefix from category name
                                $words = explode(' ', $category_name);
                                $category_prefix = strtoupper(substr($words[0], 0, 3));
                            }
                            $sku = generateCustomSKU($conn, '', $category_prefix);
                        } else {
                            // Check if SKU already exists in database
                            $sku_check = $conn->prepare("SELECT id FROM products WHERE sku = :sku");
                            $sku_check->bindParam(':sku', $sku);
                            $sku_check->execute();
                            if ($sku_check->fetch()) {
                                $import_errors[] = "Row $row_count: SKU '$sku' already exists in database";
                                $error_count++;
                                continue;
                            }
                        }

                        // Insert product with enhanced error handling and transaction safety
                        try {
                            $conn->beginTransaction();

                            $insert_stmt = $conn->prepare("
                                INSERT INTO products (
                                    name, category_id, brand_id, supplier_id, price, quantity, barcode, sku, description,
                                    tax_rate, sale_price, sale_start_date, sale_end_date,
                                    status, created_at, updated_at
                                )
                                VALUES (
                                    :name, :category_id, :brand_id, :supplier_id, :price, :quantity, :barcode, :sku, :description,
                                    :tax_rate, :sale_price, :sale_start_date, :sale_end_date,
                                    'active', NOW(), NOW()
                                )
                            ");

                            $insert_stmt->bindParam(':name', $name);
                            $insert_stmt->bindParam(':category_id', $category_id, PDO::PARAM_INT);
                            $insert_stmt->bindParam(':brand_id', $brand_id, PDO::PARAM_INT);
                            $insert_stmt->bindParam(':supplier_id', $supplier_id, PDO::PARAM_INT);
                            $insert_stmt->bindParam(':price', $price);
                            $insert_stmt->bindParam(':quantity', $quantity, PDO::PARAM_INT);
                            $insert_stmt->bindParam(':barcode', $barcode);
                            $insert_stmt->bindParam(':sku', $sku);
                            $insert_stmt->bindParam(':description', $description);
                            $insert_stmt->bindParam(':tax_rate', $tax_rate);
                            $insert_stmt->bindParam(':sale_price', $sale_price);
                            $insert_stmt->bindParam(':sale_start_date', $sale_start_date);
                            $insert_stmt->bindParam(':sale_end_date', $sale_end_date);

                            if ($insert_stmt->execute()) {
                                $success_count++;
                                $conn->commit();
                            } else {
                                $conn->rollBack();
                                $import_errors[] = "Row $row_count: Failed to insert product '$name' - database error";
                                $error_count++;
                            }
                        } catch (PDOException $e) {
                            $conn->rollBack();

                            if ($e->getCode() == 23000) { // Duplicate entry
                                $import_errors[] = "Row $row_count: Product with barcode '$barcode' already exists";
                            } else {
                                $import_errors[] = "Row $row_count: Database error inserting product '$name' - " . $e->getMessage();
                            }
                            $error_count++;
                        }
                    }
                    
                    fclose($handle);
                    
                    // Set results
                    $import_results = [
                        'total_rows' => $row_count,
                        'success_count' => $success_count,
                        'error_count' => $error_count,
                        'skipped_count' => $skipped_count,
                        'errors' => $import_errors
                    ];
                    
                    if ($success_count > 0) {
                        $success = "$success_count products imported successfully!";
                    }
                    
                    if ($error_count > 0 || $skipped_count > 0) {
                        $errors[] = "Import completed with issues. See details below.";
                    }
                }
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
    <title>Import Products - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/products.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --success-color: #10b981;
        }

        .file-upload-area {
            position: relative;
            border: 2px dashed var(--primary-color);
            border-radius: 10px;
            padding: 3rem 2rem;
            text-align: center;
            transition: all 0.3s ease;
            cursor: pointer;
            background: rgba(99, 102, 241, 0.05);
        }

        .file-upload-area:hover,
        .file-upload-area.drag-over {
            background: rgba(99, 102, 241, 0.1);
            border-color: var(--primary-color);
            transform: scale(1.02);
        }

        .file-input {
            position: absolute;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
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
                    <h1>Import Products</h1>
                    <div class="header-subtitle">Bulk import products from CSV file</div>
                </div>
                <div class="header-actions">
                    <div class="user-info">
                        <div class="user-avatar"><?php echo strtoupper(substr($username, 0, 1)); ?></div>
                        <span><?php echo htmlspecialchars($username); ?></span>
                    </div>
                </div>
            </div>
        </header>

        <!-- Content -->
        <div class="content">
            <?php if (!empty($success)): ?>
            <div class="alert alert-success">
                <i class="bi bi-check-circle me-2"></i>
                <?php echo htmlspecialchars($success); ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-circle me-2"></i>
                <?php foreach ($errors as $error): ?>
                    <div><?php echo htmlspecialchars($error); ?></div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Import Results -->
            <?php if (!empty($import_results)): ?>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-primary">
                            <i class="bi bi-file-text"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($import_results['total_rows']); ?></div>
                    <div class="stat-label">Total Rows Processed</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-success">
                            <i class="bi bi-check-circle"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($import_results['success_count']); ?></div>
                    <div class="stat-label">Successfully Imported</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-danger">
                            <i class="bi bi-x-circle"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($import_results['error_count']); ?></div>
                    <div class="stat-label">Errors</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-warning">
                            <i class="bi bi-dash-circle"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($import_results['skipped_count']); ?></div>
                    <div class="stat-label">Skipped (Empty Rows)</div>
                </div>
            </div>

            <?php if (!empty($import_results['errors'])): ?>
            <div class="data-section">
                <div class="section-header">
                    <h3 class="section-title text-danger">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        Import Errors
                    </h3>
                </div>
                <div class="alert alert-warning">
                    <?php foreach ($import_results['errors'] as $error): ?>
                        <div><?php echo htmlspecialchars($error); ?></div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <!-- Import Form -->
            <div class="import-export-section">
                <div class="section-header">
                    <h3 class="section-title">
                        <i class="bi bi-upload me-2"></i>
                        Upload CSV File
                    </h3>
                </div>
                
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    <strong>Need help?</strong> Download our <a href="export_products.php?export_type=template" class="alert-link">sample CSV template</a> to see the correct format.
                </div>

                <form method="POST" enctype="multipart/form-data" id="importForm">
                    <div class="file-upload-area" id="uploadArea">
                        <i class="bi bi-cloud-upload" style="font-size: 3rem; color: var(--primary-color);"></i>
                        <h4 class="mt-3">Drop your CSV file here or click to browse</h4>
                        <p class="text-muted mb-2">Maximum file size: 1GB | Format: CSV only</p>
                        <p class="text-muted small">Files are scanned for security before processing</p>
                        <input type="file" id="csvFile" name="csvFile" accept=".csv" class="file-input" required>
                    </div>

                    <div class="text-center mt-3">
                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="bi bi-upload"></i>
                            Import Products
                        </button>
                        <p class="text-muted mt-2 small">Import process includes security validation and duplicate checking</p>
                    </div>
                </form>
            </div>

            <!-- CSV Format Information -->
            <div class="data-section">
                <div class="section-header">
                    <h3 class="section-title">
                        <i class="bi bi-info-circle me-2"></i>
                        CSV Format Requirements
                    </h3>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                                                 <h5>Required Columns (in order):</h5>
                         <ol>
                             <li><strong>Name</strong> - Product name (required)</li>
                             <li><strong>Category</strong> - Category name (required, will be created if doesn't exist)</li>
                             <li><strong>Brand</strong> - Brand name (optional, will be created if doesn't exist)</li>
                             <li><strong>Supplier</strong> - Supplier name (optional, will be created if doesn't exist)</li>
                             <li><strong>Price</strong> - Product price (required, positive number)</li>
                             <li><strong>Quantity</strong> - Initial stock quantity (required, positive number)</li>
                             <li><strong>Barcode</strong> - Unique barcode (required)</li>
                             <li><strong>SKU</strong> - Stock Keeping Unit (optional, will be auto-generated if not provided)</li>
                             <li><strong>Description</strong> - Product description (optional)</li>
                         </ol>
                    </div>
                    <div class="col-md-6">
                                                 <h5>Important Notes:</h5>
                         <ul>
                             <li>First row should contain column headers</li>
                             <li>All barcodes and SKUs must be unique</li>
                             <li>Categories, brands, and suppliers will be created automatically if they don't exist</li>
                             <li>SKU will be auto-generated if not provided (format: CAT-XXX-001)</li>
                             <li>Empty rows will be skipped</li>
                             <li>Maximum file size is 1GB</li>
                             <li>File must be in CSV format</li>
                         </ul>
                    </div>
                </div>
                
                <div class="mt-4">
                    <h5>Sample CSV Format:</h5>
                    <div class="table-responsive">
                                                 <table class="table table-bordered">
                             <thead>
                                 <tr>
                                     <th>Name</th>
                                     <th>Category</th>
                                     <th>Brand</th>
                                     <th>Supplier</th>
                                     <th>Price</th>
                                     <th>Quantity</th>
                                     <th>Barcode</th>
                                     <th>SKU</th>
                                     <th>Description</th>
                                 </tr>
                             </thead>
                             <tbody>
                                 <tr>
                                     <td>Laptop Computer</td>
                                     <td>Electronics</td>
                                     <td>Dell</td>
                                     <td>Tech Solutions Inc</td>
                                     <td>1299.99</td>
                                     <td>10</td>
                                     <td>LAP001</td>
                                     <td>ELE-LAP-001</td>
                                     <td>High-performance laptop for business use</td>
                                 </tr>
                                 <tr>
                                     <td>Coffee Mug</td>
                                     <td>Home & Kitchen</td>
                                     <td>KitchenAid</td>
                                     <td>Home Goods Co</td>
                                     <td>15.50</td>
                                     <td>50</td>
                                     <td>MUG001</td>
                                     <td>HK-CM-001</td>
                                     <td>Ceramic coffee mug with company logo</td>
                                 </tr>
                             </tbody>
                         </table>
                    </div>
                </div>
                
                <div class="text-center mt-4">
                    <a href="#" class="btn btn-outline-secondary" onclick="downloadSampleCSV()">
                        <i class="bi bi-download"></i>
                        Download Sample CSV
                    </a>
                </div>
            </div>

            <!-- Existing Categories -->
            <?php if (!empty($categories)): ?>
            <div class="data-section">
                <div class="section-header">
                    <h3 class="section-title">
                        <i class="bi bi-tags me-2"></i>
                        Existing Categories
                    </h3>
                </div>
                <div class="row">
                    <?php foreach ($categories as $category): ?>
                    <div class="col-md-3 mb-2">
                        <span class="badge badge-secondary"><?php echo htmlspecialchars($category['name']); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="form-text">
                    You can use these existing categories in your CSV, or create new ones by specifying different category names.
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/products.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const importForm = document.getElementById('importForm');
            const csvFileInput = document.getElementById('csvFile');
            const uploadArea = document.getElementById('uploadArea');

            // Enhanced file validation
            csvFileInput.addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (file) {
                    // Client-side validation
                    if (file.size > 1073741824) { // 1GB
                        alert('File size too large. Maximum size is 1GB.');
                        this.value = '';
                        return;
                    }

                    if (!file.name.toLowerCase().endsWith('.csv')) {
                        alert('Please select a CSV file.');
                        this.value = '';
                        return;
                    }

                    // Update UI to show selected file
                    const fileName = file.name;
                    const fileSize = (file.size / 1024 / 1024).toFixed(2) + ' MB';

                    uploadArea.innerHTML = `
                        <i class="bi bi-file-earmark-check" style="font-size: 3rem; color: var(--success-color);"></i>
                        <h4 class="mt-3">File Selected</h4>
                        <p class="mb-1"><strong>${fileName}</strong></p>
                        <p class="text-muted small">Size: ${fileSize}</p>
                        <input type="file" id="csvFile" name="csvFile" accept=".csv" class="file-input" required>
                    `;
                }
            });

            // Drag and drop functionality
            uploadArea.addEventListener('dragover', function(e) {
                e.preventDefault();
                this.classList.add('drag-over');
            });

            uploadArea.addEventListener('dragleave', function(e) {
                e.preventDefault();
                this.classList.remove('drag-over');
            });

            uploadArea.addEventListener('drop', function(e) {
                e.preventDefault();
                this.classList.remove('drag-over');

                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    const file = files[0];

                    // Validate dropped file
                    if (!file.name.toLowerCase().endsWith('.csv')) {
                        alert('Please drop a CSV file.');
                        return;
                    }

                    if (file.size > 1073741824) {
                        alert('File size too large. Maximum size is 1GB.');
                        return;
                    }

                    // Set the file to the input
                    csvFileInput.files = files;

                    // Trigger change event
                    const event = new Event('change');
                    csvFileInput.dispatchEvent(event);
                }
            });

            // Form submission validation
            importForm.addEventListener('submit', function(e) {
                const file = csvFileInput.files[0];
                if (!file) {
                    e.preventDefault();
                    alert('Please select a CSV file to import.');
                    return;
                }

                // Show loading state
                const submitBtn = this.querySelector('button[type="submit"]');
                const originalText = submitBtn.innerHTML;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing...';
                submitBtn.disabled = true;

                // Re-enable button after 30 seconds as fallback
                setTimeout(() => {
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                }, 30000);
            });
        });

        function downloadSampleCSV() {
            const csvContent = "Name,Category,Brand,Supplier,Price,Quantity,Barcode,SKU,Description,Sale Price,Sale Start Date,Sale End Date,Tax Rate\n" +
                               "Wireless Mouse,Electronics,Logitech,Tech Supplies Ltd,29.99,50,MSE001,ELE-WM-001,Wireless optical mouse with USB receiver,24.99,2024-01-15 10:00,2024-01-31 23:59,16.00\n" +
                               "Coffee Mug,Home & Kitchen,KitchenAid,Home Goods Co,15.50,100,MUG001,HK-CM-001,Ceramic coffee mug with company logo,,,,\n" +
                               "Notebook,Office Supplies,Moleskine,Office Depot,5.99,200,NBK001,OS-NB-001,Spiral-bound notebook, 100 pages,4.99,2024-02-01 00:00,2024-02-28 23:59,16.00\n" +
                               "T-Shirt,Clothing,Nike,Sports Wear Inc,19.99,75,TSH001,CL-TS-001,Cotton t-shirt, various sizes available,15.99,2024-01-20 09:00,2024-01-25 23:59,18.00\n" +
                               "Desk Lamp,Furniture,IKEA,Furniture World,45.00,25,DLP001,FN-DL-001,LED desk lamp with adjustable brightness,,,,16.00";

            const blob = new Blob([csvContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'sample_import_template.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }
    </script>
</body>
</html>