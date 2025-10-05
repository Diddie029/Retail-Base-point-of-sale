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

// Handle date filter
$as_of_date = $_GET['as_of_date'] ?? date('Y-m-d');
$supplier_filter = $_GET['supplier'] ?? '';

// Build WHERE clause
$where = ["si.status IN ('pending', 'partial', 'overdue')"];
$params = [':as_of_date' => $as_of_date];

if (!empty($supplier_filter)) {
    $where[] = "si.supplier_id = :supplier_id";
    $params[':supplier_id'] = $supplier_filter;
}

$where_clause = 'WHERE ' . implode(' AND ', $where);

// Get aging report data
$sql = "
    SELECT 
        si.*,
        s.name as supplier_name,
        s.contact_person,
        s.phone,
        s.email,
        DATEDIFF(:as_of_date, si.due_date) as days_overdue,
        CASE 
            WHEN DATEDIFF(:as_of_date, si.due_date) <= 0 THEN 'current'
            WHEN DATEDIFF(:as_of_date, si.due_date) BETWEEN 1 AND 30 THEN '1-30'
            WHEN DATEDIFF(:as_of_date, si.due_date) BETWEEN 31 AND 60 THEN '31-60'
            WHEN DATEDIFF(:as_of_date, si.due_date) BETWEEN 61 AND 90 THEN '61-90'
            WHEN DATEDIFF(:as_of_date, si.due_date) > 90 THEN '90+'
        END as age_bucket
    FROM supplier_invoices si
    LEFT JOIN suppliers s ON si.supplier_id = s.id
    $where_clause
    ORDER BY s.name, si.due_date ASC
";

$stmt = $conn->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group by supplier for summary
$supplier_summary = [];
$age_totals = [
    'current' => 0,
    '1-30' => 0,
    '31-60' => 0,
    '61-90' => 0,
    '90+' => 0,
    'total' => 0
];

foreach ($invoices as $invoice) {
    $supplier_id = $invoice['supplier_id'];
    $age_bucket = $invoice['age_bucket'];
    $amount = $invoice['balance_due'];
    
    if (!isset($supplier_summary[$supplier_id])) {
        $supplier_summary[$supplier_id] = [
            'supplier_name' => $invoice['supplier_name'],
            'contact_person' => $invoice['contact_person'],
            'phone' => $invoice['phone'],
            'email' => $invoice['email'],
            'current' => 0,
            '1-30' => 0,
            '31-60' => 0,
            '61-90' => 0,
            '90+' => 0,
            'total' => 0,
            'invoices' => []
        ];
    }
    
    $supplier_summary[$supplier_id][$age_bucket] += $amount;
    $supplier_summary[$supplier_id]['total'] += $amount;
    $supplier_summary[$supplier_id]['invoices'][] = $invoice;
    
    $age_totals[$age_bucket] += $amount;
    $age_totals['total'] += $amount;
}

