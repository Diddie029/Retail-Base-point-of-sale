<?php
session_start();
require_once '../include/db.php';
require_once '../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
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

// Check if user has permission to manage payment methods
if (!hasPermission('manage_sales', $permissions) && !hasPermission('view_sales', $permissions)) {
    header('Location: ../dashboard/dashboard.php?error=access_denied');
    exit();
}

// Handle form submissions
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_payment_method':
                try {
                    $name = $_POST['name'];
                    $display_name = $_POST['display_name'];
                    $description = $_POST['description'];
                    $category = $_POST['category'];
                    $icon = $_POST['icon'];
                    $color = $_POST['color'];
                    $is_active = isset($_POST['is_active']) ? 1 : 0;
                    $requires_reconciliation = isset($_POST['requires_reconciliation']) ? 1 : 0;
                    $sort_order = $_POST['sort_order'];
                    
                    $stmt = $conn->prepare("
                        INSERT INTO payment_types (name, display_name, description, category, icon, color, is_active, requires_reconciliation, sort_order) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$name, $display_name, $description, $category, $icon, $color, $is_active, $requires_reconciliation, $sort_order]);
                    $success = 'Payment method added successfully!';
                } catch (Exception $e) {
                    $error = 'Error adding payment method: ' . $e->getMessage();
                }
                break;
                
            case 'edit_payment_method':
                try {
                    $id = $_POST['id'];
                    $name = $_POST['name'];
                    $display_name = $_POST['display_name'];
                    $description = $_POST['description'];
                    $category = $_POST['category'];
                    $icon = $_POST['icon'];
                    $color = $_POST['color'];
                    $is_active = isset($_POST['is_active']) ? 1 : 0;
                    $requires_reconciliation = isset($_POST['requires_reconciliation']) ? 1 : 0;
                    $sort_order = $_POST['sort_order'];
                    
                    $stmt = $conn->prepare("
                        UPDATE payment_types 
                        SET name = ?, display_name = ?, description = ?, category = ?, icon = ?, color = ?, is_active = ?, requires_reconciliation = ?, sort_order = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$name, $display_name, $description, $category, $icon, $color, $is_active, $requires_reconciliation, $sort_order, $id]);
                    $success = 'Payment method updated successfully!';
                } catch (Exception $e) {
                    $error = 'Error updating payment method: ' . $e->getMessage();
                }
                break;
                
            case 'delete_payment_method':
                try {
                    $id = $_POST['id'];
                    $stmt = $conn->prepare("DELETE FROM payment_types WHERE id = ?");
                    $stmt->execute([$id]);
                    $success = 'Payment method deleted successfully!';
                } catch (Exception $e) {
                    $error = 'Error deleting payment method: ' . $e->getMessage();
                }
                break;
        }
    }
}

