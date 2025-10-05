<?php
session_start();
require_once __DIR__ . '/../../include/db.php';
require_once __DIR__ . '/../../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../auth/login.php");
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

// Get payables summary statistics
$summary = [];

// Total outstanding invoices (from inventory_orders where status = 'received')
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_invoices,
        COALESCE(SUM(total_amount), 0) as total_outstanding,
        COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), received_date) > 30 THEN total_amount ELSE 0 END), 0) as overdue_amount,
        COUNT(CASE WHEN DATEDIFF(CURDATE(), received_date) > 30 THEN 1 END) as overdue_count
    FROM inventory_orders 
    WHERE status = 'received' 
    AND (paid_amount IS NULL OR paid_amount < total_amount)
");
$stmt->execute();
$summary['outstanding'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total_invoices' => 0, 'total_outstanding' => 0, 'overdue_amount' => 0, 'overdue_count' => 0];

// Recent invoices (last 30 days)
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as recent_invoices,
        COALESCE(SUM(total_amount), 0) as recent_amount
    FROM inventory_orders 
    WHERE status = 'received' 
    AND received_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
");
$stmt->execute();
$summary['recent'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['recent_invoices' => 0, 'recent_amount' => 0];

// Recent payments (last 30 days)
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as recent_payments,
        COALESCE(SUM(payment_amount), 0) as recent_payment_amount
    FROM supplier_payments 
    WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    AND status = 'completed'
");
$stmt->execute();
$summary['payments'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['recent_payments' => 0, 'recent_payment_amount' => 0];

// Available credits
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_credits,
        COALESCE(SUM(available_amount), 0) as total_amount
    FROM supplier_credits 
    WHERE status IN ('available', 'partially_applied')
");
$stmt->execute();
$summary['credits'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total_credits' => 0, 'total_amount' => 0];

// Get recent invoices for dashboard
$stmt = $conn->prepare("
    SELECT 
        io.*,
        s.name as supplier_name,
        u.username as created_by_name,
        COALESCE(io.paid_amount, 0) as paid_amount,
        (io.total_amount - COALESCE(io.paid_amount, 0)) as balance_due,
        COALESCE(io.invoice_number, io.order_number) as display_number
    FROM inventory_orders io
    LEFT JOIN suppliers s ON io.supplier_id = s.id
    LEFT JOIN users u ON io.user_id = u.id
    WHERE io.status = 'received'
    ORDER BY io.received_date DESC, io.created_at DESC
    LIMIT 10
");
$stmt->execute();
$recent_invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get overdue invoices
$stmt = $conn->prepare("
    SELECT 
        io.*,
        s.name as supplier_name,
        DATEDIFF(CURDATE(), io.received_date) as days_overdue,
        COALESCE(io.paid_amount, 0) as paid_amount,
        (io.total_amount - COALESCE(io.paid_amount, 0)) as balance_due,
        COALESCE(io.invoice_number, io.order_number) as display_number
    FROM inventory_orders io
    LEFT JOIN suppliers s ON io.supplier_id = s.id
    WHERE io.status = 'received' 
    AND DATEDIFF(CURDATE(), io.received_date) > 30
    AND (io.paid_amount IS NULL OR io.paid_amount < io.total_amount)
    ORDER BY io.received_date ASC
    LIMIT 5
");
$stmt->execute();
$overdue_invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get suppliers with outstanding balances
$stmt = $conn->prepare("
    SELECT 
        s.id,
        s.name,
        COUNT(io.id) as invoice_count,
        COALESCE(SUM(io.total_amount - COALESCE(io.paid_amount, 0)), 0) as total_outstanding
    FROM suppliers s
    LEFT JOIN inventory_orders io ON s.id = io.supplier_id 
        AND io.status = 'received'
        AND (io.paid_amount IS NULL OR io.paid_amount < io.total_amount)
    GROUP BY s.id, s.name
    HAVING total_outstanding > 0
    ORDER BY total_outstanding DESC
    LIMIT 5
");
$stmt->execute();
$suppliers_outstanding = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Payables Management";
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
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
        
        .card {
            transition: all 0.3s ease;
        }
        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1) !important;
        }
        .btn {
            transition: all 0.3s ease;
        }
        .btn:hover {
            transform: translateY(-1px);
        }
        .space-y-3 > * + * {
            margin-top: 1rem;
        }
        .bg-gradient-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .bg-gradient-success {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
        }
        .bg-gradient-danger {
            background: linear-gradient(135deg, #ff416c 0%, #ff4b2b 100%);
        }
        .bg-gradient-info {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .text-gradient {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
    </style>
</head>
<body>
    <?php include '../../include/navmenu.php'; ?>

    <div class="main-content">
        <header class="header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
            <div class="header-content">
                <div class="header-title">
                    <div class="d-flex align-items-center mb-2">
                        <a href="../index.php" class="btn btn-outline-light btn-sm me-3">
                            <i class="mdi mdi-arrow-left me-1"></i>Back to Finance
                        </a>
                        <h1 class="mb-0"><i class="mdi mdi-credit-card me-2"></i>Payables Management</h1>
                    </div>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb" style="background: rgba(255,255,255,0.1); padding: 0.5rem 1rem; border-radius: 0.375rem;">
                            <li class="breadcrumb-item"><a href="../../dashboard/dashboard.php" style="color: rgba(255,255,255,0.8);">Dashboard</a></li>
                            <li class="breadcrumb-item"><a href="../index.php" style="color: rgba(255,255,255,0.8);">Finance</a></li>
                            <li class="breadcrumb-item active" style="color: white;">Payables</li>
                        </ol>
                    </nav>
                    <p class="header-subtitle mb-0" style="color: rgba(255,255,255,0.9);">Manage supplier invoices, payments, and credits</p>
                </div>
            </div>
        </header>

        <main class="content">

    <!-- Enhanced Dashboard Cards -->
    <div class="row mb-4">
        <!-- Outstanding Invoices Card -->
        <div class="col-xl-3 col-lg-6 col-md-6 mb-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                            <h6 class="text-muted fw-semibold text-uppercase mb-1">Outstanding Invoices</h6>
                            <h2 class="fw-bold text-primary mb-0"><?php echo number_format($summary['outstanding']['total_invoices'] ?? 0); ?></h2>
                        </div>
                        <div class="bg-primary bg-opacity-10 rounded-circle p-3">
                            <i class="mdi mdi-credit-card text-primary" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-muted small">Total Amount</span>
                        <span class="fw-semibold text-primary"><?php echo formatCurrency($summary['outstanding']['total_outstanding'] ?? 0, $settings); ?></span>
                    </div>
                    <div class="mt-3">
                        <a href="invoices/invoices.php?status=pending" class="btn btn-sm btn-outline-primary">View Details</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Overdue Invoices Card -->
        <div class="col-xl-3 col-lg-6 col-md-6 mb-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                            <h6 class="text-muted fw-semibold text-uppercase mb-1">Overdue Invoices</h6>
                            <h2 class="fw-bold text-danger mb-0"><?php echo number_format($summary['outstanding']['overdue_count'] ?? 0); ?></h2>
                        </div>
                        <div class="bg-danger bg-opacity-10 rounded-circle p-3">
                            <i class="mdi mdi-alert-circle text-danger" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-muted small">Overdue Amount</span>
                        <span class="fw-semibold text-danger"><?php echo formatCurrency($summary['outstanding']['overdue_amount'] ?? 0, $settings); ?></span>
                    </div>
                    <div class="mt-3">
                        <a href="invoices/invoices.php?status=overdue" class="btn btn-sm btn-outline-danger">View Details</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Invoices Card -->
        <div class="col-xl-3 col-lg-6 col-md-6 mb-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                            <h6 class="text-muted fw-semibold text-uppercase mb-1">Recent Invoices</h6>
                            <h2 class="fw-bold text-info mb-0"><?php echo number_format($summary['recent']['recent_invoices'] ?? 0); ?></h2>
                        </div>
                        <div class="bg-info bg-opacity-10 rounded-circle p-3">
                            <i class="mdi mdi-calendar-clock text-info" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-muted small">Last 30 Days</span>
                        <span class="fw-semibold text-info"><?php echo formatCurrency($summary['recent']['recent_amount'] ?? 0, $settings); ?></span>
                    </div>
                    <div class="mt-3">
                        <a href="invoices/invoices.php" class="btn btn-sm btn-outline-info">View All</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Available Credits Card -->
        <div class="col-xl-3 col-lg-6 col-md-6 mb-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                            <h6 class="text-muted fw-semibold text-uppercase mb-1">Available Credits</h6>
                            <h2 class="fw-bold text-success mb-0"><?php echo number_format($summary['credits']['total_credits'] ?? 0); ?></h2>
                        </div>
                        <div class="bg-success bg-opacity-10 rounded-circle p-3">
                            <i class="mdi mdi-account-plus text-success" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-muted small">Credit Amount</span>
                        <span class="fw-semibold text-success"><?php echo formatCurrency($summary['credits']['total_amount'] ?? 0, $settings); ?></span>
                    </div>
                    <div class="mt-3">
                        <a href="credits/credits.php" class="btn btn-sm btn-outline-success">View Credits</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Actions Row -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">
                    <h5 class="card-title mb-4 d-flex align-items-center">
                        <i class="bi bi-lightning-fill text-warning me-2"></i>
                        Quick Actions
                    </h5>
                    <div class="row g-3">
                        <!-- Record Payment -->
                        <div class="col-lg-3 col-md-6">
                            <a href="payments/add_payment.php" class="btn btn-success w-100 py-3 d-flex align-items-center justify-content-center text-decoration-none">
                                <i class="bi bi-credit-card me-2" style="font-size: 1.2rem;"></i>
                                <div class="text-start">
                                    <div class="fw-semibold">Record Payment</div>
                                    <small class="opacity-75">Add new payment</small>
                                </div>
                            </a>
                        </div>
                        
                        <!-- Add Credit -->
                        <div class="col-lg-3 col-md-6">
                            <a href="credits/add_credit.php" class="btn btn-info w-100 py-3 d-flex align-items-center justify-content-center text-decoration-none">
                                <i class="bi bi-person-plus me-2" style="font-size: 1.2rem;"></i>
                                <div class="text-start">
                                    <div class="fw-semibold">Add Credit</div>
                                    <small class="opacity-75">Supplier credit</small>
                                </div>
                            </a>
                        </div>
                        
                        <!-- All Invoices -->
                        <div class="col-lg-3 col-md-6">
                            <a href="invoices/invoices.php" class="btn btn-primary w-100 py-3 d-flex align-items-center justify-content-center text-decoration-none">
                                <i class="bi bi-file-text me-2" style="font-size: 1.2rem;"></i>
                                <div class="text-start">
                                    <div class="fw-semibold">All Invoices</div>
                                    <small class="opacity-75">View all invoices</small>
                                </div>
                            </a>
                        </div>
                        
                        <!-- All Credits -->
                        <div class="col-lg-3 col-md-6">
                            <a href="credits/credits.php" class="btn btn-secondary w-100 py-3 d-flex align-items-center justify-content-center text-decoration-none">
                                <i class="bi bi-list-check me-2" style="font-size: 1.2rem;"></i>
                                <div class="text-start">
                                    <div class="fw-semibold">All Credits</div>
                                    <small class="opacity-75">View all credits</small>
                                </div>
                            </a>
                        </div>
                        
                        <!-- All Payment Records -->
                        <div class="col-lg-3 col-md-6">
                            <a href="payments/payments.php" class="btn btn-warning w-100 py-3 d-flex align-items-center justify-content-center text-decoration-none">
                                <i class="bi bi-receipt me-2" style="font-size: 1.2rem;"></i>
                                <div class="text-start">
                                    <div class="fw-semibold">All Payments</div>
                                    <small class="opacity-75">Payment records</small>
                                </div>
                            </a>
                        </div>
                        
                        <!-- Supplier Returns -->
                        <div class="col-lg-3 col-md-6">
                            <a href="credits/supplier_returns.php" class="btn btn-danger w-100 py-3 d-flex align-items-center justify-content-center text-decoration-none">
                                <i class="bi bi-arrow-return-left me-2" style="font-size: 1.2rem;"></i>
                                <div class="text-start">
                                    <div class="fw-semibold">Supplier Returns</div>
                                    <small class="opacity-75">View returns</small>
                                </div>
                            </a>
                        </div>
                        
                        <!-- Aging Report -->
                        <div class="col-lg-3 col-md-6">
                            <a href="reports/aging.php" class="btn btn-dark w-100 py-3 d-flex align-items-center justify-content-center text-decoration-none">
                                <i class="bi bi-graph-up me-2" style="font-size: 1.2rem;"></i>
                                <div class="text-start">
                                    <div class="fw-semibold">Aging Report</div>
                                    <small class="opacity-75">Payment analysis</small>
                                </div>
                            </a>
                        </div>
                        
                        <!-- Payables Reports -->
                        <div class="col-lg-3 col-md-6">
                            <a href="reports/payables_reports.php" class="btn btn-outline-primary w-100 py-3 d-flex align-items-center justify-content-center text-decoration-none">
                                <i class="bi bi-bar-chart me-2" style="font-size: 1.2rem;"></i>
                                <div class="text-start">
                                    <div class="fw-semibold">Reports</div>
                                    <small class="opacity-75">Payables reports</small>
                                </div>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Recent Invoices -->
        <div class="col-xl-8">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h5 class="card-title mb-0 d-flex align-items-center">
                            <i class="mdi mdi-file-document-multiple text-primary me-2"></i>
                            Recent Invoices
                        </h5>
                        <div>
                            <a href="generate_invoice_numbers.php" class="btn btn-sm btn-outline-warning me-2" 
                               onclick="return confirm('This will generate invoice numbers for all orders with \'received\' status that don\'t have invoice numbers. Only received orders will be processed. Continue?')">
                                <i class="mdi mdi-refresh me-1"></i>Generate Invoice Numbers
                            </a>
                            <a href="invoices/invoices.php" class="btn btn-sm btn-outline-primary">
                                <i class="mdi mdi-eye me-1"></i>View All
                            </a>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th class="fw-semibold">Invoice #</th>
                                    <th class="fw-semibold">Supplier</th>
                                    <th class="fw-semibold">Date</th>
                                    <th class="fw-semibold">Amount</th>
                                    <th class="fw-semibold">Status</th>
                                    <th class="fw-semibold">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recent_invoices)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center text-muted">No invoices found</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($recent_invoices as $invoice): ?>
                                        <tr>
                                            <td>
                                                <a href="../../inventory/view_order.php?id=<?php echo $invoice['id']; ?>" class="text-primary">
                                                    <?php echo htmlspecialchars($invoice['display_number']); ?>
                                                </a>
                                            </td>
                                            <td><?php echo htmlspecialchars($invoice['supplier_name']); ?></td>
                                            <td><?php echo date('M d, Y', strtotime($invoice['received_date'])); ?></td>
                                            <td><?php echo formatCurrency($invoice['total_amount'], $settings); ?></td>
                                            <td>
                                                <?php 
                                                $status = 'pending';
                                                if ($invoice['paid_amount'] >= $invoice['total_amount']) {
                                                    $status = 'paid';
                                                } elseif ($invoice['paid_amount'] > 0) {
                                                    $status = 'partial';
                                                } elseif (strtotime($invoice['received_date']) < strtotime('-30 days')) {
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
                                                <a href="../../inventory/view_order.php?id=<?php echo $invoice['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                    View
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Overdue Invoices -->
        <div class="col-xl-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-4">
                    <h5 class="card-title mb-4 d-flex align-items-center">
                        <i class="mdi mdi-alert-circle text-danger me-2"></i>
                        Overdue Invoices
                    </h5>
                    <?php if (empty($overdue_invoices)): ?>
                        <div class="text-center text-muted py-4">
                            <i class="mdi mdi-check-circle-outline display-4 text-success mb-3"></i>
                            <h6 class="text-success">All caught up!</h6>
                            <p class="mb-0">No overdue invoices</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($overdue_invoices as $invoice): ?>
                                <div class="d-flex justify-content-between align-items-center p-3 bg-danger bg-opacity-10 rounded-3 border border-danger border-opacity-25">
                                    <div>
                                        <h6 class="mb-1 fw-semibold text-dark"><?php echo htmlspecialchars($invoice['display_number']); ?></h6>
                                        <small class="text-muted"><?php echo htmlspecialchars($invoice['supplier_name']); ?></small>
                                    </div>
                                    <div class="text-end">
                                        <div class="text-danger fw-bold fs-6"><?php echo formatCurrency($invoice['balance_due'], $settings); ?></div>
                                        <small class="text-danger fw-semibold"><?php echo $invoice['days_overdue']; ?> days overdue</small>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Suppliers with Outstanding Balances -->
    <div class="row">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">
                    <h5 class="card-title mb-4 d-flex align-items-center">
                        <i class="mdi mdi-account-group text-warning me-2"></i>
                        Suppliers with Outstanding Balances
                    </h5>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th class="fw-semibold">Supplier</th>
                                    <th class="fw-semibold">Invoices</th>
                                    <th class="fw-semibold">Outstanding Amount</th>
                                    <th class="fw-semibold">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($suppliers_outstanding)): ?>
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-4">
                                            <i class="mdi mdi-check-circle-outline display-6 text-success mb-2"></i>
                                            <p class="mb-0">No outstanding balances</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($suppliers_outstanding as $supplier): ?>
                                        <tr>
                                            <td class="fw-semibold"><?php echo htmlspecialchars($supplier['name']); ?></td>
                                            <td>
                                                <span class="badge bg-primary"><?php echo $supplier['invoice_count']; ?></span>
                                            </td>
                                            <td class="fw-bold text-danger fs-6"><?php echo formatCurrency($supplier['total_outstanding'], $settings); ?></td>
                                            <td>
                                                <a href="../../inventory/view_orders.php?supplier=<?php echo $supplier['id']; ?>&status=received" class="btn btn-sm btn-outline-primary">
                                                    <i class="mdi mdi-eye me-1"></i>View Invoices
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
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
</body>
</html>
