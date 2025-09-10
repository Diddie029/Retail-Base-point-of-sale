<?php
session_start();
require_once '../include/db.php';
require_once '../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

// Check if user has admin role
if ($_SESSION['role'] !== 'Admin') {
    header('Location: ../dashboard/dashboard.php');
    exit();
}

$message = '';
$message_type = '';

// Handle form submission
if ($_POST) {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_payment_type':
                $data = [
                    'name' => $_POST['name'],
                    'display_name' => $_POST['display_name'],
                    'description' => $_POST['description'],
                    'category' => $_POST['category'],
                    'icon' => $_POST['icon'],
                    'color' => $_POST['color'],
                    'is_active' => isset($_POST['is_active']) ? 1 : 0,
                    'requires_reconciliation' => isset($_POST['requires_reconciliation']) ? 1 : 0,
                    'sort_order' => (int)$_POST['sort_order']
                ];
                
                if (savePaymentMethod($conn, $data)) {
                    $message = 'Payment type added successfully!';
                    $message_type = 'success';
                } else {
                    $message = 'Failed to add payment type.';
                    $message_type = 'danger';
                }
                break;
                
            case 'update_payment_type':
                $id = (int)$_POST['id'];
                $data = [
                    'name' => $_POST['name'],
                    'display_name' => $_POST['display_name'],
                    'description' => $_POST['description'],
                    'category' => $_POST['category'],
                    'icon' => $_POST['icon'],
                    'color' => $_POST['color'],
                    'is_active' => isset($_POST['is_active']) ? 1 : 0,
                    'requires_reconciliation' => isset($_POST['requires_reconciliation']) ? 1 : 0,
                    'sort_order' => (int)$_POST['sort_order']
                ];
                
                try {
                    $stmt = $conn->prepare("
                        UPDATE payment_types 
                        SET name = :name, display_name = :display_name, description = :description,
                            category = :category, icon = :icon, color = :color, is_active = :is_active,
                            requires_reconciliation = :requires_reconciliation, sort_order = :sort_order,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = :id
                    ");
                    
                    if ($stmt->execute(array_merge($data, [':id' => $id]))) {
                        $message = 'Payment type updated successfully!';
                        $message_type = 'success';
                    } else {
                        $message = 'Failed to update payment type.';
                        $message_type = 'danger';
                    }
                } catch (PDOException $e) {
                    $message = 'Error updating payment type: ' . $e->getMessage();
                    $message_type = 'danger';
                }
                break;
                
            case 'delete_payment_type':
                $id = (int)$_POST['id'];
                try {
                    $stmt = $conn->prepare("DELETE FROM payment_types WHERE id = :id");
                    if ($stmt->execute([':id' => $id])) {
                        $message = 'Payment type deleted successfully!';
                        $message_type = 'success';
                    } else {
                        $message = 'Failed to delete payment type.';
                        $message_type = 'danger';
                    }
                } catch (PDOException $e) {
                    $message = 'Error deleting payment type: ' . $e->getMessage();
                    $message_type = 'danger';
                }
                break;
                
            case 'add_default_types':
                $default_types = [
                    [
                        'name' => 'cash',
                        'display_name' => 'Cash',
                        'description' => 'Cash payment',
                        'category' => 'cash',
                        'icon' => 'bi-cash',
                        'color' => '#28a745',
                        'is_active' => 1,
                        'requires_reconciliation' => 1,
                        'sort_order' => 1
                    ],
                    [
                        'name' => 'loyalty_points',
                        'display_name' => 'Loyalty Points',
                        'description' => 'Pay with loyalty points',
                        'category' => 'other',
                        'icon' => 'bi-gift',
                        'color' => '#ffc107',
                        'is_active' => 1,
                        'requires_reconciliation' => 0,
                        'sort_order' => 2
                    ],
                    [
                        'name' => 'mobile_money',
                        'display_name' => 'Mobile Money',
                        'description' => 'Pay via mobile money (M-Pesa, Airtel Money, etc.)',
                        'category' => 'digital',
                        'icon' => 'bi-phone',
                        'color' => '#17a2b8',
                        'is_active' => 1,
                        'requires_reconciliation' => 1,
                        'sort_order' => 3
                    ],
                    [
                        'name' => 'card',
                        'display_name' => 'Card Payment',
                        'description' => 'Credit/Debit card payment',
                        'category' => 'card',
                        'icon' => 'bi-credit-card',
                        'color' => '#6f42c1',
                        'is_active' => 1,
                        'requires_reconciliation' => 1,
                        'sort_order' => 4
                    ],
                    [
                        'name' => 'bank_transfer',
                        'display_name' => 'Bank Transfer',
                        'description' => 'Direct bank transfer',
                        'category' => 'bank',
                        'icon' => 'bi-bank',
                        'color' => '#dc3545',
                        'is_active' => 1,
                        'requires_reconciliation' => 1,
                        'sort_order' => 5
                    ]
                ];
                
                $success_count = 0;
                foreach ($default_types as $type) {
                    if (savePaymentMethod($conn, $type)) {
                        $success_count++;
                    }
                }
                
                if ($success_count > 0) {
                    $message = "Successfully added {$success_count} default payment types!";
                    $message_type = 'success';
                } else {
                    $message = 'Failed to add default payment types.';
                    $message_type = 'danger';
                }
                break;
        }
    }
}