// Get all payment methods
$stmt = $conn->query("
    SELECT * FROM payment_types 
    ORDER BY sort_order, display_name
");
$payment_methods = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get system settings
$stmt = $conn->query("SELECT * FROM settings");
$settings = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Methods - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/dashboard.css" rel="stylesheet">
</head>
<body>
    <?php include '../include/navmenu.php'; ?>

    <div class="main-content">
        <div class="container-fluid">
            <!-- Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2><i class="bi bi-credit-card"></i> Payment Methods</h2>
                    <p class="text-muted">Configure payment types and settings</p>
                </div>
                <div>
                    <a href="salesdashboard.php" class="btn btn-outline-secondary me-2">
                        <i class="bi bi-arrow-left"></i> Back to Dashboard
                    </a>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addPaymentMethodModal">
                        <i class="bi bi-plus"></i> Add Payment Method
                    </button>
                </div>
            </div>

            <!-- Alerts -->
            <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle"></i> <?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Payment Methods Grid -->
            <div class="row">
                <?php foreach ($payment_methods as $method): ?>
                <div class="col-md-4 mb-4">
                    <div class="card h-100">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <div class="d-flex align-items-center">
                                <div class="me-2">
                                    <div class="rounded-circle d-flex align-items-center justify-content-center" 
                                         style="width: 30px; height: 30px; background: <?php echo $method['color']; ?>;">
                                        <i class="<?php echo $method['icon']; ?> text-white"></i>
                                    </div>
                                </div>
                                <h6 class="mb-0"><?php echo htmlspecialchars($method['display_name']); ?></h6>
                            </div>
                            <div>
                                <span class="badge bg-<?php echo $method['is_active'] ? 'success' : 'secondary'; ?>">
                                    <?php echo $method['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </div>
                        </div>
                        <div class="card-body">
                            <p class="text-muted mb-2"><?php echo htmlspecialchars($method['description']); ?></p>
                            <div class="row text-center">
                                <div class="col-6">
                                    <small class="text-muted">Category</small>
                                    <div class="fw-semibold"><?php echo ucfirst($method['category']); ?></div>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted">Reconciliation</small>
                                    <div class="fw-semibold">
                                        <?php echo $method['requires_reconciliation'] ? 'Required' : 'Not Required'; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer">
                            <div class="btn-group w-100">
                                <button class="btn btn-outline-primary btn-sm" onclick="editPaymentMethod(<?php echo htmlspecialchars(json_encode($method)); ?>)">
                                    <i class="bi bi-pencil"></i> Edit
                                </button>
                                <button class="btn btn-outline-danger btn-sm" onclick="deletePaymentMethod(<?php echo $method['id']; ?>, '<?php echo htmlspecialchars($method['display_name']); ?>')">
                                    <i class="bi bi-trash"></i> Delete
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if (empty($payment_methods)): ?>
            <div class="text-center py-5">
                <i class="bi bi-credit-card fs-1 text-muted mb-3"></i>
                <h5 class="text-muted">No Payment Methods Found</h5>
                <p class="text-muted">Add your first payment method to get started</p>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addPaymentMethodModal">
                    <i class="bi bi-plus"></i> Add Payment Method
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add Payment Method Modal -->
    <div class="modal fade" id="addPaymentMethodModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="add_payment_method">
                    <div class="modal-header">
                        <h5 class="modal-title">Add Payment Method</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Name *</label>
                                    <input type="text" class="form-control" name="name" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Display Name *</label>
                                    <input type="text" class="form-control" name="display_name" required>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="2"></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Category</label>
                                    <select class="form-select" name="category">
                                        <option value="cash">Cash</option>
                                        <option value="digital">Digital</option>
                                        <option value="card">Card</option>
                                        <option value="bank">Bank</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Icon</label>
                                    <input type="text" class="form-control" name="icon" placeholder="bi-credit-card">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Color</label>
                                    <input type="color" class="form-control" name="color" value="#6c757d">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Sort Order</label>
                                    <input type="number" class="form-control" name="sort_order" value="0">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="is_active" checked>
                                    <label class="form-check-label">Active</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="requires_reconciliation" checked>
                                    <label class="form-check-label">Requires Reconciliation</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Payment Method</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Payment Method Modal -->
    <div class="modal fade" id="editPaymentMethodModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="edit_payment_method">
                    <input type="hidden" name="id" id="edit_id">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Payment Method</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Name *</label>
                                    <input type="text" class="form-control" name="name" id="edit_name" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Display Name *</label>
                                    <input type="text" class="form-control" name="display_name" id="edit_display_name" required>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" id="edit_description" rows="2"></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Category</label>
                                    <select class="form-select" name="category" id="edit_category">
                                        <option value="cash">Cash</option>
                                        <option value="digital">Digital</option>
                                        <option value="card">Card</option>
                                        <option value="bank">Bank</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Icon</label>
                                    <input type="text" class="form-control" name="icon" id="edit_icon">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Color</label>
                                    <input type="color" class="form-control" name="color" id="edit_color">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Sort Order</label>
                                    <input type="number" class="form-control" name="sort_order" id="edit_sort_order">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="is_active" id="edit_is_active">
                                    <label class="form-check-label">Active</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="requires_reconciliation" id="edit_requires_reconciliation">
                                    <label class="form-check-label">Requires Reconciliation</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Payment Method</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="delete_payment_method">
                    <input type="hidden" name="id" id="delete_id">
                    <div class="modal-header">
                        <h5 class="modal-title">Confirm Delete</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure you want to delete the payment method "<span id="delete_name"></span>"?</p>
                        <p class="text-danger"><strong>This action cannot be undone!</strong></p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete Payment Method</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function editPaymentMethod(method) {
            document.getElementById('edit_id').value = method.id;
            document.getElementById('edit_name').value = method.name;
            document.getElementById('edit_display_name').value = method.display_name;
            document.getElementById('edit_description').value = method.description || '';
            document.getElementById('edit_category').value = method.category;
            document.getElementById('edit_icon').value = method.icon;
            document.getElementById('edit_color').value = method.color;
            document.getElementById('edit_sort_order').value = method.sort_order;
            document.getElementById('edit_is_active').checked = method.is_active == 1;
            document.getElementById('edit_requires_reconciliation').checked = method.requires_reconciliation == 1;
            
            new bootstrap.Modal(document.getElementById('editPaymentMethodModal')).show();
        }
        
        function deletePaymentMethod(methodId, methodName) {
            document.getElementById('delete_id').value = methodId;
            document.getElementById('delete_name').textContent = methodName;
            new bootstrap.Modal(document.getElementById('deleteModal')).show();
        }
    </script>
</body>
</html>
