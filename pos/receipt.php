<?php
session_start();
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Get user information
$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get sale ID from URL
$sale_id = isset($_GET['sale_id']) ? (int)$_GET['sale_id'] : 0;
$success = isset($_GET['success']) ? true : false;

if (!$sale_id) {
    header("Location: sale.php");
    exit();
}

// Get sale details
$stmt = $conn->prepare("
    SELECT s.*, u.username as cashier_name
    FROM sales s
    JOIN users u ON s.user_id = u.id
    WHERE s.id = :sale_id
");
$stmt->execute([':sale_id' => $sale_id]);
$sale = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sale) {
    header("Location: sale.php");
    exit();
}

// Get sale items
$stmt = $conn->prepare("
    SELECT si.*, p.name as product_name, p.sku
    FROM sale_items si
    LEFT JOIN products p ON si.product_id = p.id
    WHERE si.sale_id = :sale_id
    ORDER BY si.id
");
$stmt->execute([':sale_id' => $sale_id]);
$sale_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Format date
$sale_date = new DateTime($sale['created_at']);

// Generate formatted receipt number
$formatted_receipt_number = generateReceiptNumber($sale_id, $sale['created_at']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt <?php echo $formatted_receipt_number; ?> - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
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

        /* Clean Receipt Layout */
        .receipt-layout {
            min-height: 100vh;
            background: #f8fafc;
        }

        .receipt-header-bar {
            background: white;
            border-bottom: 1px solid #e2e8f0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        .receipt-brand h5 {
            font-weight: 700;
            margin: 0;
        }

        .receipt-brand small {
            color: #64748b;
        }

        .receipt-main-content {
            padding: 2rem 0;
        }

        .receipt-controls .btn {
            font-weight: 600;
        }

        .container-fluid {
            max-width: 1200px;
        }

        .success-alert {
            background: linear-gradient(135deg, #d1fae5, #a7f3d0);
            border: 1px solid #10b981;
            color: #065f46;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 2rem;
            text-align: center;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.1);
        }

        .print-section {
            margin-bottom: 2rem;
            text-align: center;
            padding: 1.5rem;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .btn-print {
            margin-right: 0.5rem;
        }

        @media print {
            .receipt-header-bar,
            .print-section,
            .navigation-buttons {
                display: none !important;
            }
            
            .receipt-container {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                max-width: none;
                box-shadow: none;
                border: none;
                margin: 0;
            }
        }

        .receipt-container {
            max-width: 400px;
            margin: 0 auto;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            overflow: hidden;
        }

        .receipt-header {
            background: var(--primary-color);
            color: white;
            padding: 20px;
            text-align: center;
        }

        .company-name {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .company-details {
            font-size: 0.8rem;
            opacity: 0.9;
        }

        .receipt-body {
            padding: 20px;
        }

        .receipt-info {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px dashed #d1d5db;
        }

        .receipt-info-item {
            text-align: center;
        }

        .receipt-info-label {
            font-size: 0.7rem;
            color: #6b7280;
            text-transform: uppercase;
            margin-bottom: 2px;
        }

        .receipt-info-value {
            font-size: 0.9rem;
            font-weight: 600;
            color: #111827;
        }

        .items-list {
            margin-bottom: 20px;
        }

        .item-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px dotted #e5e7eb;
        }

        .item-row:last-child {
            border-bottom: none;
        }

        .item-details {
            flex: 1;
            margin-right: 10px;
        }

        .item-name {
            font-weight: 500;
            margin-bottom: 2px;
            font-size: 0.9rem;
        }

        .item-sku {
            font-size: 0.7rem;
            color: #3b82f6;
            font-weight: 600;
            background: #eff6ff;
            padding: 0.2rem 0.4rem;
            border-radius: 3px;
            display: inline-block;
            margin-bottom: 3px;
            border: 1px solid #dbeafe;
        }

        .item-meta {
            font-size: 0.75rem;
            color: #6b7280;
        }

        .item-amount {
            font-weight: 600;
            color: #111827;
            text-align: right;
            min-width: 80px;
            font-size: 0.9rem;
        }

        .totals-section {
            border-top: 2px solid #e5e7eb;
            padding-top: 15px;
            margin-top: 15px;
        }

        .total-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
        }

        .total-row.grand-total {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--primary-color);
            border-top: 1px solid #e5e7eb;
            padding-top: 8px;
            margin-top: 8px;
        }

        .receipt-footer {
            text-align: center;
            padding: 15px 20px;
            background: #f9fafb;
            border-top: 1px solid #e5e7eb;
            font-size: 0.75rem;
            color: #6b7280;
        }

        /* Barcode Section Styles */
        .barcode-section {
            padding: 15px 0;
            border: 1px dashed #d1d5db;
            border-left: none;
            border-right: none;
            margin: 20px 0;
            background: #fafbfc;
        }

        .barcode-section svg {
            max-width: 100%;
            height: auto;
        }

        .barcode-text {
            font-family: 'Courier New', monospace;
            font-weight: 600;
            letter-spacing: 2px;
        }

        @media print {
            .barcode-section {
                background: white !important;
                border: 1px solid #000 !important;
                margin: 10px 0 !important;
                padding: 10px 0 !important;
            }
            
            .barcode-text {
                font-size: 10px !important;
                color: #000 !important;
            }
        }

        .success-alert {
            background: #d1fae5;
            border: 1px solid #10b981;
            color: #065f46;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            text-align: center;
        }

        .print-section {
            margin-bottom: 20px;
            text-align: center;
        }

        .btn-print {
            margin-right: 10px;
        }

        @media print {
            body * {
                visibility: hidden;
            }
            .receipt-container, .receipt-container * {
                visibility: visible;
            }
            .receipt-container {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                max-width: none;
                box-shadow: none;
                border: none;
            }
            .print-section, .navigation-buttons {
                display: none !important;
            }
        }
    </style>
</head>
<body>
    <!-- Clean Receipt Layout without Navigation -->
    <div class="receipt-layout">
        <!-- Header with Navigation Controls (Hidden) -->
        <div class="receipt-header-bar" style="display: none;">
            <div class="container-fluid px-4 py-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="receipt-brand">
                        <h5 class="mb-0 text-primary">
                            <i class="bi bi-receipt me-2"></i>Sale Receipt
                        </h5>
                        <small class="text-muted"><?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></small>
                    </div>
                    <div class="receipt-controls">
                        <a href="../dashboard/dashboard.php" class="btn btn-outline-primary btn-sm me-2">
                            <i class="bi bi-speedometer2"></i> Dashboard
                        </a>
                        <a href="sale.php" class="btn btn-success btn-sm">
                            <i class="bi bi-plus-circle"></i> New Sale
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <main class="receipt-main-content">
            <div class="container-fluid">
                <div class="row justify-content-center">
                    <div class="col-md-8">
                        <?php if ($success): ?>
                            <div class="success-alert">
                                <i class="bi bi-check-circle"></i>
                                <strong>Sale Completed Successfully!</strong>
                                <p class="mb-0 mt-1">Receipt #<?php echo $formatted_receipt_number; ?> has been generated.</p>
                            </div>
                        <?php endif; ?>

                        <div class="print-section">
                            <button onclick="printReceipt()" class="btn btn-primary btn-print">
                                <i class="bi bi-printer"></i> Print Receipt
                            </button>
                            <button onclick="emailReceipt()" class="btn btn-outline-primary">
                                <i class="bi bi-envelope"></i> Email Receipt
                            </button>
                            <button onclick="shareReceipt()" class="btn btn-outline-secondary">
                                <i class="bi bi-share"></i> Share
                            </button>
                            <a href="sale.php" class="btn btn-success">
                                <i class="bi bi-plus-circle"></i> New Sale
                            </a>
                        </div>

                        <div class="receipt-container">
                            <!-- Receipt Header -->
                            <div class="receipt-header">
                                <div class="company-name"><?php echo htmlspecialchars($settings['company_name'] ?? 'Your Store Name'); ?></div>
                                <div class="company-details">
                                    <?php if (!empty($settings['company_address'])): ?>
                                        <?php echo htmlspecialchars($settings['company_address']); ?><br>
                                    <?php endif; ?>
                                    <?php if (!empty($settings['company_phone'])): ?>
                                        Phone: <?php echo htmlspecialchars($settings['company_phone']); ?><br>
                                    <?php endif; ?>
                                    <?php if (!empty($settings['company_email'])): ?>
                                        Email: <?php echo htmlspecialchars($settings['company_email']); ?>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Receipt Body -->
                            <div class="receipt-body">
                                <!-- Transaction Info -->
                                <div class="receipt-info">
                                    <div class="receipt-info-item">
                                        <div class="receipt-info-label">Receipt No.</div>
                                        <div class="receipt-info-value">#<?php echo $formatted_receipt_number; ?></div>
                                    </div>
                                    <div class="receipt-info-item">
                                        <div class="receipt-info-label">Date & Time</div>
                                        <div class="receipt-info-value">
                                            <?php echo $sale_date->format('M j, Y'); ?><br>
                                            <small><?php echo $sale_date->format('g:i A'); ?></small>
                                        </div>
                                    </div>
                                    <div class="receipt-info-item">
                                        <div class="receipt-info-label">Cashier</div>
                                        <div class="receipt-info-value"><?php echo htmlspecialchars($sale['cashier_name']); ?></div>
                                    </div>
                                </div>

                                <!-- Receipt Barcode -->
                                <div class="barcode-section text-center my-3">
                                    <svg id="receiptBarcode"></svg>
                                    <div class="barcode-text" style="font-size: 0.75rem; color: #6b7280; margin-top: 5px;">
                                        <?php echo $formatted_receipt_number; ?>
                                    </div>
                                </div>

                                <!-- Items List -->
                                <div class="items-list">
                                    <?php foreach ($sale_items as $item): ?>
                                        <div class="item-row">
                                            <div class="item-details">
                                                <div class="item-name">
                                                    <?php echo htmlspecialchars($item['product_name'] ?: $item['product_name']); ?>
                                                    <?php if ($item['is_auto_bom']): ?>
                                                        <span class="badge bg-info">Auto BOM</span>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if (!empty($item['sku'])): ?>
                                                    <div class="item-sku">
                                                        SKU: <?php echo htmlspecialchars($item['sku']); ?>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="item-meta">
                                                    <?php echo $item['quantity']; ?> × <?php echo $settings['currency_symbol']; ?><?php echo number_format($item['unit_price'], 2); ?>
                                                </div>
                                            </div>
                                            <div class="item-amount">
                                                <?php echo $settings['currency_symbol']; ?><?php echo number_format($item['total_price'], 2); ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <!-- Totals Section -->
                                <div class="totals-section">
                                    <div class="total-row">
                                        <span>Subtotal:</span>
                                        <span><?php echo $settings['currency_symbol']; ?><?php echo number_format($sale['subtotal'], 2); ?></span>
                                    </div>

                                    <?php if ($sale['tax_amount'] > 0): ?>
                                        <div class="total-row">
                                            <span>Tax (<?php echo $sale['tax_rate']; ?>%):</span>
                                            <span><?php echo $settings['currency_symbol']; ?><?php echo number_format($sale['tax_amount'], 2); ?></span>
                                        </div>
                                    <?php endif; ?>

                                    <div class="total-row grand-total">
                                        <span>Total:</span>
                                        <span><?php echo $settings['currency_symbol']; ?><?php echo number_format($sale['total_amount'], 2); ?></span>
                                    </div>

                                    <div class="total-row" style="margin-top: 10px;">
                                        <span>Payment Method:</span>
                                        <span><?php echo htmlspecialchars(format_payment_method_label($sale['payment_method'] ?? null), ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                </div>
                            </div>

                            <!-- Receipt Footer -->
                            <div class="receipt-footer">
                                <p class="mb-1">Thank you for your business!</p>
                                <?php if (!empty($settings['receipt_footer_text'])): ?>
                                    <p class="mb-1"><?php echo htmlspecialchars($settings['receipt_footer_text']); ?></p>
                                <?php endif; ?>
                                <p class="mb-0">
                                    <small>
                                        This is a computer-generated receipt.<br>
                                        Generated on <?php echo (new DateTime())->format('M j, Y g:i A'); ?>
                                    </small>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/dashboard.js"></script>

    <script>
        // Generate barcode for receipt number
        document.addEventListener('DOMContentLoaded', function() {
            const formattedReceiptNumber = '<?php echo $formatted_receipt_number; ?>';
            
            JsBarcode("#receiptBarcode", formattedReceiptNumber, {
                format: "CODE128",
                width: 2,
                height: 40,
                displayValue: false,
                background: "transparent",
                lineColor: "#000000",
                margin: 0
            });
        });

        function emailReceipt() {
            // This would integrate with email service
            const receiptNumber = '#<?php echo $formatted_receipt_number; ?>';
            const customerEmail = prompt('Enter customer email address:');
            
            if (customerEmail && customerEmail.includes('@')) {
                // In real implementation, this would send to server
                alert(`Receipt ${receiptNumber} will be sent to ${customerEmail}\n\nNote: Email functionality will be fully implemented in the next update.`);
            }
        }

        function printReceipt() {
            // Prevent navigation by handling print in a controlled way
            try {
                window.print();
            } catch (error) {
                console.error('Print error:', error);
                alert('Unable to print. Please try using your browser\'s print function (Ctrl+P).');
            }
            // Ensure we stay on the current page
            return false;
        }

        function shareReceipt() {
            if (navigator.share) {
                navigator.share({
                    title: 'Receipt #<?php echo $formatted_receipt_number; ?>',
                    text: 'Sale receipt from <?php echo htmlspecialchars($settings['company_name'] ?? 'Store'); ?>',
                    url: window.location.href
                });
            } else {
                // Fallback for browsers that don't support Web Share API
                const url = window.location.href;
                navigator.clipboard.writeText(url).then(() => {
                    alert('Receipt link copied to clipboard!');
                }).catch(() => {
                    prompt('Copy this link to share the receipt:', url);
                });
            }
        }

        // Auto-focus print dialog if success parameter is present
        <?php if ($success): ?>
        document.addEventListener('DOMContentLoaded', function() {
            // Small delay to allow page to fully load
            setTimeout(() => {
                const shouldPrint = confirm('Would you like to print the receipt now?');
                if (shouldPrint) {
                    printReceipt();
                }
            }, 1000);
        });
        <?php endif; ?>
    </script>
</body>
</html>
