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

// Check if user has permission to view till details
$hasAccess = false;
if (isAdmin($role_name)) {
    $hasAccess = true;
}
if (!$hasAccess && !empty($permissions)) {
    if (hasPermission('view_sales', $permissions) || hasPermission('manage_sales', $permissions) ||
        hasPermission('view_finance', $permissions) || hasPermission('manage_tills', $permissions)) {
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

// Get parameters
$till_id = intval($_GET['till_id'] ?? 0);
$date = $_GET['date'] ?? date('Y-m-d');

// Validate parameters
if ($till_id <= 0) {
    echo '<div class="alert alert-danger">Invalid till ID</div>';
    echo '<a href="../sales/salesdashboard.php" class="btn btn-primary">Back to Dashboard</a>';
    exit();
}

// Get system settings
$stmt = $conn->query("SELECT * FROM settings");
$settings = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
$currency_symbol = $settings['currency_symbol'] ?? 'KES';

// Get till information
$stmt = $conn->prepare("
    SELECT * FROM register_tills
    WHERE id = :till_id AND is_active = 1
");
$stmt->bindParam(':till_id', $till_id);
$stmt->execute();
$till_info = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$till_info) {
    echo '<div class="alert alert-danger">Till not found or inactive</div>';
    echo '<a href="../sales/salesdashboard.php" class="btn btn-primary">Back to Dashboard</a>';
    exit();
}

// Get transactions for this till and date
$stmt = $conn->prepare("
    SELECT
        s.id,
        s.customer_id,
        s.total_amount,
        s.payment_method,
        s.created_at,
        c.first_name,
        c.last_name,
        c.phone
    FROM sales s
    LEFT JOIN customers c ON s.customer_id = c.id
    WHERE s.till_id = :till_id
    AND DATE(s.created_at) = :date
    ORDER BY s.created_at DESC
");
$stmt->bindParam(':till_id', $till_id);
$stmt->bindParam(':date', $date);
$stmt->execute();
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get cash drops for this till and date
$stmt = $conn->prepare("
    SELECT * FROM cash_drops
    WHERE till_id = :till_id
    AND DATE(drop_date) = :date
    ORDER BY drop_date DESC
");
$stmt->bindParam(':till_id', $till_id);
$stmt->bindParam(':date', $date);
$stmt->execute();
$cash_drops = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get till opening/closing information if available
$stmt = $conn->prepare("
    SELECT * FROM till_closings
    WHERE till_id = :till_id
    AND DATE(closed_at) = :date
    ORDER BY closed_at DESC
    LIMIT 1
");
$stmt->bindParam(':till_id', $till_id);
$stmt->bindParam(':date', $date);
$stmt->execute();
$closing_info = $stmt->fetch(PDO::FETCH_ASSOC);

// Calculate summary statistics
$total_transactions = count($transactions);
$total_sales = array_sum(array_column($transactions, 'total_amount'));
$total_cash_drops = array_sum(array_column($cash_drops, 'drop_amount'));

// Get payment method breakdown
$payment_breakdown = [];
foreach ($transactions as $transaction) {
    $method = $transaction['payment_method'];
    if (!isset($payment_breakdown[$method])) {
        $payment_breakdown[$method] = 0;
    }
    $payment_breakdown[$method] += $transaction['total_amount'];
}

// Get previous day closing for opening amount
$previous_date = date('Y-m-d', strtotime($date . ' -1 day'));
$stmt = $conn->prepare("
    SELECT total_amount as opening_amount
    FROM till_closings
    WHERE till_id = :till_id
    AND DATE(closed_at) = :previous_date
    ORDER BY closed_at DESC
    LIMIT 1
");
$stmt->bindParam(':till_id', $till_id);
$stmt->bindParam(':previous_date', $previous_date);
$stmt->execute();
$previous_closing = $stmt->fetch(PDO::FETCH_ASSOC);
$opening_amount = $previous_closing['opening_amount'] ?? 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Till Details - <?php echo htmlspecialchars($till_info['till_name']); ?> - <?php echo $date; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/dashboard.css" rel="stylesheet">
    <style>
        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .till-header {
            background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
            border-radius: 15px;
        }

        .transaction-row:hover {
            background-color: #f8f9fa;
        }

        .payment-badge {
            font-size: 0.75em;
            padding: 0.375rem 0.75rem;
            border-radius: 20px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
        }

        .summary-box {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .cash-drop-card {
            border-left: 4px solid #dc3545;
            background: rgba(220, 53, 69, 0.05);
        }

        .table th {
            background-color: #343a40;
            color: white;
            border: none;
        }

        .table td {
            vertical-align: middle;
        }

        .customer-info {
            font-size: 0.9em;
            color: #6c757d;
        }

        .date-selector {
            background: white;
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 1rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .payment-method-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            transition: transform 0.2s ease;
        }

        .payment-method-icon:hover {
            transform: translateY(-2px);
        }

        .progress {
            border-radius: 3px;
            background-color: #e9ecef;
        }

        .summary-box .card {
            transition: all 0.3s ease;
        }

        .summary-box .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>
    <?php include '../include/navmenu.php'; ?>

    <div class="main-content">
        <div class="container-fluid">
            <!-- Header -->
            <div class="till-header">
                <div class="container">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h2><i class="bi bi-eye"></i> Till Transaction Details</h2>
                            <p class="mb-0"><?php echo htmlspecialchars($till_info['till_name']); ?> - <?php echo date('F d, Y', strtotime($date)); ?> <span class="badge bg-info">Read-Only View</span></p>
                        </div>
                        <div class="col-md-4 text-end">
                            <!-- Read-only view - no action buttons -->
                        </div>
                    </div>
                </div>
            </div>

            <!-- Date Selector -->
            <div class="date-selector">
                <div class="row align-items-center">
                    <div class="col-md-4">
                        <label class="form-label">Select Date</label>
                        <input type="date" class="form-control" id="datePicker" value="<?php echo $date; ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Till</label>
                        <select class="form-control" id="tillSelector">
                            <?php
                            $stmt = $conn->query("SELECT id, till_name FROM register_tills WHERE is_active = 1 ORDER BY till_name");
                            $tills = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            foreach ($tills as $till): ?>
                            <option value="<?php echo $till['id']; ?>" <?php echo ($till['id'] == $till_id) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($till['till_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">&nbsp;</label>
                        <button class="btn btn-primary d-block" onclick="updateTillDetails()">
                            <i class="bi bi-search"></i> View Details
                        </button>
                    </div>
                </div>
            </div>

            <!-- Summary Statistics -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="stats-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-1">Transactions</h6>
                                <h3 class="mb-0"><?php echo $total_transactions; ?></h3>
                            </div>
                            <i class="bi bi-receipt fs-1 opacity-75"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #28a745 0%, #20c997 100%);">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-1">Total Sales</h6>
                                <h3 class="mb-0"><?php echo $currency_symbol; ?> <?php echo number_format($total_sales, 2); ?></h3>
                            </div>
                            <i class="bi bi-currency-dollar fs-1 opacity-75"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #ffc107 0%, #fd7e14 100%);">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-1">Cash Drops</h6>
                                <h3 class="mb-0"><?php echo count($cash_drops); ?></h3>
                                <small class="opacity-75"><?php echo $currency_symbol; ?> <?php echo number_format($total_cash_drops, 2); ?></small>
                            </div>
                            <i class="bi bi-arrow-down-circle fs-1 opacity-75"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-1">Opening Amount</h6>
                                <h3 class="mb-0"><?php echo $currency_symbol; ?> <?php echo number_format($opening_amount, 2); ?></h3>
                            </div>
                            <i class="bi bi-box-arrow-in-right fs-1 opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Payment Method Breakdown -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="summary-box">
                        <h5><i class="bi bi-credit-card"></i> Payment Method Breakdown</h5>
                        <?php if (empty($payment_breakdown)): ?>
                        <div class="text-center text-muted py-4">
                            <div class="mb-3">
                                <i class="bi bi-credit-card-2-front fs-1 text-muted"></i>
                            </div>
                            <h6 class="text-muted">No Payment Transactions</h6>
                            <p class="small mb-0">No payment methods were recorded for transactions on this date</p>
                        </div>
                        <?php else: ?>
                        <div class="row g-3">
                            <?php
                            $total_amount = array_sum($payment_breakdown);
                            foreach ($payment_breakdown as $method => $amount):
                                $percentage = ($total_amount > 0) ? round(($amount / $total_amount) * 100, 1) : 0;
                                $method_icon = 'credit-card';
                                $method_color = 'primary';

                                switch ($method) {
                                    case 'cash':
                                        $method_icon = 'cash-stack';
                                        $method_color = 'success';
                                        break;
                                    case 'card':
                                        $method_icon = 'credit-card-2-back';
                                        $method_color = 'info';
                                        break;
                                    case 'mobile_money':
                                        $method_icon = 'phone';
                                        $method_color = 'warning';
                                        break;
                                    case 'voucher':
                                        $method_icon = 'ticket-perforated';
                                        $method_color = 'secondary';
                                        break;
                                    case 'loyalty_points':
                                        $method_icon = 'star';
                                        $method_color = 'danger';
                                        break;
                                    default:
                                        $method_icon = 'credit-card';
                                        $method_color = 'primary';
                                }
                            ?>
                            <div class="col-md-6 col-lg-4">
                                <div class="card border-0 shadow-sm h-100">
                                    <div class="card-body text-center">
                                        <div class="d-flex align-items-center justify-content-between mb-2">
                                            <div class="payment-method-icon" style="background: linear-gradient(135deg, var(--bs-<?php echo $method_color; ?>) 0%, var(--bs-<?php echo $method_color; ?>-dark) 100%);">
                                                <i class="bi bi-<?php echo $method_icon; ?>"></i>
                                            </div>
                                            <div class="text-end">
                                                <div class="badge bg-<?php echo $method_color; ?> mb-1">
                                                    <?php echo $percentage; ?>%
                                                </div>
                                            </div>
                                        </div>
                                        <h6 class="text-muted mb-1"><?php echo ucfirst(str_replace('_', ' ', $method)); ?></h6>
                                        <div class="h5 fw-bold text-<?php echo $method_color; ?> mb-0">
                                            <?php echo $currency_symbol; ?> <?php echo number_format($amount, 2); ?>
                                        </div>
                                        <div class="progress mt-2" style="height: 6px;">
                                            <div class="progress-bar bg-<?php echo $method_color; ?>"
                                                 role="progressbar"
                                                 style="width: <?php echo $percentage; ?>%"
                                                 aria-valuenow="<?php echo $percentage; ?>"
                                                 aria-valuemin="0"
                                                 aria-valuemax="100">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Summary Statistics -->
                        <div class="row mt-3 pt-3 border-top">
                            <div class="col-md-4">
                                <div class="text-center">
                                    <small class="text-muted">Total Transactions</small>
                                    <div class="h6 mb-0"><?php echo $total_transactions; ?> transactions</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-center">
                                    <small class="text-muted">Total Sales</small>
                                    <div class="h6 mb-0 text-primary"><?php echo $currency_symbol; ?> <?php echo number_format($total_sales, 2); ?></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-center">
                                    <small class="text-muted">Payment Methods</small>
                                    <div class="h6 mb-0">
                                        <?php echo count($payment_breakdown); ?> methods
                                        <?php if (count($payment_breakdown) > 1): ?>
                                        <div class="text-success small">✓ Mixed payments</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if (count($payment_breakdown) > 1): ?>
                        <!-- Payment Distribution Chart Legend -->
                        <div class="row mt-2">
                            <div class="col-12">
                                <div class="d-flex justify-content-center gap-3 flex-wrap">
                                    <small class="text-muted">Distribution:</small>
                                    <?php foreach ($payment_breakdown as $method => $amount): ?>
                                    <small class="d-flex align-items-center">
                                        <span class="badge bg-<?php
                                            switch ($method) {
                                                case 'cash': echo 'success'; break;
                                                case 'card': echo 'info'; break;
                                                case 'mobile_money': echo 'warning'; break;
                                                case 'voucher': echo 'secondary'; break;
                                                case 'loyalty_points': echo 'danger'; break;
                                                default: echo 'primary';
                                            }
                                        ?> me-1">●</span>
                                        <?php echo ucfirst($method); ?>
                                    </small>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Transactions Table -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-receipt"></i> Transactions (<?php echo $total_transactions; ?>)</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Time</th>
                                            <th>Customer</th>
                                            <th>Payment Method</th>
                                            <th>Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($transactions)): ?>
                                        <tr>
                                            <td colspan="4" class="text-center text-muted py-4">
                                                <i class="bi bi-info-circle fs-4 mb-2"></i><br>
                                                No transactions found for this date
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                        <?php foreach ($transactions as $transaction): ?>
                                        <tr class="transaction-row">
                                            <td><?php echo date('H:i:s', strtotime($transaction['created_at'])); ?></td>
                                            <td>
                                                <div>
                                                    <?php if ($transaction['customer_id']): ?>
                                                        <strong><?php echo htmlspecialchars($transaction['first_name'] . ' ' . $transaction['last_name']); ?></strong>
                                                        <div class="customer-info"><?php echo htmlspecialchars($transaction['phone'] ?? 'No phone'); ?></div>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">Walk-in Customer</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php
                                                $payment_method = $transaction['payment_method'];
                                                $method_icon = 'credit-card';
                                                $method_color = 'primary';

                                                switch ($payment_method) {
                                                    case 'cash':
                                                        $method_icon = 'cash-stack';
                                                        $method_color = 'success';
                                                        break;
                                                    case 'card':
                                                        $method_icon = 'credit-card-2-back';
                                                        $method_color = 'info';
                                                        break;
                                                    case 'mobile_money':
                                                        $method_icon = 'phone';
                                                        $method_color = 'warning';
                                                        break;
                                                    case 'voucher':
                                                        $method_icon = 'ticket-perforated';
                                                        $method_color = 'secondary';
                                                        break;
                                                    case 'loyalty_points':
                                                        $method_icon = 'star';
                                                        $method_color = 'danger';
                                                        break;
                                                }
                                                ?>
                                                <div class="d-flex align-items-center">
                                                    <span class="badge bg-<?php echo $method_color; ?> payment-badge me-2">
                                                        <i class="bi bi-<?php echo $method_icon; ?> me-1"></i>
                                                        <?php echo ucfirst(str_replace('_', ' ', $payment_method)); ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td class="fw-bold"><?php echo $currency_symbol; ?> <?php echo number_format($transaction['total_amount'], 2); ?></td>
                                            <td>
                                                <!-- View Details button removed -->
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

            <!-- Cash Drops Table -->
            <?php if (!empty($cash_drops)): ?>
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-arrow-down-circle"></i> Cash Drops (<?php echo count($cash_drops); ?>)</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Time</th>
                                            <th>Amount</th>
                                            <th>Reason</th>
                                            <th>Dropped By</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($cash_drops as $drop): ?>
                                        <tr class="cash-drop-card">
                                            <td><?php echo date('H:i:s', strtotime($drop['drop_date'])); ?></td>
                                            <td class="fw-bold text-danger"><?php echo $currency_symbol; ?> <?php echo number_format($drop['drop_amount'], 2); ?></td>
                                            <td><?php echo htmlspecialchars($drop['notes'] ?? 'No reason specified'); ?></td>
                                            <td>
                                                <?php
                                                $stmt = $conn->prepare("SELECT username FROM users WHERE id = :user_id");
                                                $stmt->bindParam(':user_id', $drop['user_id']);
                                                $stmt->execute();
                                                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                                                echo htmlspecialchars($user['username'] ?? 'Unknown');
                                                ?>
                                            </td>
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

            <!-- Till Closing Information -->
            <?php if ($closing_info): ?>
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header bg-success text-white">
                            <h5 class="mb-0"><i class="bi bi-check-circle"></i> Till Closing Summary</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="summary-box">
                                        <h6>Till Information</h6>
                                        <table class="table table-sm">
                                            <tr>
                                                <td><strong>Closed At:</strong></td>
                                                <td><?php echo date('F d, Y H:i:s', strtotime($closing_info['closed_at'])); ?></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Closed By:</strong></td>
                                                <td>
                                                    <?php
                                                    $stmt = $conn->prepare("SELECT username, first_name, last_name FROM users WHERE id = :user_id");
                                                    $stmt->bindParam(':user_id', $closing_info['user_id']);
                                                    $stmt->execute();
                                                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                                                    echo htmlspecialchars(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '') . ' (' . ($user['username'] ?? 'Unknown') . ')');
                                                    ?>
                                                </td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="summary-box">
                                        <h6>Financial Summary</h6>
                                        <table class="table table-sm">
                                            <tr>
                                                <td><strong>Expected Balance:</strong></td>
                                                <td class="fw-bold"><?php echo $currency_symbol; ?> <?php echo number_format($closing_info['expected_balance'], 2); ?></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Actual Counted:</strong></td>
                                                <td class="fw-bold"><?php echo $currency_symbol; ?> <?php echo number_format($closing_info['actual_counted_amount'], 2); ?></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Difference:</strong></td>
                                                <td class="fw-bold <?php echo ($closing_info['difference'] < 0) ? 'text-danger' : (($closing_info['difference'] > 0) ? 'text-success' : ''); ?>">
                                                    <?php echo $currency_symbol; ?> <?php echo number_format($closing_info['difference'], 2); ?>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td><strong>Status:</strong></td>
                                                <td>
                                                    <span class="badge bg-<?php echo ($closing_info['shortage_type'] === 'exact') ? 'success' : (($closing_info['shortage_type'] === 'shortage') ? 'warning' : 'info'); ?>">
                                                        <?php echo ucfirst($closing_info['shortage_type']); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            <?php if ($closing_info['closing_notes']): ?>
                            <div class="summary-box">
                                <h6>Closing Notes</h6>
                                <p><?php echo htmlspecialchars($closing_info['closing_notes']); ?></p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Action Buttons -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <a href="../sales/salesdashboard.php" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-left"></i> Back to Dashboard
                            </a>
                            <a href="till_details.php?till_id=<?php echo $till_id; ?>&date=<?php echo date('Y-m-d', strtotime($date . ' -1 day')); ?>" class="btn btn-outline-info">
                                <i class="bi bi-chevron-left"></i> Previous Day
                            </a>
                            <a href="till_details.php?till_id=<?php echo $till_id; ?>&date=<?php echo date('Y-m-d', strtotime($date . ' +1 day')); ?>" class="btn btn-outline-info">
                                Next Day <i class="bi bi-chevron-right"></i>
                            </a>
                            <button class="btn btn-outline-primary" onclick="printTillDetails()">
                                <i class="bi bi-printer"></i> Print Report
                            </button>
                        </div>
                        <div>
                            <!-- Read-only view - no management actions -->
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function updateTillDetails() {
            const tillId = document.getElementById('tillSelector').value;
            const date = document.getElementById('datePicker').value;
            window.location.href = `till_details.php?till_id=${tillId}&date=${date}`;
        }


        function printTillDetails() {
            window.print();
        }


        // Auto-update when date changes
        document.getElementById('datePicker').addEventListener('change', function() {
            const tillId = document.getElementById('tillSelector').value;
            const date = this.value;
            window.location.href = `till_details.php?till_id=${tillId}&date=${date}`;
        });
    </script>

    <style>
        @media print {
            .btn, .date-selector, .till-header {
                display: none !important;
            }
            .card {
                border: 1px solid #000 !important;
                box-shadow: none !important;
            }
            .card-header {
                background-color: #f8f9fa !important;
                color: #000 !important;
                -webkit-print-color-adjust: exact;
            }
        }
    </style>
</body>
</html>
