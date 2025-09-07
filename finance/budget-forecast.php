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

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get budget settings
$budget_settings = [];
try {
    $stmt = $conn->query("SELECT setting_key, setting_value FROM budget_settings");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $budget_settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    $budget_settings = [
        'budget_alert_threshold_warning' => '75',
        'budget_alert_threshold_critical' => '90',
        'default_currency' => 'KES'
    ];
}

// Handle forecast generation
$forecast_data = [];
$forecast_period = '12'; // Default 12 months
$forecast_method = 'linear'; // Default method
$selected_budget = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'generate_forecast') {
        $forecast_period = $_POST['forecast_period'] ?? '12';
        $forecast_method = $_POST['forecast_method'] ?? 'linear';
        $selected_budget = $_POST['budget_id'] ?? null;
        
        try {
            $forecast_data = generateBudgetForecast($conn, $selected_budget, $forecast_period, $forecast_method);
        } catch (Exception $e) {
            $error_message = "Error generating forecast: " . $e->getMessage();
        }
    }
}

// Get available budgets for forecasting
$available_budgets = [];
try {
    $stmt = $conn->query("
        SELECT id, name, budget_type, start_date, end_date, total_budget_amount, total_actual_amount
        FROM budgets 
        WHERE status = 'active' 
        ORDER BY created_at DESC
    ");
    $available_budgets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Handle error
}

// Get historical data for forecasting
$historical_data = [];
if ($selected_budget) {
    try {
        $stmt = $conn->prepare("
            SELECT 
                DATE_FORMAT(bt.created_at, '%Y-%m') as month,
                SUM(CASE WHEN bt.transaction_type = 'expense' THEN bt.amount ELSE 0 END) as expenses,
                SUM(CASE WHEN bt.transaction_type = 'revenue' THEN bt.amount ELSE 0 END) as revenue,
                COUNT(*) as transaction_count
            FROM budget_transactions bt
            WHERE bt.budget_id = ? 
            AND bt.created_at >= DATE_SUB(NOW(), INTERVAL 24 MONTH)
            GROUP BY DATE_FORMAT(bt.created_at, '%Y-%m')
            ORDER BY month
        ");
        $stmt->execute([$selected_budget]);
        $historical_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Handle error
    }
}

// Function to generate budget forecast
function generateBudgetForecast($conn, $budget_id, $periods, $method) {
    $forecast = [];
    
    if (!$budget_id) {
        // Generate overall forecast
        $stmt = $conn->query("
            SELECT 
                DATE_FORMAT(bt.created_at, '%Y-%m') as month,
                SUM(CASE WHEN bt.transaction_type = 'expense' THEN bt.amount ELSE 0 END) as expenses,
                SUM(CASE WHEN bt.transaction_type = 'revenue' THEN bt.amount ELSE 0 END) as revenue
            FROM budget_transactions bt
            WHERE bt.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
            GROUP BY DATE_FORMAT(bt.created_at, '%Y-%m')
            ORDER BY month
        ");
        $historical = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Generate forecast for specific budget
        $stmt = $conn->prepare("
            SELECT 
                DATE_FORMAT(bt.created_at, '%Y-%m') as month,
                SUM(CASE WHEN bt.transaction_type = 'expense' THEN bt.amount ELSE 0 END) as expenses,
                SUM(CASE WHEN bt.transaction_type = 'revenue' THEN bt.amount ELSE 0 END) as revenue
            FROM budget_transactions bt
            WHERE bt.budget_id = ? 
            AND bt.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
            GROUP BY DATE_FORMAT(bt.created_at, '%Y-%m')
            ORDER BY month
        ");
        $stmt->execute([$budget_id]);
        $historical = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    if (empty($historical)) {
        return $forecast;
    }
    
    // Calculate forecast based on method
    switch ($method) {
        case 'linear':
            $forecast = calculateLinearForecast($historical, $periods);
            break;
        case 'moving_average':
            $forecast = calculateMovingAverageForecast($historical, $periods);
            break;
        case 'exponential':
            $forecast = calculateExponentialForecast($historical, $periods);
            break;
        case 'seasonal':
            $forecast = calculateSeasonalForecast($historical, $periods);
            break;
    }
    
    return $forecast;
}

// Linear regression forecast
function calculateLinearForecast($historical, $periods) {
    $n = count($historical);
    if ($n < 2) return [];
    
    $x_sum = 0;
    $y_sum = 0;
    $xy_sum = 0;
    $x2_sum = 0;
    
    foreach ($historical as $i => $data) {
        $x = $i + 1;
        $y = $data['expenses'];
        $x_sum += $x;
        $y_sum += $y;
        $xy_sum += $x * $y;
        $x2_sum += $x * $x;
    }
    
    $slope = ($n * $xy_sum - $x_sum * $y_sum) / ($n * $x2_sum - $x_sum * $x_sum);
    $intercept = ($y_sum - $slope * $x_sum) / $n;
    
    $forecast = [];
    for ($i = 1; $i <= $periods; $i++) {
        $x = $n + $i;
        $predicted = $slope * $x + $intercept;
        $forecast[] = [
            'period' => $i,
            'month' => date('Y-m', strtotime("+$i months")),
            'predicted_expenses' => max(0, $predicted),
            'confidence' => max(0, min(100, 100 - ($i * 5))) // Decreasing confidence
        ];
    }
    
    return $forecast;
}

// Moving average forecast
function calculateMovingAverageForecast($historical, $periods) {
    $n = count($historical);
    if ($n < 3) return [];
    
    $window = min(3, $n);
    $recent_data = array_slice($historical, -$window);
    $avg_expenses = array_sum(array_column($recent_data, 'expenses')) / $window;
    
    $forecast = [];
    for ($i = 1; $i <= $periods; $i++) {
        $forecast[] = [
            'period' => $i,
            'month' => date('Y-m', strtotime("+$i months")),
            'predicted_expenses' => $avg_expenses,
            'confidence' => max(0, min(100, 100 - ($i * 3)))
        ];
    }
    
    return $forecast;
}

// Exponential smoothing forecast
function calculateExponentialForecast($historical, $periods) {
    $n = count($historical);
    if ($n < 2) return [];
    
    $alpha = 0.3; // Smoothing factor
    $forecast = [];
    
    // Initialize with first value
    $last_forecast = $historical[0]['expenses'];
    
    for ($i = 1; $i <= $periods; $i++) {
        $forecast[] = [
            'period' => $i,
            'month' => date('Y-m', strtotime("+$i months")),
            'predicted_expenses' => $last_forecast,
            'confidence' => max(0, min(100, 100 - ($i * 4)))
        ];
    }
    
    return $forecast;
}

// Seasonal forecast
function calculateSeasonalForecast($historical, $periods) {
    $n = count($historical);
    if ($n < 12) return calculateLinearForecast($historical, $periods);
    
    // Calculate seasonal indices
    $monthly_totals = [];
    $monthly_counts = [];
    
    foreach ($historical as $data) {
        $month = date('n', strtotime($data['month'] . '-01'));
        if (!isset($monthly_totals[$month])) {
            $monthly_totals[$month] = 0;
            $monthly_counts[$month] = 0;
        }
        $monthly_totals[$month] += $data['expenses'];
        $monthly_counts[$month]++;
    }
    
    $monthly_averages = [];
    $overall_avg = array_sum($monthly_totals) / array_sum($monthly_counts);
    
    for ($i = 1; $i <= 12; $i++) {
        $monthly_averages[$i] = isset($monthly_totals[$i]) ? 
            $monthly_totals[$i] / $monthly_counts[$i] : $overall_avg;
    }
    
    $seasonal_indices = [];
    for ($i = 1; $i <= 12; $i++) {
        $seasonal_indices[$i] = $monthly_averages[$i] / $overall_avg;
    }
    
    $forecast = [];
    for ($i = 1; $i <= $periods; $i++) {
        $month = date('n', strtotime("+$i months"));
        $predicted = $overall_avg * $seasonal_indices[$month];
        
        $forecast[] = [
            'period' => $i,
            'month' => date('Y-m', strtotime("+$i months")),
            'predicted_expenses' => max(0, $predicted),
            'confidence' => max(0, min(100, 100 - ($i * 2)))
        ];
    }
    
    return $forecast;
}

// Get budget performance metrics
$performance_metrics = [];
try {
    $stmt = $conn->query("
        SELECT 
            b.id,
            b.name,
            b.total_budget_amount,
            b.total_actual_amount,
            (b.total_actual_amount / b.total_budget_amount * 100) as utilization_percentage,
            (b.total_budget_amount - b.total_actual_amount) as remaining_amount,
            b.start_date,
            b.end_date,
            DATEDIFF(b.end_date, CURDATE()) as days_remaining
        FROM budgets b
        WHERE b.status = 'active'
        ORDER BY utilization_percentage DESC
    ");
    $performance_metrics = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Handle error
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Budget Forecast - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <?php include '../include/navmenu.php'; ?>

    <div class="main-content">
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="index.php">Finance Dashboard</a></li>
                            <li class="breadcrumb-item"><a href="budget.php">Budget Management</a></li>
                            <li class="breadcrumb-item active">Budget Forecast</li>
                        </ol>
                    </nav>
                    <h1><i class="bi bi-graph-up-arrow"></i> Budget Forecast</h1>
                    <p class="header-subtitle">Predict future budget performance and plan accordingly</p>
                </div>
            </div>
        </header>

        <main class="content">
            <div class="container-fluid">
                <?php if (isset($error_message)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i><?php echo $error_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <!-- Forecast Controls -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="bi bi-sliders me-2"></i>Forecast Settings</h6>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="">
                                    <input type="hidden" name="action" value="generate_forecast">
                                    <div class="row">
                                        <div class="col-md-3 mb-3">
                                            <label for="budget_id" class="form-label">Select Budget</label>
                                            <select class="form-select" id="budget_id" name="budget_id">
                                                <option value="">All Budgets (Overall Forecast)</option>
                                                <?php foreach ($available_budgets as $budget): ?>
                                                <option value="<?php echo $budget['id']; ?>" <?php echo $selected_budget == $budget['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($budget['name']); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-3 mb-3">
                                            <label for="forecast_period" class="form-label">Forecast Period</label>
                                            <select class="form-select" id="forecast_period" name="forecast_period">
                                                <option value="6" <?php echo $forecast_period == '6' ? 'selected' : ''; ?>>6 Months</option>
                                                <option value="12" <?php echo $forecast_period == '12' ? 'selected' : ''; ?>>12 Months</option>
                                                <option value="18" <?php echo $forecast_period == '18' ? 'selected' : ''; ?>>18 Months</option>
                                                <option value="24" <?php echo $forecast_period == '24' ? 'selected' : ''; ?>>24 Months</option>
                                            </select>
                                        </div>
                                        <div class="col-md-3 mb-3">
                                            <label for="forecast_method" class="form-label">Forecast Method</label>
                                            <select class="form-select" id="forecast_method" name="forecast_method">
                                                <option value="linear" <?php echo $forecast_method == 'linear' ? 'selected' : ''; ?>>Linear Regression</option>
                                                <option value="moving_average" <?php echo $forecast_method == 'moving_average' ? 'selected' : ''; ?>>Moving Average</option>
                                                <option value="exponential" <?php echo $forecast_method == 'exponential' ? 'selected' : ''; ?>>Exponential Smoothing</option>
                                                <option value="seasonal" <?php echo $forecast_method == 'seasonal' ? 'selected' : ''; ?>>Seasonal Analysis</option>
                                            </select>
                                        </div>
                                        <div class="col-md-3 mb-3">
                                            <label class="form-label">&nbsp;</label>
                                            <div class="d-grid">
                                                <button type="submit" class="btn btn-primary">
                                                    <i class="bi bi-calculator me-1"></i> Generate Forecast
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (!empty($forecast_data)): ?>
                <!-- Forecast Results -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="mb-0">
                                    <i class="bi bi-graph-up me-2"></i>Forecast Results
                                    <span class="badge bg-primary ms-2"><?php echo ucfirst(str_replace('_', ' ', $forecast_method)); ?></span>
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-8">
                                        <canvas id="forecastChart" height="100"></canvas>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="forecast-summary">
                                            <h6>Forecast Summary</h6>
                                            <?php 
                                            $total_predicted = array_sum(array_column($forecast_data, 'predicted_expenses'));
                                            $avg_confidence = array_sum(array_column($forecast_data, 'confidence')) / count($forecast_data);
                                            ?>
                                            <div class="mb-3">
                                                <div class="d-flex justify-content-between">
                                                    <span>Total Predicted Expenses:</span>
                                                    <strong><?php echo htmlspecialchars($budget_settings['default_currency'] ?? 'KES'); ?> <?php echo number_format($total_predicted, 2); ?></strong>
                                                </div>
                                                <div class="d-flex justify-content-between">
                                                    <span>Average Confidence:</span>
                                                    <strong><?php echo number_format($avg_confidence, 1); ?>%</strong>
                                                </div>
                                                <div class="d-flex justify-content-between">
                                                    <span>Forecast Period:</span>
                                                    <strong><?php echo $forecast_period; ?> months</strong>
                                                </div>
                                            </div>
                                            
                                            <div class="alert alert-info">
                                                <small>
                                                    <i class="bi bi-info-circle me-1"></i>
                                                    <strong>Note:</strong> Forecasts are based on historical data and should be used as guidance only. 
                                                    Actual results may vary based on market conditions and business changes.
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Forecast Table -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="bi bi-table me-2"></i>Detailed Forecast</h6>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Period</th>
                                                <th>Month</th>
                                                <th class="text-end">Predicted Expenses</th>
                                                <th class="text-center">Confidence</th>
                                                <th class="text-center">Risk Level</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($forecast_data as $forecast): ?>
                                            <?php 
                                            $risk_level = 'Low';
                                            $risk_class = 'success';
                                            if ($forecast['confidence'] < 70) {
                                                $risk_level = 'High';
                                                $risk_class = 'danger';
                                            } elseif ($forecast['confidence'] < 85) {
                                                $risk_level = 'Medium';
                                                $risk_class = 'warning';
                                            }
                                            ?>
                                            <tr>
                                                <td><?php echo $forecast['period']; ?></td>
                                                <td><?php echo date('F Y', strtotime($forecast['month'] . '-01')); ?></td>
                                                <td class="text-end">
                                                    <strong><?php echo htmlspecialchars($budget_settings['default_currency'] ?? 'KES'); ?> <?php echo number_format($forecast['predicted_expenses'], 2); ?></strong>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge bg-<?php echo $forecast['confidence'] >= 85 ? 'success' : ($forecast['confidence'] >= 70 ? 'warning' : 'danger'); ?>">
                                                        <?php echo number_format($forecast['confidence'], 1); ?>%
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge bg-<?php echo $risk_class; ?>"><?php echo $risk_level; ?></span>
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

                <!-- Budget Performance Overview -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="bi bi-speedometer2 me-2"></i>Current Budget Performance</h6>
                            </div>
                            <div class="card-body">
                                <?php if (empty($performance_metrics)): ?>
                                <div class="text-center py-4">
                                    <i class="bi bi-inbox fs-1 text-muted mb-3"></i>
                                    <h5 class="text-muted">No Active Budgets</h5>
                                    <p class="text-muted">Create budgets to view performance metrics</p>
                                    <a href="budget.php" class="btn btn-primary">
                                        <i class="bi bi-plus-circle me-1"></i> Create Budget
                                    </a>
                                </div>
                                <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Budget Name</th>
                                                <th class="text-end">Budgeted Amount</th>
                                                <th class="text-end">Actual Amount</th>
                                                <th class="text-end">Remaining</th>
                                                <th class="text-center">Utilization</th>
                                                <th class="text-center">Days Remaining</th>
                                                <th class="text-center">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($performance_metrics as $metric): ?>
                                            <?php 
                                            $utilization_class = 'success';
                                            if ($metric['utilization_percentage'] > 90) $utilization_class = 'danger';
                                            elseif ($metric['utilization_percentage'] > 75) $utilization_class = 'warning';
                                            
                                            $status_class = 'success';
                                            $status_text = 'On Track';
                                            if ($metric['days_remaining'] < 0) {
                                                $status_class = 'danger';
                                                $status_text = 'Overdue';
                                            } elseif ($metric['days_remaining'] < 30) {
                                                $status_class = 'warning';
                                                $status_text = 'Ending Soon';
                                            }
                                            ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($metric['name']); ?></strong>
                                                </td>
                                                <td class="text-end">
                                                    <?php echo htmlspecialchars($budget_settings['default_currency'] ?? 'KES'); ?> <?php echo number_format($metric['total_budget_amount'], 2); ?>
                                                </td>
                                                <td class="text-end">
                                                    <?php echo htmlspecialchars($budget_settings['default_currency'] ?? 'KES'); ?> <?php echo number_format($metric['total_actual_amount'], 2); ?>
                                                </td>
                                                <td class="text-end <?php echo $metric['remaining_amount'] < 0 ? 'text-danger' : 'text-success'; ?>">
                                                    <?php echo htmlspecialchars($budget_settings['default_currency'] ?? 'KES'); ?> <?php echo number_format($metric['remaining_amount'], 2); ?>
                                                </td>
                                                <td class="text-center">
                                                    <div class="progress" style="width: 80px; height: 20px;">
                                                        <div class="progress-bar bg-<?php echo $utilization_class; ?>" 
                                                             style="width: <?php echo min($metric['utilization_percentage'], 100); ?>%"></div>
                                                    </div>
                                                    <small><?php echo number_format($metric['utilization_percentage'], 1); ?>%</small>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge bg-<?php echo $metric['days_remaining'] < 0 ? 'danger' : ($metric['days_remaining'] < 30 ? 'warning' : 'secondary'); ?>">
                                                        <?php echo $metric['days_remaining']; ?> days
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge bg-<?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        <?php if (!empty($forecast_data)): ?>
        // Forecast Chart
        const forecastCtx = document.getElementById('forecastChart').getContext('2d');
        const forecastChart = new Chart(forecastCtx, {
            type: 'line',
            data: {
                labels: [
                    <?php foreach ($forecast_data as $forecast): ?>
                    '<?php echo date('M Y', strtotime($forecast['month'] . '-01')); ?>',
                    <?php endforeach; ?>
                ],
                datasets: [{
                    label: 'Predicted Expenses',
                    data: [
                        <?php foreach ($forecast_data as $forecast): ?>
                        <?php echo $forecast['predicted_expenses']; ?>,
                        <?php endforeach; ?>
                    ],
                    borderColor: 'rgb(75, 192, 192)',
                    backgroundColor: 'rgba(75, 192, 192, 0.1)',
                    tension: 0.1,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: {
                        display: true,
                        text: 'Budget Forecast - <?php echo ucfirst(str_replace('_', ' ', $forecast_method)); ?> Method'
                    },
                    legend: {
                        display: true
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '<?php echo htmlspecialchars($budget_settings['default_currency'] ?? 'KES'); ?> ' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>
