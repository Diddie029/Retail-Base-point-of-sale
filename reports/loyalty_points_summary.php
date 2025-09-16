<?php
session_start();
require_once '../include/db.php';
require_once '../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

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
$hasAccess = isAdmin($role_name) ||
             hasPermission('view_analytics', $permissions) ||
             hasPermission('manage_sales', $permissions) ||
             hasPermission('manage_users', $permissions) ||
             hasPermission('view_finance', $permissions);

if (!$hasAccess) {
    header('Location: ../dashboard/dashboard.php');
    exit();
}

// Get system settings for navmenu display
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get loyalty points summary data
$loyalty_stats = [];

// Total points earned today
$stmt = $conn->prepare("SELECT COALESCE(SUM(points_earned), 0) as total FROM loyalty_points WHERE DATE(created_at) = CURDATE() AND transaction_type = 'earned'");
$stmt->execute();
$loyalty_stats['today_earned'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total points redeemed today
$stmt = $conn->prepare("SELECT COALESCE(SUM(points_redeemed), 0) as total FROM loyalty_points WHERE DATE(created_at) = CURDATE() AND transaction_type = 'redeemed'");
$stmt->execute();
$loyalty_stats['today_redeemed'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total active customers with loyalty points
$stmt = $conn->prepare("SELECT COUNT(DISTINCT customer_id) as count FROM loyalty_points WHERE points_balance > 0");
$stmt->execute();
$loyalty_stats['active_customers'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Total loyalty points balance across all customers
$stmt = $conn->prepare("SELECT COALESCE(SUM(points_balance), 0) as total FROM loyalty_points WHERE points_balance > 0");
$stmt->execute();
$loyalty_stats['total_balance'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Points earned this month
$stmt = $conn->prepare("SELECT COALESCE(SUM(points_earned), 0) as total FROM loyalty_points WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) AND transaction_type = 'earned'");
$stmt->execute();
$loyalty_stats['month_earned'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Points redeemed this month
$stmt = $conn->prepare("SELECT COALESCE(SUM(points_redeemed), 0) as total FROM loyalty_points WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) AND transaction_type = 'redeemed'");
$stmt->execute();
$loyalty_stats['month_redeemed'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Get top 5 customers by points balance
$stmt = $conn->prepare("
    SELECT
        c.first_name,
        c.last_name,
        c.phone,
        c.membership_level,
        MAX(lp.points_balance) as points_balance
    FROM customers c
    INNER JOIN loyalty_points lp ON c.id = lp.customer_id
    WHERE lp.points_balance > 0
    GROUP BY c.id, c.first_name, c.last_name, c.phone, c.membership_level
    ORDER BY points_balance DESC
    LIMIT 5
");
$stmt->execute();
$top_customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get points transaction summary by type
$stmt = $conn->prepare("
    SELECT
        transaction_type,
        COUNT(*) as transaction_count,
        COALESCE(SUM(points_earned), 0) as total_earned,
        COALESCE(SUM(points_redeemed), 0) as total_redeemed
    FROM loyalty_points
    WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())
    GROUP BY transaction_type
");
$stmt->execute();
$transaction_summary = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate redemption rate
$redemption_rate = 0;
if ($loyalty_stats['month_earned'] > 0) {
    $redemption_rate = ($loyalty_stats['month_redeemed'] / $loyalty_stats['month_earned']) * 100;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loyalty Points Summary - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }

        .loyalty-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1rem;
        }

        .loyalty-card.earned {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
        }

        .loyalty-card.redeemed {
            background: linear-gradient(135deg, #fc466b 0%, #3f5efb 100%);
        }

        .loyalty-card.balance {
            background: linear-gradient(135deg, #fdbb2d 0%, #22c1c3 100%);
        }

        .loyalty-card.customers {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }

        .stats-card {
            border: none;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            border-radius: 12px;
        }

        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .top-customer-card {
            border: none;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
            margin-bottom: 10px;
        }

        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }
    </style>
</head>
<body>
    <?php include '../include/navmenu.php'; ?>

    <div class="main-content">
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1><i class="bi bi-gift"></i> Loyalty Points Summary</h1>
                    <p class="header-subtitle">Comprehensive overview of customer loyalty program performance</p>
                </div>
                <div class="header-actions">
                    <div class="user-info">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($username, 0, 2)); ?>
                        </div>
                        <div>
                            <div class="fw-semibold"><?php echo htmlspecialchars($username); ?></div>
                            <small class="text-muted"><?php echo htmlspecialchars($role_name); ?></small>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <main class="content">
            <div class="container-fluid">
                <!-- Key Metrics -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="loyalty-card">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Today's Points Earned</h6>
                                <i class="bi bi-plus-circle fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo number_format($loyalty_stats['today_earned']); ?></h3>
                            <small class="opacity-75">New loyalty points</small>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="loyalty-card earned">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Today's Points Redeemed</h6>
                                <i class="bi bi-dash-circle fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo number_format($loyalty_stats['today_redeemed']); ?></h3>
                            <small class="opacity-75">Points used by customers</small>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="loyalty-card balance">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Total Points Balance</h6>
                                <i class="bi bi-wallet fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo number_format($loyalty_stats['total_balance']); ?></h3>
                            <small class="opacity-75">Available to redeem</small>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="loyalty-card customers">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Active Loyalty Customers</h6>
                                <i class="bi bi-people fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo number_format($loyalty_stats['active_customers']); ?></h3>
                            <small class="opacity-75">Customers with points</small>
                        </div>
                    </div>
                </div>

                <div class="row mb-4">
                    <!-- Monthly Performance -->
                    <div class="col-xl-6 col-lg-6 mb-4">
                        <div class="card stats-card">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0"><i class="bi bi-calendar-month"></i> Monthly Performance</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="text-center">
                                            <h4 class="text-success"><?php echo number_format($loyalty_stats['month_earned']); ?></h4>
                                            <p class="mb-0 text-muted">Points Earned</p>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="text-center">
                                            <h4 class="text-danger"><?php echo number_format($loyalty_stats['month_redeemed']); ?></h4>
                                            <p class="mb-0 text-muted">Points Redeemed</p>
                                        </div>
                                    </div>
                                </div>
                                <hr>
                                <div class="text-center">
                                    <h5 class="text-info">Redemption Rate: <?php echo number_format($redemption_rate, 1); ?>%</h5>
                                    <small class="text-muted">Percentage of earned points that were redeemed</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Transaction Types Chart -->
                    <div class="col-xl-6 col-lg-6 mb-4">
                        <div class="card stats-card">
                            <div class="card-header bg-success text-white">
                                <h5 class="mb-0"><i class="bi bi-pie-chart"></i> Transaction Distribution</h5>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="transactionChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row mb-4">
                    <!-- Top Customers by Points -->
                    <div class="col-xl-6 col-lg-6 mb-4">
                        <div class="card stats-card">
                            <div class="card-header bg-warning text-dark">
                                <h5 class="mb-0"><i class="bi bi-trophy"></i> Top Loyalty Customers</h5>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($top_customers)): ?>
                                    <?php foreach ($top_customers as $index => $customer): ?>
                                        <div class="top-customer-card p-3">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <h6 class="mb-0">
                                                        <?php echo htmlspecialchars(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '')); ?>
                                                        <small class="text-muted">(<?php echo htmlspecialchars($customer['phone'] ?? ''); ?>)</small>
                                                    </h6>
                                                    <small class="text-muted"><?php echo htmlspecialchars($customer['membership_level'] ?? 'Basic'); ?></small>
                                                </div>
                                                <div class="text-end">
                                                    <h5 class="text-success mb-0"><?php echo number_format($customer['points_balance']); ?></h5>
                                                    <small class="text-muted">points</small>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p class="text-center text-muted">No customers with loyalty points yet.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Transaction Summary Table -->
                    <div class="col-xl-6 col-lg-6 mb-4">
                        <div class="card stats-card">
                            <div class="card-header bg-info text-white">
                                <h5 class="mb-0"><i class="bi bi-list-ul"></i> Transaction Summary</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Transaction Type</th>
                                                <th>Count</th>
                                                <th>Points</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($transaction_summary as $summary): ?>
                                                <tr>
                                                    <td><?php echo ucfirst(htmlspecialchars($summary['transaction_type'] ?? '')); ?></td>
                                                    <td><?php echo number_format($summary['transaction_count']); ?></td>
                                                    <td>
                                                        <?php if ($summary['transaction_type'] === 'earned'): ?>
                                                            <span class="text-success">+<?php echo number_format($summary['total_earned']); ?></span>
                                                        <?php elseif ($summary['transaction_type'] === 'redeemed'): ?>
                                                            <span class="text-danger">-<?php echo number_format($summary['total_redeemed']); ?></span>
                                                        <?php else: ?>
                                                            <?php echo number_format($summary['total_earned'] - $summary['total_redeemed']); ?>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <?php if (empty($transaction_summary)): ?>
                                                <tr>
                                                    <td colspan="3" class="text-center text-muted">No transactions this month</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="row">
                    <div class="col-12">
                        <div class="card stats-card">
                            <div class="card-body text-center">
                                <h5>Explore More Loyalty Reports</h5>
                                <div class="d-flex justify-content-center gap-3 mt-3">
                                    <a href="loyalty_points_transactions.php" class="btn btn-primary">
                                        <i class="bi bi-list-ul"></i> Detailed Transactions
                                    </a>
                                    <a href="loyalty_customer_ranking.php" class="btn btn-success">
                                        <i class="bi bi-trophy"></i> Customer Rankings
                                    </a>
                                    <a href="index.php" class="btn btn-secondary">
                                        <i class="bi bi-arrow-left"></i> Back to Reports
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Transaction Distribution Chart
        const ctx = document.getElementById('transactionChart').getContext('2d');
        const transactionChart = new Chart(ctx, {
            type: 'pie',
            data: {
                labels: ['Earned', 'Redeemed', 'Expired', 'Adjusted'],
                datasets: [{
                    data: [
                        <?php echo $loyalty_stats['month_earned']; ?>,
                        <?php echo $loyalty_stats['month_redeemed']; ?>,
                        0, // Expired - you can add this if you track it
                        0  // Adjusted - you can add this if you track it
                    ],
                    backgroundColor: [
                        '#28a745', // Earned - green
                        '#dc3545', // Redeemed - red
                        '#ffc107', // Expired - yellow
                        '#17a2b8'  // Adjusted - blue
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                    }
                }
            }
        });
    </script>
</body>
</html>
