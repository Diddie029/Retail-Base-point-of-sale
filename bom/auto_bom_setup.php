<?php
session_start();
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';
require_once __DIR__ . '/../include/classes/AutoBOMManager.php';

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

// Check Auto BOM setup permissions - use granular permissions
$can_create_auto_boms = hasPermission('create_auto_boms', $permissions);
$can_edit_auto_boms = hasPermission('edit_auto_boms', $permissions);
$can_manage_configs = hasPermission('manage_auto_bom_configs', $permissions);
$can_activate_auto_boms = hasPermission('activate_auto_boms', $permissions);

if (!$can_create_auto_boms && !$can_edit_auto_boms && !$can_manage_configs && !$can_activate_auto_boms) {
    header("Location: ../dashboard/dashboard.php");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get available products for base product selection
$products = [];
$stmt = $conn->query("
    SELECT p.id, p.name, p.sku, p.quantity, p.cost_price, p.price, p.barcode, 
           s.name as supplier_name, c.name as category_name
    FROM products p
    LEFT JOIN suppliers s ON p.supplier_id = s.id
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.status = 'active'
    ORDER BY p.name ASC
");
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get product families
$product_families = [];
$stmt = $conn->query("SELECT id, name FROM product_families WHERE status = 'active' ORDER BY name");
$product_families = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Generate form token to prevent duplicate submissions
if (!isset($_SESSION['form_token'])) {
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
}

// Handle form submission
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Check for duplicate submission using session token
        if (isset($_POST['form_token'])) {
            if (!isset($_SESSION['form_token']) || $_POST['form_token'] !== $_SESSION['form_token']) {
                throw new Exception("Invalid form submission. Please try again.");
            }
            // Clear the token to prevent resubmission
            unset($_SESSION['form_token']);
        }
        
        $auto_bom_manager = new AutoBOMManager($conn, $user_id);

        // Basic configuration
        $config = [
            'config_name' => sanitizeInput($_POST['config_name']),
            'product_id' => !empty($_POST['sellable_product_id']) ? (int) $_POST['sellable_product_id'] : null,
            'base_product_id' => !empty($_POST['base_product_id']) ? (int) $_POST['base_product_id'] : null,
            'product_family_id' => !empty($_POST['product_family_id']) ? (int) $_POST['product_family_id'] : null,
            'base_unit' => sanitizeInput($_POST['base_unit'] ?? 'each'),
            'base_quantity' => (float) ($_POST['base_quantity'] ?? 1),
            'description' => sanitizeInput($_POST['description'] ?? ''),
            'auto_bom_type' => sanitizeInput($_POST['auto_bom_type'] ?? 'unit_conversion'),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
            'selling_units' => [],
            'selected_products' => !empty($_POST['selected_products']) ? explode(',', $_POST['selected_products']) : [],
            'product_varieties' => !empty($_POST['product_varieties']) ? json_decode($_POST['product_varieties'], true) : []
        ];

        // Process selling units
        if (isset($_POST['unit_names']) && is_array($_POST['unit_names'])) {
            foreach ($_POST['unit_names'] as $index => $unit_name) {
                if (!empty($unit_name) && !empty($_POST['unit_product_ids'][$index])) {
                    $product_id = (int) $_POST['unit_product_ids'][$index];

                    // Get product status from database
                    $product_status = 'active'; // Default fallback
                    try {
                        $stmt = $conn->prepare("SELECT status FROM products WHERE id = :id");
                        $stmt->execute([':id' => $product_id]);
                        $product = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($product) {
                            $product_status = $product['status'];
                        }
                    } catch (Exception $e) {
                        // Keep default status if query fails
                    }


                    $unit = [
                        'unit_name' => sanitizeInput($unit_name),
                        'unit_quantity' => (float) $_POST['unit_quantities'][$index],
                        'unit_sku' => !empty($_POST['unit_skus'][$index]) ? sanitizeInput($_POST['unit_skus'][$index]) : null,
                        'unit_barcode' => !empty($_POST['unit_barcodes'][$index]) ? sanitizeInput($_POST['unit_barcodes'][$index]) : null,
                        'pricing_strategy' => 'fixed', // Simplified to fixed pricing based on product
                        'fixed_price' => !empty($_POST['selling_prices'][$index]) ? (float) $_POST['selling_prices'][$index] : null,
                        'status' => $product_status, // Use product's status
                        'priority' => (int) ($_POST['priorities'][$index] ?? 0),
                        'product_id' => $product_id // Link to the selected product
                    ];

                    $config['selling_units'][] = $unit;
                }
            }
        }

        // Validate that we have at least one selling unit with a product
        if (empty($config['selling_units'])) {
            throw new Exception("Please add at least one selling unit with a selected product.");
        }

        // Validate that product_id is set
        if ($config['product_id'] === null) {
            throw new Exception("No valid sellable product selected for the Auto BOM configuration. Please select a product for at least one selling unit.");
        }

        // Create Auto BOM
        $auto_bom_id = $auto_bom_manager->createAutoBOM($config);

        // Log activity
        logActivity($conn, $user_id, 'create_auto_bom', "Created Auto BOM configuration: {$config['config_name']} (ID: $auto_bom_id)");

        $message = "Auto BOM configuration created successfully!";
        header("Location: auto_bom_index.php?message=" . urlencode($message));
        exit();

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auto BOM Setup - <?php echo htmlspecialchars($settings['site_name'] ?? 'Point of Sale'); ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

    <style>
         .setup-wizard {
             max-width: 1200px;
             margin: 0 auto;
             padding: 20px 15px;
         }

         .form-section {
             background: white;
             border-radius: 8px;
             box-shadow: 0 2px 10px rgba(0,0,0,0.06);
             padding: 25px;
             margin-bottom: 25px;
             border: 1px solid #f0f0f0;
         }

         .form-row {
             display: flex;
             gap: 20px;
             margin-bottom: 20px;
         }

         .form-group {
             flex: 1;
             margin-bottom: 20px;
         }
        
         .form-group label {
             display: block;
             margin-bottom: 8px;
             font-weight: 600;
             color: #2c3e50;
             font-size: 0.9rem;
         }
         
         .form-control {
             border: 1px solid #e9ecef;
             border-radius: 6px;
             padding: 10px 12px;
             font-size: 0.9rem;
             transition: all 0.3s ease;
         }
        
        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
        }

        .form-group.full-width {
            flex: 1 1 100%;
        }

         .selling-units-section {
             background: #f8f9fa;
             border: 1px solid #e9ecef;
             border-radius: 8px;
             padding: 20px;
             margin-top: 20px;
         }

         .selling-unit-item {
             border: 1px solid #e9ecef;
             border-radius: 8px;
             padding: 20px;
             margin-bottom: 20px;
             background: white;
             box-shadow: 0 1px 4px rgba(0,0,0,0.05);
             transition: all 0.3s ease;
         }
        
        .selling-unit-item:hover {
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            border-color: #dee2e6;
        }

         .unit-header {
             display: flex;
             justify-content: space-between;
             align-items: center;
             margin-bottom: 15px;
             padding-bottom: 10px;
             border-bottom: 1px solid #e9ecef;
             background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
             padding: 15px 20px;
             border-radius: 6px;
             margin: -20px -20px 15px -20px;
         }

        .unit-title {
            font-weight: 700;
            color: #2c3e50;
            font-size: 1.1rem;
        }

        .remove-unit {
            color: #dc3545;
            cursor: pointer;
            font-size: 20px;
            padding: 8px;
            border-radius: 50%;
            transition: all 0.3s ease;
            background: rgba(220, 53, 69, 0.1);
        }

        .remove-unit:hover {
            color: #ffffff;
            background: #dc3545;
            transform: scale(1.1);
        }

         .btn {
             padding: 10px 20px;
             border: none;
             border-radius: 6px;
             cursor: pointer;
             font-weight: 600;
             font-size: 0.9rem;
             transition: all 0.3s ease;
             box-shadow: 0 1px 3px rgba(0,0,0,0.1);
         }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
            color: white;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #0056b3 0%, #004085 100%);
        }

        .pricing-config {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 15px;
            margin-top: 15px;
        }

        .pricing-fields {
            display: none;
        }

        .pricing-fields.active {
            display: block;
        }

         .section-header {
             border-bottom: 2px solid #e9ecef;
             padding-bottom: 15px;
             margin-bottom: 20px;
             background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
             padding: 20px;
             border-radius: 6px;
             margin: -25px -25px 20px -25px;
         }
        
         .section-header h3 {
             color: #2c3e50;
             margin-bottom: 5px;
             font-size: 1.3rem;
             font-weight: 700;
             display: flex;
             align-items: center;
         }
         
         .section-header h3 i {
             margin-right: 8px;
             color: var(--primary-color);
         }
         
         .section-header p {
             margin-bottom: 0;
             font-size: 0.95rem;
             color: #6c757d;
             font-weight: 500;
         }

         .alert {
             padding: 15px;
             border-radius: 6px;
             margin-bottom: 20px;
             border: 1px solid transparent;
             font-size: 0.9rem;
         }
        
        .alert-info {
            background: linear-gradient(135deg, #d1ecf1 0%, #bee5eb 100%);
            border-color: #bee5eb;
            color: #0c5460;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* Product Selection Styling */
        .product-selection-container {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 20px;
            margin-top: 15px;
            background: #f8f9fa;
        }

        .product-item {
            border: 1px solid #e9ecef;
            border-radius: 6px;
            padding: 15px;
            margin-bottom: 10px;
            background: white;
            transition: all 0.2s ease;
        }

        .product-item:hover {
            border-color: #667eea;
            box-shadow: 0 2px 4px rgba(102, 126, 234, 0.1);
        }

        .product-item.selected {
            border-color: #667eea;
            background: #f0f4ff;
        }

        .product-checkbox {
            margin-right: 10px;
        }

        .product-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .product-details {
            flex: 1;
        }

        .product-name {
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
        }

        .product-meta {
            font-size: 0.9rem;
            color: #666;
        }

        .product-meta span {
            margin-right: 15px;
        }

        .selection-controls {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #dee2e6;
        }

        .selected-count {
            font-weight: 600;
            color: #667eea;
        }

        /* Dropdown Styling */
        .form-control[type="select"], 
        .form-control:is(select),
        select.form-control {
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23343a40' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m1 6 7 7 7-7'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            background-size: 16px 12px;
            padding-right: 2.5rem;
            cursor: pointer;
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
        }

        .form-control[type="select"]:focus, 
        .form-control:is(select):focus,
        select.form-control:focus {
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23007bff' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m1 6 7 7 7-7'/%3e%3c/svg%3e");
        }

        /* Enhanced dropdown styling */
        .dropdown-field {
            position: relative;
        }

        .dropdown-field::after {
            content: "▼";
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            pointer-events: none;
            color: #6c757d;
            font-size: 0.8em;
            z-index: 1;
        }

        /* Hover effect for dropdowns */
        select.form-control:hover {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }

        /* Focus effect for dropdowns */
        select.form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }

        /* Tooltip Styles */
        .tooltip-label {
            display: flex;
            align-items: center;
            gap: 6px;
            position: relative;
        }

        .tooltip-icon {
            color: #dc3545;
            font-size: 0.9rem;
            cursor: help;
            transition: all 0.3s ease;
            margin-left: 6px;
            opacity: 1;
            display: inline-block;
            background: rgba(220, 53, 69, 0.1);
            padding: 3px 5px;
            border-radius: 3px;
            border: 1px solid rgba(220, 53, 69, 0.2);
        }

        .tooltip-icon:hover {
            color: #c82333;
            transform: scale(1.1);
            background: rgba(220, 53, 69, 0.2);
        }

        .tooltip-label:hover .tooltip-icon {
            opacity: 1;
            color: #c82333;
        }

        .tooltip-icon::after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%) translateY(-8px);
            background: rgba(0, 0, 0, 0.9);
            color: white;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 0.8rem;
            line-height: 1.4;
            white-space: normal;
            max-width: 250px;
            text-align: center;
            word-wrap: break-word;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
            z-index: 9999;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
            pointer-events: none;
            font-family: inherit;
        }

        .tooltip-icon:hover::after,
        .tooltip-icon:hover::before {
            opacity: 1;
            visibility: visible;
        }

        .tooltip-icon::before {
            content: '';
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%) translateY(-8px);
            margin-bottom: -5px;
            border: 5px solid transparent;
            border-top-color: rgba(0, 0, 0, 0.9);
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
            z-index: 9999;
        }

        /* Tooltip positioning for form sections */
        .form-section .tooltip-icon::after {
            bottom: 100%;
            margin-bottom: 8px;
        }

        .form-section .tooltip-icon::before {
            bottom: 100%;
            margin-bottom: 3px;
        }

        /* Ensure tooltips are visible above other elements */
        .selling-unit-item .tooltip-icon::after {
            z-index: 9999;
        }

        .selling-unit-item .tooltip-icon::before {
            z-index: 9999;
        }

        /* Make sure tooltip icons are always visible */
        .tooltip-icon {
            visibility: visible !important;
            display: inline-block !important;
        }

        .tooltip-label {
            display: flex !important;
            align-items: center !important;
        }

        /* Additional visibility for form labels */
        .form-group label .tooltip-icon {
            vertical-align: middle;
        }

        /* Temporary test class for tooltip visibility */
        .show-tooltip::after {
            opacity: 1 !important;
            visibility: visible !important;
        }

        /* Ensure clean tooltip display */
        .tooltip-icon[data-tooltip=""]::after {
            display: none !important;
        }

        /* Prevent tooltip conflicts */
        .tooltip-icon::after {
            opacity: 0;
            visibility: hidden;
        }

        .tooltip-icon:hover::after,
        .tooltip-icon.show-tooltip::after {
            opacity: 1;
            visibility: visible;
        }

        /* Make dropdowns more prominent */
        select.form-control {
            background-color: #f8f9fa;
            border: 2px solid #e9ecef;
            transition: all 0.3s ease;
        }

        select.form-control:hover {
            background-color: #e9ecef;
            border-color: #667eea;
        }

        select.form-control:focus {
            background-color: #fff;
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }

        /* Searchable Select Styling */
        .searchable-select-container {
            position: relative;
        }
        
        /* Read-only field styling */
        input[name="unit_prices[]"], 
        input[name="unit_skus[]"], 
        input[name="unit_barcodes[]"] {
            background-color: #f8f9fa !important;
            color: #6c757d !important;
            cursor: not-allowed !important;
        }

        .search-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #ddd;
            border-top: none;
            border-radius: 0 0 4px 4px;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .search-results.show {
            display: block;
        }

        .search-result-item {
            padding: 10px 15px;
            cursor: pointer;
            border-bottom: 1px solid #f0f0f0;
            transition: background-color 0.2s ease;
        }

        .search-result-item:hover {
            background-color: #f8f9fa;
        }

        .search-result-item:last-child {
            border-bottom: none;
        }

        .search-result-item.selected {
            background-color: #667eea;
            color: white;
        }

        .search-result-name {
            font-weight: 600;
            color: #333;
        }

        .search-result-item:hover .search-result-name,
        .search-result-item.selected .search-result-name {
            color: inherit;
        }

         .search-result-details {
             font-size: 0.85rem;
             color: #666;
             margin-top: 2px;
         }

         .search-result-item:hover .search-result-details,
         .search-result-item.selected .search-result-details {
             color: rgba(255,255,255,0.8);
         }

         /* Compact form styling */
         .form-text {
             margin-top: 4px;
             margin-bottom: 8px;
         }

         .form-text.text-info {
             margin-bottom: 12px;
         }

         .mb-4 {
             margin-bottom: 1.5rem !important;
         }

         .mb-3 {
             margin-bottom: 1rem !important;
         }

         .mb-2 {
             margin-bottom: 0.5rem !important;
         }

         .mt-3 {
             margin-top: 1rem !important;
         }

         .mt-5 {
             margin-top: 2rem !important;
         }

         .my-3 {
             margin-top: 1rem !important;
             margin-bottom: 1rem !important;
         }

         .my-5 {
             margin-top: 2rem !important;
             margin-bottom: 2rem !important;
         }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include '../include/navmenu.php'; ?>

        <main class="main-content">
            <div class="container-fluid">
                <div class="setup-wizard">
                    <h1 class="mb-4"><i class="fas fa-magic"></i> Auto BOM Setup Wizard</h1>

                    <?php if ($message): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($error): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" id="auto-bom-form" autocomplete="off">
                        <!-- Form token to prevent duplicate submissions -->
                        <input type="hidden" name="form_token" value="<?php echo htmlspecialchars($_SESSION['form_token'] ?? ''); ?>">
                        <!-- Hidden field for main sellable product ID -->
                        <input type="hidden" name="sellable_product_id" id="sellable_product_id" value="">
                        
                        <!-- Page Header -->
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h2>Auto BOM Setup</h2>
                            <div class="d-flex align-items-center gap-3">
                                <small class="text-muted">
                                    <i class="fas fa-info-circle me-1" style="color: #dc3545;"></i>Hover over red <i class="fas fa-info-circle" style="color: #dc3545;"></i> icons for help
                                </small>
                                <button type="button" class="btn btn-outline-info" data-bs-toggle="modal" data-bs-target="#helpModal">
                                    <i class="fas fa-question-circle me-2"></i>Help
                                </button>
                            </div>
                        </div>

                        <!-- Section 1: Basic Configuration -->
                        <div class="form-section">
                            <div class="section-header">
                                <h3><i class="fas fa-cog me-2"></i>Basic Configuration</h3>
                                <p class="text-muted">Configure the basic settings for your Auto BOM.</p>
                            </div>
                            
                            <!-- Requirements Info -->
                            <div class="alert alert-info mb-4">
                                <h6><i class="fas fa-info-circle me-2"></i>Required Fields:</h6>
                                <ul class="mb-0">
                                    <li><strong>Configuration Name:</strong> Enter a unique name for this Auto BOM setup</li>
                                    <li><strong>Auto BOM Type:</strong> Choose how products will be converted between different units</li>
                                    <li><strong>Base Product:</strong> Select the main product for this Auto BOM</li>
                                    <li><strong>Base Unit & Quantity:</strong> Define the fundamental measurement unit</li>
                                    <li><strong>Selling Units:</strong> Add at least one selling unit with selected product and price</li>
                                </ul>
                                <div class="mt-3">
                                    <small class="text-muted">
                                        <i class="fas fa-lightbulb me-1"></i>
                                        <strong>Auto-fill Note:</strong> Each selling unit automatically uses the price and status from its selected product.
                                    </small>
                                </div>
                            </div>

                                <div class="form-row">
                                    <div class="form-group">
                                    <label for="config_name" class="tooltip-label">
                                        Configuration Name *
                                        <i class="fas fa-info-circle tooltip-icon" data-tooltip="Enter a unique name for this Auto BOM configuration. This helps identify the configuration in reports and management screens."></i>
                                    </label>
                                     <input type="text" id="config_name" name="config_name" class="form-control" required
                                            placeholder="e.g., Cooking Oil - 20L Container">
                                     <small class="form-text text-muted">
                                         <i class="fas fa-lightbulb"></i> Examples: "Rice - 50kg Bulk", "Cooking Oil - 20L Container", "Water - 20L Bottle"
                                     </small>
                                    </div>
                                    <div class="form-group">
                                    <label for="auto_bom_type" class="dropdown-label tooltip-label">
                                        Auto BOM Type
                                        <i class="fas fa-info-circle tooltip-icon" data-tooltip="Select conversion type: Unit Conversion, Repackaging, or Bulk Selling."></i>
                                    </label>
                                    <select id="auto_bom_type" name="auto_bom_type" class="form-control">
                                            <option value="unit_conversion">Unit Conversion</option>
                                            <option value="repackaging">Repackaging</option>
                                            <option value="bulk_selling">Bulk Selling</option>
                                        </select>
                                        <small class="form-text text-muted">💡 Choose how products will be converted between different units</small>
                                    </div>
                                </div>

                            <!-- Base Product Selection -->
                                <div class="form-row">
                                    <div class="form-group">
                                    <label for="base_product_search" class="dropdown-label tooltip-label">
                                        Base Product *
                                        <i class="fas fa-info-circle tooltip-icon" data-tooltip="Search and select the main product for conversions. Use name, SKU, or barcode."></i>
                                    </label>
                                        <div class="searchable-select-container">
                                            <input type="text" id="base_product_search" class="form-control"
                                                   placeholder="Search for base product..."
                                                   autocomplete="off">
                                            <input type="hidden" id="base_product_id" name="base_product_id" value="">
                                            <div class="search-results" id="base_product_results"></div>
                                        </div>
                                         <small class="form-text text-muted">
                                             <i class="fas fa-lightbulb"></i> Start typing to search for products by name, SKU, or barcode
                                         </small>
                                         <small class="form-text text-info">
                                             <i class="fas fa-info-circle"></i> Examples: Search "Rice", "Oil", "Water" or SKU "RICE001", "OIL500"
                                         </small>
                                        <div id="base_product_status" class="form-text text-info" style="display: none;">
                                            <i class="fas fa-check-circle"></i> Base product selected
                                        </div>
                                    </div>
                                </div>

                                    <div class="form-row">
                                        <div class="form-group">
                                    <label for="product_family_id" class="dropdown-label tooltip-label">
                                        Product Family (Optional)
                                        <i class="fas fa-info-circle tooltip-icon" data-tooltip="Group related products together for easier management. Optional - you can leave this empty if you don't need product grouping."></i>
                                    </label>
                                        <select id="product_family_id" name="product_family_id" class="form-control">
                                            <option value="">Select Product Family</option>
                                            <?php foreach ($product_families as $family): ?>
                                                <option value="<?php echo $family['id']; ?>">
                                                    <?php echo htmlspecialchars($family['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                     <small class="form-text text-muted">
                                         <i class="fas fa-lightbulb"></i> Example: "Cooking Oils", "Rice Products", "Beverages" - helps group related items
                                     </small>
                                     <small class="form-text text-info">
                                         <i class="fas fa-info-circle"></i> Examples: "Grains & Cereals", "Cooking Oils", "Beverages", "Snacks", "Cleaning Supplies"
                                     </small>
                                    </div>
                                    <div class="form-group">
                                    <label for="base_unit" class="dropdown-label tooltip-label">
                                        Base Unit
                                        <i class="fas fa-info-circle tooltip-icon" data-tooltip="Select the measurement unit for your base product. This is how the product is measured when you buy it in bulk (e.g., liters, kilograms, pieces)."></i>
                                    </label>
                                    <select id="base_unit" name="base_unit" class="form-control">
                                            <option value="each">Each</option>
                                            <option value="kg">Kilogram (kg)</option>
                                            <option value="g">Gram (g)</option>
                                            <option value="l">Liter (l)</option>
                                            <option value="ml">Milliliter (ml)</option>
                                            <option value="pack">Pack</option>
                                            <option value="case">Case</option>
                                            <option value="box">Box</option>
                                        </select>
                                         <small class="form-text text-muted">💡 This is the fundamental unit for all calculations</small>
                                         <small class="form-text text-info">
                                             <i class="fas fa-info-circle"></i> Examples: "kg" for rice bags, "l" for oil drums, "each" for individual items
                                         </small>
                                    </div>
                                    <div class="form-group">
                                    <label for="base_quantity" class="tooltip-label">
                                        Base Quantity
                                        <i class="fas fa-info-circle tooltip-icon" data-tooltip="Enter conversion ratio (e.g., 20L drum = 20 x 1L bottles, so enter 20)."></i>
                                    </label>
                                         <input type="number" id="base_quantity" name="base_quantity" class="form-control"
                                                value="1" step="0.01" min="0.01">
                                         <small class="form-text text-info">
                                             <i class="fas fa-info-circle"></i> Examples: 1 (for single items), 20 (for 20L drums), 50 (for 50kg bags)
                                         </small>
                                    </div>
                                </div>

                                <div class="form-group full-width">
                                <label for="description" class="tooltip-label">
                                    Description
                                    <i class="fas fa-info-circle tooltip-icon" data-tooltip="Add any additional notes or details about this Auto BOM configuration. This helps other users understand the purpose and setup of this configuration."></i>
                                </label>
                                 <textarea id="description" name="description" class="form-control" rows="3"
                                           placeholder="Describe this Auto BOM configuration..."></textarea>
                                 <small class="form-text text-info">
                                     <i class="fas fa-info-circle"></i> Examples: "Bulk rice packaging for retail sales", "Oil distribution from drums to bottles", "Water packaging from bulk to individual bottles"
                                 </small>
                                </div>

                                <div class="form-check">
                                    <input type="checkbox" id="is_active" name="is_active" class="form-check-input" checked>
                                <label for="is_active" class="form-check-label tooltip-label">
                                    Active Configuration
                                    <i class="fas fa-info-circle tooltip-icon" data-tooltip="Enable this configuration to make it available for use in sales and inventory management. Uncheck to temporarily disable without deleting."></i>
                                </label>
                            </div>
                        </div>

                         <!-- Divider -->
                         <hr class="my-3">
                        
                        <!-- Section 2: Selling Units Configuration -->
                        <div class="form-section">
                            <div class="section-header">
                                <h3><i class="fas fa-boxes me-2"></i>Selling Units Configuration</h3>
                                <p class="text-muted">Define the different selling units for this Auto BOM.</p>
                            </div>

                            <div class="selling-units-section">
                                <div id="selling-units-container">
                                    <!-- Selling units will be added here dynamically -->
                                </div>

                                <button type="button" id="add-selling-unit" class="btn btn-primary tooltip-label">
                                    <i class="fas fa-plus"></i> Add Selling Unit
                                    <i class="fas fa-info-circle tooltip-icon" data-tooltip="Click to add a new selling unit. Each selling unit represents a different way customers can buy this product (e.g., different sizes or quantities)."></i>
                                </button>
                            </div>
                        </div>

                         <!-- Save Button -->
                         <div class="text-center mt-3">
                             <button type="submit" id="save-configuration" class="btn btn-primary">
                                 <i class="fas fa-save me-2"></i>Save Auto BOM Configuration
                             </button>
                         </div>
                    </form>
                </div>
            </div>
        </main>
    </div>

    <!-- Help Modal -->
    <div class="modal fade" id="helpModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-question-circle me-2"></i>Auto BOM Setup Help</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6>What is Auto BOM?</h6>
                    <p>Auto BOM (Bill of Materials) automatically manages product unit conversions and inventory for products sold in different sizes or packaging.</p>

                    <h6>Key Components:</h6>
                    <ul>
                        <li><strong>Base Product:</strong> The main product in your inventory (e.g., 20L container of oil)</li>
                        <li><strong>Selling Units:</strong> Different sizes customers can buy (e.g., 500ml bottles, 1L bottles)</li>
                        <li><strong>Sellable Products:</strong> Each unit must be linked to an existing product with its price and status</li>
                        <li><strong>Pricing:</strong> Uses the selected product's price automatically</li>
                        <li><strong>Status:</strong> Uses the selected product's status automatically</li>
                    </ul>

                    <h6>Example - Cooking Oil:</h6>
                    <div class="alert alert-light">
                        <strong>Base Product:</strong> 20L Container of Cooking Oil<br>
                        <strong>Selling Units:</strong>
                        <ul class="mb-0 mt-2">
                            <li>500ml Bottle → Links to "500ml Cooking Oil Bottle" product ($2.50, Active)</li>
                            <li>1L Bottle → Links to "1L Cooking Oil Bottle" product ($4.50, Active)</li>
                        </ul>
                        <strong>Auto BOM handles:</strong> Stock conversion, inventory tracking, price management
                    </div>

                    <h6>Auto-fill Strategy:</h6>
                    <p class="text-info">
                        <i class="fas fa-info-circle me-2"></i>
                        Each selling unit automatically gets its price and status from the selected product. No manual configuration needed!
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Got it!</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/dashboard.js"></script>

    <script>
        // Make products data available to JavaScript
        window.productsData = <?php echo json_encode($products); ?>;
        
        $(document).ready(function() {
            let sellingUnits = [];
            let selectedProducts = [];
            let allProducts = [];

            // Initialize first selling unit
            addSellingUnit();

            // Simple tooltip initialization check
            console.log('Auto BOM Setup loaded with', $('.tooltip-icon').length, 'tooltips');

            // Verify no duplicate tooltips
            let tooltipTexts = [];
            $('.tooltip-icon').each(function(index) {
                const text = $(this).attr('data-tooltip');
                if (text && tooltipTexts.includes(text)) {
                    console.warn('Duplicate tooltip found:', text);
                }
                tooltipTexts.push(text);
            });

            console.log('Tooltip texts verified, no conflicts found');

            // Cleanup on page unload
            $(window).on('beforeunload', function() {
                cleanupEventListeners();
            });

            // Base product search functionality
            let baseProductSearchTimeout;
            $('#base_product_search').on('input', function() {
                const searchTerm = $(this).val().trim();
                const resultsDiv = $('#base_product_results');
                
                // Clear previous timeout
                clearTimeout(baseProductSearchTimeout);
                
                if (searchTerm.length === 0) {
                    resultsDiv.removeClass('show').empty();
                    return;
                }
                
                // Show loading indicator
                resultsDiv.html('<div class="search-result-item"><em>Searching...</em></div>');
                resultsDiv.addClass('show');

                // Only search if we have at least 2 characters to reduce API calls
                if (searchTerm.length >= 2) {
                    // Debounce search
                    baseProductSearchTimeout = setTimeout(() => {
                        try {
                            searchBaseProducts(searchTerm);
                        } catch (error) {
                            resultsDiv.html('<div class="search-result-item"><em>Error searching products</em></div>');
                        }
                    }, 300);
                        } else {
                    resultsDiv.removeClass('show').empty();
                }
            });

            // Hide search results when clicking outside
            $(document).on('click.baseProductSearch', function(e) {
                if (!$(e.target).closest('.searchable-select-container').length) {
                    $('.search-results').removeClass('show');
                }
            });

            // Form submission
            $('#save-configuration').click(function(e) {
                e.preventDefault();
                
                if (validateForm()) {
                    // Prevent double submission
                    const submitBtn = $(this);
                    if (submitBtn.prop('disabled')) {
                        return false;
                    }
                    
                    // Disable button and show loading
                    submitBtn.prop('disabled', true);
                    submitBtn.html('<i class="fas fa-spinner fa-spin me-2"></i>Creating Auto BOM...');

                    // Clean up event listeners before submission
                    cleanupEventListeners();
                    
                    // Submit form
                    $('#auto-bom-form').submit();
                }
            });

            // Cleanup function to remove event listeners
            function cleanupEventListeners() {
                // Remove base product search event listeners
                $(document).off('click.baseProductSearch');

                // Remove all unit search event listeners
                for (let i = 0; i < sellingUnits.length; i++) {
                    $(document).off('click.unitSearch' + i);
                }

                // Clear any pending timeouts
                if (typeof baseProductSearchTimeout !== 'undefined') {
                    clearTimeout(baseProductSearchTimeout);
                }
            }

            function validateForm() {
                // Validate basic setup
                const configName = $('#config_name').val().trim();
                const baseProductId = $('#base_product_id').val();

                if (!configName) {
                    showNotification('Please enter a configuration name.', 'error');
                    $('#config_name').focus();
                    return false;
                }

                    if (!baseProductId) {
                    showNotification('Please select a base product.', 'error');
                        $('#base_product_search').focus();
                        return false;
                }

                // Check if at least one selling unit has a product selected
                const unitProductIds = $('input[name="unit_product_ids[]"]');
                let hasProductSelected = false;
                unitProductIds.each(function() {
                    if ($(this).val().trim()) {
                        hasProductSelected = true;
                        return false; // Break out of loop
                    }
                });

                if (!hasProductSelected) {
                    showNotification('Please add at least one selling unit and select a product for it.', 'error');
                    return false;
                }

                // Validate selling units
                if (!validateSellingUnits()) {
                    return false;
                }

                return true;
            }

            function validateSellingUnits() {
                const unitNames = $('input[name="unit_names[]"]');
                const unitQuantities = $('input[name="unit_quantities[]"]');
                const unitProductIds = $('input[name="unit_product_ids[]"]');
                const sellingPrices = $('input[name="selling_prices[]"]');

                if (unitNames.length === 0) {
                    alert('Please add at least one selling unit.');
                    return false;
                }

                for (let i = 0; i < unitNames.length; i++) {
                    const unitName = $(unitNames[i]).val().trim();
                    const unitQuantity = $(unitQuantities[i]).val().trim();
                    const unitProductId = $(unitProductIds[i]).val().trim();
                    const sellingPrice = $(sellingPrices[i]).val().trim();

                    if (!unitProductId) {
                        alert(`Please select a sellable product for selling unit ${i + 1}.`);
                        return false;
                    }

                    if (!unitName || !unitQuantity) {
                        alert(`Please complete all required fields for selling unit ${i + 1}.`);
                        return false;
                    }

                    // Validate that unit quantity is a positive number
                    if (isNaN(unitQuantity) || parseFloat(unitQuantity) <= 0) {
                        alert(`Please enter a valid quantity for selling unit ${i + 1}.`);
                        return false;
                    }

                    // Validate that selling price is set
                    if (!sellingPrice || isNaN(sellingPrice) || parseFloat(sellingPrice) < 0) {
                        alert(`Please ensure a valid selling price is set for selling unit ${i + 1}. Select a product to auto-fill the price.`);
                        return false;
                    }

                    // Note: Status is automatically determined from the selected product
                }

                return true;
            }

            // Search base products function
            function searchBaseProducts(searchTerm) {
                try {
                const resultsDiv = $('#base_product_results');
                
                // Filter products based on search term
                const filteredProducts = window.productsData.filter(product => {
                    const searchLower = searchTerm.toLowerCase();
                    return product.name.toLowerCase().includes(searchLower) ||
                           (product.sku && product.sku.toLowerCase().includes(searchLower)) ||
                           (product.barcode && product.barcode.toLowerCase().includes(searchLower));
                });

                // Clear previous results
                resultsDiv.empty();

                if (filteredProducts.length === 0) {
                    resultsDiv.html('<div class="search-result-item"><em>No products found</em></div>');
                } else {
                    // Limit results to 10 for performance
                    const limitedProducts = filteredProducts.slice(0, 10);
                    
                    limitedProducts.forEach(product => {
                        const resultItem = $(`
                            <div class="search-result-item" data-product-id="${product.id}">
                                <div class="search-result-name">${product.name}</div>
                                <div class="search-result-details">
                                    SKU: ${product.sku || 'N/A'} | Stock: ${product.quantity} | Price: $${product.price || 0} | Supplier: ${product.supplier_name || 'N/A'}
                                </div>
                            </div>
                        `);
                        
                        // Add click handler
                        resultItem.on('click', function() {
                            selectBaseProduct(product);
                        });
                        
                        resultsDiv.append(resultItem);
                    });
                }
                
                resultsDiv.addClass('show');
                } catch (error) {
                    resultsDiv.html('<div class="search-result-item"><em>Error searching products</em></div>');
                resultsDiv.addClass('show');
                }
            }

            // Select base product function
            function selectBaseProduct(product) {
                // Update the search input
                $('#base_product_search').val(`${product.name} (${product.sku || 'N/A'})`);
                
                // Update the hidden field
                $('#base_product_id').val(product.id);
                
                // Hide search results
                $('#base_product_results').removeClass('show');
                
                // Show status indicator
                $('#base_product_status').show();
                
                // Show success message
                showNotification(`Selected base product: ${product.name}`, 'success');
            }

            // Search products for a specific unit
            function searchProductsForUnit(searchTerm, resultsDiv, unitIndex) {
                try {
                    // Filter products based on search term
                    const filteredProducts = window.productsData.filter(product => {
                        const searchLower = searchTerm.toLowerCase();
                        return product.name.toLowerCase().includes(searchLower) ||
                               (product.sku && product.sku.toLowerCase().includes(searchLower)) ||
                               (product.barcode && product.barcode.toLowerCase().includes(searchLower));
                    });

                // Clear previous results
                resultsDiv.empty();

                if (filteredProducts.length === 0) {
                    resultsDiv.html('<div class="search-result-item"><em>No products found</em></div>');
                } else {
                    // Limit results to 10 for performance
                    const limitedProducts = filteredProducts.slice(0, 10);

                    limitedProducts.forEach(product => {
                        const resultItem = $(`
                            <div class="search-result-item" data-product-id="${product.id}">
                                <div class="search-result-name">${product.name}</div>
                                <div class="search-result-details">
                                    SKU: ${product.sku || 'N/A'} | Stock: ${product.quantity} | Price: $${product.price || 0} | Supplier: ${product.supplier_name || 'N/A'}
                                </div>
                            </div>
                        `);

                        // Add click handler
                        resultItem.on('click', function() {
                            selectProductForUnit(product, unitIndex);
                        });

                        resultsDiv.append(resultItem);
                    });
                }

                resultsDiv.addClass('show');
                } catch (error) {
                    resultsDiv.html('<div class="search-result-item"><em>Error searching products</em></div>');
                    resultsDiv.addClass('show');
                }
            }

            // Select product for a specific unit
            function selectProductForUnit(product, unitIndex) {
                const unitItem = $(`.selling-unit-item[data-index="${unitIndex}"]`);

                // Update the search input
                unitItem.find('.unit-product-search').val(`${product.name} (${product.sku || 'N/A'})`);

                // Update the hidden field
                unitItem.find('.unit-product-id').val(product.id);

                // Hide search results
                unitItem.find('.unit-product-results').removeClass('show');

                // Auto-fill unit name if empty
                const unitNameInput = unitItem.find('input[name="unit_names[]"]');
                if (!unitNameInput.val().trim()) {
                    unitNameInput.val(`${product.name} (${product.sku || 'N/A'})`);
                }

                // Auto-fill SKU and barcode
                if (product.sku) {
                    unitItem.find('input[name="unit_skus[]"]').val(product.sku);
                }
                if (product.barcode) {
                    unitItem.find('input[name="unit_barcodes[]"]').val(product.barcode);
                }
                
                // Auto-fill price if available
                if (product.price) {
                    unitItem.find('input[name="selling_prices[]"]').val(product.price);
                }

                // Auto-fill status if available
                if (product.status) {
                    const statusDisplay = product.status.charAt(0).toUpperCase() + product.status.slice(1);
                    unitItem.find('.unit-status-field').val(statusDisplay);
                }

                // Update main sellable product ID if not set
                const mainSellableProductId = $('#sellable_product_id').val();
                if (!mainSellableProductId) {
                    $('#sellable_product_id').val(product.id);
                }

                // Show success message
                showNotification(`Selected sellable product: ${product.name}`, 'success');
            }

            // Initialize product search for a specific selling unit
            function initializeUnitProductSearch(unitIndex) {
                const unitItem = $(`.selling-unit-item[data-index="${unitIndex}"]`);
                const searchInput = unitItem.find('.unit-product-search');
                const resultsDiv = unitItem.find('.unit-product-results');
                const hiddenInput = unitItem.find('.unit-product-id');

                let searchTimeout;

                searchInput.on('input', function() {
                    const searchTerm = $(this).val().trim();

                    clearTimeout(searchTimeout);

                    if (searchTerm.length === 0) {
                        resultsDiv.removeClass('show').empty();
                        return;
                    }

                    // Show loading indicator
                    resultsDiv.html('<div class="search-result-item"><em>Searching...</em></div>');
                    resultsDiv.addClass('show');

                    // Only search if we have at least 2 characters to reduce API calls
                    if (searchTerm.length >= 2) {
                        searchTimeout = setTimeout(() => {
                            try {
                                searchProductsForUnit(searchTerm, resultsDiv, unitIndex);
                            } catch (error) {
                                resultsDiv.html('<div class="search-result-item"><em>Error searching products</em></div>');
                            }
                        }, 300);
                    } else {
                        resultsDiv.removeClass('show').empty();
                    }
                });

                // Hide results when clicking outside
                const hideResultsHandler = function(e) {
                    if (!$(e.target).closest('.searchable-select-container').length) {
                        resultsDiv.removeClass('show');
                    }
                };
                $(document).on('click.unitSearch' + unitIndex, hideResultsHandler);
            }

            // Show notification function
            function showNotification(message, type = 'info') {
                const alertClass = type === 'success' ? 'alert-success' :
                                  type === 'error' ? 'alert-danger' : 'alert-info';

                const notification = $(`
                    <div class="alert ${alertClass} alert-dismissible fade show" role="alert">
                        <i class="fas fa-info-circle me-2"></i>
                        ${message}
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                `);

                // Insert at the top of the wizard content
                $('.wizard-content').prepend(notification);

                // Auto-dismiss after 3 seconds
                setTimeout(() => {
                    notification.alert('close');
                }, 3000);
            }

            // Selling Units Management
            $('#add-selling-unit').click(function() {
                addSellingUnit();
            });

            function addSellingUnit(unitData = null) {
                const unitIndex = sellingUnits.length;
                const unitHtml = `
                    <div class="selling-unit-item" data-index="${unitIndex}">
                        <div class="unit-header">
                            <span class="unit-title tooltip-label">
                                Selling Unit ${unitIndex + 1}
                                <i class="fas fa-info-circle tooltip-icon" data-tooltip="Configure how customers can buy this product. Each selling unit represents a different packaging or quantity option."></i>
                            </span>
                            <span class="remove-unit tooltip-label" onclick="removeSellingUnit(${unitIndex})">
                                <i class="fas fa-times"></i>
                                <i class="fas fa-info-circle tooltip-icon" data-tooltip="Remove this selling unit from the configuration."></i>
                            </span>
                        </div>

                         <!-- Sellable Product Selection -->
                         <div class="form-row mb-2">
                            <div class="form-group">
                                <label class="tooltip-label">
                                    Sellable Product *
                                    <i class="fas fa-info-circle tooltip-icon" data-tooltip="Search and select the product that customers will buy for this selling unit. The product's price and status will be automatically used."></i>
                                </label>
                                <div class="searchable-select-container">
                                    <input type="text" class="form-control unit-product-search" 
                                           placeholder="Search for sellable product..."
                                           autocomplete="off">
                                    <input type="hidden" class="unit-product-id" name="unit_product_ids[]" value="">
                                    <div class="search-results unit-product-results"></div>
                                </div>
                                <small class="form-text text-muted">
                                    <i class="fas fa-lightbulb"></i> Select the product that customers will buy for this selling unit
                                </small>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="tooltip-label">
                                    Unit Name *
                                    <i class="fas fa-info-circle tooltip-icon" data-tooltip="Enter descriptive name (e.g., '500ml Bottle', '2kg Pack') for customer clarity."></i>
                                </label>
                                 <input type="text" name="unit_names[]" class="form-control"
                                        value="${unitData?.unit_name || ''}" required
                                        placeholder="e.g., 500ml Bottle">
                                 <small class="form-text text-info">
                                     <i class="fas fa-info-circle"></i> Examples: "500ml Bottle", "1kg Pack", "2L Container", "Single Item"
                                 </small>
                            </div>
                            <div class="form-group">
                                <label class="tooltip-label">
                                    Unit Quantity *
                                    <i class="fas fa-info-circle tooltip-icon" data-tooltip="Enter conversion ratio (e.g., 20L drum ÷ 500ml = 40 units)."></i>
                                </label>
                                 <input type="number" name="unit_quantities[]" class="form-control"
                                        value="" step="0.01" min="0.01" required
                                        placeholder="e.g., 20 (20kg rice = 20 x 1kg packs)">
                                 <small class="form-text text-info">
                                     <i class="fas fa-info-circle"></i> Examples: 20 (20kg ÷ 1kg), 40 (20L ÷ 500ml), 100 (100 pieces ÷ 1 piece)
                                 </small>
                            </div>
                            <div class="form-group">
                                <label class="tooltip-label">
                                    Priority
                                    <i class="fas fa-info-circle tooltip-icon" data-tooltip="Set display priority. Higher numbers appear first in lists."></i>
                                </label>
                                 <input type="number" name="priorities[]" class="form-control"
                                        value="${unitData?.priority || 0}" min="0">
                                 <small class="form-text text-info">
                                     <i class="fas fa-info-circle"></i> Examples: 0 (lowest priority), 10 (highest priority), 5 (medium priority)
                                 </small>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="tooltip-label">
                                    Selling Price <small class="text-muted">(auto-filled)</small>
                                    <i class="fas fa-info-circle tooltip-icon" data-tooltip="Automatically populated from the selected product's price setting."></i>
                                </label>
                                <input type="number" name="selling_prices[]" class="form-control selling-price-field"
                                       value="${unitData?.selling_price || ''}" step="0.01" min="0" readonly
                                       placeholder="Auto-filled from selected product">
                                <small class="form-text text-muted">
                                    💰 Price is automatically set from the selected sellable product
                                </small>
                            </div>
       <div class="form-group">
                                <label class="tooltip-label">
                                    Status <small class="text-muted">(auto-filled)</small>
                                    <i class="fas fa-info-circle tooltip-icon" data-tooltip="Automatically inherited from selected product's status setting."></i>
                                </label>
                                <input type="text" class="form-control unit-status-field" readonly
                                       value="${unitData?.status_display || 'Select a product'}" placeholder="Auto-filled from selected product">
                                <small class="form-text text-muted">
                                    📊 Status is automatically set from the selected sellable product
                                </small>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="tooltip-label">
                                    Unit SKU
                                    <i class="fas fa-info-circle tooltip-icon" data-tooltip="Automatically populated from selected product's unique identifier for inventory tracking."></i>
                                </label>
                                <input type="text" name="unit_skus[]" class="form-control"
                                       value="${unitData?.unit_sku || ''}" readonly
                                       placeholder="Auto-generated from base product">
                            </div>
                            <div class="form-group">
                                <label class="tooltip-label">
                                    Barcode
                                    <i class="fas fa-info-circle tooltip-icon" data-tooltip="Automatically populated from selected product for POS scanning and inventory tracking."></i>
                                </label>
                                <input type="text" name="unit_barcodes[]" class="form-control"
                                       value="${unitData?.unit_barcode || ''}" readonly
                                       placeholder="Auto-generated from base product">
                        </div>
                            <div class="form-group">
                                <label>Status</label>
                                <select name="unit_statuses[]" class="form-control">
                                    <option value="active" ${unitData?.status !== 'inactive' ? 'selected' : ''}>Active</option>
                                    <option value="inactive" ${unitData?.status === 'inactive' ? 'selected' : ''}>Inactive</option>
                                </select>
                            </div>
                        </div>
                    </div>
                `;

                $('#selling-units-container').append(unitHtml);

                // Initialize product search for this unit
                initializeUnitProductSearch(unitIndex);

                const unit = {
                    index: unitIndex,
                    unit_name: unitData?.unit_name || '',
                    unit_quantity: unitData?.unit_quantity || 1,
                    selling_price: unitData?.selling_price || '',
                    status_display: unitData?.status_display || 'Select a product',
                    priority: unitData?.priority || 0
                };

                sellingUnits.push(unit);
            }

            // Make removeSellingUnit global
            window.removeSellingUnit = function(index) {
                if (sellingUnits.length > 1) {
                    $(`.selling-unit-item[data-index="${index}"]`).remove();
                    sellingUnits.splice(index, 1);
                    updateSellingUnitIndices();
                } else {
                    alert('At least one selling unit is required.');
                }
            };

            function updateSellingUnitIndices() {
                $('.selling-unit-item').each(function(i) {
                    $(this).attr('data-index', i);
                    $(this).find('.unit-title').text(`Selling Unit ${i + 1}`);
                    $(this).find('.remove-unit').attr('onclick', `removeSellingUnit(${i})`);
                });
            }
        });
    </script>
</body>
</html>