// Get suppliers for filter dropdown
$suppliers = [];
$stmt = $conn->query("SELECT id, name FROM suppliers WHERE is_active = 1 ORDER BY name");
$suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Accounts Payable Aging Report";
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
                        <h1 class="mb-0"><i class="mdi mdi-clock-history me-2"></i>Accounts Payable Aging Report</h1>
                    </div>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb" style="background: rgba(255,255,255,0.1); padding: 0.5rem 1rem; border-radius: 0.375rem;">
                            <li class="breadcrumb-item"><a href="../../../dashboard/dashboard.php" style="color: rgba(255,255,255,0.8);">Dashboard</a></li>
                            <li class="breadcrumb-item"><a href="../../index.php" style="color: rgba(255,255,255,0.8);">Finance</a></li>
                            <li class="breadcrumb-item"><a href="../payables.php" style="color: rgba(255,255,255,0.8);">Payables</a></li>
                            <li class="breadcrumb-item active" style="color: white;">Aging Report</li>
                        </ol>
                    </nav>
                    <p class="header-subtitle mb-0" style="color: rgba(255,255,255,0.9);">Track outstanding invoices by age</p>
                </div>
            </div>
        </header>

        <main class="content">

    <!-- Filters -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label for="as_of_date" class="form-label">As of Date</label>
                            <input type="date" class="form-control" id="as_of_date" name="as_of_date" 
                                   value="<?php echo htmlspecialchars($as_of_date); ?>">
                        </div>
                        <div class="col-md-3">
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
                            <label class="form-label">&nbsp;</label>
                            <button type="submit" class="btn btn-primary d-block w-100">Generate Report</button>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">&nbsp;</label>
                            <button type="button" class="btn btn-success d-block w-100" onclick="printReport()">
                                <i class="mdi mdi-printer"></i> Print
                            </button>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">&nbsp;</label>
                            <button type="button" class="btn btn-info d-block w-100" onclick="exportReport()">
                                <i class="mdi mdi-download"></i> Export
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row">
        <div class="col-xl-2 col-md-4">
            <div class="card">
                <div class="card-body text-center">
                    <h5 class="text-muted">Current</h5>
                    <h3 class="text-success"><?php echo formatCurrency($age_totals['current'], $settings); ?></h3>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4">
            <div class="card">
                <div class="card-body text-center">
                    <h5 class="text-muted">1-30 Days</h5>
                    <h3 class="text-warning"><?php echo formatCurrency($age_totals['1-30'], $settings); ?></h3>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4">
            <div class="card">
                <div class="card-body text-center">
                    <h5 class="text-muted">31-60 Days</h5>
                    <h3 class="text-warning"><?php echo formatCurrency($age_totals['31-60'], $settings); ?></h3>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4">
            <div class="card">
                <div class="card-body text-center">
                    <h5 class="text-muted">61-90 Days</h5>
                    <h3 class="text-danger"><?php echo formatCurrency($age_totals['61-90'], $settings); ?></h3>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4">
            <div class="card">
                <div class="card-body text-center">
                    <h5 class="text-muted">90+ Days</h5>
                    <h3 class="text-danger"><?php echo formatCurrency($age_totals['90+'], $settings); ?></h3>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4">
            <div class="card">
                <div class="card-body text-center">
                    <h5 class="text-muted">Total</h5>
                    <h3 class="text-primary"><?php echo formatCurrency($age_totals['total'], $settings); ?></h3>
                </div>
            </div>
        </div>
    </div>

    <!-- Aging Report Table -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h4 class="header-title">Aging Report - As of <?php echo date('F d, Y', strtotime($as_of_date)); ?></h4>
                        <div class="text-muted">
                            Generated on <?php echo date('F d, Y H:i:s'); ?>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-striped table-hover" id="agingTable">
                            <thead class="table-dark">
                                <tr>
                                    <th>Supplier</th>
                                    <th class="text-end">Current</th>
                                    <th class="text-end">1-30 Days</th>
                                    <th class="text-end">31-60 Days</th>
                                    <th class="text-end">61-90 Days</th>
                                    <th class="text-end">90+ Days</th>
                                    <th class="text-end">Total</th>
                                    <th>Contact</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($supplier_summary)): ?>
                                    <tr>
                                        <td colspan="9" class="text-center text-muted">No outstanding invoices found</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($supplier_summary as $supplier_id => $supplier): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($supplier['supplier_name']); ?></strong>
                                                <br>
                                                <small class="text-muted"><?php echo count($supplier['invoices']); ?> invoice(s)</small>
                                            </td>
                                            <td class="text-end">
                                                <span class="<?php echo $supplier['current'] > 0 ? 'fw-bold text-success' : 'text-muted'; ?>">
                                                    <?php echo formatCurrency($supplier['current'], $settings); ?>
                                                </span>
                                            </td>
                                            <td class="text-end">
                                                <span class="<?php echo $supplier['1-30'] > 0 ? 'fw-bold text-warning' : 'text-muted'; ?>">
                                                    <?php echo formatCurrency($supplier['1-30'], $settings); ?>
                                                </span>
                                            </td>
                                            <td class="text-end">
                                                <span class="<?php echo $supplier['31-60'] > 0 ? 'fw-bold text-warning' : 'text-muted'; ?>">
                                                    <?php echo formatCurrency($supplier['31-60'], $settings); ?>
                                                </span>
                                            </td>
                                            <td class="text-end">
                                                <span class="<?php echo $supplier['61-90'] > 0 ? 'fw-bold text-danger' : 'text-muted'; ?>">
                                                    <?php echo formatCurrency($supplier['61-90'], $settings); ?>
                                                </span>
                                            </td>
                                            <td class="text-end">
                                                <span class="<?php echo $supplier['90+'] > 0 ? 'fw-bold text-danger' : 'text-muted'; ?>">
                                                    <?php echo formatCurrency($supplier['90+'], $settings); ?>
                                                </span>
                                            </td>
                                            <td class="text-end">
                                                <strong class="text-primary"><?php echo formatCurrency($supplier['total'], $settings); ?></strong>
                                            </td>
                                            <td>
                                                <?php if ($supplier['contact_person']): ?>
                                                    <div><?php echo htmlspecialchars($supplier['contact_person']); ?></div>
                                                <?php endif; ?>
                                                <?php if ($supplier['phone']): ?>
                                                    <div><small class="text-muted"><?php echo htmlspecialchars($supplier['phone']); ?></small></div>
                                                <?php endif; ?>
                                                <?php if ($supplier['email']): ?>
                                                    <div><small class="text-muted"><?php echo htmlspecialchars($supplier['email']); ?></small></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <button type="button" class="btn btn-outline-primary btn-sm" 
                                                            onclick="viewSupplierInvoices(<?php echo $supplier_id; ?>)">
                                                        <i class="mdi mdi-eye"></i>
                                                    </button>
                                                    <a href="../invoices/invoices.php?supplier=<?php echo $supplier_id; ?>" 
                                                       class="btn btn-outline-info btn-sm">
                                                        <i class="mdi mdi-file-document"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <tfoot class="table-dark">
                                <tr>
                                    <th>Total</th>
                                    <th class="text-end"><?php echo formatCurrency($age_totals['current'], $settings); ?></th>
                                    <th class="text-end"><?php echo formatCurrency($age_totals['1-30'], $settings); ?></th>
                                    <th class="text-end"><?php echo formatCurrency($age_totals['31-60'], $settings); ?></th>
                                    <th class="text-end"><?php echo formatCurrency($age_totals['61-90'], $settings); ?></th>
                                    <th class="text-end"><?php echo formatCurrency($age_totals['90+'], $settings); ?></th>
                                    <th class="text-end"><?php echo formatCurrency($age_totals['total'], $settings); ?></th>
                                    <th></th>
                                    <th></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
        </main>
    </div>

    <!-- Supplier Invoices Modal -->
