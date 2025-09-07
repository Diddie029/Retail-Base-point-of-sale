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
    // Budget tables don't exist yet
    $budget_settings = [
        'budget_alert_threshold_warning' => '75',
        'budget_alert_threshold_critical' => '90',
        'default_currency' => 'KES'
    ];
}

// Handle budget creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'create_budget') {
        try {
            $name = trim($_POST['budget_name']);
            $description = trim($_POST['description'] ?? '');
            $budget_type = $_POST['budget_type'];
            $start_date = $_POST['start_date'];
            $end_date = $_POST['end_date'];
            $total_amount = (float)$_POST['total_amount'];
            
            $stmt = $conn->prepare("
                INSERT INTO budgets (name, description, budget_type, start_date, end_date, total_budget_amount, created_by, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 'active')
            ");
            $stmt->execute([$name, $description, $budget_type, $start_date, $end_date, $total_amount, $user_id]);
            
            $budget_id = $conn->lastInsertId();
            
            // Create budget items if provided
            if (!empty($_POST['budget_items'])) {
                foreach ($_POST['budget_items'] as $item) {
                    if (!empty($item['name']) && !empty($item['amount'])) {
                        $stmt = $conn->prepare("
                            INSERT INTO budget_items (budget_id, category_id, name, budgeted_amount) 
                            VALUES (?, ?, ?, ?)
                        ");
                        $stmt->execute([$budget_id, $item['category_id'], $item['name'], (float)$item['amount']]);
                    }
                }
            }
            
            $success_message = "Budget created successfully!";
        } catch (Exception $e) {
            $error_message = "Error creating budget: " . $e->getMessage();
        }
    }
}

// Get current budgets
$current_budgets = [];
$budget_summary = [
    'total_budgets' => 0,
    'active_budgets' => 0,
    'total_budgeted' => 0,
    'total_spent' => 0,
    'variance' => 0
];

