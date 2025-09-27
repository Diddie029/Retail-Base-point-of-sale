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

// Generate CSRF token for security
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Generate unique transaction ID to prevent duplicate submissions
if (!isset($_SESSION['transaction_id'])) {
    $_SESSION['transaction_id'] = uniqid('txn_', true);
}

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

// Check if user has permission to manage cash drops
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

// Security: Rate limiting for cash drops (max 10 per hour per user)
$rate_limit_key = "cash_drop_rate_{$user_id}";
$rate_limit_file = sys_get_temp_dir() . "/{$rate_limit_key}";
$current_time = time();
$rate_limit_window = 3600; // 1 hour
$max_requests = 10;

if (file_exists($rate_limit_file)) {
    $last_requests = json_decode(file_get_contents($rate_limit_file), true) ?: [];
    $last_requests = array_filter($last_requests, function($timestamp) use ($current_time, $rate_limit_window) {
        return ($current_time - $timestamp) < $rate_limit_window;
    });
    
    if (count($last_requests) >= $max_requests) {
        $error = 'Too many cash drop requests. Please wait before making another request.';
    }
}

// Handle form submissions and session messages
$success = '';
$error = '';

// Check for session messages and clear them
if (isset($_SESSION['success_message'])) {
    $success = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

if (isset($_SESSION['error_message'])) {
    $error = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Security: Validate session and CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error_message'] = 'Invalid request. Please try again.';
        header("Location: cash-drop.php?error=1");
        exit();
    } else if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_cash_drop':
                try {
                    // Check for duplicate submission using transaction ID
                    $transaction_id = $_POST['transaction_id'] ?? '';
                    if ($transaction_id !== $_SESSION['transaction_id']) {
                        $_SESSION['error_message'] = 'Duplicate submission detected. Please try again.';
                        header("Location: cash-drop.php?error=1");
                        exit();
                    }
                    
                    $till_id = $_POST['till_id'];
                    $drop_amount = floatval($_POST['drop_amount']);
                    $drop_type = $_POST['drop_type'] ?? 'emergency_drop';
                    $notes = $_POST['notes'];

                    // Validate drop amount is not negative
                    if ($drop_amount <= 0) {
                        $_SESSION['error_message'] = 'Drop amount must be greater than zero!';
                        header("Location: cash-drop.php?error=1");
                        exit();
                    }

                    // Security: Only allow emergency drops
                    if ($drop_type !== 'emergency_drop') {
                        $_SESSION['error_message'] = 'Only emergency drops are permitted for security control.';
                        header("Location: cash-drop.php?error=1");
                        exit();
                    }

                    // Security: Log all cash drop attempts for audit trail
                    error_log("Cash Drop Attempt - User: {$username} (ID: {$user_id}), Till ID: {$till_id}, Amount: {$drop_amount}, Type: {$drop_type}, IP: " . $_SERVER['REMOTE_ADDR']);

                    // Security: Require manager approval for large amounts (over 10,000)
                    $large_amount_threshold = 10000;
                    if ($drop_amount > $large_amount_threshold) {
                        // Check if user has manager permissions
                        $is_manager = hasPermission('manage_sales', $permissions) || isAdmin($role_name);
                        if (!$is_manager) {
                            $error = 'Large cash drops require manager approval. Please contact a manager.';
                        break;
                        }
                    }

                    // Get current till balance before drop
                    $stmt = $conn->prepare("SELECT current_balance FROM register_tills WHERE id = ?");
                    $stmt->execute([$till_id]);
                    $till_balance = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$till_balance) {
                        $error = 'Till not found!';
                        break;
                    }

                    // Note: Drop amount can exceed available balance - till short report will handle discrepancies

                    // Add cash drop with enhanced tracking
                    $stmt = $conn->prepare("
                        INSERT INTO cash_drops (till_id, user_id, drop_amount, drop_type, notes, status)
                        VALUES (?, ?, ?, ?, ?, 'pending')
                    ");
                    $stmt->execute([$till_id, $user_id, $drop_amount, $drop_type, $notes]);

                    // Enhanced audit logging for cash drop creation
                    try {
                        $stmt = $conn->prepare("
                            INSERT INTO security_logs (event_type, details, ip_address, user_agent, severity)
                            VALUES (?, ?, ?, ?, ?)
                        ");
                        $details = "Cash drop created - Amount: " . formatCurrency($drop_amount, $settings) . ", Till: {$till_name}, Type: {$drop_type}, User: {$username}" . ($notes ? ". Notes: {$notes}" : "");
                        $stmt->execute([
                            'cash_drop_created',
                            $details,
                            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                            $drop_amount > 10000 ? 'high' : 'medium'
                        ]);
                    } catch (Exception $e) {
                        // Log error but don't fail the transaction
                        error_log("Security log error: " . $e->getMessage());
                    }

                    // Update till current balance (allow negative balance for till short reporting)
                    $stmt = $conn->prepare("
                        UPDATE register_tills
                        SET current_balance = GREATEST(0, current_balance - ?)
                        WHERE id = ?
                    ");
                    $stmt->execute([$drop_amount, $till_id]);

                    // Security: Close till after emergency drop and require reauthentication
                    $stmt = $conn->prepare("
                        UPDATE register_tills
                        SET is_active = 0, closed_at = NOW(), closed_by = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$user_id, $till_id]);

                    // Set session flags to trigger reauthentication on till side
                    $_SESSION['till_closure_occurred'] = true;
                    $_SESSION['till_closure_timestamp'] = time();
                    $_SESSION['till_closure_till_id'] = $till_id;

                    // Log till closure for audit trail
                    try {
                        $stmt = $conn->prepare("
                            INSERT INTO security_logs (user_id, action, details, ip_address, user_agent)
                            VALUES (?, 'till_closed_emergency', ?, ?, ?)
                        ");
                        $details = "Till closed after emergency drop - Till ID: {$till_id}, Drop Amount: " . formatCurrency($drop_amount);
                        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
                        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
                        $stmt->execute([$user_id, $details, $ip_address, $user_agent]);
                    } catch (Exception $e) {
                        error_log("Security log error: " . $e->getMessage());
                    }

                    // Update rate limiting
                    $last_requests = [];
                    if (file_exists($rate_limit_file)) {
                        $last_requests = json_decode(file_get_contents($rate_limit_file), true) ?: [];
                    }
                    $last_requests[] = $current_time;
                    file_put_contents($rate_limit_file, json_encode($last_requests));

                    // Regenerate transaction ID to prevent reuse
                    $_SESSION['transaction_id'] = uniqid('txn_', true);
                    
                    // Store success message in session and redirect to prevent resubmission
                    $_SESSION['success_message'] = 'Emergency drop recorded successfully! Till has been closed. Cashier will need to reauthenticate on the till side to continue operations.';
                    header("Location: cash-drop.php?success=1");
                    exit();
                } catch (Exception $e) {
                    $_SESSION['error_message'] = 'Error recording cash drop: ' . $e->getMessage();
                    header("Location: cash-drop.php?error=1");
                    exit();
                }
                break;
                
            case 'confirm_drop':
                try {
                    $drop_id = $_POST['drop_id'];

                    // Get drop details for logging
                    $stmt = $conn->prepare("SELECT cd.*, rt.till_name, u.username FROM cash_drops cd LEFT JOIN register_tills rt ON cd.till_id = rt.id LEFT JOIN users u ON cd.user_id = u.id WHERE cd.id = ?");
                    $stmt->execute([$drop_id]);
                    $drop_details = $stmt->fetch(PDO::FETCH_ASSOC);

                    $stmt = $conn->prepare("
                        UPDATE cash_drops
                        SET status = 'confirmed', confirmed_by = ?, confirmed_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$user_id, $drop_id]);

                    // Enhanced audit logging
                    $stmt = $conn->prepare("
                        INSERT INTO security_logs (event_type, details, ip_address, user_agent, severity)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $details = "Cash drop #{$drop_id} confirmed by {$username} - Amount: " . formatCurrency($drop_details['drop_amount']) . ", Till: {$drop_details['till_name']}, Dropped by: {$drop_details['username']}";
                    $stmt->execute([
                        'cash_drop_confirmed',
                        $details,
                        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                        $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                        'medium'
                    ]);

                    $_SESSION['success_message'] = 'Cash drop confirmed successfully!';
                    header("Location: cash-drop.php?success=1");
                    exit();
                } catch (Exception $e) {
                    $_SESSION['error_message'] = 'Error confirming cash drop: ' . $e->getMessage();
                    header("Location: cash-drop.php?error=1");
                    exit();
                }
                break;
                
            case 'cancel_drop':
                try {
                    $drop_id = $_POST['drop_id'];

                    // Get drop details for logging
                    $stmt = $conn->prepare("SELECT cd.*, rt.till_name, u.username FROM cash_drops cd LEFT JOIN register_tills rt ON cd.till_id = rt.id LEFT JOIN users u ON cd.user_id = u.id WHERE cd.id = ?");
                    $stmt->execute([$drop_id]);
                    $drop_details = $stmt->fetch(PDO::FETCH_ASSOC);

                    $stmt = $conn->prepare("
                        UPDATE cash_drops
                        SET status = 'cancelled', confirmed_by = ?, confirmed_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$user_id, $drop_id]);

                    // Enhanced audit logging
                    $stmt = $conn->prepare("
                        INSERT INTO security_logs (event_type, details, ip_address, user_agent, severity)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $details = "Cash drop #{$drop_id} cancelled by {$username} - Amount: " . formatCurrency($drop_details['drop_amount']) . ", Till: {$drop_details['till_name']}, Dropped by: {$drop_details['username']}";
                    $stmt->execute([
                        'cash_drop_cancelled',
                        $details,
                        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                        $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                        'high'
                    ]);

                    $_SESSION['success_message'] = 'Cash drop cancelled successfully!';
                    header("Location: cash-drop.php?success=1");
                    exit();
                } catch (Exception $e) {
                    $_SESSION['error_message'] = 'Error cancelling cash drop: ' . $e->getMessage();
                    header("Location: cash-drop.php?error=1");
                    exit();
                }
                break;
                
        }
    }
}

