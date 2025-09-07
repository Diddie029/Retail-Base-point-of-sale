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

// Check if user can manage accounts (for add/edit operations)
$canManageAccounts = hasPermission('view_finance', $permissions) || hasPermission('manage_reconciliation', $permissions);

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$action = $_GET['action'] ?? 'list';
$account_id = $_GET['id'] ?? null;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if ($action == 'add' || $action == 'edit') {
        $account_name = $_POST['account_name'] ?? '';
        $account_number = $_POST['account_number'] ?? '';
        $bank_name = $_POST['bank_name'] ?? '';
        $account_type = $_POST['account_type'] ?? 'checking';
        $opening_balance = $_POST['opening_balance'] ?? 0;
        
        if (empty($account_name)) {
            $error = "Account name is required";
        } else {
            try {
                if ($action == 'add') {
                    $stmt = $conn->prepare("
                        INSERT INTO bank_accounts (account_name, account_number, bank_name, account_type, opening_balance, current_balance, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$account_name, $account_number, $bank_name, $account_type, $opening_balance, $opening_balance, $user_id]);
                    $success = "Bank account added successfully";
                } else {
                    $stmt = $conn->prepare("
                        UPDATE bank_accounts 
                        SET account_name = ?, account_number = ?, bank_name = ?, account_type = ?, opening_balance = ?
                        WHERE id = ? AND created_by = ?
                    ");
                    $stmt->execute([$account_name, $account_number, $bank_name, $account_type, $opening_balance, $account_id, $user_id]);
                    $success = "Bank account updated successfully";
                }
                $action = 'list';
            } catch (Exception $e) {
                $error = "Error: " . $e->getMessage();
            }
        }
    }
}

