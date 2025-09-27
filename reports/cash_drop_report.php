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

// Check if user has permission to view reports
$hasAccess = false;
if (isAdmin($role_name)) {
    $hasAccess = true;
}
if (!$hasAccess && !empty($permissions)) {
    if (hasPermission('view_sales', $permissions) || hasPermission('manage_sales', $permissions) || hasPermission('cash_drop', $permissions)) {
        $hasAccess = true;
    }
}
if (!$hasAccess && hasAdminAccess($role_name, $permissions)) {
    $hasAccess = true;
}

if (!$hasAccess) {
    header('Location: ../dashboard/dashboard.php?error=access_denied');
    exit();
}

// Handle filters
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$till_id = $_GET['till_id'] ?? '';
$user_filter = $_GET['user_id'] ?? '';
$status_filter = $_GET['status'] ?? '';
$drop_type = $_GET['drop_type'] ?? '';
$amount_min = $_GET['amount_min'] ?? '';
$amount_max = $_GET['amount_max'] ?? '';
$confirmed_by_filter = $_GET['confirmed_by'] ?? '';
$confirmation_time_filter = $_GET['confirmation_time'] ?? '';
$audit_search = $_GET['audit_search'] ?? '';
$show_audit_details = $_GET['show_audit'] ?? '0';
$emergency_filter = $_GET['emergency_filter'] ?? '';

// Handle export requests
$export = $_GET['export'] ?? '';
if ($export) {
    handleExport($conn, $cash_drops, $summary_stats, $export, $settings);
    exit();
}

// Check if cash_drops table exists
$table_exists = false;
try {
    $stmt = $conn->query("SHOW TABLES LIKE 'cash_drops'");
    $table_exists = $stmt->rowCount() > 0;
} catch (Exception $e) {
    $table_exists = false;
}

$cash_drops = [];
$summary_stats = [
    'total_drops' => 0,
    'total_amount' => 0,
    'pending_drops' => 0,
    'confirmed_drops' => 0,
    'cancelled_drops' => 0,
    'pending_amount' => 0,
    'confirmed_amount' => 0,
    'cancelled_amount' => 0
];

if ($table_exists) {
    // Build query for cash drop report with enhanced audit details
    $query = "
        SELECT
            cd.*,
            rt.till_name,
            rt.till_code,
            rt.current_balance as till_current_balance,
            u.username as dropped_by_name,
            u.role as dropped_by_role,
            cu.username as confirmed_by_name,
            cu.role as confirmed_by_role,
            TIMESTAMPDIFF(MINUTE, cd.drop_date, cd.confirmed_at) as confirmation_minutes,
            TIMESTAMPDIFF(HOUR, cd.drop_date, cd.confirmed_at) as confirmation_hours,
            TIMESTAMPDIFF(DAY, cd.drop_date, cd.confirmed_at) as confirmation_days,
            CASE
                WHEN cd.status = 'pending' THEN 'Pending'
                WHEN cd.status = 'confirmed' THEN 'Confirmed'
                WHEN cd.status = 'cancelled' THEN 'Cancelled'
                ELSE 'Unknown'
            END as status_text,
            CASE
                WHEN TIMESTAMPDIFF(MINUTE, cd.drop_date, cd.confirmed_at) < 60 THEN
                    CONCAT(TIMESTAMPDIFF(MINUTE, cd.drop_date, cd.confirmed_at), ' min')
                WHEN TIMESTAMPDIFF(HOUR, cd.drop_date, cd.confirmed_at) < 24 THEN
                    CONCAT(TIMESTAMPDIFF(HOUR, cd.drop_date, cd.confirmed_at), ' hrs')
                ELSE CONCAT(TIMESTAMPDIFF(DAY, cd.drop_date, cd.confirmed_at), ' days')
            END as time_to_confirm,
            -- Get audit trail count
            (SELECT COUNT(*) FROM security_logs sl WHERE sl.event_type LIKE '%cash_drop%' AND sl.details LIKE CONCAT('%', cd.id, '%')) as audit_count
        FROM cash_drops cd
        LEFT JOIN register_tills rt ON cd.till_id = rt.id
        LEFT JOIN users u ON cd.user_id = u.id
        LEFT JOIN users cu ON cd.confirmed_by = cu.id
        WHERE cd.drop_date BETWEEN ? AND ?
    ";

    $params = [$date_from . ' 00:00:00', $date_to . ' 23:59:59'];

    if (!empty($till_id)) {
        $query .= " AND cd.till_id = ?";
        $params[] = $till_id;
    }

    if (!empty($user_filter)) {
        $query .= " AND cd.user_id = ?";
        $params[] = $user_filter;
    }

    if (!empty($status_filter) && $status_filter !== 'all') {
        $query .= " AND cd.status = ?";
        $params[] = $status_filter;
    }

    if (!empty($drop_type) && $drop_type !== 'all') {
        $query .= " AND cd.drop_type = ?";
        $params[] = $drop_type;
    }

    if (!empty($emergency_filter) && $emergency_filter !== 'all') {
        if ($emergency_filter === 'yes') {
            $query .= " AND cd.is_emergency = 1";
        } elseif ($emergency_filter === 'no') {
            $query .= " AND cd.is_emergency = 0";
        }
    }

    if (!empty($confirmed_by_filter)) {
        $query .= " AND cd.confirmed_by = ?";
        $params[] = $confirmed_by_filter;
    }

    if (!empty($amount_min)) {
        $query .= " AND cd.drop_amount >= ?";
        $params[] = $amount_min;
    }

    if (!empty($amount_max)) {
        $query .= " AND cd.drop_amount <= ?";
        $params[] = $amount_max;
    }

    if (!empty($confirmation_time_filter)) {
        switch ($confirmation_time_filter) {
            case 'under_1h':
                $query .= " AND TIMESTAMPDIFF(MINUTE, cd.drop_date, cd.confirmed_at) < 60";
                break;
            case '1h_to_4h':
                $query .= " AND TIMESTAMPDIFF(MINUTE, cd.drop_date, cd.confirmed_at) BETWEEN 60 AND 240";
                break;
            case '4h_to_24h':
                $query .= " AND TIMESTAMPDIFF(MINUTE, cd.drop_date, cd.confirmed_at) BETWEEN 241 AND 1440";
                break;
            case 'over_24h':
                $query .= " AND TIMESTAMPDIFF(MINUTE, cd.drop_date, cd.confirmed_at) > 1440";
                break;
            case 'pending':
                $query .= " AND cd.status = 'pending'";
                break;
        }
    }

    if (!empty($audit_search)) {
        $query .= " AND (cd.notes LIKE ? OR u.username LIKE ? OR cu.username LIKE ? OR cd.id = ?)";
        $search_param = '%' . $audit_search . '%';
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $audit_search;
    }

    $query .= " ORDER BY cd.drop_date DESC";

    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $cash_drops = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate summary statistics
    foreach ($cash_drops as $drop) {
        $summary_stats['total_drops']++;
        $summary_stats['total_amount'] += $drop['drop_amount'];

        switch ($drop['status']) {
            case 'pending':
                $summary_stats['pending_drops']++;
                $summary_stats['pending_amount'] += $drop['drop_amount'];
                break;
            case 'confirmed':
                $summary_stats['confirmed_drops']++;
                $summary_stats['confirmed_amount'] += $drop['drop_amount'];
                break;
            case 'cancelled':
                $summary_stats['cancelled_drops']++;
                $summary_stats['cancelled_amount'] += $drop['drop_amount'];
                break;
        }
    }
} else {
    $error_message = "Cash drops table not found. Please run the database migration first.";
}

