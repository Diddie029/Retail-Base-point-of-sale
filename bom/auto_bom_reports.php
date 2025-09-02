<?php
session_start();
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';
require_once __DIR__ . '/../include/classes/AutoBOMManager.php';

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
$can_view_auto_bom_reports = hasPermission('view_auto_bom_reports', $permissions);

if (!$can_view_auto_bom_reports) {
    header("Location: ../dashboard/dashboard.php");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get filter parameters
$config_id = isset($_GET['config_id']) ? (int) $_GET['config_id'] : null;
$report_type = $_GET['report_type'] ?? 'overview';
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');

// Get Auto BOM configurations for filter
$auto_bom_configs = [];
$stmt = $conn->query("
    SELECT abc.id, abc.config_name, p.name as product_name, bp.name as base_product_name
    FROM auto_bom_configs abc
    INNER JOIN products p ON abc.product_id = p.id
    INNER JOIN products bp ON abc.base_product_id = bp.id
    WHERE abc.is_active = 1
    ORDER BY abc.config_name ASC
");
$auto_bom_configs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Initialize data arrays
$report_data = [];
$chart_data = [];

try {
    if ($config_id) {
        // Get specific Auto BOM configuration details
        $stmt = $conn->prepare("
            SELECT
                abc.*,
                p.name as product_name,
                p.sku as product_sku,
                bp.name as base_product_name,
                bp.sku as base_product_sku,
                bp.quantity as base_stock,
                pf.name as family_name,
                COUNT(su.id) as selling_units_count
            FROM auto_bom_configs abc
            INNER JOIN products p ON abc.product_id = p.id
            INNER JOIN products bp ON abc.base_product_id = bp.id
            LEFT JOIN product_families pf ON abc.product_family_id = pf.id
            LEFT JOIN auto_bom_selling_units su ON abc.id = su.auto_bom_config_id
            WHERE abc.id = :config_id
            GROUP BY abc.id
        ");
        $stmt->execute([':config_id' => $config_id]);
        $config_details = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($config_details) {
            // Get selling units performance
            $stmt = $conn->prepare("
                SELECT
                    su.*,
                    COUNT(DISTINCT at.id) as activity_count,
                    MAX(at.created_at) as last_activity_date
                FROM auto_bom_selling_units su
                LEFT JOIN activity_logs at ON at.details LIKE CONCAT('%\"selling_unit_id\":', su.id, '%')
                    AND at.action = 'auto_bom_sale'
                    AND DATE(at.created_at) BETWEEN :date_from AND :date_to
                WHERE su.auto_bom_config_id = :config_id
                GROUP BY su.id
                ORDER BY su.priority DESC, su.unit_name ASC
            ");
            $stmt->execute([
                ':config_id' => $config_id,
                ':date_from' => $date_from,
                ':date_to' => $date_to
            ]);
            $selling_units_performance = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get price history
            $stmt = $conn->prepare("
                SELECT
                    ph.*,
                    su.unit_name,
                    u.username as changed_by_name
                FROM auto_bom_price_history ph
                INNER JOIN auto_bom_selling_units su ON ph.selling_unit_id = su.id
                LEFT JOIN users u ON ph.changed_by = u.id
                WHERE su.auto_bom_config_id = :config_id
                AND DATE(ph.change_date) BETWEEN :date_from AND :date_to
                ORDER BY ph.change_date DESC
                LIMIT 50
            ");
            $stmt->execute([
                ':config_id' => $config_id,
                ':date_from' => $date_from,
                ':date_to' => $date_to
            ]);
            $price_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Calculate summary metrics
            $total_activities = array_sum(array_column($selling_units_performance, 'activity_count'));
            $total_sales = $total_activities; // Using activity count as proxy for sales
            $price_changes = count($price_history);

            $report_data = [
                'config_details' => $config_details,
                'selling_units_performance' => $selling_units_performance,
                'price_history' => $price_history,
                'summary' => [
                    'total_sales' => $total_sales,
                    'total_activities' => $total_activities,
                    'price_changes' => $price_changes,
                    'date_range' => [
                        'from' => $date_from,
                        'to' => $date_to
                    ]
                ]
            ];

            // Prepare chart data for JavaScript
            $chart_data = [
                'activities_by_unit' => array_map(function($unit) {
                    return [
                        'unit_name' => $unit['unit_name'],
                        'activity_count' => (int) $unit['activity_count']
                    ];
                }, $selling_units_performance),
                'sales_by_unit' => array_map(function($unit) {
                    return [
                        'unit_name' => $unit['unit_name'],
                        'activity_count' => (int) $unit['activity_count']
                    ];
                }, $selling_units_performance),
                'price_changes_over_time' => array_map(function($change) {
                    return [
                        'date' => $change['change_date'],
                        'unit_name' => $change['unit_name'],
                        'old_price' => (float) $change['old_price'],
                        'new_price' => (float) $change['new_price'],
                        'change_reason' => $change['change_reason']
                    ];
                }, array_reverse($price_history))
            ];
        }
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auto BOM Reports - <?php echo htmlspecialchars($settings['site_name'] ?? 'Point of Sale'); ?></title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/products.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .reports-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            margin: -20px -20px 20px -20px;
            border-radius: 8px;
        }

        .report-filters {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .report-metrics {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .metric-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
        }

        .metric-value {
            font-size: 2rem;
            font-weight: bold;
            color: #667eea;
        }

        .metric-label {
            color: #666;
            font-size: 0.9rem;
        }

        .report-section {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            overflow: hidden;
        }

        .report-section-header {
            background: #f8f9fa;
            padding: 15px 20px;
            border-bottom: 1px solid #dee2e6;
            font-weight: bold;
        }

        .report-section-content {
            padding: 20px;
        }

        .performance-table {
            width: 100%;
            border-collapse: collapse;
        }

        .performance-table th,
        .performance-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #dee2e6;
        }

        .performance-table th {
            background: #f8f9fa;
            font-weight: bold;
        }

        .chart-container {
            position: relative;
            height: 300px;
            margin-bottom: 20px;
        }

        .price-history-item {
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 15px;
            margin-bottom: 10px;
            background: #f8f9fa;
        }

        .price-change {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: bold;
        }

        .price-increase {
            background: #d4edda;
            color: #155724;
        }

        .price-decrease {
            background: #f8d7da;
            color: #721c24;
        }

        .price-neutral {
            background: #fff3cd;
            color: #856404;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include '../include/navmenu.php'; ?>

        <main class="main-content">
            <div class="reports-header">
                <h1><i class="fas fa-chart-bar"></i> Auto BOM Reports & Analytics</h1>
                <p>Comprehensive insights into your Auto BOM performance</p>
            </div>

            <!-- Filters -->
            <div class="report-filters">
                <form method="GET" class="row">
                    <div class="col-md-3">
                        <label for="config_id">Auto BOM Configuration</label>
                        <select id="config_id" name="config_id" class="form-control" required>
                            <option value="">Select Configuration</option>
                            <?php foreach ($auto_bom_configs as $config): ?>
                                <option value="<?php echo $config['id']; ?>"
                                        <?php echo $config_id == $config['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($config['config_name']); ?> -
                                    <?php echo htmlspecialchars($config['product_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="date_from">From Date</label>
                        <input type="date" id="date_from" name="date_from" class="form-control"
                               value="<?php echo $date_from; ?>">
                    </div>
                    <div class="col-md-2">
                        <label for="date_to">To Date</label>
                        <input type="date" id="date_to" name="date_to" class="form-control"
                               value="<?php echo $date_to; ?>">
                    </div>
                    <div class="col-md-2">
                        <label for="report_type">Report Type</label>
                        <select id="report_type" name="report_type" class="form-control">
                            <option value="overview" <?php echo $report_type === 'overview' ? 'selected' : ''; ?>>Overview</option>
                            <option value="performance" <?php echo $report_type === 'performance' ? 'selected' : ''; ?>>Performance</option>
                            <option value="pricing" <?php echo $report_type === 'pricing' ? 'selected' : ''; ?>>Pricing</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label>&nbsp;</label>
                        <div>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i> Generate Report
                            </button>
                            <a href="auto_bom_reports.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Clear
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <?php if (isset($error)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php elseif ($config_id && !empty($report_data)): ?>

                <!-- Summary Metrics -->
                <div class="report-metrics">
                    <div class="metric-card">
                        <div class="metric-value"><?php echo number_format($report_data['summary']['total_activities']); ?></div>
                        <div class="metric-label">Total Activities</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value"><?php echo number_format($report_data['summary']['total_sales']); ?></div>
                        <div class="metric-label">Total Sales</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value"><?php echo number_format($report_data['summary']['price_changes']); ?></div>
                        <div class="metric-label">Price Changes</div>
                    </div>
                </div>

                <!-- Configuration Details -->
                <div class="report-section">
                    <div class="report-section-header">
                        <i class="fas fa-info-circle"></i> Configuration Details
                    </div>
                    <div class="report-section-content">
                        <div class="row">
                            <div class="col-md-6">
                                <strong>Name:</strong> <?php echo htmlspecialchars($report_data['config_details']['config_name']); ?><br>
                                <strong>Product:</strong> <?php echo htmlspecialchars($report_data['config_details']['product_name']); ?><br>
                                <strong>Base Product:</strong> <?php echo htmlspecialchars($report_data['config_details']['base_product_name']); ?><br>
                                <strong>Base Unit:</strong> <?php echo htmlspecialchars($report_data['config_details']['base_unit']); ?> (<?php echo $report_data['config_details']['base_quantity']; ?>)
                            </div>
                            <div class="col-md-6">
                                <strong>Selling Units:</strong> <?php echo $report_data['config_details']['selling_units_count']; ?><br>
                                <strong>Base Stock:</strong> <?php echo $report_data['config_details']['base_stock']; ?><br>
                                <strong>Status:</strong>
                                <span class="badge badge-<?php echo $report_data['config_details']['is_active'] ? 'success' : 'secondary'; ?>">
                                    <?php echo $report_data['config_details']['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span><br>
                                <strong>Created:</strong> <?php echo date('M j, Y H:i', strtotime($report_data['config_details']['created_at'])); ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Performance Charts -->
                <div class="report-section">
                    <div class="report-section-header">
                        <i class="fas fa-chart-line"></i> Performance Analytics
                    </div>
                    <div class="report-section-content">
                        <div class="row">
                            <div class="col-md-6">
                                <h5>Revenue by Selling Unit</h5>
                                <div class="chart-container">
                                    <canvas id="revenueChart"></canvas>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <h5>Sales Count by Selling Unit</h5>
                                <div class="chart-container">
                                    <canvas id="salesChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Selling Units Performance Table -->
                <div class="report-section">
                    <div class="report-section-header">
                        <i class="fas fa-table"></i> Selling Units Performance
                    </div>
                    <div class="report-section-content">
                        <table class="performance-table">
                            <thead>
                                <tr>
                                    <th>Unit Name</th>
                                    <th>Strategy</th>
                                    <th>Activity Count</th>
                                    <th>Last Activity</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($report_data['selling_units_performance'] as $unit): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($unit['unit_name']); ?></td>
                                        <td><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $unit['pricing_strategy']))); ?></td>
                                        <td><?php echo number_format($unit['activity_count']); ?></td>
                                        <td><?php echo $unit['last_activity_date'] ? date('M j, Y', strtotime($unit['last_activity_date'])) : 'Never'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Price History -->
                <?php if (!empty($report_data['price_history'])): ?>
                    <div class="report-section">
                        <div class="report-section-header">
                            <i class="fas fa-history"></i> Price Change History
                        </div>
                        <div class="report-section-content">
                            <?php foreach ($report_data['price_history'] as $change): ?>
                                <div class="price-history-item">
                                    <div class="row">
                                        <div class="col-md-3">
                                            <strong><?php echo htmlspecialchars($change['unit_name']); ?></strong>
                                        </div>
                                        <div class="col-md-2">
                                            <?php echo date('M j, Y H:i', strtotime($change['change_date'])); ?>
                                        </div>
                                        <div class="col-md-2">
                                            <span class="price-change price-decrease">
                                                Old: <?php echo formatCurrency($change['old_price']); ?>
                                            </span>
                                        </div>
                                        <div class="col-md-2">
                                            <span class="price-change price-increase">
                                                New: <?php echo formatCurrency($change['new_price']); ?>
                                            </span>
                                        </div>
                                        <div class="col-md-2">
                                            <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $change['change_reason']))); ?>
                                        </div>
                                        <div class="col-md-1">
                                            <?php echo htmlspecialchars($change['changed_by_name'] ?? 'System'); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

            <?php elseif ($config_id): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> No data found for the selected configuration and date range.
                </div>
            <?php else: ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i> Please select an Auto BOM configuration to view reports.
                </div>
            <?php endif; ?>
        </main>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="../assets/js/dashboard.js"></script>

    <?php if (!empty($chart_data)): ?>
    <script>
        // Revenue Chart
        const revenueCtx = document.getElementById('revenueChart').getContext('2d');
        new Chart(revenueCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_column($chart_data['revenue_by_unit'], 'unit_name')); ?>,
                datasets: [{
                    label: 'Revenue',
                    data: <?php echo json_encode(array_column($chart_data['revenue_by_unit'], 'revenue')); ?>,
                    backgroundColor: 'rgba(102, 126, 234, 0.6)',
                    borderColor: 'rgba(102, 126, 234, 1)',
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
                                return '<?php echo $settings['currency_symbol'] ?? 'KES'; ?> ' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });

        // Sales Chart
        const salesCtx = document.getElementById('salesChart').getContext('2d');
        new Chart(salesCtx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode(array_column($chart_data['sales_by_unit'], 'unit_name')); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_column($chart_data['sales_by_unit'], 'activity_count')); ?>,
                    backgroundColor: [
                        'rgba(102, 126, 234, 0.6)',
                        'rgba(118, 75, 162, 0.6)',
                        'rgba(28, 133, 145, 0.6)',
                        'rgba(40, 167, 69, 0.6)',
                        'rgba(255, 193, 7, 0.6)',
                        'rgba(220, 53, 69, 0.6)'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });
    </script>
    <?php endif; ?>
</body>
</html>
