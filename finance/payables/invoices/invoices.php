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
$status_filter = $_GET['status'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;

// Build WHERE clause
$where = [];
$params = [];

// Only show received orders (invoices)
$where[] = "io.status = 'received'";

if (!empty($search)) {
    $where[] = "(io.order_number LIKE :search OR s.name LIKE :search OR io.notes LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if (!empty($supplier_filter)) {
    $where[] = "io.supplier_id = :supplier_id";
    $params[':supplier_id'] = $supplier_filter;
}

if (!empty($status_filter)) {
    // Map status filter to payment status logic
    switch ($status_filter) {
        case 'paid':
            $where[] = "io.paid_amount >= io.total_amount";
            break;
        case 'partial':
            $where[] = "io.paid_amount > 0 AND io.paid_amount < io.total_amount";
            break;
        case 'overdue':
            $where[] = "DATEDIFF(CURDATE(), io.received_date) > 30 AND (io.paid_amount IS NULL OR io.paid_amount < io.total_amount)";
            break;
        case 'pending':
        default:
            $where[] = "(io.paid_amount IS NULL OR io.paid_amount = 0)";
            break;
    }
}

if (!empty($date_from)) {
    $where[] = "io.received_date >= :date_from";
    $params[':date_from'] = $date_from;
}

if (!empty($date_to)) {
    $where[] = "io.received_date <= :date_to";
    $params[':date_to'] = $date_to;
}

$where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Get total count
$count_sql = "
    SELECT COUNT(*) as total
    FROM inventory_orders io
    LEFT JOIN suppliers s ON io.supplier_id = s.id
    $where_clause
";

$stmt = $conn->prepare($count_sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$total_invoices = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_invoices / $per_page);

// Get invoices with pagination
$offset = ($page - 1) * $per_page;
$sql = "
    SELECT 
        io.*,
        s.name as supplier_name,
        s.contact_person,
        u.username as created_by_name,
        DATEDIFF(CURDATE(), io.received_date) as days_overdue,
        COALESCE(io.paid_amount, 0) as paid_amount,
        (io.total_amount - COALESCE(io.paid_amount, 0)) as balance_due,
        COALESCE(io.invoice_number, io.order_number) as display_number
    FROM inventory_orders io
    LEFT JOIN suppliers s ON io.supplier_id = s.id
    LEFT JOIN users u ON io.user_id = u.id
    $where_clause
    ORDER BY io.received_date DESC, io.created_at DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $conn->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get suppliers for filter dropdown
$suppliers = [];
$stmt = $conn->query("SELECT id, name FROM suppliers WHERE is_active = 1 ORDER BY name");
$suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get invoice statistics
$stats = [];
$stmt = $conn->query("
    SELECT 
        COUNT(*) as total_invoices,
        COALESCE(SUM(total_amount), 0) as total_amount,
        COALESCE(SUM(total_amount - COALESCE(paid_amount, 0)), 0) as outstanding_amount,
        COUNT(CASE WHEN DATEDIFF(CURDATE(), received_date) > 30 AND (paid_amount IS NULL OR paid_amount < total_amount) THEN 1 END) as overdue_count
    FROM inventory_orders
    WHERE status = 'received'
");
$result = $stmt->fetch(PDO::FETCH_ASSOC);
$stats = $result;

// Handle messages
$message = '';
$message_type = '';

$page_title = "Supplier Invoices";
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
                        <h1 class="mb-0"><i class="mdi mdi-file-document me-2"></i>Supplier Invoices</h1>
                    </div>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb" style="background: rgba(255,255,255,0.1); padding: 0.5rem 1rem; border-radius: 0.375rem;">
                            <li class="breadcrumb-item"><a href="../../../dashboard/dashboard.php" style="color: rgba(255,255,255,0.8);">Dashboard</a></li>
                            <li class="breadcrumb-item"><a href="../../index.php" style="color: rgba(255,255,255,0.8);">Finance</a></li>
                            <li class="breadcrumb-item"><a href="../payables.php" style="color: rgba(255,255,255,0.8);">Payables</a></li>
                            <li class="breadcrumb-item active" style="color: white;">Invoices</li>
                        </ol>
                    </nav>
                    <p class="header-subtitle mb-0" style="color: rgba(255,255,255,0.9);">Manage supplier invoices and payment status</p>
                </div>
            </div>
        </header>

        <main class="content">

    <!-- Statistics Cards -->
    <div class="row">
        <div class="col-xl-3 col-md-6">
            <div class="card">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-6">
                            <h5 class="text-muted fw-normal mt-0 text-truncate">Total Invoices</h5>
                            <h3 class="my-2 py-1"><?php echo number_format($stats['total_invoices']); ?></h3>
                        </div>
                        <div class="col-6">
                            <div class="text-end">
                                <i class="mdi mdi-file-document-outline text-primary" style="font-size: 3rem;"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
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

        <div class="col-xl-3 col-md-6">
            <div class="card">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-6">
                            <h5 class="text-muted fw-normal mt-0 text-truncate">Outstanding</h5>
                            <h3 class="my-2 py-1"><?php echo formatCurrency($stats['outstanding_amount'], $settings); ?></h3>
                        </div>
                        <div class="col-6">
                            <div class="text-end">
                                <i class="mdi mdi-alert-circle-outline text-warning" style="font-size: 3rem;"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="card">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-6">
                            <h5 class="text-muted fw-normal mt-0 text-truncate">Overdue</h5>
                            <h3 class="my-2 py-1 text-danger"><?php echo number_format($stats['overdue_count']); ?></h3>
                        </div>
                        <div class="col-6">
                            <div class="text-end">
                                <i class="mdi mdi-alert text-danger" style="font-size: 3rem;"></i>
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
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label for="search" class="form-label">Search</label>
                            <input type="text" class="form-control" id="search" name="search" 
                                   value="<?php echo htmlspecialchars($search); ?>" 
                                   placeholder="Invoice #, Supplier, Notes...">
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
                            <label for="status" class="form-label">Status</label>
                            <select class="form-select" id="status" name="status">
                                <option value="">All Status</option>
                                <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="partial" <?php echo $status_filter == 'partial' ? 'selected' : ''; ?>>Partial</option>
                                <option value="paid" <?php echo $status_filter == 'paid' ? 'selected' : ''; ?>>Paid</option>
                                <option value="overdue" <?php echo $status_filter == 'overdue' ? 'selected' : ''; ?>>Overdue</option>
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

    <!-- Invoices Table -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h4 class="header-title">Invoices (<?php echo $total_invoices; ?>)</h4>
                    </div>

                    <?php if ($message): ?>
                        <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                            <?php echo htmlspecialchars($message); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>Invoice #</th>
                                    <th>Supplier</th>
                                    <th>Invoice Date</th>
                                    <th>Due Date</th>
                                    <th>Amount</th>
                                    <th>Paid</th>
                                    <th>Balance</th>
                                    <th>Status</th>
                                    <th width="200">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                    <?php if (empty($invoices)): ?>
                                        <tr>
                                            <td colspan="9" class="text-center text-muted">No invoices found</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($invoices as $invoice): ?>
                                            <tr>
                                                <td>
                                                    <a href="../../../inventory/view_order.php?id=<?php echo $invoice['id']; ?>" class="text-primary">
                                                        <?php echo htmlspecialchars($invoice['display_number']); ?>
                                                    </a>
                                                </td>
                                                <td><?php echo htmlspecialchars($invoice['supplier_name']); ?></td>
                                                <td><?php echo date('M d, Y', strtotime($invoice['received_date'])); ?></td>
                                                <td>
                                                    <?php echo date('M d, Y', strtotime($invoice['received_date'] . ' +30 days')); ?>
                                                    <?php if ($invoice['days_overdue'] > 30): ?>
                                                        <small class="text-danger">(<?php echo $invoice['days_overdue'] - 30; ?> days overdue)</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="fw-bold"><?php echo formatCurrency($invoice['total_amount'], $settings); ?></td>
                                                <td><?php echo formatCurrency($invoice['paid_amount'], $settings); ?></td>
                                                <td class="fw-bold"><?php echo formatCurrency($invoice['balance_due'], $settings); ?></td>
                                                <td>
                                                    <?php 
                                                    $status = 'pending';
                                                    if ($invoice['paid_amount'] >= $invoice['total_amount']) {
                                                        $status = 'paid';
                                                    } elseif ($invoice['paid_amount'] > 0) {
                                                        $status = 'partial';
                                                    } elseif ($invoice['days_overdue'] > 30) {
                                                        $status = 'overdue';
                                                    }
                                                    ?>
                                                    <span class="badge bg-<?php 
                                                        echo $status == 'paid' ? 'success' : 
                                                            ($status == 'overdue' ? 'danger' : 
                                                            ($status == 'partial' ? 'warning' : 'secondary')); 
                                                    ?>">
                                                        <?php echo ucfirst($status); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="d-flex gap-2">
                                                        <a href="../../../inventory/view_order.php?id=<?php echo $invoice['id']; ?>" 
                                                           class="btn btn-primary btn-sm" 
                                                           title="View Invoice">
                                                            <i class="mdi mdi-eye me-1"></i>View
                                                        </a>
                                                        <?php if ($status != 'paid'): ?>
                                                        <a href="../payments/add_payment.php?invoice_id=<?php echo $invoice['id']; ?>" 
                                                           class="btn btn-success btn-sm" 
                                                           title="Record Payment">
                                                            <i class="mdi mdi-credit-card me-1"></i>Pay
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
                        <nav aria-label="Invoice pagination">
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
    <script>
// No JavaScript needed for individual actions
    </script>
</body>
</html>