try {
    // Get budget summary
    $stmt = $conn->query("
        SELECT 
            COUNT(*) as total_budgets,
            COUNT(CASE WHEN status = 'active' THEN 1 END) as active_budgets,
            COALESCE(SUM(total_budget_amount), 0) as total_budgeted,
            COALESCE(SUM(total_actual_amount), 0) as total_spent
        FROM budgets
    ");
    $summary_result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($summary_result) {
        $budget_summary = [
            'total_budgets' => $summary_result['total_budgets'],
            'active_budgets' => $summary_result['active_budgets'],
            'total_budgeted' => $summary_result['total_budgeted'],
            'total_spent' => $summary_result['total_spent'],
            'variance' => $summary_result['total_spent'] - $summary_result['total_budgeted']
        ];
    }
    
    // Get current active budgets
    $stmt = $conn->prepare("
        SELECT b.*, u.username as created_by_name,
               COUNT(bi.id) as items_count,
               COALESCE(SUM(bi.budgeted_amount), 0) as items_total
        FROM budgets b
        LEFT JOIN users u ON b.created_by = u.id
        LEFT JOIN budget_items bi ON b.id = bi.budget_id
        WHERE b.status IN ('active', 'draft')
        GROUP BY b.id
        ORDER BY b.created_at DESC
        LIMIT 10
    ");
    $stmt->execute();
    $current_budgets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    // Budget tables don't exist yet - will show setup message
    $show_setup_message = true;
}

// Get budget categories
$budget_categories = [];
try {
    $stmt = $conn->query("SELECT * FROM budget_categories WHERE is_active = TRUE ORDER BY name");
    $budget_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Categories table doesn't exist
}

// Get recent budget alerts
$recent_alerts = [];
try {
    $stmt = $conn->prepare("
        SELECT ba.*, b.name as budget_name, bi.name as item_name
        FROM budget_alerts ba
        LEFT JOIN budgets b ON ba.budget_id = b.id
        LEFT JOIN budget_items bi ON ba.budget_item_id = bi.id
        WHERE ba.is_active = TRUE AND ba.is_read = FALSE
        ORDER BY ba.triggered_at DESC
        LIMIT 5
    ");
    $stmt->execute();
    $recent_alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Alerts table doesn't exist
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Budget Management - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
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
                            <li class="breadcrumb-item active">Budget Management</li>
                        </ol>
                    </nav>
                    <h1><i class="bi bi-calculator"></i> Budget Management</h1>
                    <p class="header-subtitle">Create, monitor and manage budgets</p>
                </div>
            </div>
        </header>

        <main class="content">
            <div class="container-fluid">
                <?php if (isset($success_message)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle me-2"></i><?php echo $success_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <?php if (isset($error_message)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i><?php echo $error_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <?php if (isset($show_setup_message)): ?>
                <!-- Setup Message -->
                <div class="alert alert-info">
                    <h5><i class="bi bi-info-circle me-2"></i>Budget Management Setup Required</h5>
                    <p>To use the Budget Management system, please run the database setup script first.</p>
                    <div class="mt-3">
                        <strong>Setup Instructions:</strong>
                        <ol class="mt-2">
                            <li>Access your database management tool (phpMyAdmin, etc.)</li>
                            <li>Run the SQL script: <code>finance/budget_database_setup.sql</code></li>
                            <li>Refresh this page to start using Budget Management</li>
                        </ol>
                    </div>
                    <div class="mt-3">
                        <a href="budget_database_setup.sql" target="_blank" class="btn btn-primary">
                            <i class="bi bi-download me-2"></i>Download SQL Setup Script
                        </a>
                        <button onclick="location.reload()" class="btn btn-outline-secondary ms-2">
                            <i class="bi bi-arrow-clockwise me-2"></i>Refresh Page
                        </button>
                    </div>
                </div>
                <?php else: ?>
                
                <!-- Action Buttons -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="btn-group" role="group">
                                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createBudgetModal">
                                    <i class="bi bi-plus-circle me-1"></i> Create New Budget
                                </button>
                                <a href="budget-reports.php" class="btn btn-outline-primary">
                                    <i class="bi bi-graph-up me-1"></i> View Reports
                                </a>
                                <a href="budget-categories.php" class="btn btn-outline-secondary">
                                    <i class="bi bi-tags me-1"></i> Manage Categories
                                </a>
                            </div>
                            <div class="btn-group" role="group">
                                <button type="button" class="btn btn-outline-secondary" onclick="exportBudgets()">
                                    <i class="bi bi-download me-1"></i> Export
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Summary Cards -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="card bg-primary text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="card-title mb-1">Total Budgets</h6>
                                        <h3 class="mb-0"><?php echo $budget_summary['total_budgets']; ?></h3>
                                        <small class="opacity-75"><?php echo $budget_summary['active_budgets']; ?> active</small>
                                    </div>
                                    <div>
                                        <i class="bi bi-folder fs-1 opacity-75"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="card bg-success text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="card-title mb-1">Total Budgeted</h6>
                                        <h3 class="mb-0"><?php echo htmlspecialchars($budget_settings['default_currency'] ?? 'KES'); ?> <?php echo number_format($budget_summary['total_budgeted'], 2); ?></h3>
                                        <small class="opacity-75">Allocated funds</small>
                                    </div>
                                    <div>
                                        <i class="bi bi-currency-dollar fs-1 opacity-75"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="card bg-warning text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="card-title mb-1">Total Spent</h6>
                                        <h3 class="mb-0"><?php echo htmlspecialchars($budget_settings['default_currency'] ?? 'KES'); ?> <?php echo number_format($budget_summary['total_spent'], 2); ?></h3>
                                        <small class="opacity-75">Actual expenses</small>
                                    </div>
                                    <div>
                                        <i class="bi bi-cash-stack fs-1 opacity-75"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="card <?php echo $budget_summary['variance'] >= 0 ? 'bg-danger' : 'bg-info'; ?> text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="card-title mb-1">Variance</h6>
                                        <h3 class="mb-0"><?php echo htmlspecialchars($budget_settings['default_currency'] ?? 'KES'); ?> <?php echo number_format(abs($budget_summary['variance']), 2); ?></h3>
                                        <small class="opacity-75"><?php echo $budget_summary['variance'] >= 0 ? 'Over budget' : 'Under budget'; ?></small>
                                    </div>
                                    <div>
                                        <i class="bi bi-<?php echo $budget_summary['variance'] >= 0 ? 'arrow-up' : 'arrow-down'; ?> fs-1 opacity-75"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Main Content Row -->
                <div class="row">
                    <!-- Current Budgets -->
                    <div class="col-xl-8 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="bi bi-list-task me-2"></i>Current Budgets</h6>
                            </div>
                            <div class="card-body">
                                <?php if (empty($current_budgets)): ?>
                                <div class="text-center py-4">
                                    <i class="bi bi-inbox fs-1 text-muted mb-3"></i>
                                    <h5 class="text-muted">No Active Budgets</h5>
                                    <p class="text-muted mb-3">Create your first budget to start managing your finances</p>
                                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createBudgetModal">
                                        <i class="bi bi-plus-circle me-1"></i> Create Budget
                                    </button>
                                </div>
                                <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Budget Name</th>
                                                <th>Period</th>
                                                <th>Status</th>
                                                <th class="text-end">Budgeted</th>
                                                <th class="text-end">Spent</th>
                                                <th class="text-end">Remaining</th>
                                                <th class="text-center">Progress</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($current_budgets as $budget): ?>
                                            <?php 
                                                $spent_percentage = $budget['total_budget_amount'] > 0 ? 
                                                    ($budget['total_actual_amount'] / $budget['total_budget_amount']) * 100 : 0;
                                                $remaining = $budget['total_budget_amount'] - $budget['total_actual_amount'];
                                                
                                                $progress_class = 'bg-success';
                                                if ($spent_percentage > 75) $progress_class = 'bg-warning';
                                                if ($spent_percentage > 90) $progress_class = 'bg-danger';
                                            ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($budget['name']); ?></strong>
                                                    <?php if (!empty($budget['description'])): ?>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars(substr($budget['description'], 0, 50)); ?><?php echo strlen($budget['description']) > 50 ? '...' : ''; ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-secondary"><?php echo ucfirst($budget['budget_type']); ?></span>
                                                    <br><small class="text-muted"><?php echo date('M d', strtotime($budget['start_date'])); ?> - <?php echo date('M d, Y', strtotime($budget['end_date'])); ?></small>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php echo $budget['status'] === 'active' ? 'success' : ($budget['status'] === 'draft' ? 'warning' : 'secondary'); ?>">
                                                        <?php echo ucfirst($budget['status']); ?>
                                                    </span>
                                                </td>
                                                <td class="text-end">
                                                    <strong><?php echo htmlspecialchars($budget_settings['default_currency'] ?? 'KES'); ?> <?php echo number_format($budget['total_budget_amount'], 2); ?></strong>
                                                </td>
                                                <td class="text-end">
                                                    <?php echo htmlspecialchars($budget_settings['default_currency'] ?? 'KES'); ?> <?php echo number_format($budget['total_actual_amount'], 2); ?>
                                                </td>
                                                <td class="text-end <?php echo $remaining < 0 ? 'text-danger' : 'text-success'; ?>">
                                                    <?php echo htmlspecialchars($budget_settings['default_currency'] ?? 'KES'); ?> <?php echo number_format($remaining, 2); ?>
                                                </td>
                                                <td class="text-center">
                                                    <div class="progress" style="width: 80px; height: 20px;">
                                                        <div class="progress-bar <?php echo $progress_class; ?>" style="width: <?php echo min($spent_percentage, 100); ?>%"></div>
                                                    </div>
                                                    <small><?php echo number_format($spent_percentage, 1); ?>%</small>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <a href="budget-details.php?id=<?php echo $budget['id']; ?>" class="btn btn-outline-primary" title="View Details">
                                                            <i class="bi bi-eye"></i>
                                                        </a>
                                                        <a href="budget-edit.php?id=<?php echo $budget['id']; ?>" class="btn btn-outline-secondary" title="Edit">
                                                            <i class="bi bi-pencil"></i>
                                                        </a>
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
                    
                    <!-- Alerts & Quick Stats -->
                    <div class="col-xl-4 mb-4">
                        <!-- Recent Alerts -->
                        <div class="card mb-3">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="bi bi-exclamation-triangle me-2"></i>Budget Alerts</h6>
                            </div>
                            <div class="card-body">
                                <?php if (empty($recent_alerts)): ?>
                                <div class="text-center text-muted py-3">
                                    <i class="bi bi-check-circle fs-3 text-success"></i>
                                    <p class="mb-0 mt-2">No active alerts</p>
                                    <small>All budgets are within limits</small>
                                </div>
                                <?php else: ?>
                                <?php foreach ($recent_alerts as $alert): ?>
                                <div class="alert alert-<?php echo $alert['alert_type'] === 'threshold_critical' || $alert['alert_type'] === 'overspent' ? 'danger' : 'warning'; ?> alert-sm mb-2">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div class="flex-grow-1">
                                            <small><strong><?php echo htmlspecialchars($alert['budget_name']); ?></strong></small>
                                            <br><small><?php echo htmlspecialchars($alert['message']); ?></small>
                                        </div>
                                        <small class="text-muted"><?php echo date('M d', strtotime($alert['triggered_at'])); ?></small>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                <div class="text-center mt-3">
                                    <a href="budget-alerts.php" class="btn btn-sm btn-outline-primary">View All Alerts</a>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Quick Actions -->
                        <div class="card">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="bi bi-lightning me-2"></i>Quick Actions</h6>
                            </div>
                            <div class="card-body">
                                <div class="d-grid gap-2">
                                    <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#quickExpenseModal">
                                        <i class="bi bi-plus-circle me-2"></i>Add Expense to Budget
                                    </button>
                                    <a href="budget-templates.php" class="btn btn-outline-secondary">
                                        <i class="bi bi-file-earmark-text me-2"></i>Budget Templates
                                    </a>
                                    <a href="budget-forecast.php" class="btn btn-outline-info">
                                        <i class="bi bi-graph-up-arrow me-2"></i>Budget Forecast
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Create Budget Modal -->
                <div class="modal fade" id="createBudgetModal" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <form method="POST" action="">
                                <input type="hidden" name="action" value="create_budget">
                                <div class="modal-header">
                                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Create New Budget</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="budget_name" class="form-label">Budget Name *</label>
                                            <input type="text" class="form-control" id="budget_name" name="budget_name" required>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label for="budget_type" class="form-label">Budget Type *</label>
                                            <select class="form-select" id="budget_type" name="budget_type" required>
                                                <option value="monthly">Monthly</option>
                                                <option value="quarterly">Quarterly</option>
                                                <option value="yearly">Yearly</option>
                                                <option value="custom">Custom</option>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="start_date" class="form-label">Start Date *</label>
                                            <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo date('Y-m-01'); ?>" required>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label for="end_date" class="form-label">End Date *</label>
                                            <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo date('Y-m-t'); ?>" required>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="total_amount" class="form-label">Total Budget Amount *</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><?php echo htmlspecialchars($budget_settings['default_currency'] ?? 'KES'); ?></span>
                                            <input type="number" class="form-control" id="total_amount" name="total_amount" step="0.01" min="0" required>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="description" class="form-label">Description</label>
                                        <textarea class="form-control" id="description" name="description" rows="2" placeholder="Optional description for this budget..."></textarea>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <label class="form-label mb-0">Budget Items</label>
                                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="addBudgetItem()">
                                                <i class="bi bi-plus"></i> Add Item
                                            </button>
                                        </div>
                                        <div id="budget-items-container">
                                            <!-- Budget items will be added dynamically -->
                                        </div>
                                        <small class="text-muted">Add specific budget items to track expenses by category</small>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-primary">Create Budget</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Quick Expense Modal -->
                <div class="modal fade" id="quickExpenseModal" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="POST" action="budget-add-expense.php">
                                <div class="modal-header">
                                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Add Expense to Budget</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label for="expense_budget" class="form-label">Select Budget *</label>
                                        <select class="form-select" id="expense_budget" name="budget_id" required>
                                            <option value="">Choose budget...</option>
                                            <?php foreach ($current_budgets as $budget): ?>
                                            <option value="<?php echo $budget['id']; ?>"><?php echo htmlspecialchars($budget['name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="expense_amount" class="form-label">Amount *</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><?php echo htmlspecialchars($budget_settings['default_currency'] ?? 'KES'); ?></span>
                                            <input type="number" class="form-control" id="expense_amount" name="amount" step="0.01" min="0.01" required>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="expense_description" class="form-label">Description *</label>
                                        <textarea class="form-control" id="expense_description" name="description" rows="2" required></textarea>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="expense_date" class="form-label">Expense Date *</label>
                                        <input type="date" class="form-control" id="expense_date" name="expense_date" value="<?php echo date('Y-m-d'); ?>" required>
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
                <?php endif; ?>
            </div>
        </main>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let itemCounter = 0;
        
        function addBudgetItem() {
            itemCounter++;
            const container = document.getElementById('budget-items-container');
            const itemHtml = `
                <div class="card mb-2 budget-item" id="item-${itemCounter}">
                    <div class="card-body p-3">
                        <div class="row align-items-end">
                            <div class="col-md-3">
                                <label class="form-label">Category</label>
                                <select class="form-select form-select-sm" name="budget_items[${itemCounter}][category_id]" required>
                                    <option value="">Select category...</option>
                                    <?php foreach ($budget_categories as $cat): ?>
                                    <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Item Name</label>
                                <input type="text" class="form-control form-control-sm" name="budget_items[${itemCounter}][name]" placeholder="e.g., Office Supplies" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Amount</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text"><?php echo htmlspecialchars($budget_settings['default_currency'] ?? 'KES'); ?></span>
                                    <input type="number" class="form-control" name="budget_items[${itemCounter}][amount]" step="0.01" min="0" required>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <button type="button" class="btn btn-sm btn-outline-danger w-100" onclick="removeBudgetItem(${itemCounter})">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            container.insertAdjacentHTML('beforeend', itemHtml);
        }
        
        function removeBudgetItem(id) {
            document.getElementById(`item-${id}`).remove();
        }
        
        function exportBudgets() {
            let csv = 'Budget Management Export\n';
            csv += 'Generated: <?php echo date('Y-m-d H:i:s'); ?>\n\n';
            
            csv += 'SUMMARY\n';
            csv += 'Total Budgets,<?php echo $budget_summary['total_budgets']; ?>\n';
            csv += 'Active Budgets,<?php echo $budget_summary['active_budgets']; ?>\n';
            csv += 'Total Budgeted,<?php echo $budget_summary['total_budgeted']; ?>\n';
            csv += 'Total Spent,<?php echo $budget_summary['total_spent']; ?>\n';
            csv += 'Variance,<?php echo $budget_summary['variance']; ?>\n\n';
            
            csv += 'BUDGET DETAILS\n';
            csv += 'Name,Type,Start Date,End Date,Status,Budgeted Amount,Actual Amount,Remaining\n';
            <?php foreach ($current_budgets as $budget): ?>
            csv += '<?php echo addslashes($budget['name']); ?>,<?php echo $budget['budget_type']; ?>,<?php echo $budget['start_date']; ?>,<?php echo $budget['end_date']; ?>,<?php echo $budget['status']; ?>,<?php echo $budget['total_budget_amount']; ?>,<?php echo $budget['total_actual_amount']; ?>,<?php echo $budget['total_budget_amount'] - $budget['total_actual_amount']; ?>\n';
            <?php endforeach; ?>
            
            // Download
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.setAttribute('hidden', '');
            a.setAttribute('href', url);
            a.setAttribute('download', 'budget-management-export-<?php echo date('Y-m-d'); ?>.csv');
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        }
        
        // Auto-update end date based on budget type
        document.getElementById('budget_type')?.addEventListener('change', function() {
            const startDate = new Date(document.getElementById('start_date').value);
            const endDateInput = document.getElementById('end_date');
            
            if (isNaN(startDate.getTime())) return;
            
            let endDate = new Date(startDate);
            
            switch (this.value) {
                case 'monthly':
                    endDate.setMonth(endDate.getMonth() + 1);
                    endDate.setDate(0); // Last day of previous month
                    break;
                case 'quarterly':
                    endDate.setMonth(endDate.getMonth() + 3);
                    endDate.setDate(0);
                    break;
                case 'yearly':
                    endDate.setFullYear(endDate.getFullYear() + 1);
                    endDate.setDate(0);
                    break;
            }
            
            if (this.value !== 'custom') {
                endDateInput.value = endDate.toISOString().split('T')[0];
            }
        });
        
        // Add first budget item by default when modal opens
        document.getElementById('createBudgetModal')?.addEventListener('shown.bs.modal', function() {
            const container = document.getElementById('budget-items-container');
            if (container.children.length === 0) {
                addBudgetItem();
            }
        });
    </script>
</body>
</html>
