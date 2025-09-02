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

// Handle export request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export_products'])) {
    $filters = $_POST['filters'] ?? [];
    $export_fields = $_POST['export_fields'] ?? [];
    $export_format = $_POST['export_format'] ?? 'csv';
    
    try {
        // Build WHERE clause based on filters
        $where_conditions = [];
        $params = [];

        if (!empty($filters['category_id'])) {
            $where_conditions[] = "p.category_id = :category_id";
            $params[':category_id'] = $filters['category_id'];
        }

        if (!empty($filters['brand_id'])) {
            $where_conditions[] = "p.brand_id = :brand_id";
            $params[':brand_id'] = $filters['brand_id'];
        }

        if (!empty($filters['supplier_id'])) {
            $where_conditions[] = "p.supplier_id = :supplier_id";
            $params[':supplier_id'] = $filters['supplier_id'];
        }

        if (!empty($filters['status'])) {
            $where_conditions[] = "p.status = :status";
            $params[':status'] = $filters['status'];
        }

        if (!empty($filters['stock_condition'])) {
            switch ($filters['stock_condition']) {
                case 'low_stock':
                    $where_conditions[] = "p.quantity <= 10";
                    break;
                case 'out_of_stock':
                    $where_conditions[] = "p.quantity = 0";
                    break;
                case 'in_stock':
                    $where_conditions[] = "p.quantity > 0";
                    break;
            }
        }

        if (!empty($filters['created_after'])) {
            $where_conditions[] = "p.created_at >= :created_after";
            $params[':created_after'] = $filters['created_after'];
        }

        if (!empty($filters['created_before'])) {
            $where_conditions[] = "p.created_at <= :created_before";
            $params[':created_before'] = $filters['created_before'] . ' 23:59:59';
        }

        $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

        // Build SELECT clause based on chosen fields
        $select_fields = [];
        $field_headers = [];
        
        $available_fields = [
            'p.id' => 'ID',
            'p.name' => 'Name',
            'p.sku' => 'SKU',
            'p.barcode' => 'Barcode',
            'p.description' => 'Description',
            'c.name' => 'Category',
            'b.name' => 'Brand',
            's.name' => 'Supplier',
            'p.price' => 'Price',
            'p.sale_price' => 'Sale Price',
            'p.cost_price' => 'Cost Price',
            'p.quantity' => 'Quantity',
            'p.min_stock_level' => 'Min Stock Level',
            'p.tax_rate' => 'Tax Rate',
            'p.status' => 'Status',
            'p.weight' => 'Weight',
            'p.dimensions' => 'Dimensions',
            'p.created_at' => 'Created Date',
            'p.updated_at' => 'Updated Date'
        ];

        if (empty($export_fields)) {
            // Default fields if none selected
            $export_fields = ['p.name', 'p.sku', 'p.barcode', 'c.name', 'p.price', 'p.quantity', 'p.status'];
        }

        foreach ($export_fields as $field) {
            if (isset($available_fields[$field])) {
                $select_fields[] = $field . ' AS `' . $available_fields[$field] . '`';
                $field_headers[] = $available_fields[$field];
            }
        }

        $select_clause = implode(', ', $select_fields);

        // Get products
        $sql = "
            SELECT $select_clause
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN brands b ON p.brand_id = b.id
            LEFT JOIN suppliers s ON p.supplier_id = s.id
            $where_clause
            ORDER BY p.name
        ";

        $stmt = $conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($products)) {
            $_SESSION['error'] = "No products found matching the specified criteria.";
            header("Location: bulk_export.php");
            exit();
        }

        // Generate filename
        $timestamp = date('Y-m-d_H-i-s');
        $filename = "products_export_$timestamp.csv";

        // Set headers for file download
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        // Output CSV
        $output = fopen('php://output', 'w');
        
        // Write headers
        fputcsv($output, $field_headers);
        
        // Write data
        foreach ($products as $product) {
            fputcsv($output, array_values($product));
        }
        
        fclose($output);

        // Log the export
        try {
            $log_stmt = $conn->prepare("
                INSERT INTO activity_logs (user_id, username, action, details, created_at) 
                VALUES (:user_id, :username, :action, :details, NOW())
            ");
            $log_stmt->execute([
                ':user_id' => $user_id,
                ':username' => $username,
                ':action' => 'bulk_export_products',
                ':details' => "Exported " . count($products) . " products to $filename"
            ]);
        } catch (Exception $e) {
            // Log table might not exist, ignore
        }

        exit();

    } catch (Exception $e) {
        $_SESSION['error'] = "Export failed: " . $e->getMessage();
        header("Location: bulk_export.php");
        exit();
    }
}

// Get reference data
$categories_stmt = $conn->query("SELECT * FROM categories ORDER BY name");
$categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);