// Get all payment types
try {
    $stmt = $conn->query("
        SELECT * FROM payment_types 
        ORDER BY sort_order, display_name
    ");
    $payment_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $payment_types = [];
    $message = 'Error loading payment types: ' . $e->getMessage();
    $message_type = 'danger';
}

// Get payment method statistics
try {
    $stmt = $conn->query("
        SELECT 
            sp.payment_method,
            COUNT(*) as transaction_count,
            SUM(sp.amount) as total_revenue
        FROM sale_payments sp
        JOIN sales s ON sp.sale_id = s.id
        WHERE s.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY sp.payment_method
        ORDER BY total_revenue DESC
    ");
    $payment_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $payment_stats = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Types Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <?php include '../include/navmenu.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Payment Types Management</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addPaymentTypeModal">
                            <i class="bi bi-plus-circle me-1"></i>Add Payment Type
                        </button>
                        <form method="POST" class="d-inline ms-2">
                            <input type="hidden" name="action" value="add_default_types">
                            <button type="submit" class="btn btn-success" onclick="return confirm('This will add default payment types. Continue?')">
                                <i class="bi bi-download me-1"></i>Add Default Types
                            </button>
                        </form>
                    </div>
                </div>

                <?php if ($message): ?>
                    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Payment Statistics -->
                <?php if (!empty($payment_stats)): ?>
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Payment Method Statistics (Last 30 Days)</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Payment Method</th>
                                                <th>Transactions</th>
                                                <th>Total Revenue</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($payment_stats as $stat): ?>
                                            <tr>
                                                <td>
                                                    <span class="badge bg-secondary">
                                                        <?php echo htmlspecialchars($stat['payment_method'] ?? 'Unknown'); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo number_format($stat['transaction_count']); ?></td>
                                                <td>KES <?php echo number_format($stat['total_revenue'], 2); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Payment Types Table -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Payment Types</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Name</th>
                                        <th>Display Name</th>
                                        <th>Category</th>
                                        <th>Icon</th>
                                        <th>Color</th>
                                        <th>Status</th>
                                        <th>Sort Order</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($payment_types as $type): ?>
                                    <tr>
                                        <td><?php echo $type['id']; ?></td>
                                        <td><code><?php echo htmlspecialchars($type['name']); ?></code></td>
                                        <td><?php echo htmlspecialchars($type['display_name']); ?></td>
                                        <td>
                                            <span class="badge bg-info">
                                                <?php echo htmlspecialchars($type['category']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <i class="<?php echo htmlspecialchars($type['icon']); ?>"></i>
                                            <small class="text-muted"><?php echo htmlspecialchars($type['icon']); ?></small>
                                        </td>
                                        <td>
                                            <span class="badge" style="background-color: <?php echo htmlspecialchars($type['color']); ?>; color: white;">
                                                <?php echo htmlspecialchars($type['color']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($type['is_active']): ?>
                                                <span class="badge bg-success">Active</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $type['sort_order']; ?></td>
                                        <td>
                                            <button type="button" class="btn btn-sm btn-outline-primary" 
                                                    onclick="editPaymentType(<?php echo htmlspecialchars(json_encode($type)); ?>)">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <form method="POST" class="d-inline" 
                                                  onsubmit="return confirm('Are you sure you want to delete this payment type?')">
                                                <input type="hidden" name="action" value="delete_payment_type">
                                                <input type="hidden" name="id" value="<?php echo $type['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Add Payment Type Modal -->
    <div class="modal fade" id="addPaymentTypeModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="add_payment_type">
                    <div class="modal-header">
                        <h5 class="modal-title">Add Payment Type</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="name" class="form-label">Name (Internal)</label>
                            <input type="text" class="form-control" id="name" name="name" required>
                            <div class="form-text">Internal identifier (e.g., cash, loyalty_points)</div>
                        </div>
                        <div class="mb-3">
                            <label for="display_name" class="form-label">Display Name</label>
                            <input type="text" class="form-control" id="display_name" name="display_name" required>
                        </div>
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="2"></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="category" class="form-label">Category</label>
                            <select class="form-select" id="category" name="category" required>
                                <option value="cash">Cash</option>
                                <option value="digital">Digital</option>
                                <option value="card">Card</option>
                                <option value="bank">Bank</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="icon" class="form-label">Icon (Bootstrap Icons)</label>
                            <input type="text" class="form-control" id="icon" name="icon" placeholder="bi-cash">
                            <div class="form-text">Bootstrap Icons class name (e.g., bi-cash, bi-credit-card)</div>
                        </div>
                        <div class="mb-3">
                            <label for="color" class="form-label">Color</label>
                            <input type="color" class="form-control form-control-color" id="color" name="color" value="#6c757d">
                        </div>
                        <div class="mb-3">
                            <label for="sort_order" class="form-label">Sort Order</label>
                            <input type="number" class="form-control" id="sort_order" name="sort_order" value="0">
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="is_active" name="is_active" checked>
                            <label class="form-check-label" for="is_active">
                                Active
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="requires_reconciliation" name="requires_reconciliation" checked>
                            <label class="form-check-label" for="requires_reconciliation">
                                Requires Reconciliation
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Payment Type</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Payment Type Modal -->
    <div class="modal fade" id="editPaymentTypeModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="update_payment_type">
                    <input type="hidden" name="id" id="edit_id">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Payment Type</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="edit_name" class="form-label">Name (Internal)</label>
                            <input type="text" class="form-control" id="edit_name" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_display_name" class="form-label">Display Name</label>
                            <input type="text" class="form-control" id="edit_display_name" name="display_name" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_description" class="form-label">Description</label>
                            <textarea class="form-control" id="edit_description" name="description" rows="2"></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="edit_category" class="form-label">Category</label>
                            <select class="form-select" id="edit_category" name="category" required>
                                <option value="cash">Cash</option>
                                <option value="digital">Digital</option>
                                <option value="card">Card</option>
                                <option value="bank">Bank</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="edit_icon" class="form-label">Icon (Bootstrap Icons)</label>
                            <input type="text" class="form-control" id="edit_icon" name="icon">
                        </div>
                        <div class="mb-3">
                            <label for="edit_color" class="form-label">Color</label>
                            <input type="color" class="form-control form-control-color" id="edit_color" name="color">
                        </div>
                        <div class="mb-3">
                            <label for="edit_sort_order" class="form-label">Sort Order</label>
                            <input type="number" class="form-control" id="edit_sort_order" name="sort_order">
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="edit_is_active" name="is_active">
                            <label class="form-check-label" for="edit_is_active">
                                Active
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="edit_requires_reconciliation" name="requires_reconciliation">
                            <label class="form-check-label" for="edit_requires_reconciliation">
                                Requires Reconciliation
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Payment Type</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function editPaymentType(type) {
            document.getElementById('edit_id').value = type.id;
            document.getElementById('edit_name').value = type.name;
            document.getElementById('edit_display_name').value = type.display_name;
            document.getElementById('edit_description').value = type.description || '';
            document.getElementById('edit_category').value = type.category;
            document.getElementById('edit_icon').value = type.icon;
            document.getElementById('edit_color').value = type.color;
            document.getElementById('edit_sort_order').value = type.sort_order;
            document.getElementById('edit_is_active').checked = type.is_active == 1;
            document.getElementById('edit_requires_reconciliation').checked = type.requires_reconciliation == 1;
            
            new bootstrap.Modal(document.getElementById('editPaymentTypeModal')).show();
        }
    </script>
</body>
</html>
