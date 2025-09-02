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

            <!-- Action Buttons -->
            <div class="demo-section">
                <div class="text-center">
                    <h3 class="demo-title mb-4">Ready to Create Multi-Level BOMs?</h3>
                    <div class="d-flex justify-content-center gap-3 flex-wrap">
                        <a href="add.php" class="btn btn-primary btn-lg">
                            <i class="bi bi-plus-circle me-2"></i>Create New BOM
                        </a>
                        <a href="index.php" class="btn btn-outline-primary btn-lg">
                            <i class="bi bi-list me-2"></i>View All BOMs
                        </a>
                        <a href="reports.php" class="btn btn-outline-info btn-lg">
                            <i class="bi bi-graph-up me-2"></i>View Reports
                        </a>
                    </div>

                    <div class="mt-4 alert alert-success">
                        <i class="bi bi-check-circle me-2"></i>
                        <strong>Your system supports multi-level BOMs!</strong> Create nested manufacturing recipes
                        where finished products become components in other products, with automatic cost roll-up.
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
