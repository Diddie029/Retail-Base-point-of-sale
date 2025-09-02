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
$can_view_boms = hasPermission('view_boms', $permissions);
$can_approve_boms = hasPermission('approve_boms', $permissions);

if (!$can_manage_boms && !$can_view_boms) {
    header("Location: ../inventory/inventory.php");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Handle filters and search
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$sort_by = $_GET['sort'] ?? 'created_at';
$sort_order = $_GET['order'] ?? 'DESC';

// Build query
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(bh.name LIKE :search OR bh.bom_number LIKE :search OR p.name LIKE :search OR p.sku LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if (!empty($status_filter)) {
    $where_conditions[] = "bh.status = :status";
    $params[':status'] = $status_filter;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

$order_clause = "ORDER BY bh.{$sort_by} {$sort_order}";

// Get BOMs with pagination
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

$count_sql = "
    SELECT COUNT(*) as total
    FROM bom_headers bh
    INNER JOIN products p ON bh.product_id = p.id
    {$where_clause}
";

$stmt = $conn->prepare($count_sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$total_records = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_records / $per_page);

$sql = "
    SELECT
        bh.*,
        p.name as product_name,
        p.sku as product_sku,
        p.price as product_price,
        p.quantity as product_stock,
        u1.username as created_by_name,
        u2.username as approved_by_name,
        COUNT(bc.id) as component_count
    FROM bom_headers bh
    INNER JOIN products p ON bh.product_id = p.id
    LEFT JOIN users u1 ON bh.created_by = u1.id
    LEFT JOIN users u2 ON bh.approved_by = u2.id
    LEFT JOIN bom_components bc ON bh.id = bc.bom_id
    {$where_clause}
    GROUP BY bh.id
    {$order_clause}
    LIMIT :limit OFFSET :offset
";

$stmt = $conn->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$boms = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get BOM statistics
$bom_stats = getBOMStatistics($conn);

// Get Auto BOM statistics
$auto_bom_stats = [];
try {
    $stmt = $conn->query("SELECT COUNT(*) as count FROM products WHERE is_auto_bom_enabled = 1");
    $auto_bom_stats['auto_bom_products'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    $stmt = $conn->query("SELECT COUNT(*) as count FROM auto_bom_configs WHERE is_active = 1");
    $auto_bom_stats['active_auto_boms'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    $stmt = $conn->query("SELECT COUNT(*) as count FROM auto_bom_selling_units WHERE status = 'active'");
    $auto_bom_stats['selling_units'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    $stmt = $conn->query("SELECT COUNT(DISTINCT abc.product_family_id) as count FROM auto_bom_configs abc WHERE abc.product_family_id IS NOT NULL");
    $auto_bom_stats['product_families'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
} catch (Exception $e) {
    $auto_bom_stats = [
        'auto_bom_products' => 0,
        'active_auto_boms' => 0,
        'selling_units' => 0,
        'product_families' => 0
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BOM Management - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/inventory.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }

        .bom-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border: 1px solid #e2e8f0;
            transition: all 0.3s ease;
        }

        .bom-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .bom-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .bom-number {
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

        .status-draft { background: #fef3c7; color: #d97706; }
        .status-active { background: #d1fae5; color: #059669; }
        .status-obsolete { background: #fee2e2; color: #dc2626; }
        .status-archived { background: #f3f4f6; color: #374151; }

        .product-info {
            margin-bottom: 1rem;
        }

        .product-name {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.25rem;
        }

        .product-sku {
            color: #64748b;
            font-size: 0.875rem;
        }

        .bom-metrics {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .metric-item {
            text-align: center;
        }

        .metric-value {
            font-size: 1.25rem;
            font-weight: 700;
            color: #1e293b;
            display: block;
        }

        .metric-label {
            font-size: 0.75rem;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .bom-actions {
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
        }

        .action-btn {
            padding: 0.375rem 0.75rem;
            border-radius: 6px;
            font-size: 0.875rem;
            font-weight: 500;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-view { background: #e0e7ff; color: #3730a3; }
        .btn-view:hover { background: #c7d2fe; }

        .btn-edit { background: #dbeafe; color: #1d4ed8; }
        .btn-edit:hover { background: #bfdbfe; }

        .btn-approve { background: #d1fae5; color: #047857; }
        .btn-approve:hover { background: #a7f3d0; }

        .btn-delete { background: #fee2e2; color: #dc2626; }
        .btn-delete:hover { background: #fecaca; }

        .filters-section {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border: 1px solid #e2e8f0;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border: 1px solid #e2e8f0;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            margin-bottom: 1rem;
        }

        .stat-primary { background: #dbeafe; color: #2563eb; }
        .stat-success { background: #d1fae5; color: #059669; }
        .stat-warning { background: #fef3c7; color: #d97706; }
        .stat-info { background: #dbeafe; color: #2563eb; }

        .pagination {
            justify-content: center;
            margin-top: 2rem;
        }

        @media (max-width: 768px) {
            .bom-card {
                padding: 1rem;
            }

            .bom-metrics {
                grid-template-columns: repeat(2, 1fr);
                gap: 0.75rem;
            }

            .bom-actions {
                flex-direction: column;
                align-items: stretch;
            }

            .action-btn {
                text-align: center;
                padding: 0.5rem;
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
                    <h1>Bill of Materials (BOM)</h1>
                    <p class="header-subtitle">Manage product recipes and manufacturing specifications</p>
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
                    case 'bom_deleted':
                        echo "BOM deleted successfully!";
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
                <?php
                switch ($_GET['error']) {
                    case 'bom_not_found':
                        echo "BOM not found.";
                        break;
                    case 'permission_denied':
                        echo "You don't have permission to perform this action.";
                        break;
                    case 'db_error':
                        echo "Database error occurred. Please try again.";
                        break;
                    default:
                        echo "An error occurred: " . htmlspecialchars($_GET['error']);
                }
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Multi-Level BOM Info -->
            <div class="alert alert-info alert-dismissible fade show mb-4" role="alert">
                <i class="bi bi-diagram-3 me-2"></i>
                <strong>Multi-Level BOM Support:</strong> Your system supports complex manufacturing hierarchies!
                Components can have their own BOMs (like Flour made from Wheat), and costs automatically roll up through all levels.
                <a href="demo_multilevel.php" class="alert-link">Learn more about multi-level BOMs</a>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon stat-primary">
                        <i class="bi bi-file-earmark-text"></i>
                    </div>
                    <div class="stat-value"><?php echo number_format($bom_stats['total_active_boms']); ?></div>
                    <div class="stat-label">Active BOMs</div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon stat-warning">
                        <i class="bi bi-pencil-square"></i>
                    </div>
                    <div class="stat-value"><?php echo number_format($bom_stats['draft_boms']); ?></div>
                    <div class="stat-label">Draft BOMs</div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon stat-success">
                        <i class="bi bi-gear"></i>
                    </div>
                    <div class="stat-value"><?php echo number_format($bom_stats['active_production_orders']); ?></div>
                    <div class="stat-label">Active Production</div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon stat-info">
                        <i class="bi bi-check-circle"></i>
                    </div>
                    <div class="stat-value"><?php echo number_format($bom_stats['completed_this_month']); ?></div>
                    <div class="stat-label">Completed This Month</div>
                </div>

                <!-- Auto BOM Statistics -->
                <div class="stat-card">
                    <div class="stat-icon stat-success">
                        <i class="bi bi-gear-fill"></i>
                    </div>
                    <div class="stat-value"><?php echo number_format($auto_bom_stats['auto_bom_products']); ?></div>
                    <div class="stat-label">Auto BOM Products</div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon stat-primary">
                        <i class="bi bi-layers"></i>
                    </div>
                    <div class="stat-value"><?php echo number_format($auto_bom_stats['active_auto_boms']); ?></div>
                    <div class="stat-label">Active Auto BOMs</div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon stat-warning">
                        <i class="bi bi-cart"></i>
                    </div>
                    <div class="stat-value"><?php echo number_format($auto_bom_stats['selling_units']); ?></div>
                    <div class="stat-label">Selling Units</div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon stat-info">
                        <i class="bi bi-tags"></i>
                    </div>
                    <div class="stat-value"><?php echo number_format($auto_bom_stats['product_families']); ?></div>
                    <div class="stat-label">Product Families</div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="section-title mb-0">BOM Management</h3>
                    <p class="text-muted mb-0">Traditional BOMs and Auto BOM configurations</p>
                </div>
                <div class="d-flex gap-2">
                    <?php if ($can_manage_boms): ?>
                    <a href="add.php" class="btn btn-primary">
                        <i class="bi bi-plus-circle me-2"></i>Create BOM
                    </a>
                    <a href="auto_bom_setup.php" class="btn btn-outline-success">
                        <i class="bi bi-gear-fill me-2"></i>Auto BOM Setup
                    </a>
                    <a href="../product_families/families.php" class="btn btn-outline-warning">
                        <i class="bi bi-diagram-3 me-2"></i>BOM Families
                    </a>
                    <?php endif; ?>
                    <a href="auto_bom_products.php" class="btn btn-outline-info">
                        <i class="bi bi-list-ul me-2"></i>Auto BOM Products
                    </a>
                    <a href="demo_multilevel.php" class="btn btn-outline-secondary">
                        <i class="bi bi-diagram-3 me-2"></i>Multi-Level Demo
                    </a>
                    <a href="reports.php" class="btn btn-outline-primary">
                        <i class="bi bi-graph-up me-2"></i>Reports
                    </a>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-section">
                <form method="GET" class="row g-3">
                    <div class="col-md-4">
                        <label for="search" class="form-label">Search</label>
                        <input type="text" class="form-control" id="search" name="search"
                               value="<?php echo htmlspecialchars($search); ?>"
                               placeholder="BOM name, number, or product...">
                    </div>
                    <div class="col-md-3">
                        <label for="status" class="form-label">Status</label>
                        <select class="form-select" id="status" name="status">
                            <option value="">All Statuses</option>
                            <option value="draft" <?php echo $status_filter === 'draft' ? 'selected' : ''; ?>>Draft</option>
                            <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="obsolete" <?php echo $status_filter === 'obsolete' ? 'selected' : ''; ?>>Obsolete</option>
                            <option value="archived" <?php echo $status_filter === 'archived' ? 'selected' : ''; ?>>Archived</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="sort" class="form-label">Sort By</label>
                        <select class="form-select" id="sort" name="sort">
                            <option value="created_at" <?php echo $sort_by === 'created_at' ? 'selected' : ''; ?>>Created Date</option>
                            <option value="name" <?php echo $sort_by === 'name' ? 'selected' : ''; ?>>Name</option>
                            <option value="bom_number" <?php echo $sort_by === 'bom_number' ? 'selected' : ''; ?>>BOM Number</option>
                            <option value="total_cost" <?php echo $sort_by === 'total_cost' ? 'selected' : ''; ?>>Total Cost</option>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-outline-primary me-2">
                            <i class="bi bi-search me-1"></i>Filter
                        </button>
                        <a href="index.php" class="btn btn-outline-secondary">
                            <i class="bi bi-x-circle me-1"></i>Clear
                        </a>
                    </div>
                </form>
            </div>

            <!-- Auto BOM Overview (if any exist) -->
            <?php if ($auto_bom_stats['auto_bom_products'] > 0): ?>
                <div class="alert alert-info alert-dismissible fade show mb-4" role="alert">
                    <i class="bi bi-gear-fill me-2"></i>
                    <strong>Auto BOM Overview:</strong> You have <?php echo $auto_bom_stats['auto_bom_products']; ?> products with Auto BOM configurations
                    (<?php echo $auto_bom_stats['selling_units']; ?> selling units across <?php echo $auto_bom_stats['active_auto_boms']; ?> active configurations).
                    <a href="auto_bom_products.php" class="alert-link">View Auto BOM Products</a>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- BOMs Grid -->
            <div class="row">
                <?php if (empty($boms)): ?>
                <div class="col-12">
                    <div class="text-center py-5">
                        <i class="bi bi-file-earmark-x display-1 text-muted mb-3"></i>
                        <h4 class="text-muted">No BOMs found</h4>
                        <p class="text-muted mb-4">Get started by creating your first Bill of Materials</p>
                        <?php if ($can_manage_boms): ?>
                        <a href="add.php" class="btn btn-primary">
                            <i class="bi bi-plus-circle me-2"></i>Create Your First BOM
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php else: ?>
                <?php foreach ($boms as $bom): ?>
                <div class="col-lg-6 col-xl-4 mb-4">
                    <div class="bom-card">
                        <div class="bom-header">
                            <div class="bom-number"><?php echo htmlspecialchars($bom['bom_number']); ?></div>
                            <span class="status-badge status-<?php echo $bom['status']; ?>">
                                <?php echo ucfirst($bom['status']); ?>
                            </span>
                        </div>

                        <div class="product-info">
                            <div class="product-name"><?php echo htmlspecialchars($bom['product_name']); ?></div>
                            <div class="product-sku"><?php echo htmlspecialchars($bom['product_sku']); ?></div>
                        </div>

                        <div class="bom-metrics">
                            <div class="metric-item">
                                <span class="metric-value"><?php echo number_format($bom['component_count']); ?></span>
                                <span class="metric-label">Components</span>
                            </div>
                            <div class="metric-item">
                                <span class="metric-value"><?php echo formatCurrency($bom['total_cost'], $settings); ?></span>
                                <span class="metric-label">Total Cost</span>
                            </div>
                            <div class="metric-item">
                                <span class="metric-value"><?php echo $bom['total_quantity']; ?></span>
                                <span class="metric-label">Batch Size</span>
                            </div>
                            <div class="metric-item">
                                <span class="metric-value"><?php echo htmlspecialchars($bom['version']); ?></span>
                                <span class="metric-label">Version</span>
                            </div>
                        </div>

                        <div class="bom-meta mb-3">
                            <small class="text-muted">
                                <i class="bi bi-calendar me-1"></i>
                                Created <?php echo date('M j, Y', strtotime($bom['created_at'])); ?>
                                by <?php echo htmlspecialchars($bom['created_by_name'] ?? 'Unknown'); ?>
                            </small>
                        </div>

                        <div class="bom-actions">
                            <a href="view.php?id=<?php echo $bom['id']; ?>" class="action-btn btn-view">
                                <i class="bi bi-eye me-1"></i>View
                            </a>

                            <?php if ($can_manage_boms): ?>
                            <a href="edit.php?id=<?php echo $bom['id']; ?>" class="action-btn btn-edit">
                                <i class="bi bi-pencil me-1"></i>Edit
                            </a>

                            <?php if ($can_approve_boms && $bom['status'] === 'draft'): ?>
                            <button type="button" class="action-btn btn-approve" onclick="approveBOM(<?php echo $bom['id']; ?>)">
                                <i class="bi bi-check-circle me-1"></i>Approve
                            </button>
                            <?php endif; ?>

                            <button type="button" class="action-btn btn-delete" onclick="deleteBOM(<?php echo $bom['id']; ?>, '<?php echo htmlspecialchars($bom['bom_number']); ?>')">
                                <i class="bi bi-trash me-1"></i>Delete
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <nav aria-label="BOM pagination">
                <ul class="pagination">
                    <?php if ($page > 1): ?>
                    <li class="page-item">
                        <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&sort=<?php echo urlencode($sort_by); ?>&order=<?php echo urlencode($sort_order); ?>">
                            <i class="bi bi-chevron-left"></i>
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&sort=<?php echo urlencode($sort_by); ?>&order=<?php echo urlencode($sort_order); ?>">
                            <?php echo $i; ?>
                        </a>
                    </li>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                    <li class="page-item">
                        <a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&sort=<?php echo urlencode($sort_by); ?>&order=<?php echo urlencode($sort_order); ?>">
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </nav>
            <?php endif; ?>
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
