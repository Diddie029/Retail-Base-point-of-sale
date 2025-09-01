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
if (!hasPermission('manage_expense_vendors', $permissions)) {
    header('Location: index.php');
    exit();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action == 'add_vendor') {
        $name = trim($_POST['name']);
        $contact_person = trim($_POST['contact_person']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        $address = trim($_POST['address']);
        $tax_id = trim($_POST['tax_id']);
        $payment_terms = trim($_POST['payment_terms']);
        $credit_limit = floatval($_POST['credit_limit']);
        $notes = trim($_POST['notes']);
        
        if (empty($name)) {
            $_SESSION['error_message'] = "Vendor name is required";
        } else {
            try {
                $stmt = $conn->prepare("
                    INSERT INTO expense_vendors (name, contact_person, email, phone, address, tax_id, payment_terms, credit_limit, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$name, $contact_person, $email, $phone, $address, $tax_id, $payment_terms, $credit_limit, $notes]);
                
                $_SESSION['success_message'] = "Vendor added successfully";
                header('Location: vendors.php');
                exit();
            } catch (PDOException $e) {
                $_SESSION['error_message'] = "Error adding vendor: " . $e->getMessage();
            }
        }
    } elseif ($action == 'edit_vendor') {
        $id = $_POST['id'];
        $name = trim($_POST['name']);
        $contact_person = trim($_POST['contact_person']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        $address = trim($_POST['address']);
        $tax_id = trim($_POST['tax_id']);
        $payment_terms = trim($_POST['payment_terms']);
        $credit_limit = floatval($_POST['credit_limit']);
        $notes = trim($_POST['notes']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        if (empty($name)) {
            $_SESSION['error_message'] = "Vendor name is required";
        } else {
            try {
                $stmt = $conn->prepare("
                    UPDATE expense_vendors 
                    SET name = ?, contact_person = ?, email = ?, phone = ?, address = ?, 
                        tax_id = ?, payment_terms = ?, credit_limit = ?, notes = ?, is_active = ?
                    WHERE id = ?
                ");
                $stmt->execute([$name, $contact_person, $email, $phone, $address, $tax_id, $payment_terms, $credit_limit, $notes, $is_active, $id]);
                
                $_SESSION['success_message'] = "Vendor updated successfully";
                header('Location: vendors.php');
                exit();
            } catch (PDOException $e) {
                $_SESSION['error_message'] = "Error updating vendor: " . $e->getMessage();
            }
        }
    } elseif ($action == 'delete_vendor') {
        $id = $_POST['id'];
        
        try {
            // Check if vendor is used in expenses
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM expenses WHERE vendor_id = ?");
            $stmt->execute([$id]);
            $expense_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            if ($expense_count > 0) {
                $_SESSION['error_message'] = "Cannot delete vendor that is used in expenses.";
            } else {
                $stmt = $conn->prepare("DELETE FROM expense_vendors WHERE id = ?");
                $stmt->execute([$id]);
                
                $_SESSION['success_message'] = "Vendor deleted successfully";
            }
            header('Location: vendors.php');
            exit();
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error deleting vendor: " . $e->getMessage();
        }
    }
}

// Get vendors with statistics
$vendors = $conn->query("
    SELECT v.*,
           (SELECT COUNT(*) FROM expenses WHERE vendor_id = v.id) as expense_count,
           (SELECT SUM(total_amount) FROM expenses WHERE vendor_id = v.id) as total_spent,
           (SELECT SUM(total_amount) FROM expenses WHERE vendor_id = v.id AND payment_status = 'pending') as pending_amount
    FROM expense_vendors v
    ORDER BY v.name
")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vendor Management - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
    </style>
</head>
<body>
    <?php include '../include/navmenu.php'; ?>

    <div class="main-content">
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1><i class="bi bi-building"></i> Vendor Management</h1>
                    <p class="header-subtitle">Manage your expense vendors and suppliers</p>
                </div>
                <div class="header-actions">
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addVendorModal">
                        <i class="bi bi-plus-circle"></i> Add Vendor
                    </button>
                    <a href="index.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Back to Expenses
                    </a>
                </div>
            </div>
        </header>

        <main class="content">
            <div class="container-fluid">

                <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <?= htmlspecialchars($_SESSION['success_message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['success_message']); endif; ?>

                <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <?= htmlspecialchars($_SESSION['error_message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['error_message']); endif; ?>

                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-list-ul"></i> Vendors List</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($vendors)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-building fs-1 text-muted"></i>
                            <h5 class="mt-3">No vendors found</h5>
                            <p class="text-muted">Create your first vendor to get started.</p>
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addVendorModal">
                                <i class="bi bi-plus-circle"></i> Add Vendor
                            </button>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Vendor Name</th>
                                        <th>Contact</th>
                                        <th>Financial Summary</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($vendors as $vendor): ?>
                                    <tr>
                                        <td>
                                            <div>
                                                <strong><?= htmlspecialchars($vendor['name']) ?></strong>
                                                <?php if ($vendor['tax_id']): ?>
                                                <br><small class="text-muted">Tax ID: <?= htmlspecialchars($vendor['tax_id']) ?></small>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div>
                                                <?php if ($vendor['contact_person']): ?>
                                                <div><strong><?= htmlspecialchars($vendor['contact_person']) ?></strong></div>
                                                <?php endif; ?>
                                                <?php if ($vendor['email']): ?>
                                                <div><i class="bi bi-envelope"></i> <?= htmlspecialchars($vendor['email']) ?></div>
                                                <?php endif; ?>
                                                <?php if ($vendor['phone']): ?>
                                                <div><i class="bi bi-telephone"></i> <?= htmlspecialchars($vendor['phone']) ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div>
                                                <div><strong>KES <?= number_format($vendor['total_spent'] ?? 0, 2) ?></strong> total spent</div>
                                                <div><small class="text-muted"><?= $vendor['expense_count'] ?> expenses</small></div>
                                                <?php if (($vendor['pending_amount'] ?? 0) > 0): ?>
                                                <div><small class="text-warning">KES <?= number_format($vendor['pending_amount'], 2) ?> pending</small></div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($vendor['is_active']): ?>
                                            <span class="badge bg-success">Active</span>
                                            <?php else: ?>
                                            <span class="badge bg-danger">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <button type="button" class="btn btn-sm btn-outline-warning" 
                                                        onclick="editVendor(<?= htmlspecialchars(json_encode($vendor)) ?>)" title="Edit">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <?php if ($vendor['expense_count'] == 0): ?>
                                                <button type="button" class="btn btn-sm btn-outline-danger" 
                                                        onclick="deleteVendor(<?= $vendor['id'] ?>, '<?= htmlspecialchars($vendor['name']) ?>')" title="Delete">
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
        </main>
    </div>

    <!-- Add Vendor Modal -->
    <div class="modal fade" id="addVendorModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Vendor</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_vendor">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Vendor Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="name" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Contact Person</label>
                                    <input type="text" class="form-control" name="contact_person">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Email</label>
                                    <input type="email" class="form-control" name="email">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Phone</label>
                                    <input type="text" class="form-control" name="phone">
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Address</label>
                            <textarea class="form-control" name="address" rows="3"></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Tax ID</label>
                                    <input type="text" class="form-control" name="tax_id">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Payment Terms</label>
                                    <input type="text" class="form-control" name="payment_terms" placeholder="e.g., Net 30">
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Credit Limit</label>
                            <div class="input-group">
                                <span class="input-group-text">KES</span>
                                <input type="number" class="form-control" name="credit_limit" step="0.01" min="0" value="0">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="notes" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Vendor</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Vendor Modal -->
    <div class="modal fade" id="editVendorModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Vendor</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit_vendor">
                        <input type="hidden" name="id" id="edit_id">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Vendor Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="name" id="edit_name" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Contact Person</label>
                                    <input type="text" class="form-control" name="contact_person" id="edit_contact_person">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Email</label>
                                    <input type="email" class="form-control" name="email" id="edit_email">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Phone</label>
                                    <input type="text" class="form-control" name="phone" id="edit_phone">
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Address</label>
                            <textarea class="form-control" name="address" id="edit_address" rows="3"></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Tax ID</label>
                                    <input type="text" class="form-control" name="tax_id" id="edit_tax_id">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Payment Terms</label>
                                    <input type="text" class="form-control" name="payment_terms" id="edit_payment_terms">
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Credit Limit</label>
                            <div class="input-group">
                                <span class="input-group-text">KES</span>
                                <input type="number" class="form-control" name="credit_limit" id="edit_credit_limit" step="0.01" min="0">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="notes" id="edit_notes" rows="3"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_active" id="edit_is_active" value="1">
                                <label class="form-check-label">Active</label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Vendor</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Vendor Modal -->
    <div class="modal fade" id="deleteVendorModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Delete Vendor</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="delete_vendor">
                        <input type="hidden" name="id" id="delete_id">
                        
                        <p>Are you sure you want to delete the vendor "<strong id="delete_name"></strong>"?</p>
                        <p class="text-danger">This action cannot be undone.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete Vendor</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function editVendor(vendor) {
            document.getElementById('edit_id').value = vendor.id;
            document.getElementById('edit_name').value = vendor.name;
            document.getElementById('edit_contact_person').value = vendor.contact_person || '';
            document.getElementById('edit_email').value = vendor.email || '';
            document.getElementById('edit_phone').value = vendor.phone || '';
            document.getElementById('edit_address').value = vendor.address || '';
            document.getElementById('edit_tax_id').value = vendor.tax_id || '';
            document.getElementById('edit_payment_terms').value = vendor.payment_terms || '';
            document.getElementById('edit_credit_limit').value = vendor.credit_limit || 0;
            document.getElementById('edit_notes').value = vendor.notes || '';
            document.getElementById('edit_is_active').checked = vendor.is_active == 1;
            
            new bootstrap.Modal(document.getElementById('editVendorModal')).show();
        }
        
        function deleteVendor(id, name) {
            document.getElementById('delete_id').value = id;
            document.getElementById('delete_name').textContent = name;
            
            new bootstrap.Modal(document.getElementById('deleteVendorModal')).show();
        }
    </script>
</body>
</html>
