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

// Get bank accounts
$bank_accounts = [];
$stmt = $conn->query("SELECT * FROM bank_accounts WHERE is_active = 1 ORDER BY account_name");
$bank_accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $bank_account_id = $_POST['bank_account_id'] ?? '';
    $reconciliation_date = $_POST['reconciliation_date'] ?? '';
    $opening_balance = $_POST['opening_balance'] ?? 0;
    $closing_balance = $_POST['closing_balance'] ?? 0;
    $notes = $_POST['notes'] ?? '';
    
    if (empty($bank_account_id) || empty($reconciliation_date)) {
        $error = "Bank account and reconciliation date are required";
    } else {
        try {
            // Calculate expected balance (simplified - in real implementation, this would be calculated from transactions)
            $expected_balance = $opening_balance; // This should be calculated from actual transactions
            $difference_amount = $closing_balance - $expected_balance;
            
            $stmt = $conn->prepare("
                INSERT INTO reconciliation_records (bank_account_id, reconciliation_date, opening_balance, closing_balance, expected_balance, difference_amount, reconciled_by, notes, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft')
            ");
            $stmt->execute([$bank_account_id, $reconciliation_date, $opening_balance, $closing_balance, $expected_balance, $difference_amount, $user_id, $notes]);
            
            $reconciliation_id = $conn->lastInsertId();
            header("Location: view.php?id=" . $reconciliation_id);
            exit();
        } catch (Exception $e) {
            $error = "Error creating reconciliation: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Reconciliation - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
        
        .reconciliation-form {
            max-width: 600px;
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
                            <li class="breadcrumb-item active">New Reconciliation</li>
                        </ol>
                    </nav>
                    <h1><i class="bi bi-plus-circle"></i> New Reconciliation</h1>
                    <p class="header-subtitle">Start a new account reconciliation process</p>
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
                <?php if (isset($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <div class="row justify-content-center">
                    <div class="col-md-8">
                        <div class="card reconciliation-form">
                            <div class="card-header">
                                <h5 class="mb-0">
                                    <i class="bi bi-check2-square"></i> Reconciliation Details
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($bank_accounts)): ?>
                                <div class="alert alert-warning">
                                    <i class="bi bi-exclamation-triangle"></i>
                                    <strong>No Bank Accounts Found</strong><br>
                                    You need to add at least one bank account before creating a reconciliation.
                                    <div class="mt-3">
                                        <a href="accounts.php?action=add" class="btn btn-warning">
                                            <i class="bi bi-plus"></i> Add Bank Account
                                        </a>
                                    </div>
                                </div>
                                <?php else: ?>
                                <form method="POST">
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="bank_account_id" class="form-label">Bank Account *</label>
                                            <select class="form-select" id="bank_account_id" name="bank_account_id" required>
                                                <option value="">Select Bank Account</option>
                                                <?php foreach ($bank_accounts as $account): ?>
                                                <option value="<?php echo $account['id']; ?>">
                                                    <?php echo htmlspecialchars($account['account_name']); ?> 
                                                    (<?php echo htmlspecialchars($account['bank_name'] ?? 'N/A'); ?>)
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label for="reconciliation_date" class="form-label">Reconciliation Date *</label>
                                            <input type="date" class="form-control" id="reconciliation_date" name="reconciliation_date" 
                                                   value="<?php echo date('Y-m-d'); ?>" required>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="opening_balance" class="form-label">Opening Balance</label>
                                            <div class="input-group">
                                                <span class="input-group-text"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?></span>
                                                <input type="number" class="form-control" id="opening_balance" name="opening_balance" 
                                                       step="0.01" value="0">
                                            </div>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label for="closing_balance" class="form-label">Closing Balance</label>
                                            <div class="input-group">
                                                <span class="input-group-text"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?></span>
                                                <input type="number" class="form-control" id="closing_balance" name="closing_balance" 
                                                       step="0.01" value="0">
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="notes" class="form-label">Notes</label>
                                        <textarea class="form-control" id="notes" name="notes" rows="3" 
                                                  placeholder="Optional notes about this reconciliation..."></textarea>
                                    </div>
                                    
                                    <div class="d-flex justify-content-between">
                                        <a href="../reconciliation.php" class="btn btn-outline-secondary">
                                            <i class="bi bi-arrow-left"></i> Cancel
                                        </a>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-check"></i> Create Reconciliation
                                        </button>
                                    </div>
                                </form>
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
        // Auto-populate opening balance when account is selected
        document.getElementById('bank_account_id').addEventListener('change', function() {
            const accountId = this.value;
            if (accountId) {
                // In a real implementation, you would fetch the current balance via AJAX
                // For now, we'll just show a placeholder
                document.getElementById('opening_balance').placeholder = 'Current account balance will be loaded...';
            }
        });
    </script>
</body>
</html>
