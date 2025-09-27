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

// Check Auto BOM edit permissions
$can_edit_auto_boms = hasPermission('edit_auto_boms', $permissions);

if (!$can_edit_auto_boms) {
    header("Location: ../dashboard/dashboard.php");
    exit();
}

// Get Auto BOM ID
$auto_bom_id = intval($_GET['id'] ?? 0);
if (!$auto_bom_id) {
    header("Location: auto_bom_index.php?error=invalid_bom_id");
    exit();
}

// Get Auto BOM configuration
$stmt = $conn->prepare("
    SELECT abc.*, p.name as product_name, p.auto_bom_type, bp.name as base_product_name
    FROM auto_bom_configs abc
    LEFT JOIN products p ON abc.product_id = p.id
    LEFT JOIN products bp ON abc.base_product_id = bp.id
    WHERE abc.id = :id
");
$stmt->bindParam(':id', $auto_bom_id, PDO::PARAM_INT);
$stmt->execute();
$auto_bom = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$auto_bom) {
    header("Location: auto_bom_index.php?error=bom_not_found");
    exit();
}

// Set default auto_bom_type if not set
if (empty($auto_bom['auto_bom_type'])) {
    $auto_bom['auto_bom_type'] = 'unit_conversion';
}

// Get selling units for this configuration
$selling_units = [];
$stmt = $conn->prepare("
    SELECT * FROM auto_bom_selling_units
    WHERE auto_bom_config_id = :config_id
    ORDER BY priority DESC, unit_name ASC
");
$stmt->bindParam(':config_id', $auto_bom_id, PDO::PARAM_INT);
$stmt->execute();
$selling_units = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get available products for base product selection
$products = [];
$stmt = $conn->query("
    SELECT id, name, sku, quantity, cost_price, barcode
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
            'product_id' => !empty($_POST['product_id']) ? (int) $_POST['product_id'] : $auto_bom['product_id'],
            'base_product_id' => !empty($_POST['base_product_id']) ? (int) $_POST['base_product_id'] : null,
            'product_family_id' => !empty($_POST['product_family_id']) ? (int) $_POST['product_family_id'] : null,
            'base_unit' => sanitizeInput($_POST['base_unit'] ?? 'each'),
            'base_quantity' => (float) ($_POST['base_quantity'] ?? 1),
            'description' => sanitizeInput($_POST['description'] ?? ''),
            'auto_bom_type' => sanitizeInput($_POST['auto_bom_type'] ?? 'unit_conversion'),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
            'selling_units' => []
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

                    // Check if this unit has an ID (existing unit) or is new
                    if (!empty($_POST['unit_ids'][$index])) {
                        $unit['id'] = (int) $_POST['unit_ids'][$index];
                    }

                    $config['selling_units'][] = $unit;
                }
            }
        }

        // Update Auto BOM
        $auto_bom_manager->updateAutoBOM($auto_bom_id, $config);

        // Log activity
        logActivity($conn, $user_id, 'update_auto_bom', "Updated Auto BOM configuration: {$config['config_name']} (ID: $auto_bom_id)");

        $message = "Auto BOM configuration updated successfully!";
        header("Location: auto_bom_index.php?message=" . urlencode($message));
        exit();

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Add updateAutoBOM method to AutoBOMManager if it doesn't exist
if (!method_exists('AutoBOMManager', 'updateAutoBOM')) {
    class AutoBOMManagerExtended extends AutoBOMManager {
        public function updateAutoBOM($config_id, $config) {
            try {
                $this->conn->beginTransaction();

                // Validate configuration
                $this->validateAutoBOMConfig($config);

                // Update Auto BOM configuration
                $stmt = $this->conn->prepare("
                    UPDATE auto_bom_configs SET
                        config_name = :config_name,
                        base_product_id = :base_product_id,
                        product_family_id = :product_family_id,
                        base_unit = :base_unit,
                        base_quantity = :base_quantity,
                        description = :description,
                        auto_bom_type = :auto_bom_type,
                        is_active = :is_active,
                        updated_by = :updated_by,
                        updated_at = NOW()
                    WHERE id = :id
                ");

                $stmt->execute([
                    ':config_name' => $config['config_name'],
                    ':base_product_id' => $config['base_product_id'],
                    ':product_family_id' => $config['product_family_id'],
                    ':base_unit' => $config['base_unit'],
                    ':base_quantity' => $config['base_quantity'],
                    ':description' => $config['description'],
                    ':auto_bom_type' => $config['auto_bom_type'],
                    ':is_active' => $config['is_active'],
                    ':updated_by' => $this->user_id,
                    ':id' => $config_id
                ]);

                // Get existing selling units
                $existing_units = $this->getSellingUnitsByConfig($config_id);
                $existing_unit_ids = array_column($existing_units, 'id');

                // Process selling units
                $updated_unit_ids = [];
                foreach ($config['selling_units'] as $unit) {
                    if (isset($unit['id'])) {
                        // Update existing unit
                        $this->updateSellingUnit($unit['id'], $unit);
                        $updated_unit_ids[] = $unit['id'];
                    } else {
                        // Create new unit
                        $new_unit_id = $this->createSellingUnit($config_id, $unit);
                        $updated_unit_ids[] = $new_unit_id;
                    }
                }

                // Delete units that are no longer in the configuration
                $units_to_delete = array_diff($existing_unit_ids, $updated_unit_ids);
                foreach ($units_to_delete as $unit_id) {
                    $this->deleteSellingUnit($unit_id);
                }

                // Update product Auto BOM status and type
                $this->updateProductAutoBOMStatus($config['product_id'], $config['is_active'], $config['auto_bom_type']);

                $this->conn->commit();
                return $config_id;

            } catch (Exception $e) {
                $this->conn->rollBack();
                throw new Exception("Failed to update Auto BOM: " . $e->getMessage());
            }
        }

        private function updateSellingUnit($unit_id, $unit_config) {
            $stmt = $this->conn->prepare("
                UPDATE auto_bom_selling_units SET
                    unit_name = :unit_name,
                    unit_quantity = :unit_quantity,
                    unit_sku = :unit_sku,
                    unit_barcode = :unit_barcode,
                    pricing_strategy = :pricing_strategy,
                    fixed_price = :fixed_price,
                    markup_percentage = :markup_percentage,
                    min_profit_margin = :min_profit_margin,
                    market_price = :market_price,
                    dynamic_base_price = :dynamic_base_price,
                    stock_level_threshold = :stock_level_threshold,
                    demand_multiplier = :demand_multiplier,
                    hybrid_primary_strategy = :hybrid_primary_strategy,
                    hybrid_threshold_value = :hybrid_threshold_value,
                    hybrid_fallback_strategy = :hybrid_fallback_strategy,
                    status = :status,
                    priority = :priority,
                    max_quantity_per_sale = :max_quantity_per_sale,
                    image_url = :image_url,
                    updated_at = NOW()
                WHERE id = :id
            ");

            $stmt->execute([
                ':unit_name' => $unit_config['unit_name'],
                ':unit_quantity' => $unit_config['unit_quantity'],
                ':unit_sku' => $unit_config['unit_sku'],
                ':unit_barcode' => $unit_config['unit_barcode'],
                ':pricing_strategy' => $unit_config['pricing_strategy'],
                ':fixed_price' => $unit_config['fixed_price'],
                ':markup_percentage' => $unit_config['markup_percentage'],
                ':min_profit_margin' => $unit_config['min_profit_margin'],
                ':market_price' => $unit_config['market_price'],
                ':dynamic_base_price' => $unit_config['dynamic_base_price'],
                ':stock_level_threshold' => $unit_config['stock_level_threshold'],
                ':demand_multiplier' => $unit_config['demand_multiplier'],
                ':hybrid_primary_strategy' => $unit_config['hybrid_primary_strategy'],
                ':hybrid_threshold_value' => $unit_config['hybrid_threshold_value'],
                ':hybrid_fallback_strategy' => $unit_config['hybrid_fallback_strategy'],
                ':status' => $unit_config['status'],
                ':priority' => $unit_config['priority'],
                ':max_quantity_per_sale' => $unit_config['max_quantity_per_sale'],
                ':image_url' => $unit_config['image_url'],
                ':id' => $unit_id
            ]);
        }

        private function deleteSellingUnit($unit_id) {
            $stmt = $this->conn->prepare("DELETE FROM auto_bom_selling_units WHERE id = :id");
            $stmt->execute([':id' => $unit_id]);
        }
    }

    $auto_bom_manager = new AutoBOMManagerExtended($conn, $user_id);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Auto BOM - <?php echo htmlspecialchars($settings['site_name'] ?? 'Point of Sale'); ?></title>

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

        /* Searchable Select Styles */
        .searchable-select-container {
            position: relative;
        }

        .search-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #dee2e6;
            border-top: none;
            border-radius: 0 0 8px 8px;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        .search-results.show {
            display: block;
        }

        .search-result-item {
            padding: 12px 15px;
            cursor: pointer;
            border-bottom: 1px solid #f8f9fa;
            transition: background-color 0.2s ease;
        }

        .search-result-item:hover {
            background-color: #f8f9fa;
        }

        .search-result-item:last-child {
            border-bottom: none;
        }

        .search-result-item.selected {
            background-color: #e3f2fd;
            color: #1976d2;
        }

        .product-name {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 4px;
        }

        .product-details {
            font-size: 0.85rem;
            color: #6c757d;
        }

        .product-sku {
            background: #e9ecef;
            color: #495057;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.75rem;
            margin-right: 8px;
        }

        .product-stock {
            color: #28a745;
            font-weight: 500;
        }

        .no-results {
            padding: 15px;
            text-align: center;
            color: #6c757d;
            font-style: italic;
        }

        .scan-feedback {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            padding: 8px 12px;
            border-radius: 4px;
            font-size: 0.85rem;
            font-weight: 500;
            z-index: 1001;
            margin-top: 4px;
            animation: slideDown 0.3s ease;
        }

        .scan-feedback-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .scan-feedback-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .scan-feedback i {
            margin-right: 6px;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .edit-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            margin: -30px -30px 30px -30px;
            border-radius: 8px 8px 0 0;
        }

        .edit-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .edit-subtitle {
            opacity: 0.9;
            font-size: 1rem;
        }

        /* Tooltip Styles */
        .tooltip-label {
            display: flex;
            align-items: center;
            gap: 6px;
            position: relative;
        }

        .tooltip-icon {
            color: #6c757d;
            font-size: 0.8rem;
            cursor: help;
            transition: color 0.3s ease;
        }

        .tooltip-icon:hover {
            color: #667eea;
        }

        .tooltip-icon::after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0, 0, 0, 0.9);
            color: white;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 0.8rem;
            white-space: nowrap;
            max-width: 300px;
            white-space: normal;
            text-align: center;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
            z-index: 1000;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
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
            transform: translateX(-50%);
            border: 5px solid transparent;
            border-top-color: rgba(0, 0, 0, 0.9);
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
            z-index: 1000;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include '../include/navmenu.php'; ?>

        <main class="main-content">
            <div class="container-fluid">
                <div class="setup-wizard">
                    <div class="edit-header">
                        <h1 class="edit-title">
                            <i class="fas fa-edit me-2"></i>Edit Auto BOM Configuration
                        </h1>
                        <p class="edit-subtitle">
                            Modify the settings for: <strong><?php echo htmlspecialchars($auto_bom['config_name']); ?></strong>
                        </p>
                    </div>

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

                    <form method="POST" id="auto-bom-form">
                        <!-- Basic Configuration -->
                        <div class="wizard-content">
                            <div class="form-section active" id="step1">
                                <h3>Basic Configuration</h3>
                                <p>Modify the basic settings for this Auto BOM configuration.</p>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="config_name" class="tooltip-label">
                                            Configuration Name *
                                            <i class="fas fa-info-circle tooltip-icon" data-tooltip="Enter a unique name for this Auto BOM configuration. This helps identify the configuration in reports and management screens."></i>
                                        </label>
                                        <input type="text" id="config_name" name="config_name" class="form-control" required
                                               value="<?php echo htmlspecialchars($auto_bom['config_name']); ?>"
                                               placeholder="e.g., UFUTA 20LTS, Rice 25kg Bags">
                                        <small class="form-text text-muted">
                                            <i class="fas fa-lightbulb"></i> Example: "UFUTA 20LTS", "Rice 25kg Bags", "Cooking Oil Bulk"
                                        </small>
                                    </div>
                                    <div class="form-group">
                                        <label for="auto_bom_type" class="dropdown-label tooltip-label">
                                            Auto BOM Type
                                            <i class="fas fa-info-circle tooltip-icon" data-tooltip="Choose how this product will be converted: Unit Conversion (different measurement units), Repackaging (different package sizes), or Bulk Selling (quantity-based pricing)."></i>
                                        </label>
                                        <select id="auto_bom_type" name="auto_bom_type" class="form-control">
                                            <option value="unit_conversion" <?php echo $auto_bom['auto_bom_type'] === 'unit_conversion' ? 'selected' : ''; ?>>Unit Conversion</option>
                                            <option value="repackaging" <?php echo $auto_bom['auto_bom_type'] === 'repackaging' ? 'selected' : ''; ?>>Repackaging</option>
                                            <option value="bulk_selling" <?php echo $auto_bom['auto_bom_type'] === 'bulk_selling' ? 'selected' : ''; ?>>Bulk Selling</option>
                                        </select>
                                        <small class="form-text text-muted">
                                            <i class="fas fa-lightbulb"></i> Examples: Unit Conversion (20L → 1L), Repackaging (25kg bag → 1kg bags), Bulk Selling (10+ items = discount)
                                        </small>
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="base_product_search" class="dropdown-label tooltip-label">
                                            Base Product *
                                            <i class="fas fa-info-circle tooltip-icon" data-tooltip="Search and select the main product that will be used as the base for conversions. You can search by product name, SKU, or barcode."></i>
                                        </label>
                                        <div class="searchable-select-container">
                                            <input type="text" id="base_product_search" class="form-control" 
                                                   placeholder="Search by name, SKU, or barcode..." 
                                                   autocomplete="off">
                                            <input type="hidden" id="base_product_id" name="base_product_id" 
                                                   value="<?php echo $auto_bom['base_product_id']; ?>" required>
                                            <div class="search-results" id="base_product_results"></div>
                                        </div>
                                        <small class="form-text text-muted">
                                            <i class="fas fa-lightbulb"></i> Example: Type "cooking oil", "SKU000001", or scan barcode to find products
                                        </small>
                                    </div>
                                    <div class="form-group">
                                        <label for="sellable_product_search" class="dropdown-label tooltip-label">
                                            Sellable Product *
                                            <i class="fas fa-info-circle tooltip-icon" data-tooltip="Select the product that customers will buy in the POS. This is the product that will be sold to customers."></i>
                                        </label>
                                        <div class="searchable-select-container">
                                            <input type="text" id="sellable_product_search" class="form-control" 
                                                   placeholder="Search for sellable product..." 
                                                   autocomplete="off">
                                            <input type="hidden" id="product_id" name="product_id" 
                                                   value="<?php echo $auto_bom['product_id']; ?>" required>
                                            <div class="search-results" id="sellable_product_results"></div>
                                        </div>
                                        <small class="form-text text-muted">
                                            <i class="fas fa-lightbulb"></i> This is the product that customers will purchase in the POS system
                                        </small>
                                    </div>
                                    <div class="form-group">
                                        <label for="product_family_id" class="dropdown-label tooltip-label">
                                            Product Family (Optional)
                                            <i class="fas fa-info-circle tooltip-icon" data-tooltip="Group related products together for easier management. Optional - you can leave this empty if you don't need product grouping."></i>
                                        </label>
                                        <select id="product_family_id" name="product_family_id" class="form-control">
                                            <option value="">Select Product Family</option>
                                            <?php foreach ($product_families as $family): ?>
                                                <option value="<?php echo $family['id']; ?>"
                                                        <?php echo $auto_bom['product_family_id'] == $family['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($family['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="form-text text-muted">
                                            <i class="fas fa-lightbulb"></i> Example: "Cooking Oils", "Rice Products", "Beverages" - helps group related items
                                        </small>
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="base_unit" class="dropdown-label tooltip-label">
                                            Base Unit
                                            <i class="fas fa-info-circle tooltip-icon" data-tooltip="Select the measurement unit for your base product. This is how the product is measured when you buy it in bulk (e.g., liters, kilograms, pieces)."></i>
                                        </label>
                                        <select id="base_unit" name="base_unit" class="form-control">
                                            <option value="each" <?php echo $auto_bom['base_unit'] === 'each' ? 'selected' : ''; ?>>Each</option>
                                            <option value="kg" <?php echo $auto_bom['base_unit'] === 'kg' ? 'selected' : ''; ?>>Kilogram (kg)</option>
                                            <option value="g" <?php echo $auto_bom['base_unit'] === 'g' ? 'selected' : ''; ?>>Gram (g)</option>
                                            <option value="l" <?php echo $auto_bom['base_unit'] === 'l' ? 'selected' : ''; ?>>Liter (l)</option>
                                            <option value="ml" <?php echo $auto_bom['base_unit'] === 'ml' ? 'selected' : ''; ?>>Milliliter (ml)</option>
                                            <option value="pack" <?php echo $auto_bom['base_unit'] === 'pack' ? 'selected' : ''; ?>>Pack</option>
                                            <option value="case" <?php echo $auto_bom['base_unit'] === 'case' ? 'selected' : ''; ?>>Case</option>
                                            <option value="box" <?php echo $auto_bom['base_unit'] === 'box' ? 'selected' : ''; ?>>Box</option>
                                        </select>
                                        <small class="form-text text-muted">
                                            <i class="fas fa-lightbulb"></i> Example: For 20L oil drums, select "Liter (l)" as the base unit
                                        </small>
                                    </div>
                                    <div class="form-group">
                                        <label for="base_quantity" class="tooltip-label">
                                            Base Quantity
                                            <i class="fas fa-info-circle tooltip-icon" data-tooltip="Enter how many units of the base product make up one selling unit. For example, if you buy 20-liter drums and sell 1-liter bottles, enter 20."></i>
                                        </label>
                                        <input type="number" id="base_quantity" name="base_quantity" class="form-control"
                                               value="<?php echo htmlspecialchars($auto_bom['base_quantity']); ?>" 
                                               step="0.01" min="0.01" placeholder="e.g., 20">
                                        <small class="form-text text-muted">
                                            <i class="fas fa-lightbulb"></i> Example: 10kg rice bag = 10pcs 1kg (enter 10)
                                        </small>
                                    </div>
                                </div>

                                <div class="form-group full-width">
                                    <label for="description" class="tooltip-label">
                                        Description
                                        <i class="fas fa-info-circle tooltip-icon" data-tooltip="Add any additional notes or details about this Auto BOM configuration. This helps other users understand the purpose and setup of this configuration."></i>
                                    </label>
                                    <textarea id="description" name="description" class="form-control" rows="3" 
                                              placeholder="e.g., This configuration converts 20L oil drums into individual 1L bottles for retail sale..."><?php echo htmlspecialchars($auto_bom['description']); ?></textarea>
                                    <small class="form-text text-muted">
                                        <i class="fas fa-lightbulb"></i> Example: "Converts 20L cooking oil drums into 1L retail bottles. Each drum yields 20 bottles."
                                    </small>
                                </div>

                                <div class="form-check">
                                    <input type="checkbox" id="is_active" name="is_active" class="form-check-input"
                                           <?php echo $auto_bom['is_active'] ? 'checked' : ''; ?>>
                                    <label for="is_active" class="form-check-label tooltip-label">
                                        Active Configuration
                                        <i class="fas fa-info-circle tooltip-icon" data-tooltip="Enable this configuration to make it available for use in sales and inventory management. Uncheck to temporarily disable without deleting."></i>
                                    </label>
                                    <small class="form-text text-muted d-block mt-1">
                                        <i class="fas fa-lightbulb"></i> Check to enable this configuration for sales. Uncheck to temporarily disable it.
                                    </small>
                                </div>
                            </div>

                            <!-- Selling Units Configuration -->
                            <div class="form-section" id="step2">
                                <h3>Selling Units Configuration</h3>
                                <p>Modify the selling units for this Auto BOM configuration.</p>

                                <div class="selling-units-section">
                                    <div id="selling-units-container">
                                        <?php foreach ($selling_units as $index => $unit): ?>
                                        <div class="selling-unit-item" data-index="<?php echo $index; ?>">
                                            <div class="unit-header">
                                                <span class="unit-title">Selling Unit <?php echo $index + 1; ?></span>
                                                <span class="remove-unit" onclick="removeSellingUnit(<?php echo $index; ?>)">
                                                    <i class="fas fa-times"></i>
                                                </span>
                                            </div>

                                            <div class="form-row">
                                                <div class="form-group">
                                                    <label>Unit Name *</label>
                                                    <input type="text" name="unit_names[]" class="form-control"
                                                           value="<?php echo htmlspecialchars($unit['unit_name']); ?>" required>
                                                    <input type="hidden" name="unit_ids[]" value="<?php echo $unit['id']; ?>">
                                                </div>
                                                <div class="form-group">
                                                    <label>Unit Quantity *</label>
                                                    <input type="number" name="unit_quantities[]" class="form-control"
                                                           value="<?php echo htmlspecialchars($unit['unit_quantity']); ?>" step="0.01" min="0.01" required>
                                                </div>
                                                <div class="form-group">
                                                    <label>Unit SKU</label>
                                                    <input type="text" name="unit_skus[]" class="form-control"
                                                           value="<?php echo htmlspecialchars($unit['unit_sku'] ?? ''); ?>">
                                                </div>
                                            </div>

                                            <div class="form-row">
                                                <div class="form-group">
                                                    <label>Barcode</label>
                                                    <input type="text" name="unit_barcodes[]" class="form-control"
                                                           value="<?php echo htmlspecialchars($unit['unit_barcode'] ?? ''); ?>">
                                                </div>
                                                <div class="form-group">
                                                    <label>Priority</label>
                                                    <input type="number" name="priorities[]" class="form-control"
                                                           value="<?php echo htmlspecialchars($unit['priority'] ?? 0); ?>" min="0">
                                                </div>
                                                <div class="form-group">
                                                    <label>Max Qty per Sale</label>
                                                    <input type="number" name="max_quantities[]" class="form-control"
                                                           value="<?php echo htmlspecialchars($unit['max_quantity_per_sale'] ?? ''); ?>" min="1">
                                                </div>
                                            </div>

                                            <div class="form-row">
                                                <div class="form-group">
                                                    <label class="dropdown-label">Pricing Strategy</label>
                                                    <select name="pricing_strategies[]" class="form-control pricing-strategy-select">
                                                        <option value="fixed" <?php echo ($unit['pricing_strategy'] ?? 'fixed') === 'fixed' ? 'selected' : ''; ?>>Fixed Price</option>
                                                        <option value="cost_based" <?php echo ($unit['pricing_strategy'] ?? 'fixed') === 'cost_based' ? 'selected' : ''; ?>>Cost-Based</option>
                                                        <option value="market_based" <?php echo ($unit['pricing_strategy'] ?? 'fixed') === 'market_based' ? 'selected' : ''; ?>>Market-Based</option>
                                                        <option value="dynamic" <?php echo ($unit['pricing_strategy'] ?? 'fixed') === 'dynamic' ? 'selected' : ''; ?>>Dynamic</option>
                                                        <option value="hybrid" <?php echo ($unit['pricing_strategy'] ?? 'fixed') === 'hybrid' ? 'selected' : ''; ?>>Hybrid</option>
                                                    </select>
                                                </div>
                                                <div class="form-group">
                                                    <label class="dropdown-label">Status</label>
                                                    <select name="unit_statuses[]" class="form-control">
                                                        <option value="active" <?php echo ($unit['status'] ?? 'active') === 'active' ? 'selected' : ''; ?>>Active</option>
                                                        <option value="inactive" <?php echo ($unit['status'] ?? 'active') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                                    </select>
                                                </div>
                                                <div class="form-group">
                                                    <label>Image URL</label>
                                                    <input type="url" name="image_urls[]" class="form-control"
                                                           value="<?php echo htmlspecialchars($unit['image_url'] ?? ''); ?>">
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>

                                    <button type="button" id="add-selling-unit" class="btn btn-primary">
                                        <i class="fas fa-plus"></i> Add Selling Unit
                                    </button>
                                </div>
                            </div>

                            <!-- Wizard Navigation -->
                            <div class="wizard-navigation">
                                <a href="auto_bom_index.php" class="btn btn-secondary">
                                    <i class="fas fa-arrow-left"></i> Back to Auto BOM Index
                                </a>

                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-save"></i> Update Configuration
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
            let unitIndex = <?php echo count($selling_units); ?>;

            // Add selling unit functionality
            $('#add-selling-unit').click(function() {
                addSellingUnit();
            });

            function addSellingUnit(unitData = null) {
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
                                <input type="hidden" name="unit_ids[]" value="${unitData?.id || ''}">
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
                unitIndex++;
            }

            // Make removeSellingUnit global
            window.removeSellingUnit = function(index) {
                if ($('.selling-unit-item').length > 1) {
                    $(`.selling-unit-item[data-index="${index}"]`).remove();
                } else {
                    alert('At least one selling unit is required.');
                }
            };

            // Searchable Select for Base Product
            let searchTimeout;
            const products = <?php echo json_encode($products); ?>;
            
            // Set initial value if editing
            <?php if ($auto_bom['base_product_id']): ?>
            const selectedProduct = products.find(p => p.id == <?php echo $auto_bom['base_product_id']; ?>);
            if (selectedProduct) {
                $('#base_product_search').val(selectedProduct.name + ' (' + selectedProduct.sku + ') - Stock: ' + selectedProduct.quantity);
            }
            <?php endif; ?>

            let barcodeBuffer = '';
            let barcodeTimeout;
            const BARCODE_DELAY = 100; // milliseconds

            $('#base_product_search').on('input', function() {
                const query = $(this).val().trim();
                const resultsContainer = $('#base_product_results');
                
                clearTimeout(searchTimeout);
                
                if (query.length < 2) {
                    resultsContainer.removeClass('show').empty();
                    return;
                }
                
                searchTimeout = setTimeout(() => {
                    const filteredProducts = products.filter(product => 
                        product.name.toLowerCase().includes(query.toLowerCase()) ||
                        product.sku.toLowerCase().includes(query.toLowerCase()) ||
                        (product.barcode && product.barcode.toLowerCase().includes(query.toLowerCase()))
                    );
                    
                    displaySearchResults(filteredProducts, resultsContainer);
                }, 300);
            });

            // Handle barcode scanning
            $('#base_product_search').on('keydown', function(e) {
                // Clear any existing timeout
                clearTimeout(barcodeTimeout);
                
                // If it's an Enter key, prevent form submission
                if (e.key === 'Enter') {
                    e.preventDefault();
                    
                    // Check if this looks like a barcode scan (fast input)
                    if (barcodeBuffer.length > 0) {
                        // Look for exact barcode match first
                        const barcodeMatch = products.find(product => 
                            product.barcode && product.barcode === barcodeBuffer
                        );
                        
                        if (barcodeMatch) {
                            selectProduct(barcodeMatch);
                            barcodeBuffer = '';
                            return;
                        }
                        
                        // If no barcode match, treat as regular search
                        const query = barcodeBuffer;
                        const resultsContainer = $('#base_product_results');
                        const filteredProducts = products.filter(product => 
                            product.name.toLowerCase().includes(query.toLowerCase()) ||
                            product.sku.toLowerCase().includes(query.toLowerCase()) ||
                            (product.barcode && product.barcode.toLowerCase().includes(query.toLowerCase()))
                        );
                        
                        if (filteredProducts.length === 1) {
                            // Auto-select if only one result
                            selectProduct(filteredProducts[0]);
                        } else {
                            // Show results if multiple matches
                            displaySearchResults(filteredProducts, resultsContainer);
                        }
                        
                        barcodeBuffer = '';
                        return;
                    }
                }
                
                // Build barcode buffer for fast input detection
                if (e.key.length === 1) { // Single character
                    barcodeBuffer += e.key;
                    
                    // Clear buffer after delay (indicates typing, not scanning)
                    barcodeTimeout = setTimeout(() => {
                        barcodeBuffer = '';
                    }, BARCODE_DELAY);
                }
            });

            function displaySearchResults(products, container) {
                container.empty();
                
                if (products.length === 0) {
                    container.html('<div class="no-results">No products found</div>');
                } else {
                    products.forEach(product => {
                        const item = $(`
                            <div class="search-result-item" data-id="${product.id}">
                                <div class="product-name">${product.name}</div>
                                <div class="product-details">
                                    <span class="product-sku">${product.sku}</span>
                                    <span class="product-stock">Stock: ${product.quantity}</span>
                                </div>
                            </div>
                        `);
                        
                        item.click(function() {
                            selectProduct(product);
                        });
                        
                        container.append(item);
                    });
                }
                
                container.addClass('show');
            }

            function selectProduct(product) {
                $('#base_product_search').val(product.name + ' (' + product.sku + ') - Stock: ' + product.quantity);
                $('#base_product_id').val(product.id);
                $('#base_product_results').removeClass('show');
                
                // Show success feedback
                showScanFeedback('Product selected: ' + product.name, 'success');
            }

            function showScanFeedback(message, type) {
                // Remove any existing feedback
                $('.scan-feedback').remove();
                
                const feedback = $(`
                    <div class="scan-feedback scan-feedback-${type}">
                        <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
                        ${message}
                    </div>
                `);
                
                $('.searchable-select-container').append(feedback);
                
                // Auto-remove after 3 seconds
                setTimeout(() => {
                    feedback.fadeOut(300, function() {
                        $(this).remove();
                    });
                }, 3000);
            }

            // Hide results when clicking outside
            $(document).click(function(e) {
                if (!$(e.target).closest('.searchable-select-container').length) {
                    $('#base_product_results').removeClass('show');
                    $('#sellable_product_results').removeClass('show');
                }
            });

            // Searchable Select for Sellable Product
            let sellableSearchTimeout;
            
            // Set initial value if editing
            <?php if ($auto_bom['product_id']): ?>
            const selectedSellableProduct = products.find(p => p.id == <?php echo $auto_bom['product_id']; ?>);
            if (selectedSellableProduct) {
                $('#sellable_product_search').val(selectedSellableProduct.name + ' (' + selectedSellableProduct.sku + ') - Stock: ' + selectedSellableProduct.quantity);
            }
            <?php endif; ?>

            $('#sellable_product_search').on('input', function() {
                const query = $(this).val().trim();
                const resultsContainer = $('#sellable_product_results');
                
                clearTimeout(sellableSearchTimeout);
                
                if (query.length < 2) {
                    resultsContainer.removeClass('show').empty();
                    return;
                }
                
                sellableSearchTimeout = setTimeout(() => {
                    const filteredProducts = products.filter(product => 
                        product.name.toLowerCase().includes(query.toLowerCase()) ||
                        product.sku.toLowerCase().includes(query.toLowerCase()) ||
                        (product.barcode && product.barcode.toLowerCase().includes(query.toLowerCase()))
                    );
                    
                    displaySellableSearchResults(filteredProducts, resultsContainer);
                }, 300);
            });

            function displaySellableSearchResults(products, container) {
                container.empty();
                
                if (products.length === 0) {
                    container.html(`
                        <div class="search-result-item no-results">
                            <i class="fas fa-search"></i>
                            <span>No products found</span>
                        </div>
                    `);
                } else {
                    products.forEach(product => {
                        const item = $(`
                            <div class="search-result-item" data-product-id="${product.id}">
                                <div class="product-info">
                                    <div class="product-name">${product.name}</div>
                                    <div class="product-details">
                                        <span class="product-sku">SKU: ${product.sku}</span>
                                        <span class="product-stock">Stock: ${product.quantity}</span>
                                        ${product.barcode ? `<span class="product-barcode">Barcode: ${product.barcode}</span>` : ''}
                                    </div>
                                </div>
                            </div>
                        `);
                        
                        item.click(function() {
                            selectSellableProduct(product);
                        });
                        
                        container.append(item);
                    });
                }
                
                container.addClass('show');
            }

            function selectSellableProduct(product) {
                $('#sellable_product_search').val(product.name + ' (' + product.sku + ') - Stock: ' + product.quantity);
                $('#product_id').val(product.id);
                $('#sellable_product_results').removeClass('show');
                
                // Show success feedback
                showScanFeedback('Sellable product selected: ' + product.name, 'success');
            }

            // Form validation
            $('#auto-bom-form').submit(function(e) {
                const configName = $('#config_name').val().trim();
                const baseProductId = $('#base_product_id').val();
                const sellableProductId = $('#product_id').val();

                if (!configName) {
                    alert('Please enter a configuration name.');
                    e.preventDefault();
                    return false;
                }

                if (!baseProductId) {
                    alert('Please select a base product.');
                    e.preventDefault();
                    return false;
                }

                if (!sellableProductId) {
                    alert('Please select a sellable product.');
                    e.preventDefault();
                    return false;
                }

                // Validate selling units
                let hasValidUnit = false;
                $('input[name="unit_names[]"]').each(function() {
                    if ($(this).val().trim()) {
                        hasValidUnit = true;
                        return false;
                    }
                });

                if (!hasValidUnit) {
                    alert('Please add at least one selling unit.');
                    e.preventDefault();
                    return false;
                }

                return true;
            });
        });
    </script>
</body>
</html>
