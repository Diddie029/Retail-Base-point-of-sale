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
    if (hasPermission('view_sales', $permissions) || hasPermission('manage_sales', $permissions)) {
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
$shortage_type = $_GET['shortage_type'] ?? '';

// Check if till_closings table exists
$table_exists = false;
try {
    $stmt = $conn->query("SHOW TABLES LIKE 'till_closings'");
    $table_exists = $stmt->rowCount() > 0;
} catch (Exception $e) {
    $table_exists = false;
}

$till_closings = [];

if ($table_exists) {
    // Build query for till short report
    $query = "
        SELECT
            tc.*,
            rt.till_name,
            rt.till_code,
            u.username as closed_by_name
        FROM till_closings tc
        LEFT JOIN register_tills rt ON tc.till_id = rt.id
        LEFT JOIN users u ON tc.user_id = u.id
        WHERE tc.closed_at BETWEEN ? AND ?
    ";

    $params = [$date_from . ' 00:00:00', $date_to . ' 23:59:59'];

    if (!empty($till_id)) {
        $query .= " AND tc.till_id = ?";
        $params[] = $till_id;
    }

    if (!empty($user_filter)) {
        $query .= " AND tc.user_id = ?";
        $params[] = $user_filter;
    }

    if (!empty($shortage_type) && $shortage_type !== 'all') {
        $query .= " AND tc.shortage_type = ?";
        $params[] = $shortage_type;
    }

    $query .= " ORDER BY tc.closed_at DESC";

    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $till_closings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Table doesn't exist, show message
    $error_message = "Till closings table not found. Please run the database migration first.";
}

// Calculate summary statistics
$total_closings = count($till_closings);
$shortage_count = 0;
$excess_count = 0;
$exact_count = 0;
$total_shortage_amount = 0;
$total_excess_amount = 0;

foreach ($till_closings as $closing) {
    if ($closing['shortage_type'] === 'shortage') {
        $shortage_count++;
        $total_shortage_amount += abs($closing['difference']);
    } elseif ($closing['shortage_type'] === 'excess') {
        $excess_count++;
        $total_excess_amount += $closing['difference'];
    } else {
        $exact_count++;
    }
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
    <title>Till Short/Excess Report - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/dashboard.css" rel="stylesheet">
    <style>
        .shortage-row {
            background-color: rgba(220, 53, 69, 0.05);
            border-left: 4px solid #dc3545;
        }
        .excess-row {
            background-color: rgba(255, 193, 7, 0.05);
            border-left: 4px solid #ffc107;
        }
        .exact-row {
            background-color: rgba(25, 135, 84, 0.05);
            border-left: 4px solid #198754;
        }

        .difference-positive {
            color: #198754;
            font-weight: bold;
        }
        .difference-negative {
            color: #dc3545;
            font-weight: bold;
        }
        .difference-zero {
            color: #6c757d;
            font-weight: bold;
        }

        .calculation-breakdown {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin: 10px 0;
        }

        .calculation-step {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 5px 0;
            border-bottom: 1px dashed #dee2e6;
        }

        .calculation-step:last-child {
            border-bottom: none;
            font-weight: bold;
            font-size: 1.1em;
        }

        .variance-indicator {
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .variance-indicator .badge {
            font-size: 0.75em;
            padding: 4px 8px;
        }

        .info-tooltip {
            cursor: help;
            border-bottom: 1px dotted #6c757d;
        }

        .summary-cards .card {
            transition: transform 0.2s ease;
            height: 140px;
            display: flex;
            flex-direction: column;
        }

        .summary-cards .card:hover {
            transform: translateY(-2px);
        }

        .summary-cards .card-body {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .status-explanation {
            font-size: 0.875em;
            color: white !important;
            margin-top: 5px;
        }

        .summary-cards .card-title {
            color: white !important;
        }

        .summary-cards .card h3 {
            color: white !important;
        }

        .summary-cards .card small {
            color: white !important;
        }

        .summary-cards .badge {
            color: white !important;
            background-color: rgba(255, 255, 255, 0.2) !important;
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
                    <h2><i class="bi bi-graph-up"></i> Till Short/Excess Report</h2>
                    <p class="text-muted">Track till discrepancies and cash management</p>
                </div>
                <div>
                    <a href="index.php" class="btn btn-outline-secondary me-2">
                        <i class="bi bi-arrow-left"></i> Back to Reports
                    </a>
                    <button class="btn btn-success" onclick="exportReport()">
                        <i class="bi bi-download"></i> Export
                    </button>
                </div>
            </div>

            <!-- Summary Statistics -->
            <div class="row mb-4 summary-cards">
                <div class="col-md-3">
                    <div class="card bg-primary text-white fw-bold">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="card-title fw-bold">
                                        <i class="bi bi-cash-register"></i> Total Closings
                                    </h6>
                                    <h3 class="fw-bold"><?php echo $total_closings; ?></h3>
                                    <div class="status-explanation fw-bold">Till reconciliation sessions</div>
                                </div>
                                <i class="bi bi-cash-register fs-1 opacity-75"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-danger text-white fw-bold">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="card-title fw-bold">
                                        <i class="bi bi-dash-circle"></i> Shortages
                                        <span class="badge bg-white text-danger ms-2 fw-bold" title="Negative values indicate missing cash">
                                            <i class="bi bi-info-circle"></i>
                                        </span>
                                    </h6>
                                    <h3 class="fw-bold"><?php echo $shortage_count; ?></h3>
                                    <small class="fw-bold">Total: <?php echo formatCurrency($total_shortage_amount, $settings); ?></small>
                                    <div class="status-explanation fw-bold">Cash counted was less than expected</div>
                                </div>
                                <i class="bi bi-dash-circle fs-1 opacity-75"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-warning text-white fw-bold">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="card-title fw-bold">
                                        <i class="bi bi-plus-circle"></i> Excesses
                                        <span class="badge bg-white text-warning ms-2 fw-bold" title="Positive values indicate extra cash found">
                                            <i class="bi bi-info-circle"></i>
                                        </span>
                                    </h6>
                                    <h3 class="fw-bold"><?php echo $excess_count; ?></h3>
                                    <small class="fw-bold">Total: <?php echo formatCurrency($total_excess_amount, $settings); ?></small>
                                    <div class="status-explanation fw-bold">Cash counted was more than expected</div>
                                </div>
                                <i class="bi bi-plus-circle fs-1 opacity-75"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-success text-white fw-bold">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="card-title fw-bold">
                                        <i class="bi bi-check-circle"></i> Exact Matches
                                        <span class="badge bg-white text-success ms-2 fw-bold" title="Perfect reconciliation - zero variance">
                                            <i class="bi bi-check-circle-fill"></i>
                                        </span>
                                    </h6>
                                    <h3 class="fw-bold"><?php echo $exact_count; ?></h3>
                                    <div class="status-explanation fw-bold">Perfect cash reconciliation</div>
                                </div>
                                <i class="bi bi-check-circle fs-1 opacity-75"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">From Date</label>
                            <input type="date" class="form-control" name="date_from" value="<?php echo $date_from; ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">To Date</label>
                            <input type="date" class="form-control" name="date_to" value="<?php echo $date_to; ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Till</label>
                            <select class="form-select" name="till_id">
                                <option value="">All Tills</option>
                                <?php foreach ($tills as $till): ?>
                                <option value="<?php echo $till['id']; ?>" <?php echo ($till_id == $till['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($till['till_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Cashier</label>
                            <select class="form-select" name="user_id">
                                <option value="">All Cashiers</option>
                                <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['id']; ?>" <?php echo ($user_filter == $user['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($user['username']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="shortage_type">
                                <option value="all" <?php echo ($shortage_type === 'all' || empty($shortage_type)) ? 'selected' : ''; ?>>All</option>
                                <option value="shortage" <?php echo ($shortage_type === 'shortage') ? 'selected' : ''; ?>>Shortage</option>
                                <option value="excess" <?php echo ($shortage_type === 'excess') ? 'selected' : ''; ?>>Excess</option>
                                <option value="exact" <?php echo ($shortage_type === 'exact') ? 'selected' : ''; ?>>Exact Match</option>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <button type="submit" class="btn btn-primary me-2">
                                <i class="bi bi-search"></i> Filter
                            </button>
                            <a href="?date_from=<?php echo date('Y-m-d', strtotime('-30 days')); ?>&date_to=<?php echo date('Y-m-d'); ?>" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-clockwise"></i> Reset
                            </a>
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

            <!-- Report Table -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Till Closing Details</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Date & Time</th>
                                    <th>Till</th>
                                    <th>Cashier</th>
                                    <th>Opening</th>
                                    <th>Sales</th>
                                    <th>Drops</th>
                                    <th>Expected</th>
                                    <th>Counted</th>
                                    <th>Difference</th>
                                    <th>Status</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($till_closings as $closing): ?>
                                <tr class="<?php
                                    if ($closing['shortage_type'] === 'shortage') echo 'shortage-row';
                                    elseif ($closing['shortage_type'] === 'excess') echo 'excess-row';
                                    else echo 'exact-row';
                                ?>">
                                    <td><?php echo date('M d, Y H:i', strtotime($closing['closed_at'])); ?></td>
                                    <td><?php echo htmlspecialchars($closing['till_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($closing['closed_by_name'] ?? 'Unknown'); ?></td>
                                    <td><?php echo formatCurrency($closing['opening_amount'], $settings); ?></td>
                                    <td><?php echo formatCurrency($closing['total_sales'], $settings); ?></td>
                                    <td><?php echo formatCurrency($closing['total_drops'], $settings); ?></td>
                                    <td>
                                        <strong><?php echo formatCurrency($closing['expected_balance'], $settings); ?></strong>
                                        <br>
                                        <small class="text-muted">
                                            <span class="info-tooltip" title="Opening + Sales - Drops">
                                                <?php echo formatCurrency($closing['opening_amount'], $settings); ?> +
                                                <?php echo formatCurrency($closing['total_sales'], $settings); ?> -
                                                <?php echo formatCurrency($closing['total_drops'], $settings); ?>
                                            </span>
                                        </small>
                                    </td>
                                    <td><?php echo formatCurrency($closing['actual_counted_amount'], $settings); ?></td>
                                    <td>
                                        <div class="variance-indicator">
                                            <span class="<?php
                                                if ($closing['difference'] < 0) echo 'difference-negative';
                                                elseif ($closing['difference'] > 0) echo 'difference-positive';
                                                else echo 'difference-zero';
                                            ?>">
                                                <?php echo formatCurrency($closing['difference'], $settings); ?>
                                            </span>
                                            <?php if ($closing['difference'] != 0): ?>
                                                <span class="badge <?php
                                                    if ($closing['shortage_type'] === 'shortage') echo 'bg-danger';
                                                    elseif ($closing['shortage_type'] === 'excess') echo 'bg-warning text-dark';
                                                    else echo 'bg-success';
                                                ?>">
                                                    <?php
                                                    if ($closing['shortage_type'] === 'shortage') echo '↓ Short';
                                                    elseif ($closing['shortage_type'] === 'excess') echo '↑ Excess';
                                                    else echo '✓ Exact';
                                                    ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <?php
                                            $status_class = '';
                                            $status_icon = '';
                                            $status_text = '';
                                            $status_description = '';
                                            switch ($closing['shortage_type']) {
                                                case 'shortage':
                                                    $status_class = 'bg-danger';
                                                    $status_icon = 'bi-dash-circle-fill';
                                                    $status_text = 'Shortage';
                                                    $status_description = 'Cash missing from till';
                                                    break;
                                                case 'excess':
                                                    $status_class = 'bg-warning text-dark';
                                                    $status_icon = 'bi-plus-circle-fill';
                                                    $status_text = 'Excess';
                                                    $status_description = 'Extra cash found in till';
                                                    break;
                                                case 'exact':
                                                    $status_class = 'bg-success';
                                                    $status_icon = 'bi-check-circle-fill';
                                                    $status_text = 'Perfect';
                                                    $status_description = 'Exact match - no variance';
                                                    break;
                                            }
                                            ?>
                                            <span class="badge <?php echo $status_class; ?> me-2">
                                                <i class="bi <?php echo $status_icon; ?> me-1"></i>
                                                <?php echo $status_text; ?>
                                            </span>
                                            <small class="text-muted d-none d-md-inline" title="<?php echo $status_description; ?>">
                                                <?php echo $status_description; ?>
                                            </small>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($closing['closing_notes']): ?>
                                            <span class="text-truncate d-inline-block" style="max-width: 150px;" title="<?php echo htmlspecialchars($closing['closing_notes']); ?>">
                                                <?php echo htmlspecialchars($closing['closing_notes']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if (empty($till_closings)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-graph-up fs-1 text-muted mb-3"></i>
                        <h5 class="text-muted">No till closing data found</h5>
                        <p class="text-muted">Try adjusting your filters or check if tills have been closed in the selected date range.</p>
                    </div>
                    <?php endif; ?>
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
    </script>
</body>
</html>
