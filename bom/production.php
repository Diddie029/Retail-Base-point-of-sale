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

// Check BOM production permissions - use granular permissions
$can_create_production = hasPermission('create_production_orders', $permissions);
$can_manage_production = hasPermission('manage_production_orders', $permissions);
$can_approve_production = hasPermission('approve_production_orders', $permissions);
$can_view_production = hasPermission('view_production_orders', $permissions);

if (!$can_create_production && !$can_manage_production && !$can_approve_production && !$can_view_production) {
    header("Location: index.php");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Handle production order creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'create_production_order') {
        try {
            $conn->beginTransaction();

            $bom_id = intval($_POST['bom_id']);
            $quantity = intval($_POST['quantity_to_produce']);

            // Get BOM details
            $stmt = $conn->prepare("SELECT * FROM bom_headers WHERE id = :bom_id AND status = 'active'");
            $stmt->bindParam(':bom_id', $bom_id, PDO::PARAM_INT);
            $stmt->execute();
            $bom = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$bom) {
                throw new Exception("BOM not found or not active");
            }

            // Generate production order number
            $production_order_number = generateProductionOrderNumber($conn);

            // Calculate costs
            $cost_data = calculateBOMCost($conn, $bom_id);
            $unit_cost = $cost_data['cost_per_unit'] ?? 0;
            $total_cost = $unit_cost * $quantity;

            // Create production order
            $stmt = $conn->prepare("
                INSERT INTO bom_production_orders (
                    production_order_number, bom_id, quantity_to_produce, status,
                    total_material_cost, total_labor_cost, total_overhead_cost, total_production_cost,
                    created_by, created_at, updated_at
                ) VALUES (
                    :production_order_number, :bom_id, :quantity_to_produce, 'planned',
                    :total_material_cost, :total_labor_cost, :total_overhead_cost, :total_production_cost,
                    :created_by, NOW(), NOW()
                )
            ");

            $stmt->execute([
                ':production_order_number' => $production_order_number,
                ':bom_id' => $bom_id,
                ':quantity_to_produce' => $quantity,
                ':total_material_cost' => $cost_data['material_cost'] * $quantity,
                ':total_labor_cost' => $cost_data['labor_cost'] * $quantity,
                ':total_overhead_cost' => $cost_data['overhead_cost'] * $quantity,
                ':total_production_cost' => $total_cost,
                ':created_by' => $user_id
            ]);

            $production_order_id = $conn->lastInsertId();

            // Create production order items
            if (!empty($cost_data['components'])) {
                $item_stmt = $conn->prepare("
                    INSERT INTO bom_production_order_items (
                        production_order_id, component_product_id, required_quantity,
                        unit_cost, total_cost, created_at, updated_at
                    ) VALUES (
                        :production_order_id, :component_product_id, :required_quantity,
                        :unit_cost, :total_cost, NOW(), NOW()
                    )
                ");

                foreach ($cost_data['components'] as $component) {
                    $required_quantity = $component['quantity_required'] * $quantity;

                    $item_stmt->execute([
                        ':production_order_id' => $production_order_id,
                        ':component_product_id' => $component['id'],
                        ':required_quantity' => $required_quantity,
                        ':unit_cost' => $component['unit_cost'],
                        ':total_cost' => $component['total_cost'] * $quantity
                    ]);
                }
            }

            $conn->commit();

            header("Location: production.php?success=production_order_created");
            exit();

        } catch (Exception $e) {
            $conn->rollBack();
            $error = $e->getMessage();
        }
    }
}

// Get production orders with pagination
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

$status_filter = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';

$where_conditions = [];
$params = [];

if (!empty($status_filter)) {
    $where_conditions[] = "po.status = :status";
    $params[':status'] = $status_filter;
}