$brands_stmt = $conn->query("SELECT * FROM brands ORDER BY name");
$brands = $brands_stmt->fetchAll(PDO::FETCH_ASSOC);

$suppliers_stmt = $conn->query("SELECT * FROM suppliers ORDER BY name");
$suppliers = $suppliers_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk Export Products - POS System</title>
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
        .export-section {
            border: none;
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
            position: relative;
            overflow: hidden;
        }
        
        .filter-section {
            background: rgba(255, 255, 255, 0.95);
        }
        
        .filter-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #4facfe 0%, #00f2fe 100%);
        }
        
        .fields-section {
            background: rgba(232, 245, 232, 0.95);
        }
        
        .fields-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #a8edea 0%, #fed6e3 100%);
        }
        
        .preview-section {
            background: rgba(209, 236, 241, 0.95);
            border: 1px solid #bee5eb;
        }
        
        .preview-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #4facfe 0%, #00f2fe 100%);
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
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            border: none;
        }
        
        .btn-outline-primary {
            border: 2px solid #4facfe;
            color: #4facfe;
            background: rgba(255, 255, 255, 0.9);
        }
        
        .btn-outline-primary:hover {
            background: #4facfe;
            border-color: #4facfe;
        }
        
        .btn-outline-secondary {
            border: 2px solid #6c757d;
            background: rgba(255, 255, 255, 0.9);
        }
        
        .btn-outline-info {
            border: 2px solid #17a2b8;
            color: #17a2b8;
            background: rgba(255, 255, 255, 0.9);
        }
        
        /* Field categories */
        .field-category {
            background: rgba(255, 255, 255, 0.9);
            padding: 1.5rem;
            border-radius: 16px;
            margin-bottom: 1.5rem;
            border: 2px solid rgba(79, 172, 254, 0.1);
            transition: all 0.3s ease;
        }
        
        .field-category:hover {
            border-color: rgba(79, 172, 254, 0.3);
            box-shadow: 0 4px 20px rgba(79, 172, 254, 0.1);
        }
        
        .field-category h6 {
            color: #2c3e50;
            font-weight: 600;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid rgba(79, 172, 254, 0.1);
        }
        
        /* Checkbox styling */
        .field-checkbox {
            margin-bottom: 12px;
            display: flex;
            align-items: center;
        }
        
        .form-check-input {
            margin-right: 10px;
            transform: scale(1.2);
        }
        
        .form-check-input:checked {
            background-color: #4facfe;
            border-color: #4facfe;
        }
        
        .form-check-input:focus {
            box-shadow: 0 0 0 0.2rem rgba(79, 172, 254, 0.25);
        }
        
        .form-check-label {
            font-weight: 500;
            color: #2c3e50;
            cursor: pointer;
            transition: color 0.3s ease;
        }
        
        .field-checkbox:hover .form-check-label {
            color: #4facfe;
        }
        
        /* Form labels */
        .form-label {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 0.5rem;
        }
        
        /* Input group enhancements */
        .input-group-text {
            background: rgba(79, 172, 254, 0.1);
            border: 2px solid #e3f2fd;
            color: #4facfe;
            font-weight: 600;
        }
        
        /* Icon enhancements */
        .section-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-right: 10px;
            font-size: 1.2rem;
        }
        
        .filter-icon {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: white;
        }
        
        .fields-icon {
            background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%);
            color: #333;
        }
        
        .preview-icon {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: white;
        }
        
        /* Quick action buttons */
        .quick-actions {
            background: rgba(255, 255, 255, 0.8);
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            border: 2px solid rgba(79, 172, 254, 0.1);
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
        
        /* Progress and loading states */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }
        
        .loading-content {
            background: white;
            padding: 2rem;
            border-radius: 16px;
            text-align: center;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
        }
        
        /* Animation for field categories */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .field-category {
            animation: fadeInUp 0.5s ease-out;
        }
        
        .field-category:nth-child(1) { animation-delay: 0.1s; }
        .field-category:nth-child(2) { animation-delay: 0.2s; }
        .field-category:nth-child(3) { animation-delay: 0.3s; }
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
                    <h2><i class="fas fa-download me-2"></i>Bulk Export Products</h2>
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

                <form method="POST" id="exportForm">
                    <!-- Step 1: Filter Products -->
                    <div class="export-section filter-section">
                        <h4><i class="fas fa-filter me-2"></i>Step 1: Filter Products to Export</h4>
                        <p class="text-muted">Choose which products to include in the export:</p>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <label class="form-label">Category</label>
                                <select name="filters[category_id]" class="form-select">
                                    <option value="">All Categories</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo $category['id']; ?>">
                                            <?php echo htmlspecialchars($category['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Brand</label>
                                <select name="filters[brand_id]" class="form-select">
                                    <option value="">All Brands</option>
                                    <?php foreach ($brands as $brand): ?>
                                        <option value="<?php echo $brand['id']; ?>">
                                            <?php echo htmlspecialchars($brand['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Supplier</label>
                                <select name="filters[supplier_id]" class="form-select">
                                    <option value="">All Suppliers</option>
                                    <?php foreach ($suppliers as $supplier): ?>
                                        <option value="<?php echo $supplier['id']; ?>">
                                            <?php echo htmlspecialchars($supplier['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="row mt-3">
                            <div class="col-md-4">
                                <label class="form-label">Status</label>
                                <select name="filters[status]" class="form-select">
                                    <option value="">Any Status</option>
                                    <option value="active">Active Only</option>
                                    <option value="inactive">Inactive Only</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Stock Condition</label>
                                <select name="filters[stock_condition]" class="form-select">
                                    <option value="">Any Stock Level</option>
                                    <option value="in_stock">In Stock (>0)</option>
                                    <option value="low_stock">Low Stock (≤10)</option>
                                    <option value="out_of_stock">Out of Stock (0)</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <button type="button" class="btn btn-outline-primary" style="margin-top: 32px;" onclick="previewExport()">
                                    <i class="fas fa-eye me-2"></i>Preview Export
                                </button>
                            </div>
                        </div>

                        <div class="row mt-3">
                            <div class="col-md-6">
                                <label class="form-label">Created Date Range</label>
                                <div class="input-group">
                                    <input type="date" name="filters[created_after]" class="form-control">
                                    <span class="input-group-text">to</span>
                                    <input type="date" name="filters[created_before]" class="form-control">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Step 2: Select Fields -->
                    <div class="export-section fields-section">
                        <h4><i class="fas fa-list me-2"></i>Step 2: Choose Fields to Export</h4>
                        <p class="text-muted">Select which product information to include in the export:</p>

                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <button type="button" class="btn btn-outline-primary btn-sm me-2" onclick="selectAllFields()">
                                    <i class="fas fa-check-square me-1"></i>Select All
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-sm me-2" onclick="clearAllFields()">
                                    <i class="fas fa-square me-1"></i>Clear All
                                </button>
                                <button type="button" class="btn btn-outline-info btn-sm" onclick="selectEssentialFields()">
                                    <i class="fas fa-star me-1"></i>Essential Fields Only
                                </button>
                            </div>
                        </div>

                        <div class="row">
                            <!-- Basic Information -->
                            <div class="col-md-4">
                                <div class="field-category">
                                    <h6><i class="fas fa-info-circle me-2"></i>Basic Information</h6>
                                    <div class="field-checkbox">
                                        <input type="checkbox" class="form-check-input" name="export_fields[]" value="p.id" id="field_id">
                                        <label class="form-check-label" for="field_id">Product ID</label>
                                    </div>
                                    <div class="field-checkbox">
                                        <input type="checkbox" class="form-check-input" name="export_fields[]" value="p.name" id="field_name" checked>
                                        <label class="form-check-label" for="field_name">Name</label>
                                    </div>
                                    <div class="field-checkbox">
                                        <input type="checkbox" class="form-check-input" name="export_fields[]" value="p.sku" id="field_sku" checked>
                                        <label class="form-check-label" for="field_sku">SKU</label>
                                    </div>
                                    <div class="field-checkbox">
                                        <input type="checkbox" class="form-check-input" name="export_fields[]" value="p.barcode" id="field_barcode" checked>
                                        <label class="form-check-label" for="field_barcode">Barcode</label>
                                    </div>
                                    <div class="field-checkbox">
                                        <input type="checkbox" class="form-check-input" name="export_fields[]" value="p.description" id="field_description">
                                        <label class="form-check-label" for="field_description">Description</label>
                                    </div>
                                </div>
                            </div>

                            <!-- Categorization -->
                            <div class="col-md-4">
                                <div class="field-category">
                                    <h6><i class="fas fa-tags me-2"></i>Categorization</h6>
                                    <div class="field-checkbox">
                                        <input type="checkbox" class="form-check-input" name="export_fields[]" value="c.name" id="field_category" checked>
                                        <label class="form-check-label" for="field_category">Category</label>
                                    </div>
                                    <div class="field-checkbox">
                                        <input type="checkbox" class="form-check-input" name="export_fields[]" value="b.name" id="field_brand">
                                        <label class="form-check-label" for="field_brand">Brand</label>
                                    </div>
                                    <div class="field-checkbox">
                                        <input type="checkbox" class="form-check-input" name="export_fields[]" value="s.name" id="field_supplier">
                                        <label class="form-check-label" for="field_supplier">Supplier</label>
                                    </div>
                                </div>

                                <div class="field-category">
                                    <h6><i class="fas fa-cog me-2"></i>Product Status</h6>
                                    <div class="field-checkbox">
                                        <input type="checkbox" class="form-check-input" name="export_fields[]" value="p.status" id="field_status" checked>
                                        <label class="form-check-label" for="field_status">Status</label>
                                    </div>
                                </div>
                            </div>

                            <!-- Pricing & Inventory -->
                            <div class="col-md-4">
                                <div class="field-category">
                                    <h6><i class="fas fa-dollar-sign me-2"></i>Pricing</h6>
                                    <div class="field-checkbox">
                                        <input type="checkbox" class="form-check-input" name="export_fields[]" value="p.price" id="field_price" checked>
                                        <label class="form-check-label" for="field_price">Regular Price</label>
                                    </div>
                                    <div class="field-checkbox">
                                        <input type="checkbox" class="form-check-input" name="export_fields[]" value="p.sale_price" id="field_sale_price">
                                        <label class="form-check-label" for="field_sale_price">Sale Price</label>
                                    </div>
                                    <div class="field-checkbox">
                                        <input type="checkbox" class="form-check-input" name="export_fields[]" value="p.cost_price" id="field_cost_price">
                                        <label class="form-check-label" for="field_cost_price">Cost Price</label>
                                    </div>
                                    <div class="field-checkbox">
                                        <input type="checkbox" class="form-check-input" name="export_fields[]" value="p.tax_rate" id="field_tax_rate">
                                        <label class="form-check-label" for="field_tax_rate">Tax Rate</label>
                                    </div>
                                </div>

                                <div class="field-category">
                                    <h6><i class="fas fa-boxes me-2"></i>Inventory</h6>
                                    <div class="field-checkbox">
                                        <input type="checkbox" class="form-check-input" name="export_fields[]" value="p.quantity" id="field_quantity" checked>
                                        <label class="form-check-label" for="field_quantity">Quantity</label>
                                    </div>
                                    <div class="field-checkbox">
                                        <input type="checkbox" class="form-check-input" name="export_fields[]" value="p.min_stock_level" id="field_min_stock">
                                        <label class="form-check-label" for="field_min_stock">Min Stock Level</label>
                                    </div>
                                </div>

                                <div class="field-category">
                                    <h6><i class="fas fa-calendar me-2"></i>Dates</h6>
                                    <div class="field-checkbox">
                                        <input type="checkbox" class="form-check-input" name="export_fields[]" value="p.created_at" id="field_created">
                                        <label class="form-check-label" for="field_created">Created Date</label>
                                    </div>
                                    <div class="field-checkbox">
                                        <input type="checkbox" class="form-check-input" name="export_fields[]" value="p.updated_at" id="field_updated">
                                        <label class="form-check-label" for="field_updated">Updated Date</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Preview Section -->
                    <div class="export-section preview-section" id="previewSection" style="display: none;">
                        <h4><i class="fas fa-eye me-2"></i>Export Preview</h4>
                        <div id="previewContent">
                            <!-- Preview content will be loaded here -->
                        </div>
                    </div>

                    <!-- Submit -->
                    <div class="text-center">
                        <button type="submit" name="export_products" class="btn btn-success btn-lg" onclick="return confirmExport()">
                            <i class="fas fa-download me-2"></i>Export Products
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        function selectAllFields() {
            document.querySelectorAll('input[name="export_fields[]"]').forEach(checkbox => {
                checkbox.checked = true;
            });
        }

        function clearAllFields() {
            document.querySelectorAll('input[name="export_fields[]"]').forEach(checkbox => {
                checkbox.checked = false;
            });
        }

        function selectEssentialFields() {
            clearAllFields();
            const essentialFields = ['field_name', 'field_sku', 'field_barcode', 'field_category', 'field_price', 'field_quantity', 'field_status'];
            essentialFields.forEach(fieldId => {
                document.getElementById(fieldId).checked = true;
            });
        }

        function previewExport() {
            const formData = new FormData(document.getElementById('exportForm'));
            formData.append('action', 'preview');

            fetch('bulk_export_handler.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('previewContent').innerHTML = data.html;
                    document.getElementById('previewSection').style.display = 'block';
                    document.getElementById('previewSection').scrollIntoView({ behavior: 'smooth' });
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while previewing export.');
            });
        }

        function confirmExport() {
            const selectedFields = document.querySelectorAll('input[name="export_fields[]"]:checked');
            if (selectedFields.length === 0) {
                alert('Please select at least one field to export.');
                return false;
            }

            const previewSection = document.getElementById('previewSection');
            if (previewSection.style.display === 'none') {
                alert('Please preview the export first to see what will be exported.');
                return false;
            }

            return true; // No need for confirmation since it's just downloading
        }
    </script>
</body>
</html>
