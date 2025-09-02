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
$user_role = $_SESSION['role'] ?? $_SESSION['role_name'] ?? 'User';
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

// Get system settings for navmenu display
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Check permissions
if (!hasPermission('approve_expenses', $permissions)) {
    $_SESSION['error_message'] = "You don't have permission to approve expenses.";
    header('Location: index.php');
    exit();
}

// Get expense ID
$expense_id = intval($_GET['id'] ?? 0);
if (!$expense_id) {
    $_SESSION['error_message'] = "Invalid expense ID.";
    header('Location: index.php');
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'];
    $comments = trim($_POST['comments'] ?? '');
    
    $errors = [];
    
    if (!in_array($action, ['approve', 'reject'])) {
        $errors[] = "Invalid action specified.";
    }
    
    if ($action == 'reject' && empty($comments)) {
        $errors[] = "Comments are required when rejecting an expense.";
    }
    
    if (empty($errors)) {
        try {
            $conn->beginTransaction();
            
            // Get current expense details
            $stmt = $conn->prepare("SELECT * FROM expenses WHERE id = ?");
            $stmt->execute([$expense_id]);
            $expense = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$expense) {
                throw new Exception("Expense not found.");
            }
            
            if ($expense['approval_status'] !== 'pending') {
                throw new Exception("This expense has already been processed.");
            }
            
            // Update expense approval status
            $new_status = $action == 'approve' ? 'approved' : 'rejected';
            $stmt = $conn->prepare("
                UPDATE expenses 
                SET approval_status = ?, 
                    approved_by = ?, 
                    approved_at = NOW(),
                    rejection_reason = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $new_status,
                $user_id,
                $action == 'reject' ? $comments : null,
                $expense_id
            ]);
            
            // Create approval record
            $stmt = $conn->prepare("
                INSERT INTO expense_approvals 
                (expense_id, approver_id, status, comments, approved_at) 
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $expense_id,
                $user_id,
                $new_status,
                $comments
            ]);
            
            // Log activity
            $action_text = $action == 'approve' ? 'approved' : 'rejected';
            logActivity($conn, $user_id, 'expense_' . $action_text, 
                "Expense {$expense['expense_number']} - {$expense['title']} has been {$action_text}");
            
            $conn->commit();
            
            $success_message = "Expense has been successfully " . $action_text . ".";
            $_SESSION['success_message'] = $success_message;
            
            // Redirect back to expenses list
            header('Location: index.php');
            exit();
            
        } catch (Exception $e) {
            $conn->rollBack();
            $errors[] = "Error processing approval: " . $e->getMessage();
        }
    }
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
               u.email as created_by_email,
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
    
    // Get approval history
    $stmt = $conn->prepare("
        SELECT ea.*, u.username as approver_name 
        FROM expense_approvals ea
        LEFT JOIN users u ON ea.approver_id = u.id
        WHERE ea.expense_id = ?
        ORDER BY ea.created_at DESC
    ");
    $stmt->execute([$expense_id]);
    $approval_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $_SESSION['error_message'] = "Database error: " . $e->getMessage();
    header('Location: index.php');
    exit();
}

// Check if already processed
$already_processed = $expense['approval_status'] !== 'pending';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approve Expense - <?= htmlspecialchars($expense['expense_number']) ?> - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
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
        .status-badge {
            font-size: 0.875rem;
            padding: 0.25rem 0.75rem;
        }
        .receipt-preview {
            max-width: 100%;
            height: auto;
            border: 1px solid #e5e7eb;
            border-radius: 0.375rem;
        }
        .approval-actions {
            position: sticky;
            bottom: 1rem;
            background: white;
            border-radius: 0.5rem;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            padding: 1.5rem;
            border: 1px solid #e5e7eb;
        }
    </style>
