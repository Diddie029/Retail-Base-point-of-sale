<?php
session_start();
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get invoice ID from URL
$invoice_id = isset($_GET['invoice_id']) ? (int)$_GET['invoice_id'] : 0;
$invoice_number = isset($_GET['invoice_number']) ? $_GET['invoice_number'] : '';
$sale_id = isset($_GET['sale_id']) ? (int)$_GET['sale_id'] : 0;
$action = isset($_GET['action']) ? $_GET['action'] : 'view';

// Handle different actions
switch ($action) {
    case 'create_from_sale':
        if (!$sale_id) {
            header("Location: ../pos/sale.php");
            exit();
        }
        // Create invoice from sale
        $result = createInvoiceFromSale($conn, $sale_id, $user_id);
        if ($result['success']) {
            header("Location: invoice.php?invoice_id=" . $result['invoice_id'] . "&created=1");
            exit();
        } else {
            $_SESSION['error_message'] = $result['error'];
            header("Location: ../pos/sale.php?sale_id=" . $sale_id);
            exit();
        }
        break;

    case 'view':
    default:
        if (!$invoice_id && !$invoice_number) {
            header("Location: invoices.php");
            exit();
        }
        break;
}

// Get invoice data for display
if ($invoice_number && !$invoice_id) {
    // Find invoice by invoice number
    $stmt = $conn->prepare("SELECT id FROM invoices WHERE invoice_number = :invoice_number");
    $stmt->execute([':invoice_number' => $invoice_number]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result) {
        $invoice_id = $result['id'];
    }
}

$invoice = getInvoice($conn, $invoice_id);
if (!$invoice) {
    header("Location: invoices.php?error=Invoice not found");
    exit();
}

// Calculate totals
$subtotal = $invoice['subtotal'];
$tax_amount = $invoice['tax_amount'];
$discount_amount = $invoice['discount_amount'];
$final_amount = $invoice['final_amount'];

