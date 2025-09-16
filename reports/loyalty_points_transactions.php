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
$filters = [];
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = isset($_GET['per_page']) ? min(100, max(10, intval($_GET['per_page']))) : 25;
$offset = ($page - 1) * $perPage;

// Build where clause
$where = [];
$params = [];

// Date range filter
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';

if (!empty($start_date)) {
    $where[] = "lp.created_at >= :start_date";
    $params[':start_date'] = $start_date . ' 00:00:00';
}

if (!empty($end_date)) {
    $where[] = "lp.created_at <= :end_date";
    $params[':end_date'] = $end_date . ' 23:59:59';
}

// Transaction type filter
$transaction_type = isset($_GET['transaction_type']) ? $_GET['transaction_type'] : '';
if (!empty($transaction_type)) {
    $where[] = "lp.transaction_type = :transaction_type";
    $params[':transaction_type'] = $transaction_type;
}

// Customer filter
$customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;
if ($customer_id > 0) {
    $where[] = "lp.customer_id = :customer_id";
    $params[':customer_id'] = $customer_id;
}

// Source filter
$source = isset($_GET['source']) ? $_GET['source'] : '';
if (!empty($source)) {
    $where[] = "lp.source = :source";
    $params[':source'] = $source;
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Get total count for pagination
$countSql = "
    SELECT COUNT(*) as total
    FROM loyalty_points lp
    LEFT JOIN customers c ON lp.customer_id = c.id
    $whereClause
";

$stmt = $conn->prepare($countSql);
$stmt->execute($params);
$totalRecords = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = ceil($totalRecords / $perPage);

// Get loyalty transactions
$sql = "
    SELECT
        lp.*,
        c.first_name,
        c.last_name,
        c.phone,
        c.membership_level,
        u.username as approved_by_name
    FROM loyalty_points lp
    LEFT JOIN customers c ON lp.customer_id = c.id
    LEFT JOIN users u ON lp.approved_by = u.id
    $whereClause
    ORDER BY lp.created_at DESC
    LIMIT $perPage OFFSET $offset
";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get customers for filter dropdown
$stmt = $conn->prepare("
    SELECT id, first_name, last_name, phone
    FROM customers
    WHERE reward_program_active = 1
    ORDER BY first_name, last_name
");
$stmt->execute();
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get transaction types for filter
$transaction_types = ['earned', 'redeemed', 'expired', 'adjusted'];

// Get sources for filter
$sources = ['purchase', 'manual', 'welcome', 'bonus', 'adjustment'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loyalty Points Transactions - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }

        .transaction-card {
            border: none;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
            margin-bottom: 15px;
        }

        .transaction-type-earned {
            border-left: 4px solid #28a745;
        }

        .transaction-type-redeemed {
            border-left: 4px solid #dc3545;
        }

        .transaction-type-expired {
            border-left: 4px solid #ffc107;
        }

        .transaction-type-adjusted {
            border-left: 4px solid #17a2b8;
        }

        .filter-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .pagination-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 2rem;
        }

        .points-earned {
            color: #28a745;
            font-weight: 600;
        }

        .points-redeemed {
            color: #dc3545;
            font-weight: 600;
        }

        .points-adjusted {
            color: #17a2b8;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <?php include '../include/navmenu.php'; ?>

    <div class="main-content">
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1><i class="bi bi-list-ul"></i> Loyalty Points Transactions</h1>
                    <p class="header-subtitle">Detailed history of all loyalty points transactions</p>
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
                <!-- Filters Section -->
                <div class="filter-section">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label for="start_date" class="form-label">Start Date</label>
                            <input type="date" class="form-control" id="start_date" name="start_date"
                                   value="<?php echo htmlspecialchars($start_date); ?>">
                        </div>
                        <div class="col-md-3">
                            <label for="end_date" class="form-label">End Date</label>
                            <input type="date" class="form-control" id="end_date" name="end_date"
                                   value="<?php echo htmlspecialchars($end_date); ?>">
                        </div>
                        <div class="col-md-2">
                            <label for="transaction_type" class="form-label">Transaction Type</label>
                            <select class="form-select" id="transaction_type" name="transaction_type">
                                <option value="">All Types</option>
                                <?php foreach ($transaction_types as $type): ?>
                                    <option value="<?php echo $type; ?>" <?php echo $transaction_type === $type ? 'selected' : ''; ?>>
                                        <?php echo ucfirst($type); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="source" class="form-label">Source</label>
                            <select class="form-select" id="source" name="source">
                                <option value="">All Sources</option>
                                <?php foreach ($sources as $src): ?>
                                    <option value="<?php echo $src; ?>" <?php echo $source === $src ? 'selected' : ''; ?>>
                                        <?php echo ucfirst($src); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="customer_id" class="form-label">Customer</label>
                            <select class="form-select" id="customer_id" name="customer_id">
                                <option value="">All Customers</option>
                                <?php foreach ($customers as $cust): ?>
                                    <option value="<?php echo $cust['id']; ?>" <?php echo $customer_id == $cust['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(($cust['first_name'] ?? '') . ' ' . ($cust['last_name'] ?? '') . ' (' . ($cust['phone'] ?? '') . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary me-2">
                                <i class="bi bi-search"></i> Apply Filters
                            </button>
                            <a href="loyalty_points_transactions.php" class="btn btn-secondary">
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
                                        <h5 class="mb-1">Transaction Results</h5>
                                        <p class="text-muted mb-0">
                                            Showing <?php echo count($transactions); ?> of <?php echo $totalRecords; ?> transactions
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

                <!-- Transactions List -->
                <div class="row">
                    <div class="col-12">
                        <?php if (!empty($transactions)): ?>
                            <?php foreach ($transactions as $transaction): ?>
                                <div class="transaction-card transaction-type-<?php echo $transaction['transaction_type']; ?>">
                                    <div class="card-body">
                                        <div class="row align-items-center">
                                            <div class="col-md-3">
                                                <h6 class="mb-1">
                                                    <?php echo htmlspecialchars(($transaction['first_name'] ?? '') . ' ' . ($transaction['last_name'] ?? '')); ?>
                                                </h6>
                                                <small class="text-muted">
                                                    <?php echo htmlspecialchars($transaction['phone'] ?? ''); ?> |
                                                    <?php echo htmlspecialchars($transaction['membership_level'] ?? 'Basic'); ?>
                                                </small>
                                            </div>
                                            <div class="col-md-2">
                                                <span class="badge bg-<?php
                                                    echo $transaction['transaction_type'] === 'earned' ? 'success' :
                                                         ($transaction['transaction_type'] === 'redeemed' ? 'danger' :
                                                         ($transaction['transaction_type'] === 'expired' ? 'warning' : 'info'));
                                                ?>">
                                                    <?php echo ucfirst($transaction['transaction_type']); ?>
                                                </span>
                                                <br>
                                                <small class="text-muted"><?php echo ucfirst($transaction['source']); ?></small>
                                            </div>
                                            <div class="col-md-2">
                                                <?php if ($transaction['transaction_type'] === 'earned'): ?>
                                                    <span class="points-earned">+<?php echo number_format($transaction['points_earned']); ?></span>
                                                <?php elseif ($transaction['transaction_type'] === 'redeemed'): ?>
                                                    <span class="points-redeemed">-<?php echo number_format($transaction['points_redeemed']); ?></span>
                                                <?php else: ?>
                                                    <span class="points-adjusted"><?php echo number_format($transaction['points_earned'] - $transaction['points_redeemed']); ?></span>
                                                <?php endif; ?>
                                                <br>
                                                <small class="text-muted">Balance: <?php echo number_format($transaction['points_balance']); ?></small>
                                            </div>
                                            <div class="col-md-3">
                                                <p class="mb-0 small"><?php echo htmlspecialchars($transaction['description'] ?? ''); ?></p>
                                                <?php if (!empty($transaction['transaction_reference'])): ?>
                                                    <small class="text-muted">Ref: <?php echo htmlspecialchars($transaction['transaction_reference'] ?? ''); ?></small>
                                                <?php endif; ?>
                                            </div>
                                            <div class="col-md-2 text-end">
                                                <small class="text-muted d-block">
                                                    <?php echo date('M d, Y H:i', strtotime($transaction['created_at'])); ?>
                                                </small>
                                                <?php if (!empty($transaction['approved_by_name'])): ?>
                                                    <small class="text-muted">
                                                        Approved by: <?php echo htmlspecialchars($transaction['approved_by_name'] ?? ''); ?>
                                                    </small>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="card">
                                <div class="card-body text-center py-5">
                                    <i class="bi bi-inbox fs-1 text-muted mb-3"></i>
                                    <h5 class="text-muted">No transactions found</h5>
                                    <p class="text-muted">Try adjusting your filters to see more results.</p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="pagination-container">
                        <div>
                            <small class="text-muted">
                                Page <?php echo $page; ?> of <?php echo $totalPages; ?> (<?php echo $totalRecords; ?> total)
                            </small>
                        </div>
                        <nav aria-label="Transaction pagination">
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
                                <h5>Explore More Reports</h5>
                                <div class="d-flex justify-content-center gap-3 mt-3">
                                    <a href="loyalty_points_summary.php" class="btn btn-primary">
                                        <i class="bi bi-graph-up"></i> Points Summary
                                    </a>
                                    <a href="loyalty_customer_ranking.php" class="btn btn-success">
                                        <i class="bi bi-trophy"></i> Customer Rankings
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
