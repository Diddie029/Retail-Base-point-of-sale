<?php
/**
 * Multi-Level BOM (Bill of Materials) Demo and User Guide
 * 
 * This page shows you how to create and manage complex product recipes
 * where finished products can be used as ingredients in other products.
 * 
 * WHAT YOU'LL LEARN:
 * =================
 * 1. How to create recipes with sub-recipes
 * 2. How costs automatically calculate from all levels
 * 3. How to track what ingredients are needed
 * 4. How to see where your products are used
 * 5. How to manage complex manufacturing processes
 * 
 * PERFECT FOR:
 * ============
 * - Restaurants with complex dishes
 * - Manufacturers with multi-step processes
 * - Businesses that make products from other products
 * - Anyone who needs accurate cost tracking
 * 
 * @author POS System Development Team
 * @version 2.0
 * @since 2024-01-01
 */

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

// Check BOM permissions
$can_manage_boms = hasPermission('manage_boms', $permissions);

if (!$can_manage_boms) {
    header("Location: index.php?error=permission_denied");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get existing BOMs for demonstration
$stmt = $conn->prepare("
    SELECT bh.id, bh.bom_number, bh.name, p.name as product_name
    FROM bom_headers bh
    INNER JOIN products p ON bh.product_id = p.id
    WHERE bh.status = 'active'
    ORDER BY bh.created_at DESC
    LIMIT 10
");
$stmt->execute();
$existing_boms = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get BOM structure if requested
$bom_structure = null;
if (isset($_GET['bom_id'])) {
    $bom_structure = getBOMStructure($conn, intval($_GET['bom_id']));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Multi-Level BOM Demo - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }

        .demo-section {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border: 1px solid #e2e8f0;
        }

        .demo-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 1rem;
        }

        .level-indicator {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-right: 0.5rem;
        }

        .level-0 { background: #dbeafe; color: #1d4ed8; }
        .level-1 { background: #d1fae5; color: #059669; }
        .level-2 { background: #fef3c7; color: #d97706; }
        .level-3 { background: #fee2e2; color: #dc2626; }

        .bom-tree {
            margin-left: 2rem;
            border-left: 2px solid var(--primary-color);
            padding-left: 1rem;
        }

        .bom-node {
            padding: 0.75rem;
            margin-bottom: 0.5rem;
            border-radius: 8px;
            border: 2px solid #e2e8f0;
            background: #f8fafc;
        }

        .bom-node.active {
            border-color: var(--primary-color);
            background: #f0f4ff;
        }

        .component-arrow {
            color: var(--primary-color);
            margin-right: 0.5rem;
        }

        .cost-breakdown {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-top: 1rem;
        }

        .scenario-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border: 1px solid #e2e8f0;
        }

        .scenario-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 1rem;
        }

        .step-number {
            display: inline-block;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: var(--primary-color);
            color: white;
            text-align: center;
            line-height: 30px;
            font-weight: 600;
            margin-right: 1rem;
        }

        .example-flow {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin: 2rem 0;
            padding: 1rem;
            background: #f8fafc;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }

        .flow-arrow {
            font-size: 1.5rem;
            color: var(--primary-color);
        }

        @media (max-width: 768px) {
            .example-flow {
                flex-direction: column;
                gap: 1rem;
            }

            .flow-arrow {
                transform: rotate(90deg);
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
                    <h1>Multi-Level BOM Demonstration</h1>
                    <p class="header-subtitle">Understanding how Flour → Cake → Wedding Cake works in manufacturing</p>
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
            <!-- How It Works -->
            <div class="demo-section">
                <h2 class="demo-title">
                    <i class="bi bi-book me-2"></i>How Multi-Level Recipes Work
                </h2>
                
                <div class="row">
                    <div class="col-lg-6">
                        <h4><i class="bi bi-lightbulb me-2"></i>Simple Concept</h4>
                        <div class="alert alert-info">
                            <h6>Think of it like cooking:</h6>
                            <ul class="mb-0">
                                <li><strong>Level 1:</strong> Basic ingredients (flour, eggs, sugar)</li>
                                <li><strong>Level 2:</strong> Simple recipes (cake batter, frosting)</li>
                                <li><strong>Level 3:</strong> Complex dishes (wedding cake)</li>
                            </ul>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <h4><i class="bi bi-calculator me-2"></i>Automatic Costing</h4>
                        <div class="alert alert-success">
                            <h6>Smart Cost Tracking:</h6>
                            <ul class="mb-0">
                                <li><strong>Automatic:</strong> Costs roll up from all levels</li>
                                <li><strong>Accurate:</strong> No double-counting or missed costs</li>
                                <li><strong>Real-time:</strong> Updates when ingredient prices change</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <h4><i class="bi bi-star me-2"></i>Key Benefits</h4>
                <div class="row">
                    <div class="col-md-6">
                        <div class="card mb-3">
                            <div class="card-header">
                                <h6 class="mb-0">💰 Accurate Pricing</h6>
                            </div>
                            <div class="card-body">
                                <p class="mb-2">Know exactly what your products cost to make, including all hidden costs from sub-recipes.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card mb-3">
                            <div class="card-header">
                                <h6 class="mb-0">📋 Complete Inventory</h6>
                            </div>
                            <div class="card-body">
                                <p class="mb-2">See exactly what ingredients you need, even from complex multi-step recipes.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- How to Get Started -->
            <div class="demo-section">
                <h2 class="demo-title">
                    <i class="bi bi-list-ol me-2"></i>How to Get Started
                </h2>
                
                <div class="row">
                    <div class="col-lg-4">
                        <div class="scenario-card">
                            <div class="scenario-title">
                                <span class="step-number">1</span>
                                Set Up Your Products
                            </div>
                            <p>Add all your basic ingredients and finished products to your product catalog:</p>
                            <ul>
                                <li>Add raw materials (flour, sugar, etc.)</li>
                                <li>Add finished products (cakes, bread, etc.)</li>
                                <li>Set accurate cost prices</li>
                            </ul>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="scenario-card">
                            <div class="scenario-title">
                                <span class="step-number">2</span>
                                Create Simple Recipes
                            </div>
                            <p>Start with basic recipes that use only raw materials:</p>
                            <ul>
                                <li>Create cake batter recipe</li>
                                <li>Create frosting recipe</li>
                                <li>Test that costs calculate correctly</li>
                            </ul>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="scenario-card">
                            <div class="scenario-title">
                                <span class="step-number">3</span>
                                Build Complex Recipes
                            </div>
                            <p>Use your simple recipes as ingredients in complex dishes:</p>
                            <ul>
                                <li>Create wedding cake using cake and frosting</li>
                                <li>Watch costs automatically roll up</li>
                                <li>See complete ingredient lists</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-lg-4">
                        <div class="scenario-card">
                            <div class="scenario-title">
                                <span class="step-number">4</span>
                                Track Your Inventory
                            </div>
                            <p>Use your recipes to manage inventory:</p>
                            <ul>
                                <li>See what ingredients you need</li>
                                <li>Plan purchases based on production</li>
                                <li>Avoid running out of key items</li>
                            </ul>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="scenario-card">
                            <div class="scenario-title">
                                <span class="step-number">5</span>
                                Price Your Products
                            </div>
                            <p>Set profitable prices using accurate costs:</p>
                            <ul>
                                <li>Add your desired profit margin</li>
                                <li>Compare with competitor prices</li>
                                <li>Adjust recipes to improve profitability</li>
                            </ul>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="scenario-card">
                            <div class="scenario-title">
                                <span class="step-number">6</span>
                                Monitor and Improve
                            </div>
                            <p>Keep your recipes and costs up to date:</p>
                            <ul>
                                <li>Update ingredient prices regularly</li>
                                <li>Refine recipes for better efficiency</li>
                                <li>Track which products are most profitable</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Real-World Examples -->
            <div class="demo-section">
                <h2 class="demo-title">
                    <i class="bi bi-cup me-2"></i>Real-World Examples
                </h2>
                
                <div class="row">
                    <div class="col-lg-6">
                        <h5>🍰 Bakery Example</h5>
                        <div class="card">
                            <div class="card-body">
                                <h6>Wedding Cake Recipe:</h6>
                                <ul class="mb-3">
                                    <li><strong>Level 1:</strong> Cake Batter (uses flour, eggs, sugar)</li>
                                    <li><strong>Level 1:</strong> Frosting (uses butter, sugar, vanilla)</li>
                                    <li><strong>Level 2:</strong> Wedding Cake (uses cake batter + frosting)</li>
                                </ul>
                                <p class="text-muted">When you make a wedding cake, the system automatically calculates the cost of all ingredients from both levels!</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <h5>🍕 Restaurant Example</h5>
                        <div class="card">
                            <div class="card-body">
                                <h6>Pizza Recipe:</h6>
                                <ul class="mb-3">
                                    <li><strong>Level 1:</strong> Pizza Dough (uses flour, water, yeast)</li>
                                    <li><strong>Level 1:</strong> Pizza Sauce (uses tomatoes, herbs, spices)</li>
                                    <li><strong>Level 2:</strong> Margherita Pizza (uses dough + sauce + cheese)</li>
                                </ul>
                                <p class="text-muted">Perfect for restaurants that make their own dough and sauce, then use them in multiple dishes!</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row mt-4">
                    <div class="col-lg-6">
                        <h5>🏭 Manufacturing Example</h5>
                        <div class="card">
                            <div class="card-body">
                                <h6>Electronic Device:</h6>
                                <ul class="mb-3">
                                    <li><strong>Level 1:</strong> Circuit Board (uses chips, resistors, capacitors)</li>
                                    <li><strong>Level 1:</strong> Plastic Housing (uses plastic pellets, molds)</li>
                                    <li><strong>Level 2:</strong> Complete Device (uses circuit board + housing)</li>
                                </ul>
                                <p class="text-muted">Ideal for manufacturers who build sub-assemblies and then combine them into final products!</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <h5>☕ Coffee Shop Example</h5>
                        <div class="card">
                            <div class="card-body">
                                <h6>Specialty Drink:</h6>
                                <ul class="mb-3">
                                    <li><strong>Level 1:</strong> Espresso Shot (uses coffee beans, water)</li>
                                    <li><strong>Level 1:</strong> Syrup Mix (uses sugar, flavoring, water)</li>
                                    <li><strong>Level 2:</strong> Caramel Latte (uses espresso + syrup + milk)</li>
                                </ul>
                                <p class="text-muted">Great for coffee shops that make their own syrups and use them in multiple drinks!</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Introduction -->
            <div class="demo-section">
                <h2 class="demo-title">
                    <i class="bi bi-diagram-3 me-2"></i>What is Multi-Level BOM?
                </h2>

                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    <strong>Multi-Level BOMs</strong> allow finished products to become components in other products.
                    This creates a hierarchical manufacturing structure where costs automatically roll up from all levels.
                </div>

                <!-- Real-world Example -->
                <div class="example-flow">
                    <div class="text-center">
                        <div class="fw-bold text-primary mb-2">Level 3: Raw Materials</div>
                        <div class="p-3 bg-light rounded">
                            <i class="bi bi-wheat display-4 text-warning"></i><br>
                            <strong>Wheat</strong><br>
                            <small>Raw material</small>
                        </div>
                    </div>

                    <div class="flow-arrow">→</div>

                    <div class="text-center">
                        <div class="fw-bold text-success mb-2">Level 2: Sub-Assembly</div>
                        <div class="p-3 bg-light rounded">
                            <i class="bi bi-cup display-4 text-info"></i><br>
                            <strong>Flour</strong><br>
                            <small>From wheat BOM</small>
                        </div>
                    </div>

                    <div class="flow-arrow">→</div>

                    <div class="text-center">
                        <div class="fw-bold text-warning mb-2">Level 1: Assembly</div>
                        <div class="p-3 bg-light rounded">
                            <i class="bi bi-cake display-4 text-danger"></i><br>
                            <strong>Cake</strong><br>
                            <small>Uses flour</small>
                        </div>
                    </div>

                    <div class="flow-arrow">→</div>

                    <div class="text-center">
                        <div class="fw-bold text-danger mb-2">Level 0: Final Product</div>
                        <div class="p-3 bg-light rounded">
                            <i class="bi bi-star display-4 text-primary"></i><br>
                            <strong>Wedding Cake</strong><br>
                            <small>Uses cake</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- How It Works -->
            <div class="demo-section">
                <h3 class="demo-title">
                    <i class="bi bi-gear me-2"></i>How Multi-Level BOMs Work
                </h3>

                <div class="row">
                    <div class="col-md-6">
                        <div class="scenario-card">
                            <h4 class="scenario-title">
                                <i class="bi bi-check-circle text-success me-2"></i>Automatic Cost Roll-up
                            </h4>
                            <p>When you add flour (with its own BOM) to a cake BOM, the system:</p>
                            <ol>
                                <li>Calculates flour's manufacturing cost from its wheat BOM</li>
                                <li>Uses that cost as the "unit cost" for flour in the cake BOM</li>
                                <li>Adds cake's other costs (sugar, eggs, etc.)</li>
                                <li>Provides the true total cost for the cake</li>
                            </ol>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="scenario-card">
                            <h4 class="scenario-title">
                                <i class="bi bi-graph-up text-primary me-2"></i>Accurate Profit Margins
                            </h4>
                            <p>Multi-level BOMs ensure accurate pricing because:</p>
                            <ul>
                                <li>All manufacturing costs are included</li>
                                <li>No hidden costs from sub-assemblies</li>
                                <li>Profit margins reflect true manufacturing costs</li>
                                <li>Pricing decisions are based on complete cost data</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- View Existing BOM Structure -->
            <?php if (!empty($existing_boms)): ?>
            <div class="demo-section">
                <h3 class="demo-title">
                    <i class="bi bi-eye me-2"></i>Explore Your BOM Structures
                </h3>

                <div class="row">
                    <div class="col-md-6">
                        <h5>Select a BOM to view its structure:</h5>
                        <form method="GET" class="mb-3">
                            <select name="bom_id" class="form-select" onchange="this.form.submit()">
                                <option value="">Choose a BOM...</option>
                                <?php foreach ($existing_boms as $bom): ?>
                                <option value="<?php echo $bom['id']; ?>" <?php echo (isset($_GET['bom_id']) && $_GET['bom_id'] == $bom['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($bom['bom_number'] . ' - ' . $bom['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>

                    <div class="col-md-6">
                        <div class="alert alert-light">
                            <strong>Pro Tip:</strong> Look for components with <span class="badge bg-info">Has Sub-BOM</span> badges.
                            These components have their own manufacturing recipes that contribute to the total cost.
                        </div>
                    </div>
                </div>

                <?php if ($bom_structure && !isset($bom_structure['error'])): ?>
                <div class="mt-4">
                    <h5>BOM Structure for: <?php echo htmlspecialchars($bom_structure['bom_number'] . ' - ' . $bom_structure['product_name']); ?></h5>
                    <div class="bom-tree">
                        <?php
                        function displayBOMStructure($structure, $level = 0) {
                            $levelClass = 'level-' . $level;
                            $indent = str_repeat('  ', $level);
                            ?>
                            <div class="bom-node <?php echo $level === 0 ? 'active' : ''; ?>">
                                <div class="d-flex align-items-center">
                                    <span class="<?php echo $levelClass; ?>"><?php echo $level === 0 ? 'Final' : 'Sub'; ?> BOM</span>
                                    <strong><?php echo htmlspecialchars($structure['bom_number'] . ': ' . $structure['product_name']); ?></strong>
                                </div>
                                <small class="text-muted">Version <?php echo $structure['version']; ?> | <?php echo ucfirst($structure['status']); ?></small>
                            </div>

                            <?php if (!empty($structure['components'])): ?>
                                <div class="bom-tree">
                                    <?php foreach ($structure['components'] as $component): ?>
                                        <div class="bom-node">
                                            <div class="d-flex align-items-center">
                                                <i class="bi bi-arrow-right component-arrow"></i>
                                                <strong><?php echo htmlspecialchars($component['component_name']); ?></strong>
                                                <?php if ($component['has_sub_bom']): ?>
                                                <span class="badge bg-info ms-2">
                                                    <i class="bi bi-diagram-3 me-1"></i>Has Sub-BOM
                                                </span>
                                                <?php endif; ?>
                                            </div>
                                            <small class="text-muted">
                                                Quantity: <?php echo $component['quantity_required']; ?> <?php echo htmlspecialchars($component['unit_of_measure']); ?>
                                                | Waste: <?php echo $component['waste_percentage']; ?>%
                                                | Stock: <?php echo $component['available_stock']; ?>
                                            </small>
                                        </div>

                                        <?php if ($component['has_sub_bom'] && isset($component['sub_bom'])): ?>
                                            <?php displayBOMStructure($component['sub_bom'], $level + 1); ?>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif;
                        }

                        displayBOMStructure($bom_structure);
                        ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Cost Calculation Demo -->
            <div class="demo-section">
                <h3 class="demo-title">
                    <i class="bi bi-calculator me-2"></i>Cost Calculation Benefits
                </h3>

                <div class="cost-breakdown">
                    <h4 class="mb-3">Multi-Level Cost Roll-up Example</h4>
                    <div class="row">
                        <div class="col-md-4">
                            <h5>Wheat BOM</h5>
                            <ul class="list-unstyled">
                                <li>• Raw wheat: $2.00/kg</li>
                                <li>• Processing: $0.50/kg</li>
                                <li>• Packaging: $0.30/kg</li>
                                <li><strong>Total: $2.80/kg</strong></li>
                            </ul>
                        </div>
                        <div class="col-md-4">
                            <h5>Cake BOM</h5>
                            <ul class="list-unstyled">
                                <li>• Flour (from wheat BOM): $2.80/kg</li>
                                <li>• Sugar: $1.20/kg</li>
                                <li>• Eggs: $3.00/kg</li>
                                <li>• Labor: $2.50/kg</li>
                                <li><strong>Total: $9.50/kg</strong></li>
                            </ul>
                        </div>
                        <div class="col-md-4">
                            <h5>Wedding Cake BOM</h5>
                            <ul class="list-unstyled">
                                <li>• Cake (from cake BOM): $9.50/kg</li>
                                <li>• Icing: $2.00/kg</li>
                                <li>• Decorations: $1.50/kg</li>
                                <li>• Special packaging: $1.00/kg</li>
                                <li><strong>Total: $14.00/kg</strong></li>
                            </ul>
                        </div>
                    </div>

                    <div class="mt-3 p-3 bg-white bg-opacity-10 rounded">
                        <strong>Result:</strong> The wedding cake costs $14.00/kg, and every component's cost
                        is accurately calculated from its sub-BOMs. No costs are missed or double-counted!
                    </div>
                </div>
            </div>

            <!-- Tips for Success -->
            <div class="demo-section">
                <h2 class="demo-title">
                    <i class="bi bi-lightbulb me-2"></i>Tips for Success
                </h2>
                
                <div class="row">
                    <div class="col-lg-6">
                        <h5><i class="bi bi-check-circle me-2"></i>Getting Started Right</h5>
                        <ul>
                            <li>Start with simple recipes before building complex ones</li>
                            <li>Keep ingredient costs up to date for accurate pricing</li>
                            <li>Test your recipes with small batches first</li>
                            <li>Use clear, descriptive names for your recipes</li>
                            <li>Document any special instructions or notes</li>
                        </ul>
                    </div>
                    
                    <div class="col-lg-6">
                        <h5><i class="bi bi-graph-up me-2"></i>Maximizing Benefits</h5>
                        <ul>
                            <li>Regularly review and update your recipes</li>
                            <li>Track which products are most profitable</li>
                            <li>Look for ways to reduce costs without sacrificing quality</li>
                            <li>Use the system to plan your inventory purchases</li>
                            <li>Share recipe information with your team</li>
                        </ul>
                    </div>
                </div>
                
                <div class="row mt-4">
                    <div class="col-lg-6">
                        <h5><i class="bi bi-exclamation-triangle me-2"></i>Common Mistakes to Avoid</h5>
                        <ul>
                            <li>Don't forget to update costs when ingredient prices change</li>
                            <li>Avoid creating circular dependencies (A uses B, B uses A)</li>
                            <li>Don't skip testing your recipes before using them</li>
                            <li>Make sure all quantities are accurate</li>
                            <li>Don't forget to account for waste and shrinkage</li>
                        </ul>
                    </div>
                    
                    <div class="col-lg-6">
                        <h5><i class="bi bi-people me-2"></i>Team Collaboration</h5>
                        <ul>
                            <li>Train your team on how to use the system</li>
                            <li>Set up clear roles and permissions</li>
                            <li>Create standard procedures for recipe updates</li>
                            <li>Regularly review and improve your processes</li>
                            <li>Keep everyone informed of changes</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Ready to Start? -->
            <div class="demo-section">
                <div class="text-center">
                    <h3 class="demo-title mb-4">Ready to Create Your First Multi-Level Recipe?</h3>
                    <p class="lead mb-4">Start building complex recipes where your finished products become ingredients in other products!</p>
                    
                    <div class="d-flex justify-content-center gap-3 flex-wrap">
                        <a href="add.php" class="btn btn-primary btn-lg">
                            <i class="bi bi-plus-circle me-2"></i>Create New Recipe
                        </a>
                        <a href="index.php" class="btn btn-outline-primary btn-lg">
                            <i class="bi bi-list me-2"></i>View All Recipes
                        </a>
                        <a href="reports.php" class="btn btn-outline-info btn-lg">
                            <i class="bi bi-graph-up me-2"></i>View Cost Reports
                        </a>
                    </div>

                    <div class="mt-4 alert alert-success">
                        <i class="bi bi-check-circle me-2"></i>
                        <strong>You're all set!</strong> Your system is ready to handle complex recipes with automatic cost calculation.
                        Start with simple recipes and build up to more complex ones as you get comfortable with the system.
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