// Get success message
$created = isset($_GET['created']) ? true : false;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice <?php echo htmlspecialchars($invoice['invoice_number']); ?> - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }

        body {
            background-color: #f8fafc;
            font-family: 'Inter', sans-serif;
            padding-bottom: 4rem;
        }

        .main-content {
            padding-bottom: 4rem;
        }

        .container-fluid {
            margin-bottom: 3rem;
        }

        /* Invoice Layout */
        .invoice-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            padding: 2rem;
            margin: 2rem auto;
            max-width: 900px;
        }

        .invoice-header {
            border-bottom: 3px solid var(--primary-color);
            padding-bottom: 1.5rem;
            margin-bottom: 2rem;
        }

        .company-name {
            font-size: 2rem;
            font-weight: 800;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }

        .company-details {
            font-size: 0.9rem;
            color: #6b7280;
            line-height: 1.5;
        }

        .invoice-title-section {
            text-align: right;
            margin-top: -1rem;
        }

        .invoice-title {
            font-size: 2.5rem;
            font-weight: 900;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }

        .invoice-number {
            font-size: 1.2rem;
            font-weight: 700;
            color: #374151;
            background: #f3f4f6;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            display: inline-block;
        }

        .invoice-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .info-section {
            background: #f8fafc;
            border-radius: 8px;
            padding: 1.5rem;
        }

        .info-section h6 {
            color: var(--primary-color);
            font-weight: 700;
            margin-bottom: 1rem;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }

        .info-label {
            font-weight: 600;
            color: #374151;
        }

        .info-value {
            color: #6b7280;
        }

        .invoice-items {
            margin-bottom: 2rem;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1rem;
        }

        .items-table th {
            background: #f8fafc;
            padding: 1rem;
            text-align: left;
            font-weight: 700;
            font-size: 0.85rem;
            color: #374151;
            border-bottom: 2px solid #e2e8f0;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .items-table td {
            padding: 1rem;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.9rem;
        }

        .items-table tbody tr:hover {
            background: #f8fafc;
        }

        .item-description {
            font-size: 0.8rem;
            color: #6b7280;
            margin-top: 0.25rem;
        }

        .invoice-totals {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 2rem;
        }

        .totals-table {
            width: 350px;
            border-collapse: collapse;
        }

        .totals-table td {
            padding: 0.75rem 1.5rem;
            border-bottom: 1px solid #e2e8f0;
        }

        .totals-table .total-row {
            font-weight: 900;
            font-size: 1.1rem;
            background: var(--primary-color);
            color: white;
        }

        .invoice-footer {
            border-top: 2px solid #e2e8f0;
            padding-top: 2rem;
        }

        .invoice-notes, .invoice-terms {
            background: #f8fafc;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .invoice-notes h6, .invoice-terms h6 {
            color: var(--primary-color);
            font-weight: 700;
            margin-bottom: 0.75rem;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .status-badge {
            display: inline-block;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .status-draft { background: #fef3c7; color: #92400e; }
        .status-sent { background: #dbeafe; color: #1e40af; }
        .status-paid { background: #d1fae5; color: #065f46; }
        .status-overdue { background: #fee2e2; color: #991b1b; }

        .btn-print {
            background: var(--primary-color);
            border: none;
            border-radius: 8px;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            color: white;
            transition: all 0.2s;
        }

        .btn-print:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px -8px rgba(99, 102, 241, 0.4);
        }

        .btn-secondary {
            background: #6b7280;
            border: none;
            border-radius: 8px;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            color: white;
            transition: all 0.2s;
        }

        .btn-secondary:hover {
            background: #4b5563;
            transform: translateY(-2px);
        }

        .alert {
            border-radius: 8px;
            border: none;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
        }

        /* Print Styles */
        @media print {
            body * {
                visibility: hidden;
            }
            .invoice-container, .invoice-container * {
                visibility: visible;
            }
            .invoice-container {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                box-shadow: none;
                border-radius: 0;
                margin: 0;
            }
            .no-print {
                display: none !important;
            }
        }
    </style>
</head>
<body>
    <?php include '../include/navmenu.php'; ?>
    <div class="main-content">
        <div class="container-fluid">

        <!-- Success Message -->
        <?php if ($created): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle"></i>
                Invoice <?php echo htmlspecialchars($invoice['invoice_number']); ?> created successfully from sale!
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Error Message -->
        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle"></i>
                <?php echo htmlspecialchars($_SESSION['error_message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>

        <div class="invoice-container">
            <!-- Invoice Header -->
            <div class="invoice-header">
                <div class="row">
                    <div class="col-md-6">
                        <div class="company-name"><?php echo htmlspecialchars($settings['company_name'] ?? 'Your Company Name'); ?></div>
                        <div class="company-details">
                            <?php if (!empty($settings['company_address'])): ?>
                                <?php echo nl2br(htmlspecialchars($settings['company_address'])); ?><br>
                            <?php endif; ?>
                            <?php if (!empty($settings['company_phone'])): ?>
                                Phone: <?php echo htmlspecialchars($settings['company_phone']); ?><br>
                            <?php endif; ?>
                            <?php if (!empty($settings['company_email'])): ?>
                                Email: <?php echo htmlspecialchars($settings['company_email']); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="invoice-title-section">
                            <div class="invoice-title">INVOICE</div>
                            <div class="invoice-number"><?php echo htmlspecialchars($invoice['invoice_number']); ?></div>
                            <div class="status-badge status-<?php echo $invoice['invoice_status']; ?> mt-2">
                                <?php echo ucfirst($invoice['invoice_status']); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Invoice Information -->
            <div class="invoice-info">
                <div class="info-section">
                    <h6><i class="bi bi-person"></i> Bill To</h6>
                    <div class="info-row">
                        <span class="info-label">Name:</span>
                        <span class="info-value"><?php echo htmlspecialchars($invoice['customer_name'] ?: 'Walk-in Customer'); ?></span>
                    </div>
                    <?php if (!empty($invoice['customer_email'])): ?>
                    <div class="info-row">
                        <span class="info-label">Email:</span>
                        <span class="info-value"><?php echo htmlspecialchars($invoice['customer_email']); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($invoice['customer_phone'])): ?>
                    <div class="info-row">
                        <span class="info-label">Phone:</span>
                        <span class="info-value"><?php echo htmlspecialchars($invoice['customer_phone']); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($invoice['customer_address'])): ?>
                    <div class="info-row">
                        <span class="info-label">Address:</span>
                        <span class="info-value"><?php echo htmlspecialchars($invoice['customer_address']); ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="info-section">
                    <h6><i class="bi bi-calendar"></i> Invoice Details</h6>
                    <div class="info-row">
                        <span class="info-label">Invoice Date:</span>
                        <span class="info-value"><?php echo date('M j, Y', strtotime($invoice['invoice_date'])); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Due Date:</span>
                        <span class="info-value"><?php echo date('M j, Y', strtotime($invoice['due_date'])); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Payment Terms:</span>
                        <span class="info-value"><?php echo htmlspecialchars($invoice['payment_terms']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Created By:</span>
                        <span class="info-value"><?php echo htmlspecialchars($invoice['created_by_name']); ?></span>
                    </div>
                </div>
            </div>

            <!-- Invoice Items -->
            <div class="invoice-items">
                <h6 style="color: var(--primary-color); font-weight: 700; margin-bottom: 1rem; text-transform: uppercase; letter-spacing: 0.05em; font-size: 0.9rem;">
                    <i class="bi bi-list-ul"></i> Invoice Items
                </h6>
                <table class="items-table">
                    <thead>
                        <tr>
                            <th style="width: 5%;">#</th>
                            <th style="width: 40%;">Description</th>
                            <th style="width: 15%;">Qty</th>
                            <th style="width: 20%;">Unit Price</th>
                            <th style="width: 20%;">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $counter = 1; ?>
                        <?php foreach ($invoice['items'] as $item): ?>
                        <tr>
                            <td><?php echo $counter++; ?></td>
                            <td>
                                <div class="fw-bold"><?php echo htmlspecialchars($item['product_name']); ?></div>
                                <?php if (!empty($item['product_sku'])): ?>
                                    <div class="item-description">SKU: <?php echo htmlspecialchars($item['product_sku']); ?></div>
                                <?php endif; ?>
                                <?php if (!empty($item['description'])): ?>
                                    <div class="item-description"><?php echo htmlspecialchars($item['description']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo number_format($item['quantity'], 2); ?></td>
                            <td>KES <?php echo number_format($item['unit_price'], 2); ?></td>
                            <td>KES <?php echo number_format($item['total_price'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Invoice Totals -->
            <div class="invoice-totals">
                <table class="totals-table">
                    <tr>
                        <td style="text-align: right;">Subtotal:</td>
                        <td style="text-align: right;">KES <?php echo number_format($subtotal, 2); ?></td>
                    </tr>
                    <?php if ($discount_amount > 0): ?>
                    <tr>
                        <td style="text-align: right;">Discount:</td>
                        <td style="text-align: right;">-KES <?php echo number_format($discount_amount, 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($tax_amount > 0): ?>
                    <tr>
                        <td style="text-align: right;">Tax:</td>
                        <td style="text-align: right;">KES <?php echo number_format($tax_amount, 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr class="total-row">
                        <td style="text-align: right; font-size: 1.2rem;">TOTAL:</td>
                        <td style="text-align: right; font-size: 1.2rem;">KES <?php echo number_format($final_amount, 2); ?></td>
                    </tr>
                </table>
            </div>

            <!-- Invoice Footer -->
            <div class="invoice-footer">
                <?php if (!empty($invoice['notes'])): ?>
                <div class="invoice-notes">
                    <h6><i class="bi bi-sticky"></i> Notes</h6>
                    <p style="margin: 0; color: #374151;"><?php echo nl2br(htmlspecialchars($invoice['notes'])); ?></p>
                </div>
                <?php endif; ?>

                <?php if (!empty($invoice['terms'])): ?>
                <div class="invoice-terms">
                    <h6><i class="bi bi-file-text"></i> Terms & Conditions</h6>
                    <p style="margin: 0; color: #374151;"><?php echo nl2br(htmlspecialchars($invoice['terms'])); ?></p>
                </div>
                <?php endif; ?>

                <div class="text-center">
                    <small class="text-muted">
                        This invoice was generated on <?php echo date('F j, Y \a\t g:i A'); ?>.
                        Payment is due by <?php echo date('M j, Y', strtotime($invoice['due_date'])); ?>.
                    </small>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="text-center no-print">
            <button onclick="window.print()" class="btn btn-print me-2">
                <i class="bi bi-printer"></i> Print Invoice
            </button>
            <button onclick="downloadPDF()" class="btn btn-secondary me-2">
                <i class="bi bi-download"></i> Download PDF
            </button>
            <button onclick="emailInvoice()" class="btn btn-secondary me-2">
                <i class="bi bi-envelope"></i> Email Invoice
            </button>
            <a href="invoices.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Back to Invoices
            </a>
        </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Auto-hide alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
                const alerts = document.querySelectorAll('.alert');
                alerts.forEach(function(alert) {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                });
            }, 5000);
        });

        function downloadPDF() {
            alert('PDF download functionality will be implemented with a PDF generation library like TCPDF or Dompdf.');
        }

        function emailInvoice() {
            const invoiceNumber = '<?php echo $invoice['invoice_number']; ?>';
            const customerEmail = '<?php echo $invoice['customer_email']; ?>';

            if (customerEmail) {
                const subject = encodeURIComponent(`Invoice ${invoiceNumber} from <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?>`);
                const body = encodeURIComponent(`Please find attached invoice ${invoiceNumber}.\n\nDue Date: <?php echo date('M j, Y', strtotime($invoice['due_date'])); ?>\n\nTotal: KES <?php echo number_format($final_amount, 2); ?>`);
                window.location.href = `mailto:${customerEmail}?subject=${subject}&body=${body}`;
            } else {
                alert('No customer email address available. Please add an email address to send the invoice.');
            }
        }
    </script>
</body>
</html>
