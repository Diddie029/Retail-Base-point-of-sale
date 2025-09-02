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

// Check if user has permission to manage BOMs (which includes product families)
if (!hasPermission('manage_boms', $permissions) && !hasPermission('view_boms', $permissions)) {
    header("Location: ../dashboard/dashboard.php");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Handle search and sorting
$search = $_GET['search'] ?? '';
$sort_by = $_GET['sort'] ?? 'name';
$sort_order = $_GET['order'] ?? 'ASC';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Validate sort parameters
$allowed_sorts = ['name', 'created_at', 'updated_at', 'product_count'];
$allowed_orders = ['ASC', 'DESC'];

if (!in_array($sort_by, $allowed_sorts)) {
    $sort_by = 'name';
}
if (!in_array($sort_order, $allowed_orders)) {
    $sort_order = 'ASC';
}

// Build WHERE clause
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(pf.name LIKE :search OR pf.description LIKE :search)";
    $params[':search'] = "%$search%";
}

// Build ORDER BY clause
$order_clause = '';
if ($sort_by === 'product_count') {
    $order_clause = "ORDER BY product_count $sort_order, pf.name ASC";
} else {
    $order_clause = "ORDER BY pf.$sort_by $sort_order";
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get total count for pagination
$count_sql = "SELECT COUNT(*) as total FROM product_families pf $where_clause";
$count_stmt = $conn->prepare($count_sql);
foreach ($params as $key => $value) {
    $count_stmt->bindValue($key, $value);
}
$count_stmt->execute();
$total_families = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_families / $per_page);

// Get families with product count
$sql = "
    SELECT pf.*,
           COUNT(p.id) as product_count,
           COALESCE(SUM(p.quantity), 0) as total_inventory,
           COALESCE(SUM(p.price * p.quantity), 0) as total_value
    FROM product_families pf
    LEFT JOIN products p ON pf.id = p.product_family_id
    $where_clause
    GROUP BY pf.id, pf.name, pf.description, pf.base_unit, pf.default_pricing_strategy, pf.status, pf.created_at, pf.updated_at
    $order_clause
    LIMIT :limit OFFSET :offset
";

$stmt = $conn->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$families = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats = [];

// Total families
$stats['total_families'] = $total_families;

