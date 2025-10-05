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

// Get payment ID
$payment_id = $_GET['id'] ?? 0;

if (!$payment_id) {
    header("Location: payments.php");
    exit();
}

// Get payment details
$sql = "
    SELECT 
        sp.*,
        s.name as supplier_name,
        s.email as supplier_email,
        s.phone as supplier_phone,
        s.address as supplier_address,
        si.invoice_number,
        si.invoice_date,
        si.total_amount as invoice_amount,
        u.username as created_by_name,
        u.first_name as created_by_first,
        u.last_name as created_by_last
    FROM supplier_payments sp
    LEFT JOIN suppliers s ON sp.supplier_id = s.id
    LEFT JOIN supplier_invoices si ON sp.invoice_id = si.id
    LEFT JOIN users u ON sp.created_by = u.id
    WHERE sp.id = :payment_id
";

$stmt = $conn->prepare($sql);
$stmt->bindValue(':payment_id', $payment_id, PDO::PARAM_INT);
$stmt->execute();
$payment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$payment) {
    header("Location: payments.php");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$page_title = "Payment Details - " . $payment['payment_number'];
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
            ['url' => 'payments.php', 'text' => 'Payments'],
            ['url' => '', 'text' => $payment['payment_number']]
        ];
        
        $additional_actions = '
            <div class="btn-group no-print">
                <button onclick="window.print()" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-printer me-1"></i>Print
                </button>
                ' . ($payment['status'] == 'pending' ? 
                    '<a href="edit_payment.php?id=' . $payment['id'] . '" class="btn btn-outline-light btn-sm">
                        <i class="bi bi-pencil me-1"></i>Edit
                    </a>' : '') . '
            </div>
        ';
        
        printHeader('Payment Details', $breadcrumbs, 'payments.php', 'bi bi-credit-card', $additional_actions);
        ?>

        <main class="content">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-4">
                                <h4 class="card-title mb-0">Payment Details</h4>
                                <div class="btn-group no-print">
                                    <button onclick="window.print()" class="btn btn-outline-primary">
                                        <i class="bi bi-printer me-1"></i>Print
                                    </button>
                                    <?php if ($payment['status'] == 'pending'): ?>
                                        <a href="edit_payment.php?id=<?php echo $payment['id']; ?>" class="btn btn-outline-warning">
                                            <i class="bi bi-pencil me-1"></i>Edit
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="row">
                                <!-- Payment Information -->
                                <div class="col-md-6">
                                    <div class="card border">
                                        <div class="card-header bg-light">
                                            <h5 class="mb-0"><i class="bi bi-credit-card me-2"></i>Payment Information</h5>
                                        </div>
                                        <div class="card-body">
                                            <table class="table table-borderless table-sm">
                                                <tr>
                                                    <td class="fw-bold">Payment Number:</td>
                                                    <td><?php echo htmlspecialchars($payment['payment_number']); ?></td>
                                                </tr>
                                                <tr>
                                                    <td class="fw-bold">Payment Date:</td>
                                                    <td><?php echo date('F d, Y', strtotime($payment['payment_date'])); ?></td>
                                                </tr>
                                                <tr>
                                                    <td class="fw-bold">Payment Method:</td>
                                                    <td>
                                                        <span class="badge bg-<?php 
                                                            echo $payment['payment_method'] == 'cash' ? 'success' : 
                                                                ($payment['payment_method'] == 'bank_transfer' ? 'primary' : 
                                                                ($payment['payment_method'] == 'check' ? 'warning' : 'secondary')); 
                                                        ?>">
                                                            <?php echo ucfirst(str_replace('_', ' ', $payment['payment_method'])); ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td class="fw-bold">Amount:</td>
                                                    <td class="fw-bold text-success fs-5"><?php echo formatCurrency($payment['payment_amount'], $settings); ?></td>
                                                </tr>
                                                <tr>
                                                    <td class="fw-bold">Reference Number:</td>
                                                    <td><?php echo htmlspecialchars($payment['reference_number']); ?></td>
                                                </tr>
                                                <tr>
                                                    <td class="fw-bold">Status:</td>
                                                    <td>
                                                        <span class="badge bg-<?php 
                                                            echo $payment['status'] == 'completed' ? 'success' : 
                                                                ($payment['status'] == 'pending' ? 'warning' : 'danger'); 
                                                        ?>">
                                                            <?php echo ucfirst($payment['status']); ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td class="fw-bold">Notes:</td>
                                                    <td><?php echo htmlspecialchars($payment['notes'] ?? 'No notes'); ?></td>
                                                </tr>
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
                                                    <td><?php echo htmlspecialchars($payment['supplier_name']); ?></td>
                                                </tr>
                                                <?php if ($payment['supplier_email']): ?>
                                                <tr>
                                                    <td class="fw-bold">Email:</td>
                                                    <td><?php echo htmlspecialchars($payment['supplier_email']); ?></td>
                                                </tr>
                                                <?php endif; ?>
                                                <?php if ($payment['supplier_phone']): ?>
                                                <tr>
                                                    <td class="fw-bold">Phone:</td>
                                                    <td><?php echo htmlspecialchars($payment['supplier_phone']); ?></td>
                                                </tr>
                                                <?php endif; ?>
                                                <?php if ($payment['supplier_address']): ?>
                                                <tr>
                                                    <td class="fw-bold">Address:</td>
                                                    <td><?php echo nl2br(htmlspecialchars($payment['supplier_address'])); ?></td>
                                                </tr>
                                                <?php endif; ?>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Invoice Information -->
                            <?php if ($payment['invoice_number']): ?>
                            <div class="row mt-4">
                                <div class="col-12">
                                    <div class="card border">
                                        <div class="card-header bg-light">
                                            <h5 class="mb-0"><i class="bi bi-file-text me-2"></i>Related Invoice</h5>
                                        </div>
                                        <div class="card-body">
                                            <table class="table table-borderless table-sm">
                                                <tr>
                                                    <td class="fw-bold">Invoice Number:</td>
                                                    <td>
                                                        <a href="../invoices/view_invoice.php?id=<?php echo $payment['invoice_id']; ?>" class="text-info">
                                                            <?php echo htmlspecialchars($payment['invoice_number']); ?>
                                                        </a>
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td class="fw-bold">Invoice Date:</td>
                                                    <td><?php echo date('F d, Y', strtotime($payment['invoice_date'])); ?></td>
                                                </tr>
                                                <tr>
                                                    <td class="fw-bold">Invoice Amount:</td>
                                                    <td><?php echo formatCurrency($payment['invoice_amount'], $settings); ?></td>
                                                </tr>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- Payment History -->
                            <div class="row mt-4">
                                <div class="col-12">
                                    <div class="card border">
                                        <div class="card-header bg-light">
                                            <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Payment History</h5>
                                        </div>
                                        <div class="card-body">
                                            <table class="table table-borderless table-sm">
                                                <tr>
                                                    <td class="fw-bold">Created By:</td>
                                                    <td><?php echo htmlspecialchars($payment['created_by_first'] . ' ' . $payment['created_by_last'] . ' (' . $payment['created_by_name'] . ')'); ?></td>
                                                </tr>
                                                <tr>
                                                    <td class="fw-bold">Created At:</td>
                                                    <td><?php echo date('F d, Y g:i A', strtotime($payment['created_at'])); ?></td>
                                                </tr>
                                                <?php if ($payment['updated_at']): ?>
                                                <tr>
                                                    <td class="fw-bold">Last Updated:</td>
                                                    <td><?php echo date('F d, Y g:i A', strtotime($payment['updated_at'])); ?></td>
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
    <?php printContainerHeader('Payment Receipt', $payment['payment_number'], $payment['payment_date']); ?>

        <div class="print-section">
            <h4>Payment Details</h4>
            <table class="print-table">
                <tr>
                    <th>Payment Number</th>
                    <td><?php echo htmlspecialchars($payment['payment_number']); ?></td>
                </tr>
                <tr>
                    <th>Payment Date</th>
                    <td><?php echo date('F d, Y', strtotime($payment['payment_date'])); ?></td>
                </tr>
                <tr>
                    <th>Payment Method</th>
                    <td><?php echo ucfirst(str_replace('_', ' ', $payment['payment_method'])); ?></td>
                </tr>
                <tr>
                    <th>Amount</th>
                    <td><?php echo formatCurrency($payment['payment_amount'], $settings); ?></td>
                </tr>
                <tr>
                    <th>Reference Number</th>
                    <td><?php echo htmlspecialchars($payment['reference_number']); ?></td>
                </tr>
                <tr>
                    <th>Status</th>
                    <td><?php echo ucfirst($payment['status']); ?></td>
                </tr>
                <?php if ($payment['notes']): ?>
                <tr>
                    <th>Notes</th>
                    <td><?php echo htmlspecialchars($payment['notes']); ?></td>
                </tr>
                <?php endif; ?>
            </table>
        </div>

        <div class="print-section">
            <h4>Supplier Information</h4>
            <table class="print-table">
                <tr>
                    <th>Supplier Name</th>
                    <td><?php echo htmlspecialchars($payment['supplier_name']); ?></td>
                </tr>
                <?php if ($payment['supplier_email']): ?>
                <tr>
                    <th>Email</th>
                    <td><?php echo htmlspecialchars($payment['supplier_email']); ?></td>
                </tr>
                <?php endif; ?>
                <?php if ($payment['supplier_phone']): ?>
                <tr>
                    <th>Phone</th>
                    <td><?php echo htmlspecialchars($payment['supplier_phone']); ?></td>
                </tr>
                <?php endif; ?>
                <?php if ($payment['supplier_address']): ?>
                <tr>
                    <th>Address</th>
                    <td><?php echo htmlspecialchars($payment['supplier_address']); ?></td>
                </tr>
                <?php endif; ?>
            </table>
        </div>

        <?php if ($payment['invoice_number']): ?>
        <div class="print-section">
            <h4>Related Invoice</h4>
            <table class="print-table">
                <tr>
                    <th>Invoice Number</th>
                    <td><?php echo htmlspecialchars($payment['invoice_number']); ?></td>
                </tr>
                <tr>
                    <th>Invoice Date</th>
                    <td><?php echo date('F d, Y', strtotime($payment['invoice_date'])); ?></td>
                </tr>
                <tr>
                    <th>Invoice Amount</th>
                    <td><?php echo formatCurrency($payment['invoice_amount'], $settings); ?></td>
                </tr>
            </table>
        </div>
        <?php endif; ?>

        <div class="print-section">
            <h4>Payment History</h4>
            <table class="print-table">
                <tr>
                    <th>Created By</th>
                    <td><?php echo htmlspecialchars($payment['created_by_first'] . ' ' . $payment['created_by_last'] . ' (' . $payment['created_by_name'] . ')'); ?></td>
                </tr>
                <tr>
                    <th>Created At</th>
                    <td><?php echo date('F d, Y g:i A', strtotime($payment['created_at'])); ?></td>
                </tr>
                <?php if ($payment['updated_at']): ?>
                <tr>
                    <th>Last Updated</th>
                    <td><?php echo date('F d, Y g:i A', strtotime($payment['updated_at'])); ?></td>
                </tr>
                <?php endif; ?>
            </table>
        </div>

        <div class="print-total">
            <p><strong>Total Payment Amount: <?php echo formatCurrency($payment['payment_amount'], $settings); ?></strong></p>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
