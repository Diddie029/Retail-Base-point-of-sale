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
$date_from = $_GET['date_from'] ?? date('Y-m-01'); // First day of current month
$date_to = $_GET['date_to'] ?? date('Y-m-d'); // Today
$cashier_id = $_GET['cashier_id'] ?? '';
$void_type = $_GET['void_type'] ?? '';

// Build the query
$where_conditions = ["DATE(vt.voided_at) BETWEEN ? AND ?"];
$params = [$date_from, $date_to];

if (!empty($cashier_id)) {
    $where_conditions[] = "vt.user_id = ?";
    $params[] = $cashier_id;
}

if (!empty($void_type)) {
    $where_conditions[] = "vt.void_type = ?";
    $params[] = $void_type;
}

$where_clause = implode(' AND ', $where_conditions);

// Get void transactions
$stmt = $conn->prepare("
    SELECT vt.*, u.username as cashier_name, rt.till_name, rt.till_code
    FROM void_transactions vt
    LEFT JOIN users u ON vt.user_id = u.id
    LEFT JOIN register_tills rt ON vt.till_id = rt.id
    WHERE $where_clause
    ORDER BY vt.voided_at DESC
");
$stmt->execute($params);
$void_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get summary statistics
$summary_stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_voids,
        COUNT(DISTINCT vt.user_id) as cashiers_involved,
        SUM(vt.total_amount) as total_voided_amount,
        AVG(vt.total_amount) as avg_void_amount,
    COUNT(CASE WHEN vt.void_type = 'cart' THEN 1 END) as cart_voids,
    COUNT(CASE WHEN vt.void_type = 'product' THEN 1 END) as product_voids,
    COUNT(CASE WHEN vt.void_type = 'held_transaction' THEN 1 END) as held_transaction_voids
    FROM void_transactions vt
    WHERE $where_clause
");
$summary_stmt->execute($params);
$summary = $summary_stmt->fetch(PDO::FETCH_ASSOC);

// Get cashiers for filter dropdown
$cashiers_stmt = $conn->query("
    SELECT DISTINCT u.id, u.username 
    FROM void_transactions vt
    JOIN users u ON vt.user_id = u.id
    ORDER BY u.username
");
$cashiers = $cashiers_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get void types for filter dropdown
$void_types = [
    'cart' => 'Cart Void',
    'product' => 'Product Void'
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Void Transactions Report - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
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
        
        .table-responsive {
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .table thead th {
            background: var(--primary-color);
            color: white;
            border: none;
            font-weight: 600;
        }
        
        .table tbody tr:hover {
            background-color: #f8f9fa;
        }
        
        .void-reason {
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .void-reason:hover {
            white-space: normal;
            overflow: visible;
        }
        
        .badge-void-type {
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
                    <h1><i class="bi bi-x-circle"></i> Void Transactions Report</h1>
                    <p class="header-subtitle">Detailed analysis of voided transactions and cashier accountability</p>
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
                        <div class="summary-card">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Total Voids</h6>
                                <i class="bi bi-x-circle fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo number_format($summary['total_voids']); ?></h3>
                            <small class="opacity-75">Voided transactions</small>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="summary-card danger">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Total Amount</h6>
                                <i class="bi bi-currency-dollar fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($summary['total_voided_amount'], 2); ?></h3>
                            <small class="opacity-75">Voided amount</small>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="summary-card warning">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Cashiers Involved</h6>
                                <i class="bi bi-people fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo number_format($summary['cashiers_involved']); ?></h3>
                            <small class="opacity-75">Different cashiers</small>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="summary-card info">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Avg Void Amount</h6>
                                <i class="bi bi-graph-up fs-4"></i>
                            </div>
                            <h3 class="mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($summary['avg_void_amount'], 2); ?></h3>
                            <small class="opacity-75">Per transaction</small>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="filter-card">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label for="date_from" class="form-label">From Date</label>
                            <input type="date" class="form-control" id="date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                        </div>
                        <div class="col-md-3">
                            <label for="date_to" class="form-label">To Date</label>
                            <input type="date" class="form-control" id="date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                        </div>
                        <div class="col-md-3">
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
                        <div class="col-md-3">
                            <label for="void_type" class="form-label">Void Type</label>
                            <select class="form-select" id="void_type" name="void_type">
                                <option value="">All Types</option>
                                <?php foreach ($void_types as $type => $label): ?>
                                <option value="<?php echo $type; ?>" <?php echo $void_type == $type ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($label); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-search"></i> Filter
                                </button>
                                <a href="void_transactions_report.php" class="btn btn-outline-secondary">
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

                <!-- Void Transactions Table -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="bi bi-list-ul"></i> Void Transactions
                            <span class="badge bg-primary ms-2"><?php echo count($void_transactions); ?> records</span>
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Date & Time</th>
                                        <th>Cashier</th>
                                        <th>Till</th>
                                        <th>Type</th>
                                        <th>Product</th>
                                        <th>Qty</th>
                                        <th>Unit Price</th>
                                        <th>Total Amount</th>
                                        <th>Reason</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($void_transactions)): ?>
                                    <tr>
                                        <td colspan="10" class="text-center py-4 text-muted">
                                            <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                            No void transactions found for the selected criteria
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach ($void_transactions as $void): ?>
                                    <tr>
                                        <td><strong>#<?php echo $void['id']; ?></strong></td>
                                        <td>
                                            <div><?php echo date('M d, Y', strtotime($void['voided_at'])); ?></div>
                                            <small class="text-muted"><?php echo date('H:i:s', strtotime($void['voided_at'])); ?></small>
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($void['cashier_name'] ?? 'Unknown'); ?></div>
                                        </td>
                                        <td>
                                            <div><?php echo htmlspecialchars($void['till_name'] ?? 'N/A'); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($void['till_code'] ?? ''); ?></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $void['void_type'] == 'cart' ? 'danger' : 'warning'; ?> badge-void-type">
                                                <?php echo htmlspecialchars($void_types[$void['void_type']] ?? ucfirst($void['void_type'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($void['product_name']); ?></div>
                                            <small class="text-muted">ID: <?php echo $void['product_id']; ?></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary"><?php echo $void['quantity']; ?></span>
                                        </td>
                                        <td><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($void['unit_price'], 2); ?></td>
                                        <td>
                                            <strong class="text-danger"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($void['total_amount'], 2); ?></strong>
                                        </td>
                                        <td>
                                            <div class="void-reason" title="<?php echo htmlspecialchars($void['void_reason']); ?>">
                                                <?php echo htmlspecialchars($void['void_reason']); ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
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
            link.href = 'export_void_transactions.php?' + params.toString();
            link.download = 'void_transactions_' + new Date().toISOString().split('T')[0] + '.csv';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
        
        function exportToPDF() {
            // Get current filter parameters
            const params = new URLSearchParams(window.location.search);
            params.set('export', 'pdf');
            
            // Open PDF in new window
            window.open('export_void_transactions.php?' + params.toString(), '_blank');
        }
    </script>
</body>
</html>
