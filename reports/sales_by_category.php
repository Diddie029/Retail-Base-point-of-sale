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
$hasAccess = isAdmin($role_name) || 
             hasPermission('view_analytics', $permissions) || 
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
        $date_conditions[] = "DATE(s.sale_date) = CURDATE()";
        break;
    case 'week':
        $date_conditions[] = "YEARWEEK(s.sale_date) = YEARWEEK(CURDATE())";
        break;
    case 'month':
        $date_conditions[] = "MONTH(s.sale_date) = MONTH(CURDATE()) AND YEAR(s.sale_date) = YEAR(CURDATE())";
        break;
    case 'year':
        $date_conditions[] = "YEAR(s.sale_date) = YEAR(CURDATE())";
        break;
    default:
        $date_conditions[] = "MONTH(s.sale_date) = MONTH(CURDATE()) AND YEAR(s.sale_date) = YEAR(CURDATE())";
}

$date_condition = implode(' AND ', $date_conditions);

// Get sales by category data
$category_sales = [];
$stmt = $conn->prepare("
    SELECT 
        c.name as category_name,
        COUNT(DISTINCT s.id) as transaction_count,
        SUM(si.quantity) as total_quantity,
        SUM(si.total_price) as total_revenue,
        AVG(si.total_price) as avg_revenue_per_item,
        COUNT(DISTINCT si.product_id) as unique_products
    FROM sales s
    JOIN sale_items si ON s.id = si.sale_id
    JOIN products p ON si.product_id = p.id
    JOIN categories c ON p.category_id = c.id
    WHERE $date_condition
    GROUP BY c.id, c.name
    ORDER BY total_revenue DESC
");
$stmt->execute();
$category_sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total sales for percentage calculation
$total_revenue = array_sum(array_column($category_sales, 'total_revenue'));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales by Category Report - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
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
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            color: white;
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 8px 32px rgba(17, 153, 142, 0.3);
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
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            color: white;
            border: none;
            font-weight: 600;
        }
        
        .table tbody tr:hover {
            background-color: rgba(17, 153, 142, 0.05);
        }
        
        .badge {
            border-radius: 6px;
            font-weight: 500;
        }
        
        .category-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 1rem;
            transition: transform 0.3s ease;
        }
        
        .category-card:hover {
            transform: translateY(-2px);
        }
    </style>
</head>
<body>
    <?php include '../include/navmenu.php'; ?>

    <div class="main-content">
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1><i class="bi bi-tags"></i> Sales by Category Report</h1>
                    <p class="header-subtitle">Product category performance analysis and revenue breakdown</p>
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
                            <h2><i class="bi bi-tags"></i> Sales by Category Report</h2>
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
                            <h5><i class="bi bi-pie-chart"></i> Revenue by Category</h5>
                            <div class="chart-wrapper">
                                <canvas id="revenueChart"></canvas>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="chart-container">
                            <h5><i class="bi bi-bar-chart"></i> Transaction Count by Category</h5>
                            <div class="chart-wrapper">
                                <canvas id="transactionChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Category Performance Table -->
                <div class="row">
                    <div class="col-12">
                        <div class="table-container">
                            <h5><i class="bi bi-list-ul"></i> Category Performance Details</h5>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Category</th>
                                            <th>Revenue</th>
                                            <th>% of Total</th>
                                            <th>Transactions</th>
                                            <th>Quantity Sold</th>
                                            <th>Avg per Item</th>
                                            <th>Unique Products</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($category_sales as $category): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($category['category_name']); ?></strong>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($category['total_revenue'], 2); ?></strong>
                                            </td>
                                            <td>
                                                <span class="badge bg-primary">
                                                    <?php echo $total_revenue > 0 ? number_format(($category['total_revenue'] / $total_revenue) * 100, 1) : 0; ?>%
                                                </span>
                                            </td>
                                            <td><?php echo number_format($category['transaction_count']); ?></td>
                                            <td><?php echo number_format($category['total_quantity']); ?></td>
                                            <td><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($category['avg_revenue_per_item'], 2); ?></td>
                                            <td><?php echo number_format($category['unique_products']); ?></td>
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
        // Revenue by Category Chart
        const revenueCtx = document.getElementById('revenueChart').getContext('2d');
        const categoryData = <?php echo json_encode($category_sales); ?>;
        
        new Chart(revenueCtx, {
            type: 'doughnut',
            data: {
                labels: categoryData.map(item => item.category_name),
                datasets: [{
                    data: categoryData.map(item => parseFloat(item.total_revenue)),
                    backgroundColor: [
                        '#667eea',
                        '#764ba2',
                        '#f093fb',
                        '#f5576c',
                        '#4ecdc4',
                        '#45b7d1',
                        '#96ceb4',
                        '#feca57',
                        '#ff9ff3',
                        '#54a0ff'
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

        // Transaction Count Chart
        const transactionCtx = document.getElementById('transactionChart').getContext('2d');
        
        new Chart(transactionCtx, {
            type: 'bar',
            data: {
                labels: categoryData.map(item => item.category_name),
                datasets: [{
                    label: 'Transaction Count',
                    data: categoryData.map(item => parseInt(item.transaction_count)),
                    backgroundColor: 'rgba(99, 102, 241, 0.8)',
                    borderColor: 'rgb(99, 102, 241)',
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
            a.download = 'sales_by_category_report.csv';
            a.click();
        }
    </script>
</body>
</html>
