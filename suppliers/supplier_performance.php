<?php
session_start();
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
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

// Get supplier ID from URL
$supplier_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$period = isset($_GET['period']) ? $_GET['period'] : '90days';

// Validate supplier exists
$stmt = $conn->prepare("SELECT * FROM suppliers WHERE id = :id");
$stmt->bindParam(':id', $supplier_id);
$stmt->execute();
$supplier = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$supplier) {
    header("Location: suppliers.php?error=supplier_not_found");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get performance data
$performance = getSupplierPerformance($conn, $supplier_id, $period);
$cost_comparison = getSupplierCostComparison($conn, $supplier_id);

// Get performance history (last 12 months)
$performance_history = [];
$stmt = $conn->prepare("
    SELECT * FROM supplier_performance_metrics
    WHERE supplier_id = :supplier_id
    ORDER BY metric_date DESC
    LIMIT 12
");
$stmt->bindParam(':supplier_id', $supplier_id);
$stmt->execute();
$performance_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent orders for this supplier (last 10)
$recent_orders = [];
$stmt = $conn->prepare("
    SELECT io.*, COUNT(ioi.id) as item_count,
           CASE
               WHEN io.received_date IS NULL THEN 'pending'
               WHEN io.received_date <= io.expected_date THEN 'on_time'
               ELSE 'late'
           END as delivery_status
    FROM inventory_orders io
    LEFT JOIN inventory_order_items ioi ON io.id = ioi.order_id
    WHERE io.supplier_id = :supplier_id
    GROUP BY io.id
    ORDER BY io.created_at DESC
    LIMIT 10
");
$stmt->bindParam(':supplier_id', $supplier_id);
$stmt->execute();
$recent_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get quality issues for this supplier
$quality_issues = [];
$stmt = $conn->prepare("
    SELECT * FROM supplier_quality_issues
    WHERE supplier_id = :supplier_id
    ORDER BY reported_date DESC
    LIMIT 10
");
$stmt->bindParam(':supplier_id', $supplier_id);
$stmt->execute();
$quality_issues = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate period comparison
$previous_period = $period;
$current_performance = $performance;
$previous_performance = [];

if ($period === '90days') {
    $previous_performance = getSupplierPerformance($conn, $supplier_id, '90days');
} elseif ($period === '30days') {
    $previous_performance = getSupplierPerformance($conn, $supplier_id, '30days');
}

// Calculate trends
$trends = [];
if (!empty($previous_performance)) {
    $trends['score_change'] = $current_performance['overall_score'] - $previous_performance['overall_score'];
    $trends['delivery_change'] = $current_performance['delivery_performance']['on_time_percentage'] - $previous_performance['delivery_performance']['on_time_percentage'];
    $trends['quality_change'] = $current_performance['quality_metrics']['quality_score'] - $previous_performance['quality_metrics']['quality_score'];
}

// Helper function to get trend class
function getTrendClass($change) {
    if ($change > 5) return 'success';
    if ($change > 0) return 'primary';
    if ($change > -5) return 'warning';
    return 'danger';
}

function getTrendIcon($change) {
    if ($change > 0) return 'bi-arrow-up';
    if ($change < 0) return 'bi-arrow-down';
    return 'bi-dash';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supplier Performance - <?php echo htmlspecialchars($supplier['name']); ?> - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/suppliers.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }

        .performance-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border: 1px solid #e2e8f0;
            margin-bottom: 1.5rem;
        }

        .metric-card {
            text-align: center;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }

        .metric-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .metric-label {
            font-size: 0.875rem;
            color: #64748b;
            font-weight: 500;
        }

        .trend-indicator {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            font-size: 0.75rem;
            font-weight: 600;
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
        }

        .trend-positive { background: #d1fae5; color: #059669; }
        .trend-negative { background: #fee2e2; color: #dc2626; }
        .trend-neutral { background: #f3f4f6; color: #6b7280; }

        .performance-score {
            font-size: 3rem;
            font-weight: 800;
            text-align: center;
            margin: 1rem 0;
        }

        .rating-badge {
            font-size: 1rem;
            padding: 0.5rem 1rem;
            border-radius: 2rem;
        }

        .chart-container {
            position: relative;
            height: 300px;
            margin: 2rem 0;
        }

        .comparison-table th {
            background: #f8fafc;
            font-weight: 600;
            color: #374151;
        }

        .status-indicator {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 0.5rem;
        }

        .status-excellent { background: #28a745; }
        .status-good { background: #007bff; }
        .status-fair { background: #ffc107; }
        .status-poor { background: #fd7e14; }
        .status-critical { background: #dc3545; }

        @media (max-width: 768px) {
            .performance-score {
                font-size: 2rem;
            }

            .metric-value {
                font-size: 1.5rem;
            }

            .chart-container {
                height: 250px;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <?php
    $current_page = 'suppliers';
    include __DIR__ . '/../include/navmenu.php';
    ?>

    <div class="main-content">
        <!-- Header -->
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1>Supplier Performance</h1>
                    <div class="header-subtitle"><?php echo htmlspecialchars($supplier['name']); ?> - Performance Analytics</div>
                </div>
                <div class="header-actions">
                    <a href="suppliers.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i>
                        Back to Suppliers
                    </a>
                    <div class="user-info">
                        <div class="user-avatar"><?php echo strtoupper(substr($username, 0, 1)); ?></div>
                        <span><?php echo htmlspecialchars($username); ?></span>
                    </div>
                </div>
            </div>
        </header>

        <!-- Content -->
        <div class="content">
            <!-- Period Selector -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div class="btn-group" role="group">
                    <a href="?id=<?php echo $supplier_id; ?>&period=30days" class="btn btn-outline-primary <?php echo $period === '30days' ? 'active' : ''; ?>">30 Days</a>
                    <a href="?id=<?php echo $supplier_id; ?>&period=90days" class="btn btn-outline-primary <?php echo $period === '90days' ? 'active' : ''; ?>">90 Days</a>
                    <a href="?id=<?php echo $supplier_id; ?>&period=1year" class="btn btn-outline-primary <?php echo $period === '1year' ? 'active' : ''; ?>">1 Year</a>
                    <a href="?id=<?php echo $supplier_id; ?>&period=all" class="btn btn-outline-primary <?php echo $period === 'all' ? 'active' : ''; ?>">All Time</a>
                </div>
                <div class="text-muted">
                    Period: <?php echo ucfirst(str_replace(['30days', '90days', '1year'], ['30 Days', '90 Days', '1 Year'], $period)); ?>
                </div>
            </div>

            <!-- Overall Performance Score -->
            <div class="performance-card">
                <div class="row">
                    <div class="col-md-4">
                        <div class="performance-score text-<?php
                            if ($performance['overall_score'] >= 90) echo 'success';
                            elseif ($performance['overall_score'] >= 80) echo 'primary';
                            elseif ($performance['overall_score'] >= 70) echo 'warning';
                            else echo 'danger';
                        ?>">
                            <?php echo round($performance['overall_score'], 1); ?>/100
                        </div>
                        <div class="text-center">
                            <span class="badge rating-badge bg-<?php
                                if ($performance['overall_score'] >= 90) echo 'success';
                                elseif ($performance['overall_score'] >= 80) echo 'primary';
                                elseif ($performance['overall_score'] >= 70) echo 'warning';
                                else echo 'danger';
                            ?>">
                                <?php echo $performance['performance_rating']; ?>
                            </span>
                        </div>
                        <?php if (isset($trends['score_change'])): ?>
                            <div class="text-center mt-2">
                                <span class="trend-indicator trend-<?php
                                    if ($trends['score_change'] > 0) echo 'positive';
                                    elseif ($trends['score_change'] < 0) echo 'negative';
                                    else echo 'neutral';
                                ?>">
                                    <i class="bi <?php echo getTrendIcon($trends['score_change']); ?>"></i>
                                    <?php echo abs(round($trends['score_change'], 1)); ?> pts
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-8">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="metric-card border border-success">
                                    <div class="metric-value text-success">
                                        <?php echo round($performance['delivery_performance']['on_time_percentage'], 1); ?>%
                                    </div>
                                    <div class="metric-label">On-Time Delivery</div>
                                    <?php if (isset($trends['delivery_change'])): ?>
                                        <small class="trend-indicator trend-<?php
                                            if ($trends['delivery_change'] > 0) echo 'positive';
                                            elseif ($trends['delivery_change'] < 0) echo 'negative';
                                            else echo 'neutral';
                                        ?>">
                                            <i class="bi <?php echo getTrendIcon($trends['delivery_change']); ?>"></i>
                                            <?php echo abs(round($trends['delivery_change'], 1)); ?>%
                                        </small>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="metric-card border border-primary">
                                    <div class="metric-value text-primary">
                                        <?php echo round($performance['quality_metrics']['quality_score'], 1); ?>/100
                                    </div>
                                    <div class="metric-label">Quality Score</div>
                                    <?php if (isset($trends['quality_change'])): ?>
                                        <small class="trend-indicator trend-<?php
                                            if ($trends['quality_change'] > 0) echo 'positive';
                                            elseif ($trends['quality_change'] < 0) echo 'negative';
                                            else echo 'neutral';
                                        ?>">
                                            <i class="bi <?php echo getTrendIcon($trends['quality_change']); ?>"></i>
                                            <?php echo abs(round($trends['quality_change'], 1)); ?>
                                        </small>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="metric-card border border-info">
                                    <div class="metric-value text-info">
                                        <?php echo round($performance['delivery_performance']['average_delivery_days'], 1); ?>
                                    </div>
                                    <div class="metric-label">Avg Delivery Days</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Detailed Metrics -->
            <div class="row">
                <div class="col-md-6">
                    <div class="performance-card">
                        <h5 class="mb-3"><i class="bi bi-truck me-2"></i>Delivery Performance</h5>
                        <div class="row text-center">
                            <div class="col-6">
                                <div class="metric-value text-primary">
                                    <?php echo $performance['delivery_performance']['total_orders']; ?>
                                </div>
                                <div class="metric-label">Total Orders</div>
                            </div>
                            <div class="col-6">
                                <div class="metric-value text-success">
                                    <?php echo $performance['delivery_performance']['on_time_deliveries']; ?>
                                </div>
                                <div class="metric-label">On-Time Deliveries</div>
                            </div>
                        </div>
                        <div class="row text-center mt-3">
                            <div class="col-6">
                                <div class="metric-value text-danger">
                                    <?php echo $performance['delivery_performance']['late_deliveries']; ?>
                                </div>
                                <div class="metric-label">Late Deliveries</div>
                            </div>
                            <div class="col-6">
                                <div class="metric-value text-info">
                                    <?php echo round($performance['delivery_performance']['average_delivery_days'], 1); ?>
                                </div>
                                <div class="metric-label">Avg Days</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="performance-card">
                        <h5 class="mb-3"><i class="bi bi-shield-check me-2"></i>Quality Metrics</h5>
                        <div class="row text-center">
                            <div class="col-6">
                                <div class="metric-value text-primary">
                                    <?php echo $performance['quality_metrics']['total_returns']; ?>
                                </div>
                                <div class="metric-label">Total Returns</div>
                            </div>
                            <div class="col-6">
                                <div class="metric-value text-warning">
                                    <?php echo round($performance['quality_metrics']['return_rate'], 1); ?>%
                                </div>
                                <div class="metric-label">Return Rate</div>
                            </div>
                        </div>
                        <div class="row text-center mt-3">
                            <div class="col-6">
                                <div class="metric-value text-success">
                                    <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($performance['quality_metrics']['total_order_value'], 0); ?>
                                </div>
                                <div class="metric-label">Order Value</div>
                            </div>
                            <div class="col-6">
                                <div class="metric-value text-danger">
                                    <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($performance['quality_metrics']['total_return_value'], 0); ?>
                                </div>
                                <div class="metric-label">Return Value</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Cost Performance -->
            <div class="performance-card">
                <h5 class="mb-3"><i class="bi bi-cash-coin me-2"></i>Cost Performance</h5>
                <div class="row">
                    <div class="col-md-3 text-center">
                        <div class="metric-value text-primary">
                            <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($performance['cost_performance']['average_cost_per_unit'], 2); ?>
                        </div>
                        <div class="metric-label">Avg Cost/Unit</div>
                    </div>
                    <div class="col-md-3 text-center">
                        <div class="metric-value text-success">
                            <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($performance['cost_performance']['min_cost_per_unit'], 2); ?>
                        </div>
                        <div class="metric-label">Min Cost/Unit</div>
                    </div>
                    <div class="col-md-3 text-center">
                        <div class="metric-value text-warning">
                            <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($performance['cost_performance']['max_cost_per_unit'], 2); ?>
                        </div>
                        <div class="metric-label">Max Cost/Unit</div>
                    </div>
                    <div class="col-md-3 text-center">
                        <div class="metric-value text-info">
                            <?php echo $performance['cost_performance']['unique_products']; ?>
                        </div>
                        <div class="metric-label">Unique Products</div>
                    </div>
                </div>
            </div>

            <!-- Cost Comparison Chart -->
            <?php if (!empty($cost_comparison)): ?>
            <div class="performance-card">
                <h5 class="mb-3"><i class="bi bi-bar-chart me-2"></i>Cost Comparison by Category</h5>
                <div class="chart-container">
                    <canvas id="costComparisonChart"></canvas>
                </div>
            </div>
            <?php endif; ?>

            <!-- Recent Orders -->
            <div class="performance-card">
                <h5 class="mb-3"><i class="bi bi-clock-history me-2"></i>Recent Orders</h5>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Order #</th>
                                <th>Date</th>
                                <th>Items</th>
                                <th>Value</th>
                                <th>Expected</th>
                                <th>Received</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recent_orders)): ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">
                                    No orders found for this supplier
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($recent_orders as $order): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($order['order_number']); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($order['order_date'])); ?></td>
                                    <td><?php echo $order['item_count']; ?> items</td>
                                    <td><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($order['total_amount'], 2); ?></td>
                                    <td><?php echo $order['expected_date'] ? date('M d, Y', strtotime($order['expected_date'])) : 'Not set'; ?></td>
                                    <td><?php echo $order['received_date'] ? date('M d, Y', strtotime($order['received_date'])) : 'Pending'; ?></td>
                                    <td>
                                        <span class="badge bg-<?php
                                            if ($order['delivery_status'] === 'on_time') echo 'success';
                                            elseif ($order['delivery_status'] === 'late') echo 'danger';
                                            else echo 'warning';
                                        ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $order['delivery_status'])); ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Quality Issues -->
            <div class="performance-card">
                <h5 class="mb-3"><i class="bi bi-exclamation-triangle me-2"></i>Quality Issues</h5>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Severity</th>
                                <th>Description</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($quality_issues)): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">
                                    No quality issues reported
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($quality_issues as $issue): ?>
                                <tr>
                                    <td><?php echo date('M d, Y', strtotime($issue['reported_date'])); ?></td>
                                    <td><?php echo ucfirst($issue['issue_type']); ?></td>
                                    <td>
                                        <span class="badge bg-<?php
                                            if ($issue['severity'] === 'critical') echo 'danger';
                                            elseif ($issue['severity'] === 'high') echo 'warning';
                                            elseif ($issue['severity'] === 'medium') echo 'primary';
                                            else echo 'secondary';
                                        ?>">
                                            <?php echo ucfirst($issue['severity']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars(substr($issue['description'], 0, 50)) . (strlen($issue['description']) > 50 ? '...' : ''); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $issue['resolved'] ? 'success' : 'warning'; ?>">
                                            <?php echo $issue['resolved'] ? 'Resolved' : 'Open'; ?>
                                        </span>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Cost Comparison Chart
        <?php if (!empty($cost_comparison)): ?>
        const costComparisonData = <?php echo json_encode($cost_comparison); ?>;

        const ctx = document.getElementById('costComparisonChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: costComparisonData.map(item => item.category),
                datasets: [{
                    label: 'Supplier Cost',
                    data: costComparisonData.map(item => item.supplier_avg_cost),
                    backgroundColor: 'rgba(99, 102, 241, 0.8)',
                    borderColor: 'rgba(99, 102, 241, 1)',
                    borderWidth: 1
                }, {
                    label: 'Market Average',
                    data: costComparisonData.map(item => item.market_avg_cost),
                    backgroundColor: 'rgba(156, 163, 175, 0.8)',
                    borderColor: 'rgba(156, 163, 175, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '<?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> ' + value.toFixed(2);
                            }
                        }
                    }
                },
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> ' + context.parsed.y.toFixed(2);
                            }
                        }
                    }
                }
            }
        });
        <?php endif; ?>

        // Performance History Chart (if we have historical data)
        <?php if (!empty($performance_history)): ?>
        const performanceHistory = <?php echo json_encode(array_reverse($performance_history)); ?>;

        // You can add more charts here for performance trends
        <?php endif; ?>
    </script>
</body>
</html>
