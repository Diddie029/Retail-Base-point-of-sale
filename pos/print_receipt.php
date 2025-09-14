<?php
session_start();
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Helper function to extract numeric value from currency strings
function extractNumericValue($value) {
    if (is_numeric($value)) {
        return $value;
    }
    // Remove currency symbols and extract number
    $numeric = preg_replace('/[^\d.-]/', '', $value);
    return is_numeric($numeric) ? $numeric : 0;
}

// Helper function to generate receipt number based on settings
function generateReceiptNumberFromSettings($saleId, $settings, $date = null) {
    if ($date === null) {
        $date = date('Y-m-d');
    }
    
    $format = $settings['receipt_number_format'] ?? 'prefix-date-number';
    $prefix = $settings['receipt_number_prefix'] ?? 'RCP';
    $separator = $settings['receipt_number_separator'] ?? '-';
    $length = (int)($settings['receipt_number_length'] ?? 6);
    
    // Pad sale ID to required length
    $paddedId = str_pad($saleId, $length, '0', STR_PAD_LEFT);
    $dateFormatted = date('Ymd', strtotime($date));
    
    switch($format) {
        case 'prefix-date-number':
            return $prefix . $separator . $dateFormatted . $separator . $paddedId;
        case 'prefix-number':
            return $prefix . $separator . $paddedId;
        case 'date-prefix-number':
            return $dateFormatted . $separator . $prefix . $separator . $paddedId;
        case 'number-only':
            return $paddedId;
        default:
            return $prefix . $separator . $dateFormatted . $separator . $paddedId;
    }
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
        @page { size: 80mm auto; margin: 0mm; }

        @media print {
            html, body {
                margin: 0 !important;
                padding: 0 !important;
                /* Slightly increased base font size for better thermal visibility */
                font-size: 13px !important;
                line-height: 1.35 !important;
                color: #000 !important;
                background: white !important;
                -webkit-print-color-adjust: exact !important;
            }

            /* Hide navigation buttons when printing */
            .navigation-buttons {
                display: none !important;
            }

            .no-print {
                display: none !important;
            }
            
            /* Make receipt narrow and thermal-friendly (80mm width) */
            .receipt-container {
                max-width: 80mm !important;
                width: 80mm !important;
                margin: 0 auto !important;
                box-shadow: none !important;
                border: none !important;
                page-break-inside: avoid;
                padding: 4px 2px !important;
                font-family: 'Courier New', monospace !important;
                /* Force bold for thermal printer readability */
                font-weight: bold !important;
                /* Let the container size naturally and avoid forced extra height */
                display: block !important;
                height: auto !important;
                overflow: visible !important;
            }
            
            .receipt-header {
                text-align: center;
                margin-bottom: 20px;
            }
            
            /* Make all header and address text bold and slightly larger for visibility */
            .company-name {
                font-size: 18px !important;
                margin-bottom: 4px !important;
            }
            
            .company-address {
                font-size: 11px !important;
                line-height: 1.2 !important;
                margin-bottom: 6px !important;
                color: #000 !important;
            }

            /* Ensure every textual element in the receipt prints bold */
            .receipt-container, .receipt-container * {
                font-weight: bold !important;
                color: #000 !important;
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
                padding: 3px 0 !important;
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
                margin-top: 6px !important;
                padding-top: 6px !important;
                border-top: 2px solid #000;
            }
            
            .total-row {
                display: flex;
                justify-content: space-between;
                margin-bottom: 3px;
                font-size: 11px !important;
            }
            
            .total-row.final {
                font-size: 13px !important;
                margin-top: 4px !important;
                padding-top: 4px !important;
                border-top: 1px solid #000;
            }
            
            .thank-you {
                text-align: center;
                margin-top: 6px !important;
                font-size: 10px !important;
            }
            
            /* Hide visual separator when printing to save space */
            .separator { display: none !important; }
        }
        
        /* Screen styles */
        @media screen {
            body {
                background-color: #f8f9fa;
                padding: 20px;
                font-family: 'Courier New', monospace;
            }
            
            .receipt-container {
                max-width: 480px;
                margin: 0 auto;
                background: white;
                padding: 20px;
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
                display: block;
                width: 100%;
                height: auto;
            }

            @media print {
                .barcode-section {
                    background: white !important;
                    border: none !important;
                    margin: 6px 0 !important;
                    padding: 0 !important;
                }
            }
        }
    </style>
</head>
<body>
    <div class="receipt-container">
        <!-- Print Controls (only visible on screen) -->
        <div class="print-controls no-print">
            <button class="btn btn-primary me-2" onclick="printAndClose()">
                <i class="bi bi-printer"></i> Print Receipt
            </button>
            <button class="btn btn-secondary" onclick="goBackToPOS()">
                <i class="bi bi-x-circle"></i> Close
            </button>
        </div>
        <!-- Manual print fallback shown when auto-print fails or is blocked -->
        <div id="printFallback" class="no-print" style="text-align:center; margin:10px 0; display:none;">
            <div style="margin-bottom:8px; font-weight:bold;">Auto-print was blocked or failed. Please print manually.</div>
            <button id="manualPrintBtn" class="btn btn-lg btn-primary" onclick="manualPrint()"><i class="bi bi-printer"></i> Click to Print</button>
            <button class="btn btn-link" onclick="goBackToPOS()">Return to POS</button>
        </div>

        <!-- Receipt Header -->
        <div class="receipt-header">
            <div class="company-name"><?php 
                // Follow memory specification: source company info from settings
                echo htmlspecialchars(($receiptData['company_name'] ?? '') ?: ($settings['company_name'] ?? 'POS System')); 
            ?></div>
            <div class="company-address"><?php 
                echo nl2br(htmlspecialchars(($receiptData['company_address'] ?? '') ?: ($settings['company_address'] ?? ''))); 
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
                <span><?php 
                    // Use receipt_id if provided, otherwise generate sequential receipt ID
                    if (isset($receiptData['receipt_id']) && $receiptData['receipt_id'] !== 'N/A') {
                        echo htmlspecialchars($receiptData['receipt_id']);
                    } elseif (isset($receiptData['sale_id']) && is_numeric($receiptData['sale_id'])) {
                        // Generate sequential receipt ID based on sale_id
                        echo htmlspecialchars(generateSequentialReceiptId($conn));
                    } else {
                        // Fallback to generating a new sequential receipt ID
                        echo htmlspecialchars(generateSequentialReceiptId($conn));
                    }
                ?></span>
            </div>
            <div class="info-row">
                <span><strong>Date:</strong></span>
                <span><?php echo htmlspecialchars($receiptData['date'] ?? date('Y-m-d')); ?></span>
            </div>
            <div class="info-row">
                <span><strong>Time:</strong></span>
                <span><?php echo htmlspecialchars($receiptData['time'] ?? date('H:i:s')); ?></span>
            </div>
            <?php if (isset($receiptData['is_split_payment']) && $receiptData['is_split_payment']): ?>
            <div class="info-row">
                <span><strong>Payment:</strong></span>
                <span>Split Payment</span>
            </div>
            <?php else: ?>
            <div class="info-row">
                <span><strong>Payment:</strong></span>
                <span><?php echo htmlspecialchars($receiptData['method'] ?? $receiptData['payment_method'] ?? 'Cash'); ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($receiptData['customer_name']) && $receiptData['customer_name'] !== 'Walk-in Customer' && !empty($receiptData['loyalty'])): ?>
            <div class="info-row">
                <span><strong>Customer:</strong></span>
                <span><?php 
                    // Security: Show only first name for privacy protection
                    // Only show customer name when they have loyalty activity (redeeming or earning points)
                    $fullName = $receiptData['customer_name'];
                    $nameParts = explode(' ', trim($fullName));
                    $firstName = $nameParts[0] ?? $fullName;
                    echo htmlspecialchars($firstName);
                ?></span>
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
                                        // Extract numeric value from total_price string
                                        $totalPriceStr = $item['total_price'];
                                        if (is_numeric($totalPriceStr)) {
                                            $total_price = $totalPriceStr;
                                        } else {
                                            // Remove currency symbols and extract number
                                            $total_price = preg_replace('/[^\d.-]/', '', $totalPriceStr);
                                            $total_price = is_numeric($total_price) ? $total_price : 0;
                                        }
                                        echo $currency_symbol . ' ' . number_format($total_price, 2);
                                    } elseif (isset($item['price'])) {
                                        // Extract numeric value from price string (e.g., "KES100.00" -> 100.00)
                                        $priceStr = $item['price'];
                                        if (is_numeric($priceStr)) {
                                            $price = $priceStr;
                                        } else {
                                            // Remove currency symbols and extract number
                                            $price = preg_replace('/[^\d.-]/', '', $priceStr);
                                            $price = is_numeric($price) ? $price : 0;
                                        }
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
                        // Extract numeric value from subtotal string
                        $subtotalStr = $receiptData['subtotal'];
                        if (is_numeric($subtotalStr)) {
                            $subtotal = $subtotalStr;
                        } else {
                            $subtotal = preg_replace('/[^\d.-]/', '', $subtotalStr);
                            $subtotal = is_numeric($subtotal) ? $subtotal : 0;
                        }
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
                        // Extract numeric value from tax string
                        $taxStr = $receiptData['tax'];
                        if (is_numeric($taxStr)) {
                            $tax = $taxStr;
                        } else {
                            $tax = preg_replace('/[^\d.-]/', '', $taxStr);
                            $tax = is_numeric($tax) ? $tax : 0;
                        }
                        echo $currency_symbol . ' ' . number_format($tax, 2);
                    } else {
                        echo $currency_symbol . ' 0.00';
                    }
                ?></span>
            </div>
            <div class="total-row final">
                <span>TOTAL:</span>
                <span><?php 
                    if (isset($receiptData['total'])) {
                        // Extract numeric value from total string
                        $totalStr = $receiptData['total'];
                        if (is_numeric($totalStr)) {
                            $total = $totalStr;
                        } else {
                            $total = preg_replace('/[^\d.-]/', '', $totalStr);
                            $total = is_numeric($total) ? $total : 0;
                        }
                        echo $currency_symbol . ' ' . number_format($total, 2);
                    } elseif (isset($receiptData['final_amount'])) {
                        // Extract numeric value from final_amount string
                        $finalAmountStr = $receiptData['final_amount'];
                        if (is_numeric($finalAmountStr)) {
                            $final_amount = $finalAmountStr;
                        } else {
                            $final_amount = preg_replace('/[^\d.-]/', '', $finalAmountStr);
                            $final_amount = is_numeric($final_amount) ? $final_amount : 0;
                        }
                        echo $currency_symbol . ' ' . number_format($final_amount, 2);
                    } elseif (isset($receiptData['amount'])) {
                        // Extract numeric value from amount string
                        $amountStr = $receiptData['amount'];
                        if (is_numeric($amountStr)) {
                            $amount = $amountStr;
                        } else {
                            $amount = preg_replace('/[^\d.-]/', '', $amountStr);
                            $amount = is_numeric($amount) ? $amount : 0;
                        }
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
                    // Extract numeric value from cash_received string
                    $cashReceivedStr = $receiptData['cash_received'];
                    if (is_numeric($cashReceivedStr)) {
                        $cash_received = $cashReceivedStr;
                    } else {
                        $cash_received = preg_replace('/[^\d.-]/', '', $cashReceivedStr);
                        $cash_received = is_numeric($cash_received) ? $cash_received : 0;
                    }
                    echo $currency_symbol . ' ' . number_format($cash_received, 2); 
                ?></span>
            </div>
            <div class="total-row">
                <span>Change Due:</span>
                <span><?php 
                    // Extract numeric value from change_due string
                    $changeDueStr = $receiptData['change_due'];
                    if (is_numeric($changeDueStr)) {
                        $change_due = $changeDueStr;
                    } else {
                        $change_due = preg_replace('/[^\d.-]/', '', $changeDueStr);
                        $change_due = is_numeric($change_due) ? $change_due : 0;
                    }
                    echo $currency_symbol . ' ' . number_format($change_due, 2); 
                ?></span>
            </div>
            <?php endif; ?>
        </div>

        <!-- Split Payment Details -->
        <?php if (isset($receiptData['is_split_payment']) && $receiptData['is_split_payment'] && !empty($receiptData['payment_methods'])): ?>
        <div class="split-payment-section" style="margin: 15px 0; padding: 10px; background-color: #f8f9fa; border-radius: 5px;">
            <h6 style="margin-bottom: 10px; font-weight: bold; color: #495057;">Payment Methods Used:</h6>
            <?php foreach ($receiptData['payment_methods'] as $payment): ?>
            <div class="payment-method-row" style="display: flex; justify-content: space-between; margin-bottom: 5px; font-size: 12px;">
                <span>
                    <?php
                    $methodName = ucfirst(str_replace('_', ' ', $payment['method']));
                    echo htmlspecialchars($methodName);

                    // Show points used for loyalty payments
                    if ($payment['method'] === 'loyalty_points' && !empty($payment['points_used'])) {
                        echo ' (' . $payment['points_used'] . ' points)';
                    }
                    ?>
                </span>
                <span><?php echo $settings['currency_symbol'] ?? 'KES'; ?><?php echo number_format($payment['amount'], 2); ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Loyalty Points Information -->
        <?php if (!empty($receiptData['loyalty'])): ?>
        <div class="loyalty-section" style="margin: 15px 0; padding: 10px; background-color: #fff3cd; border-radius: 5px;">
            <h6 style="margin-bottom: 10px; font-weight: bold; color: #856404;">Loyalty Points:</h6>
            <?php if ($receiptData['loyalty']['points_used'] > 0): ?>
            <div style="display: flex; justify-content: space-between; margin-bottom: 5px; font-size: 12px;">
                <span>Points Redeemed:</span>
                <span><?php echo number_format($receiptData['loyalty']['points_used']); ?> points</span>
            </div>
            <?php endif; ?>
            <?php if ($receiptData['loyalty']['points_earned'] > 0): ?>
            <div style="display: flex; justify-content: space-between; margin-bottom: 5px; font-size: 12px;">
                <span>Points Earned:</span>
                <span><?php echo number_format($receiptData['loyalty']['points_earned']); ?> points</span>
            </div>
            <?php endif; ?>
            <div style="display: flex; justify-content: space-between; font-size: 12px; font-weight: bold;">
                <span>Current Balance:</span>
                <span><?php echo number_format($receiptData['loyalty']['customer_balance']); ?> points</span>
            </div>
        </div>
        <?php endif; ?>

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
        <button onclick="startNewSale()" class="btn btn-success" style="margin: 0 10px;">
            <i class="bi bi-plus-circle"></i> New Sale
        </button>
    </div>

    <script>
        // Generate barcode for transaction ID with stronger rendering for thermal printers
        document.addEventListener('DOMContentLoaded', function() {
            const transactionId = '<?php echo htmlspecialchars($receiptData['transaction_id'] ?? $receiptData['sale_id'] ?? 'N/A'); ?>';

            // Use a larger height and slightly thicker lines for thermal readability
            JsBarcode("#receiptBarcode", transactionId, {
                format: "CODE128",
                width: 2,
                height: 48,
                displayValue: false,
                background: "transparent",
                lineColor: "#000000",
                margin: 0
            });

            // Helper: try to resize the print window to fit receipt content when opened by script.
            function fitWindowToContent() {
                try {
                    const container = document.querySelector('.receipt-container');
                    if (!container) return;
                    // compute required pixel width/height
                    const contentWidth = Math.min(Math.max(container.offsetWidth + 40, 280), 800);
                    const contentHeight = container.scrollHeight + 80;

                    // Only attempt resize on windows opened by script (browsers often allow this)
                    if (window.name === 'pos_print_window' || (window.opener && !window.opener.closed)) {
                        try {
                            window.resizeTo(contentWidth, contentHeight);
                        } catch (e) {
                            // ignore if not allowed
                        }
                    }
                } catch (e) {
                    // ignore
                }
            }

            // Attempt to fit window after a short delay so styles and barcode render first
            setTimeout(fitWindowToContent, 200);
        });

        // Auto-print on page load (controlled by URL parameters auto_print and auto_close)
        const urlParams = new URLSearchParams(window.location.search);
        const autoPrint = urlParams.get('auto_print') === 'true';
        const autoClose = urlParams.get('auto_close') === 'true';

        // If auto-print requested, hide navigation buttons to avoid UI flicker on small printers
        if (autoPrint) {
            const nav = document.getElementById('navButtons');
            if (nav) nav.style.display = 'none';
        }

        // Resilient auto-print flow: attempt immediate print, show manual fallback if blocked,
        // and always return to POS (or close) after printing when requested.
        function triggerAutoPrintWithFallback() {
            let attempted = false;

            function showFallback() {
                const fallback = document.getElementById('printFallback');
                if (fallback) fallback.style.display = 'block';
                const nav = document.getElementById('navButtons');
                if (nav) nav.style.display = 'block';
            }

            try {
                window.print();
                attempted = true;
            } catch (e) {
                console.warn('Auto print call failed:', e);
                attempted = false;
            }

            // If afterprint doesn't fire within a reasonable time, assume blocked and show fallback
            const noPrintTimer = setTimeout(function() {
                // Show manual print fallback
                showFallback();
                // Always redirect to sales page after timeout
                setTimeout(function() {
                    try { window.location.href = 'sale.php'; } catch (e) {}
                }, 3000); // 3 second delay to allow user to print manually
            }, 2500);

            // afterprint handler: notify opener and close or reveal nav
            window.addEventListener('afterprint', function afterPrintHandler() {
                clearTimeout(noPrintTimer);
                try {
                    if (window.opener && !window.opener.closed) {
                        try {
                            if (typeof window.opener.postMessage === 'function') {
                                window.opener.postMessage({ type: 'receipt_printed' }, '*');
                            }
                        } catch (e) {}
                    }
                } catch (e) {}

                // Always redirect to sales page after printing
                setTimeout(function() {
                    try { window.location.href = 'sale.php'; } catch (e) {}
                }, 1000); // 1 second delay to allow printing to complete
                window.removeEventListener('afterprint', afterPrintHandler);
            });
        }

        if (autoPrint) {
            window.addEventListener('load', function() {
                // small delay to allow barcode/fonts to render
                setTimeout(triggerAutoPrintWithFallback, 450);
            });
        }

        // Show navigation buttons after page loads (unless autoPrint is running)
        window.addEventListener('load', function() {
            // Show navigation buttons after a delay (to allow for auto-print)
            setTimeout(function() {
                const nav = document.getElementById('navButtons');
                if (!nav) return;
                // Only show nav if autoPrint is not active; if autoPrint is active, nav will be revealed by fallback
                if (!autoPrint) {
                    nav.style.display = 'block';
                }
            }, 3000); // 3 seconds delay
        });

        // Manual print action triggered from fallback UI
        function manualPrint() {
            try {
                // show nav while printing so user has controls
                const nav = document.getElementById('navButtons');
                if (nav) nav.style.display = 'block';
                window.print();
                // Close and redirect to sales page after printing
                setTimeout(function() {
                    window.location.href = 'sale.php';
                }, 2000); // 2 second delay to allow printing to complete
            } catch (e) {
                console.warn('Manual print failed:', e);
                // Redirect back to POS so user is not stuck
                try { window.location.href = 'sale.php'; } catch (ee) {}
            }
        }

        // Navigation functions
        function goBackToPOS() {
            window.location.href = 'sale.php';
        }

        function printAndClose() {
            window.print();
            // Close and redirect to sales page after printing
            setTimeout(function() {
                window.location.href = 'sale.php';
            }, 2000); // 2 second delay to allow printing to complete
        }

        function startNewSale() {
            // Clear any existing cart/session data and go to POS
            window.location.href = 'sale.php?new_sale=true';
        }
    </script>
</body>
</html>
