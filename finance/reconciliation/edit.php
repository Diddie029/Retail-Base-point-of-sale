<?php
session_start();
require_once '../../include/db.php';
require_once '../../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../auth/login.php');
    exit();
}

// Get user info
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

// Check if user has permission to edit reconciliations
if (!hasPermission('view_finance', $permissions) && !hasPermission('manage_reconciliation', $permissions)) {
    header('Location: ../../dashboard/dashboard.php?error=access_denied');
    exit();
}

// Get reconciliation ID from URL
$reconciliation_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$reconciliation_id) {
    header('Location: ../reconciliation.php?error=invalid_id');
    exit();
}

// Get reconciliation details
$stmt = $conn->prepare("
    SELECT r.*, ba.account_name, ba.bank_name, u.username as reconciled_by_name
    FROM reconciliation_records r
    LEFT JOIN bank_accounts ba ON r.bank_account_id = ba.id
    LEFT JOIN users u ON r.reconciled_by = u.id
    WHERE r.id = ?
");
$stmt->execute([$reconciliation_id]);
$reconciliation = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$reconciliation) {
    header('Location: ../reconciliation.php?error=reconciliation_not_found');
    exit();
}

// Get bank accounts for dropdown
$stmt = $conn->prepare("SELECT id, account_name, bank_name, account_type FROM bank_accounts WHERE is_active = 1 ORDER BY account_name");
$stmt->execute();
$bank_accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bank_account_id = (int)$_POST['bank_account_id'];
    $reconciliation_date = $_POST['reconciliation_date'];
    $opening_balance = (float)$_POST['opening_balance'];
    $closing_balance = (float)$_POST['closing_balance'];
    $status = $_POST['status'];
    $notes = $_POST['notes'];
    
    // Calculate expected balance and difference
    $expected_balance = $opening_balance; // This would normally be calculated from POS transactions
    $difference_amount = $closing_balance - $expected_balance;
    
    try {
        $stmt = $conn->prepare("
            UPDATE reconciliation_records 
            SET bank_account_id = ?, 
                reconciliation_date = ?, 
                opening_balance = ?, 
                closing_balance = ?, 
                expected_balance = ?, 
                difference_amount = ?, 
                status = ?, 
                notes = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        
        $stmt->execute([
            $bank_account_id,
            $reconciliation_date,
            $opening_balance,
            $closing_balance,
            $expected_balance,
            $difference_amount,
            $status,
            $notes,
            $reconciliation_id
        ]);
        
        // If status changed to completed, set completed_at
        if ($status === 'completed') {
            $stmt = $conn->prepare("UPDATE reconciliation_records SET completed_at = NOW() WHERE id = ?");
            $stmt->execute([$reconciliation_id]);
        }
        
        header('Location: ../reconciliation.php?success=reconciliation_updated');
        exit();
        
    } catch (PDOException $e) {
        $error_message = "Error updating reconciliation: " . $e->getMessage();
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Reconciliation - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: #6366f1;
            --sidebar-color: #1e293b;
        }
        
        .form-section {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }
        
        .form-header {
            border-bottom: 2px solid #f1f5f9;
            padding-bottom: 1rem;
            margin-bottom: 2rem;
        }
        
        .status-badge {
            font-size: 0.875rem;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 500;
        }
        
        .status-draft { background: #f1f5f9; color: #64748b; }
        .status-in_progress { background: #fef3c7; color: #d97706; }
        .status-completed { background: #d1fae5; color: #059669; }
        .status-cancelled { background: #fee2e2; color: #dc2626; }
        
        .balance-display {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 1rem;
            margin: 1rem 0;
        }
        
        .balance-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .balance-item:last-child {
            border-bottom: none;
            font-weight: 600;
            font-size: 1.1rem;
        }
        
        .difference-positive {
            color: #059669;
        }
        
        .difference-negative {
            color: #dc2626;
        }
        
        .difference-zero {
            color: #64748b;
        }
    </style>
</head>
<body>
    <?php include '../../include/navmenu.php'; ?>

    <div class="main-content">
        <header class="page-header">
            <div class="container-fluid">
                <div class="header-content">
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="../../dashboard/dashboard.php">Dashboard</a></li>
                            <li class="breadcrumb-item"><a href="../reconciliation.php">Finance</a></li>
                            <li class="breadcrumb-item"><a href="../reconciliation.php">Reconciliation</a></li>
                            <li class="breadcrumb-item active">Edit Reconciliation</li>
                        </ol>
                    </nav>
                    <h1><i class="bi bi-pencil-square"></i> Edit Reconciliation</h1>
                    <p class="header-subtitle">Update reconciliation details and status</p>
                </div>
                <div class="header-actions">
                    <div class="d-flex align-items-center gap-2">
                        <a href="documentation.php" class="btn btn-outline-info btn-sm" title="View Documentation">
                            <i class="bi bi-book"></i> Docs
                        </a>
                        <a href="../reconciliation.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left"></i> Back to Reconciliation
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <main class="content">
            <div class="container-fluid">
                <?php if (isset($error_message)): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle"></i>
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
                <?php endif; ?>

                <form method="POST" class="reconciliation-form">
                    <div class="row">
                        <div class="col-lg-8">
                            <!-- Basic Information -->
                            <div class="form-section">
                                <div class="form-header">
                                    <h5><i class="bi bi-info-circle"></i> Basic Information</h5>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="bank_account_id" class="form-label">Bank Account <span class="text-danger">*</span></label>
                                        <select class="form-select" id="bank_account_id" name="bank_account_id" required>
                                            <option value="">Select Bank Account</option>
                                            <?php foreach ($bank_accounts as $account): ?>
                                            <option value="<?php echo $account['id']; ?>" 
                                                    <?php echo $account['id'] == $reconciliation['bank_account_id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($account['account_name'] . ' - ' . $account['bank_name']); ?>
                                                (<?php echo ucfirst($account['account_type']); ?>)
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label for="reconciliation_date" class="form-label">Reconciliation Date <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" id="reconciliation_date" name="reconciliation_date" 
                                               value="<?php echo htmlspecialchars($reconciliation['reconciliation_date']); ?>" required>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="opening_balance" class="form-label">Opening Balance <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text">KES</span>
                                            <input type="number" class="form-control" id="opening_balance" name="opening_balance" 
                                                   step="0.01" value="<?php echo htmlspecialchars($reconciliation['opening_balance']); ?>" required>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label for="closing_balance" class="form-label">Closing Balance <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text">KES</span>
                                            <input type="number" class="form-control" id="closing_balance" name="closing_balance" 
                                                   step="0.01" value="<?php echo htmlspecialchars($reconciliation['closing_balance']); ?>" required>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="status" class="form-label">Status <span class="text-danger">*</span></label>
                                    <select class="form-select" id="status" name="status" required>
                                        <option value="draft" <?php echo $reconciliation['status'] === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                        <option value="in_progress" <?php echo $reconciliation['status'] === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                        <option value="completed" <?php echo $reconciliation['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                        <option value="cancelled" <?php echo $reconciliation['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="notes" class="form-label">Notes</label>
                                    <textarea class="form-control" id="notes" name="notes" rows="4" 
                                              placeholder="Add any notes about this reconciliation..."><?php echo htmlspecialchars($reconciliation['notes']); ?></textarea>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-lg-4">
                            <!-- Balance Summary -->
                            <div class="form-section">
                                <div class="form-header">
                                    <h5><i class="bi bi-calculator"></i> Balance Summary</h5>
                                </div>
                                
                                <div class="balance-display">
                                    <div class="balance-item">
                                        <span>Opening Balance:</span>
                                        <span id="display-opening">KES <?php echo number_format($reconciliation['opening_balance'], 2); ?></span>
                                    </div>
                                    <div class="balance-item">
                                        <span>Closing Balance:</span>
                                        <span id="display-closing">KES <?php echo number_format($reconciliation['closing_balance'], 2); ?></span>
                                    </div>
                                    <div class="balance-item">
                                        <span>Expected Balance:</span>
                                        <span id="display-expected">KES <?php echo number_format($reconciliation['expected_balance'], 2); ?></span>
                                    </div>
                                    <div class="balance-item">
                                        <span>Difference:</span>
                                        <span id="display-difference" class="<?php 
                                            echo $reconciliation['difference_amount'] > 0 ? 'difference-positive' : 
                                                ($reconciliation['difference_amount'] < 0 ? 'difference-negative' : 'difference-zero'); 
                                        ?>">
                                            KES <?php echo number_format($reconciliation['difference_amount'], 2); ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <div class="mt-3">
                                    <small class="text-muted">
                                        <i class="bi bi-info-circle"></i>
                                        Expected balance is calculated from POS transactions. 
                                        Difference shows variance between closing and expected balance.
                                    </small>
                                </div>
                            </div>
                            
                            <!-- Reconciliation Info -->
                            <div class="form-section">
                                <div class="form-header">
                                    <h5><i class="bi bi-info-square"></i> Reconciliation Info</h5>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Current Status</label>
                                    <div>
                                        <span class="status-badge status-<?php echo $reconciliation['status']; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $reconciliation['status'])); ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Reconciled By</label>
                                    <p class="form-control-plaintext"><?php echo htmlspecialchars($reconciliation['reconciled_by_name']); ?></p>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Created</label>
                                    <p class="form-control-plaintext"><?php echo date('M j, Y g:i A', strtotime($reconciliation['created_at'])); ?></p>
                                </div>
                                
                                <?php if ($reconciliation['completed_at']): ?>
                                <div class="mb-3">
                                    <label class="form-label">Completed</label>
                                    <p class="form-control-plaintext"><?php echo date('M j, Y g:i A', strtotime($reconciliation['completed_at'])); ?></p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Action Buttons -->
                    <div class="row">
                        <div class="col-12">
                            <div class="form-section">
                                <div class="d-flex justify-content-between">
                                    <a href="../reconciliation.php" class="btn btn-outline-secondary">
                                        <i class="bi bi-arrow-left"></i> Cancel
                                    </a>
                                    <div>
                                        <button type="button" class="btn btn-outline-info me-2" onclick="calculateBalances()">
                                            <i class="bi bi-calculator"></i> Recalculate
                                        </button>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-check"></i> Update Reconciliation
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-calculate balances when values change
        document.getElementById('opening_balance').addEventListener('input', calculateBalances);
        document.getElementById('closing_balance').addEventListener('input', calculateBalances);
        
        function calculateBalances() {
            const opening = parseFloat(document.getElementById('opening_balance').value) || 0;
            const closing = parseFloat(document.getElementById('closing_balance').value) || 0;
            const expected = opening; // In a real system, this would be calculated from POS transactions
            
            const difference = closing - expected;
            
            // Update display
            document.getElementById('display-opening').textContent = 'KES ' + opening.toLocaleString('en-KE', {minimumFractionDigits: 2});
            document.getElementById('display-closing').textContent = 'KES ' + closing.toLocaleString('en-KE', {minimumFractionDigits: 2});
            document.getElementById('display-expected').textContent = 'KES ' + expected.toLocaleString('en-KE', {minimumFractionDigits: 2});
            
            const differenceElement = document.getElementById('display-difference');
            differenceElement.textContent = 'KES ' + difference.toLocaleString('en-KE', {minimumFractionDigits: 2});
            
            // Update difference styling
            differenceElement.className = 'difference-' + (difference > 0 ? 'positive' : (difference < 0 ? 'negative' : 'zero'));
        }
        
        // Form validation
        document.querySelector('.reconciliation-form').addEventListener('submit', function(e) {
            const opening = parseFloat(document.getElementById('opening_balance').value);
            const closing = parseFloat(document.getElementById('closing_balance').value);
            
            if (isNaN(opening) || isNaN(closing)) {
                e.preventDefault();
                alert('Please enter valid numeric values for balances.');
                return;
            }
            
            if (opening < 0 || closing < 0) {
                e.preventDefault();
                alert('Balances cannot be negative.');
                return;
            }
        });
        
        // Initialize calculations on page load
        calculateBalances();
    </script>
</body>
</html>
