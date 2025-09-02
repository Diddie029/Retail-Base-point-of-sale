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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt #<?php echo $sale_id; ?> - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
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
    <div class="dashboard-container">
        <?php include '../include/navmenu.php'; ?>

        <main class="main-content">
            <div class="container-fluid">
                <div class="row justify-content-center">
                    <div class="col-md-8">
                        <div class="d-flex justify-content-between align-items-center mb-4 navigation-buttons">
                            <div class="d-flex align-items-center gap-3">
                                <a href="../dashboard/dashboard.php" class="btn btn-outline-primary" title="Go to Dashboard">
                                    <i class="bi bi-speedometer2"></i> Dashboard
                                </a>
                                <h1 class="h3 mb-0">Sale Receipt</h1>
                            </div>
                            <div class="d-flex gap-2">
                                <a href="sale.php" class="btn btn-success">
                                    <i class="bi bi-plus-circle"></i> New Sale
                                </a>
                            </div>
                        </div>

                        <?php if ($success): ?>
                            <div class="success-alert">
                                <i class="bi bi-check-circle"></i>
                                <strong>Sale Completed Successfully!</strong>
                                <p class="mb-0 mt-1">Receipt #<?php echo $sale_id; ?> has been generated.</p>
                            </div>
                        <?php endif; ?>

                        <div class="print-section">
                            <button onclick="window.print()" class="btn btn-primary btn-print">
                                <i class="bi bi-printer"></i> Print Receipt
                            </button>
                            <button onclick="emailReceipt()" class="btn btn-outline-primary">
                                <i class="bi bi-envelope"></i> Email Receipt
                            </button>
                            <button onclick="shareReceipt()" class="btn btn-outline-secondary">
                                <i class="bi bi-share"></i> Share
                            </button>
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
                                        <div class="receipt-info-value">#<?php echo str_pad($sale_id, 6, '0', STR_PAD_LEFT); ?></div>
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
                                                <div class="item-meta">
                                                    <?php echo $item['quantity']; ?> × <?php echo $settings['currency_symbol']; ?><?php echo number_format($item['unit_price'], 2); ?>
                                                    <?php if (!empty($item['sku'])): ?>
                                                        | SKU: <?php echo htmlspecialchars($item['sku']); ?>
                                                    <?php endif; ?>
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
                                        <span><?php echo ucfirst(str_replace('_', ' ', $sale['payment_method'])); ?></span>
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
        function emailReceipt() {
            // This would integrate with email service
            const receiptNumber = '#<?php echo str_pad($sale_id, 6, '0', STR_PAD_LEFT); ?>';
            const customerEmail = prompt('Enter customer email address:');
            
            if (customerEmail && customerEmail.includes('@')) {
                // In real implementation, this would send to server
                alert(`Receipt ${receiptNumber} will be sent to ${customerEmail}\n\nNote: Email functionality will be fully implemented in the next update.`);
            }
        }

        function shareReceipt() {
            if (navigator.share) {
                navigator.share({
                    title: 'Receipt #<?php echo str_pad($sale_id, 6, '0', STR_PAD_LEFT); ?>',
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
                    window.print();
                }
            }, 1000);
        });
        <?php endif; ?>
    </script>
</body>
</html>
