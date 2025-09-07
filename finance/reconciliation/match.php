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

// Check if user has permission to manage reconciliation
if (!hasPermission('view_finance', $permissions) && !hasPermission('manage_reconciliation', $permissions)) {
    header('Location: ../../dashboard/dashboard.php?error=access_denied');
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$reconciliation_id = $_GET['id'] ?? null;

if (!$reconciliation_id) {
    header('Location: ../reconciliation.php?error=invalid_id');
    exit();
}

// Get reconciliation details
$stmt = $conn->prepare("
    SELECT r.*, ba.account_name, ba.bank_name
    FROM reconciliation_records r
    LEFT JOIN bank_accounts ba ON r.bank_account_id = ba.id
    WHERE r.id = ?
");
$stmt->execute([$reconciliation_id]);
$reconciliation = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$reconciliation) {
    header('Location: ../reconciliation.php?error=not_found');
    exit();
}

// Get unmatched bank transactions
$stmt = $conn->prepare("
    SELECT * FROM bank_transactions 
    WHERE bank_account_id = ? AND is_reconciled = 0
    ORDER BY transaction_date DESC
");
$stmt->execute([$reconciliation['bank_account_id']]);
$bank_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Debug: Check if we have bank transactions
if (empty($bank_transactions)) {
    // Try to get any bank transactions for this account
    $stmt = $conn->prepare("SELECT * FROM bank_transactions WHERE bank_account_id = ? ORDER BY transaction_date DESC LIMIT 5");
    $stmt->execute([$reconciliation['bank_account_id']]);
    $all_bank_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($all_bank_transactions)) {
        // No bank transactions at all for this account
        $bank_transactions = [];
    } else {
        // Use all transactions if no unmatched ones
        $bank_transactions = $all_bank_transactions;
    }
}

// Get unmatched POS transactions (sales) with payment method mapping
$stmt = $conn->prepare("
    SELECT s.*, u.username as cashier_name,
           CASE 
               WHEN s.payment_method = 'cash' THEN 'cash'
               WHEN s.payment_method = 'mobile_money' OR s.payment_method = 'mpesa' OR s.payment_method = 'airtel_money' THEN 'mobile_money'
               WHEN s.payment_method = 'credit_card' THEN 'credit_card'
               WHEN s.payment_method = 'debit_card' THEN 'debit_card'
               WHEN s.payment_method = 'bank_transfer' OR s.payment_method = 'bank' THEN 'bank_transfer'
               WHEN s.payment_method = 'check' THEN 'check'
               WHEN s.payment_method = 'pos_card' OR s.payment_method = 'card' THEN 'pos_card'
               WHEN s.payment_method = 'online' OR s.payment_method = 'online_payment' THEN 'online_payment'
               WHEN s.payment_method = 'voucher' THEN 'voucher'
               WHEN s.payment_method = 'store_credit' OR s.payment_method = 'loyalty' THEN 'store_credit'
               ELSE 'cash'
           END as mapped_payment_type
    FROM sales s
    LEFT JOIN users u ON s.user_id = u.id
    WHERE s.sale_date >= ? AND s.sale_date <= ?
    AND s.id NOT IN (
        SELECT COALESCE(tm.pos_transaction_id, 0) 
        FROM transaction_matches tm 
        WHERE tm.reconciliation_id = ? AND tm.pos_transaction_id IS NOT NULL
    )
    ORDER BY s.sale_date DESC
");
$stmt->execute([$reconciliation['reconciliation_date'], $reconciliation['reconciliation_date'], $reconciliation_id]);
$pos_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Debug: Check if we have POS transactions
if (empty($pos_transactions)) {
    // Try to get any sales from the last 30 days
    $stmt = $conn->prepare("
        SELECT s.*, u.username as cashier_name,
               CASE 
                   WHEN s.payment_method = 'cash' THEN 'cash'
                   WHEN s.payment_method = 'mobile_money' OR s.payment_method = 'mpesa' OR s.payment_method = 'airtel_money' THEN 'mobile_money'
                   WHEN s.payment_method = 'credit_card' THEN 'credit_card'
                   WHEN s.payment_method = 'debit_card' THEN 'debit_card'
                   WHEN s.payment_method = 'bank_transfer' OR s.payment_method = 'bank' THEN 'bank_transfer'
                   WHEN s.payment_method = 'check' THEN 'check'
                   WHEN s.payment_method = 'pos_card' OR s.payment_method = 'card' THEN 'pos_card'
                   WHEN s.payment_method = 'online' OR s.payment_method = 'online_payment' THEN 'online_payment'
                   WHEN s.payment_method = 'voucher' THEN 'voucher'
                   WHEN s.payment_method = 'store_credit' OR s.payment_method = 'loyalty' THEN 'store_credit'
                   ELSE 'cash'
               END as mapped_payment_type
        FROM sales s
        LEFT JOIN users u ON s.user_id = u.id
        WHERE s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        ORDER BY s.sale_date DESC
        LIMIT 10
    ");
    $stmt->execute();
    $pos_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Handle manual matching
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'match') {
        $bank_transaction_id = $_POST['bank_transaction_id'] ?? null;
        $pos_transaction_id = $_POST['pos_transaction_id'] ?? null;
        $match_amount = $_POST['match_amount'] ?? 0;
        $match_notes = $_POST['match_notes'] ?? '';
        
        if ($bank_transaction_id && $pos_transaction_id && $match_amount > 0) {
            try {
                $stmt = $conn->prepare("
                    INSERT INTO transaction_matches (reconciliation_id, bank_transaction_id, pos_transaction_id, match_type, match_amount, match_notes, created_by)
                    VALUES (?, ?, ?, 'manual', ?, ?, ?)
                ");
                $stmt->execute([$reconciliation_id, $bank_transaction_id, $pos_transaction_id, $match_amount, $match_notes, $user_id]);
                
                // Mark transactions as reconciled
                $stmt = $conn->prepare("UPDATE bank_transactions SET is_reconciled = 1 WHERE id = ?");
                $stmt->execute([$bank_transaction_id]);
                
                $success = "Transaction matched successfully";
                
                // Refresh the page to show updated data
                header("Location: match.php?id=" . $reconciliation_id);
                exit();
            } catch (Exception $e) {
                $error = "Error matching transactions: " . $e->getMessage();
            }
        } else {
            $error = "Please select both transactions and enter a match amount";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Match Transactions - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
        
        .transaction-card {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .transaction-card:hover {
            border-color: var(--primary-color);
            box-shadow: 0 2px 8px rgba(102, 126, 234, 0.15);
        }
        
        .transaction-card.selected {
            border-color: var(--primary-color);
            background-color: rgba(102, 126, 234, 0.05);
        }
        
        .match-interface {
            background-color: #f8f9fa;
            border-radius: 10px;
            padding: 1.5rem;
            margin: 1rem 0;
        }
        
        .amount-highlight {
            font-weight: bold;
            font-size: 1.1em;
        }
        
        .date-text {
            color: #6c757d;
            font-size: 0.9em;
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
                            <li class="breadcrumb-item"><a href="view.php?id=<?php echo $reconciliation_id; ?>">View Reconciliation</a></li>
                            <li class="breadcrumb-item active">Match Transactions</li>
                        </ol>
                    </nav>
                    <h1><i class="bi bi-arrow-left-right"></i> Match Transactions</h1>
                    <p class="header-subtitle"><?php echo htmlspecialchars($reconciliation['account_name']); ?> - <?php echo date('M d, Y', strtotime($reconciliation['reconciliation_date'])); ?></p>
                </div>
                <div class="header-actions">
                    <a href="view.php?id=<?php echo $reconciliation_id; ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Back to Reconciliation
                    </a>
                </div>
            </div>
        </header>

        <main class="content">
            <div class="container-fluid">
                <?php if (isset($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <?php if (isset($success)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle"></i> <?php echo htmlspecialchars($success); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <!-- Debug Information -->
                <div class="alert alert-info mb-3">
                    <h6><i class="bi bi-info-circle"></i> Debug Information</h6>
                    <p class="mb-1"><strong>Bank Transactions Found:</strong> <?php echo count($bank_transactions); ?></p>
                    <p class="mb-1"><strong>POS Transactions Found:</strong> <?php echo count($pos_transactions); ?></p>
                    <p class="mb-0"><strong>Reconciliation Date:</strong> <?php echo $reconciliation['reconciliation_date']; ?></p>
                </div>

                <?php if (empty($bank_transactions) && empty($pos_transactions)): ?>
                <div class="alert alert-warning mb-3">
                    <h6><i class="bi bi-exclamation-triangle"></i> No Data Available</h6>
                    <p class="mb-2">No bank transactions or sales data found for reconciliation. You can:</p>
                    <ul class="mb-2">
                        <li>Import bank statements using the <a href="import.php" class="alert-link">Import</a> feature</li>
                        <li>Add sample data using the <a href="../../add_sample_reconciliation_data.php" class="alert-link">Sample Data Generator</a></li>
                        <li>Check if you have sales data in your POS system</li>
                    </ul>
                </div>
                <?php endif; ?>

                <!-- Match Interface -->
                <div class="match-interface">
                    <h5 class="mb-3"><i class="bi bi-arrow-left-right"></i> Manual Matching</h5>
                    <form method="POST" id="matchForm">
                        <input type="hidden" name="action" value="match">
                        <div class="row">
                            <div class="col-md-5">
                                <label class="form-label">Select Bank Transaction</label>
                                <select class="form-select" name="bank_transaction_id" id="bankTransactionSelect" required>
                                    <option value="">Choose Bank Transaction</option>
                                    <?php if (empty($bank_transactions)): ?>
                                    <option value="" disabled>No bank transactions available</option>
                                    <?php else: ?>
                                    <?php foreach ($bank_transactions as $bt): ?>
                                    <option value="<?php echo $bt['id']; ?>" data-amount="<?php echo $bt['amount']; ?>" data-type="<?php echo $bt['transaction_type']; ?>">
                                        <?php echo date('M d, Y', strtotime($bt['transaction_date'])); ?> - 
                                        <?php echo htmlspecialchars($bt['description']); ?> - 
                                        <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($bt['amount'], 2); ?>
                                    </option>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label">Select POS Transaction</label>
                                <select class="form-select" name="pos_transaction_id" id="posTransactionSelect" required>
                                    <option value="">Choose POS Transaction</option>
                                    <?php if (empty($pos_transactions)): ?>
                                    <option value="" disabled>No POS transactions available</option>
                                    <?php else: ?>
                                    <?php foreach ($pos_transactions as $pt): ?>
                                    <option value="<?php echo $pt['id']; ?>" data-amount="<?php echo $pt['final_amount']; ?>">
                                        <?php echo date('M d, Y', strtotime($pt['sale_date'])); ?> - 
                                        <?php echo htmlspecialchars($pt['customer_name']); ?> - 
                                        <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($pt['final_amount'], 2); ?>
                                    </option>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Match Amount</label>
                                <div class="input-group">
                                    <span class="input-group-text"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?></span>
                                    <input type="number" class="form-control" name="match_amount" id="matchAmount" step="0.01" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row mt-3">
                            <div class="col-md-10">
                                <label class="form-label">Match Notes (Optional)</label>
                                <input type="text" class="form-control" name="match_notes" placeholder="Add notes about this match...">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">&nbsp;</label>
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-check"></i> Match
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Payment Type Filter -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-funnel"></i> Filter by Payment Type</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <label class="form-label">Bank Transaction Payment Type</label>
                                        <select class="form-select" id="bankPaymentTypeFilter" onchange="filterTransactions()">
                                            <option value="">All Payment Types</option>
                                            <?php
                                            $stmt = $conn->query("SELECT * FROM payment_types WHERE is_active = 1 ORDER BY sort_order, display_name");
                                            $payment_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                            foreach ($payment_types as $type):
                                            ?>
                                            <option value="<?php echo $type['id']; ?>">
                                                <?php echo htmlspecialchars($type['display_name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">POS Transaction Payment Type</label>
                                        <select class="form-select" id="posPaymentTypeFilter" onchange="filterTransactions()">
                                            <option value="">All Payment Types</option>
                                            <?php
                                            $stmt = $conn->query("SELECT * FROM payment_types WHERE is_active = 1 ORDER BY sort_order, display_name");
                                            $payment_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                            foreach ($payment_types as $type):
                                            ?>
                                            <option value="<?php echo $type['name']; ?>">
                                                <?php echo htmlspecialchars($type['display_name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="row mt-2">
                                    <div class="col-12">
                                        <button class="btn btn-outline-secondary btn-sm" onclick="clearFilters()">
                                            <i class="bi bi-x-circle"></i> Clear Filters
                                        </button>
                                        <button class="btn btn-outline-primary btn-sm" onclick="autoMatchByPaymentType()">
                                            <i class="bi bi-magic"></i> Auto-Match by Payment Type
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Bank Transactions -->
                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-bank"></i> Bank Transactions</h5>
                                <small class="text-muted">Click to select for matching</small>
                            </div>
                            <div class="card-body" style="max-height: 500px; overflow-y: auto;">
                                <?php if (empty($bank_transactions)): ?>
                                <div class="text-center py-4">
                                    <i class="bi bi-check-circle fs-1 text-success mb-3"></i>
                                    <h6 class="text-success">All Bank Transactions Matched</h6>
                                    <p class="text-muted">No unmatched bank transactions found</p>
                                </div>
                                <?php else: ?>
                                <?php foreach ($bank_transactions as $bt): ?>
                                <div class="transaction-card bank-transaction-card" 
                                     data-payment-type-id="<?php echo $bt['payment_type_id'] ?? ''; ?>"
                                     onclick="selectBankTransaction(<?php echo $bt['id']; ?>, <?php echo $bt['amount']; ?>, '<?php echo $bt['transaction_type']; ?>')">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($bt['description']); ?></div>
                                            <div class="date-text"><?php echo date('M d, Y', strtotime($bt['transaction_date'])); ?></div>
                                            <?php if ($bt['reference_number']): ?>
                                            <small class="text-muted">Ref: <?php echo htmlspecialchars($bt['reference_number']); ?></small>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-end">
                                            <div class="amount-highlight text-<?php echo $bt['transaction_type'] == 'credit' ? 'success' : 'danger'; ?>">
                                                <?php echo $bt['transaction_type'] == 'credit' ? '+' : '-'; ?><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($bt['amount'], 2); ?>
                                            </div>
                                            <?php if ($bt['balance_after']): ?>
                                            <small class="text-muted">Balance: <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($bt['balance_after'], 2); ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- POS Transactions -->
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-receipt"></i> POS Transactions</h5>
                                <small class="text-muted">Click to select for matching</small>
                            </div>
                            <div class="card-body" style="max-height: 500px; overflow-y: auto;">
                                <?php if (empty($pos_transactions)): ?>
                                <div class="text-center py-4">
                                    <i class="bi bi-check-circle fs-1 text-success mb-3"></i>
                                    <h6 class="text-success">All POS Transactions Matched</h6>
                                    <p class="text-muted">No unmatched POS transactions found</p>
                                </div>
                                <?php else: ?>
                                <?php foreach ($pos_transactions as $pt): ?>
                                <?php
                                // Get payment type info for this transaction
                                $stmt = $conn->prepare("SELECT * FROM payment_types WHERE name = ?");
                                $stmt->execute([$pt['mapped_payment_type']]);
                                $payment_type = $stmt->fetch(PDO::FETCH_ASSOC);
                                ?>
                                <div class="transaction-card pos-transaction-card" 
                                     data-payment-method="<?php echo $pt['mapped_payment_type']; ?>"
                                     onclick="selectPosTransaction(<?php echo $pt['id']; ?>, <?php echo $pt['final_amount']; ?>)">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($pt['customer_name']); ?></div>
                                            <div class="date-text"><?php echo date('M d, Y H:i', strtotime($pt['sale_date'])); ?></div>
                                            <small class="text-muted">Cashier: <?php echo htmlspecialchars($pt['cashier_name']); ?></small>
                                        </div>
                                        <div class="text-end">
                                            <div class="amount-highlight text-success">
                                                <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($pt['final_amount'], 2); ?>
                                            </div>
                                            <div class="d-flex align-items-center mt-1">
                                                <?php if ($payment_type): ?>
                                                <i class="<?php echo $payment_type['icon']; ?> me-1" style="color: <?php echo $payment_type['color']; ?>;"></i>
                                                <small class="text-muted"><?php echo htmlspecialchars($payment_type['display_name']); ?></small>
                                                <?php else: ?>
                                                <small class="text-muted">Payment: <?php echo ucfirst($pt['payment_method']); ?></small>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
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
        let selectedBankTransaction = null;
        let selectedPosTransaction = null;

        function selectBankTransaction(id, amount, type) {
            // Remove previous selection
            document.querySelectorAll('.transaction-card').forEach(card => {
                card.classList.remove('selected');
            });
            
            // Add selection to clicked card
            event.currentTarget.classList.add('selected');
            
            selectedBankTransaction = { id, amount, type };
            document.getElementById('bankTransactionSelect').value = id;
            
            // Auto-fill match amount if POS transaction is selected
            if (selectedPosTransaction) {
                document.getElementById('matchAmount').value = Math.min(amount, selectedPosTransaction.amount);
            }
        }

        function selectPosTransaction(id, amount) {
            // Remove previous selection
            document.querySelectorAll('.transaction-card').forEach(card => {
                card.classList.remove('selected');
            });
            
            // Add selection to clicked card
            event.currentTarget.classList.add('selected');
            
            selectedPosTransaction = { id, amount };
            document.getElementById('posTransactionSelect').value = id;
            
            // Auto-fill match amount if bank transaction is selected
            if (selectedBankTransaction) {
                document.getElementById('matchAmount').value = Math.min(selectedBankTransaction.amount, amount);
            }
        }

        // Handle form submission
        document.getElementById('matchForm').addEventListener('submit', function(e) {
            if (!selectedBankTransaction || !selectedPosTransaction) {
                e.preventDefault();
                alert('Please select both a bank transaction and a POS transaction');
                return;
            }
            
            const matchAmount = parseFloat(document.getElementById('matchAmount').value);
            if (matchAmount <= 0) {
                e.preventDefault();
                alert('Please enter a valid match amount');
                return;
            }
        });

        // Payment type filtering functions
        function filterTransactions() {
            const bankFilter = document.getElementById('bankPaymentTypeFilter').value;
            const posFilter = document.getElementById('posPaymentTypeFilter').value;
            
            // Filter bank transactions
            document.querySelectorAll('.bank-transaction-card').forEach(card => {
                const paymentTypeId = card.dataset.paymentTypeId;
                if (!bankFilter || paymentTypeId === bankFilter) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
            
            // Filter POS transactions
            document.querySelectorAll('.pos-transaction-card').forEach(card => {
                const paymentMethod = card.dataset.paymentMethod;
                if (!posFilter || paymentMethod === posFilter) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        function clearFilters() {
            document.getElementById('bankPaymentTypeFilter').value = '';
            document.getElementById('posPaymentTypeFilter').value = '';
            filterTransactions();
        }

        function autoMatchByPaymentType() {
            const bankFilter = document.getElementById('bankPaymentTypeFilter').value;
            const posFilter = document.getElementById('posPaymentTypeFilter').value;
            
            if (!bankFilter || !posFilter) {
                alert('Please select both bank and POS payment types for auto-matching');
                return;
            }
            
            // Find matching transactions by payment type
            const bankCards = document.querySelectorAll('.bank-transaction-card[data-payment-type-id="' + bankFilter + '"]');
            const posCards = document.querySelectorAll('.pos-transaction-card[data-payment-method="' + posFilter + '"]');
            
            if (bankCards.length === 0 || posCards.length === 0) {
                alert('No transactions found for the selected payment types');
                return;
            }
            
            // Auto-select first matching transactions
            if (bankCards.length > 0 && posCards.length > 0) {
                const bankCard = bankCards[0];
                const posCard = posCards[0];
                
                // Trigger selection
                bankCard.click();
                posCard.click();
                
                alert('Auto-matched transactions selected. Review and confirm the match.');
            }
        }
    </script>
</body>
</html>
