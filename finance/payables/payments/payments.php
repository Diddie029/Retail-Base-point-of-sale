<?php
session_start();
require_once __DIR__ . '/../../../include/db.php';
require_once __DIR__ . '/../../../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$user_role = $_SESSION['role_name'] ?? 'User';

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Handle filters and search
$search = $_GET['search'] ?? '';
$supplier_filter = $_GET['supplier'] ?? '';
$method_filter = $_GET['method'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;

// Build WHERE clause
$where = [];
$params = [];

if (!empty($search)) {
    $where[] = "(sp.payment_number LIKE :search OR s.name LIKE :search OR sp.reference_number LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if (!empty($supplier_filter)) {
    $where[] = "sp.supplier_id = :supplier_id";
    $params[':supplier_id'] = $supplier_filter;
}

if (!empty($method_filter)) {
    $where[] = "sp.payment_method = :method";
    $params[':method'] = $method_filter;
}

if (!empty($date_from)) {
    $where[] = "sp.payment_date >= :date_from";
    $params[':date_from'] = $date_from;
}

if (!empty($date_to)) {
    $where[] = "sp.payment_date <= :date_to";
    $params[':date_to'] = $date_to;
}

$where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Get total count
$count_sql = "
    SELECT COUNT(*) as total
    FROM supplier_payments sp
    LEFT JOIN suppliers s ON sp.supplier_id = s.id
    $where_clause
";

$stmt = $conn->prepare($count_sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$total_payments = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_payments / $per_page);

// Get payments with pagination
$offset = ($page - 1) * $per_page;
$sql = "
    SELECT 
        sp.*,
        s.name as supplier_name,
        si.invoice_number,
        u.username as created_by_name
    FROM supplier_payments sp
    LEFT JOIN suppliers s ON sp.supplier_id = s.id
    LEFT JOIN supplier_invoices si ON sp.invoice_id = si.id
    LEFT JOIN users u ON sp.created_by = u.id
    $where_clause
    ORDER BY sp.payment_date DESC, sp.created_at DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $conn->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get suppliers for filter dropdown
$suppliers = [];
$stmt = $conn->query("SELECT id, name FROM suppliers WHERE is_active = 1 ORDER BY name");
$suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get payment statistics
$stats = [];
$stmt = $conn->query("
    SELECT 
        COUNT(*) as total_payments,
        COALESCE(SUM(payment_amount), 0) as total_amount,
        COALESCE(SUM(CASE WHEN payment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN payment_amount ELSE 0 END), 0) as recent_amount
    FROM supplier_payments
    WHERE status = 'completed'
");
$result = $stmt->fetch(PDO::FETCH_ASSOC);
$stats = $result;

$page_title = "Supplier Payments";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
    </style>
</head>
<body>
    <?php include '../../../include/navmenu.php'; ?>

    <div class="main-content">
        <header class="header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
            <div class="header-content">
                <div class="header-title">
                    <div class="d-flex align-items-center mb-2">
                        <a href="../payables.php" class="btn btn-outline-light btn-sm me-3">
                            <i class="mdi mdi-arrow-left me-1"></i>Back to Payables
                        </a>
                        <h1 class="mb-0"><i class="mdi mdi-credit-card me-2"></i>Supplier Payments</h1>
                    </div>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb" style="background: rgba(255,255,255,0.1); padding: 0.5rem 1rem; border-radius: 0.375rem;">
                            <li class="breadcrumb-item"><a href="../../../dashboard/dashboard.php" style="color: rgba(255,255,255,0.8);">Dashboard</a></li>
                            <li class="breadcrumb-item"><a href="../../index.php" style="color: rgba(255,255,255,0.8);">Finance</a></li>
                            <li class="breadcrumb-item"><a href="../payables.php" style="color: rgba(255,255,255,0.8);">Payables</a></li>
                            <li class="breadcrumb-item active" style="color: white;">Payments</li>
                        </ol>
                    </nav>
                    <p class="header-subtitle mb-0" style="color: rgba(255,255,255,0.9);">View and manage supplier payment history</p>
                </div>
            </div>
        </header>

        <main class="content">

    <!-- Statistics Cards -->
    <div class="row">
        <div class="col-xl-4 col-md-6">
            <div class="card">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-6">
                            <h5 class="text-muted fw-normal mt-0 text-truncate">Total Payments</h5>
                            <h3 class="my-2 py-1"><?php echo number_format($stats['total_payments']); ?></h3>
                        </div>
                        <div class="col-6">
                            <div class="text-end">
                                <i class="mdi mdi-credit-card text-primary" style="font-size: 3rem;"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4 col-md-6">
            <div class="card">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-6">
                            <h5 class="text-muted fw-normal mt-0 text-truncate">Total Amount</h5>
                            <h3 class="my-2 py-1"><?php echo formatCurrency($stats['total_amount'], $settings); ?></h3>
                        </div>
                        <div class="col-6">
                            <div class="text-end">
                                <i class="mdi mdi-currency-usd text-success" style="font-size: 3rem;"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4 col-md-6">
            <div class="card">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-6">
                            <h5 class="text-muted fw-normal mt-0 text-truncate">Last 30 Days</h5>
                            <h3 class="my-2 py-1"><?php echo formatCurrency($stats['recent_amount'], $settings); ?></h3>
                        </div>
                        <div class="col-6">
                            <div class="text-end">
                                <i class="mdi mdi-chart-line text-info" style="font-size: 3rem;"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters and Actions -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h4 class="header-title">Payment History</h4>
                        <a href="add_payment.php" class="btn btn-primary">
                            <i class="mdi mdi-plus"></i> Record Payment
                        </a>
                    </div>
                    
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label for="search" class="form-label">Search</label>
                            <input type="text" class="form-control" id="search" name="search" 
                                   value="<?php echo htmlspecialchars($search); ?>" 
                                   placeholder="Payment #, Supplier, Reference...">
                        </div>
                        <div class="col-md-2">
                            <label for="supplier" class="form-label">Supplier</label>
                            <select class="form-select" id="supplier" name="supplier">
                                <option value="">All Suppliers</option>
                                <?php foreach ($suppliers as $supplier): ?>
                                    <option value="<?php echo $supplier['id']; ?>" 
                                            <?php echo $supplier_filter == $supplier['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($supplier['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="method" class="form-label">Method</label>
                            <select class="form-select" id="method" name="method">
                                <option value="">All Methods</option>
                                <option value="cash" <?php echo $method_filter == 'cash' ? 'selected' : ''; ?>>Cash</option>
                                <option value="check" <?php echo $method_filter == 'check' ? 'selected' : ''; ?>>Check</option>
                                <option value="bank_transfer" <?php echo $method_filter == 'bank_transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                                <option value="credit_card" <?php echo $method_filter == 'credit_card' ? 'selected' : ''; ?>>Credit Card</option>
                                <option value="other" <?php echo $method_filter == 'other' ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="date_from" class="form-label">From Date</label>
                            <input type="date" class="form-control" id="date_from" name="date_from" 
                                   value="<?php echo htmlspecialchars($date_from); ?>">
                        </div>
                        <div class="col-md-2">
                            <label for="date_to" class="form-label">To Date</label>
                            <input type="date" class="form-control" id="date_to" name="date_to" 
                                   value="<?php echo htmlspecialchars($date_to); ?>">
                        </div>
                        <div class="col-md-1">
                            <label class="form-label">&nbsp;</label>
                            <button type="submit" class="btn btn-primary d-block w-100">Filter</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Payments Table -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>Payment #</th>
                                    <th>Supplier</th>
                                    <th>Invoice</th>
                                    <th>Date</th>
                                    <th>Method</th>
                                    <th>Amount</th>
                                    <th>Reference</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($payments)): ?>
                                    <tr>
                                        <td colspan="9" class="text-center text-muted">No payments found</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($payments as $payment): ?>
                                        <tr>
                                            <td>
                                                <span class="text-primary fw-bold">
                                                    <?php echo htmlspecialchars($payment['payment_number']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($payment['supplier_name']); ?></td>
                                            <td>
                                                <?php if ($payment['invoice_number']): ?>
                                                    <a href="../invoices/view_invoice.php?id=<?php echo $payment['invoice_id']; ?>" class="text-info">
                                                        <?php echo htmlspecialchars($payment['invoice_number']); ?>
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-muted">General Payment</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo date('M d, Y', strtotime($payment['payment_date'])); ?></td>
                                            <td>
                                                <span class="badge bg-<?php 
                                                    echo $payment['payment_method'] == 'cash' ? 'success' : 
                                                        ($payment['payment_method'] == 'bank_transfer' ? 'primary' : 
                                                        ($payment['payment_method'] == 'check' ? 'warning' : 'secondary')); 
                                                ?>">
                                                    <?php echo ucfirst(str_replace('_', ' ', $payment['payment_method'])); ?>
                                                </span>
                                            </td>
                                            <td class="fw-bold"><?php echo formatCurrency($payment['payment_amount'], $settings); ?></td>
                                            <td><?php echo htmlspecialchars($payment['reference_number']); ?></td>
                                            <td>
                                                <span class="badge bg-<?php 
                                                    echo $payment['status'] == 'completed' ? 'success' : 
                                                        ($payment['status'] == 'pending' ? 'warning' : 'danger'); 
                                                ?>">
                                                    <?php echo ucfirst($payment['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <a href="view_payment.php?id=<?php echo $payment['id']; ?>" 
                                                       class="btn btn-outline-info btn-sm" 
                                                       title="View Payment">
                                                        <i class="bi bi-eye"></i>
                                                    </a>
                                                    <?php if ($payment['status'] == 'pending'): ?>
                                                        <a href="edit_payment.php?id=<?php echo $payment['id']; ?>" 
                                                           class="btn btn-outline-warning btn-sm"
                                                           title="Edit Payment">
                                                            <i class="bi bi-pencil"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <nav aria-label="Payment pagination">
                            <ul class="pagination justify-content-center">
                                <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">Previous</a>
                                    </li>
                                <?php endif; ?>
                                
                                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                    <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>
                                
                                <?php if ($page < $total_pages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">Next</a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
