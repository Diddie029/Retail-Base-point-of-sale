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
// Allow access if user is admin OR has sales permissions
$hasAccess = false;

// Check if user is admin
if (isAdmin($role_name)) {
    $hasAccess = true;
}

// Check if user has sales permissions
if (!$hasAccess && !empty($permissions)) {
    if (hasPermission('manage_sales', $permissions) || hasPermission('view_sales', $permissions)) {
        $hasAccess = true;
    }
}

// Check if user has admin access through permissions
if (!$hasAccess && hasAdminAccess($role_name, $permissions)) {
    $hasAccess = true;
}

if (!$hasAccess) {
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

// Get all tills with held transaction details
$stmt = $conn->query("
    SELECT rt.*, u.username as assigned_user_name,
           COALESCE(ht.held_count, 0) as held_transactions_count
    FROM register_tills rt
    LEFT JOIN users u ON rt.assigned_user_id = u.id
    LEFT JOIN (
        SELECT till_id, COUNT(*) as held_count
        FROM held_transactions 
        WHERE status = 'held'
        GROUP BY till_id
    ) ht ON rt.id = ht.till_id
    ORDER BY rt.till_name
");
$tills = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Add held transactions with NULL till_id to the currently selected till
$selected_till_id = $_SESSION['selected_till_id'] ?? null;
if ($selected_till_id) {
    // Get count of held transactions with NULL till_id
    $stmt = $conn->prepare("SELECT COUNT(*) as null_till_count FROM held_transactions WHERE status = 'held' AND till_id IS NULL");
    $stmt->execute();
    $null_till_result = $stmt->fetch(PDO::FETCH_ASSOC);
    $null_till_count = $null_till_result['null_till_count'];
    
    // Add this count to the selected till
    foreach ($tills as &$till) {
        if ($till['id'] == $selected_till_id) {
            $till['held_transactions_count'] += $null_till_count;
        }
    }
}


// Get currently selected till from session
$selected_till_id = $_SESSION['selected_till_id'] ?? null;

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
            <div class="row g-3">
                <?php foreach ($tills as $till): ?>
                <div class="col-lg-4 col-md-6">
                    <div class="card h-100 till-card <?php echo ($selected_till_id && $till['id'] == $selected_till_id) ? 'selected-till' : ''; ?>">
                        <!-- Compact Header -->
                        <div class="card-header py-2">
                            <div class="d-flex justify-content-between align-items-center">
                                <h6 class="mb-0 fw-bold"><?php echo htmlspecialchars($till['till_name']); ?></h6>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="till-status <?php echo $till['is_active'] ? 'till-active' : 'till-inactive'; ?>"></span>
                                    <span class="badge bg-<?php echo $till['is_active'] ? 'success' : 'secondary'; ?> badge-sm">
                                        <?php echo $till['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="card-body p-3">
                            <!-- Till Details - Compact -->
                            <div class="till-details mb-3">
                                <div class="row g-2">
                                    <div class="col-6">
                                        <small class="text-muted">Code</small>
                                        <div class="fw-bold"><?php echo htmlspecialchars($till['till_code']); ?></div>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted">Location</small>
                                        <div class="fw-bold"><?php echo htmlspecialchars($till['location']); ?></div>
                                    </div>
                                </div>
                                <div class="mt-2">
                                    <small class="text-muted">Assigned to</small>
                                    <div class="fw-bold">
                                        <?php if ($till['assigned_user_name']): ?>
                                            <?php echo htmlspecialchars($till['assigned_user_name']); ?>
                                        <?php else: ?>
                                            <span class="text-muted">Not assigned</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Till Status Info - Show for all tills -->
                            <div class="till-status-info mb-3">
                                <div class="row g-2">
                                    <?php if ($selected_till_id && $till['id'] == $selected_till_id): ?>
                                    <div class="col-6">
                                        <div class="status-badge status-success">
                                            <i class="bi bi-person-check-circle me-1"></i>
                                            <div>
                                                <small>Active Cashier</small>
                                                <div class="fw-bold"><?php echo htmlspecialchars($username); ?></div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php else: ?>
                                    <div class="col-6">
                                        <div class="status-badge status-secondary">
                                            <i class="bi bi-cash-register me-1"></i>
                                            <div>
                                                <small>Status</small>
                                                <div class="fw-bold"><?php echo $till['is_active'] ? 'Active' : 'Inactive'; ?></div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    <div class="col-6">
                                        <div class="status-badge <?php echo $till['held_transactions_count'] > 0 ? 'status-warning' : 'status-info'; ?>">
                                            <i class="bi bi-clock-history me-1"></i>
                                            <div>
                                                <small>On Hold</small>
                                                <div class="fw-bold"><?php echo $till['held_transactions_count']; ?></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            
                            <!-- Balance Info - Compact -->
                            <div class="balance-info">
                                <div class="row g-2 text-center">
                                    <div class="col-6">
                                        <div class="balance-item">
                                            <small class="text-muted d-block">Opening</small>
                                            <div class="fw-bold text-primary">KES <?php echo number_format($till['opening_balance'], 2); ?></div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="balance-item">
                                            <small class="text-muted d-block">Current</small>
                                            <div class="fw-bold text-success">KES <?php echo number_format($till['current_balance'], 2); ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Compact Footer -->
                        <div class="card-footer py-2">
                            <div class="btn-group w-100" role="group">
                                <button class="btn btn-outline-primary btn-sm" onclick="editTill(<?php echo htmlspecialchars(json_encode($till)); ?>)">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button class="btn btn-outline-danger btn-sm" onclick="deleteTill(<?php echo $till['id']; ?>, '<?php echo htmlspecialchars($till['till_name']); ?>')">
                                    <i class="bi bi-trash"></i>
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
        /* Till Status Indicators */
        .till-status {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
        }
        .till-active { background-color: #28a745; }
        .till-inactive { background-color: #dc3545; }
        
        /* Badge Sizing */
        .badge-sm {
            font-size: 0.7rem;
            padding: 0.25rem 0.5rem;
        }
        
        /* Till Card Styling */
        .till-card {
            transition: all 0.3s ease;
            border: 1px solid #e9ecef;
        }
        
        .till-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .selected-till {
            border-color: #0d6efd;
            box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.25);
        }
        
        /* Status Badge Styling */
        .status-badge {
            display: flex;
            align-items: center;
            padding: 0.5rem;
            border-radius: 0.375rem;
            font-size: 0.8rem;
        }
        
        .status-success {
            background-color: #d1edff;
            color: #0c5460;
            border: 1px solid #b8daff;
        }
        
        .status-warning {
            background-color: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }
        
        .status-info {
            background-color: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        
        .status-secondary {
            background-color: #e2e3e5;
            color: #383d41;
            border: 1px solid #d6d8db;
        }
        
        /* Till Details */
        .till-details {
            font-size: 0.9rem;
        }
        
        /* Balance Info */
        .balance-item {
            padding: 0.5rem;
            border-radius: 0.375rem;
            background-color: #f8f9fa;
        }
        
        /* Compact Layout */
        .card-header {
            background-color: #f8f9fa;
            border-bottom: 1px solid #e9ecef;
        }
        
        .card-footer {
            background-color: #f8f9fa;
            border-top: 1px solid #e9ecef;
        }
        
        /* Responsive Grid */
        @media (max-width: 768px) {
            .col-lg-4 {
                margin-bottom: 1rem;
            }
        }
        
        /* Button Group */
        .btn-group .btn {
            font-size: 0.8rem;
        }
        
    </style>
</body>
</html>