if (!empty($search)) {
    $where_conditions[] = "(po.production_order_number LIKE :search OR bh.bom_number LIKE :search OR bh.name LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

$sql = "
    SELECT
        po.*,
        bh.bom_number,
        bh.name as bom_name,
        p.name as product_name,
        u.username as created_by_name
    FROM bom_production_orders po
    INNER JOIN bom_headers bh ON po.bom_id = bh.id
    INNER JOIN products p ON bh.product_id = p.id
    LEFT JOIN users u ON po.created_by = u.id
    {$where_clause}
    ORDER BY po.created_at DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $conn->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$production_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total count
$count_sql = "
    SELECT COUNT(*) as total
    FROM bom_production_orders po
    INNER JOIN bom_headers bh ON po.bom_id = bh.id
    {$where_clause}
";

$stmt = $conn->prepare($count_sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$total_records = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_records / $per_page);

// Get BOMs for creation dropdown
$stmt = $conn->prepare("
    SELECT bh.id, bh.bom_number, bh.name, p.name as product_name
    FROM bom_headers bh
    INNER JOIN products p ON bh.product_id = p.id
    WHERE bh.status = 'active'
    ORDER BY bh.bom_number ASC
");
$stmt->execute();
$available_boms = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Production Orders - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }

        .production-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border: 1px solid #e2e8f0;
            transition: all 0.3s ease;
        }

        .production-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .production-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .production-number {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--primary-color);
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-planned { background: #dbeafe; color: #1d4ed8; }
        .status-in_progress { background: #fef3c7; color: #d97706; }
        .status-completed { background: #d1fae5; color: #059669; }
        .status-cancelled { background: #fee2e2; color: #dc2626; }
        .status-on_hold { background: #f3f4f6; color: #374151; }

        .production-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .detail-item {
            text-align: center;
        }

        .detail-value {
            font-size: 1.25rem;
            font-weight: 700;
            color: #1e293b;
            display: block;
        }

        .detail-label {
            font-size: 0.75rem;
            color: #64748b;
            text-transform: uppercase;
        }

        .filters-section {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border: 1px solid #e2e8f0;
        }

        .create-form {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border: 1px solid #e2e8f0;
        }

        .form-section-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid var(--primary-color);
        }

        @media (max-width: 768px) {
            .production-card {
                padding: 1rem;
            }

            .production-details {
                grid-template-columns: repeat(2, 1fr);
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
                    <h1>Production Orders</h1>
                    <p class="header-subtitle">Manage manufacturing and production processes</p>
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
                    case 'production_order_created':
                        echo "Production order created successfully!";
                        break;
                    default:
                        echo "Operation completed successfully!";
                }
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Create Production Order Form -->
            <?php if ($can_manage_production && !empty($available_boms)): ?>
            <div class="create-form">
                <h3 class="form-section-title">
                    <i class="bi bi-plus-circle me-2"></i>Create Production Order
                </h3>

                <form method="POST" id="productionOrderForm">
                    <input type="hidden" name="action" value="create_production_order">

                    <div class="row">
                        <div class="col-md-6">
                            <label for="bom_id" class="form-label">Select BOM *</label>
                            <select class="form-select" id="bom_id" name="bom_id" required>
                                <option value="">Choose a BOM...</option>
                                <?php foreach ($available_boms as $bom): ?>
                                <option value="<?php echo $bom['id']; ?>">
                                    <?php echo htmlspecialchars($bom['bom_number'] . ' - ' . $bom['name'] . ' (' . $bom['product_name'] . ')'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="quantity_to_produce" class="form-label">Quantity to Produce *</label>
                            <input type="number" class="form-control" id="quantity_to_produce" name="quantity_to_produce" min="1" required>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-plus-circle me-2"></i>Create Order
                            </button>
                        </div>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <!-- Filters -->
            <div class="filters-section">
                <form method="GET" class="row g-3">
                    <div class="col-md-4">
                        <label for="search" class="form-label">Search</label>
                        <input type="text" class="form-control" id="search" name="search"
                               value="<?php echo htmlspecialchars($search); ?>"
                               placeholder="Order number, BOM name...">
                    </div>
                    <div class="col-md-3">
                        <label for="status" class="form-label">Status</label>
                        <select class="form-select" id="status" name="status">
                            <option value="">All Statuses</option>
                            <option value="planned" <?php echo $status_filter === 'planned' ? 'selected' : ''; ?>>Planned</option>
                            <option value="in_progress" <?php echo $status_filter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                            <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            <option value="on_hold" <?php echo $status_filter === 'on_hold' ? 'selected' : ''; ?>>On Hold</option>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-outline-primary me-2">
                            <i class="bi bi-search me-1"></i>Filter
                        </button>
                        <a href="production.php" class="btn btn-outline-secondary">
                            <i class="bi bi-x-circle me-1"></i>Clear
                        </a>
                    </div>
                </form>
            </div>

            <!-- Production Orders List -->
            <div class="row">
                <?php if (empty($production_orders)): ?>
                <div class="col-12">
                    <div class="text-center py-5">
                        <i class="bi bi-gear display-1 text-muted mb-3"></i>
                        <h4 class="text-muted">No Production Orders</h4>
                        <p class="text-muted">Get started by creating your first production order</p>
                        <?php if ($can_manage_production && !empty($available_boms)): ?>
                        <button type="button" class="btn btn-primary" onclick="document.getElementById('bom_id').focus()">
                            <i class="bi bi-plus-circle me-2"></i>Create Production Order
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php else: ?>
                <?php foreach ($production_orders as $order): ?>
                <div class="col-lg-6 col-xl-4 mb-4">
                    <div class="production-card">
                        <div class="production-header">
                            <div class="production-number"><?php echo htmlspecialchars($order['production_order_number']); ?></div>
                            <span class="status-badge status-<?php echo $order['status']; ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $order['status'])); ?>
                            </span>
                        </div>

                        <div class="mb-3">
                            <strong><?php echo htmlspecialchars($order['bom_name']); ?></strong><br>
                            <small class="text-muted"><?php echo htmlspecialchars($order['product_name']); ?></small>
                        </div>

                        <div class="production-details">
                            <div class="detail-item">
                                <span class="detail-value"><?php echo number_format($order['quantity_to_produce']); ?></span>
                                <span class="detail-label">Quantity</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-value"><?php echo formatCurrency($order['total_production_cost'], $settings); ?></span>
                                <span class="detail-label">Total Cost</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-value"><?php echo formatCurrency($order['total_production_cost'] / max($order['quantity_to_produce'], 1), $settings); ?></span>
                                <span class="detail-label">Cost per Unit</span>
                            </div>
                        </div>

                        <div class="text-muted small">
                            Created <?php echo date('M j, Y', strtotime($order['created_at'])); ?>
                            by <?php echo htmlspecialchars($order['created_by_name'] ?? 'Unknown'); ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <nav aria-label="Production orders pagination">
                <ul class="pagination justify-content-center">
                    <?php if ($page > 1): ?>
                    <li class="page-item">
                        <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>">
                            <i class="bi bi-chevron-left"></i>
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>">
                            <?php echo $i; ?>
                        </a>
                    </li>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                    <li class="page-item">
                        <a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>">
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </nav>
            <?php endif; ?>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
