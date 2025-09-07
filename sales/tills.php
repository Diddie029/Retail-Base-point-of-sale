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

// Check if user has permission to manage tills
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
            case 'add_till':
                try {
                    $till_name = $_POST['till_name'];
                    $till_code = $_POST['till_code'];
                    $location = $_POST['location'];
                    $opening_balance = $_POST['opening_balance'];
                    $assigned_user_id = $_POST['assigned_user_id'] ?: null;
                    
                    $stmt = $conn->prepare("
                        INSERT INTO register_tills (till_name, till_code, location, opening_balance, current_balance, assigned_user_id) 
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$till_name, $till_code, $location, $opening_balance, $opening_balance, $assigned_user_id]);
                    $success = 'Till added successfully!';
                } catch (Exception $e) {
                    $error = 'Error adding till: ' . $e->getMessage();
                }
                break;
                
            case 'edit_till':
                try {
                    $till_id = $_POST['till_id'];
                    $till_name = $_POST['till_name'];
                    $till_code = $_POST['till_code'];
                    $location = $_POST['location'];
                    $assigned_user_id = $_POST['assigned_user_id'] ?: null;
                    $is_active = isset($_POST['is_active']) ? 1 : 0;
                    
                    $stmt = $conn->prepare("
                        UPDATE register_tills 
                        SET till_name = ?, till_code = ?, location = ?, assigned_user_id = ?, is_active = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$till_name, $till_code, $location, $assigned_user_id, $is_active, $till_id]);
                    $success = 'Till updated successfully!';
                } catch (Exception $e) {
                    $error = 'Error updating till: ' . $e->getMessage();
                }
                break;
                
            case 'delete_till':
                try {
                    $till_id = $_POST['till_id'];
                    $stmt = $conn->prepare("DELETE FROM register_tills WHERE id = ?");
                    $stmt->execute([$till_id]);
                    $success = 'Till deleted successfully!';
                } catch (Exception $e) {
                    $error = 'Error deleting till: ' . $e->getMessage();
                }
                break;
        }
    }
}

