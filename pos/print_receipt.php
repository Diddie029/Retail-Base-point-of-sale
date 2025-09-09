<?php
session_start();
require_once __DIR__ . '/../include/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Get receipt data from URL parameter
$receiptData = null;
if (isset($_GET['data'])) {
    $receiptData = json_decode(urldecode($_GET['data']), true);
}

// If no data provided, redirect back
if (!$receiptData) {
    header("Location: sale.php");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt - <?php echo htmlspecialchars($receiptData['transaction_id']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <!-- JsBarcode for barcode generation -->
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
    <style>
        /* Print-specific styles */
        @media print {
            body {
                margin: 0 !important;
                padding: 20px !important;
                font-size: 12px !important;
                line-height: 1.4 !important;
                color: #000 !important;
                background: white !important;
            }

            /* Hide navigation buttons when printing */
            .navigation-buttons {
                display: none !important;
            }

            .no-print {
                display: none !important;
            }
            
            .receipt-container {
                max-width: none !important;
                width: 100% !important;
                margin: 0 !important;
                box-shadow: none !important;
                border: none !important;
                page-break-inside: avoid;
            }
            
            .receipt-header {
                text-align: center;
                margin-bottom: 20px;
            }
            
            .company-name {
                font-size: 18px !important;
                font-weight: bold !important;
                margin-bottom: 5px;
            }
            
            .company-address {
                font-size: 11px !important;
                line-height: 1.3 !important;
                margin-bottom: 15px;
            }
            
            .transaction-info {
                margin-bottom: 15px;
                font-size: 11px !important;
            }
            
            .items-table {
                width: 100% !important;
                border-collapse: collapse !important;
                margin-bottom: 15px;
            }
            
            .items-table th,
            .items-table td {
                padding: 4px 0 !important;
                border-bottom: 1px solid #ddd !important;
                font-size: 11px !important;
            }
            
            .items-table th {
                font-weight: bold !important;
                text-align: left !important;
            }
            
            .items-table .text-end {
                text-align: right !important;
            }
            
            .totals-section {
                margin-top: 10px;
                padding-top: 10px;
                border-top: 2px solid #000;
            }
            
            .total-row {
                display: flex;
                justify-content: space-between;
                margin-bottom: 3px;
                font-size: 11px !important;
            }
            
            .total-row.final {
                font-weight: bold !important;
                font-size: 13px !important;
                margin-top: 5px;
                padding-top: 5px;
                border-top: 1px solid #000;
            }
            
            .thank-you {
                text-align: center;
                margin-top: 20px;
                font-size: 10px !important;
            }
            
            .separator {
                text-align: center;
                margin: 15px 0;
                font-size: 10px;
            }
        }
        
        /* Screen styles */
        @media screen {
            body {
                background-color: #f8f9fa;
                padding: 20px;
                font-family: 'Courier New', monospace;
            }
            
            .receipt-container {
                max-width: 400px;
                margin: 0 auto;
                background: white;
                padding: 30px;
                border-radius: 8px;
                box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            }
            
            .print-controls {
                text-align: center;
                margin-bottom: 20px;
            }
            
            .receipt-header {
                text-align: center;
                margin-bottom: 25px;
                border-bottom: 2px solid #000;
                padding-bottom: 15px;
            }
            
            .company-name {
                font-size: 20px;
                font-weight: bold;
                margin-bottom: 8px;
            }
            
            .company-address {
                font-size: 12px;
                line-height: 1.4;
                color: #666;
                margin-bottom: 0;
            }
            
            .transaction-info {
                margin-bottom: 20px;
                font-size: 12px;
            }
            
            .info-row {
                display: flex;
                justify-content: space-between;
                margin-bottom: 4px;
            }
            
            .items-section {
                margin-bottom: 20px;
            }
            
            .items-table {
                width: 100%;
                border-collapse: collapse;
            }
            
            .items-table th,
            .items-table td {
                padding: 8px 0;
                border-bottom: 1px solid #eee;
                font-size: 12px;
            }
            
            .items-table th {
                font-weight: bold;
                text-align: left;
                border-bottom: 2px solid #000;
            }
            
            .totals-section {
                margin-top: 15px;
                padding-top: 15px;
                border-top: 2px solid #000;
            }
            
            .total-row {
                display: flex;
                justify-content: space-between;
                margin-bottom: 5px;
                font-size: 12px;
            }
            
            .total-row.final {
                font-weight: bold;
                font-size: 14px;
                margin-top: 8px;
                padding-top: 8px;
                border-top: 1px solid #000;
            }
            
            .thank-you {
                text-align: center;
                margin-top: 25px;
                font-size: 11px;
                color: #666;
                border-top: 1px dashed #ccc;
                padding-top: 15px;
            }
            
            .separator {
                text-align: center;
                margin: 20px 0;
                font-size: 12px;
                color: #999;
            }

            /* Barcode Section Styles */
            .barcode-section svg {
                max-width: 100%;
                height: auto;
            }

            @media print {
                .barcode-section {
                    background: white !important;
                    border: 1px solid #000 !important;
                    margin: 10px 0 !important;
                    padding: 5px 0 !important;
                }
            }
        }
    </style>
</head>
<body>
    <div class="receipt-container">
        <!-- Print Controls (only visible on screen) -->
        <div class="print-controls no-print">
            <button class="btn btn-primary me-2" onclick="window.print()">
                <i class="bi bi-printer"></i> Print Receipt
            </button>
            <button class="btn btn-secondary" onclick="window.close()">
                <i class="bi bi-x-circle"></i> Close
            </button>
        </div>

        <!-- Receipt Header -->
        <div class="receipt-header">
            <div class="company-name"><?php 
                // Follow memory specification: source company info from settings
                echo htmlspecialchars($receiptData['company_name'] ?: ($settings['company_name'] ?? 'POS System')); 
            ?></div>
            <div class="company-address"><?php 
                echo nl2br(htmlspecialchars($receiptData['company_address'] ?: ($settings['company_address'] ?? ''))); 
            ?></div>
        </div>

        <!-- Transaction Information -->
        <div class="transaction-info">
            <div class="info-row">
                <span><strong>Transaction ID:</strong></span>
                <span><?php echo htmlspecialchars($receiptData['transaction_id'] ?? $receiptData['sale_id'] ?? 'N/A'); ?></span>
            </div>
            <div class="info-row">
                <span><strong>Receipt #:</strong></span>
                <span><?php echo htmlspecialchars($receiptData['receipt_id'] ?? $receiptData['sale_id'] ?? 'N/A'); ?></span>
            </div>
            <div class="info-row">
                <span><strong>Date:</strong></span>
                <span><?php echo htmlspecialchars($receiptData['date'] ?? date('Y-m-d')); ?></span>
            </div>
            <div class="info-row">
                <span><strong>Time:</strong></span>
                <span><?php echo htmlspecialchars($receiptData['time'] ?? date('H:i:s')); ?></span>
            </div>
            <div class="info-row">
                <span><strong>Payment:</strong></span>
                <span><?php echo htmlspecialchars($receiptData['method'] ?? $receiptData['payment_method'] ?? 'Cash'); ?></span>
            </div>
            <?php if (!empty($receiptData['customer_name']) && $receiptData['customer_name'] !== 'Walk-in Customer'): ?>
            <div class="info-row">
                <span><strong>Customer:</strong></span>
                <span><?php echo htmlspecialchars($receiptData['customer_name']); ?></span>
            </div>
            <?php endif; ?>
        </div>

        <!-- Receipt Barcode -->
        <div class="barcode-section text-center" style="margin: 15px 0; padding: 10px 0; border: 1px dashed #ccc; border-left: none; border-right: none;">
            <svg id="receiptBarcode"></svg>
            <div style="font-size: 10px; color: #666; margin-top: 5px; font-family: 'Courier New', monospace; font-weight: bold; letter-spacing: 1px;">
                <?php echo htmlspecialchars($receiptData['transaction_id'] ?? $receiptData['sale_id'] ?? 'N/A'); ?>
            </div>
        </div>

        <!-- Items Section -->
        <div class="items-section">
            <table class="items-table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Qty</th>
                        <th class="text-end">Price</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($receiptData['items'])): ?>
                        <?php foreach ($receiptData['items'] as $item): ?>
                            <tr>
                                <td>
                                    <div><?php echo htmlspecialchars($item['name'] ?? $item['product_name'] ?? 'Item'); ?></div>
                                    <?php if (!empty($item['qty'])): ?>
                                        <small class="text-muted"><?php echo htmlspecialchars($item['qty']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                    // Handle both old and new data structures
                                    if (isset($item['quantity'])) {
                                        echo htmlspecialchars($item['quantity']);
                                    } else {
                                        // Extract quantity from qty string (e.g., "1 × $ 4.25" -> "1")
                                        $qtyParts = explode(' × ', $item['qty'] ?? '1');
                                        echo htmlspecialchars($qtyParts[0] ?? '1');
                                    }
                                    ?>
                                </td>
                                <td class="text-end">
                                    <?php 
                                    $currency_symbol = $settings['currency_symbol'] ?? 'KES';
                                    if (isset($item['total_price'])) {
                                        $total_price = is_numeric($item['total_price']) ? $item['total_price'] : 0;
                                        echo $currency_symbol . ' ' . number_format($total_price, 2);
                                    } elseif (isset($item['price'])) {
                                        $price = is_numeric($item['price']) ? $item['price'] : 0;
                                        echo $currency_symbol . ' ' . number_format($price, 2);
                                    } else {
                                        echo $currency_symbol . ' 0.00';
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="3">
                                <div>No items</div>
                            </td>
                            <td>0</td>
                            <td class="text-end"><?php echo $settings['currency_symbol'] ?? 'KES'; ?> 0.00</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Totals Section -->
        <div class="totals-section">
            <div class="total-row">
                <span>Subtotal:</span>
                <span><?php 
                    $currency_symbol = $settings['currency_symbol'] ?? 'KES';
                    if (isset($receiptData['subtotal'])) {
                        $subtotal = is_numeric($receiptData['subtotal']) ? $receiptData['subtotal'] : 0;
                        echo $currency_symbol . ' ' . number_format($subtotal, 2);
                    } else {
                        echo $currency_symbol . ' 0.00';
                    }
                ?></span>
            </div>
            <?php if (isset($receiptData['discount']) && $receiptData['discount'] > 0): ?>
            <div class="total-row">
                <span>Discount:</span>
                <span><?php 
                    $discount = is_numeric($receiptData['discount']) ? $receiptData['discount'] : 0;
                    echo $currency_symbol . ' ' . number_format($discount, 2); 
                ?></span>
            </div>
            <?php endif; ?>
            <div class="total-row">
                <span>Tax:</span>
                <span><?php 
                    if (isset($receiptData['tax'])) {
                        $tax = is_numeric($receiptData['tax']) ? $receiptData['tax'] : 0;
                        echo $currency_symbol . ' ' . number_format($tax, 2);
                    } else {
                        echo $currency_symbol . ' 0.00';
                    }
                ?></span>
            </div>
            <div class="total-row final">
                <span>TOTAL:</span>
                <span><?php 
                    if (isset($receiptData['final_amount'])) {
                        $final_amount = is_numeric($receiptData['final_amount']) ? $receiptData['final_amount'] : 0;
                        echo $currency_symbol . ' ' . number_format($final_amount, 2);
                    } elseif (isset($receiptData['amount'])) {
                        $amount = is_numeric($receiptData['amount']) ? $receiptData['amount'] : 0;
                        echo $currency_symbol . ' ' . number_format($amount, 2);
                    } else {
                        echo $currency_symbol . ' 0.00';
                    }
                ?></span>
            </div>
            <?php if (isset($receiptData['cash_received']) && isset($receiptData['change_due'])): ?>
            <div class="total-row">
                <span>Cash Received:</span>
                <span><?php 
                    $cash_received = is_numeric($receiptData['cash_received']) ? $receiptData['cash_received'] : 0;
                    echo $currency_symbol . ' ' . number_format($cash_received, 2); 
                ?></span>
            </div>
            <div class="total-row">
                <span>Change Due:</span>
                <span><?php 
                    $change_due = is_numeric($receiptData['change_due']) ? $receiptData['change_due'] : 0;
                    echo $currency_symbol . ' ' . number_format($change_due, 2); 
                ?></span>
            </div>
            <?php endif; ?>
        </div>

        <!-- Thank You Message -->
        <div class="thank-you">
            <p><strong>Thank you for your business!</strong><br>
            Please keep this receipt for your records</p>
        </div>

        <!-- Separator for cutting -->
        <div class="separator">
            - - - - - - - - - - - - - - - - - - - - - -
        </div>
    </div>

    <!-- Navigation Buttons (visible on screen, hidden when printing) -->
    <div class="navigation-buttons" style="margin-top: 20px; text-align: center; display: none;" id="navButtons">
        <button onclick="goBackToPOS()" class="btn btn-primary" style="margin: 0 10px;">
            <i class="bi bi-arrow-left"></i> Back to POS
        </button>
        <button onclick="printAgain()" class="btn btn-secondary" style="margin: 0 10px;">
            <i class="bi bi-printer"></i> Print Again
        </button>
        <button onclick="startNewSale()" class="btn btn-success" style="margin: 0 10px;">
            <i class="bi bi-plus-circle"></i> New Sale
        </button>
    </div>

    <script>
        // Generate barcode for transaction ID
        document.addEventListener('DOMContentLoaded', function() {
            const transactionId = '<?php echo htmlspecialchars($receiptData['transaction_id'] ?? $receiptData['sale_id'] ?? 'N/A'); ?>';
            
            JsBarcode("#receiptBarcode", transactionId, {
                format: "CODE128",
                width: 1.5,
                height: 30,
                displayValue: false,
                background: "transparent",
                lineColor: "#000000",
                margin: 0
            });
        });

        // Auto-print on page load (controlled by parameter)
        const urlParams = new URLSearchParams(window.location.search);
        const autoPrint = urlParams.get('auto_print');
        
        if (autoPrint === 'true') {
            window.addEventListener('load', function() {
                setTimeout(function() {
                    window.print();
                }, 2000); // 2 second delay for auto-print
            });
        }
        
        // Close window after printing
        window.addEventListener('afterprint', function() {
            // Optionally close the window after printing
            // setTimeout(function() {
            //     window.close();
            // }, 1000);
        });

        // Show navigation buttons after page loads
        window.addEventListener('load', function() {
            // Show navigation buttons after a delay (to allow for auto-print)
            setTimeout(function() {
                document.getElementById('navButtons').style.display = 'block';
            }, 3000); // 3 seconds delay
        });

        // Navigation functions
        function goBackToPOS() {
            window.location.href = 'sale.php';
        }

        function printAgain() {
            window.print();
        }

        function startNewSale() {
            // Clear any existing cart/session data and go to POS
            window.location.href = 'sale.php?new_sale=true';
        }
    </script>
</body>
</html>