// Get accounts list
$accounts = [];
$stmt = $conn->query("
    SELECT ba.*, u.username as created_by_name
    FROM bank_accounts ba
    LEFT JOIN users u ON ba.created_by = u.id
    ORDER BY ba.created_at DESC
");
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get account details for edit/view
$account = null;
if ($account_id && ($action == 'edit' || $action == 'view')) {
    $stmt = $conn->prepare("
        SELECT ba.*, u.username as created_by_name
        FROM bank_accounts ba
        LEFT JOIN users u ON ba.created_by = u.id
        WHERE ba.id = ?
    ");
    $stmt->execute([$account_id]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$account) {
        $error = "Account not found";
        $action = 'list';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bank Accounts - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
        
        .account-form {
            max-width: 600px;
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
                            <li class="breadcrumb-item active">Bank Accounts</li>
                        </ol>
                    </nav>
                    <h1><i class="bi bi-bank"></i> Bank Accounts</h1>
                    <p class="header-subtitle">Manage your bank accounts for reconciliation</p>
                </div>
                <div class="header-actions">
                    <div class="d-flex align-items-center gap-2">
                        <a href="documentation.php" class="btn btn-outline-info btn-sm" title="View Documentation">
                            <i class="bi bi-book"></i> Docs
                        </a>
                        <?php if ($action == 'list'): ?>
                        <a href="?action=add" class="btn btn-primary">
                            <i class="bi bi-plus"></i> Add Account
                        </a>
                        <?php else: ?>
                        <a href="?" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left"></i> Back to List
                        </a>
                        <?php endif; ?>
                    </div>
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

                <?php if ($action == 'list'): ?>
                <!-- Accounts List -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-list"></i> All Bank Accounts</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($accounts)): ?>
                                <div class="text-center py-5">
                                    <i class="bi bi-bank fs-1 text-muted mb-3"></i>
                                    <h5 class="text-muted">No Bank Accounts</h5>
                                    <p class="text-muted">Add your first bank account to start reconciliation</p>
                                    <?php if ($canManageAccounts): ?>
                                    <a href="?action=add" class="btn btn-primary">
                                        <i class="bi bi-plus"></i> Add Bank Account
                                    </a>
                                    <?php endif; ?>
                                </div>
                                <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Account Name</th>
                                                <th>Bank</th>
                                                <th>Account Number</th>
                                                <th>Type</th>
                                                <th>Current Balance</th>
                                                <th>Status</th>
                                                <th>Created By</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($accounts as $acc): ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-semibold"><?php echo htmlspecialchars($acc['account_name']); ?></div>
                                                </td>
                                                <td><?php echo htmlspecialchars($acc['bank_name'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($acc['account_number'] ?? 'N/A'); ?></td>
                                                <td>
                                                    <span class="badge bg-<?php echo $acc['account_type'] == 'checking' ? 'primary' : ($acc['account_type'] == 'savings' ? 'success' : 'info'); ?> status-badge">
                                                        <?php echo ucfirst($acc['account_type']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="fw-bold"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($acc['current_balance'], 2); ?></div>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php echo $acc['is_active'] ? 'success' : 'danger'; ?> status-badge">
                                                        <?php echo $acc['is_active'] ? 'Active' : 'Inactive'; ?>
                                                    </span>
                                                </td>
                                                <td><?php echo htmlspecialchars($acc['created_by_name']); ?></td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <a href="?action=view&id=<?php echo $acc['id']; ?>" class="btn btn-outline-primary">
                                                            <i class="bi bi-eye"></i>
                                                        </a>
                                                        <?php if ($canManageAccounts): ?>
                                                        <a href="?action=edit&id=<?php echo $acc['id']; ?>" class="btn btn-outline-warning">
                                                            <i class="bi bi-pencil"></i>
                                                        </a>
                                                        <button class="btn btn-outline-danger" onclick="deleteAccount(<?php echo $acc['id']; ?>)">
                                                            <i class="bi bi-trash"></i>
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

                <?php elseif ($action == 'add' || $action == 'edit'): ?>
                <!-- Add/Edit Form -->
                <div class="row justify-content-center">
                    <div class="col-md-8">
                        <div class="card account-form">
                            <div class="card-header">
                                <h5 class="mb-0">
                                    <i class="bi bi-<?php echo $action == 'add' ? 'plus' : 'pencil'; ?>"></i>
                                    <?php echo $action == 'add' ? 'Add' : 'Edit'; ?> Bank Account
                                </h5>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="account_name" class="form-label">Account Name *</label>
                                            <input type="text" class="form-control" id="account_name" name="account_name" 
                                                   value="<?php echo htmlspecialchars($account['account_name'] ?? ''); ?>" required>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label for="bank_name" class="form-label">Bank Name</label>
                                            <input type="text" class="form-control" id="bank_name" name="bank_name" 
                                                   value="<?php echo htmlspecialchars($account['bank_name'] ?? ''); ?>">
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="account_number" class="form-label">Account Number</label>
                                            <input type="text" class="form-control" id="account_number" name="account_number" 
                                                   value="<?php echo htmlspecialchars($account['account_number'] ?? ''); ?>">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label for="account_type" class="form-label">Account Type</label>
                                            <select class="form-select" id="account_type" name="account_type" required>
                                                <option value="checking" <?php echo ($account['account_type'] ?? '') == 'checking' ? 'selected' : ''; ?>>Checking</option>
                                                <option value="savings" <?php echo ($account['account_type'] ?? '') == 'savings' ? 'selected' : ''; ?>>Savings</option>
                                                <option value="cash_drawer" <?php echo ($account['account_type'] ?? '') == 'cash_drawer' ? 'selected' : ''; ?>>Cash Drawer</option>
                                                <option value="credit_card" <?php echo ($account['account_type'] ?? '') == 'credit_card' ? 'selected' : ''; ?>>Credit Card</option>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="opening_balance" class="form-label">Opening Balance</label>
                                            <div class="input-group">
                                                <span class="input-group-text"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?></span>
                                                <input type="number" class="form-control" id="opening_balance" name="opening_balance" 
                                                       step="0.01" value="<?php echo $account['opening_balance'] ?? 0; ?>">
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="d-flex justify-content-between">
                                        <a href="?" class="btn btn-outline-secondary">
                                            <i class="bi bi-arrow-left"></i> Cancel
                                        </a>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-check"></i> <?php echo $action == 'add' ? 'Add Account' : 'Update Account'; ?>
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <?php elseif ($action == 'view' && $account): ?>
                <!-- View Account Details -->
                <div class="row justify-content-center">
                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">
                                    <i class="bi bi-eye"></i> Account Details
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6>Account Information</h6>
                                        <table class="table table-borderless">
                                            <tr>
                                                <td><strong>Account Name:</strong></td>
                                                <td><?php echo htmlspecialchars($account['account_name']); ?></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Bank Name:</strong></td>
                                                <td><?php echo htmlspecialchars($account['bank_name'] ?? 'N/A'); ?></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Account Number:</strong></td>
                                                <td><?php echo htmlspecialchars($account['account_number'] ?? 'N/A'); ?></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Account Type:</strong></td>
                                                <td>
                                                    <span class="badge bg-<?php echo $account['account_type'] == 'checking' ? 'primary' : ($account['account_type'] == 'savings' ? 'success' : 'info'); ?> status-badge">
                                                        <?php echo ucfirst($account['account_type']); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        </table>
                                    </div>
                                    <div class="col-md-6">
                                        <h6>Balance Information</h6>
                                        <table class="table table-borderless">
                                            <tr>
                                                <td><strong>Opening Balance:</strong></td>
                                                <td><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($account['opening_balance'], 2); ?></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Current Balance:</strong></td>
                                                <td class="fw-bold"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($account['current_balance'], 2); ?></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Status:</strong></td>
                                                <td>
                                                    <span class="badge bg-<?php echo $account['is_active'] ? 'success' : 'danger'; ?> status-badge">
                                                        <?php echo $account['is_active'] ? 'Active' : 'Inactive'; ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td><strong>Created By:</strong></td>
                                                <td><?php echo htmlspecialchars($account['created_by_name']); ?></td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                                
                                <div class="d-flex justify-content-between mt-4">
                                    <a href="?" class="btn btn-outline-secondary">
                                        <i class="bi bi-arrow-left"></i> Back to List
                                    </a>
                                    <div>
                                        <a href="?action=edit&id=<?php echo $account['id']; ?>" class="btn btn-warning">
                                            <i class="bi bi-pencil"></i> Edit
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function deleteAccount(accountId) {
            if (confirm('Are you sure you want to delete this bank account? This action cannot be undone.')) {
                // Create a form to submit the delete request
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'delete_account.php';
                
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'account_id';
                input.value = accountId;
                
                form.appendChild(input);
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>
</html>
