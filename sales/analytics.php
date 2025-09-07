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

// Check if user has permission to view sales
if (!hasPermission('view_sales', $permissions) && !hasPermission('manage_sales', $permissions)) {
    header('Location: ../dashboard/dashboard.php?error=access_denied');
    exit();
}

// Get date range (default to last 30 days)
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// Get system settings
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
$settings = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get sales analytics data
$analytics_data = [];

// Total sales for period
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_transactions,
        SUM(final_amount) as total_revenue,
        AVG(final_amount) as average_transaction,
        COUNT(DISTINCT customer_id) as unique_customers
    FROM sales 
    WHERE sale_date >= ? AND sale_date <= ?
");
$stmt->execute([$start_date, $end_date]);
$analytics_data['overview'] = $stmt->fetch(PDO::FETCH_ASSOC);

// Daily sales trend
$stmt = $conn->prepare("
    SELECT 
        DATE(sale_date) as sale_day,
        COUNT(*) as transactions,
        SUM(final_amount) as revenue
    FROM sales 
    WHERE sale_date >= ? AND sale_date <= ?
    GROUP BY DATE(sale_date)
    ORDER BY sale_day
");
$stmt->execute([$start_date, $end_date]);
$analytics_data['daily_trend'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Top selling products (simplified - since we don't have sales_items table)
$stmt = $conn->prepare("
    SELECT 
        p.name,
        p.sku,
        COUNT(s.id) as total_sales,
        SUM(s.final_amount) as total_revenue
    FROM products p
    LEFT JOIN sales s ON s.sale_date >= ? AND s.sale_date <= ?
    WHERE p.status = 'active'
    GROUP BY p.id
    HAVING total_sales > 0
    ORDER BY total_sales DESC
    LIMIT 10
");
$stmt->execute([$start_date, $end_date]);
$analytics_data['top_products'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Payment method breakdown
$stmt = $conn->prepare("
    SELECT 
        payment_method,
        COUNT(*) as transaction_count,
        SUM(final_amount) as total_amount
    FROM sales 
    WHERE sale_date >= ? AND sale_date <= ?
    GROUP BY payment_method
    ORDER BY total_amount DESC
");
$stmt->execute([$start_date, $end_date]);
$analytics_data['payment_methods'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Hourly sales pattern
$stmt = $conn->prepare("
    SELECT 
        HOUR(sale_date) as hour,
        COUNT(*) as transactions,
        SUM(final_amount) as revenue
    FROM sales 
    WHERE sale_date >= ? AND sale_date <= ?
    GROUP BY HOUR(sale_date)
    ORDER BY hour
");
$stmt->execute([$start_date, $end_date]);
$analytics_data['hourly_pattern'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Customer analytics
$stmt = $conn->prepare("
    SELECT 
        CONCAT(c.first_name, ' ', c.last_name) as customer_name,
        c.phone,
        COUNT(s.id) as total_orders,
        SUM(s.final_amount) as total_spent,
        AVG(s.final_amount) as average_order_value
    FROM customers c
    JOIN sales s ON c.id = s.customer_id
    WHERE s.sale_date >= ? AND s.sale_date <= ?
    GROUP BY c.id
    ORDER BY total_spent DESC
    LIMIT 10
");
$stmt->execute([$start_date, $end_date]);
$analytics_data['top_customers'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Analytics - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* Main Content Layout */
        .main-content {
            margin-left: 250px;
            min-height: 100vh;
            background-color: #f8fafc;
            padding: 20px;
        }
        
        .analytics-card {
            transition: transform 0.2s;
        }
        .analytics-card:hover {
            transform: translateY(-2px);
        }
        .metric-value {
            font-size: 2rem;
            font-weight: bold;
        }
        .metric-label {
            color: #6c757d;
            font-size: 0.9rem;
        }
        .chart-container {
            position: relative;
            height: 300px;
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 10px;
            }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../include/navmenu.php'; ?>
    
    <!-- Main Content -->
    <div class="main-content">
        <div class="container-fluid">
        <div class="row">
            <div class="col-md-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h2><i class="bi bi-graph-up-arrow text-primary"></i> Sales Analytics</h2>
                        <p class="text-muted">Detailed sales reports and insights</p>
                    </div>
                    <a href="salesdashboard.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Back to Dashboard
                    </a>
                </div>

                <!-- Date Range Filter -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-4">
                                <label for="start_date" class="form-label">Start Date</label>
                                <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo $start_date; ?>">
                            </div>
                            <div class="col-md-4">
                                <label for="end_date" class="form-label">End Date</label>
                                <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo $end_date; ?>">
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary me-2">
                                    <i class="bi bi-search"></i> Apply Filter
                                </button>
                                <a href="?start_date=<?php echo date('Y-m-d', strtotime('-30 days')); ?>&end_date=<?php echo date('Y-m-d'); ?>" class="btn btn-outline-secondary">
                                    Last 30 Days
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Overview Metrics -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card analytics-card text-center">
                            <div class="card-body">
                                <div class="metric-value text-primary"><?php echo number_format($analytics_data['overview']['total_transactions'] ?? 0); ?></div>
                                <div class="metric-label">Total Transactions</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card analytics-card text-center">
                            <div class="card-body">
                                <div class="metric-value text-success"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($analytics_data['overview']['total_revenue'] ?? 0, 2); ?></div>
                                <div class="metric-label">Total Revenue</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card analytics-card text-center">
                            <div class="card-body">
                                <div class="metric-value text-info"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($analytics_data['overview']['average_transaction'] ?? 0, 2); ?></div>
                                <div class="metric-label">Average Transaction</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card analytics-card text-center">
                            <div class="card-body">
                                <div class="metric-value text-warning"><?php echo number_format($analytics_data['overview']['unique_customers'] ?? 0); ?></div>
                                <div class="metric-label">Unique Customers</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Charts Row -->
                <div class="row mb-4">
                    <!-- Daily Sales Trend -->
                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-graph-up"></i> Daily Sales Trend</h5>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="dailyTrendChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Methods -->
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-credit-card"></i> Payment Methods</h5>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="paymentMethodsChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Data Tables Row -->
                <div class="row">
                    <!-- Top Products -->
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-trophy"></i> Top Selling Products</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Product</th>
                                                <th>SKU</th>
                                                <th>Sold</th>
                                                <th>Revenue</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($analytics_data['top_products'] as $product): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($product['name']); ?></td>
                                                <td><small class="text-muted"><?php echo htmlspecialchars($product['sku']); ?></small></td>
                                                <td><span class="badge bg-primary"><?php echo $product['total_sales']; ?></span></td>
                                                <td><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($product['total_revenue'], 2); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Top Customers -->
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-people"></i> Top Customers</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Customer</th>
                                                <th>Phone</th>
                                                <th>Orders</th>
                                                <th>Total Spent</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($analytics_data['top_customers'] as $customer): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($customer['customer_name']); ?></td>
                                                <td><small class="text-muted"><?php echo htmlspecialchars($customer['phone']); ?></small></td>
                                                <td><span class="badge bg-info"><?php echo $customer['total_orders']; ?></span></td>
                                                <td><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($customer['total_spent'], 2); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Daily Sales Trend Chart
        const dailyTrendData = <?php echo json_encode($analytics_data['daily_trend']); ?>;
        const dailyLabels = dailyTrendData.map(item => item.sale_day);
        const dailyRevenue = dailyTrendData.map(item => parseFloat(item.revenue) || 0);
        const dailyTransactions = dailyTrendData.map(item => parseInt(item.transactions) || 0);

        new Chart(document.getElementById('dailyTrendChart'), {
            type: 'line',
            data: {
                labels: dailyLabels,
                datasets: [{
                    label: 'Revenue',
                    data: dailyRevenue,
                    borderColor: 'rgb(75, 192, 192)',
                    backgroundColor: 'rgba(75, 192, 192, 0.2)',
                    tension: 0.1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });

        // Payment Methods Chart
        const paymentData = <?php echo json_encode($analytics_data['payment_methods']); ?>;
        const paymentLabels = paymentData.map(item => item.payment_method);
        const paymentAmounts = paymentData.map(item => parseFloat(item.total_amount) || 0);

        new Chart(document.getElementById('paymentMethodsChart'), {
            type: 'doughnut',
            data: {
                labels: paymentLabels,
                datasets: [{
                    data: paymentAmounts,
                    backgroundColor: [
                        '#FF6384',
                        '#36A2EB',
                        '#FFCE56',
                        '#4BC0C0',
                        '#9966FF',
                        '#FF9F40'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });
    </script>
</body>
</html>