<div class="modal fade" id="supplierInvoicesModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Supplier Invoices</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="supplierInvoicesContent">
                <!-- Content will be loaded here -->
            </div>
        </div>
    </div>
</div>

<script>
function printReport() {
    window.print();
}

function exportReport() {
    // Simple CSV export
    const table = document.getElementById('agingTable');
    const rows = table.querySelectorAll('tr');
    let csv = [];
    
    rows.forEach(row => {
        const cells = row.querySelectorAll('th, td');
        const rowData = Array.from(cells).map(cell => {
            return '"' + cell.textContent.trim().replace(/"/g, '""') + '"';
        });
        csv.push(rowData.join(','));
    });
    
    const csvContent = csv.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'aging_report_<?php echo date('Y-m-d'); ?>.csv';
    a.click();
    window.URL.revokeObjectURL(url);
}

function viewSupplierInvoices(supplierId) {
    // This would typically load invoice details via AJAX
    // For now, redirect to invoices page with supplier filter
    window.location.href = '../invoices/invoices.php?supplier=' + supplierId;
}
</script>

<style>
@media print {
    .page-title-box,
    .card:first-child,
    .btn,
    .modal {
        display: none !important;
    }
    
    .card-body {
        padding: 0 !important;
    }
    
    .table {
        font-size: 12px;
    }
}
</style>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
