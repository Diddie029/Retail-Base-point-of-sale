<?php
session_start();
require_once '../include/db.php';
require_once '../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

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

// Check if user has permission to view reports
$hasAccess = isAdmin($role_name) ||
             hasPermission('view_analytics', $permissions) ||
             hasPermission('manage_sales', $permissions) ||
             hasPermission('manage_users', $permissions) ||
             hasPermission('view_finance', $permissions);

if (!$hasAccess) {
    header('Location: ../dashboard/dashboard.php');
    exit();
}

// Get system settings for navmenu display
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Handle filters
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = isset($_GET['per_page']) ? min(100, max(10, intval($_GET['per_page']))) : 25;
$offset = ($page - 1) * $perPage;

// Build where clause
$where = [];
$params = [];

// Membership level filter
$membership_level = isset($_GET['membership_level']) ? $_GET['membership_level'] : '';
if (!empty($membership_level)) {
    $where[] = "c.membership_level = :membership_level";
    $params[':membership_level'] = $membership_level;
}

// Minimum points filter
$min_points = isset($_GET['min_points']) ? intval($_GET['min_points']) : 0;
if ($min_points > 0) {
    $where[] = "COALESCE(lp.points_balance, 0) >= :min_points";
    $params[':min_points'] = $min_points;
}

// Sort by filter
$sort_by = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'points_balance';
$sort_order = isset($_GET['sort_order']) && $_GET['sort_order'] === 'asc' ? 'ASC' : 'DESC';

