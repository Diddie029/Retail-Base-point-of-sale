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

// Check if user has permission to view reports
$hasAccess = hasPermission('view_analytics', $permissions) || 
             hasPermission('manage_sales', $permissions) || 
             hasPermission('view_finance', $permissions);

if (!$hasAccess) {
    header('Location: ../dashboard/dashboard.php');
    exit();
}

// Get date range parameters
$date_range = $_GET['range'] ?? 'month';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

// Calculate date ranges
$date_conditions = [];
$date_params = [];

switch ($date_range) {
    case 'today':
        $date_conditions[] = "DATE(sale_date) = CURDATE()";
        break;
    case 'week':
        $date_conditions[] = "YEARWEEK(sale_date) = YEARWEEK(CURDATE())";
        break;
    case 'month':
        $date_conditions[] = "MONTH(sale_date) = MONTH(CURDATE()) AND YEAR(sale_date) = YEAR(CURDATE())";
        break;
    case 'year':
        $date_conditions[] = "YEAR(sale_date) = YEAR(CURDATE())";
        break;
    case 'custom':
        if ($start_date && $end_date) {
            $date_conditions[] = "DATE(sale_date) BETWEEN :start_date AND :end_date";
            $date_params[':start_date'] = $start_date;
            $date_params[':end_date'] = $end_date;
        } else {
            $date_conditions[] = "MONTH(sale_date) = MONTH(CURDATE()) AND YEAR(sale_date) = YEAR(CURDATE())";
        }
        break;
    default:
        $date_conditions[] = "MONTH(sale_date) = MONTH(CURDATE()) AND YEAR(sale_date) = YEAR(CURDATE())";
}

$date_condition = implode(' AND ', $date_conditions);

// Get sales summary data
$summary_data = [];

// Total sales for the period
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_transactions,
        COALESCE(SUM(final_amount), 0) as total_revenue,
        COALESCE(AVG(final_amount), 0) as average_transaction,
        COALESCE(MIN(final_amount), 0) as min_transaction,
        COALESCE(MAX(final_amount), 0) as max_transaction
    FROM sales 
    WHERE $date_condition