</head>
<body>
    <?php include '../include/navmenu.php'; ?>

    <div class="main-content">
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1><i class="bi bi-check-circle"></i> Approve Expense</h1>
                    <p class="header-subtitle">Review and approve expense request</p>
                </div>
                <div class="header-actions">
                    <a href="index.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Back to Expenses
                    </a>
                </div>
            </div>
        </header>

        <main class="content">
            <div class="container-fluid">

                <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

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
                                    <span class="badge bg-<?= $approval_class ?> status-badge">
                                        <i class="bi bi-check-circle-fill"></i> <?= ucfirst($expense['approval_status']) ?>
                                    </span>
                                    <span class="badge bg-<?= $payment_status_class ?> status-badge">
                                        <i class="bi bi-credit-card"></i> <?= ucfirst($expense['payment_status']) ?>
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
                                
                                <!-- Additional Information -->
                                <div class="row">
                                    <div class="col-md-6">
                                        <label>Tax Deductible:</label>
                                        <p>
                                            <?php if ($expense['is_tax_deductible']): ?>
                                            <span class="badge bg-success"><i class="bi bi-check"></i> Yes</span>
                                            <?php else: ?>
                                            <span class="badge bg-secondary"><i class="bi bi-x"></i> No</span>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <div class="col-md-6">
                                        <label>Recurring:</label>
                                        <p>
                                            <?php if ($expense['is_recurring']): ?>
                                            <span class="badge bg-info"><i class="bi bi-arrow-repeat"></i> <?= ucfirst($expense['recurring_frequency']) ?></span>
                                            <?php else: ?>
                                            <span class="badge bg-secondary"><i class="bi bi-x"></i> No</span>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                </div>
                                
                                <?php if ($expense['due_date']): ?>
                                <div class="row">
                                    <div class="col-md-6">
                                        <label>Due Date:</label>
                                        <p><?= date('F j, Y', strtotime($expense['due_date'])) ?></p>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($expense['notes']): ?>
                                <div class="row">
                                    <div class="col-12">
                                        <label>Notes:</label>
                                        <p><?= nl2br(htmlspecialchars($expense['notes'])) ?></p>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
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
                                                <img src="<?= $file_path ?>" alt="Receipt" class="receipt-preview" style="max-height: 300px;">
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

                        <!-- Approval History -->
                        <?php if (!empty($approval_history)): ?>
                        <div class="card mt-3">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-clock-history"></i> Approval History</h5>
                            </div>
                            <div class="card-body">
                                <?php foreach ($approval_history as $approval): ?>
                                <div class="d-flex align-items-start mb-3">
                                    <div class="flex-shrink-0 me-3">
                                        <?php if ($approval['status'] == 'approved'): ?>
                                        <div class="bg-success text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                            <i class="bi bi-check"></i>
                                        </div>
                                        <?php elseif ($approval['status'] == 'rejected'): ?>
                                        <div class="bg-danger text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                            <i class="bi bi-x"></i>
                                        </div>
                                        <?php else: ?>
                                        <div class="bg-warning text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                            <i class="bi bi-clock"></i>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <h6 class="mb-1"><?= ucfirst($approval['status']) ?> by <?= htmlspecialchars($approval['approver_name']) ?></h6>
                                            <small class="text-muted"><?= date('M j, Y g:i A', strtotime($approval['approved_at'])) ?></small>
                                        </div>
                                        <?php if ($approval['comments']): ?>
                                        <p class="mb-0 text-muted"><?= nl2br(htmlspecialchars($approval['comments'])) ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="col-lg-4">
                        <!-- Approval Actions -->
                        <?php if (!$already_processed): ?>
                        <div class="card approval-actions">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-check-square"></i> Approval Decision</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" id="approvalForm">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Comments <span class="text-danger">*</span></label>
                                        <textarea class="form-control" name="comments" rows="4" 
                                                  placeholder="Add your comments about this expense approval/rejection..." required></textarea>
                                        <div class="form-text">Comments are required for rejection, optional for approval.</div>
                                    </div>
                                    
                                    <div class="d-grid gap-2">
                                        <button type="submit" name="action" value="approve" class="btn btn-success btn-lg">
                                            <i class="bi bi-check-circle"></i> Approve Expense
                                        </button>
                                        <button type="submit" name="action" value="reject" class="btn btn-danger btn-lg">
                                            <i class="bi bi-x-circle"></i> Reject Expense
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-info-circle"></i> Status</h5>
                            </div>
                            <div class="card-body text-center">
                                <?php if ($expense['approval_status'] == 'approved'): ?>
                                <div class="text-success mb-3">
                                    <i class="bi bi-check-circle-fill" style="font-size: 3rem;"></i>
                                </div>
                                <h5 class="text-success">Expense Approved</h5>
                                <p class="text-muted">This expense has already been approved and cannot be modified.</p>
                                <?php elseif ($expense['approval_status'] == 'rejected'): ?>
                                <div class="text-danger mb-3">
                                    <i class="bi bi-x-circle-fill" style="font-size: 3rem;"></i>
                                </div>
                                <h5 class="text-danger">Expense Rejected</h5>
                                <p class="text-muted">This expense has been rejected.</p>
                                <?php if ($expense['rejection_reason']): ?>
                                <div class="alert alert-danger">
                                    <strong>Rejection Reason:</strong><br>
                                    <?= nl2br(htmlspecialchars($expense['rejection_reason'])) ?>
                                </div>
                                <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Quick Actions -->
                        <div class="card mt-3">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-lightning"></i> Quick Actions</h5>
                            </div>
                            <div class="card-body">
                                <div class="d-grid gap-2">
                                    <a href="index.php" class="btn btn-outline-secondary">
                                        <i class="bi bi-list"></i> View All Expenses
                                    </a>
                                    <?php if (hasPermission('create_expenses', $permissions)): ?>
                                    <a href="add.php" class="btn btn-outline-primary">
                                        <i class="bi bi-plus"></i> Add New Expense
                                    </a>
                                    <?php endif; ?>
                                    <?php if (hasPermission('view_expense_reports', $permissions)): ?>
                                    <a href="reports.php" class="btn btn-outline-info">
                                        <i class="bi bi-graph-up"></i> View Reports
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
    <script>
        // Form validation
        document.getElementById('approvalForm')?.addEventListener('submit', function(e) {
            const action = e.submitter.value;
            const comments = document.querySelector('textarea[name="comments"]').value.trim();
            
            if (action === 'reject' && comments === '') {
                e.preventDefault();
                alert('Comments are required when rejecting an expense.');
                return;
            }
            
            const confirmMessage = action === 'approve' ? 
                'Are you sure you want to approve this expense?' : 
                'Are you sure you want to reject this expense?';
                
            if (!confirm(confirmMessage)) {
                e.preventDefault();
            }
        });
    </script>
</body>
</html>
