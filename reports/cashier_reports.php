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
             hasPermission('manage_users', $permissions) ||
             hasPermission('view_finance', $permissions);

if (!$hasAccess) {
    header('Location: ../dashboard/dashboard.php');
    exit();
}

// Get filter parameters
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$cashier_id = $_GET['cashier_id'] ?? '';

// Build date filter for queries
$date_filter = "DATE(s.sale_date) BETWEEN ? AND ?";
$void_date_filter = "DATE(vt.voided_at) BETWEEN ? AND ?";
$held_date_filter = "DATE(ht.created_at) BETWEEN ? AND ?";
$params = [$date_from, $date_to];

// Get cashier performance data
if (!empty($cashier_id)) {
    $cashier_filter = "AND s.user_id = ?";
    $void_cashier_filter = "AND vt.user_id = ?";
    $held_cashier_filter = "AND ht.user_id = ?";
    $params[] = $cashier_id;
} else {
    $cashier_filter = "";
    $void_cashier_filter = "";
    $held_cashier_filter = "";
}

// Get cashiers for filter dropdown
$cashiers_stmt = $conn->query("
    SELECT DISTINCT u.id, u.username 
    FROM sales s
    JOIN users u ON s.user_id = u.id
    ORDER BY u.username
");
$cashiers = $cashiers_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get cashier performance summary
$performance_stmt = $conn->prepare("
    SELECT 
        u.id,
        u.username,
        COUNT(s.id) as total_sales,
        COALESCE(SUM(s.final_amount), 0) as total_revenue,
        COALESCE(AVG(s.final_amount), 0) as avg_sale_amount,
        COUNT(DISTINCT DATE(s.sale_date)) as days_worked,
        COALESCE(SUM(s.final_amount) / COUNT(DISTINCT DATE(s.sale_date)), 0) as daily_average
    FROM users u
    LEFT JOIN sales s ON u.id = s.user_id AND $date_filter
    WHERE u.role = 'cashier' OR u.role = 'admin'
    $cashier_filter
    GROUP BY u.id, u.username
    ORDER BY total_revenue DESC
");
$performance_stmt->execute($params);
$cashier_performance = $performance_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get void transactions summary by cashier
$void_summary_stmt = $conn->prepare("
    SELECT 
        u.id,
        u.username,
        COUNT(vt.id) as total_voids,
        COALESCE(SUM(vt.total_amount), 0) as total_voided_amount,
        COALESCE(AVG(vt.total_amount), 0) as avg_void_amount
    FROM users u
    LEFT JOIN void_transactions vt ON u.id = vt.user_id AND $void_date_filter
    WHERE u.role = 'cashier' OR u.role = 'admin'
    $void_cashier_filter
    GROUP BY u.id, u.username
    ORDER BY total_voided_amount DESC
");
$void_summary_stmt->execute($params);
$void_summary = $void_summary_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get held transactions summary by cashier
$held_summary_stmt = $conn->prepare("
    SELECT 
        u.id,
        u.username,
        COUNT(ht.id) as total_held,
        COALESCE(SUM(JSON_UNQUOTE(JSON_EXTRACT(ht.cart_data, '$.total'))), 0) as total_held_amount
    FROM users u
    LEFT JOIN held_transactions ht ON u.id = ht.user_id AND $held_date_filter AND ht.status = 'held'
    WHERE u.role = 'cashier' OR u.role = 'admin'
    $held_cashier_filter
    GROUP BY u.id, u.username
    ORDER BY total_held_amount DESC
");
$held_summary_stmt->execute($params);
$held_summary = $held_summary_stmt->fetchAll(PDO::FETCH_ASSOC);

// Merge all data
$cashier_data = [];
foreach ($cashier_performance as $perf) {
    $cashier_data[$perf['id']] = $perf;
}

foreach ($void_summary as $void) {
    if (isset($cashier_data[$void['id']])) {
        $cashier_data[$void['id']] = array_merge($cashier_data[$void['id']], $void);
    } else {
        $cashier_data[$void['id']] = $void;
    }
}

foreach ($held_summary as $held) {
    if (isset($cashier_data[$held['id']])) {
        $cashier_data[$held['id']] = array_merge($cashier_data[$held['id']], $held);
    } else {
        $cashier_data[$held['id']] = $held;
    }
}

// Calculate overall statistics
$total_revenue = array_sum(array_column($cashier_data, 'total_revenue'));
$total_voids = array_sum(array_column($cashier_data, 'total_voids'));
$total_voided_amount = array_sum(array_column($cashier_data, 'total_voided_amount'));
$total_held = array_sum(array_column($cashier_data, 'total_held'));
$total_held_amount = array_sum(array_column($cashier_data, 'total_held_amount'));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cashier Reports - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
        
        .summary-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1rem;
        }
        
        .summary-card.success {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
        }
        
        .summary-card.danger {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);
        }
        
        .summary-card.warning {
            background: linear-gradient(135deg, #feca57 0%, #ff9ff3 100%);
        }
        
        .summary-card.info {
            background: linear-gradient(135deg, #48cae4 0%, #023e8a 100%);
        }
        
        .filter-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .cashier-card {
            border: none;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            border-radius: 12px;
            margin-bottom: 1rem;
        }
        
        .cashier-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }
        
        .cashier-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px 12px 0 0;
            padding: 1rem;
        }
        
        .metric-item {
            text-align: center;
            padding: 0.5rem;
        }
        
        .metric-value {
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--primary-color);
        }
        
        .metric-label {
            font-size: 0.8rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .performance-badge {
            font-size: 0.75rem;
            padding: 0.4rem 0.8rem;
        }
        
        .export-buttons {
            gap: 0.5rem;
        }
    </style>
</head>
<body>
    <?php include '../include/navmenu.php'; ?>

    <div class="main-content">
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1><i class="bi bi-person-check"></i> Cashier Performance Reports</h1>
                    <p class="header-subtitle">Comprehensive cashier performance analysis and accountability metrics</p>
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
                <!-- Summary Cards -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="summary-card success">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Total Revenue</h6>
                                <i class="bi bi-currency-dollar fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($total_revenue, 2); ?></h3>
                            <small class="opacity-75">All cashiers</small>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="summary-card danger">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Total Voids</h6>
                                <i class="bi bi-x-circle fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo number_format($total_voids); ?></h3>
                            <small class="opacity-75">Voided transactions</small>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="summary-card warning">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Held Transactions</h6>
                                <i class="bi bi-clock-history fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo number_format($total_held); ?></h3>
                            <small class="opacity-75">Pending transactions</small>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="summary-card info">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Void Amount</h6>
                                <i class="bi bi-exclamation-triangle fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($total_voided_amount, 2); ?></h3>
                            <small class="opacity-75">Total voided</small>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="filter-card">
                    <form method="GET" class="row g-3">
                        <div class="col-md-4">
                            <label for="date_from" class="form-label">From Date</label>
                            <input type="date" class="form-control" id="date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                        </div>
                        <div class="col-md-4">
                            <label for="date_to" class="form-label">To Date</label>
                            <input type="date" class="form-control" id="date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                        </div>
                        <div class="col-md-4">
                            <label for="cashier_id" class="form-label">Cashier</label>
                            <select class="form-select" id="cashier_id" name="cashier_id">
                                <option value="">All Cashiers</option>
                                <?php foreach ($cashiers as $cashier): ?>
                                <option value="<?php echo $cashier['id']; ?>" <?php echo $cashier_id == $cashier['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cashier['username']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-search"></i> Filter
                                </button>
                                <a href="cashier_reports.php" class="btn btn-outline-secondary">
                                    <i class="bi bi-arrow-clockwise"></i> Reset
                                </a>
                                <div class="export-buttons d-flex">
                                    <button type="button" class="btn btn-success" onclick="exportToCSV()">
                                        <i class="bi bi-file-earmark-spreadsheet"></i> Export CSV
                                    </button>
                                    <button type="button" class="btn btn-danger" onclick="exportToPDF()">
                                        <i class="bi bi-file-earmark-pdf"></i> Export PDF
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Cashier Performance Cards -->
                <div class="row">
                    <?php if (empty($cashier_data)): ?>
                    <div class="col-12">
                        <div class="text-center py-5">
                            <i class="bi bi-person-x fs-1 text-muted mb-3"></i>
                            <h5 class="text-muted">No Cashier Data Found</h5>
                            <p class="text-muted">No sales data found for the selected criteria</p>
                        </div>
                    </div>
                    <?php else: ?>
                    <?php foreach ($cashier_data as $cashier): ?>
                    <div class="col-xl-6 col-lg-12 mb-4">
                        <div class="card cashier-card">
                            <div class="cashier-header">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h5 class="mb-1"><?php echo htmlspecialchars($cashier['username']); ?></h5>
                                        <small class="opacity-75">Cashier Performance</small>
                                    </div>
                                    <div class="text-end">
                                        <div class="h4 mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($cashier['total_revenue'] ?? 0, 2); ?></div>
                                        <small class="opacity-75">Total Revenue</small>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-3">
                                        <div class="metric-item">
                                            <div class="metric-value"><?php echo number_format($cashier['total_sales'] ?? 0); ?></div>
                                            <div class="metric-label">Sales</div>
                                        </div>
                                    </div>
                                    <div class="col-3">
                                        <div class="metric-item">
                                            <div class="metric-value"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($cashier['avg_sale_amount'] ?? 0, 2); ?></div>
                                            <div class="metric-label">Avg Sale</div>
                                        </div>
                                    </div>
                                    <div class="col-3">
                                        <div class="metric-item">
                                            <div class="metric-value text-danger"><?php echo number_format($cashier['total_voids'] ?? 0); ?></div>
                                            <div class="metric-label">Voids</div>
                                        </div>
                                    </div>
                                    <div class="col-3">
                                        <div class="metric-item">
                                            <div class="metric-value text-warning"><?php echo number_format($cashier['total_held'] ?? 0); ?></div>
                                            <div class="metric-label">Held</div>
                                        </div>
                                    </div>
                                </div>
                                
                                <hr class="my-3">
                                
                                <div class="row g-2">
                                    <div class="col-6">
                                        <div class="d-flex justify-content-between">
                                            <span class="text-muted">Days Worked:</span>
                                            <strong><?php echo $cashier['days_worked'] ?? 0; ?></strong>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="d-flex justify-content-between">
                                            <span class="text-muted">Daily Average:</span>
                                            <strong><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($cashier['daily_average'] ?? 0, 2); ?></strong>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="d-flex justify-content-between">
                                            <span class="text-muted">Void Amount:</span>
                                            <strong class="text-danger"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($cashier['total_voided_amount'] ?? 0, 2); ?></strong>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="d-flex justify-content-between">
                                            <span class="text-muted">Held Amount:</span>
                                            <strong class="text-warning"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($cashier['total_held_amount'] ?? 0, 2); ?></strong>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mt-3">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="text-muted">Performance Rating:</span>
                                        <?php
                                        $void_rate = $cashier['total_sales'] > 0 ? ($cashier['total_voids'] / $cashier['total_sales']) * 100 : 0;
                                        if ($void_rate <= 2) {
                                            $rating = 'Excellent';
                                            $badge_class = 'success';
                                        } elseif ($void_rate <= 5) {
                                            $rating = 'Good';
                                            $badge_class = 'info';
                                        } elseif ($void_rate <= 10) {
                                            $rating = 'Fair';
                                            $badge_class = 'warning';
                                        } else {
                                            $rating = 'Needs Improvement';
                                            $badge_class = 'danger';
                                        }
                                        ?>
                                        <span class="badge bg-<?php echo $badge_class; ?> performance-badge">
                                            <?php echo $rating; ?> (<?php echo number_format($void_rate, 1); ?>% void rate)
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Back Button -->
                <div class="row mt-4">
                    <div class="col-12">
                        <a href="index.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left"></i> Back to Reports
                        </a>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function exportToCSV() {
            // Get current filter parameters
            const params = new URLSearchParams(window.location.search);
            params.set('export', 'csv');
            
            // Create download link
            const link = document.createElement('a');
            link.href = 'export_cashier_reports.php?' + params.toString();
            link.download = 'cashier_reports_' + new Date().toISOString().split('T')[0] + '.csv';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
        
        function exportToPDF() {
            // Get current filter parameters
            const params = new URLSearchParams(window.location.search);
            params.set('export', 'pdf');
            
            // Open PDF in new window
            window.open('export_cashier_reports.php?' + params.toString(), '_blank');
        }
    </script>
</body>
</html>