");
foreach ($date_params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$summary_data = $stmt->fetch(PDO::FETCH_ASSOC);

// Daily sales breakdown for the period
$daily_sales = [];
$stmt = $conn->prepare("
    SELECT 
        DATE(sale_date) as sale_date,
        COUNT(*) as transaction_count,
        COALESCE(SUM(final_amount), 0) as daily_revenue
    FROM sales 
    WHERE $date_condition
    GROUP BY DATE(sale_date)
    ORDER BY sale_date DESC
");
foreach ($date_params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$daily_sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Sales by hour (for today only)
$hourly_sales = [];
if ($date_range === 'today') {
    $stmt = $conn->prepare("
        SELECT 
            HOUR(sale_date) as hour,
            COUNT(*) as transaction_count,
            COALESCE(SUM(final_amount), 0) as hourly_revenue
        FROM sales 
        WHERE DATE(sale_date) = CURDATE()
        GROUP BY HOUR(sale_date)
        ORDER BY hour
    ");
    $stmt->execute();
    $hourly_sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Top selling products
$top_products = [];
$stmt = $conn->prepare("
    SELECT 
        p.name as product_name,
        p.sku,
        SUM(si.quantity) as total_quantity,
        SUM(si.total_price) as total_revenue,
        COUNT(DISTINCT s.id) as transaction_count
    FROM sales s
    JOIN sale_items si ON s.id = si.sale_id
    JOIN products p ON si.product_id = p.id
    WHERE $date_condition
    GROUP BY p.id, p.name, p.sku
    ORDER BY total_revenue DESC
    LIMIT 10
");
foreach ($date_params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$top_products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Sales by payment method
$payment_methods = [];
$stmt = $conn->prepare("
    SELECT 
        payment_method,
        COUNT(*) as transaction_count,
        COALESCE(SUM(final_amount), 0) as total_revenue
    FROM sales 
    WHERE $date_condition
    GROUP BY payment_method
    ORDER BY total_revenue DESC
");
foreach ($date_params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$payment_methods = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Summary Report - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
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
        
        .report-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 8px 32px rgba(102, 126, 234, 0.3);
        }
        
        .metric-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            border: 1px solid rgba(0, 0, 0, 0.05);
        }
        
        .metric-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }
        
        .metric-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
        }
        
        .chart-container {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
            border: 1px solid rgba(0, 0, 0, 0.05);
            height: 400px;
            display: flex;
            flex-direction: column;
        }
        
        .chart-container h5 {
            margin-bottom: 1rem;
            flex-shrink: 0;
        }
        
        .chart-wrapper {
            flex: 1;
            position: relative;
            min-height: 300px;
        }
        
        .table-container {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(0, 0, 0, 0.05);
        }
        
        .filter-section {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
            border: 1px solid rgba(0, 0, 0, 0.05);
        }
        
        .btn {
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn:hover {
            transform: translateY(-1px);
        }
        
        .table {
            border-radius: 8px;
            overflow: hidden;
        }
        
        .table thead th {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            font-weight: 600;
        }
        
        .table tbody tr:hover {
            background-color: rgba(102, 126, 234, 0.05);
        }
        
        .badge {
            border-radius: 6px;
            font-weight: 500;
        }
    </style>
</head>
<body>
    <?php include '../include/navmenu.php'; ?>

    <div class="main-content">
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1><i class="bi bi-calendar-range"></i> Sales Summary Report</h1>
                    <p class="header-subtitle">Comprehensive sales analysis and revenue tracking</p>
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
                <!-- Report Header -->
                <div class="report-header">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h2><i class="bi bi-graph-up"></i> Sales Summary Report</h2>
                            <p class="mb-0">Period: <?php 
                                switch($date_range) {
                                    case 'today': echo 'Today'; break;
                                    case 'week': echo 'This Week'; break;
                                    case 'month': echo 'This Month'; break;
                                    case 'year': echo 'This Year'; break;
                                    case 'custom': echo $start_date . ' to ' . $end_date; break;
                                    default: echo 'This Month';
                                }
                            ?></p>
                        </div>
                        <div class="col-md-4 text-end">
                            <button class="btn btn-light" onclick="window.print()">
                                <i class="bi bi-printer"></i> Print Report
                            </button>
                            <button class="btn btn-outline-light" onclick="exportToExcel()">
                                <i class="bi bi-download"></i> Export Excel
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Filter Section -->
                <div class="filter-section">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label for="range" class="form-label">Date Range</label>
                            <select class="form-select" id="range" name="range" onchange="toggleCustomDates()">
                                <option value="today" <?php echo $date_range === 'today' ? 'selected' : ''; ?>>Today</option>
                                <option value="week" <?php echo $date_range === 'week' ? 'selected' : ''; ?>>This Week</option>
                                <option value="month" <?php echo $date_range === 'month' ? 'selected' : ''; ?>>This Month</option>
                                <option value="year" <?php echo $date_range === 'year' ? 'selected' : ''; ?>>This Year</option>
                                <option value="custom" <?php echo $date_range === 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                            </select>
                        </div>
                        <div class="col-md-3" id="start-date-group" style="display: <?php echo $date_range === 'custom' ? 'block' : 'none'; ?>;">
                            <label for="start_date" class="form-label">Start Date</label>
                            <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo $start_date; ?>">
                        </div>
                        <div class="col-md-3" id="end-date-group" style="display: <?php echo $date_range === 'custom' ? 'block' : 'none'; ?>;">
                            <label for="end_date" class="form-label">End Date</label>
                            <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo $end_date; ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">&nbsp;</label>
                            <div>
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-search"></i> Generate Report
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Key Metrics -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="metric-card text-center">
                            <i class="bi bi-cash-coin fs-1 text-primary mb-2"></i>
                            <div class="metric-value"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($summary_data['total_revenue'], 2); ?></div>
                            <div class="text-muted">Total Revenue</div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="metric-card text-center">
                            <i class="bi bi-receipt fs-1 text-success mb-2"></i>
                            <div class="metric-value"><?php echo number_format($summary_data['total_transactions']); ?></div>
                            <div class="text-muted">Total Transactions</div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="metric-card text-center">
                            <i class="bi bi-graph-up fs-1 text-warning mb-2"></i>
                            <div class="metric-value"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($summary_data['average_transaction'], 2); ?></div>
                            <div class="text-muted">Average Transaction</div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="metric-card text-center">
                            <i class="bi bi-trophy fs-1 text-info mb-2"></i>
                            <div class="metric-value"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($summary_data['max_transaction'], 2); ?></div>
                            <div class="text-muted">Highest Transaction</div>
                        </div>
                    </div>
                </div>

                <!-- Charts Row -->
                <div class="row mb-4">
                    <div class="col-lg-8">
                        <div class="chart-container">
                            <h5><i class="bi bi-bar-chart"></i> Daily Sales Trend</h5>
                            <div class="chart-wrapper">
                                <canvas id="dailySalesChart"></canvas>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="chart-container">
                            <h5><i class="bi bi-pie-chart"></i> Payment Methods</h5>
                            <div class="chart-wrapper">
                                <canvas id="paymentMethodsChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($date_range === 'today' && !empty($hourly_sales)): ?>
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="chart-container">
                            <h5><i class="bi bi-clock"></i> Hourly Sales Distribution</h5>
                            <div class="chart-wrapper">
                                <canvas id="hourlySalesChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Top Products Table -->
                <div class="row">
                    <div class="col-12">
                        <div class="table-container">
                            <h5><i class="bi bi-trophy"></i> Top Selling Products</h5>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Rank</th>
                                            <th>Product</th>
                                            <th>SKU</th>
                                            <th>Quantity Sold</th>
                                            <th>Revenue</th>
                                            <th>Transactions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($top_products as $index => $product): ?>
                                        <tr>
                                            <td><span class="badge bg-primary"><?php echo $index + 1; ?></span></td>
                                            <td><?php echo htmlspecialchars($product['product_name']); ?></td>
                                            <td><code><?php echo htmlspecialchars($product['sku']); ?></code></td>
                                            <td><?php echo number_format($product['total_quantity']); ?></td>
                                            <td><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($product['total_revenue'], 2); ?></td>
                                            <td><?php echo number_format($product['transaction_count']); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Daily Sales Chart
        const dailySalesCtx = document.getElementById('dailySalesChart').getContext('2d');
        const dailySalesData = <?php echo json_encode($daily_sales); ?>;
        
        new Chart(dailySalesCtx, {
            type: 'line',
            data: {
                labels: dailySalesData.map(item => new Date(item.sale_date).toLocaleDateString()),
                datasets: [{
                    label: 'Daily Revenue',
                    data: dailySalesData.map(item => parseFloat(item.daily_revenue)),
                    borderColor: 'rgb(99, 102, 241)',
                    backgroundColor: 'rgba(99, 102, 241, 0.1)',
                    tension: 0.4,
                    fill: true,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    borderWidth: 3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    intersect: false,
                    mode: 'index'
                },
                plugins: {
                    legend: {
                        display: true,
                        position: 'top'
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        titleColor: 'white',
                        bodyColor: 'white',
                        borderColor: 'rgb(99, 102, 241)',
                        borderWidth: 1
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: true,
                            color: 'rgba(0, 0, 0, 0.1)'
                        },
                        ticks: {
                            color: '#666'
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            display: true,
                            color: 'rgba(0, 0, 0, 0.1)'
                        },
                        ticks: {
                            color: '#666',
                            callback: function(value) {
                                return '<?php echo $settings['currency_symbol'] ?? 'KES'; ?>' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });

        // Payment Methods Chart
        const paymentMethodsCtx = document.getElementById('paymentMethodsChart').getContext('2d');
        const paymentMethodsData = <?php echo json_encode($payment_methods); ?>;
        
        new Chart(paymentMethodsCtx, {
            type: 'doughnut',
            data: {
                labels: paymentMethodsData.map(item => item.payment_method),
                datasets: [{
                    data: paymentMethodsData.map(item => parseFloat(item.total_revenue)),
                    backgroundColor: [
                        '#667eea',
                        '#764ba2',
                        '#f093fb',
                        '#f5576c',
                        '#4ecdc4',
                        '#45b7d1'
                    ],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 20,
                            usePointStyle: true,
                            font: {
                                size: 12
                            }
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        titleColor: 'white',
                        bodyColor: 'white',
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.parsed;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = ((value / total) * 100).toFixed(1);
                                return `${label}: <?php echo $settings['currency_symbol'] ?? 'KES'; ?>${value.toLocaleString()} (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });

        <?php if ($date_range === 'today' && !empty($hourly_sales)): ?>
        // Hourly Sales Chart
        const hourlySalesCtx = document.getElementById('hourlySalesChart').getContext('2d');
        const hourlySalesData = <?php echo json_encode($hourly_sales); ?>;
        
        new Chart(hourlySalesCtx, {
            type: 'bar',
            data: {
                labels: hourlySalesData.map(item => item.hour + ':00'),
                datasets: [{
                    label: 'Hourly Revenue',
                    data: hourlySalesData.map(item => parseFloat(item.hourly_revenue)),
                    backgroundColor: 'rgba(99, 102, 241, 0.8)',
                    borderColor: 'rgb(99, 102, 241)',
                    borderWidth: 2,
                    borderRadius: 4,
                    borderSkipped: false
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    intersect: false,
                    mode: 'index'
                },
                plugins: {
                    legend: {
                        display: true,
                        position: 'top'
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        titleColor: 'white',
                        bodyColor: 'white',
                        borderColor: 'rgb(99, 102, 241)',
                        borderWidth: 1
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: true,
                            color: 'rgba(0, 0, 0, 0.1)'
                        },
                        ticks: {
                            color: '#666'
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            display: true,
                            color: 'rgba(0, 0, 0, 0.1)'
                        },
                        ticks: {
                            color: '#666',
                            callback: function(value) {
                                return '<?php echo $settings['currency_symbol'] ?? 'KES'; ?>' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });
        <?php endif; ?>

        function toggleCustomDates() {
            const range = document.getElementById('range').value;
            const startDateGroup = document.getElementById('start-date-group');
            const endDateGroup = document.getElementById('end-date-group');
            
            if (range === 'custom') {
                startDateGroup.style.display = 'block';
                endDateGroup.style.display = 'block';
            } else {
                startDateGroup.style.display = 'none';
                endDateGroup.style.display = 'none';
            }
        }

        function exportToExcel() {
            // Simple Excel export functionality
            const table = document.querySelector('table');
            const rows = table.querySelectorAll('tr');
            let csv = [];
            
            for (let i = 0; i < rows.length; i++) {
                const row = [], cols = rows[i].querySelectorAll('td, th');
                for (let j = 0; j < cols.length; j++) {
                    row.push(cols[j].innerText);
                }
                csv.push(row.join(','));
            }
            
            const csvContent = csv.join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'sales_summary_report.csv';
            a.click();
        }
    </script>
</body>
</html>