// Active families
$stmt = $conn->query("SELECT COUNT(*) as count FROM product_families WHERE status = 'active'");
$stats['active_families'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Families with products
$stmt = $conn->query("SELECT COUNT(*) as count FROM product_families pf WHERE EXISTS (SELECT 1 FROM products p WHERE p.product_family_id = pf.id)");
$stats['families_with_products'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Total products across all families
$stmt = $conn->query("SELECT COUNT(*) as count FROM products WHERE product_family_id IS NOT NULL");
$stats['total_products_in_families'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Handle success/error messages
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// Function to generate sort URL
function getSortUrl($column, $current_sort, $current_order, $search) {
    $new_order = ($column === $current_sort && $current_order === 'ASC') ? 'DESC' : 'ASC';
    $params = [
        'sort' => $column,
        'order' => $new_order
    ];
    if (!empty($search)) {
        $params['search'] = $search;
    }
    return '?' . http_build_query($params);
}

// Function to get sort icon
function getSortIcon($column, $current_sort, $current_order) {
    if ($column !== $current_sort) {
        return '<i class="bi bi-arrow-down-up text-muted"></i>';
    }
    return $current_order === 'ASC' ? '<i class="bi bi-arrow-up"></i>' : '<i class="bi bi-arrow-down"></i>';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product Families - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/families.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <?php
    $current_page = 'families';
    include __DIR__ . '/../include/navmenu.php';
    ?>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1>Product Families</h1>
                    <div class="header-subtitle">Manage product family organization</div>
                </div>
                <div class="header-actions">
                    <div class="user-info">
                        <div class="user-avatar"><?php echo strtoupper(substr($username, 0, 1)); ?></div>
                        <span><?php echo htmlspecialchars($username); ?></span>
                    </div>
                </div>
            </div>
        </header>

        <!-- Content -->
        <div class="content">
            <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle me-2"></i>
                <?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-circle me-2"></i>
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-primary">
                            <i class="bi bi-diagram-3"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['total_families']); ?></div>
                    <div class="stat-label">Total Families</div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-success">
                            <i class="bi bi-check-circle"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['active_families']); ?></div>
                    <div class="stat-label">Active Families</div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-info">
                            <i class="bi bi-box"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['total_products_in_families']); ?></div>
                    <div class="stat-label">Products in Families</div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-warning">
                            <i class="bi bi-graph-up"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['families_with_products']); ?></div>
                    <div class="stat-label">Families with Products</div>
                </div>
            </div>

            <!-- Family Header -->
            <div class="family-header">
                <h2 class="family-title">Family Management</h2>
                <div class="family-actions">
                    <?php if (hasPermission('manage_boms', $permissions)): ?>
                    <a href="add.php" class="btn btn-primary">
                        <i class="bi bi-plus"></i>
                        Add Family
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <form method="GET" id="filterForm">
                    <div class="filter-row">
                        <div class="form-group">
                            <label for="searchInput" class="form-label">Search Families</label>
                            <input type="text" class="form-control" id="searchInput" name="search"
                                   value="<?php echo htmlspecialchars($search); ?>" placeholder="Search by name or description...">
                        </div>
                        <div class="form-group">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-search"></i>
                                Search
                            </button>
                            <?php if (!empty($search)): ?>
                            <a href="families.php" class="btn btn-outline-secondary">
                                <i class="bi bi-x"></i>
                                Clear
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Preserve sort parameters -->
                    <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort_by); ?>">
                    <input type="hidden" name="order" value="<?php echo htmlspecialchars($sort_order); ?>">
                </form>
            </div>

            <!-- Families Table -->
            <div class="data-section">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>
                                    <a href="<?php echo getSortUrl('name', $sort_by, $sort_order, $search); ?>" class="sort-link">
                                        Family Name <?php echo getSortIcon('name', $sort_by, $sort_order); ?>
                                    </a>
                                </th>
                                <th>Description</th>
                                <th>Base Unit</th>
                                <th>Pricing Strategy</th>
                                <th>
                                    <a href="<?php echo getSortUrl('product_count', $sort_by, $sort_order, $search); ?>" class="sort-link">
                                        Products <?php echo getSortIcon('product_count', $sort_by, $sort_order); ?>
                                    </a>
                                </th>
                                <th>Total Value</th>
                                <th>
                                    <a href="<?php echo getSortUrl('created_at', $sort_by, $sort_order, $search); ?>" class="sort-link">
                                        Created <?php echo getSortIcon('created_at', $sort_by, $sort_order); ?>
                                    </a>
                                </th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($families)): ?>
                            <tr>
                                <td colspan="9" class="text-center">
                                    <div class="py-4">
                                        <i class="bi bi-diagram-3" style="font-size: 3rem; color: #9ca3af;"></i>
                                        <p class="text-muted mt-2">No product families found</p>
                                        <?php if (hasPermission('manage_boms', $permissions)): ?>
                                        <a href="add.php" class="btn btn-primary">Create Your First Family</a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($families as $family): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="family-icon me-3">
                                            <i class="bi bi-diagram-3"></i>
                                        </div>
                                        <div>
                                            <div class="font-weight-bold"><?php echo htmlspecialchars($family['name']); ?></div>
                                            <small class="text-muted">ID: <?php echo $family['id']; ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="family-description">
                                        <?php
                                        $description = $family['description'] ?? '';
                                        echo !empty($description) ? htmlspecialchars(substr($description, 0, 100)) . (strlen($description) > 100 ? '...' : '') : '<span class="text-muted">No description</span>';
                                        ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge badge-info"><?php echo htmlspecialchars($family['base_unit']); ?></span>
                                </td>
                                <td>
                                    <span class="badge badge-secondary"><?php echo ucwords(str_replace('_', ' ', $family['default_pricing_strategy'])); ?></span>
                                </td>
                                <td>
                                    <div class="product-count">
                                        <?php echo number_format($family['product_count']); ?>
                                        <?php if ($family['product_count'] > 0): ?>
                                            <span class="badge badge-success ms-1"><?php echo number_format($family['total_inventory']); ?> items</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="currency">
                                    <?php echo $settings['currency_symbol']; ?> <?php echo number_format($family['total_value'], 2); ?>
                                </td>
                                <td>
                                    <small><?php echo date('M j, Y g:i A', strtotime($family['created_at'])); ?></small>
                                </td>
                                <td>
                                    <span class="badge <?php echo $family['status'] === 'active' ? 'badge-success' : 'badge-secondary'; ?>">
                                        <?php echo ucfirst($family['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <a href="view.php?id=<?php echo $family['id']; ?>" class="btn btn-outline-secondary btn-sm" title="View">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <?php if (hasPermission('manage_boms', $permissions)): ?>
                                        <a href="edit.php?id=<?php echo $family['id']; ?>" class="btn btn-warning btn-sm" title="Edit">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <button type="button" class="btn btn-sm <?php echo $family['status'] === 'active' ? 'btn-warning' : 'btn-success'; ?> toggle-status"
                                                data-id="<?php echo $family['id']; ?>"
                                                data-current-status="<?php echo $family['status']; ?>"
                                                title="<?php echo $family['status'] === 'active' ? 'Deactivate Family' : 'Activate Family'; ?>">
                                            <i class="bi <?php echo $family['status'] === 'active' ? 'bi-pause-fill' : 'bi-play-fill'; ?>"></i>
                                        </button>
                                        <a href="delete.php?id=<?php echo $family['id']; ?>"
                                           class="btn btn-danger btn-sm btn-delete"
                                           data-family-name="<?php echo htmlspecialchars($family['name']); ?>"
                                           data-product-count="<?php echo $family['product_count']; ?>"
                                           title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <div class="pagination-wrapper">
                        <div class="pagination-info">
                            Showing <?php echo number_format($offset + 1); ?> to <?php echo number_format(min($offset + $per_page, $total_families)); ?>
                            of <?php echo number_format($total_families); ?> families
                        </div>
                        <nav aria-label="Family pagination">
                            <ul class="pagination">
                                <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                        <i class="bi bi-chevron-left"></i>
                                    </a>
                                </li>
                                <?php endif; ?>

                                <?php
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $page + 2);

                                for ($i = $start_page; $i <= $end_page; $i++):
                                ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                                <?php endfor; ?>

                                <?php if ($page < $total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                        <i class="bi bi-chevron-right"></i>
                                    </a>
                                </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/families.js"></script>
</body>
</html>
