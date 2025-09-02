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

// Check Auto BOM permissions
$can_manage_auto_boms = hasPermission('manage_auto_boms', $permissions);

if (!$can_manage_auto_boms) {
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
    SELECT id, name, sku, quantity, cost_price
    FROM products
    WHERE status = 'active'
    ORDER BY name ASC
");
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get product families
$product_families = [];
$stmt = $conn->query("SELECT id, name FROM product_families WHERE status = 'active' ORDER BY name");
$product_families = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $auto_bom_manager = new AutoBOMManager($conn, $user_id);

        // Basic configuration
        $config = [
            'config_name' => sanitizeInput($_POST['config_name']),
            'product_id' => !empty($_POST['product_id']) ? (int) $_POST['product_id'] : null,
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
                if (!empty($unit_name)) {
                    $unit = [
                        'unit_name' => sanitizeInput($unit_name),
                        'unit_quantity' => (float) $_POST['unit_quantities'][$index],
                        'unit_sku' => !empty($_POST['unit_skus'][$index]) ? sanitizeInput($_POST['unit_skus'][$index]) : null,
                        'unit_barcode' => !empty($_POST['unit_barcodes'][$index]) ? sanitizeInput($_POST['unit_barcodes'][$index]) : null,
                        'pricing_strategy' => sanitizeInput($_POST['pricing_strategies'][$index] ?? 'fixed'),
                        'fixed_price' => !empty($_POST['fixed_prices'][$index]) ? (float) $_POST['fixed_prices'][$index] : null,
                        'markup_percentage' => !empty($_POST['markup_percentages'][$index]) ? (float) $_POST['markup_percentages'][$index] : 0,
                        'min_profit_margin' => !empty($_POST['min_profit_margins'][$index]) ? (float) $_POST['min_profit_margins'][$index] : 0,
                        'market_price' => !empty($_POST['market_prices'][$index]) ? (float) $_POST['market_prices'][$index] : null,
                        'dynamic_base_price' => !empty($_POST['dynamic_base_prices'][$index]) ? (float) $_POST['dynamic_base_prices'][$index] : null,
                        'stock_level_threshold' => !empty($_POST['stock_thresholds'][$index]) ? (int) $_POST['stock_thresholds'][$index] : null,
                        'demand_multiplier' => !empty($_POST['demand_multipliers'][$index]) ? (float) $_POST['demand_multipliers'][$index] : 1.0,
                        'hybrid_primary_strategy' => sanitizeInput($_POST['hybrid_primary_strategies'][$index] ?? 'fixed'),
                        'hybrid_threshold_value' => !empty($_POST['hybrid_threshold_values'][$index]) ? (float) $_POST['hybrid_threshold_values'][$index] : null,
                        'hybrid_fallback_strategy' => sanitizeInput($_POST['hybrid_fallback_strategies'][$index] ?? 'cost_based'),
                        'status' => sanitizeInput($_POST['unit_statuses'][$index] ?? 'active'),
                        'priority' => (int) ($_POST['priorities'][$index] ?? 0),
                        'max_quantity_per_sale' => !empty($_POST['max_quantities'][$index]) ? (int) $_POST['max_quantities'][$index] : null,
                        'image_url' => !empty($_POST['image_urls'][$index]) ? sanitizeInput($_POST['image_urls'][$index]) : null
                    ];

                    $config['selling_units'][] = $unit;
                }
            }
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

    <!-- Unit System Help Modal -->
    <div class="modal fade" id="unitHelpModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-question-circle me-2"></i>Understanding Auto BOM Units</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6>What are Units in Auto BOM?</h6>
                    <p>Units define how your products are measured and converted between different sizes/packaging.</p>

                    <h6>Unit Components:</h6>
                    <ul>
                        <li><strong>Base Unit:</strong> The fundamental measurement (liter, kilogram, each, etc.)</li>
                        <li><strong>Base Quantity:</strong> How much of the base unit makes one "logical unit"</li>
                        <li><strong>Selling Unit:</strong> How customers buy the product (bottles, packs, boxes, etc.)</li>
                        <li><strong>Unit Quantity:</strong> How many selling units equal one base unit</li>
                    </ul>

                    <h6>Examples:</h6>
                    <div class="row">
                        <div class="col-md-6">
                            <strong>Cooking Oil Example:</strong>
                            <ul>
                                <li>Base Unit: "liter"</li>
                                <li>Base Quantity: 20 (20L container)</li>
                                <li>Selling Unit: "500ml Bottle"</li>
                                <li>Unit Quantity: 40 (40 bottles = 1 container)</li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <strong>Rice Example:</strong>
                            <ul>
                                <li>Base Unit: "kilogram"</li>
                                <li>Base Quantity: 50 (50kg bag)</li>
                                <li>Selling Unit: "1kg Pack"</li>
                                <li>Unit Quantity: 50 (50 packs = 1 bag)</li>
                            </ul>
                        </div>
                    </div>

                    <h6>Search & Display:</h6>
                    <p>When customers search for products, the system shows:</p>
                    <ul>
                        <li>Available selling units for each Auto BOM product</li>
                        <li>Stock levels for each unit size</li>
                        <li>Pricing based on selected unit</li>
                        <li>Automatic inventory conversion</li>
                    </ul>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Got it!</button>
                </div>
            </div>
        </div>
    </div>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/products.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .setup-wizard {
            max-width: 1200px;
            margin: 0 auto;
        }

        .wizard-steps {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
        }

        .wizard-step {
            flex: 1;
            text-align: center;
            position: relative;
        }

        .wizard-step:not(:last-child)::after {
            content: '';
            position: absolute;
            top: 15px;
            left: 50%;
            width: 100%;
            height: 2px;
            background: #ddd;
            z-index: 1;
        }

        .step-circle {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: #ddd;
            color: #666;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            position: relative;
            z-index: 2;
        }

        .step-circle.active {
            background: #667eea;
            color: white;
        }

        .step-circle.completed {
            background: #28a745;
            color: white;
        }

        .step-label {
            display: block;
            margin-top: 10px;
            font-size: 0.9rem;
            color: #666;
        }

        .wizard-content {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 30px;
        }

        .form-section {
            display: none;
        }

        .form-section.active {
            display: block;
        }

        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            flex: 1;
        }

        .form-group.full-width {
            flex: 1 1 100%;
        }

        .selling-units-section {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 20px;
            margin-top: 20px;
        }

        .selling-unit-item {
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            background: #f8f9fa;
        }

        .unit-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .unit-title {
            font-weight: bold;
            color: #333;
        }

        .remove-unit {
            color: #dc3545;
            cursor: pointer;
            padding: 5px;
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

        .wizard-navigation {
            display: flex;
            justify-content: space-between;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #dee2e6;
        }

        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
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

        /* Modal and Units Help Styling */
        .modal-dialog {
            max-width: 800px;
        }

        .modal-body {
            padding: 2rem;
        }

        .modal-body h6 {
            color: #333;
            font-weight: 600;
            margin-top: 1.5rem;
            margin-bottom: 1rem;
        }

        .modal-body h6:first-child {
            margin-top: 0;
        }

        .modal-body ul {
            margin-bottom: 1.5rem;
        }

        .modal-body li {
            margin-bottom: 0.5rem;
        }

        .modal-body .row {
            margin-bottom: 1rem;
        }

        .modal-body strong {
            color: #495057;
        }

        /* Ensure proper text wrapping and spacing */
        .modal-body p {
            line-height: 1.6;
            margin-bottom: 1rem;
        }

        /* Fix any potential overflow issues */
        .modal-content {
            overflow: hidden;
        }

        .modal-body {
            overflow-y: auto;
            max-height: 70vh;
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

        .filter-group {
            transition: all 0.3s ease;
        }

        .filter-group.hidden {
            display: none !important;
        }

        /* Product Varieties Styling */
        .product-varieties {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 6px;
            padding: 10px;
            margin-top: 10px;
        }

        .variety-inputs .input-group {
            margin-bottom: 5px;
        }

        .variety-inputs .input-group:last-child {
            margin-bottom: 0;
        }

        .variety-name {
            font-size: 0.9rem;
        }

        .add-variety, .remove-variety {
            min-width: 35px;
        }

        .add-variety {
            border-color: #28a745;
            color: #28a745;
        }

        .add-variety:hover {
            background-color: #28a745;
            color: white;
        }

        .remove-variety {
            border-color: #dc3545;
            color: #dc3545;
        }

        .remove-variety:hover {
            background-color: #dc3545;
            color: white;
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

        /* Dropdown indicator for labels */
        .dropdown-label::after {
            content: " ▼";
            color: #6c757d;
            font-size: 0.8em;
            margin-left: 5px;
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

        /* Additional dropdown visual cues */
        .dropdown-label {
            position: relative;
            display: inline-block;
        }

        .dropdown-label::before {
            content: "📋";
            margin-right: 5px;
            font-size: 0.9em;
        }

        /* Alternative dropdown indicator */
        .form-group:has(select) .dropdown-label::after {
            content: " ▼";
            color: #667eea;
            font-weight: bold;
            font-size: 0.8em;
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

        /* Add subtle animation to dropdown arrow */
        select.form-control {
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23667eea' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m1 6 7 7 7-7'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            background-size: 16px 12px;
            padding-right: 2.5rem;
            cursor: pointer;
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
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

                    <!-- Wizard Steps -->
                    <div class="wizard-steps">
                        <div class="wizard-step">
                            <div class="step-circle active" id="step1-circle">1</div>
                            <span class="step-label">Basic Setup</span>
                        </div>
                        <div class="wizard-step">
                            <div class="step-circle" id="step2-circle">2</div>
                            <span class="step-label">Selling Units</span>
                        </div>
                        <div class="wizard-step">
                            <div class="step-circle" id="step3-circle">3</div>
                            <span class="step-label">Pricing Strategy</span>
                        </div>
                        <div class="wizard-step">
                            <div class="step-circle" id="step4-circle">4</div>
                            <span class="step-label">Review & Save</span>
                        </div>
                    </div>

                    <form method="POST" id="auto-bom-form">
                        <!-- Help Button -->
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h2>Auto BOM Setup Wizard</h2>
                            <button type="button" class="btn btn-outline-info" data-bs-toggle="modal" data-bs-target="#unitHelpModal">
                                <i class="fas fa-question-circle me-2"></i>Help with Units
                            </button>
                        </div>

                        <!-- Step 1: Basic Setup -->
                        <div class="wizard-content">
                            <div class="form-section active" id="step1">
                                <h3>Basic Configuration</h3>
                                <p>Configure the basic settings for your Auto BOM.</p>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="config_name">Configuration Name *</label>
                                        <input type="text" id="config_name" name="config_name" class="form-control" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="auto_bom_type" class="dropdown-label" title="Choose the type of Auto BOM configuration">Auto BOM Type</label>
                                        <select id="auto_bom_type" name="auto_bom_type" class="form-control" title="Select the type of Auto BOM">
                                            <option value="unit_conversion">Unit Conversion</option>
                                            <option value="repackaging">Repackaging</option>
                                            <option value="bulk_selling">Bulk Selling</option>
                                        </select>
                                        <small class="form-text text-muted">💡 Choose how products will be converted between different units</small>
                                    </div>
                                </div>

                                <!-- Product Selection Mode -->
                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="dropdown-label" title="Choose how you want to select products for this Auto BOM configuration">Product Selection Mode *</label>
                                        <select id="product_selection_mode" class="form-control" required title="Select how you want to choose products">
                                            <option value="single">Single Product</option>
                                            <option value="multiple">Multiple Products</option>
                                            <option value="category">By Category</option>
                                            <option value="family">By Product Family</option>
                                        </select>
                                        <small class="form-text text-muted">💡 Click the dropdown arrow to see all available options</small>
                                    </div>
                                    <div class="form-group" id="category_filter_group" style="display: none;">
                                        <label for="category_filter" class="dropdown-label">Filter by Category</label>
                                        <select id="category_filter" class="form-control">
                                            <option value="">All Categories</option>
                                        </select>
                                    </div>
                                    <div class="form-group" id="family_filter_group" style="display: none;">
                                        <label for="family_filter" class="dropdown-label">Filter by Product Family</label>
                                        <select id="family_filter" class="form-control">
                                            <option value="">All Product Families</option>
                                        </select>
                                    </div>
                                </div>

                                <!-- Product Search and Selection -->
                                <div class="form-row">
                                    <div class="form-group">
                                        <label>Search Products</label>
                                        <div class="input-group">
                                            <input type="text" id="product_search" class="form-control" placeholder="Search products...">
                                            <button type="button" id="search_products" class="btn btn-outline-secondary">
                                                <i class="fas fa-search"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <!-- Product Selection Results -->
                                <div id="product_selection_results" style="display: none;">
                                    <h5>Select Products:</h5>
                                    <div id="products_list" class="border rounded p-3" style="max-height: 300px; overflow-y: auto;">
                                        <!-- Products will be loaded here -->
                                    </div>
                                    <div class="mt-2">
                                        <button type="button" id="select_all_products" class="btn btn-sm btn-outline-primary">Select All</button>
                                        <button type="button" id="clear_selection" class="btn btn-sm btn-outline-secondary">Clear Selection</button>
                                        <span id="selected_count" class="ms-3 text-muted">0 products selected</span>
                                    </div>
                                </div>

                                <!-- Hidden fields for selected products -->
                                <input type="hidden" id="selected_products" name="selected_products" value="">
                                <input type="hidden" id="product_varieties" name="product_varieties" value="">

                                <!-- Base Product Selection (for single product mode) -->
                                <div class="form-row" id="base_product_row">
                                    <div class="form-group">
                                        <label for="base_product_id" class="dropdown-label">Base Product *</label>
                                        <select id="base_product_id" name="base_product_id" class="form-control">
                                            <option value="">Select Base Product</option>
                                            <?php foreach ($products as $product): ?>
                                                <option value="<?php echo $product['id']; ?>">
                                                    <?php echo htmlspecialchars($product['name'] . ' (' . $product['sku'] . ') - Stock: ' . $product['quantity']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="product_family_id" class="dropdown-label">Product Family (Optional)</label>
                                        <select id="product_family_id" name="product_family_id" class="form-control">
                                            <option value="">Select Product Family</option>
                                            <?php foreach ($product_families as $family): ?>
                                                <option value="<?php echo $family['id']; ?>">
                                                    <?php echo htmlspecialchars($family['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label for="base_unit" class="dropdown-label" title="Select the base measurement unit">Base Unit</label>
                                        <select id="base_unit" name="base_unit" class="form-control" title="Choose the base unit for calculations">
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
                                    </div>
                                    <div class="form-group">
                                        <label for="base_quantity">Base Quantity</label>
                                        <input type="number" id="base_quantity" name="base_quantity" class="form-control"
                                               value="1" step="0.01" min="0.01">
                                    </div>
                                </div>

                                <div class="form-group full-width">
                                    <label for="description">Description</label>
                                    <textarea id="description" name="description" class="form-control" rows="3"></textarea>
                                </div>

                                <div class="form-check">
                                    <input type="checkbox" id="is_active" name="is_active" class="form-check-input" checked>
                                    <label for="is_active" class="form-check-label">Active Configuration</label>
                                </div>
                            </div>

                            <!-- Step 2: Selling Units -->
                            <div class="form-section" id="step2">
                                <h3>Selling Units Configuration</h3>
                                <p>Define the different selling units for this Auto BOM.</p>

                                <div class="selling-units-section">
                                    <div id="selling-units-container">
                                        <!-- Selling units will be added here dynamically -->
                                    </div>

                                    <button type="button" id="add-selling-unit" class="btn btn-primary">
                                        <i class="fas fa-plus"></i> Add Selling Unit
                                    </button>
                                </div>
                            </div>

                            <!-- Step 3: Pricing Strategy -->
                            <div class="form-section" id="step3">
                                <h3>Pricing Strategy</h3>
                                <p>Configure pricing strategies for each selling unit.</p>

                                <div id="pricing-configs">
                                    <!-- Pricing configurations will be shown here -->
                                </div>
                            </div>

                            <!-- Step 4: Review & Save -->
                            <div class="form-section" id="step4">
                                <h3>Review & Save</h3>
                                <p>Review your Auto BOM configuration before saving.</p>

                                <div id="review-content">
                                    <!-- Review content will be populated here -->
                                </div>
                            </div>

                            <!-- Wizard Navigation -->
                            <div class="wizard-navigation">
                                <button type="button" id="prev-step" class="btn btn-secondary" disabled>
                                    <i class="fas fa-chevron-left"></i> Previous
                                </button>

                                <div id="step-indicators">
                                    Step <span id="current-step">1</span> of 4
                                </div>

                                <button type="button" id="next-step" class="btn btn-primary">
                                    Next <i class="fas fa-chevron-right"></i>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/dashboard.js"></script>

    <script>
        $(document).ready(function() {
            let currentStep = 1;
            let sellingUnits = [];
            let selectedProducts = [];
            let allProducts = [];
            let categories = [];
            let productFamilies = [];

            // Initialize first selling unit
            addSellingUnit();

            // Load categories and product families
            loadCategoriesAndFamilies();

            // Product selection mode change handler
            $('#product_selection_mode').change(function() {
                const mode = $(this).val();
                updateProductSelectionUI(mode);
            });

            // Category filter change handler
            $('#category_filter').change(function() {
                searchProducts();
            });

            // Family filter change handler
            $('#family_filter').change(function() {
                searchProducts();
            });

            // Search products handler
            $('#search_products').click(function() {
                searchProducts();
            });

            // Enter key handler for search
            $('#product_search').keypress(function(e) {
                if (e.which === 13) {
                    searchProducts();
                }
            });

            // Select all products handler
            $('#select_all_products').click(function() {
                $('.product-checkbox').prop('checked', true);
                updateSelectedProducts();
            });

            // Clear selection handler
            $('#clear_selection').click(function() {
                $('.product-checkbox').prop('checked', false);
                selectedProducts = [];
                updateSelectedProducts();
            });

            // Navigation
            $('#next-step').click(function() {
                if (validateCurrentStep()) {
                    if (currentStep < 4) {
                        navigateToStep(currentStep + 1);
                    } else {
                        $('#auto-bom-form').submit();
                    }
                }
            });

            $('#prev-step').click(function() {
                if (currentStep > 1) {
                    navigateToStep(currentStep - 1);
                }
            });

            function navigateToStep(step) {
                // Hide current step
                $(`#step${currentStep}`).removeClass('active');
                $(`#step${currentStep}-circle`).removeClass('active completed');

                // Show new step
                currentStep = step;
                $(`#step${currentStep}`).addClass('active');
                $(`#step${currentStep}-circle`).addClass('active');

                // Mark previous steps as completed
                for (let i = 1; i < currentStep; i++) {
                    $(`#step${i}-circle`).addClass('completed');
                }

                // Update navigation
                $('#current-step').text(currentStep);
                $('#prev-step').prop('disabled', currentStep === 1);
                $('#next-step').html(currentStep === 4 ?
                    'Save Configuration <i class="fas fa-save"></i>' :
                    'Next <i class="fas fa-chevron-right"></i>');

                // Update step content
                if (currentStep === 3) {
                    updatePricingConfigs();
                } else if (currentStep === 4) {
                    updateReviewContent();
                }
            }

            function validateCurrentStep() {
                switch (currentStep) {
                    case 1:
                        return validateBasicSetup();
                    case 2:
                        return validateSellingUnits();
                    case 3:
                        return validatePricingStrategy();
                    default:
                        return true;
                }
            }

            function validateBasicSetup() {
                const configName = $('#config_name').val().trim();
                const selectionMode = $('#product_selection_mode').val();
                const baseProductId = $('#base_product_id').val();

                if (!configName) {
                    alert('Please enter a configuration name.');
                    return false;
                }

                if (selectionMode === 'single') {
                    if (!baseProductId) {
                        alert('Please select a base product.');
                        return false;
                    }
                } else {
                    if (selectedProducts.length === 0) {
                        alert('Please select at least one product.');
                        return false;
                    }
                }

                return true;
            }

            // Load categories and product families
            function loadCategoriesAndFamilies() {
                $.ajax({
                    url: '../api/get_categories_and_families.php',
                    method: 'GET',
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            categories = response.categories;
                            productFamilies = response.product_families;
                            
                            // Populate category filter
                            const categorySelect = $('#category_filter');
                            categorySelect.empty().append('<option value="">All Categories</option>');
                            categories.forEach(category => {
                                categorySelect.append(`<option value="${category.id}">${category.name}</option>`);
                            });

                            // Populate family filter
                            const familySelect = $('#family_filter');
                            familySelect.empty().append('<option value="">All Product Families</option>');
                            productFamilies.forEach(family => {
                                familySelect.append(`<option value="${family.id}">${family.name}</option>`);
                            });
                        }
                    },
                    error: function() {
                        console.error('Failed to load categories and families');
                    }
                });
            }

            // Update product selection UI based on mode
            function updateProductSelectionUI(mode) {
                const baseProductRow = $('#base_product_row');
                const productSelectionResults = $('#product_selection_results');
                const categoryFilterGroup = $('#category_filter_group');
                const familyFilterGroup = $('#family_filter_group');

                if (mode === 'single') {
                    baseProductRow.show();
                    productSelectionResults.hide();
                    categoryFilterGroup.hide();
                    familyFilterGroup.hide();
                } else {
                    baseProductRow.hide();
                    productSelectionResults.show();
                    
                    if (mode === 'category') {
                        categoryFilterGroup.show();
                        familyFilterGroup.hide();
                    } else if (mode === 'family') {
                        categoryFilterGroup.hide();
                        familyFilterGroup.show();
                    } else {
                        categoryFilterGroup.show();
                        familyFilterGroup.show();
                    }
                    
                    // Load products for the selected mode
                    searchProducts();
                }
            }

            // Search products based on current filters
            function searchProducts() {
                const search = $('#product_search').val();
                const categoryId = $('#category_filter').val();
                const familyId = $('#family_filter').val();
                const selectionMode = $('#product_selection_mode').val();

                let url = '../api/get_products.php?';
                const params = [];

                if (search) params.push(`search=${encodeURIComponent(search)}`);
                if (categoryId) params.push(`category_id=${categoryId}`);
                if (familyId) params.push(`product_family_id=${familyId}`);
                
                // For category/family modes, get all products in that category/family
                if (selectionMode === 'category' && categoryId) {
                    params.push(`category_id=${categoryId}`);
                } else if (selectionMode === 'family' && familyId) {
                    params.push(`product_family_id=${familyId}`);
                }

                url += params.join('&');

                $.ajax({
                    url: url,
                    method: 'GET',
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            allProducts = response.products;
                            displayProducts(allProducts);
                        } else {
                            console.error('Failed to load products:', response.error);
                        }
                    },
                    error: function() {
                        console.error('Failed to search products');
                    }
                });
            }

            // Display products in the selection list
            function displayProducts(products) {
                const productsList = $('#products_list');
                productsList.empty();

                if (products.length === 0) {
                    productsList.html('<p class="text-muted text-center py-4">No products found matching your criteria.</p>');
                    return;
                }

                products.forEach(product => {
                    const isSelected = selectedProducts.some(p => p.id === product.id);
                    const selectedProduct = selectedProducts.find(p => p.id === product.id);
                    const varieties = selectedProduct ? selectedProduct.varieties || [] : [];
                    
                    const productHtml = `
                        <div class="product-item ${isSelected ? 'selected' : ''}" data-product-id="${product.id}">
                            <div class="product-info">
                                <input class="form-check-input product-checkbox" type="checkbox" 
                                       value="${product.id}" id="product_${product.id}" ${isSelected ? 'checked' : ''}>
                                <div class="product-details">
                                    <div class="product-name">${product.name}</div>
                                    <div class="product-meta">
                                        <span><i class="fas fa-barcode"></i> ${product.sku || 'N/A'}</span>
                                        <span><i class="fas fa-tag"></i> ${product.category_name || 'N/A'}</span>
                                        <span><i class="fas fa-layer-group"></i> ${product.product_family_name || 'N/A'}</span>
                                        <span><i class="fas fa-boxes"></i> Stock: ${product.quantity}</span>
                                        <span><i class="fas fa-dollar-sign"></i> $${product.selling_price}</span>
                                    </div>
                                    ${isSelected ? `
                                        <div class="product-varieties mt-2">
                                            <label class="form-label small">Product Varieties:</label>
                                            <div class="variety-inputs">
                                                <div class="input-group input-group-sm mb-1">
                                                    <input type="text" class="form-control variety-name" placeholder="Variety 1 (e.g., Small, Red, etc.)" value="${varieties[0] || ''}">
                                                    <button type="button" class="btn btn-outline-success btn-sm add-variety" data-product-id="${product.id}">
                                                        <i class="fas fa-plus"></i>
                                                    </button>
                                                </div>
                                                ${varieties.slice(1).map((variety, index) => `
                                                    <div class="input-group input-group-sm mb-1">
                                                        <input type="text" class="form-control variety-name" placeholder="Variety ${index + 2}" value="${variety}">
                                                        <button type="button" class="btn btn-outline-danger btn-sm remove-variety" data-product-id="${product.id}">
                                                            <i class="fas fa-times"></i>
                                                        </button>
                                                    </div>
                                                `).join('')}
                                            </div>
                                        </div>
                                    ` : ''}
                                </div>
                            </div>
                        </div>
                    `;
                    productsList.append(productHtml);
                });

                // Add change handlers for checkboxes
                $('.product-checkbox').change(function() {
                    const productItem = $(this).closest('.product-item');
                    if ($(this).is(':checked')) {
                        productItem.addClass('selected');
                    } else {
                        productItem.removeClass('selected');
                    }
                    updateSelectedProducts();
                    // Re-render products to show/hide variety inputs
                    displayProducts(allProducts);
                });

                // Add variety management handlers
                $('.add-variety').click(function() {
                    const productId = parseInt($(this).data('product-id'));
                    const varietyInputs = $(this).closest('.variety-inputs');
                    const varietyCount = varietyInputs.find('.variety-name').length;
                    
                    const newVarietyHtml = `
                        <div class="input-group input-group-sm mb-1">
                            <input type="text" class="form-control variety-name" placeholder="Variety ${varietyCount + 1}">
                            <button type="button" class="btn btn-outline-danger btn-sm remove-variety" data-product-id="${productId}">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    `;
                    varietyInputs.append(newVarietyHtml);
                    updateProductVarieties();
                });

                $('.remove-variety').click(function() {
                    $(this).closest('.input-group').remove();
                    updateProductVarieties();
                });

                // Update varieties when input changes
                $('.variety-name').on('input', function() {
                    updateProductVarieties();
                });
            }

            // Update selected products array
            function updateSelectedProducts() {
                const newSelectedProducts = [];
                
                $('.product-checkbox:checked').each(function() {
                    const productId = parseInt($(this).val());
                    const product = allProducts.find(p => p.id === productId);
                    if (product) {
                        // Preserve existing varieties if any
                        const existingProduct = selectedProducts.find(p => p.id === productId);
                        if (existingProduct && existingProduct.varieties) {
                            product.varieties = existingProduct.varieties;
                        }
                        newSelectedProducts.push(product);
                    }
                });

                selectedProducts = newSelectedProducts;

                // Update hidden field
                $('#selected_products').val(selectedProducts.map(p => p.id).join(','));
                
                // Update selected count
                $('#selected_count').text(`${selectedProducts.length} products selected`);
            }

            // Update product varieties
            function updateProductVarieties() {
                const varietiesData = {};
                
                $('.product-checkbox:checked').each(function() {
                    const productId = parseInt($(this).val());
                    const productItem = $(this).closest('.product-item');
                    const varietyInputs = productItem.find('.variety-name');
                    
                    const varieties = [];
                    varietyInputs.each(function() {
                        const variety = $(this).val().trim();
                        if (variety) {
                            varieties.push(variety);
                        }
                    });
                    
                    // Update the selectedProducts array
                    const selectedProduct = selectedProducts.find(p => p.id === productId);
                    if (selectedProduct) {
                        selectedProduct.varieties = varieties;
                    }
                    
                    if (varieties.length > 0) {
                        varietiesData[productId] = varieties;
                    }
                });

                // Update hidden field
                $('#product_varieties').val(JSON.stringify(varietiesData));
            }

            function validateSellingUnits() {
                const unitNames = $('input[name="unit_names[]"]');
                const unitQuantities = $('input[name="unit_quantities[]"]');
                
                if (unitNames.length === 0) {
                    alert('Please add at least one selling unit.');
                    return false;
                }

                for (let i = 0; i < unitNames.length; i++) {
                    const unitName = $(unitNames[i]).val().trim();
                    const unitQuantity = $(unitQuantities[i]).val().trim();
                    
                    if (!unitName || !unitQuantity) {
                        alert(`Please complete all required fields for selling unit ${i + 1}.`);
                        return false;
                    }
                    
                    // Validate that unit quantity is a positive number
                    if (isNaN(unitQuantity) || parseFloat(unitQuantity) <= 0) {
                        alert(`Please enter a valid quantity for selling unit ${i + 1}.`);
                        return false;
                    }
                }

                return true;
            }

            function validatePricingStrategy() {
                // Basic validation - could be enhanced
                return true;
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
                            <span class="unit-title">Selling Unit ${unitIndex + 1}</span>
                            <span class="remove-unit" onclick="removeSellingUnit(${unitIndex})">
                                <i class="fas fa-times"></i>
                            </span>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Unit Name *</label>
                                <input type="text" name="unit_names[]" class="form-control"
                                       value="${unitData?.unit_name || ''}" required>
                            </div>
                            <div class="form-group">
                                <label>Unit Quantity *</label>
                                <input type="number" name="unit_quantities[]" class="form-control"
                                       value="${unitData?.unit_quantity || 1}" step="0.01" min="0.01" required>
                            </div>
                            <div class="form-group">
                                <label>Unit SKU</label>
                                <input type="text" name="unit_skus[]" class="form-control"
                                       value="${unitData?.unit_sku || ''}">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Barcode</label>
                                <input type="text" name="unit_barcodes[]" class="form-control"
                                       value="${unitData?.unit_barcode || ''}">
                            </div>
                            <div class="form-group">
                                <label>Priority</label>
                                <input type="number" name="priorities[]" class="form-control"
                                       value="${unitData?.priority || 0}" min="0">
                            </div>
                            <div class="form-group">
                                <label>Max Qty per Sale</label>
                                <input type="number" name="max_quantities[]" class="form-control"
                                       value="${unitData?.max_quantity_per_sale || ''}" min="1">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="dropdown-label">Pricing Strategy</label>
                                <select name="pricing_strategies[]" class="form-control pricing-strategy-select">
                                    <option value="fixed" ${unitData?.pricing_strategy === 'fixed' ? 'selected' : ''}>Fixed Price</option>
                                    <option value="cost_based" ${unitData?.pricing_strategy === 'cost_based' ? 'selected' : ''}>Cost-Based</option>
                                    <option value="market_based" ${unitData?.pricing_strategy === 'market_based' ? 'selected' : ''}>Market-Based</option>
                                    <option value="dynamic" ${unitData?.pricing_strategy === 'dynamic' ? 'selected' : ''}>Dynamic</option>
                                    <option value="hybrid" ${unitData?.pricing_strategy === 'hybrid' ? 'selected' : ''}>Hybrid</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="dropdown-label">Status</label>
                                <select name="unit_statuses[]" class="form-control">
                                    <option value="active" ${unitData?.status !== 'inactive' ? 'selected' : ''}>Active</option>
                                    <option value="inactive" ${unitData?.status === 'inactive' ? 'selected' : ''}>Inactive</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Image URL</label>
                                <input type="url" name="image_urls[]" class="form-control"
                                       value="${unitData?.image_url || ''}">
                            </div>
                        </div>
                    </div>
                `;

                $('#selling-units-container').append(unitHtml);

                const unit = {
                    index: unitIndex,
                    unit_name: unitData?.unit_name || '',
                    unit_quantity: unitData?.unit_quantity || 1,
                    unit_sku: unitData?.unit_sku || '',
                    unit_barcode: unitData?.unit_barcode || '',
                    pricing_strategy: unitData?.pricing_strategy || 'fixed',
                    status: unitData?.status || 'active',
                    priority: unitData?.priority || 0,
                    max_quantity_per_sale: unitData?.max_quantity_per_sale || null,
                    image_url: unitData?.image_url || ''
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

            function updatePricingConfigs() {
                let pricingHtml = '';

                sellingUnits.forEach((unit, index) => {
                    pricingHtml += `
                        <div class="pricing-config">
                            <h4>${unit.unit_name} (${unit.unit_quantity} units)</h4>
                            <div class="pricing-fields active" id="pricing-fields-${index}">
                                ${getPricingFieldsHtml(unit.pricing_strategy, index, unit)}
                            </div>
                        </div>
                    `;
                });

                $('#pricing-configs').html(pricingHtml);
            }

            function getPricingFieldsHtml(strategy, index, unitData) {
                switch (strategy) {
                    case 'fixed':
                        return `
                            <div class="form-group">
                                <label>Fixed Price *</label>
                                <input type="number" name="fixed_prices[]" class="form-control"
                                       value="${unitData?.fixed_price || ''}" step="0.01" min="0" required>
                            </div>
                        `;

                    case 'cost_based':
                        return `
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Markup Percentage (%)</label>
                                    <input type="number" name="markup_percentages[]" class="form-control"
                                           value="${unitData?.markup_percentage || 0}" step="0.01" min="0">
                                </div>
                                <div class="form-group">
                                    <label>Min Profit Margin (%)</label>
                                    <input type="number" name="min_profit_margins[]" class="form-control"
                                           value="${unitData?.min_profit_margin || 0}" step="0.01" min="0">
                                </div>
                            </div>
                        `;

                    case 'market_based':
                        return `
                            <div class="form-group">
                                <label>Market Price *</label>
                                <input type="number" name="market_prices[]" class="form-control"
                                       value="${unitData?.market_price || ''}" step="0.01" min="0" required>
                            </div>
                        `;

                    case 'dynamic':
                        return `
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Dynamic Base Price</label>
                                    <input type="number" name="dynamic_base_prices[]" class="form-control"
                                           value="${unitData?.dynamic_base_price || ''}" step="0.01" min="0">
                                </div>
                                <div class="form-group">
                                    <label>Stock Threshold</label>
                                    <input type="number" name="stock_thresholds[]" class="form-control"
                                           value="${unitData?.stock_level_threshold || ''}" min="0">
                                </div>
                                <div class="form-group">
                                    <label>Demand Multiplier</label>
                                    <input type="number" name="demand_multipliers[]" class="form-control"
                                           value="${unitData?.demand_multiplier || 1.0}" step="0.01" min="0.1">
                                </div>
                            </div>
                        `;

                    case 'hybrid':
                        return `
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="dropdown-label">Primary Strategy</label>
                                    <select name="hybrid_primary_strategies[]" class="form-control">
                                        <option value="fixed" ${unitData?.hybrid_primary_strategy === 'fixed' ? 'selected' : ''}>Fixed</option>
                                        <option value="cost_based" ${unitData?.hybrid_primary_strategy === 'cost_based' ? 'selected' : ''}>Cost-Based</option>
                                        <option value="market_based" ${unitData?.hybrid_primary_strategy === 'market_based' ? 'selected' : ''}>Market-Based</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Threshold Value</label>
                                    <input type="number" name="hybrid_threshold_values[]" class="form-control"
                                           value="${unitData?.hybrid_threshold_value || ''}" step="0.01" min="0">
                                </div>
                                <div class="form-group">
                                    <label class="dropdown-label">Fallback Strategy</label>
                                    <select name="hybrid_fallback_strategies[]" class="form-control">
                                        <option value="fixed" ${unitData?.hybrid_fallback_strategy === 'fixed' ? 'selected' : ''}>Fixed</option>
                                        <option value="cost_based" ${unitData?.hybrid_fallback_strategy === 'cost_based' ? 'selected' : ''}>Cost-Based</option>
                                        <option value="market_based" ${unitData?.hybrid_fallback_strategy === 'market_based' ? 'selected' : ''}>Market-Based</option>
                                    </select>
                                </div>
                            </div>
                        `;

                    default:
                        return '<p>Please select a pricing strategy.</p>';
                }
            }

            function updateReviewContent() {
                const configName = $('#config_name').val();
                const selectionMode = $('#product_selection_mode').val();
                const baseProductId = $('#base_product_id option:selected').text();

                let reviewHtml = `
                    <div class="review-section">
                        <h4>Basic Configuration</h4>
                        <p><strong>Name:</strong> ${configName}</p>
                        <p><strong>Selection Mode:</strong> ${selectionMode.charAt(0).toUpperCase() + selectionMode.slice(1)}</p>
                `;

                if (selectionMode === 'single') {
                    reviewHtml += `<p><strong>Base Product:</strong> ${baseProductId}</p>`;
                } else {
                    reviewHtml += `<p><strong>Selected Products:</strong> ${selectedProducts.length} products</p>`;
                    if (selectedProducts.length > 0) {
                        reviewHtml += `<ul class="mt-2">`;
                        selectedProducts.forEach(product => {
                            const varieties = product.varieties || [];
                            reviewHtml += `<li><strong>${product.name}</strong> (${product.sku}) - ${product.category_name}`;
                            if (varieties.length > 0) {
                                reviewHtml += `<br><small class="text-muted">Varieties: ${varieties.join(', ')}</small>`;
                            }
                            reviewHtml += `</li>`;
                        });
                        reviewHtml += `</ul>`;
                    }
                }

                reviewHtml += `
                        <p><strong>Base Unit:</strong> ${$('#base_unit').val()} (${$('#base_quantity').val()})</p>
                    </div>

                    <div class="review-section">
                        <h4>Selling Units (${sellingUnits.length})</h4>
                        <ul>
                `;

                sellingUnits.forEach(unit => {
                    reviewHtml += `
                        <li>
                            <strong>${unit.unit_name}</strong> (${unit.unit_quantity} units) -
                            Strategy: ${unit.pricing_strategy} - Status: ${unit.status}
                        </li>
                    `;
                });

                reviewHtml += `
                        </ul>
                    </div>
                `;

                $('#review-content').html(reviewHtml);
            }

            // Update pricing when strategy changes
            $(document).on('change', '.pricing-strategy-select', function() {
                const index = $(this).closest('.selling-unit-item').data('index');
                const strategy = $(this).val();

                if (sellingUnits[index]) {
                    sellingUnits[index].pricing_strategy = strategy;
                }

                if (currentStep === 3) {
                    updatePricingConfigs();
                }
            });

            // Update sellingUnits array when form fields change
            $(document).on('input change', 'input[name="unit_names[]"], input[name="unit_quantities[]"], input[name="unit_skus[]"], input[name="unit_barcodes[]"], input[name="priorities[]"], input[name="max_quantities[]"], input[name="image_urls[]"], select[name="unit_statuses[]"]', function() {
                const index = $(this).closest('.selling-unit-item').data('index');
                if (sellingUnits[index]) {
                    const fieldName = $(this).attr('name');
                    const value = $(this).val();
                    
                    switch(fieldName) {
                        case 'unit_names[]':
                            sellingUnits[index].unit_name = value;
                            break;
                        case 'unit_quantities[]':
                            sellingUnits[index].unit_quantity = parseFloat(value) || 0;
                            break;
                        case 'unit_skus[]':
                            sellingUnits[index].unit_sku = value;
                            break;
                        case 'unit_barcodes[]':
                            sellingUnits[index].unit_barcode = value;
                            break;
                        case 'priorities[]':
                            sellingUnits[index].priority = parseInt(value) || 0;
                            break;
                        case 'max_quantities[]':
                            sellingUnits[index].max_quantity_per_sale = value ? parseInt(value) : null;
                            break;
                        case 'image_urls[]':
                            sellingUnits[index].image_url = value;
                            break;
                        case 'unit_statuses[]':
                            sellingUnits[index].status = value;
                            break;
                    }
                }
            });
        });
    </script>
</body>
</html>
