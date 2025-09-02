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

// Check permissions
if (!hasPermission('view_expense_reports', $permissions)) {
    $_SESSION['error_message'] = "You don't have permission to view expenses.";
    header('Location: ../dashboard/dashboard.php');
    exit();
}

// Get expense ID
$expense_id = intval($_GET['id'] ?? 0);
if (!$expense_id) {
    $_SESSION['error_message'] = "Invalid expense ID.";
    header('Location: index.php');
    exit();
}

// Get expense details
try {
    $stmt = $conn->prepare("
        SELECT e.*, 
               ec.name as category_name,
               sc.name as subcategory_name,
               ev.name as vendor_name,
               ed.name as department_name,
               epm.name as payment_method_name,
               u.username as created_by_name,
               a.username as approved_by_name
        FROM expenses e
        LEFT JOIN expense_categories ec ON e.category_id = ec.id
        LEFT JOIN expense_categories sc ON e.subcategory_id = sc.id
        LEFT JOIN expense_vendors ev ON e.vendor_id = ev.id
        LEFT JOIN expense_departments ed ON e.department_id = ed.id
        LEFT JOIN expense_payment_methods epm ON e.payment_method_id = epm.id
        LEFT JOIN users u ON e.created_by = u.id
        LEFT JOIN users a ON e.approved_by = a.id
        WHERE e.id = ?
    ");
    $stmt->execute([$expense_id]);
    $expense = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$expense) {
        $_SESSION['error_message'] = "Expense not found.";
        header('Location: index.php');
        exit();
    }
    
} catch (PDOException $e) {
    $_SESSION['error_message'] = "Database error: " . $e->getMessage();
    header('Location: index.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Expense - <?= htmlspecialchars($expense['expense_number']) ?> - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
        .expense-details .row {
            margin-bottom: 1rem;
        }
        .expense-details label {
            font-weight: 600;
            color: #374151;
        }
    </style>
</head>
<body>
    <?php include '../include/navmenu.php'; ?>

    <div class="main-content">
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1><i class="bi bi-eye"></i> View Expense</h1>
                    <p class="header-subtitle"><?= htmlspecialchars($expense['expense_number']) ?></p>
                </div>
                <div class="header-actions">
                    <a href="index.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Back to Expenses
                    </a>
                    <?php if (hasPermission('approve_expenses', $permissions) && $expense['approval_status'] == 'pending'): ?>
                    <a href="approve.php?id=<?= $expense['id'] ?>" class="btn btn-success">
                        <i class="bi bi-check-circle"></i> Approve Expense
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </header>

        <main class="content">
            <div class="container-fluid">
                <div class="row">
                    <div class="col-lg-8">
                        <!-- Expense Details Card -->
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">
                                    <i class="bi bi-receipt"></i> Expense Details
                                </h5>
                                <div class="d-flex gap-2">
                                    <?php
                                    $status_class = [
                                        'pending' => 'warning',
                                        'approved' => 'success',
                                        'rejected' => 'danger',
                                        'cancelled' => 'secondary'
                                    ];
                                    $approval_class = $status_class[$expense['approval_status']] ?? 'secondary';
                                    
                                    $payment_class = [
                                        'pending' => 'warning',
                                        'paid' => 'success',
                                        'partial' => 'info',
                                        'overdue' => 'danger'
                                    ];
                                    $payment_status_class = $payment_class[$expense['payment_status']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?= $approval_class ?>">
                                        <?= ucfirst($expense['approval_status']) ?>
                                    </span>
                                    <span class="badge bg-<?= $payment_status_class ?>">
                                        <?= ucfirst($expense['payment_status']) ?>
                                    </span>
                                </div>
                            </div>
                            <div class="card-body expense-details">
                                <div class="row">
                                    <div class="col-md-6">
                                        <label>Expense Number:</label>
                                        <p class="fw-bold text-primary"><?= htmlspecialchars($expense['expense_number']) ?></p>
                                    </div>
                                    <div class="col-md-6">
                                        <label>Expense Date:</label>
                                        <p><?= date('F j, Y', strtotime($expense['expense_date'])) ?></p>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-12">
                                        <label>Title:</label>
                                        <p class="fw-semibold"><?= htmlspecialchars($expense['title']) ?></p>
                                    </div>
                                </div>
                                
                                <?php if ($expense['description']): ?>
                                <div class="row">
                                    <div class="col-12">
                                        <label>Description:</label>
                                        <p><?= nl2br(htmlspecialchars($expense['description'])) ?></p>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <label>Category:</label>
                                        <p><?= htmlspecialchars($expense['category_name']) ?></p>
                                        <?php if ($expense['subcategory_name']): ?>
                                        <small class="text-muted">Subcategory: <?= htmlspecialchars($expense['subcategory_name']) ?></small>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-6">
                                        <label>Department:</label>
                                        <p><?= htmlspecialchars($expense['department_name'] ?? 'Not specified') ?></p>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <label>Vendor:</label>
                                        <p><?= htmlspecialchars($expense['vendor_name'] ?? 'Not specified') ?></p>
                                    </div>
                                    <div class="col-md-6">
                                        <label>Payment Method:</label>
                                        <p><?= htmlspecialchars($expense['payment_method_name'] ?? 'Not specified') ?></p>
                                    </div>
                                </div>
                                
                                <!-- Amount Information -->
                                <div class="row">
                                    <div class="col-md-4">
                                        <label>Base Amount:</label>
                                        <p class="fw-bold"><?= formatCurrency($expense['amount']) ?></p>
                                    </div>
                                    <div class="col-md-4">
                                        <label>Tax Amount:</label>
                                        <p><?= formatCurrency($expense['tax_amount']) ?></p>
                                    </div>
                                    <div class="col-md-4">
                                        <label>Total Amount:</label>
                                        <p class="fw-bold text-success fs-5"><?= formatCurrency($expense['total_amount']) ?></p>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <label>Submitted by:</label>
                                        <p><?= htmlspecialchars($expense['created_by_name']) ?></p>
                                        <small class="text-muted">On <?= date('F j, Y g:i A', strtotime($expense['created_at'])) ?></small>
                                    </div>
                                    <?php if ($expense['approved_by_name']): ?>
                                    <div class="col-md-6">
                                        <label>Processed by:</label>
                                        <p><?= htmlspecialchars($expense['approved_by_name']) ?></p>
                                        <small class="text-muted">On <?= date('F j, Y g:i A', strtotime($expense['approved_at'])) ?></small>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Receipt/Attachment -->
                                <?php if ($expense['receipt_file']): ?>
                                <div class="row">
                                    <div class="col-12">
                                        <label>Receipt/Invoice:</label>
                                        <div class="mt-2">
                                            <?php
                                            $file_ext = strtolower(pathinfo($expense['receipt_file'], PATHINFO_EXTENSION));
                                            $file_path = '../storage/expenses/' . $expense['receipt_file'];
                                            ?>
                                            <?php if (in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif'])): ?>
                                                <img src="<?= $file_path ?>" alt="Receipt" style="max-height: 300px; max-width: 100%;">
                                            <?php else: ?>
                                                <a href="<?= $file_path ?>" target="_blank" class="btn btn-outline-primary btn-sm">
                                                    <i class="bi bi-file-earmark"></i> View Receipt (<?= strtoupper($file_ext) ?>)
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-4">
                        <!-- Quick Actions -->
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-lightning"></i> Actions</h5>
                            </div>
                            <div class="card-body">
                                <div class="d-grid gap-2">
                                    <?php if (hasPermission('approve_expenses', $permissions) && $expense['approval_status'] == 'pending'): ?>
                                    <a href="approve.php?id=<?= $expense['id'] ?>" class="btn btn-success">
                                        <i class="bi bi-check-circle"></i> Approve Expense
                                    </a>
                                    <?php endif; ?>
                                    
                                    <?php if (hasPermission('edit_expenses', $permissions) && $expense['approval_status'] == 'pending'): ?>
                                    <a href="edit.php?id=<?= $expense['id'] ?>" class="btn btn-warning">
                                        <i class="bi bi-pencil"></i> Edit Expense
                                    </a>
                                    <?php endif; ?>
                                    
                                    <a href="index.php" class="btn btn-outline-secondary">
                                        <i class="bi bi-list"></i> View All Expenses
                                    </a>
                                    
                                    <?php if (hasPermission('create_expenses', $permissions)): ?>
                                    <a href="add.php" class="btn btn-outline-primary">
                                        <i class="bi bi-plus"></i> Add New Expense
                                    </a>
                                    <?php endif; ?>
                                </div>
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
