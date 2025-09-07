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

// Check permissions
$hasAccess = hasPermission('view_finance', $permissions) || 
             hasPermission('manage_expenses', $permissions) || 
             hasPermission('view_analytics', $permissions) ||
             hasPermission('manage_users', $permissions);

if (!$hasAccess) {
    header('Location: ../dashboard/dashboard.php');
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get date range parameters
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');
$category_filter = $_GET['category'] ?? 'all';
$department_filter = $_GET['department'] ?? 'all';

// Validate dates
$start_date = date('Y-m-d', strtotime($start_date));
$end_date = date('Y-m-d', strtotime($end_date));

// Expense Analytics Functions
function getExpenseAnalytics($conn, $start_date, $end_date, $category_filter = 'all', $department_filter = 'all') {
    $analytics = [];
    
    // Base query conditions - try without approval_status first
    $where_conditions = ["DATE(expense_date) BETWEEN :start_date AND :end_date"];
    $params = [':start_date' => $start_date, ':end_date' => $end_date];
    
    // Check if approval_status column exists and add it if it does
    try {
        $check_stmt = $conn->query("SHOW COLUMNS FROM expenses LIKE 'approval_status'");
        if ($check_stmt->rowCount() > 0) {
            $where_conditions[] = "approval_status = 'approved'";
        }
    } catch (Exception $e) {
        // Column doesn't exist, continue without it
    }
    
    if ($category_filter !== 'all') {
        $where_conditions[] = "category_id = :category_id";
        $params[':category_id'] = $category_filter;
    }
    
    if ($department_filter !== 'all') {
        $where_conditions[] = "department_id = :department_id";
        $params[':department_id'] = $department_filter;
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    // Total expenses
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as count,
            SUM(total_amount) as total,
            AVG(total_amount) as average,
            MIN(total_amount) as min_amount,
            MAX(total_amount) as max_amount
        FROM expenses 
        WHERE $where_clause
    ");
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $totals = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Handle null values from aggregate functions when no data exists
    $analytics['totals'] = [
        'count' => (int)($totals['count'] ?? 0),
        'total' => (float)($totals['total'] ?? 0),
        'average' => (float)($totals['average'] ?? 0),
        'min_amount' => (float)($totals['min_amount'] ?? 0),
        'max_amount' => (float)($totals['max_amount'] ?? 0)
    ];
    
    // Expenses by category
    try {
        $stmt = $conn->prepare("
            SELECT 
                ec.name as category_name,
                COUNT(*) as count,
                SUM(e.total_amount) as total,
                AVG(e.total_amount) as average
            FROM expenses e
            LEFT JOIN expense_categories ec ON e.category_id = ec.id
            WHERE $where_clause
            GROUP BY e.category_id, ec.name
            ORDER BY total DESC
        ");
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $category_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Handle null values in category data
        $analytics['by_category'] = array_map(function($item) {
            return [
                'category_name' => $item['category_name'] ?? 'Unknown Category',
                'count' => (int)($item['count'] ?? 0),
                'total' => (float)($item['total'] ?? 0),
                'average' => (float)($item['average'] ?? 0)
            ];
        }, $category_data);
    } catch (Exception $e) {
        // Categories table might not exist or have different structure
        $analytics['by_category'] = [];
    }
    
    // Get detailed expense list with approval status
    try {
        $stmt = $conn->prepare("
            SELECT 
                e.id,
                e.title,
                e.total_amount,
                e.expense_date,
                e.approval_status,
                ec.name as category_name,
                v.name as vendor_name
            FROM expenses e
            LEFT JOIN expense_categories ec ON e.category_id = ec.id
            LEFT JOIN vendors v ON e.vendor_id = v.id
            WHERE $where_clause
            ORDER BY e.expense_date DESC
            LIMIT 50
        ");
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $expense_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Handle null values in expense list
        $analytics['expense_list'] = array_map(function($item) {
            return [
                'id' => (int)($item['id'] ?? 0),
                'title' => $item['title'] ?? 'Untitled Expense',
                'total_amount' => (float)($item['total_amount'] ?? 0),
                'expense_date' => $item['expense_date'] ?? date('Y-m-d'),
                'approval_status' => $item['approval_status'] ?? 'pending',
                'category_name' => $item['category_name'] ?? 'Uncategorized',
                'vendor_name' => $item['vendor_name'] ?? 'Unknown Vendor'
            ];
        }, $expense_list);
    } catch (Exception $e) {
        // Handle error gracefully
        $analytics['expense_list'] = [];
    }
    
    // Daily trend
    $stmt = $conn->prepare("
        SELECT 
            DATE(expense_date) as date,
            COUNT(*) as count,
            SUM(total_amount) as total
        FROM expenses 
        WHERE $where_clause
        GROUP BY DATE(expense_date)
        ORDER BY date
    ");
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $daily_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Handle null values in daily trend data
    $analytics['daily_trend'] = array_map(function($item) {
        return [
            'date' => $item['date'] ?? date('Y-m-d'),
            'count' => (int)($item['count'] ?? 0),
            'total' => (float)($item['total'] ?? 0)
        ];
    }, $daily_data);
    
    // Top vendors
    try {
        $stmt = $conn->prepare("
            SELECT 
                v.name as vendor_name,
                COUNT(*) as count,
                SUM(e.total_amount) as total
            FROM expenses e
            LEFT JOIN vendors v ON e.vendor_id = v.id
            WHERE $where_clause
            GROUP BY e.vendor_id, v.name
            ORDER BY total DESC
            LIMIT 10
        ");
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $vendor_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Handle null values in vendor data
        $analytics['top_vendors'] = array_map(function($item) {
            return [
                'vendor_name' => $item['vendor_name'] ?? 'Unknown Vendor',
                'count' => (int)($item['count'] ?? 0),
                'total' => (float)($item['total'] ?? 0)
            ];
        }, $vendor_data);
    } catch (Exception $e) {
        // Vendors table might not exist or have different structure
        $analytics['top_vendors'] = [];
    }
    
    return $analytics;
}

function getExpenseInsights($analytics) {
    $insights = [];
    
    $current_total = $analytics['totals']['total'];
    
    // If no expenses, return a helpful message
    if ($current_total == 0) {
        $insights[] = [
            'type' => 'info',
            'title' => 'No Expenses Found',
            'message' => 'No expenses found for the selected period. Consider expanding your date range or checking if expenses have been properly recorded.'
        ];
        return $insights;
    }
    
    // Category analysis
    if (!empty($analytics['by_category'])) {
        $top_category = $analytics['by_category'][0];
        $top_category_percent = ($top_category['total'] / $current_total) * 100;
        
        if ($top_category_percent > 50) {
            $insights[] = [
                'type' => 'info',
                'title' => 'Concentrated Spending',
                'message' => "{$top_category['category_name']} represents " . number_format($top_category_percent, 1) . "% of total expenses. Consider diversifying or optimizing this category."
            ];
        }
    }
    
    // Average expense analysis
    $avg_expense = $analytics['totals']['average'];
    if ($avg_expense > 1000) {
        $insights[] = [
            'type' => 'info',
            'title' => 'High Average Expense',
            'message' => "Average expense amount is " . number_format($avg_expense, 2) . ". Consider reviewing if all expenses are necessary."
        ];
    }
    
    return $insights;
}


// Get expense analytics data
$analytics = getExpenseAnalytics($conn, $start_date, $end_date, $category_filter, $department_filter);
$insights = getExpenseInsights($analytics);

// Get filter options
$categories = [];
$departments = [];

try {
    $stmt = $conn->query("SELECT id, name FROM expense_categories ORDER BY name");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Table might not exist, continue with empty array
    $categories = [];
}

try {
    $stmt = $conn->query("SELECT id, name FROM expense_departments ORDER BY name");
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Table might not exist, continue with empty array
    $departments = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expense Analytics - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .expense-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1rem;
        }
        
        .expense-card.total {
            background: linear-gradient(135deg, #fc466b 0%, #3f5efb 100%);
        }
        
        .expense-card.average {
            background: linear-gradient(135deg, #fdbb2d 0%, #22c1c3 100%);
        }
        
        .expense-card.count {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
        }
        
        .chart-container {
            position: relative;
            height: 400px;
            margin: 20px 0;
        }
        
        .filter-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .metric-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 1rem;
        }
        
        .category-item {
            border-left: 4px solid #28a745;
            padding: 1rem;
            margin-bottom: 0.5rem;
            background: #f8f9fa;
            border-radius: 0 8px 8px 0;
        }
        
        .vendor-item {
            border-left: 4px solid #007bff;
            padding: 1rem;
            margin-bottom: 0.5rem;
            background: #f8f9fa;
            border-radius: 0 8px 8px 0;
        }
        
        .status-badge {
            font-size: 0.75rem;
            padding: 0.375rem 0.75rem;
            border-radius: 0.375rem;
        }
        
        .table th {
            background-color: #f8f9fa;
            border-top: none;
            font-weight: 600;
            color: #495057;
        }
        
        .table td {
            vertical-align: middle;
        }
        
        .expense-amount {
            font-weight: 600;
            color: #dc3545;
        }
    </style>
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
                            <li class="breadcrumb-item active">Expense Analytics</li>
                        </ol>
                    </nav>
                    <h1><i class="bi bi-pie-chart"></i> Expense Analytics</h1>
                    <p class="header-subtitle">Detailed analysis of spending patterns and cost optimization</p>
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
                <!-- Filters -->
                <div class="filter-card">
                    <form method="GET" class="row g-3">
                        <div class="col-md-2">
                            <label for="start_date" class="form-label">Start Date</label>
                            <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo $start_date; ?>">
                        </div>
                        <div class="col-md-2">
                            <label for="end_date" class="form-label">End Date</label>
                            <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo $end_date; ?>">
                        </div>
                        <div class="col-md-2">
                            <label for="category" class="form-label">Category</label>
                            <select class="form-select" id="category" name="category">
                                <option value="all" <?php echo $category_filter === 'all' ? 'selected' : ''; ?>>All Categories</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo $category['id']; ?>" <?php echo $category_filter == $category['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($category['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="department" class="form-label">Department</label>
                            <select class="form-select" id="department" name="department">
                                <option value="all" <?php echo $department_filter === 'all' ? 'selected' : ''; ?>>All Departments</option>
                                <?php foreach ($departments as $department): ?>
                                    <option value="<?php echo $department['id']; ?>" <?php echo $department_filter == $department['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($department['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">&nbsp;</label>
                            <div>
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-search"></i> Update Analysis
                                </button>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">&nbsp;</label>
                            <div>
                                <a href="?start_date=<?php echo date('Y-m-01'); ?>&end_date=<?php echo date('Y-m-t'); ?>&category=all&department=all" class="btn btn-outline-secondary">
                                    <i class="bi bi-calendar-month"></i> This Month
                                </a>
                            </div>
                        </div>
                    </form>
                </div>


                <!-- Key Metrics -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="expense-card total">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Total Expenses</h6>
                                <i class="bi bi-cash-stack fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($analytics['totals']['total'], 2); ?></h3>
                            <small class="opacity-75"><?php echo $analytics['totals']['count']; ?> transactions</small>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="expense-card average">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Average Expense</h6>
                                <i class="bi bi-graph-up fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($analytics['totals']['average'], 2); ?></h3>
                            <small class="opacity-75">Per transaction</small>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="expense-card count">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Transaction Count</h6>
                                <i class="bi bi-receipt fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo number_format($analytics['totals']['count']); ?></h3>
                            <small class="opacity-75">Total transactions</small>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="expense-card">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Largest Expense</h6>
                                <i class="bi bi-arrow-up-circle fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($analytics['totals']['max_amount'], 2); ?></h3>
                            <small class="opacity-75">Single transaction</small>
                        </div>
                    </div>
                </div>

                <!-- Charts Section -->
                <div class="row mb-4">
                    <div class="col-lg-8">
                        <div class="metric-card">
                            <h5><i class="bi bi-graph-up"></i> Expense Trends</h5>
                            <div class="chart-container">
                                <canvas id="expenseTrendChart"></canvas>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="metric-card">
                            <h5><i class="bi bi-pie-chart"></i> Expenses by Category</h5>
                            <div class="chart-container">
                                <canvas id="categoryChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Detailed Analysis -->
                <div class="row mb-4">
                    <div class="col-lg-6">
                        <div class="metric-card">
                            <h5><i class="bi bi-tags"></i> Top Expense Categories</h5>
                            <div style="max-height: 400px; overflow-y: auto;">
                                <?php if (empty($analytics['by_category'])): ?>
                                    <p class="text-muted text-center py-3">No expense categories found</p>
                                <?php else: ?>
                                    <?php foreach ($analytics['by_category'] as $category): ?>
                                        <div class="category-item">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <strong><?php echo htmlspecialchars($category['category_name']); ?></strong>
                                                    <br>
                                                    <small class="text-muted"><?php echo $category['count']; ?> transactions</small>
                                                </div>
                                                <div class="text-end">
                                                    <strong class="text-primary"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($category['total'], 2); ?></strong>
                                                    <br>
                                                    <small class="text-muted">Avg: <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($category['average'], 2); ?></small>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-6">
                        <div class="metric-card">
                            <h5><i class="bi bi-building"></i> Top Vendors</h5>
                            <div style="max-height: 400px; overflow-y: auto;">
                                <?php if (empty($analytics['top_vendors'])): ?>
                                    <p class="text-muted text-center py-3">No vendor data found</p>
                                <?php else: ?>
                                    <?php foreach ($analytics['top_vendors'] as $vendor): ?>
                                        <div class="vendor-item">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <strong><?php echo htmlspecialchars($vendor['vendor_name'] ?: 'Unknown Vendor'); ?></strong>
                                                    <br>
                                                    <small class="text-muted"><?php echo $vendor['count']; ?> transactions</small>
                                                </div>
                                                <div class="text-end">
                                                    <strong class="text-primary"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($vendor['total'], 2); ?></strong>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Expense List with Approval Status -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="metric-card">
                            <h5><i class="bi bi-list-ul"></i> Recent Expenses</h5>
                            <div style="max-height: 500px; overflow-y: auto;">
                                <?php if (empty($analytics['expense_list'])): ?>
                                    <p class="text-muted text-center py-3">No expenses found for the selected period</p>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Title</th>
                                                    <th>Category</th>
                                                    <th>Vendor</th>
                                                    <th>Amount</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($analytics['expense_list'] as $expense): ?>
                                                    <tr>
                                                        <td><?php echo date('M d, Y', strtotime($expense['expense_date'])); ?></td>
                                                        <td>
                                                            <strong><?php echo htmlspecialchars($expense['title']); ?></strong>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-secondary"><?php echo htmlspecialchars($expense['category_name']); ?></span>
                                                        </td>
                                                        <td><?php echo htmlspecialchars($expense['vendor_name']); ?></td>
                                                        <td>
                                                            <span class="expense-amount">
                                                                <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($expense['total_amount'], 2); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <?php
                                                            $status = strtolower($expense['approval_status']);
                                                            $badge_class = '';
                                                            $status_text = '';
                                                            
                                                            switch ($status) {
                                                                case 'approved':
                                                                    $badge_class = 'bg-success';
                                                                    $status_text = 'Approved';
                                                                    break;
                                                                case 'pending':
                                                                    $badge_class = 'bg-warning';
                                                                    $status_text = 'Pending';
                                                                    break;
                                                                case 'rejected':
                                                                    $badge_class = 'bg-danger';
                                                                    $status_text = 'Rejected';
                                                                    break;
                                                                default:
                                                                    $badge_class = 'bg-secondary';
                                                                    $status_text = ucfirst($status);
                                                                    break;
                                                            }
                                                            ?>
                                                            <span class="badge status-badge <?php echo $badge_class; ?>">
                                                                <i class="bi bi-<?php echo $status === 'approved' ? 'check-circle' : ($status === 'pending' ? 'clock' : ($status === 'rejected' ? 'x-circle' : 'question-circle')); ?>"></i>
                                                                <?php echo $status_text; ?>
                                                            </span>
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

                <!-- Insights and Recommendations -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="metric-card">
                            <h5><i class="bi bi-lightbulb"></i> Insights & Recommendations</h5>
                            <div class="row">
                                <?php if (empty($insights)): ?>
                                    <div class="col-12">
                                        <div class="alert alert-info">
                                            <i class="bi bi-info-circle"></i> No specific insights available for the selected period. Continue monitoring your expenses for optimization opportunities.
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($insights as $insight): ?>
                                        <div class="col-md-6 mb-3">
                                            <div class="alert alert-<?php echo $insight['type'] === 'positive' ? 'success' : ($insight['type'] === 'warning' ? 'warning' : ($insight['type'] === 'danger' ? 'danger' : 'info')); ?>">
                                                <h6 class="alert-heading">
                                                    <i class="bi bi-<?php echo $insight['type'] === 'positive' ? 'check-circle' : ($insight['type'] === 'warning' ? 'exclamation-triangle' : ($insight['type'] === 'danger' ? 'x-circle' : 'info-circle')); ?>"></i>
                                                    <?php echo htmlspecialchars($insight['title']); ?>
                                                </h6>
                                                <p class="mb-0"><?php echo htmlspecialchars($insight['message']); ?></p>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="row mt-4">
                    <div class="col-12 text-center">
                        <a href="index.php" class="btn btn-outline-primary me-2">
                            <i class="bi bi-arrow-left"></i> Back to Finance Dashboard
                        </a>
                        <button class="btn btn-primary" onclick="exportExpenseAnalytics()">
                            <i class="bi bi-download"></i> Export Report
                        </button>
                        <button class="btn btn-success" onclick="printExpenseAnalytics()">
                            <i class="bi bi-printer"></i> Print Report
                        </button>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Chart data
        const dailyTrendData = <?php echo json_encode($analytics['daily_trend']); ?>;
        const categoryData = <?php echo json_encode($analytics['by_category']); ?>;
        
        // Expense Trends Chart
        const ctx1 = document.getElementById('expenseTrendChart').getContext('2d');
        
        if (dailyTrendData.length > 0) {
            new Chart(ctx1, {
                type: 'line',
                data: {
                    labels: dailyTrendData.map(item => new Date(item.date).toLocaleDateString()),
                    datasets: [{
                        label: 'Daily Expenses',
                        data: dailyTrendData.map(item => item.total),
                        borderColor: '#dc3545',
                        backgroundColor: 'rgba(220, 53, 69, 0.1)',
                        tension: 0.4,
                        fill: true
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
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return 'Expenses: <?php echo $settings['currency_symbol'] ?? 'KES'; ?> ' + context.parsed.y.toLocaleString();
                            }
                        }
                    }
                }
            }
        });
        } else {
            // Show message if no data
            ctx1.canvas.parentNode.innerHTML = '<div class="text-center py-5"><i class="bi bi-info-circle fs-1 text-muted"></i><p class="text-muted mt-3">No expense data available for the selected period</p></div>';
        }

        // Category Pie Chart
        const ctx2 = document.getElementById('categoryChart').getContext('2d');
        
        if (categoryData.length > 0) {
            new Chart(ctx2, {
                type: 'doughnut',
                data: {
                    labels: categoryData.map(item => item.category_name),
                    datasets: [{
                        data: categoryData.map(item => item.total),
                        backgroundColor: categoryData.map((item, index) => {
                            const colors = ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF', '#FF9F40'];
                            return colors[index % colors.length];
                        }),
                        borderWidth: 2
                    }]
                },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.label + ': <?php echo $settings['currency_symbol'] ?? 'KES'; ?> ' + context.parsed.toLocaleString();
                            }
                        }
                    }
                }
            }
        });
        } else {
            // Show message if no category data
            ctx2.canvas.parentNode.innerHTML = '<div class="text-center py-5"><i class="bi bi-info-circle fs-1 text-muted"></i><p class="text-muted mt-3">No category data available</p></div>';
        }

        // Export and Print Functions
        function exportExpenseAnalytics() {
            let csv = 'Date,Amount,Count\n';
            dailyTrendData.forEach(item => {
                csv += `${item.date},${item.total},${item.count}\n`;
            });
            
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'expense_analytics_<?php echo $start_date; ?>_to_<?php echo $end_date; ?>.csv';
            a.click();
            window.URL.revokeObjectURL(url);
        }

        function printExpenseAnalytics() {
            window.print();
        }
    </script>
</body>
</html>
