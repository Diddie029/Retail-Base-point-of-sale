<?php
/**
 * Bulk Invoice Print Script
 *
 * This script handles bulk printing of multiple invoices.
 * Uses DomPDF for server-side PDF generation.
 */

session_start();

// Include DomPDF autoloader
require_once __DIR__ . '/../vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Get order IDs from URL parameter
$order_ids_param = isset($_GET['ids']) ? trim($_GET['ids']) : '';
if (empty($order_ids_param)) {
    header("Location: view_orders.php?error=no_orders_selected");
    exit();
}

$order_ids = array_map('intval', explode(',', $order_ids_param));

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Function to get order data
function getOrderData($conn, $order_id) {
    try {
        // Get order details
        $stmt = $conn->prepare("
            SELECT io.*,
                   s.name as supplier_name, s.contact_person, s.phone, s.email, s.address,
                   u.username as created_by_name
            FROM inventory_orders io
            LEFT JOIN suppliers s ON io.supplier_id = s.id
            LEFT JOIN users u ON io.user_id = u.id
            WHERE io.id = :order_id OR io.order_number = :order_number
        ");
        $stmt->execute([
            ':order_id' => is_numeric($order_id) ? $order_id : 0,
            ':order_number' => $order_id
        ]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            return null;
        }

        // Get order items
        $stmt = $conn->prepare("
            SELECT ioi.*,
                   p.name as product_name, p.sku, p.description, p.image_url,
                   c.name as category_name, b.name as brand_name
            FROM inventory_order_items ioi
            LEFT JOIN products p ON ioi.product_id = p.id
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN brands b ON p.brand_id = b.id
            WHERE ioi.order_id = :order_id
            ORDER BY ioi.id ASC
        ");
        $stmt->bindParam(':order_id', $order['id']);
        $stmt->execute();
        $order['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $order;
    } catch (PDOException $e) {
        error_log("Error getting order data: " . $e->getMessage());
        return null;
    }
}

// Get all orders data
$orders = [];
$valid_orders = [];
foreach ($order_ids as $order_id) {
    $order = getOrderData($conn, $order_id);
    if ($order && $order['status'] === 'received') {
        $orders[] = $order;
        $valid_orders[] = $order_id;
    }
}

if (empty($orders)) {
    header("Location: view_orders.php?error=no_valid_orders");
    exit();
}

// Configure DomPDF
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isPhpEnabled', true);
$options->set('defaultFont', 'Arial');
$options->set('isRemoteEnabled', true);

$dompdf = new Dompdf($options);

// Generate HTML content for all invoices
$html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Bulk Invoices - ' . htmlspecialchars($settings['company_name'] ?? 'POS System') . '</title>
    <style>
        @page { margin: 15mm; }
        body { font-family: Arial, sans-serif; font-size: 12px; line-height: 1.4; }
        .invoice-container { margin-bottom: 30px; page-break-after: always; }
        .invoice-container:last-child { page-break-after: avoid; }
        .invoice-header { text-align: center; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 2px solid #333; }
        .invoice-header h2 { margin: 5px 0; color: #333; }
        .invoice-details { margin-bottom: 20px; }
        .invoice-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .invoice-table th, .invoice-table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        .invoice-table th { background-color: #f5f5f5; font-weight: bold; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .total-row { font-weight: bold; background-color: #f9f9f9; }
        .company-info { margin-bottom: 20px; }
        .supplier-info { margin-bottom: 20px; }
        .invoice-footer { margin-top: 20px; padding-top: 10px; border-top: 1px solid #ddd; font-size: 10px; }
    </style>
</head>
<body>';

// Generate content for each invoice
foreach ($orders as $order) {
    $html .= '
    <div class="invoice-container">
        <div class="invoice-header">
            <h2>' . htmlspecialchars($settings['company_name'] ?? 'POS System') . '</h2>
            <h3>Invoice</h3>
        </div>

        <div style="display: flex; justify-content: space-between; margin-bottom: 20px;">
            <div class="company-info" style="width: 50%;">
                <strong>From:</strong><br>
                ' . htmlspecialchars($settings['company_name'] ?? 'POS System') . '<br>
                ' . nl2br(htmlspecialchars($settings['company_address'] ?? '')) . '<br>
                Phone: ' . htmlspecialchars($settings['company_phone'] ?? '') . '<br>
                Email: ' . htmlspecialchars($settings['company_email'] ?? '') . '
            </div>

            <div class="supplier-info" style="width: 50%; text-align: right;">
                <strong>To:</strong><br>
                ' . htmlspecialchars($order['supplier_name'] ?? 'N/A') . '<br>
                ' . nl2br(htmlspecialchars($order['contact_person'] ?? '')) . '<br>
                Phone: ' . htmlspecialchars($order['phone'] ?? '') . '<br>
                Email: ' . htmlspecialchars($order['email'] ?? '') . '<br>
                ' . nl2br(htmlspecialchars($order['address'] ?? '')) . '
            </div>
        </div>

        <div class="invoice-details">
            <table style="width: 100%; border: none;">
                <tr>
                    <td><strong>Invoice #:</strong> ' . htmlspecialchars($order['invoice_number'] ?? 'N/A') . '</td>
                    <td><strong>Order #:</strong> ' . htmlspecialchars($order['order_number']) . '</td>
                </tr>
                <tr>
                    <td><strong>Date:</strong> ' . date('M d, Y', strtotime($order['received_date'] ?? $order['updated_at'])) . '</td>
                    <td><strong>Supplier Invoice:</strong> ' . htmlspecialchars($order['supplier_invoice_number'] ?? 'N/A') . '</td>
                </tr>
            </table>
        </div>

        <table class="invoice-table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th class="text-center">SKU</th>
                    <th class="text-center">Ordered</th>
                    <th class="text-center">Received</th>
                    <th class="text-right">Cost Price</th>
                    <th class="text-right">Line Total</th>
                </tr>
            </thead>
            <tbody>';

    $total_amount = 0;
    foreach ($order['items'] as $item) {
        $line_total = $item['received_quantity'] * $item['cost_price'];
        $total_amount += $line_total;

        $html .= '
                <tr>
                    <td>' . htmlspecialchars($item['product_name']) . '</td>
                    <td class="text-center">' . htmlspecialchars($item['sku'] ?? 'N/A') . '</td>
                    <td class="text-center">' . $item['quantity'] . '</td>
                    <td class="text-center">' . $item['received_quantity'] . '</td>
                    <td class="text-right">' . htmlspecialchars($settings['currency_symbol'] ?? 'KES') . ' ' . number_format($item['cost_price'], 2) . '</td>
                    <td class="text-right">' . htmlspecialchars($settings['currency_symbol'] ?? 'KES') . ' ' . number_format($line_total, 2) . '</td>
                </tr>';
    }

    $html .= '
                <tr class="total-row">
                    <td colspan="5" class="text-right"><strong>Total Amount:</strong></td>
                    <td class="text-right"><strong>' . htmlspecialchars($settings['currency_symbol'] ?? 'KES') . ' ' . number_format($total_amount, 2) . '</strong></td>
                </tr>
            </tbody>
        </table>

        <div class="invoice-footer">
            <p><strong>Notes:</strong> ' . nl2br(htmlspecialchars($order['invoice_notes'] ?? 'No notes')) . '</p>
            <p><em>Generated on: ' . date('M d, Y H:i:s') . '</em></p>
        </div>
    </div>';
}

$html .= '
</body>
</html>';

// Load HTML content
$dompdf->loadHtml($html);

// Set paper size and orientation
$dompdf->setPaper('A4', 'portrait');

// Render PDF
$dompdf->render();

// Generate filename
$filename = 'bulk_invoices_' . date('Y-m-d_H-i-s') . '.pdf';

// Output PDF
$dompdf->stream($filename, array('Attachment' => true));
exit();
?>
