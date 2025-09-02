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

// Check BOM permissions - use granular permission
$can_create_boms = hasPermission('create_boms', $permissions);

if (!$can_create_boms) {
    header("Location: index.php");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get available products for BOM creation (products that don't already have active BOMs)
$stmt = $conn->prepare("
    SELECT p.id, p.name, p.sku, p.price, p.cost_price, c.name as category_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.status = 'active'
    AND (p.is_bom IS NULL OR p.is_bom = 0 OR p.bom_id IS NULL)
    ORDER BY p.name ASC
");
$stmt->execute();
$available_products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all products for component selection
$stmt = $conn->prepare("
    SELECT p.id, p.name, p.sku, p.price, p.cost_price, p.quantity, c.name as category_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.status = 'active'
    ORDER BY p.name ASC
");
$stmt->execute();
$all_products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get suppliers for component supplier selection
$stmt = $conn->prepare("
    SELECT id, name
    FROM suppliers
    WHERE is_active = 1
    ORDER BY name ASC
");
$stmt->execute();
$suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Generate BOM number based on settings
$auto_generate = isset($settings['auto_generate_bom_number']) && $settings['auto_generate_bom_number'] == '1';
$bom_number = ''; // Don't generate on page load, only when needed

// Handle AJAX requests for BOM Number generation
if (isset($_GET['action']) && $_GET['action'] === 'generate_bom_number') {
    $bom_number = generateBOMNumber($conn);
    
    header('Content-Type: application/json');
    echo json_encode(['bom_number' => $bom_number]);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create BOM - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }

        .form-section {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border: 1px solid #e2e8f0;
        }

        .section-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid var(--primary-color);
        }

        .component-row {
            background: #f8fafc;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            border: 1px solid #e2e8f0;
        }

        .component-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .component-number {
            font-weight: 600;
            color: var(--primary-color);
        }

        .remove-component {
            background: none;
            border: none;
            color: #dc2626;
            cursor: pointer;
            padding: 0.25rem;
            border-radius: 4px;
            transition: all 0.2s;
        }

        .remove-component:hover {
            background: #fee2e2;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
        }

        .cost-summary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-top: 2rem;
        }

        .cost-item {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 0.75rem;
        }

        .cost-label {
            opacity: 0.9;
        }

        .cost-value {
            font-weight: 600;
            font-size: 1.1rem;
        }

        .total-cost {
            border-top: 2px solid rgba(255, 255, 255, 0.3);
            padding-top: 1rem;
            margin-top: 1rem;
            font-size: 1.25rem;
            font-weight: 700;
        }

        .btn-add-component {
            background: var(--primary-color);
            border: none;
            border-radius: 8px;
            padding: 0.75rem 1.5rem;
            color: white;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-add-component:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(99, 102, 241, 0.3);
        }

        .product-selector {
            position: relative;
        }

        .product-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
        }

        .product-option {
            padding: 0.75rem 1rem;
            cursor: pointer;
            border-bottom: 1px solid #f1f5f9;
            transition: background 0.2s;
        }

        .product-option:hover {
            background: #f8fafc;
        }

        .product-option:last-child {
            border-bottom: none;
        }

        .product-info {
            display: flex;
            justify-content: between;
            align-items: center;
        }

        .product-name {
            font-weight: 500;
        }

        .product-sku {
            font-size: 0.875rem;
            color: #64748b;
        }

        .product-price {
            font-size: 0.875rem;
            color: #059669;
            font-weight: 500;
        }

        @media (max-width: 768px) {
            .form-section {
                padding: 1rem;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .component-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <?php
    $current_page = 'bom';
    include __DIR__ . '/../include/navmenu.php';
    ?>

    <div class="main-content">
        <!-- Header -->
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1>Create Bill of Materials</h1>
                    <p class="header-subtitle">Define the components and costs for manufacturing a product</p>
                </div>
                <div class="header-actions">
                    <div class="user-info">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($username, 0, 2)); ?>
                        </div>
                        <div>
                            <div class="fw-semibold"><?php echo htmlspecialchars($username); ?></div>
                            <small class="text-muted"><?php echo htmlspecialchars($role_name); ?></small>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- Content -->
        <main class="content">
            <form id="bomForm" method="POST" action="save.php">
                <!-- BOM Header Information -->
                <div class="form-section">
                    <h3 class="section-title">
                        <i class="bi bi-info-circle me-2"></i>BOM Information
                    </h3>

                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>BOM Basics:</strong> A Bill of Materials defines what components are needed to manufacture a finished product.
                        Start by selecting the finished product and defining its components below.
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <label for="bom_number" class="form-label">
                                BOM Number <?php echo $auto_generate ? '*' : ''; ?>
                                <i class="bi bi-info-circle text-muted" data-bs-toggle="tooltip" data-bs-placement="top"
                                   title="<?php echo $auto_generate ? 'Auto-generated unique identifier for this BOM. This number cannot be changed.' : 'Enter a custom BOM number or leave blank for auto-generation.'; ?>"></i>
                            </label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="bom_number" name="bom_number"
                                       value="<?php echo htmlspecialchars($bom_number); ?>" 
                                       placeholder="<?php echo $auto_generate ? 'Generating BOM number...' : 'Enter BOM number or leave blank'; ?>"
                                       <?php echo $auto_generate ? 'readonly' : ''; ?> <?php echo $auto_generate ? 'required' : ''; ?>>
                                <?php if (!$auto_generate): ?>
                                <button type="button" class="btn btn-outline-secondary" id="generateBOMNumber">
                                    <i class="bi bi-magic"></i>
                                    Generate
                                </button>
                                <?php endif; ?>
                            </div>
                            <div class="form-text">
                                <?php if ($auto_generate): ?>
                                    <span class="text-info"><i class="bi bi-info-circle"></i> BOM number will be generated automatically when you start creating the BOM.</span>
                                <?php else: ?>
                                    Leave blank for auto-generation or enter custom number
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="product_id" class="form-label">
                                Finished Product *
                                <i class="bi bi-info-circle text-muted" data-bs-toggle="tooltip" data-bs-placement="top"
                                   title="The final product that will be manufactured using the components defined in this BOM."></i>
                            </label>
                            <select class="form-select" id="product_id" name="product_id" required>
                                <option value="">Select a finished product...</option>
                                <?php foreach ($available_products as $product): ?>
                                <option value="<?php echo $product['id']; ?>"
                                        data-price="<?php echo $product['price']; ?>"
                                        data-cost="<?php echo $product['cost_price']; ?>">
                                    <?php echo htmlspecialchars($product['name']); ?>
                                    (<?php echo htmlspecialchars($product['sku']); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Choose the product this BOM will manufacture</div>
                        </div>
                    </div>

                    <div class="row mt-3">
                        <div class="col-md-8">
                            <label for="name" class="form-label">
                                BOM Name *
                                <i class="bi bi-info-circle text-muted" data-bs-toggle="tooltip" data-bs-placement="top"
                                   title="A descriptive name for this BOM that makes it easy to identify."></i>
                            </label>
                            <input type="text" class="form-control" id="name" name="name" required
                                   placeholder="e.g., Smartphone Assembly BOM v1.0">
                            <div class="form-text">Use a descriptive name that clearly identifies this BOM</div>
                        </div>
                        <div class="col-md-4">
                            <label for="version" class="form-label">
                                Version
                                <i class="bi bi-info-circle text-muted" data-bs-toggle="tooltip" data-bs-placement="top"
                                   title="Version number for this BOM. Increment when making significant changes."></i>
                            </label>
                            <input type="number" class="form-control" id="version" name="version" value="1" min="1" required>
                            <div class="form-text">Version control for BOM changes</div>
                        </div>
                    </div>

                    <div class="row mt-3">
                        <div class="col-md-12">
                            <label for="description" class="form-label">
                                Description
                                <i class="bi bi-info-circle text-muted" data-bs-toggle="tooltip" data-bs-placement="top"
                                   title="Optional detailed description of this BOM, manufacturing notes, or special instructions."></i>
                            </label>
                            <textarea class="form-control" id="description" name="description" rows="3"
                                      placeholder="Describe the manufacturing process, special requirements, or notes about this BOM..."></textarea>
                            <div class="form-text">Optional description and manufacturing notes</div>
                        </div>
                    </div>

                    <div class="row mt-3">
                        <div class="col-md-4">
                            <label for="total_quantity" class="form-label">
                                Batch Size *
                                <i class="bi bi-info-circle text-muted" data-bs-toggle="tooltip" data-bs-placement="top"
                                   title="The quantity of finished products this BOM recipe produces in one manufacturing run."></i>
                            </label>
                            <input type="number" class="form-control" id="total_quantity" name="total_quantity" value="1" min="1" required>
                            <div class="form-text">Quantity this BOM produces per batch</div>
                        </div>
                        <div class="col-md-4">
                            <label for="unit_of_measure" class="form-label">
                                Unit of Measure
                                <i class="bi bi-info-circle text-muted" data-bs-toggle="tooltip" data-bs-placement="top"
                                   title="The unit used to measure the finished product (each, kg, liters, etc.)."></i>
                            </label>
                            <input type="text" class="form-control" id="unit_of_measure" name="unit_of_measure" value="each"
                                   placeholder="each, kg, liters, etc.">
                            <div class="form-text">Unit for measuring the finished product</div>
                        </div>
                        <div class="col-md-4">
                            <label for="status" class="form-label">
                                Status
                                <i class="bi bi-info-circle text-muted" data-bs-toggle="tooltip" data-bs-placement="top"
                                   title="BOM status: Draft (work in progress), Active (ready for production)."></i>
                            </label>
                            <select class="form-select" id="status" name="status">
                                <option value="draft">Draft (Work in Progress)</option>
                                <option value="active">Active (Production Ready)</option>
                            </select>
                            <div class="form-text">Set to Active when ready for production</div>
                        </div>
                    </div>
                </div>

                <!-- Cost Information -->
                <div class="form-section">
                    <h3 class="section-title">
                        <i class="bi bi-cash me-2"></i>Cost Information
                    </h3>

                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong>Cost Calculation:</strong> These costs will be added to the total material costs from components to calculate the final product cost.
                        Leave as 0 if you don't want to include additional labor or overhead costs.
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <label for="labor_cost" class="form-label">
                                Labor Cost (<?php echo getCurrencySymbol($settings); ?>)
                                <i class="bi bi-info-circle text-muted" data-bs-toggle="tooltip" data-bs-placement="top"
                                   title="Additional labor costs per batch (wages, benefits, training, etc.). This is added to component costs."></i>
                            </label>
                            <input type="number" class="form-control" id="labor_cost" name="labor_cost" step="0.01" min="0" value="0">
                            <div class="form-text">Total labor cost for this batch production</div>
                        </div>
                        <div class="col-md-6">
                            <label for="overhead_cost" class="form-label">
                                Overhead Cost (<?php echo getCurrencySymbol($settings); ?>)
                                <i class="bi bi-info-circle text-muted" data-bs-toggle="tooltip" data-bs-placement="top"
                                   title="Manufacturing overhead costs per batch (rent, utilities, equipment maintenance, etc.)."></i>
                            </label>
                            <input type="number" class="form-control" id="overhead_cost" name="overhead_cost" step="0.01" min="0" value="0">
                            <div class="form-text">Manufacturing overhead costs for this batch</div>
                        </div>
                    </div>
                </div>

                <!-- Components Section -->
                <div class="form-section">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h3 class="section-title mb-0">
                            <i class="bi bi-puzzle-piece me-2"></i>Components
                        </h3>
                        <button type="button" class="btn-add-component" onclick="addComponent()">
                            <i class="bi bi-plus-circle"></i>Add Component
                        </button>
                    </div>

                    <div class="alert alert-success">
                        <i class="bi bi-lightbulb me-2"></i>
                        <strong>Component Management:</strong> Add all raw materials, parts, and sub-assemblies needed to manufacture your finished product.
                        Each component can have its own BOM for multi-level manufacturing.
                    </div>

                    <div class="alert alert-primary">
                        <i class="bi bi-diagram-3 me-2"></i>
                        <strong>Multi-Level BOM Example:</strong><br>
                        <small>
                            • <strong>Flour BOM:</strong> Wheat + Water + Processing → Flour<br>
                            • <strong>Cake BOM:</strong> Flour + Sugar + Eggs + Milk → Cake<br>
                            • <strong>Wedding Cake BOM:</strong> Cake + Icing + Decorations → Wedding Cake<br>
                            <em>The system automatically calculates costs from all levels!</em>
                        </small>
                    </div>

                    <div id="components-container">
                        <!-- Components will be added here dynamically -->
                    </div>

                    <div class="text-center mt-4" id="no-components-message">
                        <i class="bi bi-puzzle-piece display-1 text-muted mb-3"></i>
                        <h5 class="text-muted">No Components Added</h5>
                        <p class="text-muted">Click "Add Component" above to start building your BOM</p>
                        <small class="text-muted">Components are the building blocks of your product</small>
                    </div>
                </div>

                <!-- Cost Summary -->
                <div class="cost-summary" id="costSummary" style="display: none;">
                    <h4 class="mb-3">
                        <i class="bi bi-calculator me-2"></i>Cost Summary
                    </h4>

                    <div class="alert alert-info mb-3">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Real-time Calculation:</strong> Costs update automatically as you add components and change quantities.
                        The cost per unit shows what each finished product costs to manufacture.
                    </div>

                    <div id="costBreakdown">
                        <div class="cost-item">
                            <span class="cost-label">Material Cost:</span>
                            <span class="cost-value" id="materialCost">0.00</span>
                        </div>
                        <div class="cost-item">
                            <span class="cost-label">Labor Cost:</span>
                            <span class="cost-value" id="laborCostDisplay">0.00</span>
                        </div>
                        <div class="cost-item">
                            <span class="cost-label">Overhead Cost:</span>
                            <span class="cost-value" id="overheadCostDisplay">0.00</span>
                        </div>
                        <div class="cost-item total-cost">
                            <span class="cost-label">Total Cost:</span>
                            <span class="cost-value" id="totalCost">0.00</span>
                        </div>
                        <div class="cost-item">
                            <span class="cost-label">Cost per Unit:</span>
                            <span class="cost-value" id="costPerUnit">0.00</span>
                        </div>
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="form-section">
                    <div class="alert alert-primary">
                        <i class="bi bi-save me-2"></i>
                        <strong>Ready to Save:</strong> Review your BOM details above. You can save it as a Draft to continue editing later,
                        or set it to Active to make it available for production. All components and cost calculations will be saved.
                    </div>

                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <small class="text-muted">* Required fields</small>
                            <br>
                            <small class="text-muted">
                                <i class="bi bi-info-circle me-1"></i>
                                Tip: Save as Draft first if you're still working on the BOM
                            </small>
                        </div>
                        <div class="d-flex gap-2">
                            <a href="index.php" class="btn btn-outline-secondary">
                                <i class="bi bi-x-circle me-2"></i>Cancel
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-circle me-2"></i>Create BOM
                            </button>
                        </div>
                    </div>
                </div>

                <input type="hidden" name="action" value="create">
            </form>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let componentCount = 0;
        const currencySymbol = '<?php echo getCurrencySymbol($settings); ?>';
        const allProducts = <?php echo json_encode($all_products); ?>;
        const suppliers = <?php echo json_encode($suppliers); ?>;

        function addComponent() {
            componentCount++;
            const container = document.getElementById('components-container');
            const noComponentsMessage = document.getElementById('no-components-message');

            // Hide the "no components" message
            if (noComponentsMessage) {
                noComponentsMessage.style.display = 'none';
            }

            const componentHtml = `
                <div class="component-row" id="component-${componentCount}">
                    <div class="component-header">
                        <div class="component-number">Component ${componentCount}</div>
                        <button type="button" class="remove-component" onclick="removeComponent(${componentCount})">
                            <i class="bi bi-x-circle"></i>
                        </button>
                    </div>

                    <div class="form-grid">
                        <div class="product-selector">
                            <label class="form-label">
                                Component Product *
                                <i class="bi bi-info-circle text-muted" data-bs-toggle="tooltip" data-bs-placement="top"
                                   title="Search and select the raw material or part needed for this BOM."></i>
                            </label>
                            <input type="text" class="form-control component-search"
                                   placeholder="Type to search for products..."
                                   onkeyup="filterProducts(this, ${componentCount})"
                                   required>
                            <input type="hidden" name="components[${componentCount}][component_product_id]" id="component_product_id_${componentCount}" required>
                            <div class="product-dropdown" id="dropdown-${componentCount}"></div>
                            <div class="form-text">Search by product name or SKU</div>
                        </div>

                        <div>
                            <label class="form-label">
                                Quantity Required *
                                <i class="bi bi-info-circle text-muted" data-bs-toggle="tooltip" data-bs-placement="top"
                                   title="How much of this component is needed per finished product unit."></i>
                            </label>
                            <input type="number" class="form-control" name="components[${componentCount}][quantity_required]"
                                   step="0.001" min="0" required onchange="calculateCosts()"
                                   placeholder="e.g., 2.5, 10, 0.25">
                            <div class="form-text">Per finished product unit</div>
                        </div>

                        <div>
                            <label class="form-label">
                                Unit of Measure
                                <i class="bi bi-info-circle text-muted" data-bs-toggle="tooltip" data-bs-placement="top"
                                   title="The unit used to measure this component (each, kg, liters, meters, etc.)."></i>
                            </label>
                            <input type="text" class="form-control" name="components[${componentCount}][unit_of_measure]"
                                   value="each" placeholder="each, kg, liters, etc.">
                            <div class="form-text">How this component is measured</div>
                        </div>

                        <div>
                            <label class="form-label">
                                Waste Percentage (%)
                                <i class="bi bi-info-circle text-muted" data-bs-toggle="tooltip" data-bs-placement="top"
                                   title="Expected waste/loss percentage during manufacturing (cutting, trimming, defects, etc.)."></i>
                            </label>
                            <input type="number" class="form-control" name="components[${componentCount}][waste_percentage]"
                                   step="0.1" min="0" max="100" value="0" onchange="calculateCosts()"
                                   placeholder="e.g., 5, 10, 2.5">
                            <div class="form-text">Expected manufacturing waste/loss</div>
                        </div>

                        <div>
                            <label class="form-label">
                                Unit Cost (<?php echo getCurrencySymbol($settings); ?>)
                                <i class="bi bi-info-circle text-muted" data-bs-toggle="tooltip" data-bs-placement="top"
                                   title="Cost per unit of this component. Auto-filled from product cost, but can be overridden."></i>
                            </label>
                            <input type="number" class="form-control component-cost"
                                   name="components[${componentCount}][unit_cost]"
                                   step="0.01" min="0" onchange="calculateCosts()"
                                   placeholder="Cost per unit">
                            <div class="form-text">Cost per unit (auto-filled from product)</div>
                        </div>

                        <div>
                            <label class="form-label">
                                Preferred Supplier
                                <i class="bi bi-info-circle text-muted" data-bs-toggle="tooltip" data-bs-placement="top"
                                   title="Optional preferred supplier for this component. Used for procurement planning."></i>
                            </label>
                            <select class="form-select" name="components[${componentCount}][supplier_id]">
                                <option value="">Select supplier (optional)...</option>
                                ${suppliers.map(supplier => `<option value="${supplier.id}">${supplier.name}</option>`).join('')}
                            </select>
                            <div class="form-text">Optional preferred supplier</div>
                        </div>
                    </div>

                    <div class="mt-3">
                        <label class="form-label">
                            Notes
                            <i class="bi bi-info-circle text-muted" data-bs-toggle="tooltip" data-bs-placement="top"
                               title="Optional notes about this component (special handling, quality requirements, alternatives, etc.)."></i>
                        </label>
                        <textarea class="form-control" name="components[${componentCount}][notes]" rows="2"
                                  placeholder="Special handling instructions, quality requirements, or other notes..."></textarea>
                        <div class="form-text">Optional notes about this component</div>
                    </div>

                    <div class="mt-3">
                        <small class="text-muted">
                            <strong>Quantity with Waste:</strong> <span id="qty-with-waste-${componentCount}">0</span> |
                            <strong>Total Cost:</strong> <?php echo getCurrencySymbol($settings); ?> <span id="total-cost-${componentCount}">0.00</span>
                        </small>
                    </div>
                </div>
            `;

            container.insertAdjacentHTML('beforeend', componentHtml);
            document.getElementById('costSummary').style.display = 'block';

            // Re-initialize tooltips for new elements
            reinitializeTooltips();
        }

        function removeComponent(id) {
            const component = document.getElementById(`component-${id}`);
            if (component) {
                component.remove();
                calculateCosts();

                // Show "no components" message if no components left
                const container = document.getElementById('components-container');
                if (container.children.length === 1 && container.querySelector('#no-components-message')) {
                    document.getElementById('no-components-message').style.display = 'block';
                    document.getElementById('costSummary').style.display = 'none';
                }
            }
        }

        function filterProducts(input, componentId) {
            const query = input.value.toLowerCase();
            const dropdown = document.getElementById(`dropdown-${componentId}`);

            if (query.length < 2) {
                dropdown.style.display = 'none';
                return;
            }

            const filtered = allProducts.filter(product =>
                product.name.toLowerCase().includes(query) ||
                product.sku.toLowerCase().includes(query)
            );

            if (filtered.length > 0) {
                const optionsHtml = filtered.slice(0, 10).map(product => `
                    <div class="product-option" onclick="selectProduct(${componentId}, ${product.id}, '${product.name.replace(/'/g, "\\'")}', '${product.sku}', ${product.cost_price || 0})">
                        <div class="product-info">
                            <div>
                                <div class="product-name">${product.name}</div>
                                <div class="product-sku">${product.sku}</div>
                            </div>
                            <div class="product-price"><?php echo getCurrencySymbol($settings); ?> ${product.cost_price || 0}</div>
                        </div>
                    </div>
                `).join('');

                dropdown.innerHTML = optionsHtml;
                dropdown.style.display = 'block';
            } else {
                dropdown.style.display = 'none';
            }
        }

        function selectProduct(componentId, productId, productName, productSku, costPrice) {
            document.getElementById(`component_product_id_${componentId}`).value = productId;
            document.querySelector(`#component-${componentId} .component-search`).value = `${productName} (${productSku})`;
            document.querySelector(`#component-${componentId} .component-cost`).value = costPrice;
            document.getElementById(`dropdown-${componentId}`).style.display = 'none';

            calculateCosts();
        }

        function calculateCosts() {
            const laborCost = parseFloat(document.getElementById('labor_cost').value) || 0;
            const overheadCost = parseFloat(document.getElementById('overhead_cost').value) || 0;
            const batchSize = parseInt(document.getElementById('total_quantity').value) || 1;

            let totalMaterialCost = 0;

            // Calculate costs for each component
            for (let i = 1; i <= componentCount; i++) {
                const componentDiv = document.getElementById(`component-${i}`);
                if (!componentDiv) continue;

                const quantityRequired = parseFloat(componentDiv.querySelector('[name*="[quantity_required]"]').value) || 0;
                const wastePercentage = parseFloat(componentDiv.querySelector('[name*="[waste_percentage]"]').value) || 0;
                const unitCost = parseFloat(componentDiv.querySelector('.component-cost').value) || 0;

                const quantityWithWaste = quantityRequired * (1 + wastePercentage / 100);
                const componentTotalCost = quantityWithWaste * unitCost;

                // Update display values
                const qtyWithWasteSpan = document.getElementById(`qty-with-waste-${i}`);
                const totalCostSpan = document.getElementById(`total-cost-${i}`);

                if (qtyWithWasteSpan) qtyWithWasteSpan.textContent = quantityWithWaste.toFixed(3);
                if (totalCostSpan) totalCostSpan.textContent = componentTotalCost.toFixed(2);

                totalMaterialCost += componentTotalCost;
            }

            const totalCost = totalMaterialCost + laborCost + overheadCost;
            const costPerUnit = totalCost / batchSize;

            // Update cost summary
            document.getElementById('materialCost').textContent = totalMaterialCost.toFixed(2);
            document.getElementById('laborCostDisplay').textContent = laborCost.toFixed(2);
            document.getElementById('overheadCostDisplay').textContent = overheadCost.toFixed(2);
            document.getElementById('totalCost').textContent = totalCost.toFixed(2);
            document.getElementById('costPerUnit').textContent = costPerUnit.toFixed(2);
        }

        // Calculate costs when labor or overhead costs change
        document.getElementById('labor_cost').addEventListener('input', calculateCosts);
        document.getElementById('overhead_cost').addEventListener('input', calculateCosts);
        document.getElementById('total_quantity').addEventListener('input', calculateCosts);

        // Initialize tooltips
        function initializeTooltips() {
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            const tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
        }

        // Initialize tooltips on page load
        document.addEventListener('DOMContentLoaded', function() {
            initializeTooltips();
        });

        // Re-initialize tooltips when adding new components
        function reinitializeTooltips() {
            setTimeout(initializeTooltips, 100);
        }

        // Close dropdowns when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.product-selector')) {
                document.querySelectorAll('.product-dropdown').forEach(dropdown => {
                    dropdown.style.display = 'none';
                });
            }

            // BOM Number Generation
            const generateBOMNumberBtn = document.getElementById('generateBOMNumber');
            const bomNumberField = document.getElementById('bom_number');
            const autoGenerate = <?php echo $auto_generate ? 'true' : 'false'; ?>;
            
            // Auto-generate BOM number if auto-generation is enabled and field is empty
            if (autoGenerate && bomNumberField && !bomNumberField.value.trim()) {
                generateBOMNumber();
            }
            
            if (generateBOMNumberBtn) {
                generateBOMNumberBtn.addEventListener('click', function() {
                    generateBOMNumber();
                });
            }
            
            function generateBOMNumber() {
                // Show loading state
                if (bomNumberField) {
                    bomNumberField.placeholder = 'Generating BOM number...';
                    bomNumberField.value = '';
                }
                
                if (generateBOMNumberBtn) {
                    const originalText = generateBOMNumberBtn.innerHTML;
                    generateBOMNumberBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Generating...';
                    generateBOMNumberBtn.disabled = true;
                }
                
                fetch('?action=generate_bom_number')
                    .then(response => response.json())
                    .then(data => {
                        if (bomNumberField) {
                            bomNumberField.value = data.bom_number;
                            bomNumberField.placeholder = '';
                        }
                    })
                    .catch(error => {
                        console.error('Error generating BOM number:', error);
                        if (bomNumberField) {
                            bomNumberField.placeholder = 'Error generating BOM number';
                        }
                    })
                    .finally(() => {
                        // Reset button state
                        if (generateBOMNumberBtn) {
                            generateBOMNumberBtn.innerHTML = '<i class="bi bi-magic"></i> Generate';
                            generateBOMNumberBtn.disabled = false;
                        }
                    });
            }
        });
    </script>
</body>
</html>
