<?php
session_start();
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';
require_once __DIR__ . '/../include/auto_bom_pricing_migration.php';

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

// Check Auto BOM permissions
$can_manage_auto_boms = hasPermission('manage_auto_boms', $permissions);
$can_view_auto_boms = hasPermission('view_auto_boms', $permissions);

if (!$can_manage_auto_boms && !$can_view_auto_boms) {
    header("Location: ../dashboard/dashboard.php");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Ensure pricing tables exist
try {
    if (needsPricingMigration($conn)) {
        createAutoBOMPricingTables($conn);
    }
} catch (Exception $e) {
    $error = "Database migration error: " . $e->getMessage();
}

// Get date range for analytics
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$config_filter = $_GET['config_id'] ?? '';

// Build analytics queries
$date_filter = "DATE(created_at) BETWEEN :start_date AND :end_date";
$params = [':start_date' => $start_date, ':end_date' => $end_date];

$config_join = '';
$config_where = '';
if (!empty($config_filter)) {
    $config_join = "INNER JOIN auto_bom_selling_units su ON ph.selling_unit_id = su.id";
    $config_where = "AND su.auto_bom_config_id = :config_id";
    $params[':config_id'] = $config_filter;
}

// Get price change analytics
$price_changes = [];
try {
    $stmt = $conn->prepare("
        SELECT
            DATE(ph.created_at) as change_date,
            COUNT(*) as changes_count,
            AVG(ph.new_price - ph.old_price) as avg_change_amount,
            AVG((ph.new_price - ph.old_price) / ph.old_price * 100) as avg_change_percentage,
            SUM(CASE WHEN ph.new_price > ph.old_price THEN 1 ELSE 0 END) as increases,
            SUM(CASE WHEN ph.new_price < ph.old_price THEN 1 ELSE 0 END) as decreases
        FROM auto_bom_price_history ph
        {$config_join}
        WHERE {$date_filter} {$config_where}
        GROUP BY DATE(ph.created_at)
        ORDER BY change_date DESC
        LIMIT 30
    ");
    
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $price_changes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Price history table might not exist yet
    $price_changes = [];
}

// Get strategy performance
$strategy_performance = [];
try {
    $stmt = $conn->prepare("
        SELECT
            su.pricing_strategy,
            COUNT(DISTINCT su.id) as unit_count,
            AVG(su.fixed_price) as avg_price,
            AVG(
                CASE 
                    WHEN bp.cost_price > 0 THEN 
                        ((su.fixed_price - (bp.cost_price / abc.base_quantity * su.unit_quantity)) / (bp.cost_price / abc.base_quantity * su.unit_quantity)) * 100
                    ELSE 0
                END
            ) as avg_margin_percentage,
            COUNT(ph.id) as price_changes_count
        FROM auto_bom_selling_units su
        INNER JOIN auto_bom_configs abc ON su.auto_bom_config_id = abc.id
        INNER JOIN products bp ON abc.base_product_id = bp.id
        LEFT JOIN auto_bom_price_history ph ON su.id = ph.selling_unit_id 
            AND DATE(ph.created_at) BETWEEN :start_date AND :end_date
        WHERE su.status = 'active'
        " . (!empty($config_filter) ? "AND abc.id = :config_id" : "") . "
        GROUP BY su.pricing_strategy
        ORDER BY avg_margin_percentage DESC
    ");
    
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $strategy_performance = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $strategy_performance = [];
}

// Get top performing units
$top_units = [];
try {
    $stmt = $conn->prepare("
        SELECT
            su.id,
            su.unit_name,
            su.fixed_price,
            su.pricing_strategy,
            abc.config_name,
            p.name as product_name,
            bp.cost_price,
            abc.base_quantity,
            su.unit_quantity,
            ((su.fixed_price - (bp.cost_price / abc.base_quantity * su.unit_quantity)) / (bp.cost_price / abc.base_quantity * su.unit_quantity)) * 100 as margin_percentage,
            COUNT(ph.id) as price_changes
        FROM auto_bom_selling_units su
        INNER JOIN auto_bom_configs abc ON su.auto_bom_config_id = abc.id
        INNER JOIN products p ON abc.product_id = p.id
        INNER JOIN products bp ON abc.base_product_id = bp.id
        LEFT JOIN auto_bom_price_history ph ON su.id = ph.selling_unit_id 
            AND DATE(ph.created_at) BETWEEN :start_date AND :end_date
        WHERE su.status = 'active'
        " . (!empty($config_filter) ? "AND abc.id = :config_id" : "") . "
        GROUP BY su.id
        HAVING margin_percentage > 0
        ORDER BY margin_percentage DESC
        LIMIT 10
    ");
    
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $top_units = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $top_units = [];
}

// Get recent alerts
$recent_alerts = [];
try {
    $stmt = $conn->prepare("
        SELECT
            pa.*,
            su.unit_name,
            abc.config_name,
            p.name as product_name
        FROM auto_bom_pricing_alerts pa
        INNER JOIN auto_bom_selling_units su ON pa.selling_unit_id = su.id
        INNER JOIN auto_bom_configs abc ON su.auto_bom_config_id = abc.id
        INNER JOIN products p ON abc.product_id = p.id
        WHERE pa.is_resolved = FALSE
        " . (!empty($config_filter) ? "AND abc.id = :config_id" : "") . "
        ORDER BY pa.created_at DESC
        LIMIT 20
    ");
    
    if (!empty($config_filter)) {
        $stmt->bindValue(':config_id', $config_filter);
    }
    $stmt->execute();
    $recent_alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recent_alerts = [];
}

// Get Auto BOM configurations for filter
$configs = [];
$stmt = $conn->query("
    SELECT abc.id, abc.config_name, p.name as product_name
    FROM auto_bom_configs abc
    INNER JOIN products p ON abc.product_id = p.id
    WHERE abc.is_active = 1
    ORDER BY abc.config_name ASC
");
$configs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate summary stats
$summary_stats = [
    'total_units' => 0,
    'avg_margin' => 0,
    'price_changes_today' => 0,
    'active_alerts' => count($recent_alerts)
];

foreach ($strategy_performance as $strategy) {
    $summary_stats['total_units'] += $strategy['unit_count'];
    $summary_stats['avg_margin'] += $strategy['avg_margin_percentage'] * $strategy['unit_count'];
}

if ($summary_stats['total_units'] > 0) {
    $summary_stats['avg_margin'] = $summary_stats['avg_margin'] / $summary_stats['total_units'];
}

// Count today's price changes
foreach ($price_changes as $change) {
    if ($change['change_date'] === date('Y-m-d')) {
        $summary_stats['price_changes_today'] = $change['changes_count'];
        break;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auto BOM Pricing Analytics - <?php echo htmlspecialchars($settings['site_name'] ?? 'Point of Sale'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .analytics-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            margin: -20px -20px 20px -20px;
            border-radius: 8px;
        }

        .analytics-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
            border-left: 4px solid #667eea;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: bold;
            color: #667eea;
            margin-bottom: 5px;
        }

        .stat-label {
            color: #666;
            font-size: 0.9rem;
        }

        .chart-container {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 20px;
            margin-bottom: 20px;
        }

        .chart-title {
            font-size: 1.2rem;
            font-weight: bold;
            margin-bottom: 20px;
            color: #333;
        }

        .filters-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .alert-item {
            border-left: 4px solid #dc3545;
            background: #fff5f5;
            padding: 15px;
            margin-bottom: 10px;
            border-radius: 4px;
        }

        .alert-margin-low { border-left-color: #ffc107; background: #fffdf0; }
        .alert-price-spike { border-left-color: #dc3545; background: #fff5f5; }
        .alert-cost-increase { border-left-color: #fd7e14; background: #fff8f0; }
        .alert-strategy-failure { border-left-color: #6f42c1; background: #f8f5ff; }

        .performance-table {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .strategy-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: bold;
            text-transform: uppercase;
        }

        .strategy-fixed { background: #e3f2fd; color: #1565c0; }
        .strategy-cost-based { background: #f3e5f5; color: #7b1fa2; }
        .strategy-market-based { background: #e8f5e8; color: #2e7d32; }
        .strategy-dynamic { background: #fff3e0; color: #f57c00; }
        .strategy-hybrid { background: #fce4ec; color: #c2185b; }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include '../include/navmenu.php'; ?>

        <main class="main-content">
            <div class="analytics-header">
                <h1><i class="fas fa-chart-line"></i> Auto BOM Pricing Analytics</h1>
                <p>Comprehensive pricing performance analysis and insights</p>
            </div>

            <?php if (isset($error)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <!-- Filter Section -->
            <div class="filters-section">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Start Date</label>
                        <input type="date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($start_date); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">End Date</label>
                        <input type="date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($end_date); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Configuration</label>
                        <select name="config_id" class="form-select">
                            <option value="">All Configurations</option>
                            <?php foreach ($configs as $config): ?>
                                <option value="<?php echo $config['id']; ?>" <?php echo $config_filter == $config['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($config['config_name'] . ' - ' . $config['product_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-filter"></i> Filter
                        </button>
                    </div>
                </form>
            </div>

            <!-- Summary Statistics -->
            <div class="analytics-stats">
                <div class="stat-card">
                    <div class="stat-value"><?php echo $summary_stats['total_units']; ?></div>
                    <div class="stat-label">Total Units</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo number_format($summary_stats['avg_margin'], 1); ?>%</div>
                    <div class="stat-label">Average Margin</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $summary_stats['price_changes_today']; ?></div>
                    <div class="stat-label">Price Changes Today</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $summary_stats['active_alerts']; ?></div>
                    <div class="stat-label">Active Alerts</div>
                </div>
            </div>

            <!-- Charts Row -->
            <div class="row">
                <div class="col-md-8">
                    <div class="chart-container">
                        <div class="chart-title">Price Changes Over Time</div>
                        <canvas id="priceChangesChart"></canvas>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="chart-container">
                        <div class="chart-title">Strategy Performance</div>
                        <canvas id="strategyChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Performance Tables Row -->
            <div class="row">
                <div class="col-md-6">
                    <div class="performance-table">
                        <div class="card-header bg-light">
                            <h4><i class="fas fa-trophy"></i> Top Performing Units</h4>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-striped mb-0">
                                <thead>
                                    <tr>
                                        <th>Unit</th>
                                        <th>Strategy</th>
                                        <th>Margin</th>
                                        <th>Price</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($top_units)): ?>
                                        <tr>
                                            <td colspan="4" class="text-center text-muted py-3">No data available</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($top_units as $unit): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($unit['unit_name']); ?></strong><br>
                                                    <small class="text-muted"><?php echo htmlspecialchars($unit['config_name']); ?></small>
                                                </td>
                                                <td>
                                                    <span class="strategy-badge strategy-<?php echo str_replace('_', '-', $unit['pricing_strategy']); ?>">
                                                        <?php echo str_replace('_', ' ', $unit['pricing_strategy']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-success"><?php echo number_format($unit['margin_percentage'], 1); ?>%</span>
                                                </td>
                                                <td>
                                                    <?php echo formatCurrency($unit['fixed_price'], $settings); ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="performance-table">
                        <div class="card-header bg-light">
                            <h4><i class="fas fa-exclamation-triangle"></i> Recent Alerts</h4>
                        </div>
                        <div style="max-height: 400px; overflow-y: auto;">
                            <?php if (empty($recent_alerts)): ?>
                                <p class="text-center text-muted py-3">No active alerts</p>
                            <?php else: ?>
                                <?php foreach ($recent_alerts as $alert): ?>
                                    <div class="alert-item alert-<?php echo str_replace('_', '-', $alert['alert_type']); ?>">
                                        <div class="d-flex justify-content-between">
                                            <strong><?php echo htmlspecialchars($alert['unit_name']); ?></strong>
                                            <small><?php echo date('M j, g:i A', strtotime($alert['created_at'])); ?></small>
                                        </div>
                                        <div><?php echo htmlspecialchars($alert['alert_message']); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($alert['config_name']); ?></small>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Strategy Performance Table -->
            <div class="performance-table mt-4">
                <div class="card-header bg-light">
                    <h4><i class="fas fa-cog"></i> Strategy Performance Summary</h4>
                </div>
                <div class="table-responsive">
                    <table class="table table-striped mb-0">
                        <thead>
                            <tr>
                                <th>Strategy</th>
                                <th>Units</th>
                                <th>Avg Price</th>
                                <th>Avg Margin</th>
                                <th>Price Changes</th>
                                <th>Performance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($strategy_performance)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-3">No strategy data available</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($strategy_performance as $strategy): ?>
                                    <tr>
                                        <td>
                                            <span class="strategy-badge strategy-<?php echo str_replace('_', '-', $strategy['pricing_strategy']); ?>">
                                                <?php echo str_replace('_', ' ', $strategy['pricing_strategy']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo $strategy['unit_count']; ?></td>
                                        <td><?php echo formatCurrency($strategy['avg_price'], $settings); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $strategy['avg_margin_percentage'] > 20 ? 'success' : ($strategy['avg_margin_percentage'] > 10 ? 'warning' : 'danger'); ?>">
                                                <?php echo number_format($strategy['avg_margin_percentage'], 1); ?>%
                                            </span>
                                        </td>
                                        <td><?php echo $strategy['price_changes_count']; ?></td>
                                        <td>
                                            <?php
                                            $score = 'Good';
                                            $color = 'success';
                                            if ($strategy['avg_margin_percentage'] < 10) {
                                                $score = 'Poor';
                                                $color = 'danger';
                                            } elseif ($strategy['avg_margin_percentage'] < 20) {
                                                $score = 'Fair';
                                                $color = 'warning';
                                            }
                                            ?>
                                            <span class="badge bg-<?php echo $color; ?>"><?php echo $score; ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/dashboard.js"></script>

    <script>
        // Price Changes Chart
        const priceChangesData = <?php echo json_encode(array_reverse($price_changes)); ?>;
        const priceChangesCtx = document.getElementById('priceChangesChart').getContext('2d');
        new Chart(priceChangesCtx, {
            type: 'line',
            data: {
                labels: priceChangesData.map(item => new Date(item.change_date).toLocaleDateString()),
                datasets: [{
                    label: 'Price Increases',
                    data: priceChangesData.map(item => item.increases),
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                    tension: 0.4
                }, {
                    label: 'Price Decreases',
                    data: priceChangesData.map(item => item.decreases),
                    borderColor: '#dc3545',
                    backgroundColor: 'rgba(220, 53, 69, 0.1)',
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        }
                    }
                },
                plugins: {
                    legend: {
                        position: 'top'
                    }
                }
            }
        });

        // Strategy Performance Chart
        const strategyData = <?php echo json_encode($strategy_performance); ?>;
        const strategyCtx = document.getElementById('strategyChart').getContext('2d');
        new Chart(strategyCtx, {
            type: 'doughnut',
            data: {
                labels: strategyData.map(item => item.pricing_strategy.replace('_', ' ')),
                datasets: [{
                    data: strategyData.map(item => item.unit_count),
                    backgroundColor: [
                        '#667eea',
                        '#764ba2',
                        '#f093fb',
                        '#f5576c',
                        '#4facfe',
                        '#00f2fe'
                    ]
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });

        // Auto-refresh data every 5 minutes
        setInterval(function() {
            location.reload();
        }, 300000);
    </script>
</body>
</html>