// Get tills for filter dropdown
$stmt = $conn->query("SELECT id, till_name, till_code FROM register_tills ORDER BY till_name");
$tills = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get users for filter dropdown
$stmt = $conn->query("SELECT id, username FROM users ORDER BY username");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get confirmed_by users for filter dropdown
$stmt = $conn->query("
    SELECT DISTINCT u.id, u.username
    FROM users u
    INNER JOIN cash_drops cd ON u.id = cd.confirmed_by
    ORDER BY u.username
");
$confirmed_by_users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get settings for currency
$settings = getSystemSettings($conn);

// Export function
function handleExport($conn, $cash_drops, $summary_stats, $export_type, $settings) {
    $filename = 'cash_drop_report_' . date('Y-m-d_H-i-s');

    if ($export_type === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');

        $output = fopen('php://output', 'w');

        // Summary section
        fputcsv($output, ['Cash Drop Report Summary']);
        fputcsv($output, ['Generated', date('Y-m-d H:i:s')]);
        fputcsv($output, []);
        fputcsv($output, ['Total Drops', 'Total Amount', 'Pending Drops', 'Confirmed Drops', 'Cancelled Drops']);
        fputcsv($output, [
            $summary_stats['total_drops'],
            formatCurrency($summary_stats['total_amount'], $settings),
            $summary_stats['pending_drops'],
            $summary_stats['confirmed_drops'],
            $summary_stats['cancelled_drops']
        ]);
        fputcsv($output, []);

        // Data headers
        fputcsv($output, [
            'Date & Time',
            'Till Name',
            'Drop Type',
            'Amount',
            'Dropped By',
            'Status',
            'Confirmed By',
            'Confirmation Time',
            'Notes'
        ]);

        // Data rows
        foreach ($cash_drops as $drop) {
            fputcsv($output, [
                date('M d, Y H:i:s', strtotime($drop['drop_date'])),
                $drop['till_name'] . ' (' . $drop['till_code'] . ')',
                ucfirst(str_replace('_', ' ', $drop['drop_type'])),
                formatCurrency($drop['drop_amount'], $settings),
                $drop['dropped_by_name'],
                $drop['status_text'],
                $drop['confirmed_by_name'] ?: 'Not confirmed',
                $drop['time_to_confirm'] ?: 'N/A',
                $drop['notes'] ?: ''
            ]);
        }

        fclose($output);
    } elseif ($export_type === 'audit_csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '_audit.csv"');

        $output = fopen('php://output', 'w');

        // Get audit data for all drops
        $audit_data = [];
        foreach ($cash_drops as $drop) {
            $stmt = $conn->prepare("
                SELECT * FROM security_logs
                WHERE event_type LIKE '%cash_drop%' AND details LIKE ?
                ORDER BY created_at ASC
            ");
            $stmt->execute(['%' . $drop['id'] . '%']);
            $audit_events = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($audit_events as $event) {
                $audit_data[] = [
                    'drop_id' => $drop['id'],
                    'drop_amount' => formatCurrency($drop['drop_amount'], $settings),
                    'till_name' => $drop['till_name'],
                    'event_type' => $event['event_type'],
                    'details' => $event['details'],
                    'severity' => $event['severity'],
                    'ip_address' => $event['ip_address'],
                    'created_at' => $event['created_at']
                ];
            }
        }

        // Headers
        fputcsv($output, ['Cash Drop Audit Trail']);
        fputcsv($output, ['Generated', date('Y-m-d H:i:s')]);
        fputcsv($output, []);
        fputcsv($output, [
            'Drop ID',
            'Amount',
            'Till Name',
            'Event Type',
            'Details',
            'Severity',
            'IP Address',
            'Timestamp'
        ]);

        // Data rows
        foreach ($audit_data as $row) {
            fputcsv($output, $row);
        }

        fclose($output);
    } elseif ($export_type === 'single_audit_csv') {
        $drop_id = intval($_GET['drop_id'] ?? 0);

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="cash_drop_' . $drop_id . '_audit_' . date('Y-m-d_H-i-s') . '.csv"');

        $output = fopen('php://output', 'w');

        // Get drop details
        $stmt = $conn->prepare("
            SELECT cd.*, rt.till_name, u.username as dropped_by_name
            FROM cash_drops cd
            LEFT JOIN register_tills rt ON cd.till_id = rt.id
            LEFT JOIN users u ON cd.user_id = u.id
            WHERE cd.id = ?
        ");
        $stmt->execute([$drop_id]);
        $drop = $stmt->fetch(PDO::FETCH_ASSOC);

        // Get audit trail
        $stmt = $conn->prepare("
            SELECT * FROM security_logs
            WHERE event_type LIKE '%cash_drop%' AND details LIKE ?
            ORDER BY created_at ASC
        ");
        $stmt->execute(['%' . $drop_id . '%']);
        $audit_events = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Headers
        fputcsv($output, ['Cash Drop #' . $drop_id . ' Audit Trail']);
        fputcsv($output, ['Drop Amount', formatCurrency($drop['drop_amount'], $settings)]);
        fputcsv($output, ['Till Name', $drop['till_name']]);
        fputcsv($output, ['Dropped By', $drop['dropped_by_name']]);
        fputcsv($output, ['Generated', date('Y-m-d H:i:s')]);
        fputcsv($output, []);
        fputcsv($output, [
            'Event Type',
            'Details',
            'Severity',
            'IP Address',
            'User Agent',
            'Timestamp'
        ]);

        // Data rows
        foreach ($audit_events as $event) {
            fputcsv($output, [
                $event['event_type'],
                $event['details'],
                $event['severity'],
                $event['ip_address'],
                substr($event['user_agent'] ?? '', 0, 100), // Truncate user agent
                $event['created_at']
            ]);
        }

        fclose($output);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cash Drop Report - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/dashboard.css" rel="stylesheet">
    <style>
        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 0.375rem;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .status-pending { background-color: #fff3cd; color: #856404; }
        .status-confirmed { background-color: #d1edff; color: #0c5460; }
        .status-cancelled { background-color: #f8d7da; color: #721c24; }
        .drop-type-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 0.375rem;
            font-size: 0.75rem;
            font-weight: 600;
            background-color: #e9ecef;
            color: #495057;
        }
        
        /* Filter Form Improvements */
        .form-label {
            font-size: 0.875rem;
            margin-bottom: 0.5rem;
            color: #495057;
        }
        
        .form-control, .form-select {
            font-size: 0.875rem;
            padding: 0.5rem 0.75rem;
            border-radius: 0.375rem;
            border: 1px solid #ced4da;
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #86b7fe;
            outline: 0;
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
        }
        
        /* Responsive field widths */
        @media (min-width: 992px) {
            .col-lg-1 { flex: 0 0 8.333333%; max-width: 8.333333%; }
            .col-lg-2 { flex: 0 0 16.666667%; max-width: 16.666667%; }
            .col-lg-3 { flex: 0 0 25%; max-width: 25%; }
        }
        
        @media (min-width: 768px) and (max-width: 991px) {
            .col-md-3 { flex: 0 0 25%; max-width: 25%; }
            .col-md-4 { flex: 0 0 33.333333%; max-width: 33.333333%; }
            .col-md-6 { flex: 0 0 50%; max-width: 50%; }
        }
        
        @media (max-width: 767px) {
            .col-sm-6 { flex: 0 0 50%; max-width: 50%; }
        }
        
        /* Action buttons styling */
        .btn {
            font-size: 0.875rem;
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
        }
        
        .btn-group .btn {
            border-radius: 0.375rem;
        }
        
        .btn-group .btn:not(:last-child) {
            margin-right: 0.25rem;
        }
        
        /* Filter card improvements */
        .card-body {
            padding: 1.5rem;
        }
        
        .row.g-3 > * {
            padding-right: calc(var(--bs-gutter-x) * 0.5);
            padding-left: calc(var(--bs-gutter-x) * 0.5);
        }
        
        /* Table borders */
        .table {
            border: 1px solid #dee2e6;
        }
        
        .table th,
        .table td {
            border: 1px solid #dee2e6;
            vertical-align: middle;
        }
        
        .table thead th {
            border-bottom: 2px solid #dee2e6;
            background-color: #f8f9fa;
            font-weight: 600;
        }
        
        .table tbody tr:hover {
            background-color: #f8f9fa;
        }
    </style>
</head>
<body>
    <?php include '../include/navmenu.php'; ?>

    <div class="main-content">
        <div class="container-fluid">
            <!-- Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2><i class="bi bi-cash-coin"></i> Cash Drop Report</h2>
                    <p class="text-muted">Detailed cash drop tracking and management</p>
                </div>
                <div>
                    <a href="index.php" class="btn btn-outline-secondary me-2">
                        <i class="bi bi-arrow-left"></i> Back to Reports
                    </a>
                    <a href="daily_cash_drop_summary.php" class="btn btn-info me-2">
                        <i class="bi bi-calendar-week"></i> Daily Summary
                    </a>
                    <button class="btn btn-success" onclick="exportReport()">
                        <i class="bi bi-download"></i> Export
                    </button>
                </div>
            </div>

            <!-- Filters -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="bi bi-funnel me-2"></i>Advanced Filters</h6>
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#advancedFilters" aria-expanded="false">
                        <i class="bi bi-chevron-down"></i> Toggle Advanced Filters
                    </button>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <!-- Basic Filters Row -->
                        <div class="col-lg-2 col-md-4 col-sm-6">
                            <label class="form-label fw-semibold">From Date</label>
                            <input type="date" class="form-control" name="date_from" value="<?php echo $date_from; ?>">
                        </div>
                        <div class="col-lg-2 col-md-4 col-sm-6">
                            <label class="form-label fw-semibold">To Date</label>
                            <input type="date" class="form-control" name="date_to" value="<?php echo $date_to; ?>">
                        </div>
                        <div class="col-lg-2 col-md-4 col-sm-6">
                            <label class="form-label fw-semibold">Till</label>
                            <select class="form-select" name="till_id">
                                <option value="">All Tills</option>
                                <?php foreach ($tills as $till): ?>
                                <option value="<?php echo $till['id']; ?>" <?php echo ($till_id == $till['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($till['till_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-lg-2 col-md-4 col-sm-6">
                            <label class="form-label fw-semibold">Cashier</label>
                            <select class="form-select" name="user_id">
                                <option value="">All Cashiers</option>
                                <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['id']; ?>" <?php echo ($user_filter == $user['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($user['username']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-lg-2 col-md-4 col-sm-6">
                            <label class="form-label fw-semibold">Status</label>
                            <select class="form-select" name="status">
                                <option value="all" <?php echo ($status_filter === 'all' || empty($status_filter)) ? 'selected' : ''; ?>>All</option>
                                <option value="pending" <?php echo ($status_filter === 'pending') ? 'selected' : ''; ?>>Not Confirmed (Pending)</option>
                                <option value="confirmed" <?php echo ($status_filter === 'confirmed') ? 'selected' : ''; ?>>Confirmed</option>
                                <option value="cancelled" <?php echo ($status_filter === 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                        <div class="col-lg-2 col-md-4 col-sm-6">
                            <label class="form-label fw-semibold">Drop Type</label>
                            <select class="form-select" name="drop_type">
                                <option value="all" <?php echo ($drop_type === 'all' || empty($drop_type)) ? 'selected' : ''; ?>>All Types</option>
                                <option value="cashier_drop" <?php echo ($drop_type === 'cashier_drop') ? 'selected' : ''; ?>>Cashier Drop</option>
                                <option value="manager_drop" <?php echo ($drop_type === 'manager_drop') ? 'selected' : ''; ?>>Manager Drop</option>
                                <option value="end_of_day_drop" <?php echo ($drop_type === 'end_of_day_drop') ? 'selected' : ''; ?>>End of Day</option>
                                <option value="emergency_drop" <?php echo ($drop_type === 'emergency_drop') ? 'selected' : ''; ?>>Emergency</option>
                                <option value="bank_deposit" <?php echo ($drop_type === 'bank_deposit') ? 'selected' : ''; ?>>Bank Deposit</option>
                                <option value="safe_drop" <?php echo ($drop_type === 'safe_drop') ? 'selected' : ''; ?>>Safe Drop</option>
                            </select>
                        </div>
                        <div class="col-lg-2 col-md-4 col-sm-6">
                            <label class="form-label fw-semibold">Emergency</label>
                            <select class="form-select" name="emergency_filter">
                                <option value="all" <?php echo ($emergency_filter === 'all' || empty($emergency_filter)) ? 'selected' : ''; ?>>All</option>
                                <option value="yes" <?php echo ($emergency_filter === 'yes') ? 'selected' : ''; ?>>Emergency Only</option>
                                <option value="no" <?php echo ($emergency_filter === 'no') ? 'selected' : ''; ?>>Regular Only</option>
                            </select>
                        </div>

                        <!-- Advanced Filters (Collapsible) -->
                        <div class="collapse <?php echo (!empty($amount_min) || !empty($amount_max) || !empty($confirmed_by_filter) || !empty($confirmation_time_filter) || !empty($audit_search)) ? 'show' : ''; ?>" id="advancedFilters">
                            <!-- Amount Range Row -->
                            <div class="col-lg-2 col-md-4 col-sm-6">
                                <label class="form-label fw-semibold">Min Amount</label>
                                <input type="number" class="form-control" name="amount_min" value="<?php echo $amount_min; ?>" step="0.01" placeholder="0.00">
                            </div>
                            <div class="col-lg-2 col-md-4 col-sm-6">
                                <label class="form-label fw-semibold">Max Amount</label>
                                <input type="number" class="form-control" name="amount_max" value="<?php echo $amount_max; ?>" step="0.01" placeholder="0.00">
                            </div>
                            <div class="col-lg-2 col-md-4 col-sm-6">
                                <label class="form-label fw-semibold">Confirmed By</label>
                                <select class="form-select" name="confirmed_by">
                                    <option value="">All Approvers</option>
                                    <?php foreach ($confirmed_by_users as $user): ?>
                                    <option value="<?php echo $user['id']; ?>" <?php echo ($confirmed_by_filter == $user['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($user['username']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-lg-2 col-md-4 col-sm-6">
                                <label class="form-label fw-semibold">Confirmation Time</label>
                                <select class="form-select" name="confirmation_time">
                                    <option value="">All Times</option>
                                    <option value="under_1h" <?php echo ($confirmation_time_filter === 'under_1h') ? 'selected' : ''; ?>>Under 1 Hour</option>
                                    <option value="1h_to_4h" <?php echo ($confirmation_time_filter === '1h_to_4h') ? 'selected' : ''; ?>>1-4 Hours</option>
                                    <option value="4h_to_24h" <?php echo ($confirmation_time_filter === '4h_to_24h') ? 'selected' : ''; ?>>4-24 Hours</option>
                                    <option value="over_24h" <?php echo ($confirmation_time_filter === 'over_24h') ? 'selected' : ''; ?>>Over 24 Hours</option>
                                    <option value="pending" <?php echo ($confirmation_time_filter === 'pending') ? 'selected' : ''; ?>>Not Confirmed (Still Pending)</option>
                                </select>
                            </div>
                            <div class="col-lg-2 col-md-4 col-sm-6">
                                <label class="form-label fw-semibold">Audit Search</label>
                                <input type="text" class="form-control" name="audit_search" value="<?php echo htmlspecialchars($audit_search); ?>" placeholder="Search notes, users, ID...">
                            </div>
                            <div class="col-lg-2 col-md-4 col-sm-6">
                                <label class="form-label fw-semibold">Show Audit Details</label>
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" name="show_audit" value="1" id="showAuditSwitch" <?php echo ($show_audit_details === '1') ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="showAuditSwitch">Include audit trail</label>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Action Buttons Row -->
                        <div class="col-12">
                            <div class="d-flex flex-wrap gap-2 mt-3">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-search me-1"></i> Filter & Search
                                </button>
                                <a href="?date_from=<?php echo date('Y-m-d', strtotime('-30 days')); ?>&date_to=<?php echo date('Y-m-d'); ?>" class="btn btn-outline-secondary">
                                    <i class="bi bi-arrow-clockwise me-1"></i> Reset All
                                </a>
                                <button type="button" class="btn btn-outline-info" onclick="exportReport()">
                                    <i class="bi bi-download me-1"></i> Export Report
                                </button>
                                <button type="button" class="btn btn-outline-success" onclick="exportDetailedAudit()">
                                    <i class="bi bi-file-earmark-spreadsheet me-1"></i> Export Audit Trail
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>


            <?php if (isset($error_message)): ?>
            <div class="alert alert-warning alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle"></i> <?php echo htmlspecialchars($error_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Summary Statistics -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card bg-primary text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="card-title">Total Drops</h6>
                                    <h3><?php echo $summary_stats['total_drops']; ?></h3>
                                    <small><?php echo formatCurrency($summary_stats['total_amount'], $settings); ?> total</small>
                                </div>
                                <i class="bi bi-cash-coin fs-1 opacity-75"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-warning text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="card-title">Pending</h6>
                                    <h3><?php echo $summary_stats['pending_drops']; ?></h3>
                                    <small><?php echo formatCurrency($summary_stats['pending_amount'], $settings); ?></small>
                                </div>
                                <i class="bi bi-clock fs-1 opacity-75"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-success text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="card-title">Confirmed</h6>
                                    <h3><?php echo $summary_stats['confirmed_drops']; ?></h3>
                                    <small><?php echo formatCurrency($summary_stats['confirmed_amount'], $settings); ?></small>
                                </div>
                                <i class="bi bi-check-circle fs-1 opacity-75"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-danger text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="card-title">Cancelled</h6>
                                    <h3><?php echo $summary_stats['cancelled_drops']; ?></h3>
                                    <small><?php echo formatCurrency($summary_stats['cancelled_amount'], $settings); ?></small>
                                </div>
                                <i class="bi bi-x-circle fs-1 opacity-75"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>


            <!-- Cash Drops Table -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Cash Drop Details</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Date & Time</th>
                                    <th>Till</th>
                                    <th>Drop Type</th>
                                    <th>Emergency</th>
                                    <th>Amount</th>
                                    <th>Dropped By</th>
                                    <th>Status</th>
                                    <th>Confirmed By</th>
                                    <th>Balance After Drop</th>
                                    <th>Time to Confirm</th>
                                    <th>Notes</th>
                                    <?php if ($show_audit_details === '1'): ?>
                                    <th>Audit Trail</th>
                                    <?php endif; ?>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cash_drops as $drop): ?>
                                <tr>
                                    <td>
                                        <?php echo date('M d, Y H:i:s', strtotime($drop['drop_date'])); ?>
                                        <br>
                                        <small class="text-muted"><?php echo date('l', strtotime($drop['drop_date'])); ?></small>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($drop['till_name'] ?? 'N/A'); ?></strong>
                                        <br>
                                        <small class="text-muted"><?php echo htmlspecialchars($drop['till_code'] ?? ''); ?></small>
                                    </td>
                                    <td>
                                        <span class="drop-type-badge">
                                            <?php
                                            switch ($drop['drop_type']) {
                                                case 'cashier_drop': echo 'Cashier'; break;
                                                case 'manager_drop': echo 'Manager'; break;
                                                case 'end_of_day_drop': echo 'End of Day'; break;
                                                case 'emergency_drop': echo 'Emergency'; break;
                                                default: echo ucfirst(str_replace('_', ' ', $drop['drop_type'] ?? 'Unknown'));
                                            }
                                            ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($drop['is_emergency']): ?>
                                            <span class="badge bg-danger">
                                                <i class="bi bi-exclamation-triangle me-1"></i>Emergency
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong class="text-primary"><?php echo formatCurrency($drop['drop_amount'], $settings); ?></strong>
                                    </td>
                                    <td><?php echo htmlspecialchars($drop['dropped_by_name']); ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo $drop['status']; ?>">
                                            <?php echo htmlspecialchars($drop['status_text']); ?>
                                        </span>
                                        <?php if ($drop['status'] === 'confirmed' && $drop['confirmed_at']): ?>
                                        <br>
                                        <small class="text-muted"><?php echo date('M d, H:i', strtotime($drop['confirmed_at'])); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($drop['confirmed_by_name']): ?>
                                            <?php echo htmlspecialchars($drop['confirmed_by_name']); ?>
                                            <?php if ($drop['confirmed_at']): ?>
                                            <br>
                                            <small class="text-muted"><?php echo date('M d, H:i', strtotime($drop['confirmed_at'])); ?></small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($drop['till_current_balance'] !== null): ?>
                                            <span class="text-success"><?php echo formatCurrency($drop['till_current_balance'], $settings); ?></span>
                                            <br>
                                            <small class="text-muted">
                                                <?php
                                                $balance_after_drop = $drop['till_current_balance'];
                                                $original_balance = $balance_after_drop + $drop['drop_amount'];
                                                echo "Before: " . formatCurrency($original_balance, $settings);
                                                ?>
                                            </small>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($drop['confirmed_at'] && $drop['drop_date']): ?>
                                            <?php
                                            $drop_time = strtotime($drop['drop_date']);
                                            $confirm_time = strtotime($drop['confirmed_at']);
                                            $time_diff = $confirm_time - $drop_time;

                                            if ($time_diff < 3600) { // Less than 1 hour
                                                $minutes = round($time_diff / 60);
                                                echo $minutes . ' min';
                                            } elseif ($time_diff < 86400) { // Less than 24 hours
                                                $hours = round($time_diff / 3600);
                                                echo $hours . ' hrs';
                                            } else {
                                                $days = round($time_diff / 86400);
                                                echo $days . ' days';
                                            }
                                            ?>
                                        <?php else: ?>
                                            <?php if ($drop['status'] === 'pending'): ?>
                                                <span class="text-warning">Pending</span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($drop['notes']): ?>
                                            <div class="d-flex align-items-center">
                                                <span class="text-truncate d-inline-block" style="max-width: 150px;" title="<?php echo htmlspecialchars($drop['notes']); ?>">
                                                    <?php echo htmlspecialchars($drop['notes']); ?>
                                                </span>
                                                <button class="btn btn-sm btn-outline-info ms-1" onclick="showFullNotes('<?php echo htmlspecialchars(addslashes($drop['notes'])); ?>')" title="View full notes">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php if ($show_audit_details === '1'): ?>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <small class="text-muted">
                                                <?php if ($drop['audit_count'] > 0): ?>
                                                    <span class="badge bg-info"><?php echo $drop['audit_count']; ?> events</span>
                                                <?php else: ?>
                                                    <span class="text-muted">No audit</span>
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                        <?php if ($drop['status'] !== 'pending'): ?>
                                            <br>
                                            <small class="text-success">
                                                <i class="bi bi-check-circle-fill me-1"></i>
                                                <?php echo date('M d, H:i', strtotime($drop['confirmed_at'] ?? $drop['updated_at'])); ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <?php endif; ?>
                                    <td>
                                        <div class="d-flex flex-wrap gap-1 justify-content-start">
                                            <!-- Primary Action - View Details -->
                                            <button class="btn btn-outline-primary btn-sm" onclick="viewDropDetails(<?php echo $drop['id']; ?>)" title="View complete drop details">
                                                <i class="bi bi-eye"></i>
                                            </button>

                                            <!-- Notes Action (if notes exist) -->
                                            <?php if ($drop['notes']): ?>
                                            <button class="btn btn-outline-info btn-sm" onclick="showFullNotes('<?php echo htmlspecialchars(addslashes($drop['notes'])); ?>')" title="View full notes">
                                                <i class="bi bi-sticky"></i>
                                            </button>
                                            <?php endif; ?>

                                            <!-- Status-based Actions -->
                                            <?php if ($drop['status'] === 'pending'): ?>
                                            <div class="btn-group btn-group-sm ms-1">
                                                <button class="btn btn-outline-success btn-sm" onclick="approveDrop(<?php echo $drop['id']; ?>, '<?php echo htmlspecialchars($drop['till_name']); ?>', '<?php echo formatCurrency($drop['drop_amount'], $settings); ?>')" title="Approve this cash drop">
                                                    <i class="bi bi-check-circle"></i>
                                                </button>
                                                <button class="btn btn-outline-warning btn-sm" onclick="denyDrop(<?php echo $drop['id']; ?>, '<?php echo htmlspecialchars($drop['till_name']); ?>', '<?php echo formatCurrency($drop['drop_amount'], $settings); ?>')" title="Deny this cash drop">
                                                    <i class="bi bi-x-circle"></i>
                                                </button>
                                            </div>
                                            <?php elseif ($drop['status'] === 'confirmed'): ?>
                                            <button class="btn btn-outline-success btn-sm" onclick="printDropReceipt(<?php echo $drop['id']; ?>)" title="Print receipt">
                                                <i class="bi bi-printer"></i>
                                            </button>
                                            <button class="btn btn-outline-warning btn-sm" onclick="editDrop(<?php echo $drop['id']; ?>)" title="Edit drop details">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <?php endif; ?>

                                            <!-- Admin Actions -->
                                            <?php if (isAdmin($role_name) && $drop['status'] === 'confirmed'): ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if (empty($cash_drops)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-cash-coin fs-1 text-muted mb-3"></i>
                        <h5 class="text-muted">No cash drops found</h5>
                        <p class="text-muted">Try adjusting your filters or check if cash drops have been recorded in the selected date range.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Notes Modal -->
    <div class="modal fade" id="notesModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Drop Notes</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p id="fullNotes"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Approve Drop Modal -->
    <div class="modal fade" id="approveDropModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">
                        <i class="bi bi-check-circle me-2"></i>Approve Cash Drop
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-success">
                        <h6>Confirm Drop Approval</h6>
                        <p id="approveDropDetails"></p>
                    </div>
                    <form id="approveDropForm">
                        <input type="hidden" id="approve_drop_id" name="drop_id">
                        <input type="hidden" name="action" value="approve_drop">
                        <div class="mb-3">
                            <label for="approve_notes" class="form-label">Approval Notes (Optional)</label>
                            <textarea class="form-control" id="approve_notes" name="notes" rows="2" placeholder="Add any notes about this approval..."></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" onclick="confirmApproveDrop()">
                        <i class="bi bi-check-circle me-1"></i>Approve Drop
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Deny Drop Modal -->
    <div class="modal fade" id="denyDropModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">
                        <i class="bi bi-x-circle me-2"></i>Deny Cash Drop
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger">
                        <h6>Confirm Drop Denial</h6>
                        <p id="denyDropDetails"></p>
                    </div>
                    <form id="denyDropForm">
                        <input type="hidden" id="deny_drop_id" name="drop_id">
                        <input type="hidden" name="action" value="deny_drop">
                        <div class="mb-3">
                            <label for="deny_reason" class="form-label">Reason for Denial <span class="text-danger">*</span></label>
                            <select class="form-select" id="deny_reason" name="reason" required>
                                <option value="">Select reason...</option>
                                <option value="insufficient_funds">Insufficient Funds</option>
                                <option value="incorrect_amount">Incorrect Amount</option>
                                <option value="suspicious_activity">Suspicious Activity</option>
                                <option value="policy_violation">Policy Violation</option>
                                <option value="documentation_missing">Documentation Missing</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="deny_notes" class="form-label">Additional Notes</label>
                            <textarea class="form-control" id="deny_notes" name="notes" rows="3" placeholder="Provide additional details about the denial..."></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" onclick="confirmDenyDrop()">
                        <i class="bi bi-x-circle me-1"></i>Deny Drop
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- View Drop Details Modal -->
    <div class="modal fade" id="viewDropModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title">
                        <i class="bi bi-eye me-2"></i>Cash Drop Details
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="dropDetailsContent">
                    <!-- Content will be loaded dynamically -->
                    <div class="text-center">
                        <div class="spinner-border text-info" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-2">Loading drop details...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" id="printDropBtn" style="display: none;">
                        <i class="bi bi-printer me-1"></i>Print Details
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function exportReport() {
            const url = new URL(window.location);
            url.searchParams.set('export', 'csv');
            window.open(url, '_blank');
        }

        function exportDetailedAudit() {
            const url = new URL(window.location);
            url.searchParams.set('export', 'audit_csv');
            window.open(url, '_blank');
        }

        function viewAuditTrail(dropId) {
            // Show loading modal or create one
            const auditModal = document.getElementById('auditTrailModal') || createAuditModal();

            // Show loading state
            const modal = new bootstrap.Modal(auditModal);
            const content = document.getElementById('auditTrailContent');
            content.innerHTML = `
                <div class="text-center">
                    <div class="spinner-border text-info" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2">Loading audit trail...</p>
                </div>
            `;

            modal.show();

            // Fetch audit trail
            fetch('cash_drop_audit.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    'action': 'get_audit_trail',
                    'drop_id': dropId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    content.innerHTML = generateAuditTrailHTML(data.audit_trail, data.drop);
                } else {
                    content.innerHTML = `
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            ${data.message || 'No audit trail found for this cash drop.'}
                        </div>
                    `;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                content.innerHTML = `
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        Network error occurred while loading audit trail.
                    </div>
                `;
            });
        }

        function createAuditModal() {
            const modalHTML = `
                <div class="modal fade" id="auditTrailModal" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header bg-info text-white">
                                <h5 class="modal-title">
                                    Cash Drop Audit Trail
                                </h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body" id="auditTrailContent">
                                <!-- Content will be loaded dynamically -->
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                <button type="button" class="btn btn-primary" onclick="exportCurrentAudit()">
                                    <i class="bi bi-download me-1"></i>Export This Trail
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            document.body.insertAdjacentHTML('beforeend', modalHTML);
            return document.getElementById('auditTrailModal');
        }

        function generateAuditTrailHTML(auditTrail, drop) {
            let html = `
                <div class="row mb-3">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="mb-0">Cash Drop #${drop.id} - ${drop.till_name}</h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-3"><strong>Amount:</strong> ${drop.formatted_amount || drop.drop_amount}</div>
                                    <div class="col-md-3"><strong>Status:</strong> <span class="badge bg-${drop.status === 'confirmed' ? 'success' : drop.status === 'pending' ? 'warning' : 'danger'}">${drop.status_text || drop.status}</span></div>
                                    <div class="col-md-3"><strong>Dropped By:</strong> ${drop.dropped_by_name || 'N/A'}</div>
                                    <div class="col-md-3"><strong>Drop Date:</strong> ${new Date(drop.drop_date).toLocaleString()}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="timeline">
                    <h6 class="mb-3"><i class="bi bi-timeline me-2"></i>Audit Events</h6>
            `;

            if (auditTrail && auditTrail.length > 0) {
                auditTrail.forEach((event, index) => {
                    const eventTime = new Date(event.created_at);
                    const severityClass = event.severity === 'high' ? 'danger' :
                                        event.severity === 'medium' ? 'warning' :
                                        event.severity === 'low' ? 'info' : 'secondary';

                    html += `
                        <div class="timeline-item mb-3">
                            <div class="timeline-marker bg-${severityClass}"></div>
                            <div class="timeline-content">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h6 class="mb-1">${event.event_type.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())}</h6>
                                        <p class="mb-1 text-muted small">${event.details}</p>
                                        ${event.ip_address ? `<small class="text-muted">IP: ${event.ip_address}</small>` : ''}
                                    </div>
                                    <div class="text-end">
                                        <small class="text-muted">${eventTime.toLocaleString()}</small>
                                        <br>
                                        <span class="badge bg-${severityClass}">${event.severity}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                });
            } else {
                html += `
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        No detailed audit trail available for this cash drop. Basic logging may be enabled.
                    </div>
                `;
            }

            html += `
                </div>
                <style>
                    .timeline { position: relative; padding-left: 30px; }
                    .timeline::before { content: ''; position: absolute; left: 15px; top: 0; bottom: 0; width: 2px; background: #e9ecef; }
                    .timeline-item { position: relative; margin-bottom: 20px; }
                    .timeline-marker { position: absolute; left: -22px; top: 5px; width: 12px; height: 12px; border-radius: 50%; border: 2px solid #fff; }
                    .timeline-content { background: #f8f9fa; padding: 15px; border-radius: 5px; }
                </style>
            `;

            return html;
        }

        function exportCurrentAudit() {
            // Get the current drop ID from the modal
            const content = document.getElementById('auditTrailContent');
            const dropIdMatch = content.innerHTML.match(/Cash Drop #(\d+)/);
            if (dropIdMatch) {
                const dropId = dropIdMatch[1];
                const url = new URL(window.location);
                url.searchParams.set('export', 'single_audit_csv');
                url.searchParams.set('drop_id', dropId);
                window.open(url, '_blank');
            }
        }

        function showFullNotes(notes) {
            document.getElementById('fullNotes').textContent = notes;
            new bootstrap.Modal(document.getElementById('notesModal')).show();
        }

        function viewDropDetails(dropId) {
            // Show loading state
            const modal = new bootstrap.Modal(document.getElementById('viewDropModal'));
            const content = document.getElementById('dropDetailsContent');
            const printBtn = document.getElementById('printDropBtn');

            content.innerHTML = `
                <div class="text-center">
                    <div class="spinner-border text-info" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2">Loading drop details...</p>
                </div>
            `;

            printBtn.style.display = 'none';
            modal.show();

            // Fetch drop details
            fetch('cash_drop_details.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    'action': 'get_drop_details',
                    'drop_id': dropId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    content.innerHTML = generateDropDetailsHTML(data.drop);
                    printBtn.style.display = 'inline-block';
                    printBtn.onclick = () => printDropDetails(data.drop);
                } else {
                    content.innerHTML = `
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            Error loading drop details: ${data.message || 'Unknown error'}
                        </div>
                    `;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                content.innerHTML = `
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        Network error occurred while loading drop details.
                    </div>
                `;
            });
        }

        function generateDropDetailsHTML(drop) {
            const statusClass = drop.status === 'confirmed' ? 'success' :
                              drop.status === 'pending' ? 'warning' :
                              drop.status === 'cancelled' ? 'danger' : 'secondary';

            const dropTypeLabel = drop.drop_type === 'cashier_drop' ? 'Cashier Drop' :
                                drop.drop_type === 'manager_drop' ? 'Manager Drop' :
                                drop.drop_type === 'end_of_day_drop' ? 'End of Day' :
                                drop.drop_type === 'emergency_drop' ? 'Emergency' :
                                drop.drop_type === 'bank_deposit' ? 'Bank Deposit' :
                                drop.drop_type === 'safe_drop' ? 'Safe Drop' :
                                drop.drop_type.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase());

            return `
                <div class="row">
                    <div class="col-md-6">
                        <h6 class="text-primary mb-3"><i class="bi bi-info-circle me-2"></i>Drop Information</h6>
                        <table class="table table-sm">
                            <tr>
                                <td class="fw-bold">Drop ID:</td>
                                <td>${drop.id}</td>
                            </tr>
                            <tr>
                                <td class="fw-bold">Till:</td>
                                <td>${drop.till_name || 'N/A'} (${drop.till_code || ''})</td>
                            </tr>
                            <tr>
                                <td class="fw-bold">Amount:</td>
                                <td class="fw-bold text-success">${drop.formatted_amount || drop.drop_amount}</td>
                            </tr>
                            <tr>
                                <td class="fw-bold">Drop Type:</td>
                                <td><span class="badge bg-info">${dropTypeLabel}</span></td>
                            </tr>
                            <tr>
                                <td class="fw-bold">Emergency Drop:</td>
                                <td>${drop.is_emergency ? '<span class="badge bg-danger"><i class="bi bi-exclamation-triangle me-1"></i>Yes</span>' : '<span class="text-muted">No</span>'}</td>
                            </tr>
                            <tr>
                                <td class="fw-bold">Status:</td>
                                <td><span class="badge bg-${statusClass}">${drop.status_text || drop.status}</span></td>
                            </tr>
                            <tr>
                                <td class="fw-bold">Dropped By:</td>
                                <td>${drop.dropped_by_name || 'N/A'}</td>
                            </tr>
                            <tr>
                                <td class="fw-bold">Drop Date:</td>
                                <td>${new Date(drop.drop_date).toLocaleString()}</td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-primary mb-3"><i class="bi bi-check-circle me-2"></i>Approval Information</h6>
                        <table class="table table-sm">
                            <tr>
                                <td class="fw-bold">Confirmed By:</td>
                                <td>${drop.confirmed_by_name || 'Not confirmed'}</td>
                            </tr>
                            <tr>
                                <td class="fw-bold">Confirmed At:</td>
                                <td>${drop.confirmed_at ? new Date(drop.confirmed_at).toLocaleString() : 'Not confirmed'}</td>
                            </tr>
                            <tr>
                                <td class="fw-bold">Time to Confirm:</td>
                                <td>${drop.time_to_confirm || 'N/A'}</td>
                            </tr>
                            <tr>
                                <td class="fw-bold">Balance After:</td>
                                <td class="fw-bold text-info">${drop.till_current_balance ? drop.formatted_balance : 'N/A'}</td>
                            </tr>
                        </table>

                        ${drop.notes ? `
                        <h6 class="text-primary mb-2"><i class="bi bi-sticky me-2"></i>Notes</h6>
                        <div class="bg-light p-2 rounded small">
                            ${drop.notes.replace(/\n/g, '<br>')}
                        </div>
                        ` : ''}
                    </div>
                </div>
            `;
        }

        function printDropDetails(drop) {
            const printWindow = window.open('', '_blank');
            const statusClass = drop.status === 'confirmed' ? 'success' :
                              drop.status === 'pending' ? 'warning' :
                              drop.status === 'cancelled' ? 'danger' : 'secondary';

            printWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Cash Drop Details - ID: ${drop.id}</title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 20px; }
                        .header { text-align: center; border-bottom: 2px solid #333; padding-bottom: 10px; margin-bottom: 20px; }
                        .details { margin: 20px 0; }
                        .status { padding: 5px 10px; border-radius: 3px; color: white; }
                        .status-confirmed { background-color: #28a745; }
                        .status-pending { background-color: #ffc107; color: #000; }
                        .status-cancelled { background-color: #dc3545; }
                        table { width: 100%; border-collapse: collapse; }
                        th, td { padding: 8px; text-align: left; border-bottom: 1px solid #ddd; }
                        .notes { background-color: #f8f9fa; padding: 10px; margin-top: 20px; }
                        @media print { body { margin: 0; } }
                    </style>
                </head>
                <body>
                    <div class="header">
                        <h2>Cash Drop Details</h2>
                        <p>Drop ID: ${drop.id}</p>
                    </div>

                    <div class="details">
                        <h3>Drop Information</h3>
                        <table>
                            <tr><th>Till:</th><td>${drop.till_name || 'N/A'} (${drop.till_code || ''})</td></tr>
                            <tr><th>Amount:</th><td>${drop.formatted_amount || drop.drop_amount}</td></tr>
                            <tr><th>Emergency Drop:</th><td>${drop.is_emergency ? 'YES - Emergency Drop' : 'No'}</td></tr>
                            <tr><th>Status:</th><td><span class="status status-${drop.status}">${drop.status_text || drop.status}</span></td></tr>
                            <tr><th>Dropped By:</th><td>${drop.dropped_by_name || 'N/A'}</td></tr>
                            <tr><th>Drop Date:</th><td>${new Date(drop.drop_date).toLocaleString()}</td></tr>
                        </table>

                        <h3 style="margin-top: 30px;">Approval Information</h3>
                        <table>
                            <tr><th>Confirmed By:</th><td>${drop.confirmed_by_name || 'Not confirmed'}</td></tr>
                            <tr><th>Confirmed At:</th><td>${drop.confirmed_at ? new Date(drop.confirmed_at).toLocaleString() : 'Not confirmed'}</td></tr>
                            <tr><th>Balance After:</th><td>${drop.till_current_balance ? drop.formatted_balance : 'N/A'}</td></tr>
                        </table>

                        ${drop.notes ? `
                        <div class="notes">
                            <h4>Notes:</h4>
                            <p>${drop.notes.replace(/\n/g, '<br>')}</p>
                        </div>
                        ` : ''}
                    </div>

                    <div style="text-align: center; margin-top: 40px; font-size: 12px; color: #666;">
                        Generated on ${new Date().toLocaleString()}
                    </div>
                </body>
                </html>
            `);

            printWindow.document.close();
            printWindow.print();
        }

        function printDropReceipt(dropId) {
            // Print drop receipt
            window.open('print_drop_receipt.php?id=' + dropId, '_blank', 'width=400,height=600');
        }

        // Approve Drop Functions
        function approveDrop(dropId, tillName, amount) {
            document.getElementById('approve_drop_id').value = dropId;
            document.getElementById('approveDropDetails').innerHTML =
                `You are about to approve a cash drop of <strong>${amount}</strong> from <strong>${tillName}</strong>.<br>
                This will confirm the cash drop and update the till balance.`;
            document.getElementById('approve_notes').value = '';
            new bootstrap.Modal(document.getElementById('approveDropModal')).show();
        }

        function confirmApproveDrop() {
            const formData = new FormData(document.getElementById('approveDropForm'));
            const dropId = formData.get('drop_id');
            const notes = formData.get('notes');

            if (!dropId) {
                alert('Invalid drop ID');
                return;
            }

            // Show loading
            const button = event.target;
            const originalText = button.innerHTML;
            button.innerHTML = '<i class="bi bi-hourglass me-1"></i>Processing...';
            button.disabled = true;

            // Send AJAX request
            fetch('cash_drop_actions.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    'action': 'approve_drop',
                    'drop_id': dropId,
                    'notes': notes
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Cash drop approved successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + (data.message || 'Failed to approve drop'));
                    button.innerHTML = originalText;
                    button.disabled = false;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Network error occurred');
                button.innerHTML = originalText;
                button.disabled = false;
            });
        }

        // Deny Drop Functions
        function denyDrop(dropId, tillName, amount) {
            document.getElementById('deny_drop_id').value = dropId;
            document.getElementById('denyDropDetails').innerHTML =
                `You are about to deny a cash drop of <strong>${amount}</strong> from <strong>${tillName}</strong>.<br>
                The drop will be marked as cancelled and the till balance will be restored.`;
            document.getElementById('deny_reason').value = '';
            document.getElementById('deny_notes').value = '';
            new bootstrap.Modal(document.getElementById('denyDropModal')).show();
        }

        function confirmDenyDrop() {
            const reason = document.getElementById('deny_reason').value;
            const notes = document.getElementById('deny_notes').value;

            if (!reason) {
                alert('Please select a reason for denial');
                return;
            }

            const formData = new FormData(document.getElementById('denyDropForm'));
            const dropId = formData.get('drop_id');

            // Show loading
            const button = event.target;
            const originalText = button.innerHTML;
            button.innerHTML = '<i class="bi bi-hourglass me-1"></i>Processing...';
            button.disabled = true;

            // Send AJAX request
            fetch('cash_drop_actions.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    'action': 'deny_drop',
                    'drop_id': dropId,
                    'reason': reason,
                    'notes': notes
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Cash drop denied successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + (data.message || 'Failed to deny drop'));
                    button.innerHTML = originalText;
                    button.disabled = false;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Network error occurred');
                button.innerHTML = originalText;
                button.disabled = false;
            });
        }


        // Edit Drop Function
        function editDrop(dropId) {
            window.open('edit_cash_drop.php?id=' + dropId, '_blank', 'width=800,height=600');
        }

        // View Audit Trail Function
        function viewAuditTrail(dropId) {
            window.open('cash_drop_audit.php?id=' + dropId, '_blank', 'width=1000,height=700');
        }


        // Enhanced table interactions
        document.addEventListener('DOMContentLoaded', function() {
            // Add row highlighting on hover
            const rows = document.querySelectorAll('tbody tr');
            rows.forEach(row => {
                row.addEventListener('mouseenter', function() {
                    this.style.backgroundColor = '#f8f9fa';
                });
                row.addEventListener('mouseleave', function() {
                    this.style.backgroundColor = '';
                });

                // Highlight large amounts
                const amountCell = row.cells[3]; // Amount column
                if (amountCell) {
                    const amountText = amountCell.textContent;
                    const amount = parseFloat(amountText.replace(/[^\d.-]/g, ''));
                    if (amount > 50000) { // Highlight amounts over 50,000
                        row.classList.add('table-warning');
                        row.title = 'Large cash drop amount';
                    }
                }
            });

            // Add search functionality
            const searchInput = document.createElement('input');
            searchInput.type = 'text';
            searchInput.className = 'form-control form-control-sm';
            searchInput.placeholder = 'Search drops...';
            searchInput.style.marginBottom = '10px';

            const tableContainer = document.querySelector('.table-responsive');
            tableContainer.parentNode.insertBefore(searchInput, tableContainer);

            searchInput.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                const rows = document.querySelectorAll('tbody tr');

                rows.forEach(row => {
                    const text = row.textContent.toLowerCase();
                    if (text.includes(searchTerm)) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
            });
        });

    </script>
</body>
</html>
