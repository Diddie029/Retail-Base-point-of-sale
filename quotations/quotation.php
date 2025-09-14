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

// Get quotation ID from URL
$quotation_id = isset($_GET['quotation_id']) ? (int)$_GET['quotation_id'] : 0;
$action = isset($_GET['action']) ? $_GET['action'] : 'view';

if (!$quotation_id && $action !== 'create') {
    header("Location: quotations.php");
    exit();
}

// Handle different actions
switch ($action) {
    case 'create':
        // Show quotation creation form with navigation
        include 'quotation_create.php';
        exit();
    case 'edit':
        // Load quotation data for editing
        $quotation = getQuotation($conn, $quotation_id);
        if (!$quotation) {
            header("Location: quotations.php?error=Quotation not found");
            exit();
        }
        // Show quotation edit form with navigation
        include 'quotation_edit.php';
        exit();
    case 'view':
    default:
        // Show quotation display
        $quotation = getQuotation($conn, $quotation_id);
        if (!$quotation) {
            header("Location: quotations.php?error=Quotation not found");
            exit();
        }
        break;
}

// Get quotation data for display
$sale_date = new DateTime($quotation['created_at']);
$valid_date = new DateTime($quotation['valid_until']);

// Calculate totals
$subtotal = $quotation['subtotal'];
$tax_amount = $quotation['tax_amount'];
$discount_amount = $quotation['discount_amount'];
$final_amount = $quotation['final_amount'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quotation <?php echo htmlspecialchars($quotation['quotation_number']); ?> - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <!-- JsBarcode for barcode generation -->
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }

        /* Clean Quotation Layout */
        .quotation-layout {
            min-height: 100vh;
            background: #f8fafc;
        }

        .quotation-header-bar {
            background: white;
            border-bottom: 1px solid #e2e8f0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        .quotation-brand h5 {
            font-weight: 700;
            margin: 0;
        }

        .quotation-brand small {
            color: #64748b;
        }

        .quotation-controls {
            display: flex;
            gap: 0.5rem;
        }

        .quotation-main-content {
            padding: 2rem 0;
        }

        .quotation-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .quotation-header {
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 1rem;
            margin-bottom: 1.5rem;
        }

        .company-name {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }

        .company-details {
            font-size: 0.875rem;
            color: #64748b;
            line-height: 1.4;
        }

        .quotation-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .info-section {
            background: #f8fafc;
            border-radius: 8px;
            padding: 1rem;
        }

        .info-section h6 {
            color: var(--primary-color);
            font-weight: 600;
            margin-bottom: 0.75rem;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.25rem;
            font-size: 0.875rem;
        }

        .info-label {
            font-weight: 500;
            color: #374151;
        }

        .info-value {
            color: #6b7280;
        }

        .quotation-items {
            margin-bottom: 2rem;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1rem;
        }

        .items-table th {
            background: #f8fafc;
            padding: 0.75rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.875rem;
            color: #374151;
            border-bottom: 1px solid #e2e8f0;
        }

        .items-table td {
            padding: 0.75rem;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.875rem;
        }

        .items-table tbody tr:hover {
            background: #f8fafc;
        }

        .quotation-totals {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 2rem;
        }

        .totals-table {
            width: 300px;
            border-collapse: collapse;
        }

        .totals-table td {
            padding: 0.5rem 1rem;
            border-bottom: 1px solid #e2e8f0;
        }

        .totals-table .total-row {
            font-weight: 700;
            background: var(--primary-color);
            color: white;
        }

        .quotation-footer {
            border-top: 1px solid #e2e8f0;
            padding-top: 1.5rem;
        }

        .quotation-terms {
            background: #f8fafc;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .quotation-notes {
            background: #fef3c7;
            border: 1px solid #fde68a;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-draft {
            background: #fef3c7;
            color: #92400e;
        }

        .status-sent {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-approved {
            background: #d1fae5;
            color: #065f46;
        }

        .status-rejected {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-expired {
            background: #f3f4f6;
            color: #374151;
        }

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
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.4);
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
            transform: translateY(-1px);
        }

        @media print {
            body * {
                visibility: hidden;
            }
            .quotation-container, .quotation-container * {
                visibility: visible;
            }
            .quotation-container {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                box-shadow: none;
                border-radius: 0;
            }
            .no-print {
                display: none !important;
            }
        }
    </style>
