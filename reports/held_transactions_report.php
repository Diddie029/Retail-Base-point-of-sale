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
$username = $_SESSION['username'] ?? 'User';
$role_name = $_SESSION['role'] ?? 'User';

// Get user permissions
$permissions = [];
if ($role_name !== 'Admin') {
    $stmt = $conn->prepare("
        SELECT p.name 
        FROM permissions p
        JOIN role_permissions rp ON p.id = rp.permission_id
        JOIN users u ON rp.role_id = u.role_id
        WHERE u.id = ?
    ");
    $stmt->execute([$user_id]);
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

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get filter parameters
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$cashier_id = $_GET['cashier_id'] ?? '';
$status = $_GET['status'] ?? '';

// Build the query
$where_conditions = ["DATE(ht.created_at) BETWEEN ? AND ?"];
$params = [$date_from, $date_to];

if (!empty($cashier_id)) {
    $where_conditions[] = "ht.user_id = ?";
    $params[] = $cashier_id;
}

if (!empty($status)) {
    $where_conditions[] = "ht.status = ?";
    $params[] = $status;
}

$where_clause = implode(' AND ', $where_conditions);

// Get held transactions
$stmt = $conn->prepare("
    SELECT ht.*, u.username as cashier_name, rt.till_name, rt.till_code
    FROM held_transactions ht
    LEFT JOIN users u ON ht.user_id = u.id
    LEFT JOIN register_tills rt ON ht.till_id = rt.id
    WHERE $where_clause
    ORDER BY ht.created_at DESC
");
$stmt->execute($params);
$held_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get summary statistics
$summary_stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_held,
        COUNT(DISTINCT ht.user_id) as cashiers_involved,
        SUM(JSON_UNQUOTE(JSON_EXTRACT(ht.cart_data, '$.total'))) as total_held_amount,
        COUNT(CASE WHEN ht.status = 'held' THEN 1 END) as currently_held,
        COUNT(CASE WHEN ht.status = 'resumed' THEN 1 END) as resumed,
        COUNT(CASE WHEN ht.status = 'deleted' THEN 1 END) as deleted,
        COUNT(CASE WHEN ht.status = 'completed' THEN 1 END) as completed
    FROM held_transactions ht
    WHERE $where_clause
");
$summary_stmt->execute($params);
$summary = $summary_stmt->fetch(PDO::FETCH_ASSOC);

// Get cashiers for filter dropdown
$cashiers_stmt = $conn->query("
    SELECT DISTINCT u.id, u.username 
    FROM held_transactions ht
    JOIN users u ON ht.user_id = u.id
    ORDER BY u.username
");
$cashiers = $cashiers_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get status options
$status_options = [
    'held' => 'Currently Held',
    'resumed' => 'Resumed',
    'deleted' => 'Deleted',
    'completed' => 'Completed'
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Held Transactions Report - Point of Sale</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        .report-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
        }
        .summary-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 1.5rem;
        }
        .filter-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 1.5rem;
        }
        .transaction-card {
            background: white;
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 1rem;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            border-left: 4px solid #007bff;
        }
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .status-held { background-color: #fff3cd; color: #856404; }
        .status-resumed { background-color: #d4edda; color: #155724; }
        .status-deleted { background-color: #f8d7da; color: #721c24; }
        .status-completed { background-color: #d1ecf1; color: #0c5460; }
        .cart-preview {
            background-color: #f8f9fa;
            border-radius: 5px;
            padding: 0.75rem;
            margin-top: 0.5rem;
            font-size: 0.875rem;
        }
        .export-buttons {
            margin-bottom: 1.5rem;
        }
    </style>
</head>
<body>
    <?php include '../include/navmenu.php'; ?>

    <div class="main-content">
        <!-- Report Header -->
        <div class="report-header">
            <div class="container-fluid">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <h1 class="mb-2"><i class="bi bi-pause-circle"></i> Held Transactions Report</h1>
                        <p class="mb-0 opacity-75">Detailed report of held/suspended transactions and their resolution status</p>
                    </div>
                    <div class="col-md-4 text-md-end">
                        <div class="export-buttons">
                            <a href="export_held_transactions.php?<?php echo http_build_query($_GET); ?>&export=csv" class="btn btn-light me-2">
                                <i class="bi bi-download"></i> Export CSV
                            </a>
                            <a href="export_held_transactions.php?<?php echo http_build_query($_GET); ?>&export=pdf" class="btn btn-light">
                                <i class="bi bi-file-pdf"></i> Export PDF
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="container-fluid">
            <!-- Summary Statistics -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="summary-card text-center">
                        <h3 class="text-primary mb-1"><?php echo number_format($summary['total_held'] ?? 0); ?></h3>
                        <p class="text-muted mb-0">Total Held</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="summary-card text-center">
                        <h3 class="text-warning mb-1"><?php echo number_format($summary['currently_held'] ?? 0); ?></h3>
                        <p class="text-muted mb-0">Currently Held</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="summary-card text-center">
                        <h3 class="text-success mb-1"><?php echo number_format($summary['resumed'] ?? 0); ?></h3>
                        <p class="text-muted mb-0">Resumed</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="summary-card text-center">
                        <h3 class="text-info mb-1"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($summary['total_held_amount'] ?? 0, 2); ?></h3>
                        <p class="text-muted mb-0">Total Value</p>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filter-card">
                <h5 class="mb-3"><i class="bi bi-funnel"></i> Filters</h5>
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Date From</label>
                        <input type="date" class="form-control" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Date To</label>
                        <input type="date" class="form-control" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Cashier</label>
                        <select class="form-select" name="cashier_id">
                            <option value="">All Cashiers</option>
                            <?php foreach ($cashiers as $cashier): ?>
                                <option value="<?php echo $cashier['id']; ?>" <?php echo $cashier_id == $cashier['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cashier['username']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status">
                            <option value="">All Status</option>
                            <?php foreach ($status_options as $value => $label): ?>
                                <option value="<?php echo $value; ?>" <?php echo $status == $value ? 'selected' : ''; ?>>
                                    <?php echo $label; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-search"></i> Apply Filters
                        </button>
                        <a href="held_transactions_report.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-clockwise"></i> Reset
                        </a>
                    </div>
                </form>
            </div>

            <!-- Held Transactions List -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-list-ul"></i> Held Transactions</h5>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($held_transactions)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-inbox fs-1 text-muted"></i>
                            <h5 class="text-muted mt-3">No held transactions found</h5>
                            <p class="text-muted">No transactions match your current filter criteria.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>ID</th>
                                        <th>Date/Time</th>
                                        <th>Cashier</th>
                                        <th>Till</th>
                                        <th>Status</th>
                                        <th>Value</th>
                                        <th>Items</th>
                                        <th>Reason</th>
                                        <th>Customer Ref</th>
                                        <th>Updated</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($held_transactions as $held): ?>
                                        <?php 
                                        $cart_data = json_decode($held['cart_data'], true);
                                        $total_amount = $cart_data['total'] ?? 0;
                                        $items_count = count($cart_data['items'] ?? []);
                                        ?>
                                        <tr>
                                            <td><strong>#<?php echo $held['id']; ?></strong></td>
                                            <td>
                                                <div><?php echo date('M d, Y', strtotime($held['created_at'])); ?></div>
                                                <small class="text-muted"><?php echo date('H:i:s', strtotime($held['created_at'])); ?></small>
                                            </td>
                                            <td>
                                                <div class="fw-semibold"><?php echo htmlspecialchars($held['cashier_name'] ?? 'Unknown'); ?></div>
                                            </td>
                                            <td>
                                                <div><?php echo htmlspecialchars($held['till_name'] ?? 'N/A'); ?></div>
                                                <?php if (!empty($held['till_code'])): ?>
                                                    <small class="text-muted"><?php echo htmlspecialchars($held['till_code']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?php echo $held['status']; ?>">
                                                    <?php echo ucfirst($held['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="fw-semibold text-primary">
                                                    <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($total_amount, 2); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div><?php echo $items_count; ?> items</div>
                                                <?php if ($items_count > 0): ?>
                                                    <small class="text-muted">
                                                        <?php 
                                                        $items = array_slice($cart_data['items'] ?? [], 0, 2);
                                                        $item_names = array_map(function($item) { return $item['name'] ?? 'Unknown Item'; }, $items);
                                                        echo implode(', ', $item_names);
                                                        if (count($cart_data['items'] ?? []) > 2) {
                                                            echo ' +' . (count($cart_data['items'] ?? []) - 2) . ' more';
                                                        }
                                                        ?>
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div><?php echo htmlspecialchars($held['reason'] ?? ''); ?></div>
                                            </td>
                                            <td>
                                                <div><?php echo htmlspecialchars($held['customer_reference'] ?? ''); ?></div>
                                            </td>
                                            <td>
                                                <?php if ($held['status'] !== 'held' && !empty($held['updated_at'])): ?>
                                                    <div><?php echo date('M d, Y', strtotime($held['updated_at'])); ?></div>
                                                    <small class="text-muted"><?php echo date('H:i:s', strtotime($held['updated_at'])); ?></small>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Bottom spacing -->
    <div style="height: 50px;"></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
