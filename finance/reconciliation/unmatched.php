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
$account_filter = $_GET['account'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Build query for unmatched bank transactions
$where_conditions = ["bt.is_reconciled = 0"];
$params = [];

if ($account_filter) {
    $where_conditions[] = "bt.bank_account_id = ?";
    $params[] = $account_filter;
}

if ($date_from) {
    $where_conditions[] = "bt.transaction_date >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $where_conditions[] = "bt.transaction_date <= ?";
    $params[] = $date_to;
}

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

// Get unmatched bank transactions
$stmt = $conn->prepare("
    SELECT bt.*, ba.account_name, ba.bank_name
    FROM bank_transactions bt
    LEFT JOIN bank_accounts ba ON bt.bank_account_id = ba.id
    $where_clause
    ORDER BY bt.transaction_date DESC
");
$stmt->execute($params);
$unmatched_bank = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get unmatched POS transactions (last 30 days)
$pos_where_conditions = ["s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)"];
$pos_params = [];

if ($date_from) {
    $pos_where_conditions[] = "s.sale_date >= ?";
    $pos_params[] = $date_from;
}

if ($date_to) {
    $pos_where_conditions[] = "s.sale_date <= ?";
    $pos_params[] = $date_to;
}

$pos_where_clause = "WHERE " . implode(" AND ", $pos_where_conditions);

$stmt = $conn->prepare("
    SELECT s.*, u.username as cashier_name
    FROM sales s
    LEFT JOIN users u ON s.user_id = u.id
    $pos_where_clause
    AND s.id NOT IN (
        SELECT COALESCE(tm.pos_transaction_id, 0) 
        FROM transaction_matches tm 
        WHERE tm.pos_transaction_id IS NOT NULL
    )
    ORDER BY s.sale_date DESC
");
$stmt->execute($pos_params);
$unmatched_pos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get bank accounts for filter
$bank_accounts = [];
$stmt = $conn->query("SELECT * FROM bank_accounts WHERE is_active = 1 ORDER BY account_name");
$bank_accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats = [];
$stmt = $conn->query("
    SELECT 
        COUNT(*) as total_unmatched_bank,
        SUM(amount) as total_unmatched_bank_amount
    FROM bank_transactions 
    WHERE is_reconciled = 0
");
$stats['bank'] = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $conn->query("
    SELECT 
        COUNT(*) as total_unmatched_pos,
        SUM(final_amount) as total_unmatched_pos_amount
    FROM sales 
    WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    AND id NOT IN (
        SELECT COALESCE(tm.pos_transaction_id, 0) 
        FROM transaction_matches tm 
        WHERE tm.pos_transaction_id IS NOT NULL
    )
");
$stats['pos'] = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unmatched Transactions - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
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
        
        .stats-card.danger {
            background: linear-gradient(135deg, #fc466b 0%, #3f5efb 100%);
        }
        
        .stats-card.warning {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
        
        .transaction-card {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
        }
        
        .transaction-card:hover {
            border-color: var(--primary-color);
            box-shadow: 0 2px 8px rgba(102, 126, 234, 0.15);
        }
        
        .amount-highlight {
            font-weight: bold;
            font-size: 1.1em;
        }
        
        .date-text {
            color: #6c757d;
            font-size: 0.9em;
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
                            <li class="breadcrumb-item active">Unmatched Transactions</li>
                        </ol>
                    </nav>
                    <h1><i class="bi bi-exclamation-triangle"></i> Unmatched Transactions</h1>
                    <p class="header-subtitle">Transactions that need to be reconciled</p>
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
                    <div class="col-md-6 mb-3">
                        <div class="stats-card danger">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Unmatched Bank Transactions</h6>
                                <i class="bi bi-bank fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo $stats['bank']['total_unmatched_bank']; ?></h3>
                            <small class="opacity-75"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($stats['bank']['total_unmatched_bank_amount'], 2); ?></small>
                        </div>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <div class="stats-card warning">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Unmatched POS Transactions</h6>
                                <i class="bi bi-receipt fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo $stats['pos']['total_unmatched_pos']; ?></h3>
                            <small class="opacity-75"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($stats['pos']['total_unmatched_pos_amount'], 2); ?></small>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="filter-card">
                    <h5 class="mb-3"><i class="bi bi-funnel"></i> Filters</h5>
                    <form method="GET" class="row g-3">
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
                                <a href="unmatched.php" class="btn btn-outline-secondary">
                                    <i class="bi bi-x"></i> Clear
                                </a>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Unmatched Bank Transactions -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-bank"></i> Unmatched Bank Transactions</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($unmatched_bank)): ?>
                                <div class="text-center py-4">
                                    <i class="bi bi-check-circle fs-1 text-success mb-3"></i>
                                    <h5 class="text-success">All Bank Transactions Matched</h5>
                                    <p class="text-muted">No unmatched bank transactions found</p>
                                </div>
                                <?php else: ?>
                                <div class="row">
                                    <?php foreach ($unmatched_bank as $transaction): ?>
                                    <div class="col-md-6 col-lg-4 mb-3">
                                        <div class="transaction-card">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <div>
                                                    <div class="fw-semibold"><?php echo htmlspecialchars($transaction['description']); ?></div>
                                                    <div class="date-text"><?php echo date('M d, Y', strtotime($transaction['transaction_date'])); ?></div>
                                                    <small class="text-muted"><?php echo htmlspecialchars($transaction['account_name']); ?></small>
                                                </div>
                                                <div class="text-end">
                                                    <div class="amount-highlight text-<?php echo $transaction['transaction_type'] == 'credit' ? 'success' : 'danger'; ?>">
                                                        <?php echo $transaction['transaction_type'] == 'credit' ? '+' : '-'; ?><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($transaction['amount'], 2); ?>
                                                    </div>
                                                    <?php if ($transaction['reference_number']): ?>
                                                    <small class="text-muted">Ref: <?php echo htmlspecialchars($transaction['reference_number']); ?></small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Unmatched POS Transactions -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-receipt"></i> Unmatched POS Transactions (Last 30 Days)</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($unmatched_pos)): ?>
                                <div class="text-center py-4">
                                    <i class="bi bi-check-circle fs-1 text-success mb-3"></i>
                                    <h5 class="text-success">All POS Transactions Matched</h5>
                                    <p class="text-muted">No unmatched POS transactions found</p>
                                </div>
                                <?php else: ?>
                                <div class="row">
                                    <?php foreach ($unmatched_pos as $transaction): ?>
                                    <div class="col-md-6 col-lg-4 mb-3">
                                        <div class="transaction-card">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <div>
                                                    <div class="fw-semibold"><?php echo htmlspecialchars($transaction['customer_name']); ?></div>
                                                    <div class="date-text"><?php echo date('M d, Y H:i', strtotime($transaction['sale_date'])); ?></div>
                                                    <small class="text-muted">Cashier: <?php echo htmlspecialchars($transaction['cashier_name']); ?></small>
                                                </div>
                                                <div class="text-end">
                                                    <div class="amount-highlight text-success">
                                                        <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($transaction['final_amount'], 2); ?>
                                                    </div>
                                                    <small class="text-muted"><?php echo ucfirst($transaction['payment_method']); ?></small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
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
