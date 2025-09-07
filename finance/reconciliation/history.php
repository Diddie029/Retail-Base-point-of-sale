<?php
session_start();
require_once '../../include/db.php';
require_once '../../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../auth/login.php');
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

// Check if user has permission to view reconciliation
if (!hasPermission('view_finance', $permissions) && !hasPermission('view_reconciliation', $permissions)) {
    header('Location: ../../dashboard/dashboard.php?error=access_denied');
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get filter parameters
$status_filter = $_GET['status'] ?? '';
$account_filter = $_GET['account'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Build query
$where_conditions = [];
$params = [];

if ($status_filter) {
    $where_conditions[] = "r.status = ?";
    $params[] = $status_filter;
}

if ($account_filter) {
    $where_conditions[] = "r.bank_account_id = ?";
    $params[] = $account_filter;
}

if ($date_from) {
    $where_conditions[] = "r.reconciliation_date >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $where_conditions[] = "r.reconciliation_date <= ?";
    $params[] = $date_to;
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Get reconciliation records
$stmt = $conn->prepare("
    SELECT r.*, ba.account_name, ba.bank_name, u.username as reconciled_by_name,
           COUNT(tm.id) as match_count,
           SUM(tm.match_amount) as total_matched_amount
    FROM reconciliation_records r
    LEFT JOIN bank_accounts ba ON r.bank_account_id = ba.id
    LEFT JOIN users u ON r.reconciled_by = u.id
    LEFT JOIN transaction_matches tm ON r.id = tm.reconciliation_id
    $where_clause
    GROUP BY r.id
    ORDER BY r.created_at DESC
");
$stmt->execute($params);
$reconciliations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get bank accounts for filter
$bank_accounts = [];
$stmt = $conn->query("SELECT * FROM bank_accounts WHERE is_active = 1 ORDER BY account_name");
$bank_accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats = [];
$stmt = $conn->query("
    SELECT 
        COUNT(*) as total_reconciliations,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_reconciliations,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_reconciliations,
        SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft_reconciliations,
        AVG(CASE WHEN status = 'completed' THEN difference_amount ELSE NULL END) as avg_difference
    FROM reconciliation_records
");
$stats = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reconciliation History - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
        
        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1rem;
        }
        
        .stats-card.success {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
        }
        
        .stats-card.warning {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
        
        .stats-card.info {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }
        
        .status-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
        }
        
        .reconciliation-table {
            font-size: 0.9rem;
        }
        
        .filter-card {
            background-color: #f8f9fa;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
    </style>
</head>
<body>
    <?php include '../../include/navmenu.php'; ?>

    <div class="main-content">
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="../index.php">Finance Dashboard</a></li>
                            <li class="breadcrumb-item"><a href="../reconciliation.php">Reconciliation</a></li>
                            <li class="breadcrumb-item active">History</li>
                        </ol>
                    </nav>
                    <h1><i class="bi bi-clock-history"></i> Reconciliation History</h1>
                    <p class="header-subtitle">View all reconciliation records and their status</p>
                </div>
                <div class="header-actions">
                    <a href="../reconciliation.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Back to Reconciliation
                    </a>
                </div>
            </div>
        </header>

        <main class="content">
            <div class="container-fluid">
                <!-- Statistics -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="stats-card">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Total Reconciliations</h6>
                                <i class="bi bi-list-check fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo $stats['total_reconciliations']; ?></h3>
                            <small class="opacity-75">All time</small>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="stats-card success">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Completed</h6>
                                <i class="bi bi-check-circle fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo $stats['completed_reconciliations']; ?></h3>
                            <small class="opacity-75">Successfully reconciled</small>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="stats-card warning">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">In Progress</h6>
                                <i class="bi bi-clock fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo $stats['in_progress_reconciliations']; ?></h3>
                            <small class="opacity-75">Currently reconciling</small>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="stats-card info">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Avg Difference</h6>
                                <i class="bi bi-graph-up fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($stats['avg_difference'] ?? 0, 2); ?></h3>
                            <small class="opacity-75">Completed reconciliations</small>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="filter-card">
                    <h5 class="mb-3"><i class="bi bi-funnel"></i> Filters</h5>
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label for="status" class="form-label">Status</label>
                            <select class="form-select" id="status" name="status">
                                <option value="">All Statuses</option>
                                <option value="draft" <?php echo $status_filter == 'draft' ? 'selected' : ''; ?>>Draft</option>
                                <option value="in_progress" <?php echo $status_filter == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                <option value="completed" <?php echo $status_filter == 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="cancelled" <?php echo $status_filter == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="account" class="form-label">Bank Account</label>
                            <select class="form-select" id="account" name="account">
                                <option value="">All Accounts</option>
                                <?php foreach ($bank_accounts as $account): ?>
                                <option value="<?php echo $account['id']; ?>" <?php echo $account_filter == $account['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($account['account_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="date_from" class="form-label">From Date</label>
                            <input type="date" class="form-control" id="date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                        </div>
                        <div class="col-md-2">
                            <label for="date_to" class="form-label">To Date</label>
                            <input type="date" class="form-control" id="date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">&nbsp;</label>
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-search"></i> Filter
                                </button>
                                <a href="history.php" class="btn btn-outline-secondary">
                                    <i class="bi bi-x"></i> Clear
                                </a>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Reconciliation Records -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-list"></i> Reconciliation Records</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($reconciliations)): ?>
                                <div class="text-center py-5">
                                    <i class="bi bi-clock-history fs-1 text-muted mb-3"></i>
                                    <h5 class="text-muted">No Reconciliation Records</h5>
                                    <p class="text-muted">No reconciliations found matching your criteria</p>
                                    <a href="../reconciliation.php" class="btn btn-primary">
                                        <i class="bi bi-plus"></i> Start New Reconciliation
                                    </a>
                                </div>
                                <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover reconciliation-table">
                                        <thead>
                                            <tr>
                                                <th>Account</th>
                                                <th>Date</th>
                                                <th>Opening Balance</th>
                                                <th>Closing Balance</th>
                                                <th>Difference</th>
                                                <th>Matches</th>
                                                <th>Status</th>
                                                <th>Reconciled By</th>
                                                <th>Created</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($reconciliations as $reconciliation): ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-semibold"><?php echo htmlspecialchars($reconciliation['account_name']); ?></div>
                                                    <small class="text-muted"><?php echo htmlspecialchars($reconciliation['bank_name'] ?? 'N/A'); ?></small>
                                                </td>
                                                <td><?php echo date('M d, Y', strtotime($reconciliation['reconciliation_date'])); ?></td>
                                                <td><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($reconciliation['opening_balance'], 2); ?></td>
                                                <td><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($reconciliation['closing_balance'], 2); ?></td>
                                                <td class="<?php echo $reconciliation['difference_amount'] == 0 ? 'text-success' : 'text-danger'; ?>">
                                                    <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($reconciliation['difference_amount'], 2); ?>
                                                </td>
                                                <td>
                                                    <div class="fw-semibold"><?php echo $reconciliation['match_count']; ?></div>
                                                    <small class="text-muted"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($reconciliation['total_matched_amount'] ?? 0, 2); ?></small>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php echo $reconciliation['status'] == 'completed' ? 'success' : ($reconciliation['status'] == 'in_progress' ? 'warning' : 'secondary'); ?> status-badge">
                                                        <?php echo ucfirst($reconciliation['status']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo htmlspecialchars($reconciliation['reconciled_by_name']); ?></td>
                                                <td><?php echo date('M d, Y H:i', strtotime($reconciliation['created_at'])); ?></td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <a href="view.php?id=<?php echo $reconciliation['id']; ?>" class="btn btn-outline-primary" title="View">
                                                            <i class="bi bi-eye"></i>
                                                        </a>
                                                        <?php if ($reconciliation['status'] == 'draft' && hasPermission('manage_reconciliation', $permissions)): ?>
                                                        <a href="edit.php?id=<?php echo $reconciliation['id']; ?>" class="btn btn-outline-warning" title="Edit">
                                                            <i class="bi bi-pencil"></i>
                                                        </a>
                                                        <?php endif; ?>
                                                        <?php if ($reconciliation['status'] == 'draft' || $reconciliation['status'] == 'in_progress'): ?>
                                                        <a href="match.php?id=<?php echo $reconciliation['id']; ?>" class="btn btn-outline-success" title="Match Transactions">
                                                            <i class="bi bi-arrow-left-right"></i>
                                                        </a>
                                                        <?php endif; ?>
                                                    </div>
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
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