</head>
<body>
    <?php include '../include/navmenu.php'; ?>
    <div class="main-content" style="padding-bottom: 4rem;">
        <!-- Clean Quotation Layout -->
        <div class="quotation-layout" style="margin-bottom: 3rem;">
        <!-- Header with Navigation Controls (Hidden) -->
        <div class="quotation-header-bar" style="display: none;">
            <div class="container-fluid px-4 py-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="quotation-brand">
                        <h5 class="mb-0 text-primary">
                            <i class="bi bi-file-earmark-text"></i>Quotation
                        </h5>
                        <small><?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></small>
                    </div>
                    <div class="quotation-controls">
                        <a href="../dashboard/dashboard.php" class="btn btn-outline-primary btn-sm me-2">
                            <i class="bi bi-speedometer2"></i> Dashboard
                        </a>
                        <a href="quotation.php?action=create" class="btn btn-success btn-sm">
                            <i class="bi bi-plus-circle"></i> New Quotation
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <main class="quotation-main-content">
            <div class="container-fluid">
                <div class="row justify-content-center">
                    <div class="col-md-10">
                        <div class="quotation-container">
                            <!-- Quotation Header -->
                            <div class="quotation-header">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="company-name"><?php echo htmlspecialchars($settings['company_name'] ?? 'Your Store Name'); ?></div>
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
                                    <div class="text-end">
                                        <h3 class="mb-1">QUOTATION</h3>
                                        <div class="quotation-number">#<?php echo htmlspecialchars($quotation['quotation_number']); ?></div>
                                        <div class="status-badge status-<?php echo $quotation['quotation_status']; ?>">
                                            <?php echo ucfirst($quotation['quotation_status']); ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Quotation Information -->
                            <div class="quotation-info">
                                <div class="info-section">
                                    <h6><i class="bi bi-person"></i> Customer Information</h6>
                                    <div class="info-row">
                                        <span class="info-label">Name:</span>
                                        <span class="info-value"><?php echo htmlspecialchars($quotation['customer_name'] ?: 'Walk-in Customer'); ?></span>
                                    </div>
                                    <?php if (!empty($quotation['customer_email'])): ?>
                                    <div class="info-row">
                                        <span class="info-label">Email:</span>
                                        <span class="info-value"><?php echo htmlspecialchars($quotation['customer_email']); ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($quotation['customer_phone'])): ?>
                                    <div class="info-row">
                                        <span class="info-label">Phone:</span>
                                        <span class="info-value"><?php echo htmlspecialchars($quotation['customer_phone']); ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($quotation['customer_address'])): ?>
                                    <div class="info-row">
                                        <span class="info-label">Address:</span>
                                        <span class="info-value"><?php echo htmlspecialchars($quotation['customer_address']); ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <div class="info-section">
                                    <h6><i class="bi bi-calendar"></i> Quotation Details</h6>
                                    <div class="info-row">
                                        <span class="info-label">Date:</span>
                                        <span class="info-value"><?php echo $sale_date->format('M j, Y'); ?></span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Valid Until:</span>
                                        <span class="info-value"><?php echo $valid_date->format('M j, Y'); ?></span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Created By:</span>
                                        <span class="info-value"><?php echo htmlspecialchars($quotation['created_by']); ?></span>
                                    </div>
                                </div>
                            </div>

                            <!-- Quotation Items -->
                            <div class="quotation-items">
                                <h6 style="color: var(--primary-color); font-weight: 600; margin-bottom: 1rem;">
                                    <i class="bi bi-list-ul"></i> Items
                                </h6>
                                <table class="items-table">
                                    <thead>
                                        <tr>
                                            <th style="width: 5%;">#</th>
                                            <th style="width: 35%;">Description</th>
                                            <th style="width: 15%;">Qty</th>
                                            <th style="width: 20%;">Unit Price</th>
                                            <th style="width: 25%;">Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $counter = 1; ?>
                                        <?php foreach ($quotation['items'] as $item): ?>
                                        <tr>
                                            <td><?php echo $counter++; ?></td>
                                            <td>
                                                <div class="fw-bold"><?php echo htmlspecialchars($item['product_name']); ?></div>
                                                <?php if (!empty($item['product_sku'])): ?>
                                                    <small class="text-muted">SKU: <?php echo htmlspecialchars($item['product_sku']); ?></small>
                                                <?php endif; ?>
                                                <?php if (!empty($item['description'])): ?>
                                                    <br><small><?php echo htmlspecialchars($item['description']); ?></small>
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

                            <!-- Quotation Totals -->
                            <div class="quotation-totals">
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
                                        <td style="text-align: right; font-size: 1.1rem;">TOTAL:</td>
                                        <td style="text-align: right; font-size: 1.1rem;">KES <?php echo number_format($final_amount, 2); ?></td>
                                    </tr>
                                </table>
                            </div>

                            <!-- Quotation Footer -->
                            <div class="quotation-footer">
                                <?php if (!empty($quotation['notes'])): ?>
                                <div class="quotation-notes">
                                    <h6 style="color: #92400e; margin-bottom: 0.5rem;">
                                        <i class="bi bi-sticky"></i> Notes
                                    </h6>
                                    <p style="margin: 0; color: #92400e;"><?php echo nl2br(htmlspecialchars($quotation['notes'])); ?></p>
                                </div>
                                <?php endif; ?>

                                <?php if (!empty($quotation['terms'])): ?>
                                <div class="quotation-terms">
                                    <h6 style="color: var(--primary-color); margin-bottom: 0.5rem;">
                                        <i class="bi bi-file-text"></i> Terms & Conditions
                                    </h6>
                                    <p style="margin: 0;"><?php echo nl2br(htmlspecialchars($quotation['terms'])); ?></p>
                                </div>
                                <?php endif; ?>

                                <div class="text-center">
                                    <small class="text-muted">
                                        This quotation is valid until <?php echo $valid_date->format('F j, Y'); ?>. Prices are subject to change without notice.
                                    </small>
                                </div>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="text-center no-print">
                            <button onclick="window.print()" class="btn btn-print me-2">
                                <i class="bi bi-printer"></i> Print Quotation
                            </button>
                            <a href="quotation.php?action=edit&amp;quotation_id=<?php echo $quotation_id; ?>" class="btn btn-warning me-2">
                                <i class="bi bi-pencil-square"></i> Edit Quotation
                            </a>
                            <button onclick="emailQuotation()" class="btn btn-secondary me-2">
                                <i class="bi bi-envelope"></i> Email Quotation
                            </button>
                            <button onclick="downloadPDF()" class="btn btn-secondary me-2">
                                <i class="bi bi-download"></i> Download PDF
                            </button>
                            <a href="quotations.php" class="btn btn-secondary">
                                <i class="bi bi-arrow-left"></i> Back to Quotations
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </main>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Generate barcode for quotation number
        document.addEventListener('DOMContentLoaded', function() {
            const quotationNumber = '<?php echo $quotation['quotation_number']; ?>';

            // Use JsBarcode if element exists
            if (document.getElementById('quotationBarcode')) {
                JsBarcode("#quotationBarcode", quotationNumber, {
                    format: "CODE128",
                    width: 2,
                    height: 40,
                    displayValue: false,
                    background: "transparent",
                    lineColor: "#000000",
                    margin: 0
                });
            }
        });

        function emailQuotation() {
            const quotationNumber = '<?php echo $quotation['quotation_number']; ?>';
            const customerEmail = '<?php echo $quotation['customer_email']; ?>';

            if (customerEmail) {
                const subject = encodeURIComponent(`Quotation ${quotationNumber} from <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?>`);
                const body = encodeURIComponent(`Please find attached quotation ${quotationNumber}.\n\nValid until: <?php echo $valid_date->format('F j, Y'); ?>\n\nTotal: KES <?php echo number_format($final_amount, 2); ?>`);
                window.location.href = `mailto:${customerEmail}?subject=${subject}&body=${body}`;
            } else {
                alert('No customer email address available. Please add an email address to send the quotation.');
            }
        }

        function downloadPDF() {
            // This would integrate with a PDF generation library
            alert('PDF download functionality will be implemented with a PDF generation library like TCPDF or Dompdf.');
        }
    </script>
</body>
</html>
