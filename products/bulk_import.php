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

// Handle template download
if (isset($_GET['action']) && $_GET['action'] === 'template') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="product_import_template.csv"');
    
    // CSV headers
    $headers = [
        'name', 'sku', 'barcode', 'description', 'category', 'brand', 'supplier',
        'price', 'sale_price', 'sale_start_date', 'sale_end_date', 'cost_price',
        'quantity', 'min_stock_level', 'tax_rate', 'status', 'weight', 'dimensions'
    ];
    
    echo implode(',', $headers) . "\n";
    
    // Sample data row
    $sample = [
        'Sample Product', 'PROD001', '1234567890123', 'Sample product description',
        'Electronics', 'Sample Brand', 'Sample Supplier', '99.99', '89.99',
        '2024-01-01', '2024-12-31', '75.00', '100', '10', '10.00', 'active',
        '1.5', '10x8x2'
    ];
    
    echo implode(',', array_map(function($field) {
        return '"' . str_replace('"', '""', $field) . '"';
    }, $sample)) . "\n";
    
    exit();
}

// Get reference data
$categories_stmt = $conn->query("SELECT * FROM categories ORDER BY name");
$categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);

$brands_stmt = $conn->query("SELECT * FROM brands ORDER BY name");
$brands = $brands_stmt->fetchAll(PDO::FETCH_ASSOC);