// Get active tills
$stmt = $conn->query("
    SELECT * FROM register_tills 
    WHERE is_active = 1 
    ORDER BY till_name
");
$tills = $stmt->fetchAll(PDO::FETCH_ASSOC);


// Get only pending cash drops that need action
$stmt = $conn->query("
    SELECT cd.*, rt.till_name, u.username as dropped_by, 
           cu.username as confirmed_by_name
    FROM cash_drops cd
    LEFT JOIN register_tills rt ON cd.till_id = rt.id
    LEFT JOIN users u ON cd.user_id = u.id
    LEFT JOIN users cu ON cd.confirmed_by = cu.id
    WHERE cd.status = 'pending'
    ORDER BY cd.drop_date DESC
    LIMIT 50
");
$cash_drops = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get today's cash drop summary
$today = date('Y-m-d');
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_drops,
        SUM(drop_amount) as total_amount,
        COUNT(CASE WHEN status = 'confirmed' THEN 1 END) as confirmed_drops,
        COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_drops
    FROM cash_drops 
    WHERE DATE(drop_date) = ?
");
$stmt->execute([$today]);
$today_stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Provide default values if no data exists
$today_stats = [
    'total_drops' => $today_stats['total_drops'] ?? 0,
    'total_amount' => $today_stats['total_amount'] ?? 0,
    'confirmed_drops' => $today_stats['confirmed_drops'] ?? 0,
    'pending_drops' => $today_stats['pending_drops'] ?? 0
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Drop Management - POS System</title>
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
                    <h2><i class="bi bi-cash-coin"></i> Drop Management</h2>
                    <p class="text-muted">Manage and confirm cash drops from tills</p>
                </div>
                <div>
                    <a href="salesdashboard.php" class="btn btn-outline-secondary me-2">
                        <i class="bi bi-arrow-left"></i> Back to Dashboard
                    </a>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCashDropModal">
                        <i class="bi bi-plus"></i> Record Drop
                    </button>
                </div>
            </div>

            <!-- Today's Stats -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card bg-primary text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="card-title">Total Drops Today</h6>
                                    <h3><?php echo $today_stats['total_drops']; ?></h3>
                                </div>
                                <i class="bi bi-cash-coin fs-1 opacity-75"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-success text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="card-title">Total Amount</h6>
                                    <h3>KES <?php echo number_format($today_stats['total_amount'] ?? 0, 2); ?></h3>
                                </div>
                                <i class="bi bi-currency-dollar fs-1 opacity-75"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-warning text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="card-title">Pending Drops</h6>
                                    <h3><?php echo $today_stats['pending_drops']; ?></h3>
                                </div>
                                <i class="bi bi-clock fs-1 opacity-75"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-info text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="card-title">Confirmed Drops</h6>
                                    <h3><?php echo $today_stats['confirmed_drops']; ?></h3>
                                </div>
                                <i class="bi bi-check-circle fs-1 opacity-75"></i>
                            </div>
                        </div>
                    </div>
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


            <!-- Cash Drops Table -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Pending Drops (Awaiting Confirmation)</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Drop ID</th>
                                    <th>Date & Time</th>
                                    <th>Till</th>
                                    <th>Drop Type</th>
                                    <th>Amount</th>
                                    <th>Dropped By</th>
                                    <th>Status</th>
                                    <th>Notes</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($cash_drops)): ?>
                                <tr>
                                    <td colspan="9" class="text-center py-4">
                                        <i class="bi bi-check-circle fs-1 text-success mb-3"></i>
                                        <h5 class="text-muted">No Pending Cash Drops</h5>
                                        <p class="text-muted">All cash drops have been processed. Great job!</p>
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($cash_drops as $drop): ?>
                                <tr>
                                    <td>
                                        <span class="badge bg-secondary">#<?php echo $drop['id']; ?></span>
                                    </td>
                                    <td><?php echo date('M d, Y H:i', strtotime($drop['drop_date'])); ?></td>
                                    <td><?php echo htmlspecialchars($drop['till_name']); ?></td>
                                    <td>
                                        <?php
                                        $drop_type_labels = [
                                            'emergency_drop' => 'Emergency Drop'
                                        ];
                                        $drop_type_label = $drop_type_labels[$drop['drop_type']] ?? 'Emergency Drop';
                                        ?>
                                        <span class="badge bg-info">
                                            <?php echo $drop_type_label; ?>
                                        </span>
                                    </td>
                                    <td><strong>KES <?php echo number_format($drop['drop_amount'], 2); ?></strong></td>
                                    <td><?php echo htmlspecialchars($drop['dropped_by']); ?></td>
                                    <td>
                                        <span class="badge bg-warning">
                                            Pending
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($drop['notes']): ?>
                                            <span class="text-truncate d-inline-block" style="max-width: 150px;" title="<?php echo htmlspecialchars($drop['notes']); ?>">
                                                <?php echo htmlspecialchars($drop['notes']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button class="btn btn-outline-success" onclick="confirmDrop(<?php echo $drop['id']; ?>)" title="Confirm this cash drop">
                                                <i class="bi bi-check"></i> Confirm
                                            </button>
                                            <button class="btn btn-outline-danger" onclick="cancelDrop(<?php echo $drop['id']; ?>)" title="Cancel this cash drop">
                                                <i class="bi bi-x"></i> Cancel
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Cash Drop Modal -->
    <div class="modal fade" id="addCashDropModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="add_cash_drop">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="transaction_id" value="<?php echo $_SESSION['transaction_id']; ?>">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="bi bi-cash-coin me-2"></i>Record Cash Drop
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Select Till *</label>
                            <select class="form-select" name="till_id" required>
                                <option value="">Choose Till</option>
                                <?php foreach ($tills as $till): ?>
                                <option value="<?php echo $till['id']; ?>" data-balance="<?php echo $till['current_balance']; ?>">
                                    <?php echo htmlspecialchars($till['till_name']); ?> 
                                    (KES <?php echo number_format($till['current_balance'], 2); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Drop Type</label>
                            <select class="form-select" name="drop_type" id="drop_type" required>
                                <option value="emergency_drop" selected>Emergency Drop</option>
                            </select>
                            <div class="form-text">
                                <i class="bi bi-info-circle text-info"></i> 
                                Drop type is automatically set to emergency for security control.
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Drop Amount *</label>
                            <input type="number" class="form-control" name="drop_amount" step="0.01" min="0.01" required>
                            <div class="form-text">
                                Available balance: <span id="available_balance">-</span>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="notes" rows="3" placeholder="Optional notes about this cash drop"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-cash-coin"></i> Record Drop
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>


    <!-- Confirm Drop Form -->
    <form method="POST" id="confirmForm" style="display: none;">
        <input type="hidden" name="action" value="confirm_drop">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <input type="hidden" name="drop_id" id="confirm_drop_id">
    </form>

    <!-- Cancel Drop Form -->
    <form method="POST" id="cancelForm" style="display: none;">
        <input type="hidden" name="action" value="cancel_drop">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <input type="hidden" name="drop_id" id="cancel_drop_id">
    </form>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Prevent form resubmission and double-clicking
        let formSubmitted = false;
        
        // Update available balance when till is selected
        document.querySelector('select[name="till_id"]').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const balance = selectedOption.getAttribute('data-balance');
            document.getElementById('available_balance').textContent = 'KES ' + parseFloat(balance).toFixed(2);
        });

        // Prevent double-clicking on forms
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                if (formSubmitted) {
                    e.preventDefault();
                    return false;
                }
                formSubmitted = true;
                
                // Disable submit buttons to prevent double-clicking
                const submitButtons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
                submitButtons.forEach(btn => {
                    btn.disabled = true;
                    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing...';
                });
            });
        });

        function confirmDrop(dropId) {
            if (formSubmitted) return false;
            
            if (confirm('Are you sure you want to confirm this cash drop?')) {
                formSubmitted = true;
                document.getElementById('confirm_drop_id').value = dropId;
                document.getElementById('confirmForm').submit();
            }
        }

        function cancelDrop(dropId) {
            if (formSubmitted) return false;
            
            if (confirm('Are you sure you want to cancel this cash drop?')) {
                formSubmitted = true;
                document.getElementById('cancel_drop_id').value = dropId;
                document.getElementById('cancelForm').submit();
            }
        }


        // Reset form submission flag when modals are closed
        document.getElementById('addCashDropModal').addEventListener('hidden.bs.modal', function() {
            formSubmitted = false;
            const submitButtons = document.querySelectorAll('#addCashDropModal button[type="submit"]');
            submitButtons.forEach(btn => {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-cash-coin"></i> Record Drop';
            });
        });

    </script>
</body>
</html>
