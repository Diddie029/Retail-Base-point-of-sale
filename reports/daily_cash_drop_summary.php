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
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-90 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$till_id = $_GET['till_id'] ?? '';
$user_filter = $_GET['user_id'] ?? '';
$status_filter = $_GET['status'] ?? 'all';
$drop_type = $_GET['drop_type'] ?? 'all';
$sort_by = $_GET['sort_by'] ?? 'date_desc';
$view_mode = $_GET['view_mode'] ?? 'summary'; // summary, detailed, chart

// Check if cash_drops table exists
$table_exists = false;
try {
    $stmt = $conn->query("SHOW TABLES LIKE 'cash_drops'");
    $table_exists = $stmt->rowCount() > 0;
} catch (Exception $e) {
    $table_exists = false;
}

$daily_summary = [];
$overall_stats = [
    'total_days' => 0,
    'total_drops' => 0,
    'total_amount' => 0,
    'avg_daily_drops' => 0,
    'avg_daily_amount' => 0,
    'highest_day_amount' => 0,
    'highest_day_date' => '',
    'lowest_day_amount' => 0,
    'lowest_day_date' => '',
    'total_pending' => 0,
    'total_confirmed' => 0,
    'total_cancelled' => 0,
    'avg_confirmation_time' => 0
];

if ($table_exists) {
    // Build query for daily summary
    $query = "
        SELECT
            DATE(cd.drop_date) as drop_date,
            COUNT(*) as total_drops,
            SUM(cd.drop_amount) as total_amount,
            COUNT(CASE WHEN cd.status = 'pending' THEN 1 END) as pending_drops,
            COUNT(CASE WHEN cd.status = 'confirmed' THEN 1 END) as confirmed_drops,
            COUNT(CASE WHEN cd.status = 'cancelled' THEN 1 END) as cancelled_drops,
            SUM(CASE WHEN cd.status = 'pending' THEN cd.drop_amount ELSE 0 END) as pending_amount,
            SUM(CASE WHEN cd.status = 'confirmed' THEN cd.drop_amount ELSE 0 END) as confirmed_amount,
            SUM(CASE WHEN cd.status = 'cancelled' THEN cd.drop_amount ELSE 0 END) as cancelled_amount,
            AVG(CASE WHEN cd.confirmed_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, cd.drop_date, cd.confirmed_at) END) as avg_confirmation_time,
            GROUP_CONCAT(DISTINCT cd.drop_type) as drop_types,
            GROUP_CONCAT(DISTINCT rt.till_name) as tills_used,
            GROUP_CONCAT(DISTINCT u.username) as cashiers
        FROM cash_drops cd
        LEFT JOIN register_tills rt ON cd.till_id = rt.id
        LEFT JOIN users u ON cd.user_id = u.id
        WHERE DATE(cd.drop_date) BETWEEN ? AND ?
    ";

    $params = [$date_from, $date_to];

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

    $query .= " GROUP BY DATE(cd.drop_date)";

    // Add sorting
    switch ($sort_by) {
        case 'date_asc':
            $query .= " ORDER BY drop_date ASC";
            break;
        case 'date_desc':
            $query .= " ORDER BY drop_date DESC";
            break;
        case 'amount_desc':
            $query .= " ORDER BY total_amount DESC";
            break;
        case 'amount_asc':
            $query .= " ORDER BY total_amount ASC";
            break;
        case 'drops_desc':
            $query .= " ORDER BY total_drops DESC";
            break;
        case 'drops_asc':
            $query .= " ORDER BY total_drops ASC";
            break;
        default:
            $query .= " ORDER BY drop_date DESC";
    }

    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $raw_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Process and calculate additional metrics
    foreach ($raw_data as $day) {
        $date = $day['drop_date'];

        // Calculate average drop size
        $day['avg_drop_size'] = $day['total_drops'] > 0 ? $day['total_amount'] / $day['total_drops'] : 0;

        // Calculate confirmation rate
        $total_processed = $day['confirmed_drops'] + $day['cancelled_drops'];
        $day['confirmation_rate'] = $total_processed > 0 ? ($day['confirmed_drops'] / $total_processed) * 100 : 0;

        // Calculate day of week
        $day['day_of_week'] = date('l', strtotime($date));
        $day['is_weekend'] = in_array(date('N', strtotime($date)), [6, 7]);

        $daily_summary[$date] = $day;

        // Update overall stats
        $overall_stats['total_days']++;
        $overall_stats['total_drops'] += $day['total_drops'];
        $overall_stats['total_amount'] += $day['total_amount'];
        $overall_stats['total_pending'] += $day['pending_drops'];
        $overall_stats['total_confirmed'] += $day['confirmed_drops'];
        $overall_stats['total_cancelled'] += $day['cancelled_drops'];

        // Track highest and lowest days
        if ($day['total_amount'] > $overall_stats['highest_day_amount']) {
            $overall_stats['highest_day_amount'] = $day['total_amount'];
            $overall_stats['highest_day_date'] = $date;
        }

        if ($overall_stats['lowest_day_amount'] == 0 || $day['total_amount'] < $overall_stats['lowest_day_amount']) {
            $overall_stats['lowest_day_amount'] = $day['total_amount'];
            $overall_stats['lowest_day_date'] = $date;
        }
    }

    // Calculate averages
    if ($overall_stats['total_days'] > 0) {
        $overall_stats['avg_daily_drops'] = $overall_stats['total_drops'] / $overall_stats['total_days'];
        $overall_stats['avg_daily_amount'] = $overall_stats['total_amount'] / $overall_stats['total_days'];
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

// Get settings for currency
$settings = getSystemSettings($conn);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Cash Drop Summary - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/dashboard.css" rel="stylesheet">

    <!-- Chart.js for visualizations -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        .summary-card {
            transition: transform 0.2s;
        }
        .summary-card:hover {
            transform: translateY(-2px);
        }

        .trend-up { color: #28a745; }
        .trend-down { color: #dc3545; }
        .trend-neutral { color: #6c757d; }

        .chart-container {
            position: relative;
            height: 400px;
            margin: 20px 0;
        }

        .filter-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .table-responsive {
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .view-mode-btn {
            border-radius: 20px;
            padding: 8px 16px;
            margin: 0 5px;
        }

        .view-mode-btn.active {
            background-color: #0d6efd;
            color: white;
        }

        .day-highlight {
            background-color: #fff3cd !important;
        }

        .weekend-highlight {
            background-color: #f8f9fa !important;
        }

        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .stat-card.success {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
        }

        .stat-card.warning {
            background: linear-gradient(135deg, #fcb045 0%, #fd1d1d 100%);
        }

        .stat-card.info {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
                    <h2><i class="bi bi-calendar-week"></i> Daily Cash Drop Summary</h2>
                    <p class="text-muted">Comprehensive daily cash drop analysis and trends</p>
                </div>
                <div>
                    <a href="cash_drop_report.php" class="btn btn-outline-secondary me-2">
                        <i class="bi bi-arrow-left"></i> Back to Full Report
                    </a>
                    <button class="btn btn-success" onclick="exportSummary()">
                        <i class="bi bi-download"></i> Export Summary
                    </button>
                </div>
            </div>

            <!-- View Mode Toggle -->
            <div class="d-flex justify-content-center mb-4">
                <div class="btn-group" role="group">
                    <button type="button" class="btn view-mode-btn <?php echo $view_mode === 'summary' ? 'active' : ''; ?>" onclick="changeViewMode('summary')">
                        <i class="bi bi-table"></i> Summary View
                    </button>
                    <button type="button" class="btn view-mode-btn <?php echo $view_mode === 'detailed' ? 'active' : ''; ?>" onclick="changeViewMode('detailed')">
                        <i class="bi bi-list-ul"></i> Detailed View
                    </button>
                    <button type="button" class="btn view-mode-btn <?php echo $view_mode === 'chart' ? 'active' : ''; ?>" onclick="changeViewMode('chart')">
                        <i class="bi bi-graph-up"></i> Chart View
                    </button>
                </div>
            </div>

            <!-- Filters -->
            <div class="filter-section">
                <form method="GET" class="row g-3">
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
                    <div class="col-lg-1 col-md-3 col-sm-6">
                        <label class="form-label fw-semibold">Status</label>
                        <select class="form-select" name="status">
                            <option value="all" <?php echo ($status_filter === 'all' || empty($status_filter)) ? 'selected' : ''; ?>>All</option>
                            <option value="pending" <?php echo ($status_filter === 'pending') ? 'selected' : ''; ?>>Pending</option>
                            <option value="confirmed" <?php echo ($status_filter === 'confirmed') ? 'selected' : ''; ?>>Confirmed</option>
                            <option value="cancelled" <?php echo ($status_filter === 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-6 col-sm-6">
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
                    <div class="col-lg-1 col-md-3 col-sm-6">
                        <label class="form-label fw-semibold">Sort By</label>
                        <select class="form-select" name="sort_by">
                            <option value="date_desc" <?php echo ($sort_by === 'date_desc') ? 'selected' : ''; ?>>Date (Newest)</option>
                            <option value="date_asc" <?php echo ($sort_by === 'date_asc') ? 'selected' : ''; ?>>Date (Oldest)</option>
                            <option value="amount_desc" <?php echo ($sort_by === 'amount_desc') ? 'selected' : ''; ?>>Amount (High)</option>
                            <option value="amount_asc" <?php echo ($sort_by === 'amount_asc') ? 'selected' : ''; ?>>Amount (Low)</option>
                            <option value="drops_desc" <?php echo ($sort_by === 'drops_desc') ? 'selected' : ''; ?>>Drops (Most)</option>
                            <option value="drops_asc" <?php echo ($sort_by === 'drops_asc') ? 'selected' : ''; ?>>Drops (Least)</option>
                        </select>
                    </div>

                    <div class="col-12">
                        <div class="d-flex flex-wrap gap-2 mt-3">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-search me-1"></i> Apply Filters
                            </button>
                            <a href="?" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-clockwise me-1"></i> Reset
                            </a>
                            <button type="button" class="btn btn-outline-info" onclick="exportSummary()">
                                <i class="bi bi-download me-1"></i> Export
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <?php if (isset($error_message)): ?>
            <div class="alert alert-warning alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle"></i> <?php echo htmlspecialchars($error_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Overall Statistics -->
            <?php if ($table_exists && !empty($daily_summary)): ?>
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="stat-card summary-card">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h6 class="card-title">Total Period</h6>
                                <h3><?php echo formatCurrency($overall_stats['total_amount'], $settings); ?></h3>
                                <small><?php echo $overall_stats['total_days']; ?> days • <?php echo $overall_stats['total_drops']; ?> drops</small>
                            </div>
                            <i class="bi bi-cash-coin fs-1 opacity-75"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card success summary-card">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h6 class="card-title">Daily Average</h6>
                                <h3><?php echo formatCurrency($overall_stats['avg_daily_amount'], $settings); ?></h3>
                                <small><?php echo number_format($overall_stats['avg_daily_drops'], 1); ?> drops/day</small>
                            </div>
                            <i class="bi bi-graph-up fs-1 opacity-75"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card warning summary-card">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h6 class="card-title">Best Day</h6>
                                <h3><?php echo formatCurrency($overall_stats['highest_day_amount'], $settings); ?></h3>
                                <small><?php echo date('M d, Y', strtotime($overall_stats['highest_day_date'])); ?></small>
                            </div>
                            <i class="bi bi-trophy fs-1 opacity-75"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card info summary-card">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h6 class="card-title">Success Rate</h6>
                                <h3><?php echo number_format(($overall_stats['total_confirmed'] / max(1, $overall_stats['total_confirmed'] + $overall_stats['total_cancelled'])) * 100, 1); ?>%</h3>
                                <small><?php echo $overall_stats['total_confirmed']; ?>/<?php echo $overall_stats['total_confirmed'] + $overall_stats['total_cancelled']; ?> confirmed</small>
                            </div>
                            <i class="bi bi-check-circle fs-1 opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Chart View -->
            <?php if ($view_mode === 'chart'): ?>
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Daily Trends Chart</h5>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="dailyTrendsChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0">Drop Status Distribution</h6>
                        </div>
                        <div class="card-body">
                            <div class="chart-container" style="height: 300px;">
                                <canvas id="statusChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0">Average Drop Size Trend</h6>
                        </div>
                        <div class="card-body">
                            <div class="chart-container" style="height: 300px;">
                                <canvas id="avgDropSizeChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Summary Table View -->
            <?php if ($view_mode === 'summary' || $view_mode === 'detailed'): ?>
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Daily Summary</h5>
                    <div class="text-muted small">
                        Showing <?php echo count($daily_summary); ?> days
                        <?php if (!empty($date_from) && !empty($date_to)): ?>
                        from <?php echo date('M d, Y', strtotime($date_from)); ?> to <?php echo date('M d, Y', strtotime($date_to)); ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover" id="summaryTable">
                            <thead class="table-dark">
                                <tr>
                                    <th>Date</th>
                                    <th>Day</th>
                                    <th>Total Drops</th>
                                    <th>Total Amount</th>
                                    <th>Avg Drop Size</th>
                                    <?php if ($view_mode === 'detailed'): ?>
                                    <th>Pending</th>
                                    <th>Confirmed</th>
                                    <th>Cancelled</th>
                                    <th>Success Rate</th>
                                    <th>Tills Used</th>
                                    <th>Cashiers</th>
                                    <?php endif; ?>
                                    <th>Trend</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $previous_amount = 0;
                                foreach ($daily_summary as $date => $data):
                                    $trend_class = 'trend-neutral';
                                    $trend_icon = 'bi-dash-circle';

                                    if ($previous_amount > 0) {
                                        if ($data['total_amount'] > $previous_amount * 1.1) {
                                            $trend_class = 'trend-up';
                                            $trend_icon = 'bi-arrow-up-circle-fill';
                                        } elseif ($data['total_amount'] < $previous_amount * 0.9) {
                                            $trend_class = 'trend-down';
                                            $trend_icon = 'bi-arrow-down-circle-fill';
                                        }
                                    }

                                    $row_class = $data['is_weekend'] ? 'weekend-highlight' : '';
                                    if ($date === $overall_stats['highest_day_date']) {
                                        $row_class = 'day-highlight';
                                    }
                                ?>
                                <tr class="<?php echo $row_class; ?>">
                                    <td>
                                        <strong><?php echo date('M d, Y', strtotime($date)); ?></strong>
                                        <?php if ($data['is_weekend']): ?>
                                        <br><small class="text-muted">Weekend</small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $data['day_of_week']; ?></td>
                                    <td><?php echo $data['total_drops']; ?></td>
                                    <td>
                                        <strong><?php echo formatCurrency($data['total_amount'], $settings); ?></strong>
                                    </td>
                                    <td><?php echo formatCurrency($data['avg_drop_size'], $settings); ?></td>
                                    <?php if ($view_mode === 'detailed'): ?>
                                    <td><?php echo $data['pending_drops']; ?> (<?php echo formatCurrency($data['pending_amount'], $settings); ?>)</td>
                                    <td><?php echo $data['confirmed_drops']; ?> (<?php echo formatCurrency($data['confirmed_amount'], $settings); ?>)</td>
                                    <td><?php echo $data['cancelled_drops']; ?> (<?php echo formatCurrency($data['cancelled_amount'], $settings); ?>)</td>
                                    <td><?php echo number_format($data['confirmation_rate'], 1); ?>%</td>
                                    <td>
                                        <?php
                                        $tills = explode(',', $data['tills_used']);
                                        echo count(array_filter($tills)) > 1 ? count(array_filter($tills)) . ' tills' : htmlspecialchars($tills[0] ?? 'N/A');
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                        $cashiers = explode(',', $data['cashiers']);
                                        echo count(array_filter($cashiers)) > 1 ? count(array_filter($cashiers)) . ' users' : htmlspecialchars($cashiers[0] ?? 'N/A');
                                        ?>
                                    </td>
                                    <?php endif; ?>
                                    <td>
                                        <i class="bi <?php echo $trend_icon; ?> <?php echo $trend_class; ?> fs-5" title="Compared to previous day"></i>
                                    </td>
                                </tr>
                                <?php
                                $previous_amount = $data['total_amount'];
                                endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if (empty($daily_summary)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-calendar-week fs-1 text-muted mb-3"></i>
                        <h5 class="text-muted">No data found</h5>
                        <p class="text-muted">Try adjusting your filters to see daily cash drop summaries.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Additional Insights -->
            <?php if ($table_exists && !empty($daily_summary) && $view_mode === 'detailed'): ?>
            <div class="row mt-4">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0">Performance Insights</h6>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <strong>Peak Performance:</strong>
                                <p class="mb-1"><?php echo date('l, M d, Y', strtotime($overall_stats['highest_day_date'])); ?> - <?php echo formatCurrency($overall_stats['highest_day_amount'], $settings); ?></p>
                            </div>
                            <div class="mb-3">
                                <strong>Lowest Performance:</strong>
                                <p class="mb-1"><?php echo date('l, M d, Y', strtotime($overall_stats['lowest_day_date'])); ?> - <?php echo formatCurrency($overall_stats['lowest_day_amount'], $settings); ?></p>
                            </div>
                            <div>
                                <strong>Consistency:</strong>
                                <p class="mb-0">
                                    <?php
                                    $variances = array_map(function($day) use ($overall_stats) {
                                        return abs($day['total_amount'] - $overall_stats['avg_daily_amount']);
                                    }, array_values($daily_summary));
                                    $variance_count = count($variances);
                                    if ($variance_count > 0 && $overall_stats['avg_daily_amount'] > 0) {
                                        $avg_variance = array_sum($variances) / $variance_count;
                                        $consistency = 100 - min(100, ($avg_variance / $overall_stats['avg_daily_amount']) * 100);
                                        echo number_format($consistency, 1) . '% consistent daily performance';
                                    } else {
                                        echo 'Consistency calculation unavailable';
                                    }
                                    ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0">Operational Metrics</h6>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <strong>Average Confirmation Time:</strong>
                                <p class="mb-1">
                                    <?php
                                    $total_time = 0;
                                    $count = 0;
                                    foreach ($daily_summary as $day) {
                                        if ($day['avg_confirmation_time']) {
                                            $total_time += $day['avg_confirmation_time'];
                                            $count++;
                                        }
                                    }
                                    $avg_time = $count > 0 ? $total_time / $count : 0;
                                    if ($avg_time < 60) {
                                        echo round($avg_time) . ' minutes';
                                    } else {
                                        echo round($avg_time / 60, 1) . ' hours';
                                    }
                                    ?>
                                </p>
                            </div>
                            <div class="mb-3">
                                <strong>Most Active Day:</strong>
                                <p class="mb-1">
                                    <?php
                                    $most_active = array_reduce($daily_summary, function($carry, $day) {
                                        return ($day['total_drops'] > ($carry['total_drops'] ?? 0)) ? $day : $carry;
                                    });
                                    echo date('l, M d, Y', strtotime($most_active['drop_date'])) . ' - ' . $most_active['total_drops'] . ' drops';
                                    ?>
                                </p>
                            </div>
                            <div>
                                <strong>Weekend vs Weekday Performance:</strong>
                                <p class="mb-0">
                                    <?php
                                    $weekend_total = 0;
                                    $weekday_total = 0;
                                    $weekend_count = 0;
                                    $weekday_count = 0;

                                    foreach ($daily_summary as $day) {
                                        if ($day['is_weekend']) {
                                            $weekend_total += $day['total_amount'];
                                            $weekend_count++;
                                        } else {
                                            $weekday_total += $day['total_amount'];
                                            $weekday_count++;
                                        }
                                    }

                                    $weekend_avg = $weekend_count > 0 ? $weekend_total / $weekend_count : 0;
                                    $weekday_avg = $weekday_count > 0 ? $weekday_total / $weekday_count : 0;

                                    if ($weekday_avg > 0 && $weekend_avg > $weekday_avg) {
                                        echo 'Weekends perform ' . number_format((($weekend_avg - $weekday_avg) / $weekday_avg) * 100, 1) . '% better';
                                    } elseif ($weekend_avg > 0 && $weekday_avg > $weekend_avg) {
                                        echo 'Weekdays perform ' . number_format((($weekday_avg - $weekend_avg) / $weekend_avg) * 100, 1) . '% better';
                                    } else {
                                        echo 'Performance comparison unavailable';
                                    }
                                    ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function changeViewMode(mode) {
            const url = new URL(window.location);
            url.searchParams.set('view_mode', mode);
            window.location.href = url.toString();
        }

        function exportSummary() {
            const url = new URL(window.location);
            url.searchParams.set('export', 'csv');
            window.open(url.toString(), '_blank');
        }

        <?php if ($view_mode === 'chart' && !empty($daily_summary)): ?>
        // Daily Trends Chart
        const dailyTrendsCtx = document.getElementById('dailyTrendsChart').getContext('2d');
        const dailyTrendsChart = new Chart(dailyTrendsCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_map(function($date) { return date('M d', strtotime($date)); }, array_keys($daily_summary))); ?>,
                datasets: [{
                    label: 'Daily Total Amount',
                    data: <?php echo json_encode(array_column($daily_summary, 'total_amount')); ?>,
                    borderColor: '#0d6efd',
                    backgroundColor: 'rgba(13, 110, 253, 0.1)',
                    tension: 0.4,
                    fill: true
                }, {
                    label: 'Daily Drop Count',
                    data: <?php echo json_encode(array_column($daily_summary, 'total_drops')); ?>,
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                    yAxisID: 'y1',
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Amount ($)'
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Drop Count'
                        },
                        grid: {
                            drawOnChartArea: false,
                        },
                    }
                },
                plugins: {
                    legend: {
                        display: true
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                if (context.datasetIndex === 0) {
                                    return 'Amount: $' + context.parsed.y.toLocaleString();
                                } else {
                                    return 'Drops: ' + context.parsed.y;
                                }
                            }
                        }
                    }
                }
            }
        });

        // Status Distribution Chart
        const statusCtx = document.getElementById('statusChart').getContext('2d');
        const statusChart = new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: ['Confirmed', 'Pending', 'Cancelled'],
                datasets: [{
                    data: [
                        <?php echo $overall_stats['total_confirmed']; ?>,
                        <?php echo $overall_stats['total_pending']; ?>,
                        <?php echo $overall_stats['total_cancelled']; ?>
                    ],
                    backgroundColor: [
                        '#28a745',
                        '#ffc107',
                        '#dc3545'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });

        // Average Drop Size Chart
        const avgDropSizeCtx = document.getElementById('avgDropSizeChart').getContext('2d');
        const avgDropSizeChart = new Chart(avgDropSizeCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_map(function($date) { return date('M d', strtotime($date)); }, array_keys($daily_summary))); ?>,
                datasets: [{
                    label: 'Average Drop Size',
                    data: <?php echo json_encode(array_column($daily_summary, 'avg_drop_size')); ?>,
                    backgroundColor: 'rgba(54, 162, 235, 0.5)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Average Amount ($)'
                        }
                    }
                }
            }
        });
        <?php endif; ?>

        // Table enhancements
        document.addEventListener('DOMContentLoaded', function() {
            // Add row highlighting on hover
            const rows = document.querySelectorAll('#summaryTable tbody tr');
            rows.forEach(row => {
                row.addEventListener('mouseenter', function() {
                    this.style.transform = 'scale(1.01)';
                    this.style.transition = 'transform 0.2s';
                });
                row.addEventListener('mouseleave', function() {
                    this.style.transform = 'scale(1)';
                });
            });
        });
    </script>
</body>
</html>