$suppliers_stmt = $conn->query("SELECT * FROM suppliers ORDER BY name");
$suppliers = $suppliers_stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle CSV import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csvFile'])) {
    $file = $_FILES['csvFile'];
    $import_mode = $_POST['import_mode'] ?? 'create_only';
    $duplicate_handling = $_POST['duplicate_handling'] ?? 'skip';
    
    $errors = [];
    $success_count = 0;
    $error_count = 0;
    $skipped_count = 0;
    $updated_count = 0;
    $import_errors = [];

    // Validate file
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'File upload failed. Error code: ' . $file['error'];
    } elseif ($file['size'] > 10485760) { // 10MB limit
        $errors[] = 'File size too large. Maximum size is 10MB.';
    } elseif ($file['size'] === 0) {
        $errors[] = 'Uploaded file is empty.';
    } elseif (pathinfo($file['name'], PATHINFO_EXTENSION) !== 'csv') {
        $errors[] = 'Please upload a valid CSV file.';
    }

    if (empty($errors)) {
        $handle = fopen($file['tmp_name'], 'r');
        if ($handle === false) {
            $errors[] = 'Could not read the CSV file.';
        } else {
            try {
                $conn->beginTransaction();
                
                $row_count = 0;
                $header = fgetcsv($handle);
                
                if (!$header) {
                    $errors[] = 'Invalid CSV format. Could not read header row.';
                } else {
                    // Normalize headers
                    $header_map = [];
                    foreach ($header as $index => $col) {
                        $header_map[strtolower(trim($col))] = $index;
                    }
                    
                    // Required fields mapping
                    $required_fields = ['name', 'price'];
                    $optional_fields = [
                        'sku', 'barcode', 'description', 'category', 'brand', 'supplier',
                        'sale_price', 'sale_start_date', 'sale_end_date', 'cost_price',
                        'quantity', 'min_stock_level', 'tax_rate', 'status', 'weight', 'dimensions'
                    ];
                    
                    // Check for required fields
                    $missing_required = [];
                    foreach ($required_fields as $field) {
                        if (!isset($header_map[$field])) {
                            $missing_required[] = $field;
                        }
                    }
                    
                    if (!empty($missing_required)) {
                        $errors[] = 'Missing required columns: ' . implode(', ', $missing_required);
                    } else {
                        // Process data rows
                        while (($data = fgetcsv($handle)) !== false && $row_count < 5000) {
                            $row_count++;
                            
                            // Skip empty rows
                            if (empty(array_filter($data))) {
                                $skipped_count++;
                                continue;
                            }
                            
                            // Extract data
                            $product_data = [];
                            
                            // Required fields
                            $product_data['name'] = isset($header_map['name']) ? trim($data[$header_map['name']]) : '';
                            $product_data['price'] = isset($header_map['price']) ? (float)trim($data[$header_map['price']]) : 0;
                            
                            // Optional fields
                            $product_data['sku'] = isset($header_map['sku']) ? trim($data[$header_map['sku']]) : '';
                            $product_data['barcode'] = isset($header_map['barcode']) ? trim($data[$header_map['barcode']]) : '';
                            $product_data['description'] = isset($header_map['description']) ? trim($data[$header_map['description']]) : '';
                            $product_data['sale_price'] = isset($header_map['sale_price']) ? (float)trim($data[$header_map['sale_price']]) : null;
                            $product_data['cost_price'] = isset($header_map['cost_price']) ? (float)trim($data[$header_map['cost_price']]) : null;
                            $product_data['quantity'] = isset($header_map['quantity']) ? (int)trim($data[$header_map['quantity']]) : 0;
                            $product_data['min_stock_level'] = isset($header_map['min_stock_level']) ? (int)trim($data[$header_map['min_stock_level']]) : 0;
                            $product_data['tax_rate'] = isset($header_map['tax_rate']) ? (float)trim($data[$header_map['tax_rate']]) : 0;
                            $product_data['status'] = isset($header_map['status']) ? trim($data[$header_map['status']]) : 'active';
                            $product_data['weight'] = isset($header_map['weight']) ? trim($data[$header_map['weight']]) : null;
                            $product_data['dimensions'] = isset($header_map['dimensions']) ? trim($data[$header_map['dimensions']]) : null;
                            
                            // Handle category
                            $category_id = null;
                            if (isset($header_map['category']) && !empty($data[$header_map['category']])) {
                                $category_name = trim($data[$header_map['category']]);
                                $cat_stmt = $conn->prepare("SELECT id FROM categories WHERE name = :name");
                                $cat_stmt->bindParam(':name', $category_name);
                                $cat_stmt->execute();
                                $cat_result = $cat_stmt->fetch(PDO::FETCH_ASSOC);
                                if ($cat_result) {
                                    $category_id = $cat_result['id'];
                                }
                            }
                            
                            // Handle brand
                            $brand_id = null;
                            if (isset($header_map['brand']) && !empty($data[$header_map['brand']])) {
                                $brand_name = trim($data[$header_map['brand']]);
                                $brand_stmt = $conn->prepare("SELECT id FROM brands WHERE name = :name");
                                $brand_stmt->bindParam(':name', $brand_name);
                                $brand_stmt->execute();
                                $brand_result = $brand_stmt->fetch(PDO::FETCH_ASSOC);
                                if ($brand_result) {
                                    $brand_id = $brand_result['id'];
                                }
                            }
                            
                            // Handle supplier
                            $supplier_id = null;
                            if (isset($header_map['supplier']) && !empty($data[$header_map['supplier']])) {
                                $supplier_name = trim($data[$header_map['supplier']]);
                                $supplier_stmt = $conn->prepare("SELECT id FROM suppliers WHERE name = :name");
                                $supplier_stmt->bindParam(':name', $supplier_name);
                                $supplier_stmt->execute();
                                $supplier_result = $supplier_stmt->fetch(PDO::FETCH_ASSOC);
                                if ($supplier_result) {
                                    $supplier_id = $supplier_result['id'];
                                }
                            }
                            
                            // Validate required data
                            if (empty($product_data['name']) || $product_data['price'] <= 0) {
                                $import_errors[] = "Row $row_count: Missing required fields (name and price)";
                                $error_count++;
                                continue;
                            }
                            
                            // Generate SKU if not provided
                            if (empty($product_data['sku'])) {
                                $product_data['sku'] = 'PROD' . date('ymd') . str_pad($row_count, 4, '0', STR_PAD_LEFT);
                            }
                            
                            // Check for duplicates
                            $duplicate_check = false;
                            if (!empty($product_data['sku'])) {
                                $dup_stmt = $conn->prepare("SELECT id FROM products WHERE sku = :sku");
                                $dup_stmt->bindParam(':sku', $product_data['sku']);
                                $dup_stmt->execute();
                                $duplicate_check = $dup_stmt->fetch(PDO::FETCH_ASSOC);
                            }
                            
                            if ($duplicate_check) {
                                if ($duplicate_handling === 'skip') {
                                    $skipped_count++;
                                    continue;
                                } elseif ($duplicate_handling === 'update') {
                                    // Update existing product
                                    $update_sql = "
                                        UPDATE products SET 
                                        name = :name, price = :price, description = :description,
                                        category_id = :category_id, brand_id = :brand_id, supplier_id = :supplier_id,
                                        sale_price = :sale_price, cost_price = :cost_price, quantity = :quantity,
                                        min_stock_level = :min_stock_level, tax_rate = :tax_rate, status = :status,
                                        weight = :weight, dimensions = :dimensions, updated_at = NOW()
                                        WHERE id = :id
                                    ";
                                    $update_stmt = $conn->prepare($update_sql);
                                    $update_stmt->execute([
                                        ':name' => $product_data['name'],
                                        ':price' => $product_data['price'],
                                        ':description' => $product_data['description'],
                                        ':category_id' => $category_id,
                                        ':brand_id' => $brand_id,
                                        ':supplier_id' => $supplier_id,
                                        ':sale_price' => $product_data['sale_price'],
                                        ':cost_price' => $product_data['cost_price'],
                                        ':quantity' => $product_data['quantity'],
                                        ':min_stock_level' => $product_data['min_stock_level'],
                                        ':tax_rate' => $product_data['tax_rate'],
                                        ':status' => $product_data['status'],
                                        ':weight' => $product_data['weight'],
                                        ':dimensions' => $product_data['dimensions'],
                                        ':id' => $duplicate_check['id']
                                    ]);
                                    $updated_count++;
                                    continue;
                                }
                            }
                            
                            // Insert new product
                            $insert_sql = "
                                INSERT INTO products (
                                    name, sku, barcode, description, category_id, brand_id, supplier_id,
                                    price, sale_price, cost_price, quantity, min_stock_level,
                                    tax_rate, status, weight, dimensions, created_at, updated_at
                                ) VALUES (
                                    :name, :sku, :barcode, :description, :category_id, :brand_id, :supplier_id,
                                    :price, :sale_price, :cost_price, :quantity, :min_stock_level,
                                    :tax_rate, :status, :weight, :dimensions, NOW(), NOW()
                                )
                            ";
                            
                            $insert_stmt = $conn->prepare($insert_sql);
                            if ($insert_stmt->execute([
                                ':name' => $product_data['name'],
                                ':sku' => $product_data['sku'],
                                ':barcode' => $product_data['barcode'],
                                ':description' => $product_data['description'],
                                ':category_id' => $category_id,
                                ':brand_id' => $brand_id,
                                ':supplier_id' => $supplier_id,
                                ':price' => $product_data['price'],
                                ':sale_price' => $product_data['sale_price'],
                                ':cost_price' => $product_data['cost_price'],
                                ':quantity' => $product_data['quantity'],
                                ':min_stock_level' => $product_data['min_stock_level'],
                                ':tax_rate' => $product_data['tax_rate'],
                                ':status' => $product_data['status'],
                                ':weight' => $product_data['weight'],
                                ':dimensions' => $product_data['dimensions']
                            ])) {
                                $success_count++;
                            } else {
                                $import_errors[] = "Row $row_count: Failed to insert product";
                                $error_count++;
                            }
                        }
                    }
                }
                
                $conn->commit();
                
                // Prepare success message
                $messages = [];
                if ($success_count > 0) $messages[] = "$success_count products imported";
                if ($updated_count > 0) $messages[] = "$updated_count products updated";
                if ($skipped_count > 0) $messages[] = "$skipped_count products skipped (duplicates)";
                if ($error_count > 0) $messages[] = "$error_count errors occurred";
                
                $_SESSION['success'] = "Import completed: " . implode(', ', $messages);
                
                if (!empty($import_errors)) {
                    $_SESSION['import_errors'] = $import_errors;
                }
                
                // Log the import
                try {
                    $log_stmt = $conn->prepare("
                        INSERT INTO activity_logs (user_id, username, action, details, created_at) 
                        VALUES (:user_id, :username, :action, :details, NOW())
                    ");
                    $log_stmt->execute([
                        ':user_id' => $user_id,
                        ':username' => $username,
                        ':action' => 'bulk_import_products',
                        ':details' => "Imported $success_count, updated $updated_count, skipped $skipped_count products"
                    ]);
                } catch (Exception $e) {
                    // Log table might not exist, ignore
                }
                
            } catch (Exception $e) {
                $conn->rollBack();
                $errors[] = 'Database error: ' . $e->getMessage();
            }
            
            fclose($handle);
        }
    }
    
    if (!empty($errors)) {
        $_SESSION['error'] = implode('<br>', $errors);
    }
    
    header("Location: bulk_import.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk Import Products - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Main layout alignment */
        .main-content {
            margin-left: 250px;
            padding: 0;
            min-height: 100vh;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
        }
        
        .content {
            padding: 20px;
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
        }
        
        /* Modern section styling */
        .import-section, .template-section, .preview-section {
            border: none;
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
            position: relative;
            overflow: hidden;
        }
        
        .import-section {
            background: rgba(255, 255, 255, 0.95);
        }
        
        .import-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #f093fb 0%, #f5576c 100%);
        }
        
        .template-section {
            background: rgba(209, 236, 241, 0.95);
            border: 1px solid #bee5eb;
        }
        
        .template-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #4facfe 0%, #00f2fe 100%);
        }
        
        /* Enhanced drag and drop zone */
        .drop-zone {
            border: 3px dashed rgba(79, 172, 254, 0.3);
            border-radius: 20px;
            padding: 3rem;
            text-align: center;
            background: linear-gradient(135deg, rgba(79, 172, 254, 0.05), rgba(0, 242, 254, 0.05));
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
            position: relative;
            overflow: hidden;
            min-height: 200px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        
        .drop-zone::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(79, 172, 254, 0.1), transparent 70%);
            transform: scale(0);
            transition: transform 0.6s ease;
        }
        
        .drop-zone:hover {
            border-color: #4facfe;
            background: linear-gradient(135deg, rgba(79, 172, 254, 0.1), rgba(0, 242, 254, 0.1));
            transform: translateY(-5px) scale(1.02);
            box-shadow: 0 20px 40px rgba(79, 172, 254, 0.2);
        }
        
        .drop-zone:hover::before {
            transform: scale(1);
        }
        
        .drop-zone.dragover {
            border-color: #f093fb;
            background: linear-gradient(135deg, rgba(240, 147, 251, 0.15), rgba(245, 87, 108, 0.15));
            transform: translateY(-8px) scale(1.05);
            box-shadow: 0 25px 50px rgba(240, 147, 251, 0.3);
        }
        
        .drop-zone-icon {
            font-size: 4rem;
            color: #4facfe;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
        }
        
        .drop-zone:hover .drop-zone-icon {
            transform: scale(1.2) rotate(5deg);
            color: #f093fb;
        }
        
        .drop-zone-text {
            font-size: 1.25rem;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 0.5rem;
        }
        
        .drop-zone-subtext {
            color: #6c757d;
            margin-bottom: 1.5rem;
        }
        
        /* Field mapping enhancement */
        .field-mapping {
            background: linear-gradient(135deg, rgba(255, 236, 205, 0.95), rgba(252, 182, 159, 0.95));
            padding: 1.5rem;
            border-radius: 16px;
            margin-top: 1.5rem;
            box-shadow: 0 4px 20px rgba(252, 182, 159, 0.2);
            border: none;
        }
        
        /* Enhanced form controls */
        .form-select, .form-control {
            border-radius: 12px;
            border: 2px solid #e3f2fd;
            padding: 12px 16px;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.9);
        }
        
        .form-select:focus, .form-control:focus {
            border-color: #4facfe;
            box-shadow: 0 0 0 0.2rem rgba(79, 172, 254, 0.15);
            background: rgba(255, 255, 255, 1);
        }
        
        /* Enhanced buttons */
        .btn-modern {
            border-radius: 12px;
            padding: 12px 24px;
            font-weight: 600;
            transition: all 0.3s ease;
            border: none;
            text-transform: none;
        }
        
        .btn-modern:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
        }
        
        .btn-success {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            border: none;
        }
        
        .btn-info {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            border: none;
        }
        
        /* File upload styling */
        .file-input {
            opacity: 0;
            position: absolute;
            z-index: -1;
        }
        
        .file-label {
            display: inline-block;
            padding: 12px 24px;
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: white;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 600;
        }
        
        .file-label:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(79, 172, 254, 0.3);
        }
        
        /* Progress indicators */
        .upload-progress {
            display: none;
            margin-top: 1rem;
        }
        
        .progress {
            height: 10px;
            border-radius: 10px;
            background-color: rgba(79, 172, 254, 0.1);
        }
        
        .progress-bar {
            background: linear-gradient(90deg, #4facfe 0%, #00f2fe 100%);
            border-radius: 10px;
        }
        
        /* Error and success states */
        .upload-success {
            border-color: #28a745;
            background: linear-gradient(135deg, rgba(40, 167, 69, 0.1), rgba(40, 167, 69, 0.05));
        }
        
        .upload-error {
            border-color: #dc3545;
            background: linear-gradient(135deg, rgba(220, 53, 69, 0.1), rgba(220, 53, 69, 0.05));
        }
        
        /* Form labels */
        .form-label {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 0.5rem;
        }
        
        /* Section headers */
        .section-header {
            display: flex;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid rgba(79, 172, 254, 0.1);
        }
        
        .section-header h4 {
            margin: 0;
            color: #2c3e50;
            font-weight: 600;
        }
        
        /* Template download card */
        .template-card {
            background: rgba(255, 255, 255, 0.9);
            border: 2px solid rgba(79, 172, 254, 0.2);
            border-radius: 16px;
            padding: 1.5rem;
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .template-card:hover {
            border-color: #4facfe;
            background: rgba(255, 255, 255, 1);
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(79, 172, 254, 0.2);
        }
    </style>
</head>
<body class="bg-light">
    <!-- Sidebar -->
    <?php
    $current_page = 'bulk_operations';
    include __DIR__ . '/../include/navmenu.php';
    ?>

    <!-- Main Content -->
    <div class="main-content">
        <div class="content">
            <div class="container-fluid py-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><i class="fas fa-upload me-2"></i>Bulk Import Products</h2>
                    <a href="bulk_operations.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back to Bulk Operations
                    </a>
                </div>

                <!-- Display messages -->
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle me-2"></i><?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="fas fa-exclamation-circle me-2"></i><?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['import_errors'])): ?>
                    <div class="alert alert-warning alert-dismissible fade show">
                        <h6><i class="fas fa-exclamation-triangle me-2"></i>Import Errors:</h6>
                        <ul class="mb-0">
                            <?php foreach ($_SESSION['import_errors'] as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['import_errors']); ?>
                <?php endif; ?>

                <!-- Template Download Section -->
                <div class="import-section template-section">
                    <h4><i class="fas fa-download me-2"></i>Step 1: Download Import Template</h4>
                    <p class="text-muted">Download our CSV template to ensure your data is formatted correctly.</p>
                    
                    <div class="row">
                        <div class="col-md-8">
                            <h6>Template includes these fields:</h6>
                            <div class="row">
                                <div class="col-md-6">
                                    <ul class="list-unstyled">
                                        <li><i class="fas fa-check text-success me-2"></i><strong>name</strong> (required)</li>
                                        <li><i class="fas fa-check text-success me-2"></i><strong>price</strong> (required)</li>
                                        <li><i class="fas fa-circle text-muted me-2"></i>sku</li>
                                        <li><i class="fas fa-circle text-muted me-2"></i>barcode</li>
                                        <li><i class="fas fa-circle text-muted me-2"></i>description</li>
                                        <li><i class="fas fa-circle text-muted me-2"></i>category</li>
                                        <li><i class="fas fa-circle text-muted me-2"></i>brand</li>
                                        <li><i class="fas fa-circle text-muted me-2"></i>supplier</li>
                                        <li><i class="fas fa-circle text-muted me-2"></i>quantity</li>
                                    </ul>
                                </div>
                                <div class="col-md-6">
                                    <ul class="list-unstyled">
                                        <li><i class="fas fa-circle text-muted me-2"></i>sale_price</li>
                                        <li><i class="fas fa-circle text-muted me-2"></i>sale_start_date</li>
                                        <li><i class="fas fa-circle text-muted me-2"></i>sale_end_date</li>
                                        <li><i class="fas fa-circle text-muted me-2"></i>cost_price</li>
                                        <li><i class="fas fa-circle text-muted me-2"></i>min_stock_level</li>
                                        <li><i class="fas fa-circle text-muted me-2"></i>tax_rate</li>
                                        <li><i class="fas fa-circle text-muted me-2"></i>status</li>
                                        <li><i class="fas fa-circle text-muted me-2"></i>weight</li>
                                        <li><i class="fas fa-circle text-muted me-2"></i>dimensions</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 text-center">
                            <a href="?action=template" class="btn btn-primary btn-lg">
                                <i class="fas fa-download me-2"></i>Download Template
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Import Form -->
                <form method="POST" enctype="multipart/form-data" id="importForm">
                    <div class="import-section">
                        <h4><i class="fas fa-cog me-2"></i>Step 2: Import Settings</h4>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <label class="form-label">Import Mode</label>
                                <select name="import_mode" class="form-select">
                                    <option value="create_only">Create New Products Only</option>
                                    <option value="create_and_update">Create New & Update Existing</option>
                                </select>
                                <small class="form-text text-muted">Choose how to handle the import process</small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Duplicate Handling</label>
                                <select name="duplicate_handling" class="form-select">
                                    <option value="skip">Skip Duplicates</option>
                                    <option value="update">Update Existing</option>
                                    <option value="create_new">Create with New SKU</option>
                                </select>
                                <small class="form-text text-muted">What to do when SKU already exists</small>
                            </div>
                        </div>
                    </div>

                    <div class="import-section">
                        <h4><i class="fas fa-file-upload me-2"></i>Step 3: Upload CSV File</h4>
                        
                        <div class="drop-zone" id="dropZone">
                            <i class="fas fa-cloud-upload-alt fa-3x text-muted mb-3"></i>
                            <h5>Drag and drop your CSV file here</h5>
                            <p class="text-muted">or click to browse</p>
                            <input type="file" name="csvFile" id="csvFile" accept=".csv" style="display: none;" required>
                            <button type="button" class="btn btn-outline-primary" onclick="document.getElementById('csvFile').click()">
                                <i class="fas fa-folder-open me-2"></i>Choose File
                            </button>
                        </div>
                        
                        <div id="fileInfo" class="mt-3" style="display: none;">
                            <div class="alert alert-info">
                                <strong>Selected file:</strong> <span id="fileName"></span><br>
                                <strong>Size:</strong> <span id="fileSize"></span>
                            </div>
                        </div>

                        <div class="field-mapping">
                            <h6><i class="fas fa-info-circle me-2"></i>Field Mapping Information</h6>
                            <p class="mb-2">Your CSV headers should match these field names (case-insensitive):</p>
                            <div class="row">
                                <div class="col-md-6">
                                    <strong>Required Fields:</strong>
                                    <ul class="list-unstyled ms-3">
                                        <li>• name</li>
                                        <li>• price</li>
                                    </ul>
                                </div>
                                <div class="col-md-6">
                                    <strong>Optional Fields:</strong>
                                    <ul class="list-unstyled ms-3">
                                        <li>• sku, barcode, description</li>
                                        <li>• category, brand, supplier</li>
                                        <li>• quantity, cost_price, tax_rate</li>
                                        <li>• status, weight, dimensions</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="text-center">
                        <button type="submit" class="btn btn-success btn-lg" id="submitBtn" disabled>
                            <i class="fas fa-upload me-2"></i>Import Products
                        </button>
                    </div>
                </form>

                <!-- Import Progress -->
                <div id="importProgress" class="mt-4" style="display: none;">
                    <div class="card">
                        <div class="card-body">
                            <h5><i class="fas fa-spinner fa-spin me-2"></i>Import in Progress...</h5>
                            <div class="progress">
                                <div class="progress-bar progress-bar-striped progress-bar-animated" style="width: 0%"></div>
                            </div>
                            <small class="text-muted">Please do not close this page during import.</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        const dropZone = document.getElementById('dropZone');
        const fileInput = document.getElementById('csvFile');
        const submitBtn = document.getElementById('submitBtn');
        const fileInfo = document.getElementById('fileInfo');
        const fileName = document.getElementById('fileName');
        const fileSize = document.getElementById('fileSize');

        // Drag and drop functionality
        dropZone.addEventListener('click', () => fileInput.click());
        
        dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropZone.classList.add('dragover');
        });
        
        dropZone.addEventListener('dragleave', () => {
            dropZone.classList.remove('dragover');
        });
        
        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.classList.remove('dragover');
            
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                fileInput.files = files;
                handleFileSelect(files[0]);
            }
        });

        fileInput.addEventListener('change', (e) => {
            if (e.target.files.length > 0) {
                handleFileSelect(e.target.files[0]);
            }
        });

        function handleFileSelect(file) {
            if (file.type !== 'text/csv' && !file.name.endsWith('.csv')) {
                alert('Please select a CSV file.');
                return;
            }

            fileName.textContent = file.name;
            fileSize.textContent = formatFileSize(file.size);
            fileInfo.style.display = 'block';
            submitBtn.disabled = false;
        }

        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        // Show progress during import
        document.getElementById('importForm').addEventListener('submit', function() {
            document.getElementById('importProgress').style.display = 'block';
            submitBtn.disabled = true;
        });
    </script>
</body>
</html>
