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

// Handle CSV import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csvFile'])) {
    $file = $_FILES['csvFile'];
    
    // Validate file
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'File upload failed. Please try again.';
    } elseif ($file['size'] > 5 * 1024 * 1024) { // 5MB limit
        $errors[] = 'File size too large. Maximum size is 5MB.';
    } elseif (pathinfo($file['name'], PATHINFO_EXTENSION) !== 'csv') {
        $errors[] = 'Please upload a CSV file.';
    } else {
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
            
            // Read header row
            $header = fgetcsv($handle);
            if (!$header || count($header) < 4) {
                $errors[] = 'Invalid CSV format. Expected columns: Name, Category, Price, Quantity, Barcode, Description';
            } else {
                // Expected columns: Name, Category, Price, Quantity, Barcode, Description
                while (($data = fgetcsv($handle)) !== false) {
                    $row_count++;
                    
                    // Skip empty rows
                    if (empty(array_filter($data))) {
                        $skipped_count++;
                        continue;
                    }
                    
                    // Validate required fields
                    $name = trim($data[0] ?? '');
                    $category_name = trim($data[1] ?? '');
                    $price = (float)($data[2] ?? 0);
                    $quantity = (int)($data[3] ?? 0);
                    $barcode = trim($data[4] ?? '');
                    $description = trim($data[5] ?? '');
                    
                    $row_errors = [];
                    
                    if (empty($name)) {
                        $row_errors[] = 'Product name is required';
                    }
                    
                    if (empty($category_name)) {
                        $row_errors[] = 'Category is required';
                    }
                    
                    if ($price < 0) {
                        $row_errors[] = 'Price must be positive';
                    }
                    
                    if ($quantity < 0) {
                        $row_errors[] = 'Quantity must be positive';
                    }
                    
                    if (empty($barcode)) {
                        $row_errors[] = 'Barcode is required';
                    }
                    
                    if (!empty($row_errors)) {
                        $import_errors[] = "Row $row_count: " . implode(', ', $row_errors);
                        $error_count++;
                        continue;
                    }
                    
                    // Find or create category
                    $category_id = null;
                    foreach ($categories as $category) {
                        if (strcasecmp($category['name'], $category_name) === 0) {
                            $category_id = $category['id'];
                            break;
                        }
                    }
                    
                    if (!$category_id) {
                        // Create new category
                        try {
                            $cat_stmt = $conn->prepare("INSERT INTO categories (name) VALUES (:name)");
                            $cat_stmt->bindParam(':name', $category_name);
                            if ($cat_stmt->execute()) {
                                $category_id = $conn->lastInsertId();
                                // Add to categories array for future rows
                                $categories[] = ['id' => $category_id, 'name' => $category_name];
                            }
                        } catch (PDOException $e) {
                            $import_errors[] = "Row $row_count: Could not create category '$category_name'";
                            $error_count++;
                            continue;
                        }
                    }
                    
                    // Check if barcode already exists
                    $barcode_check = $conn->prepare("SELECT id FROM products WHERE barcode = :barcode");
                    $barcode_check->bindParam(':barcode', $barcode);
                    $barcode_check->execute();
                    if ($barcode_check->fetch()) {
                        $import_errors[] = "Row $row_count: Barcode '$barcode' already exists";
                        $error_count++;
                        continue;
                    }
                    
                    // Insert product
                    try {
                        $insert_stmt = $conn->prepare("
                            INSERT INTO products (name, category_id, price, quantity, barcode, description) 
                            VALUES (:name, :category_id, :price, :quantity, :barcode, :description)
                        ");
                        
                        $insert_stmt->bindParam(':name', $name);
                        $insert_stmt->bindParam(':category_id', $category_id);
                        $insert_stmt->bindParam(':price', $price);
                        $insert_stmt->bindParam(':quantity', $quantity);
                        $insert_stmt->bindParam(':barcode', $barcode);
                        $insert_stmt->bindParam(':description', $description);
                        
                        if ($insert_stmt->execute()) {
                            $success_count++;
                        } else {
                            $import_errors[] = "Row $row_count: Failed to insert product '$name'";
                            $error_count++;
                        }
                    } catch (PDOException $e) {
                        $import_errors[] = "Row $row_count: Database error for product '$name'";
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
                    <h1>Import Products</h1>
                    <div class="header-subtitle">Bulk import products from CSV file</div>
                </div>
                <div class="header-actions">
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
                
                <form method="POST" enctype="multipart/form-data" id="importForm">
                    <div class="file-upload-area" id="uploadArea">
                        <i class="bi bi-cloud-upload" style="font-size: 3rem; color: var(--primary-color);"></i>
                        <h4 class="mt-3">Drop your CSV file here or click to browse</h4>
                        <p class="text-muted">Maximum file size: 5MB</p>
                        <input type="file" id="csvFile" name="csvFile" accept=".csv" class="file-input" required>
                    </div>
                    
                    <div class="text-center mt-3">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-upload"></i>
                            Import Products
                        </button>
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
                            <li><strong>Price</strong> - Product price (required, positive number)</li>
                            <li><strong>Quantity</strong> - Initial stock quantity (required, positive number)</li>
                            <li><strong>Barcode</strong> - Unique barcode (required)</li>
                            <li><strong>Description</strong> - Product description (optional)</li>
                        </ol>
                    </div>
                    <div class="col-md-6">
                        <h5>Important Notes:</h5>
                        <ul>
                            <li>First row should contain column headers</li>
                            <li>All barcodes must be unique</li>
                            <li>Categories will be created automatically if they don't exist</li>
                            <li>Empty rows will be skipped</li>
                            <li>Maximum file size is 5MB</li>
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
                                    <th>Price</th>
                                    <th>Quantity</th>
                                    <th>Barcode</th>
                                    <th>Description</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>Laptop Computer</td>
                                    <td>Electronics</td>
                                    <td>1299.99</td>
                                    <td>10</td>
                                    <td>LAP001</td>
                                    <td>High-performance laptop for business use</td>
                                </tr>
                                <tr>
                                    <td>Coffee Mug</td>
                                    <td>Home & Kitchen</td>
                                    <td>15.50</td>
                                    <td>50</td>
                                    <td>MUG001</td>
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
        function downloadSampleCSV() {
            const csvContent = "Name,Category,Price,Quantity,Barcode,Description\n" +
                               "Laptop Computer,Electronics,1299.99,10,LAP001,High-performance laptop for business use\n" +
                               "Coffee Mug,Home & Kitchen,15.50,50,MUG001,Ceramic coffee mug with company logo\n" +
                               "Wireless Mouse,Electronics,29.99,25,MOU001,Wireless optical mouse\n" +
                               "Notebook,Office Supplies,5.99,100,NOT001,Spiral-bound notebook";
            
            const blob = new Blob([csvContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'sample_products.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }
    </script>
</body>
</html>