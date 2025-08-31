<?php
session_start();
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Get user information
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

// Check if user has permission to manage returns
if (!in_array('manage_products', $permissions) && !in_array('process_sales', $permissions)) {
    header("Location: returns_list.php?error=permission_denied");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get return IDs from URL
$return_ids = [];
if (isset($_GET['return_ids'])) {
    $return_ids = explode(',', $_GET['return_ids']);
    $return_ids = array_map('intval', $return_ids);
    $return_ids = array_filter($return_ids);
}

if (empty($return_ids)) {
    header("Location: returns_list.php?error=no_returns_selected");
    exit();
}

// Get return details
$returns = [];
try {
    $placeholders = str_repeat('?,', count($return_ids) - 1) . '?';
    $stmt = $conn->prepare("
        SELECT r.*, s.name as supplier_name, u.username as created_by_name
        FROM returns r
        LEFT JOIN suppliers s ON r.supplier_id = s.id
        LEFT JOIN users u ON r.user_id = u.id
        WHERE r.id IN ($placeholders)
        ORDER BY r.created_at DESC
    ");
    $stmt->execute($return_ids);
    $returns = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    header("Location: returns_list.php?error=db_error");
    exit();
}

// Handle form submission
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->beginTransaction();
        
        $new_status = $_POST['new_status'];
        $status_reason = trim($_POST['status_reason']);
        $bulk_notes = trim($_POST['bulk_notes']);
        $affected_rows = 0;
        
        // Validate status transition
        $valid_transitions = [
            'pending' => ['approved', 'cancelled'],
            'approved' => ['shipped', 'cancelled', 'completed'],
            'shipped' => ['received', 'cancelled'],
            'received' => ['completed', 'cancelled'],
            'completed' => [],
            'cancelled' => []
        ];
        
        foreach ($returns as $return) {
            $current_status = $return['status'];
            
            if (!in_array($new_status, $valid_transitions[$current_status])) {
                throw new Exception("Invalid status transition from '$current_status' to '$new_status' for return {$return['return_number']}");
            }
            
            // Update return status
            $stmt = $conn->prepare("
                UPDATE returns 
                SET status = :new_status, 
                    updated_at = NOW()
                WHERE id = :return_id
            ");
            $stmt->execute([
                ':new_status' => $new_status,
                ':return_id' => $return['id']
            ]);
            
            $affected_rows += $stmt->rowCount();
            
            // Log status change
            logReturnStatusChange($conn, $return['id'], $new_status, $user_id, $status_reason);
            
            // Handle inventory updates based on status
            if ($new_status === 'cancelled') {
                // Restore inventory for cancelled returns
                restoreInventoryForReturn($conn, $return['id']);
            } elseif ($new_status === 'completed') {
                // Mark return as completed
                $stmt = $conn->prepare("
                    UPDATE returns 
                    SET completed_at = NOW() 
                    WHERE id = :return_id
                ");
                $stmt->execute([':return_id' => $return['id']]);
            }
        }
        
        $conn->commit();
        $message = "$affected_rows return(s) status updated to '$new_status' successfully!";
        $message_type = 'success';
        
    } catch (Exception $e) {
        $conn->rollBack();
        $message = "Error updating return status: " . $e->getMessage();
        $message_type = 'danger';
    }
}

$page_title = "Bulk Status Update";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    
    <!-- Custom CSS -->
    <link href="../assets/css/dashboard.css" rel="stylesheet">
    
    <style>
        .return-card {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            margin-bottom: 1rem;
            overflow: hidden;
        }
        
        .return-header {
            background: #f8fafc;
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .return-title {
            font-weight: 600;
            color: #1e293b;
        }
        
        .status-badge {
            font-size: 0.875rem;
            padding: 0.5rem 1rem;
        }
        
        .status-pending { background-color: #fef3c7; color: #92400e; }
        .status-approved { background-color: #dbeafe; color: #1e40af; }
        .status-shipped { background-color: #e9d5ff; color: #7c3aed; }
        .status-received { background-color: #d1fae5; color: #065f46; }
        .status-completed { background-color: #d1fae5; color: #065f46; }
        .status-cancelled { background-color: #fee2e2; color: #991b1b; }
    </style>
</head>
<body>
    <?php include '../include/navmenu.php'; ?>

    <div class="main-content">
        <div class="container-fluid">
            <!-- Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h3 mb-0">
                        <i class="bi bi-arrow-up-circle text-info me-2"></i>
                        Bulk Status Update
                    </h1>
                    <p class="text-muted">Update status for multiple returns at once</p>
                </div>
                <div>
                    <a href="returns_list.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-2"></i>Back to Returns
                    </a>
                </div>
            </div>

            <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show">
                <i class="bi bi-<?php echo $message_type == 'success' ? 'check-circle' : 'exclamation-circle'; ?> me-2"></i>
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <div class="row">
                <div class="col-md-8">
                    <!-- Returns List -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="bi bi-list-ul me-2"></i>
                                Selected Returns (<?php echo count($returns); ?>)
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php foreach ($returns as $return): ?>
                            <div class="return-card">
                                <div class="return-header">
                                    <div class="row align-items-center">
                                        <div class="col-md-8">
                                            <h6 class="mb-1"><?php echo htmlspecialchars($return['return_number']); ?></h6>
                                            <small class="text-muted">
                                                Supplier: <?php echo htmlspecialchars($return['supplier_name'] ?? 'N/A'); ?> | 
                                                Created: <?php echo date('M j, Y', strtotime($return['created_at'])); ?>
                                            </small>
                                        </div>
                                        <div class="col-md-4 text-end">
                                            <span class="status-badge status-<?php echo $return['status']; ?>">
                                                <?php echo ucfirst($return['status']); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <strong>Items:</strong> <?php echo $return['total_items']; ?> items<br>
                                            <strong>Value:</strong> <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($return['total_amount'], 2); ?>
                                        </div>
                                        <div class="col-md-6">
                                            <strong>Reason:</strong> <?php echo ucfirst(str_replace('_', ' ', $return['return_reason'])); ?><br>
                                            <strong>Created by:</strong> <?php echo htmlspecialchars($return['created_by_name']); ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <!-- Status Update Form -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="bi bi-gear me-2"></i>
                                Update Status
                            </h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" id="statusUpdateForm">
                                <div class="mb-3">
                                    <label for="new_status" class="form-label">New Status *</label>
                                    <select class="form-control" id="new_status" name="new_status" required>
                                        <option value="">Select Status</option>
                                        <option value="approved">Approved</option>
                                        <option value="shipped">Shipped</option>
                                        <option value="received">Received</option>
                                        <option value="completed">Completed</option>
                                        <option value="cancelled">Cancelled</option>
                                    </select>
                                    <div class="form-text">Select the new status for all selected returns</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="status_reason" class="form-label">Status Reason</label>
                                    <select class="form-control" id="status_reason" name="status_reason">
                                        <option value="">Select Reason</option>
                                        <option value="Quality check passed">Quality check passed</option>
                                        <option value="Supplier approved">Supplier approved</option>
                                        <option value="Items shipped">Items shipped</option>
                                        <option value="Items received">Items received</option>
                                        <option value="Return processed">Return processed</option>
                                        <option value="Return cancelled">Return cancelled</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="bulk_notes" class="form-label">Additional Notes</label>
                                    <textarea class="form-control" id="bulk_notes" name="bulk_notes" rows="3" 
                                              placeholder="Any additional notes for this status update..."></textarea>
                                </div>
                                
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle me-2"></i>
                                    <strong>Note:</strong> This action will update the status of all <?php echo count($returns); ?> selected returns.
                                </div>
                                
                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-check-circle me-2"></i>
                                        Update Status
                                    </button>
                                    <a href="returns_list.php" class="btn btn-outline-secondary">
                                        <i class="bi bi-x-circle me-2"></i>
                                        Cancel
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Custom JS -->
    <script src="../assets/js/dashboard.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Form validation
            const form = document.getElementById('statusUpdateForm');
            const statusSelect = document.getElementById('new_status');
            const reasonSelect = document.getElementById('status_reason');
            
            // Auto-fill reason based on status
            statusSelect.addEventListener('change', function() {
                const status = this.value;
                const reason = reasonSelect.value;
                
                // Auto-suggest reason if none selected
                if (!reason) {
                    switch(status) {
                        case 'approved':
                            reasonSelect.value = 'Quality check passed';
                            break;
                        case 'shipped':
                            reasonSelect.value = 'Items shipped';
                            break;
                        case 'received':
                            reasonSelect.value = 'Items received';
                            break;
                        case 'completed':
                            reasonSelect.value = 'Return processed';
                            break;
                        case 'cancelled':
                            reasonSelect.value = 'Return cancelled';
                            break;
                    }
                }
            });
            
            // Form submission validation
            form.addEventListener('submit', function(e) {
                if (!statusSelect.value) {
                    e.preventDefault();
                    alert('Please select a new status.');
                    statusSelect.focus();
                    return;
                }
                
                if (!confirm(`Are you sure you want to update the status of ${<?php echo count($returns); ?>} returns to '${statusSelect.value}'?`)) {
                    e.preventDefault();
                    return;
                }
            });
        });
    </script>
</body>
</html>
