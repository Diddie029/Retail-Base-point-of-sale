<?php
// Check if session is already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// Get receipt data from request
$input = file_get_contents('php://input');
$receiptData = json_decode($input, true);

if (!$receiptData) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid receipt data']);
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Generate PDF receipt
try {
    // Create HTML content for PDF
    $html = generateReceiptHTML($receiptData, $settings);
    
    // Generate PDF using a simple HTML to PDF approach
    // For production, consider using libraries like TCPDF, mPDF, or wkhtmltopdf
    $pdfContent = generateSimplePDF($html);
    
    // Set headers for PDF download
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="receipt_' . $receiptData['transaction_id'] . '.pdf"');
    header('Content-Length: ' . strlen($pdfContent));
    
    echo $pdfContent;
    
} catch (Exception $e) {
    error_log("PDF generation error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'PDF generation failed']);
}

/**
 * Generate HTML content for receipt
 */
function generateReceiptHTML($data, $settings) {
    $companyName = $data['company_name'] ?: $settings['company_name'] ?? 'POS System';
    $companyAddress = $data['company_address'] ?: $settings['company_address'] ?? '';
    $currency = $settings['currency_symbol'] ?? 'KES';
    
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Receipt - ' . htmlspecialchars($data['transaction_id']) . '</title>
        <style>
            body {
                font-family: "Courier New", monospace;
                font-size: 12px;
                line-height: 1.4;
                margin: 0;
                padding: 20px;
                color: #000;
                background: white;
            }
            .receipt-container {
                max-width: 400px;
                margin: 0 auto;
                background: white;
            }
            .receipt-header {
                text-align: center;
                margin-bottom: 20px;
                border-bottom: 2px solid #000;
                padding-bottom: 15px;
            }
            .company-name {
                font-size: 18px;
                font-weight: bold;
                margin-bottom: 8px;
            }
            .company-address {
                font-size: 11px;
                line-height: 1.3;
                color: #666;
            }
            .transaction-info {
                margin-bottom: 20px;
                font-size: 11px;
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
                padding: 6px 0;
                border-bottom: 1px solid #eee;
                font-size: 11px;
            }
            .items-table th {
                font-weight: bold;
                text-align: left;
                border-bottom: 2px solid #000;
            }
            .items-table .text-right {
                text-align: right;
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
                font-size: 11px;
            }
            .total-row.final {
                font-weight: bold;
                font-size: 13px;
                margin-top: 8px;
                padding-top: 8px;
                border-top: 1px solid #000;
            }
            .thank-you {
                text-align: center;
                margin-top: 25px;
                font-size: 10px;
                color: #666;
                border-top: 1px dashed #ccc;
                padding-top: 15px;
            }
            .separator {
                text-align: center;
                margin: 20px 0;
                font-size: 10px;
                color: #999;
            }
        </style>
    </head>
    <body>
        <div class="receipt-container">
            <!-- Receipt Header -->
            <div class="receipt-header">
                <div class="company-name">' . htmlspecialchars($companyName) . '</div>
                <div class="company-address">' . nl2br(htmlspecialchars($companyAddress)) . '</div>
            </div>

            <!-- Transaction Information -->
            <div class="transaction-info">
                <div class="info-row">
                    <span><strong>Transaction ID:</strong></span>
                    <span>' . htmlspecialchars($data['transaction_id']) . '</span>
                </div>
                <div class="info-row">
                    <span><strong>Date:</strong></span>
                    <span>' . htmlspecialchars($data['date']) . '</span>
                </div>
                <div class="info-row">
                    <span><strong>Time:</strong></span>
                    <span>' . htmlspecialchars($data['time']) . '</span>
                </div>
                <div class="info-row">
                    <span><strong>Payment:</strong></span>
                    <span>' . htmlspecialchars($data['payment_method']) . '</span>
                </div>
            </div>

            <!-- Items Section -->
            <div class="items-section">
                <table class="items-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Qty</th>
                            <th class="text-right">Price</th>
                        </tr>
                    </thead>
                    <tbody>';

    // Add items
    if (!empty($data['items'])) {
        foreach ($data['items'] as $item) {
            $qtyParts = explode(' × ', $item['qty']);
            $quantity = $qtyParts[0] ?? '1';
            
            $html .= '
                        <tr>
                            <td>
                                <div>' . htmlspecialchars($item['name']) . '</div>
                                <small>' . htmlspecialchars($item['qty']) . '</small>
                            </td>
                            <td>' . htmlspecialchars($quantity) . '</td>
                            <td class="text-right">' . htmlspecialchars($item['price']) . '</td>
                        </tr>';
        }
    }

    $html .= '
                    </tbody>
                </table>
            </div>

            <!-- Totals Section -->
            <div class="totals-section">
                <div class="total-row">
                    <span>Subtotal:</span>
                    <span>' . htmlspecialchars($data['subtotal']) . '</span>
                </div>
                <div class="total-row">
                    <span>Tax:</span>
                    <span>' . htmlspecialchars($data['tax']) . '</span>
                </div>
                <div class="total-row final">
                    <span>TOTAL:</span>
                    <span>' . htmlspecialchars($data['total']) . '</span>
                </div>
            </div>

            <!-- Thank You Message -->
            <div class="thank-you">
                <p><strong>Thank you for your business!</strong><br>
                Please keep this receipt for your records</p>
            </div>

            <!-- Separator -->
            <div class="separator">
                - - - - - - - - - - - - - - - - - - - - - -
            </div>
        </div>
    </body>
    </html>';

    return $html;
}

/**
 * Generate simple PDF (basic implementation)
 * For production, use proper PDF libraries like TCPDF or mPDF
 */
function generateSimplePDF($html) {
    // This is a basic implementation
    // In production, you should use a proper PDF library
    
    // For now, we'll return the HTML as a simple text-based receipt
    // that can be printed or converted to PDF by the browser
    
    $pdfContent = "=== RECEIPT ===\n\n";
    
    // Extract text content from HTML for basic PDF-like output
    $text = strip_tags($html);
    $text = html_entity_decode($text);
    $text = preg_replace('/\s+/', ' ', $text);
    
    $pdfContent .= $text;
    
    return $pdfContent;
}

/**
 * Alternative: Use mPDF library (if available)
 */
function generateMPDFReceipt($html) {
    // Uncomment and install mPDF if you want to use it
    /*
    require_once 'vendor/autoload.php';
    
    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => [80, 200], // Receipt size
        'orientation' => 'P',
        'margin_left' => 5,
        'margin_right' => 5,
        'margin_top' => 5,
        'margin_bottom' => 5,
    ]);
    
    $mpdf->WriteHTML($html);
    return $mpdf->Output('', 'S'); // Return as string
    */
}
?>
