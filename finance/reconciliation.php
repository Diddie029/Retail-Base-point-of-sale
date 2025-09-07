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

// Check if user has permission to view reconciliation
if (!hasPermission('view_finance', $permissions) && !hasPermission('view_reconciliation', $permissions)) {
    header('Location: ../dashboard/dashboard.php?error=access_denied');
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get bank accounts
$bank_accounts = [];
$stmt = $conn->query("SELECT * FROM bank_accounts WHERE is_active = 1 ORDER BY account_name");
$bank_accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent reconciliation records
$recent_reconciliations = [];
$stmt = $conn->prepare("
    SELECT r.*, ba.account_name, u.username as reconciled_by_name
    FROM reconciliation_records r
    LEFT JOIN bank_accounts ba ON r.bank_account_id = ba.id
    LEFT JOIN users u ON r.reconciled_by = u.id
    ORDER BY r.created_at DESC
    LIMIT 10
");
$stmt->execute();
$recent_reconciliations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get reconciliation statistics
$stats = [];
$stmt = $conn->query("
    SELECT 
        COUNT(*) as total_reconciliations,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_reconciliations,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_reconciliations,
        SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft_reconciliations
    FROM reconciliation_records
");
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Get unmatched transactions count
$unmatched_count = 0;
$stmt = $conn->query("
    SELECT COUNT(*) as count FROM bank_transactions 
    WHERE is_reconciled = 0
");
$unmatched_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Reconciliation - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
        
        .reconciliation-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1rem;
        }
        
        .reconciliation-card.success {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
        }
        
        .reconciliation-card.warning {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
        
        .reconciliation-card.info {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }
        
        .account-card {
            border: none;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            border-radius: 12px;
        }
        
        .account-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }
        
        .status-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
        }
        
        .reconciliation-table {
            font-size: 0.9rem;
        }
        
        .action-btn {
            transition: all 0.3s ease;
        }
        
        .action-btn:hover {
            transform: scale(1.05);
        }
    </style>
</head>
<body>
<?php include '../include/navmenu.php'; ?>

    <div class="main-content">
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="index.php">Finance Dashboard</a></li>
                            <li class="breadcrumb-item active">Account Reconciliation</li>
                        </ol>
                    </nav>
                    <h1><i class="bi bi-check2-square"></i> Account Reconciliation</h1>
                    <p class="header-subtitle">Reconcile bank accounts with POS transactions</p>
                </div>
                <div class="header-actions">
                    <div class="d-flex align-items-center gap-3">
                        <a href="reconciliation/documentation.php" class="btn btn-outline-info btn-sm" title="View Documentation">
                            <i class="bi bi-book"></i> Documentation
                        </a>
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
            </div>
        </header>

        <main class="content">
            <div class="container-fluid">
                <!-- Reconciliation Statistics -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="reconciliation-card">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Total Reconciliations</h6>
                                <i class="bi bi-list-check fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo $stats['total_reconciliations']; ?></h3>
                            <small class="opacity-75">All time</small>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="reconciliation-card success">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Completed</h6>
                                <i class="bi bi-check-circle fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo $stats['completed_reconciliations']; ?></h3>
                            <small class="opacity-75">Successfully reconciled</small>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="reconciliation-card warning">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">In Progress</h6>
                                <i class="bi bi-clock fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo $stats['in_progress_reconciliations']; ?></h3>
                            <small class="opacity-75">Currently reconciling</small>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="reconciliation-card info">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Unmatched</h6>
                                <i class="bi bi-exclamation-triangle fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo $unmatched_count; ?></h3>
                            <small class="opacity-75">Transactions need matching</small>
                        </div>
                    </div>
                </div>

                <!-- Quick Help -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="alert alert-info">
                            <div class="d-flex align-items-center">
                                <i class="bi bi-info-circle fs-4 me-3"></i>
                                <div class="flex-grow-1">
                                    <h6 class="mb-1">New to Reconciliation?</h6>
                                    <p class="mb-0">Start by adding a bank account, then import your bank statement. Check our <a href="reconciliation/documentation.php" class="alert-link">comprehensive documentation</a> for detailed step-by-step instructions.</p>
                                </div>
                                <a href="reconciliation/documentation.php" class="btn btn-outline-info btn-sm">
                                    <i class="bi bi-book"></i> View Docs
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Payment Types Overview -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0"><i class="bi bi-credit-card"></i> Payment Types</h5>
                                <a href="reconciliation/payment-types.php" class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-gear"></i> Manage
                                </a>
                            </div>
                            <div class="card-body">
                                <?php
                                // Get payment types for display with sales data
                                $stmt = $conn->query("
                                    SELECT pt.*, 
                                           COUNT(bt.id) as bank_transaction_count,
                                           SUM(bt.amount) as bank_total_amount,
                                           COUNT(s.id) as sales_count,
                                           SUM(s.final_amount) as sales_total_amount
                                    FROM payment_types pt
                                    LEFT JOIN bank_transactions bt ON pt.id = bt.payment_type_id
                                    LEFT JOIN sales s ON (
                                        CASE 
                                            WHEN s.payment_method = 'cash' THEN pt.name = 'cash'
                                            WHEN s.payment_method = 'mobile_money' OR s.payment_method = 'mpesa' OR s.payment_method = 'airtel_money' THEN pt.name = 'mobile_money'
                                            WHEN s.payment_method = 'credit_card' THEN pt.name = 'credit_card'
                                            WHEN s.payment_method = 'debit_card' THEN pt.name = 'debit_card'
                                            WHEN s.payment_method = 'bank_transfer' OR s.payment_method = 'bank' THEN pt.name = 'bank_transfer'
                                            WHEN s.payment_method = 'check' THEN pt.name = 'check'
                                            WHEN s.payment_method = 'pos_card' OR s.payment_method = 'card' THEN pt.name = 'pos_card'
                                            WHEN s.payment_method = 'online' OR s.payment_method = 'online_payment' THEN pt.name = 'online_payment'
                                            WHEN s.payment_method = 'voucher' THEN pt.name = 'voucher'
                                            WHEN s.payment_method = 'store_credit' OR s.payment_method = 'loyalty' THEN pt.name = 'store_credit'
                                            ELSE pt.name = 'cash'
                                        END
                                    ) AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                                    WHERE pt.is_active = 1 AND pt.requires_reconciliation = 1
                                    GROUP BY pt.id
                                    ORDER BY pt.sort_order, pt.display_name
                                ");
                                $payment_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                ?>
                                
                                <?php if (empty($payment_types)): ?>
                                <div class="text-center py-3">
                                    <i class="bi bi-credit-card fs-1 text-muted mb-3"></i>
                                    <h6 class="text-muted">No Payment Types Configured</h6>
                                    <p class="text-muted">Set up payment types to improve reconciliation accuracy</p>
                                    <a href="reconciliation/payment-types.php" class="btn btn-primary">
                                        <i class="bi bi-plus"></i> Add Payment Types
                                    </a>
                                </div>
                                <?php else: ?>
                                <div class="row">
                                    <?php foreach ($payment_types as $type): ?>
                                    <div class="col-md-3 col-sm-6 mb-3">
                                        <div class="d-flex align-items-center p-3 border rounded">
                                            <div class="me-3">
                                                <div class="rounded-circle d-flex align-items-center justify-content-center" 
                                                     style="width: 40px; height: 40px; background: <?php echo $type['color']; ?>;">
                                                    <i class="<?php echo $type['icon']; ?> text-white"></i>
                                                </div>
                                            </div>
                                            <div class="flex-grow-1">
                                                <h6 class="mb-1"><?php echo htmlspecialchars($type['display_name']); ?></h6>
                                                <small class="text-muted">
                                                    <div>Bank: <?php echo $type['bank_transaction_count']; ?> transactions</div>
                                                    <div>Sales: <?php echo $type['sales_count']; ?> transactions</div>
                                                    <?php if ($type['bank_total_amount'] || $type['sales_total_amount']): ?>
                                                    <div class="mt-1">
                                                        <?php if ($type['bank_total_amount']): ?>
                                                        <span class="text-primary">Bank: <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($type['bank_total_amount'], 2); ?></span>
                                                        <?php endif; ?>
                                                        <?php if ($type['sales_total_amount']): ?>
                                                        <span class="text-success">Sales: <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($type['sales_total_amount'], 2); ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php endif; ?>
                                                </small>
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

                <!-- Quick Actions -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-lightning"></i> Quick Actions</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <?php if (hasPermission('view_finance', $permissions) || hasPermission('manage_reconciliation', $permissions)): ?>
                                    <div class="col-md-3 mb-3">
                                        <button class="btn btn-primary w-100 action-btn" onclick="startNewReconciliation()">
                                            <i class="bi bi-plus-circle"></i> Start New Reconciliation
                                        </button>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if (hasPermission('view_finance', $permissions) || hasPermission('import_bank_statements', $permissions)): ?>
                                    <div class="col-md-3 mb-3">
                                        <button class="btn btn-success w-100 action-btn" onclick="importBankStatement()">
                                            <i class="bi bi-upload"></i> Import Bank Statement
                                        </button>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="col-md-3 mb-3">
                                        <button class="btn btn-info w-100 action-btn" onclick="viewReconciliationHistory()">
                                            <i class="bi bi-clock-history"></i> View History
                                        </button>
                                    </div>
                                    
                                    <div class="col-md-3 mb-3">
                                        <button class="btn btn-warning w-100 action-btn" onclick="viewUnmatchedTransactions()">
                                            <i class="bi bi-exclamation-triangle"></i> Unmatched Transactions
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Bank Accounts -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0"><i class="bi bi-bank"></i> Bank Accounts</h5>
                                <?php if (hasPermission('view_finance', $permissions) || hasPermission('manage_reconciliation', $permissions)): ?>
                                <button class="btn btn-sm btn-outline-primary" onclick="addBankAccount()">
                                    <i class="bi bi-plus"></i> Add Account
                                </button>
                                <?php endif; ?>
                            </div>
                            <div class="card-body">
                                <?php if (empty($bank_accounts)): ?>
                                <div class="text-center py-4">
                                    <i class="bi bi-bank fs-1 text-muted mb-3"></i>
                                    <h5 class="text-muted">No Bank Accounts</h5>
                                    <p class="text-muted">Add your first bank account to start reconciliation</p>
                                    <?php if (hasPermission('view_finance', $permissions) || hasPermission('manage_reconciliation', $permissions)): ?>
                                    <button class="btn btn-primary" onclick="addBankAccount()">
                                        <i class="bi bi-plus"></i> Add Bank Account
                                    </button>
                                    <?php endif; ?>
                                </div>
                                <?php else: ?>
                                <div class="row">
                                    <?php foreach ($bank_accounts as $account): ?>
                                    <div class="col-md-6 col-lg-4 mb-3">
                                        <div class="card account-card h-100" onclick="viewAccountDetails(<?php echo $account['id']; ?>)">
                                            <div class="card-body">
                                                <div class="d-flex justify-content-between align-items-start mb-2">
                                                    <h6 class="card-title mb-0"><?php echo htmlspecialchars($account['account_name']); ?></h6>
                                                    <span class="badge bg-<?php echo $account['account_type'] == 'checking' ? 'primary' : ($account['account_type'] == 'savings' ? 'success' : 'info'); ?> status-badge">
                                                        <?php echo ucfirst($account['account_type']); ?>
                                                    </span>
                                                </div>
                                                <p class="text-muted small mb-2"><?php echo htmlspecialchars($account['bank_name'] ?? 'N/A'); ?></p>
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <div>
                                                        <small class="text-muted">Current Balance</small>
                                                        <div class="fw-bold"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($account['current_balance'], 2); ?></div>
                                                    </div>
                                                    <i class="bi bi-arrow-right text-primary"></i>
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

                <!-- Recent Reconciliations -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-clock-history"></i> Recent Reconciliations</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($recent_reconciliations)): ?>
                                <div class="text-center py-4">
                                    <i class="bi bi-clock-history fs-1 text-muted mb-3"></i>
                                    <h5 class="text-muted">No Recent Reconciliations</h5>
                                    <p class="text-muted">Start your first reconciliation to see history here</p>
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
                                                <th>Status</th>
                                                <th>Reconciled By</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recent_reconciliations as $reconciliation): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($reconciliation['account_name']); ?></td>
                                                <td><?php echo date('M d, Y', strtotime($reconciliation['reconciliation_date'])); ?></td>
                                                <td><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($reconciliation['opening_balance'], 2); ?></td>
                                                <td><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($reconciliation['closing_balance'], 2); ?></td>
                                                <td class="<?php echo $reconciliation['difference_amount'] == 0 ? 'text-success' : 'text-danger'; ?>">
                                                    <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($reconciliation['difference_amount'], 2); ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php echo $reconciliation['status'] == 'completed' ? 'success' : ($reconciliation['status'] == 'in_progress' ? 'warning' : 'secondary'); ?> status-badge">
                                                        <?php echo ucfirst($reconciliation['status']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo htmlspecialchars($reconciliation['reconciled_by_name']); ?></td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <button class="btn btn-outline-primary" onclick="viewReconciliation(<?php echo $reconciliation['id']; ?>)">
                                                            <i class="bi bi-eye"></i>
                                                        </button>
                                                        <?php if ($reconciliation['status'] == 'draft' && (hasPermission('view_finance', $permissions) || hasPermission('manage_reconciliation', $permissions))): ?>
                                                        <button class="btn btn-outline-warning" onclick="editReconciliation(<?php echo $reconciliation['id']; ?>)">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
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
    <script>
        function startNewReconciliation() {
            // Redirect to new reconciliation page
            window.location.href = 'reconciliation/new.php';
        }
        
        function importBankStatement() {
            // Redirect to import page
            window.location.href = 'reconciliation/import.php';
        }
        
        function viewReconciliationHistory() {
            // Redirect to history page
            window.location.href = 'reconciliation/history.php';
        }
        
        function viewUnmatchedTransactions() {
            // Redirect to unmatched transactions page
            window.location.href = 'reconciliation/unmatched.php';
        }
        
        function addBankAccount() {
            // Redirect to add bank account page
            window.location.href = 'reconciliation/accounts.php?action=add';
        }
        
        function viewAccountDetails(accountId) {
            // Redirect to account details page
            window.location.href = 'reconciliation/accounts.php?action=view&id=' + accountId;
        }
        
        function viewReconciliation(reconciliationId) {
            // Redirect to view reconciliation page
            window.location.href = 'reconciliation/view.php?id=' + reconciliationId;
        }
        
        function editReconciliation(reconciliationId) {
            // Redirect to edit reconciliation page
            window.location.href = 'reconciliation/edit.php?id=' + reconciliationId;
        }
    </script>
</body>
</html>