$valid_sort_fields = ['points_balance', 'total_earned', 'total_redeemed', 'last_transaction', 'join_date'];
if (!in_array($sort_by, $valid_sort_fields)) {
    $sort_by = 'points_balance';
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Get customer rankings with loyalty data
$sql = "
    SELECT
        c.id,
        c.first_name,
        c.last_name,
        c.phone,
        c.email,
        c.membership_level,
        c.created_at as join_date,
        COALESCE(lp.points_balance, 0) as points_balance,
        COALESCE(lp.total_earned, 0) as total_earned,
        COALESCE(lp.total_redeemed, 0) as total_redeemed,
        COALESCE(lp.last_transaction, c.created_at) as last_transaction,
        COALESCE(lp.transaction_count, 0) as transaction_count
    FROM customers c
    LEFT JOIN (
        SELECT
            customer_id,
            MAX(points_balance) as points_balance,
            SUM(points_earned) as total_earned,
            SUM(points_redeemed) as total_redeemed,
            MAX(created_at) as last_transaction,
            COUNT(*) as transaction_count
        FROM loyalty_points
        GROUP BY customer_id
    ) lp ON c.id = lp.customer_id
    $whereClause
    ORDER BY $sort_by $sort_order
    LIMIT $perPage OFFSET $offset
";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total count for pagination
$countSql = "
    SELECT COUNT(*) as total
    FROM customers c
    LEFT JOIN (
        SELECT customer_id
        FROM loyalty_points
        GROUP BY customer_id
    ) lp ON c.id = lp.customer_id
    $whereClause
";

$stmt = $conn->prepare($countSql);
$stmt->execute($params);
$totalRecords = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = ceil($totalRecords / $perPage);

// Get membership levels for filter
$stmt = $conn->prepare("SELECT DISTINCT membership_level FROM customers WHERE membership_level IS NOT NULL ORDER BY membership_level");
$stmt->execute();
$membership_levels = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Get ranking statistics
$stats = [];

// Top 10 customers
$stmt = $conn->prepare("
    SELECT COUNT(*) as count
    FROM customers c
    LEFT JOIN loyalty_points lp ON c.id = lp.customer_id
    WHERE COALESCE(lp.points_balance, 0) > 0
");
$stmt->execute();
$stats['total_loyalty_customers'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Average points per customer
$stmt = $conn->prepare("
    SELECT AVG(points_balance) as average
    FROM loyalty_points
    WHERE points_balance > 0
");
$stmt->execute();
$stats['avg_points_per_customer'] = $stmt->fetch(PDO::FETCH_ASSOC)['average'] ?? 0;

// Total points in system
$stmt = $conn->prepare("SELECT SUM(points_balance) as total FROM loyalty_points WHERE points_balance > 0");
$stmt->execute();
$stats['total_points_system'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// Most active customer (by transaction count)
$stmt = $conn->prepare("
    SELECT
        c.id,
        c.first_name,
        c.last_name,
        COUNT(lp.id) as transaction_count
    FROM customers c
    LEFT JOIN loyalty_points lp ON c.id = lp.customer_id
    GROUP BY c.id, c.first_name, c.last_name
    ORDER BY transaction_count DESC
    LIMIT 1
");
$stmt->execute();
$most_active = $stmt->fetch(PDO::FETCH_ASSOC);
$stats['most_active_customer'] = $most_active ? $most_active['first_name'] . ' ' . $most_active['last_name'] : 'N/A';
$stats['most_active_count'] = $most_active ? $most_active['transaction_count'] : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Loyalty Rankings - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }

        .ranking-card {
            border: none;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border-radius: 12px;
            margin-bottom: 20px;
        }

        .rank-badge {
            position: absolute;
            top: -10px;
            left: -10px;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 14px;
        }

        .rank-1 { background: linear-gradient(135deg, #FFD700, #FFA500); color: #000; }
        .rank-2 { background: linear-gradient(135deg, #C0C0C0, #A8A8A8); color: #000; }
        .rank-3 { background: linear-gradient(135deg, #CD7F32, #A0522D); color: #fff; }
        .rank-other { background: var(--primary-color); color: white; }

        .customer-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            margin-right: 15px;
        }

        .filter-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 1.5rem;
        }

        .points-display {
            font-size: 1.2rem;
            font-weight: 600;
        }

        .membership-badge {
            font-size: 0.75rem;
        }
    </style>
</head>
<body>
    <?php include '../include/navmenu.php'; ?>

    <div class="main-content">
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1><i class="bi bi-trophy"></i> Customer Loyalty Rankings</h1>
                    <p class="header-subtitle">Top customers ranked by loyalty points and engagement</p>
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

        <main class="content">
            <div class="container-fluid">
                <!-- Statistics Overview -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="stats-card">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Total Loyalty Customers</h6>
                                <i class="bi bi-people fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo number_format($stats['total_loyalty_customers']); ?></h3>
                            <small class="opacity-75">Active participants</small>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="stats-card">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Total Points in System</h6>
                                <i class="bi bi-gift fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo number_format($stats['total_points_system']); ?></h3>
                            <small class="opacity-75">Available to redeem</small>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="stats-card">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Avg Points per Customer</h6>
                                <i class="bi bi-graph-up-arrow fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo number_format($stats['avg_points_per_customer'], 0); ?></h3>
                            <small class="opacity-75">Average balance</small>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="stats-card">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Most Active Customer</h6>
                                <i class="bi bi-star fs-4"></i>
                            </div>
                            <h6 class="mb-0"><?php echo htmlspecialchars($stats['most_active_customer'] ?? 'N/A'); ?></h6>
                            <small class="opacity-75"><?php echo $stats['most_active_count']; ?> transactions</small>
                        </div>
                    </div>
                </div>

                <!-- Filters Section -->
                <div class="filter-section">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label for="membership_level" class="form-label">Membership Level</label>
                            <select class="form-select" id="membership_level" name="membership_level">
                                <option value="">All Levels</option>
                                <?php foreach ($membership_levels as $level): ?>
                                    <option value="<?php echo $level; ?>" <?php echo $membership_level === $level ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($level); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="min_points" class="form-label">Minimum Points</label>
                            <input type="number" class="form-control" id="min_points" name="min_points"
                                   value="<?php echo $min_points; ?>" min="0">
                        </div>
                        <div class="col-md-2">
                            <label for="sort_by" class="form-label">Sort By</label>
                            <select class="form-select" id="sort_by" name="sort_by">
                                <option value="points_balance" <?php echo $sort_by === 'points_balance' ? 'selected' : ''; ?>>Points Balance</option>
                                <option value="total_earned" <?php echo $sort_by === 'total_earned' ? 'selected' : ''; ?>>Total Earned</option>
                                <option value="total_redeemed" <?php echo $sort_by === 'total_redeemed' ? 'selected' : ''; ?>>Total Redeemed</option>
                                <option value="last_transaction" <?php echo $sort_by === 'last_transaction' ? 'selected' : ''; ?>>Last Transaction</option>
                                <option value="join_date" <?php echo $sort_by === 'join_date' ? 'selected' : ''; ?>>Join Date</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="sort_order" class="form-label">Order</label>
                            <select class="form-select" id="sort_order" name="sort_order">
                                <option value="desc" <?php echo $sort_order === 'DESC' ? 'selected' : ''; ?>>Descending</option>
                                <option value="asc" <?php echo $sort_order === 'ASC' ? 'selected' : ''; ?>>Ascending</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">&nbsp;</label>
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-search"></i> Apply Filters
                                </button>
                            </div>
                        </div>
                        <div class="col-12">
                            <a href="loyalty_customer_ranking.php" class="btn btn-secondary">
                                <i class="bi bi-x-circle"></i> Clear Filters
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Results Summary -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h5 class="mb-1">Customer Rankings</h5>
                                        <p class="text-muted mb-0">
                                            Showing <?php echo count($customers); ?> of <?php echo $totalRecords; ?> customers
                                            (sorted by <?php echo str_replace('_', ' ', $sort_by); ?>)
                                        </p>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <select class="form-select form-select-sm" id="perPage" style="width: auto;">
                                            <option value="10" <?php echo $perPage == 10 ? 'selected' : ''; ?>>10 per page</option>
                                            <option value="25" <?php echo $perPage == 25 ? 'selected' : ''; ?>>25 per page</option>
                                            <option value="50" <?php echo $perPage == 50 ? 'selected' : ''; ?>>50 per page</option>
                                            <option value="100" <?php echo $perPage == 100 ? 'selected' : ''; ?>>100 per page</option>
                                        </select>
                                        <button class="btn btn-outline-primary btn-sm" onclick="exportToExcel()">
                                            <i class="bi bi-download"></i> Export
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Customer Rankings List -->
                <div class="row">
                    <div class="col-12">
                        <?php if (!empty($customers)): ?>
                            <?php $rank = $offset + 1; ?>
                            <?php foreach ($customers as $customer): ?>
                                <div class="ranking-card card position-relative">
                                    <div class="rank-badge rank-<?php echo $rank <= 3 ? $rank : 'other'; ?>">
                                        <?php echo $rank; ?>
                                    </div>
                                    <div class="card-body">
                                        <div class="row align-items-center">
                                            <div class="col-md-1">
                                                <div class="customer-avatar">
                                                    <?php echo strtoupper(substr($customer['first_name'], 0, 1) . substr($customer['last_name'], 0, 1)); ?>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <h6 class="mb-1">
                                                    <?php echo htmlspecialchars(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '')); ?>
                                                </h6>
                                                <small class="text-muted d-block"><?php echo htmlspecialchars($customer['phone'] ?? ''); ?></small>
                                                <?php if (!empty($customer['email'])): ?>
                                                    <small class="text-muted"><?php echo htmlspecialchars($customer['email'] ?? ''); ?></small>
                                                <?php endif; ?>
                                            </div>
                                            <div class="col-md-2">
                                                <span class="badge bg-primary membership-badge">
                                                    <?php echo htmlspecialchars($customer['membership_level'] ?? 'Basic'); ?>
                                                </span>
                                                <br>
                                                <small class="text-muted">Joined: <?php echo date('M d, Y', strtotime($customer['join_date'])); ?></small>
                                            </div>
                                            <div class="col-md-2 text-center">
                                                <div class="points-display text-success">
                                                    <?php echo number_format($customer['points_balance']); ?>
                                                </div>
                                                <small class="text-muted">Current Balance</small>
                                            </div>
                                            <div class="col-md-2 text-center">
                                                <div class="text-info">
                                                    <strong>+<?php echo number_format($customer['total_earned']); ?></strong>
                                                </div>
                                                <small class="text-muted">Total Earned</small>
                                                <br>
                                                <div class="text-danger">
                                                    <strong>-<?php echo number_format($customer['total_redeemed']); ?></strong>
                                                </div>
                                                <small class="text-muted">Total Redeemed</small>
                                            </div>
                                            <div class="col-md-2 text-end">
                                                <small class="text-muted d-block">
                                                    <?php echo $customer['transaction_count']; ?> transactions
                                                </small>
                                                <small class="text-muted">
                                                    Last: <?php echo $customer['last_transaction'] ? date('M d, Y', strtotime($customer['last_transaction'])) : 'Never'; ?>
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php $rank++; ?>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="card">
                                <div class="card-body text-center py-5">
                                    <i class="bi bi-inbox fs-1 text-muted mb-3"></i>
                                    <h5 class="text-muted">No customers found</h5>
                                    <p class="text-muted">Try adjusting your filters to see more results.</p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="d-flex justify-content-between align-items-center mt-4">
                        <div>
                            <small class="text-muted">
                                Page <?php echo $page; ?> of <?php echo $totalPages; ?> (<?php echo $totalRecords; ?> total)
                            </small>
                        </div>
                        <nav aria-label="Customer ranking pagination">
                            <ul class="pagination pagination-sm mb-0">
                                <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>">
                                            <i class="bi bi-chevron-double-left"></i>
                                        </a>
                                    </li>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                            <i class="bi bi-chevron-left"></i>
                                        </a>
                                    </li>
                                <?php endif; ?>

                                <?php
                                $startPage = max(1, $page - 2);
                                $endPage = min($totalPages, $page + 2);

                                for ($i = $startPage; $i <= $endPage; $i++):
                                ?>
                                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>

                                <?php if ($page < $totalPages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                            <i class="bi bi-chevron-right"></i>
                                        </a>
                                    </li>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $totalPages])); ?>">
                                            <i class="bi bi-chevron-double-right"></i>
                                        </a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>

                <!-- Action Buttons -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body text-center">
                                <h5>Explore More Loyalty Reports</h5>
                                <div class="d-flex justify-content-center gap-3 mt-3">
                                    <a href="loyalty_points_summary.php" class="btn btn-primary">
                                        <i class="bi bi-graph-up"></i> Points Summary
                                    </a>
                                    <a href="loyalty_points_transactions.php" class="btn btn-success">
                                        <i class="bi bi-list-ul"></i> Transaction History
                                    </a>
                                    <a href="index.php" class="btn btn-secondary">
                                        <i class="bi bi-arrow-left"></i> Back to Reports
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Handle per page change
        document.getElementById('perPage').addEventListener('change', function() {
            const url = new URL(window.location);
            url.searchParams.set('per_page', this.value);
            url.searchParams.set('page', '1'); // Reset to first page
            window.location.href = url.toString();
        });

        // Export to Excel function
        function exportToExcel() {
            const url = new URL(window.location);
            url.searchParams.set('export', 'excel');
            window.open(url.toString(), '_blank');
        }
    </script>
</body>
</html>
