<?php
session_start();

// Debug session configuration
error_log("Session save path: " . session_save_path());
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

// Handle messages from URL parameters (after redirect)
$message = '';
$message_type = '';

if (isset($_GET['message']) && isset($_GET['type'])) {
    $message = urldecode($_GET['message']);
    $message_type = $_GET['type'];
}

// Handle bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $return_ids = $_POST['return_ids'] ?? [];

    if (empty($return_ids)) {
        $message = "Please select at least one return.";
        $message_type = 'warning';
        
        // Redirect to prevent form resubmission
        $redirect_url = "view_returns.php";
        $query_params = [];
        
        if (!empty($search)) $query_params['search'] = $search;
        if (!empty($supplier_filter)) $query_params['supplier'] = $supplier_filter;
        if (!empty($status_filter)) $query_params['status'] = $status_filter;
        if (!empty($date_from)) $query_params['date_from'] = $date_from;
        if (!empty($date_to)) $query_params['date_to'] = $date_to;
        if ($page > 1) $query_params['page'] = $page;
        
        if (!empty($query_params)) {
            $redirect_url .= '?' . http_build_query($query_params);
        }
        
        $redirect_url .= (strpos($redirect_url, '?') !== false ? '&' : '?') . 'message=' . urlencode($message) . '&type=' . $message_type;
        
        header("Location: $redirect_url");
        exit();
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

                case 'delete_drafts':
                    $deleted_count = 0;
                    $skipped_count = 0;
                    
                    foreach ($return_ids as $return_id) {
                        // First check if the return is actually a draft
                        $check_stmt = $conn->prepare("
                            SELECT id, status, return_number 
                            FROM returns 
                            WHERE id = :return_id
                        ");
                        $check_stmt->execute([':return_id' => $return_id]);
                        $return_data = $check_stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($return_data && $return_data['status'] === 'draft') {
                            // Only delete if it's actually a draft
                            $delete_stmt = $conn->prepare("
                                DELETE FROM returns
                                WHERE id = :return_id AND status = 'draft'
                            ");
                            $delete_stmt->execute([':return_id' => $return_id]);
                            
                            if ($delete_stmt->rowCount() > 0) {
                                $deleted_count++;
                                error_log("Deleted draft return ID: {$return_id}, Number: {$return_data['return_number']}");
                            }
                        } else {
                            $skipped_count++;
                            error_log("Skipped non-draft return ID: {$return_id}, Status: " . ($return_data['status'] ?? 'not found'));
                        }
                    }
                    
                    if ($deleted_count > 0) {
                        $message = "$deleted_count draft return(s) deleted.";
                        if ($skipped_count > 0) {
                            $message .= " $skipped_count non-draft return(s) were skipped.";
                        }
                        $message_type = 'success';
                    } else {
                        $message = "No draft returns were deleted. Only draft returns can be deleted.";
                        $message_type = 'warning';
                    }
                    break;
            }

            $conn->commit();
            
            // Redirect to prevent form resubmission
            $redirect_url = "view_returns.php";
            $query_params = [];
            
            if (!empty($search)) $query_params['search'] = $search;
            if (!empty($supplier_filter)) $query_params['supplier'] = $supplier_filter;
            if (!empty($status_filter)) $query_params['status'] = $status_filter;
            if (!empty($date_from)) $query_params['date_from'] = $date_from;
            if (!empty($date_to)) $query_params['date_to'] = $date_to;
            if ($page > 1) $query_params['page'] = $page;
            
            if (!empty($query_params)) {
                $redirect_url .= '?' . http_build_query($query_params);
            }
            
            // Add success message to URL
            $redirect_url .= (strpos($redirect_url, '?') !== false ? '&' : '?') . 'message=' . urlencode($message) . '&type=' . $message_type;
            
            header("Location: $redirect_url");
            exit();
            
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

// Debug: Log the query and results for draft filtering
if ($status_filter === 'draft') {
    error_log("Draft filter query: " . $sql);
    error_log("Draft filter params: " . json_encode($params));
    error_log("Draft returns found: " . count($returns));
    
    // Also check if there are any draft returns at all
    $debug_stmt = $conn->query("SELECT COUNT(*) as count FROM returns WHERE status = 'draft'");
    $debug_result = $debug_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Check what statuses exist
    $debug_stmt = $conn->query("SELECT DISTINCT status FROM returns ORDER BY status");
    $debug_statuses = $debug_stmt->fetchAll(PDO::FETCH_COLUMN);
}

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

        .returns-hero {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            color: white;
            border-radius: 20px;
            padding: 2.5rem;
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }

        .returns-hero h3 {
            color: white;
            font-weight: 700;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }

        .returns-hero p {
            color: white;
            opacity: 0.95;
            text-shadow: 0 1px 2px rgba(0,0,0,0.2);
        }

        .returns-hero::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="20" cy="20" r="2" fill="rgba(255,255,255,0.1)"/><circle cx="80" cy="80" r="2" fill="rgba(255,255,255,0.1)"/><circle cx="40" cy="60" r="1" fill="rgba(255,255,255,0.1)"/><circle cx="60" cy="30" r="1.5" fill="rgba(255,255,255,0.08)"/><circle cx="10" cy="70" r="1" fill="rgba(255,255,255,0.08)"/></svg>');
            animation: float 25s infinite linear;
        }

        @keyframes float {
            0% { transform: translateX(-50%) translateY(-50%) rotate(0deg); }
            100% { transform: translateX(-50%) translateY(-50%) rotate(360deg); }
        }

        .returns-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 1.25rem;
            margin-top: 2rem;
        }

        .stat-box {
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(15px);
            border-radius: 15px;
            padding: 1.25rem;
            text-align: center;
            border: 1px solid rgba(255, 255, 255, 0.3);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-box::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.1), transparent);
            transition: left 0.5s;
        }

        .stat-box:hover::before {
            left: 100%;
        }

        .stat-box:hover {
            transform: translateY(-3px);
            background: rgba(255, 255, 255, 0.25);
            border-color: rgba(255, 255, 255, 0.5);
        }

        .stat-number {
            font-size: 1.75rem;
            font-weight: 700;
            display: block;
            margin-bottom: 0.5rem;
            color: white;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }

        .stat-label {
            font-size: 0.9rem;
            opacity: 1;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: white;
            text-shadow: 0 1px 2px rgba(0,0,0,0.3);
        }

        .filter-section {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            margin-bottom: 2rem;
            border: 1px solid #f1f5f9;
        }

        .filter-section h4 {
            color: var(--primary-color);
            margin-bottom: 1.5rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .form-group {
            margin-bottom: 0;
        }

        .form-label {
            font-weight: 600;
            color: #374151;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }

        .form-control, .form-select {
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            padding: 0.75rem 1rem;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }

        .btn-filter {
            background: var(--primary-color);
            border: none;
            border-radius: 10px;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-filter:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(99, 102, 241, 0.3);
        }

        .btn-clear {
            background: #f3f4f6;
            border: 2px solid #e2e8f0;
            color: #6b7280;
            border-radius: 10px;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-clear:hover {
            background: #e5e7eb;
            border-color: #d1d5db;
            color: #374151;
        }

        .status-badge {
            padding: 0.375rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-draft {
            background-color: #e3f2fd;
            color: #1976d2;
        }

        .status-pending { background: #fef3c7; color: #d97706; }
        .status-approved { background: #dbeafe; color: #2563eb; }
        .status-shipped { background: #e0e7ff; color: #3730a3; }
        .status-received { background: #f0fdf4; color: #166534; }
        .status-completed { background: #d1fae5; color: #059669; }
        .status-cancelled { background: #fee2e2; color: #dc2626; }

        .bulk-actions {
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border: 1px solid #e2e8f0;
        }

        .bulk-actions h5 {
            color: var(--primary-color);
            margin-bottom: 1rem;
            font-weight: 600;
        }

        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: #64748b;
            background: white;
            border-radius: 16px;
            border: 2px dashed #e2e8f0;
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1.5rem;
            opacity: 0.4;
            color: var(--primary-color);
        }

        .empty-state h4 {
            color: #374151;
            margin-bottom: 1rem;
        }

        .search-highlight {
            background: rgba(255, 193, 7, 0.2);
            padding: 0.1rem 0.3rem;
            border-radius: 4px;
            font-weight: 600;
        }

        @media (max-width: 768px) {
            .returns-hero {
                padding: 1.5rem;
                border-radius: 15px;
            }

            .returns-stats {
                grid-template-columns: repeat(2, 1fr);
                gap: 1rem;
            }

            .filter-form {
                grid-template-columns: 1fr;
            }

            .stat-box {
                padding: 1rem;
            }

            .stat-number {
                font-size: 1.5rem;
            }
        }

        @media (max-width: 480px) {
            .returns-stats {
                grid-template-columns: 1fr;
            }

            .returns-hero {
                padding: 1rem;
            }

            .filter-section {
                padding: 1rem;
            }
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

            <!-- Returns Hero Section -->
            <div class="returns-hero">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div>
                        <h3 class="mb-2">Return Overview</h3>
                        <p class="mb-0 opacity-90">Track and manage all product returns in one place</p>
                    </div>
                    <div class="text-end">
                        <div class="stat-number"><?php echo number_format($total_records); ?></div>
                        <div class="stat-label">Total Returns</div>
                    </div>
                </div>

                <div class="returns-stats">
                    <div class="stat-box">
                        <span class="stat-number">
                            <?php
                            $draft_count = 0;
                            foreach ($returns as $return) {
                                if ($return['status'] === 'draft') $draft_count++;
                            }
                            echo $draft_count;
                            ?>
                        </span>
                        <span class="stat-label">Drafts</span>
                    </div>
                    <div class="stat-box">
                        <span class="stat-number">
                            <?php
                            $pending_count = 0;
                            foreach ($returns as $return) {
                                if ($return['status'] === 'pending') $pending_count++;
                            }
                            echo $pending_count;
                            ?>
                        </span>
                        <span class="stat-label">Pending</span>
                    </div>
                    <div class="stat-box">
                        <span class="stat-number">
                            <?php
                            $approved_count = 0;
                            foreach ($returns as $return) {
                                if ($return['status'] === 'approved') $approved_count++;
                            }
                            echo $approved_count;
                            ?>
                        </span>
                        <span class="stat-label">Approved</span>
                    </div>
                    <div class="stat-box">
                        <span class="stat-number">
                            <?php
                            $completed_count = 0;
                            foreach ($returns as $return) {
                                if ($return['status'] === 'completed') $completed_count++;
                            }
                            echo $completed_count;
                            ?>
                        </span>
                        <span class="stat-label">Completed</span>
                    </div>
                    <div class="stat-box">
                        <span class="stat-number"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?>
                            <?php
                            $total_value = 0;
                            foreach ($returns as $return) {
                                $total_value += $return['total_amount'];
                            }
                            echo number_format($total_value, 2);
                            ?>
                        </span>
                        <span class="stat-label">Total Value</span>
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
                            <option value="draft" <?php echo $status_filter === 'draft' ? 'selected' : ''; ?>>Draft</option>
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
                            <button type="button" class="btn btn-warning btn-sm me-2" onclick="submitBulkAction('mark_cancelled')">
                                <i class="bi bi-x-circle me-1"></i>Cancel Returns
                            </button>
                            <button type="button" class="btn btn-danger btn-sm" onclick="submitBulkAction('delete_drafts')" id="deleteDraftsBtn" style="display: none;">
                                <i class="bi bi-trash me-1"></i>Delete Drafts
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
                        <div class="empty-state">
                            <i class="bi bi-arrow-return-left"></i>
                            <h4>No Returns Found</h4>
                            <p>Start by creating your first product return</p>
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
                                                <?php if ($return['status'] === 'draft'): ?>
                                                <a href="create_return.php?draft_id=<?php echo $return['id']; ?>"
                                                   class="btn btn-sm btn-primary">
                                                    <i class="bi bi-pencil-square"></i> Continue
                                                </a>
                                                <?php else: ?>
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
        // Wait for DOM to be fully loaded
        document.addEventListener('DOMContentLoaded', function() {
            // Select all functionality
            const selectAllElement = document.getElementById('selectAll');
            if (selectAllElement) {
                selectAllElement.addEventListener('change', function() {
                    const checkboxes = document.querySelectorAll('.return-checkbox');
                    checkboxes.forEach(checkbox => {
                        checkbox.checked = this.checked;
                    });
                    updateBulkActions();
                });
            }

            // Individual checkbox change
            document.addEventListener('change', function(e) {
                if (e.target.classList.contains('return-checkbox')) {
                    updateBulkActions();
                }
            });

            // Auto-hide alerts
            setTimeout(function() {
                const alerts = document.querySelectorAll('.alert');
                alerts.forEach(function(alert) {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                });
            }, 5000);
        });

        function updateBulkActions() {
            const checkedBoxes = document.querySelectorAll('.return-checkbox:checked');
            const bulkActions = document.getElementById('bulkActions');
            const selectedCount = document.getElementById('selectedCount');
            const deleteDraftsBtn = document.getElementById('deleteDraftsBtn');

            if (bulkActions && selectedCount) {
                if (checkedBoxes.length > 0) {
                    bulkActions.classList.remove('d-none');
                    selectedCount.textContent = checkedBoxes.length + ' return(s) selected';
                    
                    // Check if any selected returns are drafts
                    let hasDrafts = false;
                    let allDrafts = true;
                    let draftCount = 0;
                    let nonDraftCount = 0;
                    
                    checkedBoxes.forEach(checkbox => {
                        const row = checkbox.closest('tr');
                        const statusBadge = row.querySelector('.status-badge');
                        if (statusBadge && statusBadge.classList.contains('status-draft')) {
                            hasDrafts = true;
                            draftCount++;
                        } else {
                            allDrafts = false;
                            nonDraftCount++;
                        }
                    });
                    
                    // Show/hide delete drafts button
                    if (deleteDraftsBtn) {
                        if (hasDrafts) {
                            deleteDraftsBtn.style.display = 'inline-block';
                            
                            // Update button text to show counts
                            if (allDrafts) {
                                deleteDraftsBtn.innerHTML = `<i class="bi bi-trash me-1"></i>Delete ${draftCount} Draft${draftCount > 1 ? 's' : ''}`;
                            } else {
                                deleteDraftsBtn.innerHTML = `<i class="bi bi-trash me-1"></i>Delete ${draftCount} Draft${draftCount > 1 ? 's' : ''} (${nonDraftCount} non-draft${nonDraftCount > 1 ? 's' : ''} will be skipped)`;
                            }
                        } else {
                            deleteDraftsBtn.style.display = 'none';
                        }
                    }
                } else {
                    bulkActions.classList.add('d-none');
                }
            }
        }

        function submitBulkAction(action) {
            let confirmMessage = '';
            switch(action) {
                case 'delete_drafts':
                    const checkedBoxes = document.querySelectorAll('.return-checkbox:checked');
                    let draftCount = 0;
                    let nonDraftCount = 0;
                    
                    checkedBoxes.forEach(checkbox => {
                        const row = checkbox.closest('tr');
                        const statusBadge = row.querySelector('.status-badge');
                        if (statusBadge && statusBadge.classList.contains('status-draft')) {
                            draftCount++;
                        } else {
                            nonDraftCount++;
                        }
                    });
                    
                    if (draftCount > 0) {
                        if (nonDraftCount > 0) {
                            confirmMessage = `Are you sure you want to permanently delete ${draftCount} draft return${draftCount > 1 ? 's' : ''}? ${nonDraftCount} non-draft return${nonDraftCount > 1 ? 's' : ''} will be skipped. This action cannot be undone.`;
                        } else {
                            confirmMessage = `Are you sure you want to permanently delete ${draftCount} draft return${draftCount > 1 ? 's' : ''}? This action cannot be undone.`;
                        }
                    } else {
                        alert('No draft returns selected. Only draft returns can be deleted.');
                        return;
                    }
                    break;
                case 'mark_completed':
                    confirmMessage = 'Are you sure you want to mark the selected returns as completed?';
                    break;
                case 'mark_cancelled':
                    confirmMessage = 'Are you sure you want to cancel the selected returns?';
                    break;
                default:
                    confirmMessage = `Are you sure you want to ${action.replace('_', ' ')} the selected returns?`;
            }
            
            if (!confirm(confirmMessage)) {
                return;
            }

            const bulkActionElement = document.getElementById('bulkAction');
            const bulkFormElement = document.getElementById('bulkForm');
            
            if (bulkActionElement && bulkFormElement) {
                bulkActionElement.value = action;
                bulkFormElement.submit();
            }
        }
    </script>
</body>
</html>
