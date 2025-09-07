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
$budget_id = $_GET['id'] ?? 0;

if (!$budget_id) {
    header('Location: budget.php');
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get budget settings
$budget_settings = [];
try {
    $stmt = $conn->query("SELECT setting_key, setting_value FROM budget_settings");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $budget_settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    $budget_settings = ['default_currency' => 'KES'];
}

// Handle budget item updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_actual_amount') {
        try {
            $item_id = (int)$_POST['item_id'];
            $actual_amount = (float)$_POST['actual_amount'];
            
            $stmt = $conn->prepare("UPDATE budget_items SET actual_amount = ? WHERE id = ? AND budget_id = ?");
            $stmt->execute([$actual_amount, $item_id, $budget_id]);
            
            // Update budget total
            updateBudgetTotals($conn, $budget_id);
            
            $success_message = "Budget item updated successfully!";
        } catch (Exception $e) {
            $error_message = "Error updating budget item: " . $e->getMessage();
        }
    } elseif ($_POST['action'] === 'add_transaction') {
        try {
            $item_id = (int)$_POST['item_id'];
            $amount = (float)$_POST['amount'];
            $description = trim($_POST['description']);
            $transaction_date = $_POST['transaction_date'];
            
            $stmt = $conn->prepare("
                INSERT INTO budget_transactions (budget_id, budget_item_id, amount, transaction_date, description, created_by) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$budget_id, $item_id, $amount, $transaction_date, $description, $user_id]);
            
            // Update actual amount in budget item
            $stmt = $conn->prepare("
                UPDATE budget_items SET actual_amount = (
                    SELECT COALESCE(SUM(amount), 0) 
                    FROM budget_transactions 
                    WHERE budget_item_id = ?
                ) 
                WHERE id = ?
            ");
            $stmt->execute([$item_id, $item_id]);
            
            // Update budget total
            updateBudgetTotals($conn, $budget_id);
            
            $success_message = "Expense added successfully!";
        } catch (Exception $e) {
            $error_message = "Error adding expense: " . $e->getMessage();
        }
    }
}

