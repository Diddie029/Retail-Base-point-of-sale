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

// Calculate date conditions
$date_conditions = [];
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
    default:
        $date_conditions[] = "MONTH(sale_date) = MONTH(CURDATE()) AND YEAR(sale_date) = YEAR(CURDATE())";
}

$date_condition = implode(' AND ', $date_conditions);

// Get payment method data
$payment_methods = [];
$stmt = $conn->prepare("
    SELECT 
        payment_method,
        COUNT(*) as transaction_count,
        COALESCE(SUM(final_amount), 0) as total_revenue,
        COALESCE(AVG(final_amount), 0) as avg_transaction_amount,
        MIN(final_amount) as min_transaction,
        MAX(final_amount) as max_transaction
    FROM sales 
    WHERE $date_condition
    GROUP BY payment_method
    ORDER BY total_revenue DESC
");
$stmt->execute();
$payment_methods = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total revenue for percentage calculation
$total_revenue = array_sum(array_column($payment_methods, 'total_revenue'));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Methods Report - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
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
            background: linear-gradient(135deg, #4ecdc4 0%, #44a08d 100%);
            color: white;
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 8px 32px rgba(78, 205, 196, 0.3);
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
            background: linear-gradient(135deg, #4ecdc4 0%, #44a08d 100%);
            color: white;
            border: none;
            font-weight: 600;
        }
        
        .table tbody tr:hover {
            background-color: rgba(78, 205, 196, 0.05);
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
                    <h1><i class="bi bi-credit-card"></i> Payment Methods Report</h1>
                    <p class="header-subtitle">Analysis of cash, card, mobile payments, and other payment types</p>
                </div>
                <div class="header-actions">
                    <div class="user-info">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($username, 0, 2)); ?>
                        </div>
                        <div>
                            <div class="fw-semibold"><?php echo htmlspecialchars($username ?? 'Unknown User'); ?></div>
                            <small class="text-muted"><?php echo htmlspecialchars($role_name ?? 'Unknown Role'); ?></small>
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
                            <h2><i class="bi bi-credit-card"></i> Payment Methods Report</h2>
                            <p class="mb-0">Period: <?php 
                                switch($date_range) {
                                    case 'today': echo 'Today'; break;
                                    case 'week': echo 'This Week'; break;
                                    case 'month': echo 'This Month'; break;
                                    case 'year': echo 'This Year'; break;
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
                            <select class="form-select" id="range" name="range">
                                <option value="today" <?php echo $date_range === 'today' ? 'selected' : ''; ?>>Today</option>
                                <option value="week" <?php echo $date_range === 'week' ? 'selected' : ''; ?>>This Week</option>
                                <option value="month" <?php echo $date_range === 'month' ? 'selected' : ''; ?>>This Month</option>
                                <option value="year" <?php echo $date_range === 'year' ? 'selected' : ''; ?>>This Year</option>
                            </select>
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

                <!-- Charts Row -->
                <div class="row mb-4">
                    <div class="col-lg-6">
                        <div class="chart-container">
                            <h5><i class="bi bi-pie-chart"></i> Revenue by Payment Method</h5>
                            <div class="chart-wrapper">
                                <canvas id="revenueChart"></canvas>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="chart-container">
                            <h5><i class="bi bi-bar-chart"></i> Transaction Count by Payment Method</h5>
                            <div class="chart-wrapper">
                                <canvas id="transactionChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Payment Methods Table -->
                <div class="row">
                    <div class="col-12">
                        <div class="table-container">
                            <h5><i class="bi bi-list-ul"></i> Payment Method Performance</h5>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Payment Method</th>
                                            <th>Revenue</th>
                                            <th>% of Total</th>
                                            <th>Transactions</th>
                                            <th>Avg per Transaction</th>
                                            <th>Min Transaction</th>
                                            <th>Max Transaction</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($payment_methods as $method): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($method['payment_method'] ?? 'Unknown'); ?></strong>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($method['total_revenue'], 2); ?></strong>
                                            </td>
                                            <td>
                                                <span class="badge bg-primary">
                                                    <?php echo $total_revenue > 0 ? number_format(($method['total_revenue'] / $total_revenue) * 100, 1) : 0; ?>%
                                                </span>
                                            </td>
                                            <td><?php echo number_format($method['transaction_count']); ?></td>
                                            <td><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($method['avg_transaction_amount'], 2); ?></td>
                                            <td><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($method['min_transaction'], 2); ?></td>
                                            <td><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($method['max_transaction'], 2); ?></td>
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
        // Revenue Chart
        const revenueCtx = document.getElementById('revenueChart').getContext('2d');
        const paymentData = <?php echo json_encode($payment_methods); ?>;
        
        new Chart(revenueCtx, {
            type: 'doughnut',
            data: {
                labels: paymentData.map(item => item.payment_method),
                datasets: [{
                    data: paymentData.map(item => parseFloat(item.total_revenue)),
                    backgroundColor: [
                        '#667eea',
                        '#764ba2',
                        '#f093fb',
                        '#f5576c',
                        '#4ecdc4',
                        '#45b7d1'
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

        // Transaction Chart
        const transactionCtx = document.getElementById('transactionChart').getContext('2d');
        
        new Chart(transactionCtx, {
            type: 'bar',
            data: {
                labels: paymentData.map(item => item.payment_method),
                datasets: [{
                    label: 'Transaction Count',
                    data: paymentData.map(item => parseInt(item.transaction_count)),
                    backgroundColor: 'rgba(78, 205, 196, 0.8)',
                    borderColor: 'rgb(78, 205, 196)',
                    borderWidth: 1
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

        function exportToExcel() {
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
            a.download = 'payment_methods_report.csv';
            a.click();
        }
    </script>
</body>
</html>
