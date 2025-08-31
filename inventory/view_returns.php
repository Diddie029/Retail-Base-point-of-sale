<?php
session_start();

// Debug session configuration
error_log("Session save path: " . session_save_path());
error_log("Session cookie params: " . print_r(session_get_cookie_params(), true));
error_log("Session status: " . session_status());

// Ensure session is working properly
if (session_status() !== PHP_SESSION_ACTIVE) {
    error_log("Session not active, starting new session");
    session_start();
}
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Validate user exists in database
try {
    $stmt = $conn->prepare("SELECT id FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    if (!$stmt->fetch()) {
        // User doesn't exist, log them out
        session_destroy();
        header("Location: ../auth/login.php?error=user_not_found");
        exit();
    }
} catch (PDOException $e) {
    error_log("User validation error: " . $e->getMessage());
    header("Location: ../auth/login.php?error=db_error");
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

// Check if user has permission to view returns
if (!in_array('manage_products', $permissions) && !in_array('process_sales', $permissions)) {
    header("Location: inventory.php?error=permission_denied");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Handle filters and search
$search = $_GET['search'] ?? '';
$supplier_filter = $_GET['supplier'] ?? '';
$status_filter = $_GET['status'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;

// Handle bulk actions
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $return_ids = $_POST['return_ids'] ?? [];

    if (empty($return_ids)) {
        $message = "Please select at least one return.";
        $message_type = 'warning';
    } else {
        try {
            $conn->beginTransaction();
            $affected_rows = 0;

            switch ($action) {
                case 'mark_completed':
                    foreach ($return_ids as $return_id) {
                        $stmt = $conn->prepare("
                            UPDATE returns
                            SET status = 'completed', completed_at = NOW(), updated_at = NOW()
                            WHERE id = :return_id AND status IN ('approved', 'shipped', 'received')
                        ");
                        $stmt->execute([':return_id' => $return_id]);
                        $affected_rows += $stmt->rowCount();

                        // Log status change
                        logReturnStatusChange($conn, $return_id, 'completed', $user_id, 'Bulk status update');
                    }
                    $message = "$affected_rows return(s) marked as completed.";
                    $message_type = 'success';
                    break;

                case 'mark_cancelled':
                    foreach ($return_ids as $return_id) {
                        $stmt = $conn->prepare("
                            UPDATE returns
                            SET status = 'cancelled', updated_at = NOW()
                            WHERE id = :return_id AND status IN ('pending', 'approved')
                        ");
                        $stmt->execute([':return_id' => $return_id]);
                        $affected_rows += $stmt->rowCount();

                        // Log status change
                        logReturnStatusChange($conn, $return_id, 'cancelled', $user_id, 'Bulk status update');

                        // Restore inventory for cancelled returns
                        restoreInventoryForReturn($conn, $return_id);
                    }
                    $message = "$affected_rows return(s) cancelled and inventory restored.";
                    $message_type = 'success';
                    break;
            }

            $conn->commit();
        } catch (PDOException $e) {
            $conn->rollBack();
            $message = "Error updating returns: " . $e->getMessage();
            $message_type = 'danger';
        }
    }
}

// Build WHERE clause for returns
$where = [];
$params = [];

if (!empty($search)) {
    $where[] = "(r.return_number LIKE :search OR s.name LIKE :search OR u.username LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if (!empty($supplier_filter)) {
    $where[] = "r.supplier_id = :supplier_id";
    $params[':supplier_id'] = $supplier_filter;
}

if (!empty($status_filter)) {
    $where[] = "r.status = :status";
    $params[':status'] = $status_filter;
}

if (!empty($date_from)) {
    $where[] = "DATE(r.created_at) >= :date_from";
    $params[':date_from'] = $date_from;
}

if (!empty($date_to)) {
    $where[] = "DATE(r.created_at) <= :date_to";
    $params[':date_to'] = $date_to;
}

$where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Get total count for pagination
$count_sql = "
    SELECT COUNT(*) as total
    FROM returns r
    LEFT JOIN suppliers s ON r.supplier_id = s.id
    LEFT JOIN users u ON r.user_id = u.id
    $where_clause
";

$stmt = $conn->prepare($count_sql);
$stmt->execute($params);
$total_records = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_records / $per_page);

// Get returns with pagination
$offset = ($page - 1) * $per_page;
$sql = "
    SELECT r.*,
           s.name as supplier_name,
           u.username as created_by_name,
           COALESCE(au.username, 'System') as approved_by_name
    FROM returns r
    LEFT JOIN suppliers s ON r.supplier_id = s.id
    LEFT JOIN users u ON r.user_id = u.id
    LEFT JOIN users au ON r.approved_by = au.id
    $where_clause
    ORDER BY r.created_at DESC
    LIMIT :offset, :per_page
";

$stmt = $conn->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->bindValue(':per_page', $per_page, PDO::PARAM_INT);
$stmt->execute();
$returns = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get suppliers for filter dropdown
$suppliers = [];
try {
    $stmt = $conn->query("SELECT id, name FROM suppliers ORDER BY name ASC");
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error loading suppliers: " . $e->getMessage());
}

// Helper functions
function logReturnStatusChange($conn, $return_id, $new_status, $changed_by, $reason = '') {
    try {
        // Get current status
        $stmt = $conn->prepare("SELECT status FROM returns WHERE id = :return_id");
        $stmt->execute([':return_id' => $return_id]);
        $old_status = $stmt->fetch(PDO::FETCH_ASSOC)['status'];

        // Insert status history
        $stmt = $conn->prepare("
            INSERT INTO return_status_history (return_id, old_status, new_status, changed_by, change_reason)
            VALUES (:return_id, :old_status, :new_status, :changed_by, :change_reason)
        ");
        $stmt->execute([
            ':return_id' => $return_id,
            ':old_status' => $old_status,
            ':new_status' => $new_status,
            ':changed_by' => $changed_by,
            ':change_reason' => $reason
        ]);
    } catch (PDOException $e) {
        error_log("Error logging return status change: " . $e->getMessage());
    }
}

function restoreInventoryForReturn($conn, $return_id) {
    try {
        // Get return items and restore inventory
        $stmt = $conn->prepare("
            SELECT product_id, quantity
            FROM return_items
            WHERE return_id = :return_id
        ");
        $stmt->execute([':return_id' => $return_id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($items as $item) {
            $stmt = $conn->prepare("
                UPDATE products
                SET quantity = quantity + :quantity,
                    updated_at = NOW()
                WHERE id = :product_id
            ");
            $stmt->execute([
                ':quantity' => $item['quantity'],
                ':product_id' => $item['product_id']
            ]);
        }
    } catch (PDOException $e) {
        error_log("Error restoring inventory: " . $e->getMessage());
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Returns - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/inventory.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }

        .filter-section {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }

        .status-badge {
            padding: 0.375rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-pending { background: #fef3c7; color: #d97706; }
        .status-approved { background: #dbeafe; color: #2563eb; }
        .status-shipped { background: #e0e7ff; color: #3730a3; }
        .status-received { background: #f0fdf4; color: #166534; }
        .status-completed { background: #d1fae5; color: #059669; }
        .status-cancelled { background: #fee2e2; color: #dc2626; }

        .return-card {
            transition: all 0.3s ease;
        }

        .return-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .bulk-actions {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <?php
    $current_page = 'inventory';
    include __DIR__ . '/../include/navmenu.php';
    ?>

    <div class="main-content">
        <!-- Header -->
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h2>Return Management</h2>
                    <p class="header-subtitle">View and manage all product returns</p>
                </div>
                <div class="header-actions">
                    <a href="inventory.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-2"></i>Back to Inventory
                    </a>
                    <a href="create_return.php" class="btn btn-primary">
                        <i class="bi bi-plus-circle me-2"></i>Create Return
                    </a>
                </div>
            </div>
        </header>

        <!-- Content -->
        <main class="content">
            <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                <i class="bi bi-<?php echo $message_type === 'success' ? 'check-circle' : ($message_type === 'danger' ? 'exclamation-triangle' : 'info-circle'); ?> me-2"></i>
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card">
                        <div class="card-body text-center">
                            <h5 class="text-primary"><?php echo $total_records; ?></h5>
                            <p class="text-muted mb-0">Total Returns</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card">
                        <div class="card-body text-center">
                            <h5 class="text-warning">
                                <?php
                                $pending_count = 0;
                                foreach ($returns as $return) {
                                    if ($return['status'] === 'pending') $pending_count++;
                                }
                                echo $pending_count;
                                ?>
                            </h5>
                            <p class="text-muted mb-0">Pending</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card">
                        <div class="card-body text-center">
                            <h5 class="text-success">
                                <?php
                                $completed_count = 0;
                                foreach ($returns as $return) {
                                    if ($return['status'] === 'completed') $completed_count++;
                                }
                                echo $completed_count;
                                ?>
                            </h5>
                            <p class="text-muted mb-0">Completed</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card">
                        <div class="card-body text-center">
                            <h5 class="text-info">
                                <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?>
                                <?php
                                $total_value = 0;
                                foreach ($returns as $return) {
                                    $total_value += $return['total_amount'];
                                }
                                echo number_format($total_value, 2);
                                ?>
                            </h5>
                            <p class="text-muted mb-0">Total Value</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filter-section">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label for="search" class="form-label">Search</label>
                        <input type="text" class="form-control" id="search" name="search"
                               value="<?php echo htmlspecialchars($search); ?>"
                               placeholder="Return #, supplier, user...">
                    </div>
                    <div class="col-md-2">
                        <label for="supplier" class="form-label">Supplier</label>
                        <select class="form-select" id="supplier" name="supplier">
                            <option value="">All Suppliers</option>
                            <?php foreach ($suppliers as $supplier): ?>
                            <option value="<?php echo $supplier['id']; ?>"
                                    <?php echo $supplier_filter == $supplier['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($supplier['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="status" class="form-label">Status</label>
                        <select class="form-select" id="status" name="status">
                            <option value="">All Status</option>
                            <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                            <option value="shipped" <?php echo $status_filter === 'shipped' ? 'selected' : ''; ?>>Shipped</option>
                            <option value="received" <?php echo $status_filter === 'received' ? 'selected' : ''; ?>>Received</option>
                            <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="date_from" class="form-label">From Date</label>
                        <input type="date" class="form-control" id="date_from" name="date_from"
                               value="<?php echo htmlspecialchars($date_from); ?>">
                    </div>
                    <div class="col-md-2">
                        <label for="date_to" class="form-label">To Date</label>
                        <input type="date" class="form-control" id="date_to" name="date_to"
                               value="<?php echo htmlspecialchars($date_to); ?>">
                    </div>
                    <div class="col-md-1">
                        <label class="form-label">&nbsp;</label>
                        <div class="d-flex gap-1">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-search"></i>
                            </button>
                            <a href="view_returns.php" class="btn btn-outline-secondary">
                                <i class="bi bi-x-circle"></i>
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Bulk Actions -->
            <form method="POST" id="bulkForm">
                <input type="hidden" name="action" id="bulkAction">
                <div class="bulk-actions d-none" id="bulkActions">
                    <div class="d-flex justify-content-between align-items-center">
                        <span id="selectedCount">0 returns selected</span>
                        <div>
                            <button type="button" class="btn btn-success btn-sm me-2" onclick="submitBulkAction('mark_completed')">
                                <i class="bi bi-check-circle me-1"></i>Mark Completed
                            </button>
                            <button type="button" class="btn btn-danger btn-sm" onclick="submitBulkAction('mark_cancelled')">
                                <i class="bi bi-x-circle me-1"></i>Cancel Returns
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Returns Table -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Returns (<?php echo $total_records; ?> total)</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($returns)): ?>
                        <div class="text-center py-4">
                            <i class="bi bi-search fs-1 text-muted mb-3"></i>
                            <h5 class="text-muted">No returns found</h5>
                            <p class="text-muted">Try adjusting your filters or create a new return.</p>
                            <a href="create_return.php" class="btn btn-primary">
                                <i class="bi bi-plus-circle me-2"></i>Create First Return
                            </a>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th width="40">
                                            <input type="checkbox" id="selectAll" class="form-check-input">
                                        </th>
                                        <th>Return #</th>
                                        <th>Supplier</th>
                                        <th>Items</th>
                                        <th>Total Amount</th>
                                        <th>Status</th>
                                        <th>Reason</th>
                                        <th>Created</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($returns as $return): ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" name="return_ids[]" value="<?php echo $return['id']; ?>"
                                                   class="form-check-input return-checkbox">
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($return['return_number']); ?></strong>
                                        </td>
                                        <td><?php echo htmlspecialchars($return['supplier_name']); ?></td>
                                        <td><?php echo $return['total_items']; ?> items</td>
                                        <td><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($return['total_amount'], 2); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo $return['status']; ?>">
                                                <?php echo ucfirst($return['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo ucfirst(str_replace('_', ' ', $return['return_reason'])); ?></td>
                                        <td><?php echo date('M j, Y', strtotime($return['created_at'])); ?></td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <a href="view_return.php?id=<?php echo $return['id']; ?>"
                                                   class="btn btn-sm btn-outline-primary">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <?php if ($return['status'] === 'pending'): ?>
                                                <a href="edit_return.php?id=<?php echo $return['id']; ?>"
                                                   class="btn btn-sm btn-outline-secondary">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <?php endif; ?>
                                                <a href="print_return.php?id=<?php echo $return['id']; ?>"
                                                   class="btn btn-sm btn-outline-info" target="_blank">
                                                    <i class="bi bi-printer"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                        <nav aria-label="Returns pagination" class="mt-4">
                            <ul class="pagination justify-content-center">
                                <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                        Previous
                                    </a>
                                </li>
                                <?php endif; ?>

                                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                                <?php endfor; ?>

                                <?php if ($page < $total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                        Next
                                    </a>
                                </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Select all functionality
        document.getElementById('selectAll').addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.return-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
            updateBulkActions();
        });

        // Individual checkbox change
        document.addEventListener('change', function(e) {
            if (e.target.classList.contains('return-checkbox')) {
                updateBulkActions();
            }
        });

        function updateBulkActions() {
            const checkedBoxes = document.querySelectorAll('.return-checkbox:checked');
            const bulkActions = document.getElementById('bulkActions');
            const selectedCount = document.getElementById('selectedCount');

            if (checkedBoxes.length > 0) {
                bulkActions.classList.remove('d-none');
                selectedCount.textContent = checkedBoxes.length + ' return(s) selected';
            } else {
                bulkActions.classList.add('d-none');
            }
        }

        function submitBulkAction(action) {
            if (!confirm(`Are you sure you want to ${action.replace('_', ' ')} the selected returns?`)) {
                return;
            }

            document.getElementById('bulkAction').value = action;
            document.getElementById('bulkForm').submit();
        }

        // Auto-hide alerts
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
    </script>
</body>
</html>
