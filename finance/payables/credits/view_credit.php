<?php
session_start();
require_once __DIR__ . '/../../../include/db.php';
require_once __DIR__ . '/../../../include/functions.php';
require_once __DIR__ . '/../../../include/print_header.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$user_role = $_SESSION['role_name'] ?? 'User';

// Get credit ID
$credit_id = $_GET['id'] ?? 0;

if (!$credit_id) {
    header("Location: credits.php");
    exit();
}

// Get credit details
$sql = "
    SELECT 
        sc.*,
        s.name as supplier_name,
        s.email as supplier_email,
        s.phone as supplier_phone,
        s.address as supplier_address,
        u.username as created_by_name,
        u.first_name as created_by_first,
        u.last_name as created_by_last
    FROM supplier_credits sc
    LEFT JOIN suppliers s ON sc.supplier_id = s.id
    LEFT JOIN users u ON sc.created_by = u.id
    WHERE sc.id = :credit_id
";

$stmt = $conn->prepare($sql);
$stmt->bindValue(':credit_id', $credit_id, PDO::PARAM_INT);
$stmt->execute();
$credit = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$credit) {
    header("Location: credits.php");
    exit();
}

// Get credit applications
$applications = [];
try {
    $applications_sql = "
        SELECT 
            ca.*,
            si.invoice_number,
            si.invoice_date,
            u.username as applied_by_name
        FROM credit_applications ca
        LEFT JOIN supplier_invoices si ON ca.invoice_id = si.id
        LEFT JOIN users u ON ca.applied_by = u.id
        WHERE ca.credit_id = :credit_id
        ORDER BY ca.applied_date DESC
    ";

    $stmt = $conn->prepare($applications_sql);
    $stmt->bindValue(':credit_id', $credit_id, PDO::PARAM_INT);
    $stmt->execute();
    $applications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // If credit_applications table doesn't exist or has issues, just show empty applications
    $applications = [];
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$page_title = "Credit Details - " . $credit['credit_number'];
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
    <?php 
    $GLOBALS['settings'] = $settings;
    printStyles(); 
    ?>
</head>
<body>
    <?php include '../../../include/navmenu.php'; ?>

    <div class="main-content">
        <?php
        $breadcrumbs = [
            ['url' => '../../../dashboard/dashboard.php', 'text' => 'Dashboard'],
            ['url' => '../../index.php', 'text' => 'Finance'],
            ['url' => '../payables.php', 'text' => 'Payables'],
            ['url' => 'credits.php', 'text' => 'Credits'],
            ['url' => '', 'text' => $credit['credit_number']]
        ];
        
        $additional_actions = '
            <div class="btn-group no-print">
                <button onclick="window.print()" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-printer me-1"></i>Print
                </button>
                ' . ($credit['status'] != 'fully_applied' && $credit['available_amount'] > 0 ? 
                    '<a href="apply_credit.php?credit_id=' . $credit['id'] . '" class="btn btn-outline-light btn-sm">
                        <i class="bi bi-check me-1"></i>Apply Credit
                    </a>' : '') . '
            </div>
        ';
        
        printHeader('Credit Details', $breadcrumbs, 'credits.php', 'bi bi-person-plus', $additional_actions);
        ?>

        <main class="content">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-4">
                                <h4 class="card-title mb-0">Credit Details</h4>
                                <div class="btn-group no-print">
                                    <button onclick="window.print()" class="btn btn-outline-primary">
                                        <i class="bi bi-printer me-1"></i>Print
                                    </button>
                                    <?php if ($credit['status'] != 'fully_applied' && $credit['available_amount'] > 0): ?>
                                        <a href="apply_credit.php?credit_id=<?php echo $credit['id']; ?>" class="btn btn-outline-success">
                                            <i class="bi bi-check me-1"></i>Apply Credit
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="row">
                                <!-- Credit Information -->
                                <div class="col-md-6">
                                    <div class="card border">
                                        <div class="card-header bg-light">
                                            <h5 class="mb-0"><i class="bi bi-person-plus me-2"></i>Credit Information</h5>
                                        </div>
                                        <div class="card-body">
                                            <table class="table table-borderless table-sm">
                                                <tr>
                                                    <td class="fw-bold">Credit Number:</td>
                                                    <td><?php echo htmlspecialchars($credit['credit_number']); ?></td>
                                                </tr>
                                                <tr>
                                                    <td class="fw-bold">Credit Date:</td>
                                                    <td><?php echo date('F d, Y', strtotime($credit['credit_date'])); ?></td>
                                                </tr>
                                                <tr>
                                                    <td class="fw-bold">Credit Type:</td>
                                                    <td>
                                                        <span class="badge bg-<?php 
                                                            echo $credit['credit_type'] == 'return' ? 'danger' : 
                                                                ($credit['credit_type'] == 'discount' ? 'success' : 
                                                                ($credit['credit_type'] == 'overpayment' ? 'info' : 'secondary')); 
                                                        ?>">
                                                            <?php echo ucfirst($credit['credit_type']); ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td class="fw-bold">Credit Amount:</td>
                                                    <td class="fw-bold text-success fs-5"><?php echo formatCurrency($credit['credit_amount'], $settings); ?></td>
                                                </tr>
                                                <tr>
                                                    <td class="fw-bold">Applied Amount:</td>
                                                    <td class="fw-bold text-warning"><?php echo formatCurrency($credit['applied_amount'], $settings); ?></td>
                                                </tr>
                                                <tr>
                                                    <td class="fw-bold">Available Amount:</td>
                                                    <td class="fw-bold text-info"><?php echo formatCurrency($credit['available_amount'], $settings); ?></td>
                                                </tr>
                                                <tr>
                                                    <td class="fw-bold">Status:</td>
                                                    <td>
                                                        <span class="badge bg-<?php 
                                                            echo $credit['status'] == 'available' ? 'success' : 
                                                                ($credit['status'] == 'partially_applied' ? 'warning' : 
                                                                ($credit['status'] == 'fully_applied' ? 'info' : 'danger')); 
                                                        ?>">
                                                            <?php echo ucfirst(str_replace('_', ' ', $credit['status'])); ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                                <?php if ($credit['expiry_date']): ?>
                                                <tr>
                                                    <td class="fw-bold">Expiry Date:</td>
                                                    <td>
                                                        <?php 
                                                        $expiry_date = new DateTime($credit['expiry_date']);
                                                        $today = new DateTime();
                                                        $is_expired = $expiry_date < $today;
                                                        ?>
                                                        <span class="<?php echo $is_expired ? 'text-danger fw-bold' : 'text-muted'; ?>">
                                                            <?php echo date('F d, Y', strtotime($credit['expiry_date'])); ?>
                                                            <?php if ($is_expired): ?>
                                                                <span class="badge bg-danger ms-2">Expired</span>
                                                            <?php endif; ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                                <?php endif; ?>
                                                <tr>
                                                    <td class="fw-bold">Reason:</td>
                                                    <td><?php echo htmlspecialchars($credit['reason'] ?? 'No reason provided'); ?></td>
                                                </tr>
                                                <?php if (!empty($credit['notes'])): ?>
                                                <tr>
                                                    <td class="fw-bold">Notes:</td>
                                                    <td><?php echo htmlspecialchars($credit['notes']); ?></td>
                                                </tr>
                                                <?php endif; ?>
                                            </table>
                                        </div>
                                    </div>
                                </div>

                                <!-- Supplier Information -->
                                <div class="col-md-6">
                                    <div class="card border">
                                        <div class="card-header bg-light">
                                            <h5 class="mb-0"><i class="bi bi-person me-2"></i>Supplier Information</h5>
                                        </div>
                                        <div class="card-body">
                                            <table class="table table-borderless table-sm">
                                                <tr>
                                                    <td class="fw-bold">Supplier Name:</td>
                                                    <td><?php echo htmlspecialchars($credit['supplier_name']); ?></td>
                                                </tr>
                                                <?php if ($credit['supplier_email']): ?>
                                                <tr>
                                                    <td class="fw-bold">Email:</td>
                                                    <td><?php echo htmlspecialchars($credit['supplier_email']); ?></td>
                                                </tr>
                                                <?php endif; ?>
                                                <?php if ($credit['supplier_phone']): ?>
                                                <tr>
                                                    <td class="fw-bold">Phone:</td>
                                                    <td><?php echo htmlspecialchars($credit['supplier_phone']); ?></td>
                                                </tr>
                                                <?php endif; ?>
                                                <?php if ($credit['supplier_address']): ?>
                                                <tr>
                                                    <td class="fw-bold">Address:</td>
                                                    <td><?php echo nl2br(htmlspecialchars($credit['supplier_address'])); ?></td>
                                                </tr>
                                                <?php endif; ?>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Credit Applications History -->
                            <div class="row mt-4">
                                <div class="col-12">
                                    <div class="card border">
                                        <div class="card-header bg-light">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <h5 class="mb-0"><i class="bi bi-list-check me-2"></i>Credit Application History</h5>
                                                <span class="badge bg-info"><?php echo count($applications); ?> Application(s)</span>
                                            </div>
                                        </div>
                                        <div class="card-body">
                                            <?php if (empty($applications)): ?>
                                                <div class="text-center text-muted py-4">
                                                    <i class="bi bi-inbox" style="font-size: 2rem;"></i>
                                                    <p class="mt-2">No credit applications yet</p>
                                                    <small>This credit has not been applied to any invoices.</small>
                                                </div>
                                            <?php else: ?>
                                                <div class="table-responsive">
                                                    <table class="table table-striped table-hover">
                                                        <thead>
                                                            <tr>
                                                                <th>#</th>
                                                                <th>Invoice Details</th>
                                                                <th>Applied Amount</th>
                                                                <th>Applied Date</th>
                                                                <th>Applied By</th>
                                                                <th>Notes</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($applications as $index => $application): ?>
                                                            <tr>
                                                                <td>
                                                                    <span class="badge bg-primary"><?php echo $index + 1; ?></span>
                                                                </td>
                                                                <td>
                                                                    <div>
                                                                        <a href="../invoices/view_invoice.php?id=<?php echo $application['invoice_id']; ?>" class="text-info fw-bold">
                                                                            <?php echo htmlspecialchars($application['invoice_number']); ?>
                                                                        </a>
                                                                        <br>
                                                                        <small class="text-muted">
                                                                            Invoice Date: <?php echo date('M d, Y', strtotime($application['invoice_date'])); ?>
                                                                        </small>
                                                                    </div>
                                                                </td>
                                                                <td>
                                                                    <span class="fw-bold text-success">
                                                                        <?php echo formatCurrency($application['applied_amount'], $settings); ?>
                                                                    </span>
                                                                </td>
                                                                <td>
                                                                    <div>
                                                                        <?php echo date('M d, Y', strtotime($application['applied_date'])); ?>
                                                                        <br>
                                                                        <small class="text-muted">
                                                                            <?php echo date('g:i A', strtotime($application['applied_date'])); ?>
                                                                        </small>
                                                                    </div>
                                                                </td>
                                                                <td>
                                                                    <span class="text-muted">
                                                                        <?php echo htmlspecialchars($application['applied_by_name']); ?>
                                                                    </span>
                                                                </td>
                                                                <td>
                                                                    <?php if (!empty($application['notes'])): ?>
                                                                        <span class="text-muted" title="<?php echo htmlspecialchars($application['notes']); ?>">
                                                                            <i class="bi bi-chat-text"></i>
                                                                        </span>
                                                                    <?php else: ?>
                                                                        <span class="text-muted">-</span>
                                                                    <?php endif; ?>
                                                                </td>
                                                            </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                        <tfoot>
                                                            <tr class="table-info">
                                                                <th colspan="2">Total Applied</th>
                                                                <th class="text-success">
                                                                    <?php echo formatCurrency($credit['applied_amount'], $settings); ?>
                                                                </th>
                                                                <th colspan="3"></th>
                                                            </tr>
                                                        </tfoot>
                                                    </table>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Credit History -->
                            <div class="row mt-4">
                                <div class="col-12">
                                    <div class="card border">
                                        <div class="card-header bg-light">
                                            <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Credit History</h5>
                                        </div>
                                        <div class="card-body">
                                            <table class="table table-borderless table-sm">
                                                <tr>
                                                    <td class="fw-bold">Created By:</td>
                                                    <td><?php echo htmlspecialchars($credit['created_by_first'] . ' ' . $credit['created_by_last'] . ' (' . $credit['created_by_name'] . ')'); ?></td>
                                                </tr>
                                                <tr>
                                                    <td class="fw-bold">Created At:</td>
                                                    <td><?php echo date('F d, Y g:i A', strtotime($credit['created_at'])); ?></td>
                                                </tr>
                                                <?php if ($credit['updated_at']): ?>
                                                <tr>
                                                    <td class="fw-bold">Last Updated:</td>
                                                    <td><?php echo date('F d, Y g:i A', strtotime($credit['updated_at'])); ?></td>
                                                </tr>
                                                <?php endif; ?>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Print Version -->
    <?php printContainerHeader('Credit Memo', $credit['credit_number'], $credit['credit_date']); ?>

        <div class="print-section">
            <h4>Credit Details</h4>
            <table class="print-table">
                <tr>
                    <th>Credit Number</th>
                    <td><?php echo htmlspecialchars($credit['credit_number']); ?></td>
                </tr>
                <tr>
                    <th>Credit Date</th>
                    <td><?php echo date('F d, Y', strtotime($credit['credit_date'])); ?></td>
                </tr>
                <tr>
                    <th>Credit Type</th>
                    <td><?php echo ucfirst($credit['credit_type']); ?></td>
                </tr>
                <tr>
                    <th>Credit Amount</th>
                    <td><?php echo formatCurrency($credit['credit_amount'], $settings); ?></td>
                </tr>
                <tr>
                    <th>Applied Amount</th>
                    <td><?php echo formatCurrency($credit['applied_amount'], $settings); ?></td>
                </tr>
                <tr>
                    <th>Available Amount</th>
                    <td><?php echo formatCurrency($credit['available_amount'], $settings); ?></td>
                </tr>
                <tr>
                    <th>Status</th>
                    <td><?php echo ucfirst(str_replace('_', ' ', $credit['status'])); ?></td>
                </tr>
                <?php if ($credit['expiry_date']): ?>
                <tr>
                    <th>Expiry Date</th>
                    <td><?php echo date('F d, Y', strtotime($credit['expiry_date'])); ?></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <th>Reason</th>
                    <td><?php echo htmlspecialchars($credit['reason'] ?? 'No reason provided'); ?></td>
                </tr>
                <?php if (!empty($credit['notes'])): ?>
                <tr>
                    <th>Notes</th>
                    <td><?php echo htmlspecialchars($credit['notes']); ?></td>
                </tr>
                <?php endif; ?>
            </table>
        </div>

        <div class="print-section">
            <h4>Supplier Information</h4>
            <table class="print-table">
                <tr>
                    <th>Supplier Name</th>
                    <td><?php echo htmlspecialchars($credit['supplier_name']); ?></td>
                </tr>
                <?php if ($credit['supplier_email']): ?>
                <tr>
                    <th>Email</th>
                    <td><?php echo htmlspecialchars($credit['supplier_email']); ?></td>
                </tr>
                <?php endif; ?>
                <?php if ($credit['supplier_phone']): ?>
                <tr>
                    <th>Phone</th>
                    <td><?php echo htmlspecialchars($credit['supplier_phone']); ?></td>
                </tr>
                <?php endif; ?>
                <?php if ($credit['supplier_address']): ?>
                <tr>
                    <th>Address</th>
                    <td><?php echo htmlspecialchars($credit['supplier_address']); ?></td>
                </tr>
                <?php endif; ?>
            </table>
        </div>

        <?php if (!empty($applications)): ?>
        <div class="print-section">
            <h4>Credit Application History</h4>
            <table class="print-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Invoice #</th>
                        <th>Invoice Date</th>
                        <th>Applied Amount</th>
                        <th>Applied Date</th>
                        <th>Applied By</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($applications as $index => $application): ?>
                    <tr>
                        <td><?php echo $index + 1; ?></td>
                        <td><?php echo htmlspecialchars($application['invoice_number']); ?></td>
                        <td><?php echo date('M d, Y', strtotime($application['invoice_date'])); ?></td>
                        <td><?php echo formatCurrency($application['applied_amount'], $settings); ?></td>
                        <td><?php echo date('M d, Y g:i A', strtotime($application['applied_date'])); ?></td>
                        <td><?php echo htmlspecialchars($application['applied_by_name']); ?></td>
                        <td><?php echo htmlspecialchars($application['notes'] ?? '-'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="3">Total Applied</th>
                        <th><?php echo formatCurrency($credit['applied_amount'], $settings); ?></th>
                        <th colspan="3"></th>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php else: ?>
        <div class="print-section">
            <h4>Credit Application History</h4>
            <p><em>No credit applications have been made yet.</em></p>
        </div>
        <?php endif; ?>

        <div class="print-section">
            <h4>Credit History</h4>
            <table class="print-table">
                <tr>
                    <th>Created By</th>
                    <td><?php echo htmlspecialchars($credit['created_by_first'] . ' ' . $credit['created_by_last'] . ' (' . $credit['created_by_name'] . ')'); ?></td>
                </tr>
                <tr>
                    <th>Created At</th>
                    <td><?php echo date('F d, Y g:i A', strtotime($credit['created_at'])); ?></td>
                </tr>
                <?php if ($credit['updated_at']): ?>
                <tr>
                    <th>Last Updated</th>
                    <td><?php echo date('F d, Y g:i A', strtotime($credit['updated_at'])); ?></td>
                </tr>
                <?php endif; ?>
            </table>
        </div>

        <div class="print-total">
            <p><strong>Total Credit Amount: <?php echo formatCurrency($credit['credit_amount'], $settings); ?></strong></p>
            <p><strong>Available Amount: <?php echo formatCurrency($credit['available_amount'], $settings); ?></strong></p>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