// Get all tills
$stmt = $conn->query("
    SELECT rt.*, u.username as assigned_user_name
    FROM register_tills rt
    LEFT JOIN users u ON rt.assigned_user_id = u.id
    ORDER BY rt.till_name
");
$tills = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get users for assignment
$stmt = $conn->query("SELECT id, username FROM users ORDER BY username");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register Tills - POS System</title>
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
                    <h2><i class="bi bi-cash-register"></i> Register Tills</h2>
                    <p class="text-muted">Manage cash registers and till assignments</p>
                </div>
                <div>
                    <a href="salesdashboard.php" class="btn btn-outline-secondary me-2">
                        <i class="bi bi-arrow-left"></i> Back to Dashboard
                    </a>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTillModal">
                        <i class="bi bi-plus"></i> Add Till
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

            <!-- Tills Grid -->
            <div class="row">
                <?php foreach ($tills as $till): ?>
                <div class="col-md-4 mb-4">
                    <div class="card h-100">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h6 class="mb-0"><?php echo htmlspecialchars($till['till_name']); ?></h6>
                            <div>
                                <span class="till-status <?php echo $till['is_active'] ? 'till-active' : 'till-inactive'; ?>"></span>
                                <span class="badge bg-<?php echo $till['is_active'] ? 'success' : 'secondary'; ?>">
                                    <?php echo $till['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <strong>Code:</strong> <?php echo htmlspecialchars($till['till_code']); ?><br>
                                <strong>Location:</strong> <?php echo htmlspecialchars($till['location']); ?><br>
                                <strong>Assigned to:</strong> 
                                <?php if ($till['assigned_user_name']): ?>
                                    <?php echo htmlspecialchars($till['assigned_user_name']); ?>
                                <?php else: ?>
                                    <span class="text-muted">Not assigned</span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="row text-center">
                                <div class="col-6">
                                    <div class="border-end">
                                        <h6 class="text-muted mb-1">Opening Balance</h6>
                                        <h5 class="text-primary">KES <?php echo number_format($till['opening_balance'], 2); ?></h5>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <h6 class="text-muted mb-1">Current Balance</h6>
                                    <h5 class="text-success">KES <?php echo number_format($till['current_balance'], 2); ?></h5>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer">
                            <div class="btn-group w-100">
                                <button class="btn btn-outline-primary btn-sm" onclick="editTill(<?php echo htmlspecialchars(json_encode($till)); ?>)">
                                    <i class="bi bi-pencil"></i> Edit
                                </button>
                                <button class="btn btn-outline-danger btn-sm" onclick="deleteTill(<?php echo $till['id']; ?>, '<?php echo htmlspecialchars($till['till_name']); ?>')">
                                    <i class="bi bi-trash"></i> Delete
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if (empty($tills)): ?>
            <div class="text-center py-5">
                <i class="bi bi-cash-register fs-1 text-muted mb-3"></i>
                <h5 class="text-muted">No Tills Found</h5>
                <p class="text-muted">Add your first till to get started</p>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTillModal">
                    <i class="bi bi-plus"></i> Add Till
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add Till Modal -->
    <div class="modal fade" id="addTillModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="add_till">
                    <div class="modal-header">
                        <h5 class="modal-title">Add New Till</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Till Name *</label>
                            <input type="text" class="form-control" name="till_name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Till Code *</label>
                            <input type="text" class="form-control" name="till_code" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Location</label>
                            <input type="text" class="form-control" name="location">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Opening Balance</label>
                            <input type="number" class="form-control" name="opening_balance" step="0.01" value="0">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Assign to User</label>
                            <select class="form-select" name="assigned_user_id">
                                <option value="">Select User</option>
                                <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['id']; ?>">
                                    <?php echo htmlspecialchars($user['username']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Till</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Till Modal -->
    <div class="modal fade" id="editTillModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="edit_till">
                    <input type="hidden" name="till_id" id="edit_till_id">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Till</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Till Name *</label>
                            <input type="text" class="form-control" name="till_name" id="edit_till_name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Till Code *</label>
                            <input type="text" class="form-control" name="till_code" id="edit_till_code" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Location</label>
                            <input type="text" class="form-control" name="location" id="edit_location">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Assign to User</label>
                            <select class="form-select" name="assigned_user_id" id="edit_assigned_user_id">
                                <option value="">Select User</option>
                                <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['id']; ?>">
                                    <?php echo htmlspecialchars($user['username']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_active" id="edit_is_active">
                                <label class="form-check-label" for="edit_is_active">
                                    Active
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Till</button>
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
                    <input type="hidden" name="action" value="delete_till">
                    <input type="hidden" name="till_id" id="delete_till_id">
                    <div class="modal-header">
                        <h5 class="modal-title">Confirm Delete</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure you want to delete the till "<span id="delete_till_name"></span>"?</p>
                        <p class="text-danger"><strong>This action cannot be undone!</strong></p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete Till</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function editTill(till) {
            document.getElementById('edit_till_id').value = till.id;
            document.getElementById('edit_till_name').value = till.till_name;
            document.getElementById('edit_till_code').value = till.till_code;
            document.getElementById('edit_location').value = till.location || '';
            document.getElementById('edit_assigned_user_id').value = till.assigned_user_id || '';
            document.getElementById('edit_is_active').checked = till.is_active == 1;
            
            new bootstrap.Modal(document.getElementById('editTillModal')).show();
        }
        
        function deleteTill(tillId, tillName) {
            document.getElementById('delete_till_id').value = tillId;
            document.getElementById('delete_till_name').textContent = tillName;
            new bootstrap.Modal(document.getElementById('deleteModal')).show();
        }
    </script>
    <style>
        .till-status {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 0.5rem;
        }
        .till-active { background-color: #28a745; }
        .till-inactive { background-color: #dc3545; }
    </style>
</body>
</html>
