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

// Check BOM permissions - use more granular permissions
$can_view_boms = hasPermission('view_boms', $permissions);
$can_view_components = hasPermission('view_bom_components', $permissions);
$can_view_costing = hasPermission('view_bom_costing', $permissions);

if (!$can_view_boms && !$can_view_components && !$can_view_costing) {
    header("Location: index.php");
    exit();
}

// Get BOM ID
$bom_id = intval($_GET['id'] ?? 0);
if (!$bom_id) {
    header("Location: index.php?error=invalid_bom_id");
    exit();
}

// Get BOM details
$stmt = $conn->prepare("
    SELECT
        bh.*,
        p.name as product_name,
        p.sku as product_sku,
        p.price as product_price,
        p.quantity as product_stock,
        u1.username as created_by_name,
        u2.username as approved_by_name
    FROM bom_headers bh
    INNER JOIN products p ON bh.product_id = p.id
    LEFT JOIN users u1 ON bh.created_by = u1.id
    LEFT JOIN users u2 ON bh.approved_by = u2.id
    WHERE bh.id = :bom_id
");
$stmt->bindParam(':bom_id', $bom_id, PDO::PARAM_INT);
$stmt->execute();
$bom = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$bom) {
    header("Location: index.php?error=bom_not_found");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get BOM components with cost calculation
$cost_data = calculateBOMCost($conn, $bom_id);

// Get BOM production orders
$stmt = $conn->prepare("
    SELECT
        po.*,
        u.username as created_by_name
    FROM bom_production_orders po
    LEFT JOIN users u ON po.created_by = u.id
    WHERE po.bom_id = :bom_id
    ORDER BY po.created_at DESC
    LIMIT 5
");
$stmt->bindParam(':bom_id', $bom_id, PDO::PARAM_INT);
$stmt->execute();
$production_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get where-used data for this BOM's product
$where_used = getWhereUsedReport($conn, $bom['product_id']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BOM Details - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }

        .bom-header {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border: 1px solid #e2e8f0;
        }

        .bom-title {
            font-size: 2rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 0.5rem;
        }

        .bom-subtitle {
            font-size: 1.1rem;
            color: #64748b;
            margin-bottom: 1rem;
        }

        .status-badge {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-draft { background: #fef3c7; color: #d97706; }
        .status-active { background: #d1fae5; color: #059669; }
        .status-obsolete { background: #fee2e2; color: #dc2626; }
        .status-archived { background: #f3f4f6; color: #374151; }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .info-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border: 1px solid #e2e8f0;
        }

        .info-label {
            font-size: 0.875rem;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
        }

        .info-value {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1e293b;
        }

        .cost-breakdown {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .cost-item {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 1rem;
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

        .components-section {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border: 1px solid #e2e8f0;
        }

        .component-row {
            display: flex;
            justify-content: between;
            align-items: center;
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
            transition: background 0.2s;
        }

        .component-row:hover {
            background: #f8fafc;
        }

        .component-row:last-child {
            border-bottom: none;
        }

        .component-info {
            flex: 1;
        }

        .component-name {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.25rem;
        }

        .component-details {
            color: #64748b;
            font-size: 0.875rem;
        }

        .component-cost {
            text-align: right;
            font-weight: 600;
            color: #059669;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
        }

        .btn-action {
            padding: 0.375rem 0.75rem;
            border-radius: 6px;
            font-size: 0.875rem;
            font-weight: 500;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-primary { background: var(--primary-color); color: white; }
        .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3); }

        .btn-outline-primary {
            background: transparent;
            border: 1px solid var(--primary-color);
            color: var(--primary-color);
        }
        .btn-outline-primary:hover {
            background: var(--primary-color);
            color: white;
        }

        .production-orders {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border: 1px solid #e2e8f0;
        }

        .order-row {
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
        }

        .order-row:last-child {
            border-bottom: none;
        }

        @media (max-width: 768px) {
            .bom-header {
                padding: 1rem;
            }

            .bom-title {
                font-size: 1.5rem;
            }

            .info-grid {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 1rem;
            }

            .component-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .component-cost {
                text-align: left;
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
                    <h1>BOM Details</h1>
                    <p class="header-subtitle">View and manage bill of materials information</p>
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
            <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle me-2"></i>
                <?php
                switch ($_GET['success']) {
                    case 'bom_created':
                        echo "BOM created successfully!";
                        break;
                    case 'bom_updated':
                        echo "BOM updated successfully!";
                        break;
                    case 'bom_approved':
                        echo "BOM approved successfully!";
                        break;
                    default:
                        echo "Operation completed successfully!";
                }
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <?php echo htmlspecialchars($_GET['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Multi-level BOM Information -->
            <?php
            $multi_level_count = $cost_data['multi_level_components'] ?? 0;
            if ($multi_level_count > 0):
            ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <i class="bi bi-diagram-3 me-2"></i>
                <strong>Multi-Level BOM:</strong> This BOM contains <?php echo $multi_level_count; ?> component(s) that have their own Bill of Materials.
                The system automatically rolls up costs from all sub-BOMs to provide accurate total costs.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- BOM Structure Information -->
            <div class="alert alert-light border">
                <i class="bi bi-info-circle me-2"></i>
                <strong>How Multi-Level BOMs Work:</strong><br>
                <small class="text-muted">
                    • Components with <span class="badge bg-info">Has Sub-BOM</span> badges have their own manufacturing recipes<br>
                    • Example: Flour (with wheat BOM) → Cake → Wedding Cake (with cake BOM)<br>
                    • Costs automatically roll up from sub-BOMs for accurate pricing
                </small>
            </div>

            <!-- BOM Header -->
            <div class="bom-header">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div>
                        <h1 class="bom-title"><?php echo htmlspecialchars($bom['bom_number']); ?> - <?php echo htmlspecialchars($bom['name']); ?></h1>
                        <p class="bom-subtitle"><?php echo htmlspecialchars($bom['product_name']); ?> (<?php echo htmlspecialchars($bom['product_sku']); ?>)</p>
                    </div>
                    <div class="d-flex gap-2">
                        <span class="status-badge status-<?php echo $bom['status']; ?>">
                            <?php echo ucfirst($bom['status']); ?>
                        </span>
                        <?php if ($can_manage_boms): ?>
                        <div class="dropdown">
                            <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                <i class="bi bi-three-dots"></i>
                            </button>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="edit.php?id=<?php echo $bom['id']; ?>">
                                    <i class="bi bi-pencil me-2"></i>Edit BOM
                                </a></li>
                                <li><a class="dropdown-item" href="production.php?bom_id=<?php echo $bom['id']; ?>">
                                    <i class="bi bi-gear me-2"></i>Create Production Order
                                </a></li>
                                <?php if ($can_approve_boms && $bom['status'] === 'draft'): ?>
                                <li><a class="dropdown-item" href="#" onclick="approveBOM(<?php echo $bom['id']; ?>)">
                                    <i class="bi bi-check-circle me-2"></i>Approve BOM
                                </a></li>
                                <?php endif; ?>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-danger" href="#" onclick="deleteBOM(<?php echo $bom['id']; ?>, '<?php echo htmlspecialchars($bom['bom_number']); ?>')">
                                    <i class="bi bi-trash me-2"></i>Delete BOM
                                </a></li>
                            </ul>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($bom['description'])): ?>
                <p class="text-muted mb-3"><?php echo htmlspecialchars($bom['description']); ?></p>
                <?php endif; ?>

                <div class="info-grid">
                    <div class="info-card">
                        <div class="info-label">Version</div>
                        <div class="info-value"><?php echo $bom['version']; ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Batch Size</div>
                        <div class="info-value"><?php echo number_format($bom['total_quantity']); ?> <?php echo htmlspecialchars($bom['unit_of_measure']); ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Total Cost</div>
                        <div class="info-value"><?php echo formatCurrency($bom['total_cost'], $settings); ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Cost per Unit</div>
                        <div class="info-value"><?php echo formatCurrency($bom['total_cost'] / max($bom['total_quantity'], 1), $settings); ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Created</div>
                        <div class="info-value"><?php echo date('M j, Y', strtotime($bom['created_at'])); ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Created By</div>
                        <div class="info-value"><?php echo htmlspecialchars($bom['created_by_name'] ?? 'Unknown'); ?></div>
                    </div>
                </div>
            </div>

            <!-- Cost Breakdown -->
            <?php if (!empty($cost_data['components'])): ?>
            <div class="cost-breakdown">
                <h4 class="mb-4">
                    <i class="bi bi-calculator me-2"></i>Cost Breakdown
                </h4>

                <div class="cost-item">
                    <span class="cost-label">Material Cost:</span>
                    <span class="cost-value"><?php echo formatCurrency($cost_data['material_cost'], $settings); ?></span>
                </div>
                <div class="cost-item">
                    <span class="cost-label">Labor Cost:</span>
                    <span class="cost-value"><?php echo formatCurrency($cost_data['labor_cost'], $settings); ?></span>
                </div>
                <div class="cost-item">
                    <span class="cost-label">Overhead Cost:</span>
                    <span class="cost-value"><?php echo formatCurrency($cost_data['overhead_cost'], $settings); ?></span>
                </div>
                <div class="cost-item total-cost">
                    <span class="cost-label">Total Cost:</span>
                    <span class="cost-value"><?php echo formatCurrency($cost_data['total_cost'], $settings); ?></span>
                </div>
                <div class="cost-item">
                    <span class="cost-label">Cost per Unit:</span>
                    <span class="cost-value"><?php echo formatCurrency($cost_data['cost_per_unit'], $settings); ?></span>
                </div>
            </div>
            <?php endif; ?>

            <!-- Components -->
            <div class="components-section">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h4 class="mb-0">
                        <i class="bi bi-puzzle-piece me-2"></i>Components (<?php echo count($cost_data['components'] ?? []); ?>)
                    </h4>
                    <?php if ($can_manage_boms): ?>
                    <a href="edit.php?id=<?php echo $bom['id']; ?>" class="btn btn-outline-primary">
                        <i class="bi bi-plus-circle me-2"></i>Edit Components
                    </a>
                    <?php endif; ?>
                </div>

                <?php if (!empty($cost_data['components'])): ?>
                <div class="components-list">
                    <?php foreach ($cost_data['components'] as $component): ?>
                    <div class="component-row">
                        <div class="component-info">
                            <div class="component-name">
                                <?php echo htmlspecialchars($component['component_name']); ?>
                                <small class="text-muted">(<?php echo htmlspecialchars($component['component_sku']); ?>)</small>
                                <?php if (($component['has_sub_bom'] ?? false)): ?>
                                <span class="badge bg-info ms-2">
                                    <i class="bi bi-diagram-3 me-1"></i>Has Sub-BOM
                                </span>
                                <?php endif; ?>
                            </div>
                            <div class="component-details">
                                Quantity: <?php echo number_format($component['quantity_required'], 3); ?> <?php echo htmlspecialchars($component['unit_of_measure']); ?>
                                <?php if ($component['waste_percentage'] > 0): ?>
                                | Waste: <?php echo $component['waste_percentage']; ?>%
                                | With Waste: <?php echo number_format($component['quantity_with_waste'], 3); ?>
                                <?php endif; ?>
                                | Stock: <?php echo number_format($component['available_stock']); ?>
                                <span class="badge bg-<?php echo $component['stock_status'] === 'sufficient' ? 'success' : 'warning'; ?> ms-2">
                                    <?php echo ucfirst($component['stock_status']); ?>
                                </span>
                            </div>
                        </div>
                        <div class="component-cost">
                            <?php echo formatCurrency($component['total_cost'], $settings); ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="text-center py-4">
                    <i class="bi bi-puzzle-piece display-1 text-muted mb-3"></i>
                    <h5 class="text-muted">No Components</h5>
                    <p class="text-muted">This BOM doesn't have any components defined yet.</p>
                    <?php if ($can_manage_boms): ?>
                    <a href="edit.php?id=<?php echo $bom['id']; ?>" class="btn btn-primary">
                        <i class="bi bi-plus-circle me-2"></i>Add Components
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Production Orders -->
            <?php if (!empty($production_orders)): ?>
            <div class="production-orders">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h4 class="mb-0">
                        <i class="bi bi-gear me-2"></i>Recent Production Orders
                    </h4>
                    <a href="production.php?bom_id=<?php echo $bom['id']; ?>" class="btn btn-primary">
                        <i class="bi bi-plus-circle me-2"></i>Create Production Order
                    </a>
                </div>

                <div class="production-list">
                    <?php foreach ($production_orders as $order): ?>
                    <div class="order-row">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <strong><?php echo htmlspecialchars($order['production_order_number']); ?></strong>
                                <br>
                                <small class="text-muted">
                                    Quantity: <?php echo number_format($order['quantity_to_produce']); ?> |
                                    Status: <span class="badge bg-<?php
                                        switch ($order['status']) {
                                            case 'completed': echo 'success'; break;
                                            case 'in_progress': echo 'primary'; break;
                                            case 'planned': echo 'secondary'; break;
                                            case 'cancelled': echo 'danger'; break;
                                            default: echo 'warning';
                                        }
                                    ?>"><?php echo ucfirst(str_replace('_', ' ', $order['status'])); ?></span>
                                    | Created: <?php echo date('M j, Y', strtotime($order['created_at'])); ?>
                                </small>
                            </div>
                            <div class="text-end">
                                <div class="fw-bold"><?php echo formatCurrency($order['total_production_cost'], $settings); ?></div>
                                <small class="text-muted">Total Cost</small>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Action Buttons -->
            <div class="d-flex justify-content-between align-items-center">
                <a href="index.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-2"></i>Back to BOM List
                </a>

                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-primary" onclick="window.print()">
                        <i class="bi bi-printer me-2"></i>Print BOM
                    </button>
                    <a href="reports.php?bom_id=<?php echo $bom['id']; ?>" class="btn btn-outline-info">
                        <i class="bi bi-graph-up me-2"></i>View Reports
                    </a>
                </div>
            </div>
        </main>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Delete BOM</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete BOM <strong id="deleteBOMNumber"></strong>?</p>
                    <p class="text-danger mb-0">This action cannot be undone. All associated components and production orders will also be deleted.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmDelete">Delete BOM</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let deleteBOMId = null;

        function deleteBOM(id, bomNumber) {
            deleteBOMId = id;
            document.getElementById('deleteBOMNumber').textContent = bomNumber;
            new bootstrap.Modal(document.getElementById('deleteModal')).show();
        }

        document.getElementById('confirmDelete').addEventListener('click', function() {
            if (deleteBOMId) {
                window.location.href = `delete.php?id=${deleteBOMId}`;
            }
        });

        function approveBOM(id) {
            if (confirm('Are you sure you want to approve this BOM?')) {
                window.location.href = `approve.php?id=${id}`;
            }
        }
    </script>
</body>
</html>