function updateBudgetTotals($conn, $budget_id) {
    $stmt = $conn->prepare("
        UPDATE budgets SET 
            total_budget_amount = (SELECT COALESCE(SUM(budgeted_amount), 0) FROM budget_items WHERE budget_id = ?),
            total_actual_amount = (SELECT COALESCE(SUM(actual_amount), 0) FROM budget_items WHERE budget_id = ?)
        WHERE id = ?
    ");
    $stmt->execute([$budget_id, $budget_id, $budget_id]);
}

// Get budget details
try {
    $stmt = $conn->prepare("
        SELECT b.*, u.username as created_by_name, u2.username as approved_by_name
        FROM budgets b
        LEFT JOIN users u ON b.created_by = u.id
        LEFT JOIN users u2 ON b.approved_by = u2.id
        WHERE b.id = ?
    ");
    $stmt->execute([$budget_id]);
    $budget = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$budget) {
        header('Location: budget.php');
        exit();
    }
    
} catch (Exception $e) {
    die("Error loading budget: " . $e->getMessage());
}

// Get budget items with categories
$budget_items = [];
try {
    $stmt = $conn->prepare("
        SELECT bi.*, bc.name as category_name, bc.color as category_color, bc.icon as category_icon
        FROM budget_items bi
        LEFT JOIN budget_categories bc ON bi.category_id = bc.id
        WHERE bi.budget_id = ?
        ORDER BY bc.name, bi.name
    ");
    $stmt->execute([$budget_id]);
    $budget_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $budget_items = [];
}

// Get recent transactions
$recent_transactions = [];
try {
    $stmt = $conn->prepare("
        SELECT bt.*, bi.name as item_name, bc.name as category_name, u.username as created_by_name
        FROM budget_transactions bt
        LEFT JOIN budget_items bi ON bt.budget_item_id = bi.id
        LEFT JOIN budget_categories bc ON bi.category_id = bc.id
        LEFT JOIN users u ON bt.created_by = u.id
        WHERE bt.budget_id = ?
        ORDER BY bt.transaction_date DESC, bt.created_at DESC
        LIMIT 20
    ");
    $stmt->execute([$budget_id]);
    $recent_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $recent_transactions = [];
}

// Calculate budget statistics
$total_budgeted = $budget['total_budget_amount'];
$total_actual = $budget['total_actual_amount'];
$total_variance = $total_actual - $total_budgeted;
$variance_percentage = $total_budgeted > 0 ? ($total_variance / $total_budgeted) * 100 : 0;
$days_remaining = max(0, (strtotime($budget['end_date']) - time()) / (24 * 60 * 60));
$budget_duration = (strtotime($budget['end_date']) - strtotime($budget['start_date'])) / (24 * 60 * 60);
$days_elapsed = max(0, $budget_duration - $days_remaining);
$time_progress = $budget_duration > 0 ? ($days_elapsed / $budget_duration) * 100 : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($budget['name']); ?> - Budget Details</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .budget-progress {
            position: relative;
            height: 8px;
            background: #e9ecef;
            border-radius: 4px;
            overflow: hidden;
        }
        .budget-progress-bar {
            height: 100%;
            transition: width 0.3s ease;
        }
        .category-icon {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            color: white;
            font-size: 18px;
        }
        .variance-positive { color: #dc3545; }
        .variance-negative { color: #198754; }
        .item-card {
            transition: all 0.3s ease;
            border-left: 4px solid transparent;
        }
        .item-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .progress-ring {
            transform: rotate(-90deg);
        }
        .quick-stats {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
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
                            <li class="breadcrumb-item"><a href="budget.php">Budget Management</a></li>
                            <li class="breadcrumb-item active"><?php echo htmlspecialchars($budget['name']); ?></li>
                        </ol>
                    </nav>
                    <div class="d-flex align-items-center gap-3">
                        <div>
                            <h1><i class="bi bi-clipboard-data"></i> <?php echo htmlspecialchars($budget['name']); ?></h1>
                            <p class="header-subtitle">
                                <?php echo ucfirst($budget['budget_type']); ?> Budget • 
                                <?php echo date('M d, Y', strtotime($budget['start_date'])); ?> - <?php echo date('M d, Y', strtotime($budget['end_date'])); ?>
                            </p>
                        </div>
                        <div class="ms-auto">
                            <span class="badge bg-<?php echo $budget['status'] === 'active' ? 'success' : ($budget['status'] === 'draft' ? 'warning' : 'secondary'); ?> fs-6">
                                <?php echo ucfirst($budget['status']); ?>
                            </span>
                        </div>
                    </div>
                </div>
                <div class="header-actions">
                    <div class="btn-group">
                        <a href="budget-edit.php?id=<?php echo $budget_id; ?>" class="btn btn-primary">
                            <i class="bi bi-pencil me-1"></i> Edit Budget
                        </a>
                        <button type="button" class="btn btn-outline-secondary" onclick="exportBudgetDetails()">
                            <i class="bi bi-download me-1"></i> Export
                        </button>
                        <button type="button" class="btn btn-outline-success" onclick="window.print()">
                            <i class="bi bi-printer me-1"></i> Print
                        </button>
                    </div>
                </div>
            </div>
        </header>

        <main class="content">
            <div class="container-fluid">
                <?php if (isset($success_message)): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="bi bi-check-circle me-2"></i><?php echo $success_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <?php if (isset($error_message)): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="bi bi-exclamation-triangle me-2"></i><?php echo $error_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <!-- Budget Overview Cards -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="card quick-stats">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="mb-1 opacity-75">Total Budget</h6>
                                        <h3 class="mb-0"><?php echo htmlspecialchars($budget_settings['default_currency'] ?? 'KES'); ?> <?php echo number_format($total_budgeted, 2); ?></h3>
                                    </div>
                                    <div>
                                        <i class="bi bi-wallet2 fs-1 opacity-75"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="card <?php echo $total_actual > $total_budgeted ? 'bg-danger' : 'bg-success'; ?> text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="mb-1 opacity-75">Actual Spent</h6>
                                        <h3 class="mb-0"><?php echo htmlspecialchars($budget_settings['default_currency'] ?? 'KES'); ?> <?php echo number_format($total_actual, 2); ?></h3>
                                        <small class="opacity-75">
                                            <?php echo $total_budgeted > 0 ? number_format(($total_actual / $total_budgeted) * 100, 1) : 0; ?>% of budget
                                        </small>
                                    </div>
                                    <div>
                                        <i class="bi bi-cash-stack fs-1 opacity-75"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="card <?php echo $total_variance >= 0 ? 'bg-warning' : 'bg-info'; ?> text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="mb-1 opacity-75">Variance</h6>
                                        <h3 class="mb-0"><?php echo htmlspecialchars($budget_settings['default_currency'] ?? 'KES'); ?> <?php echo number_format(abs($total_variance), 2); ?></h3>
                                        <small class="opacity-75">
                                            <?php echo $total_variance >= 0 ? 'Over budget' : 'Under budget'; ?>
                                        </small>
                                    </div>
                                    <div>
                                        <i class="bi bi-arrow-<?php echo $total_variance >= 0 ? 'up' : 'down'; ?> fs-1 opacity-75"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="card bg-secondary text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="mb-1 opacity-75">Days Remaining</h6>
                                        <h3 class="mb-0"><?php echo ceil($days_remaining); ?></h3>
                                        <small class="opacity-75">
                                            <?php echo number_format($time_progress, 1); ?>% time elapsed
                                        </small>
                                    </div>
                                    <div>
                                        <i class="bi bi-calendar-event fs-1 opacity-75"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Progress Overview -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col-md-8">
                                        <h6 class="mb-3">Budget Progress Overview</h6>
                                        <div class="budget-progress mb-2">
                                            <div class="budget-progress-bar bg-<?php echo $total_actual > $total_budgeted ? 'danger' : ($total_actual > $total_budgeted * 0.8 ? 'warning' : 'success'); ?>" 
                                                 style="width: <?php echo min(($total_actual / max($total_budgeted, 1)) * 100, 100); ?>%"></div>
                                        </div>
                                        <div class="d-flex justify-content-between text-sm">
                                            <span>Spent: <?php echo htmlspecialchars($budget_settings['default_currency'] ?? 'KES'); ?> <?php echo number_format($total_actual, 2); ?></span>
                                            <span>Budget: <?php echo htmlspecialchars($budget_settings['default_currency'] ?? 'KES'); ?> <?php echo number_format($total_budgeted, 2); ?></span>
                                        </div>
                                    </div>
                                    <div class="col-md-4 text-center">
                                        <div class="position-relative d-inline-block">
                                            <svg width="120" height="120" class="progress-ring">
                                                <circle cx="60" cy="60" r="50" fill="none" stroke="#e9ecef" stroke-width="8"/>
                                                <circle cx="60" cy="60" r="50" fill="none" stroke="<?php echo $total_actual > $total_budgeted ? '#dc3545' : '#198754'; ?>" 
                                                        stroke-width="8" stroke-dasharray="314" 
                                                        stroke-dashoffset="<?php echo 314 - (min(($total_actual / max($total_budgeted, 1)) * 100, 100) * 314 / 100); ?>"/>
                                            </svg>
                                            <div class="position-absolute top-50 start-50 translate-middle text-center">
                                                <div class="fs-4 fw-bold"><?php echo number_format(min(($total_actual / max($total_budgeted, 1)) * 100, 100), 1); ?>%</div>
                                                <div class="small text-muted">Used</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Budget Items and Transactions -->
                <div class="row">
                    <div class="col-xl-8 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h6 class="mb-0"><i class="bi bi-list-ul me-2"></i>Budget Items</h6>
                                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addExpenseModal">
                                        <i class="bi bi-plus-circle me-1"></i> Add Expense
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if (empty($budget_items)): ?>
                                <div class="text-center py-4">
                                    <i class="bi bi-inbox fs-1 text-muted mb-3"></i>
                                    <h5 class="text-muted">No Budget Items</h5>
                                    <p class="text-muted">This budget doesn't have any specific items defined.</p>
                                </div>
                                <?php else: ?>
                                <div class="row">
                                    <?php foreach ($budget_items as $item): ?>
                                    <?php
                                        $item_progress = $item['budgeted_amount'] > 0 ? ($item['actual_amount'] / $item['budgeted_amount']) * 100 : 0;
                                        $item_variance = $item['actual_amount'] - $item['budgeted_amount'];
                                        $progress_class = $item_progress > 100 ? 'bg-danger' : ($item_progress > 80 ? 'bg-warning' : 'bg-success');
                                    ?>
                                    <div class="col-md-6 mb-3">
                                        <div class="card item-card h-100" style="border-left-color: <?php echo $item['category_color'] ?? '#6366f1'; ?>">
                                            <div class="card-body">
                                                <div class="d-flex align-items-start mb-3">
                                                    <div class="category-icon me-3" style="background-color: <?php echo $item['category_color'] ?? '#6366f1'; ?>">
                                                        <i class="<?php echo $item['category_icon'] ?? 'bi-folder'; ?>"></i>
                                                    </div>
                                                    <div class="flex-grow-1">
                                                        <h6 class="mb-1"><?php echo htmlspecialchars($item['name']); ?></h6>
                                                        <small class="text-muted"><?php echo htmlspecialchars($item['category_name'] ?? 'Uncategorized'); ?></small>
                                                    </div>
                                                    <div class="text-end">
                                                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" 
                                                                data-bs-target="#updateItemModal" 
                                                                onclick="updateItemModal(<?php echo htmlspecialchars(json_encode($item)); ?>)">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                                
                                                <div class="row mb-2">
                                                    <div class="col-6">
                                                        <div class="text-success">
                                                            <small>Budgeted</small><br>
                                                            <strong><?php echo htmlspecialchars($budget_settings['default_currency'] ?? 'KES'); ?> <?php echo number_format($item['budgeted_amount'], 2); ?></strong>
                                                        </div>
                                                    </div>
                                                    <div class="col-6 text-end">
                                                        <div class="<?php echo $item_variance >= 0 ? 'text-danger' : 'text-primary'; ?>">
                                                            <small>Actual</small><br>
                                                            <strong><?php echo htmlspecialchars($budget_settings['default_currency'] ?? 'KES'); ?> <?php echo number_format($item['actual_amount'], 2); ?></strong>
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <div class="progress mb-2" style="height: 6px;">
                                                    <div class="progress-bar <?php echo $progress_class; ?>" 
                                                         style="width: <?php echo min($item_progress, 100); ?>%"></div>
                                                </div>
                                                
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <small class="text-muted"><?php echo number_format($item_progress, 1); ?>% used</small>
                                                    <small class="<?php echo $item_variance >= 0 ? 'variance-positive' : 'variance-negative'; ?>">
                                                        <?php echo $item_variance >= 0 ? '+' : ''; ?><?php echo htmlspecialchars($budget_settings['default_currency'] ?? 'KES'); ?><?php echo number_format($item_variance, 2); ?>
                                                    </small>
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

                    <div class="col-xl-4 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="bi bi-clock-history me-2"></i>Recent Transactions</h6>
                            </div>
                            <div class="card-body">
                                <?php if (empty($recent_transactions)): ?>
                                <div class="text-center py-4">
                                    <i class="bi bi-receipt fs-3 text-muted mb-3"></i>
                                    <p class="text-muted mb-0">No transactions recorded</p>
                                </div>
                                <?php else: ?>
                                <div class="timeline">
                                    <?php foreach ($recent_transactions as $transaction): ?>
                                    <div class="d-flex mb-3 pb-3 border-bottom">
                                        <div class="me-3">
                                            <div class="bg-primary rounded-circle d-flex align-items-center justify-content-center" 
                                                 style="width: 32px; height: 32px;">
                                                <i class="bi bi-cash text-white"></i>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="fw-bold"><?php echo htmlspecialchars($budget_settings['default_currency'] ?? 'KES'); ?> <?php echo number_format($transaction['amount'], 2); ?></div>
                                            <div class="text-muted small"><?php echo htmlspecialchars($transaction['description']); ?></div>
                                            <div class="text-muted small">
                                                <?php echo htmlspecialchars($transaction['item_name']); ?> • 
                                                <?php echo date('M d, Y', strtotime($transaction['transaction_date'])); ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php if (count($recent_transactions) >= 20): ?>
                                <div class="text-center">
                                    <a href="budget-transactions.php?budget_id=<?php echo $budget_id; ?>" class="btn btn-sm btn-outline-primary">
                                        View All Transactions
                                    </a>
                                </div>
                                <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Add Expense Modal -->
    <div class="modal fade" id="addExpenseModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="add_transaction">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Add Expense</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Budget Item *</label>
                            <select class="form-select" name="item_id" required>
                                <option value="">Select budget item...</option>
                                <?php foreach ($budget_items as $item): ?>
                                <option value="<?php echo $item['id']; ?>">
                                    <?php echo htmlspecialchars($item['name']); ?> (<?php echo htmlspecialchars($item['category_name']); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Amount *</label>
                            <div class="input-group">
                                <span class="input-group-text"><?php echo htmlspecialchars($budget_settings['default_currency'] ?? 'KES'); ?></span>
                                <input type="number" class="form-control" name="amount" step="0.01" min="0.01" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description *</label>
                            <textarea class="form-control" name="description" rows="2" required></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Transaction Date *</label>
                            <input type="date" class="form-control" name="transaction_date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Expense</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Update Item Modal -->
    <div class="modal fade" id="updateItemModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="update_actual_amount">
                    <input type="hidden" name="item_id" id="update_item_id">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Update Budget Item</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Item Name</label>
                            <input type="text" class="form-control" id="update_item_name" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Budgeted Amount</label>
                            <div class="input-group">
                                <span class="input-group-text"><?php echo htmlspecialchars($budget_settings['default_currency'] ?? 'KES'); ?></span>
                                <input type="text" class="form-control" id="update_budgeted_amount" readonly>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Actual Amount *</label>
                            <div class="input-group">
                                <span class="input-group-text"><?php echo htmlspecialchars($budget_settings['default_currency'] ?? 'KES'); ?></span>
                                <input type="number" class="form-control" name="actual_amount" id="update_actual_amount" step="0.01" min="0" required>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function updateItemModal(item) {
            document.getElementById('update_item_id').value = item.id;
            document.getElementById('update_item_name').value = item.name;
            document.getElementById('update_budgeted_amount').value = parseFloat(item.budgeted_amount).toFixed(2);
            document.getElementById('update_actual_amount').value = parseFloat(item.actual_amount).toFixed(2);
        }
        
        function exportBudgetDetails() {
            let csv = 'Budget Details Export\n';
            csv += 'Budget: <?php echo addslashes($budget['name']); ?>\n';
            csv += 'Period: <?php echo $budget['start_date']; ?> to <?php echo $budget['end_date']; ?>\n';
            csv += 'Generated: <?php echo date('Y-m-d H:i:s'); ?>\n\n';
            
            csv += 'SUMMARY\n';
            csv += 'Total Budgeted,<?php echo $total_budgeted; ?>\n';
            csv += 'Total Actual,<?php echo $total_actual; ?>\n';
            csv += 'Total Variance,<?php echo $total_variance; ?>\n';
            csv += 'Days Remaining,<?php echo ceil($days_remaining); ?>\n\n';
            
            csv += 'BUDGET ITEMS\n';
            csv += 'Category,Item Name,Budgeted Amount,Actual Amount,Variance,Variance %\n';
            <?php foreach ($budget_items as $item): ?>
            <?php 
                $item_variance_pct = $item['budgeted_amount'] > 0 ? 
                    (($item['actual_amount'] - $item['budgeted_amount']) / $item['budgeted_amount']) * 100 : 0;
            ?>
            csv += '<?php echo addslashes($item['category_name'] ?? 'Uncategorized'); ?>,<?php echo addslashes($item['name']); ?>,<?php echo $item['budgeted_amount']; ?>,<?php echo $item['actual_amount']; ?>,<?php echo $item['actual_amount'] - $item['budgeted_amount']; ?>,<?php echo number_format($item_variance_pct, 2); ?>\n';
            <?php endforeach; ?>
            
            // Download
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.setAttribute('hidden', '');
            a.setAttribute('href', url);
            a.setAttribute('download', 'budget-details-<?php echo $budget_id; ?>-<?php echo date('Y-m-d'); ?>.csv');
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        }
    </script>
</body>
</html>
