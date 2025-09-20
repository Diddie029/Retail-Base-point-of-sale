<?php
session_start();
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';

// Import DomPDF classes
require_once __DIR__ . '/../vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Get user information
$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$role_name = $_SESSION['role_name'] ?? 'User';
$role_id = $_SESSION['role_id'] ?? 0;

// Get user permissions
$permissions = [];
if ($role_id) {
    $stmt = $conn->prepare("
        SELECT p.name
        FROM permissions p
        JOIN role_permissions rp ON p.id = rp.permission_id
        WHERE rp.role_id = :role_id
    ");
    $stmt->bindParam(':role_id', $role_id);
    $stmt->execute();
    $permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Check if user has permission to manage inventory
if (!hasPermission('manage_inventory', $permissions)) {
    header("Location: ../dashboard/dashboard.php?error=permission_denied");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Function to generate invoice HTML content for received orders
function generateOrderInvoiceHTML($order, $settings, $username) {
    $is_received = ($order['status'] === 'received');
    $document_type = $is_received ? 'Invoice' : 'Purchase Order';
    $document_number = $is_received ? ($order['invoice_number'] ?? $order['order_number']) : $order['order_number'];

    $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . $document_type . ' ' . htmlspecialchars($document_number) . ' - Print Ready</title>
    <style>
        @media print {
            body { margin: 0; padding: 15pt; }
            .no-print { display: none !important; }
            .page-break { page-break-before: always; }
        }

        @media screen {
            body {
                margin: 20px;
                padding: 20px;
                background: #f5f5f5;
                font-family: Arial, sans-serif;
            }
        }

        body {
            font-family: \'DejaVu Sans\', \'Arial\', sans-serif;
            font-size: 12pt;
            line-height: 1.4;
            color: #000;
            margin: 0;
            padding: 20pt 80pt 20pt 60pt;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 30pt;
            border-bottom: 2px solid #dee2e6;
            padding-bottom: 20pt;
        }

        .company-info {
            flex: 1;
            width: 50%;
            padding-right: 20pt;
        }

        .supplier-info {
            flex: 1;
            width: 50%;
            padding-left: 20pt;
            text-align: right;
        }

        .supplier-info h3 {
            margin: 0 0 15pt 0;
            font-size: 14pt;
            font-weight: 600;
            color: #000;
            text-align: right;
        }

        .supplier-details-box {
            background: #f8f9fa;
            padding: 15pt;
            border-radius: 8pt;
            border-left: 4px solid #007bff;
            text-align: left;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .supplier-name {
            margin: 0 0 8pt 0;
            font-size: 14pt;
            font-weight: 600;
            color: #000;
        }

        .supplier-detail {
            margin: 0 0 5pt 0;
            font-size: 10pt;
        }

        .document-title {
            text-align: center;
            margin: 20pt 0;
            font-size: 18pt;
            font-weight: 600;
        }

        .document-details {
            display: flex;
            justify-content: space-between;
            margin: 20pt 0;
            padding: 15pt;
            background: #f8f9fa;
            border-radius: 5pt;
        }

        .order-items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20pt 0;
        }

        .order-items-table th,
        .order-items-table td {
            border: 1px solid #dee2e6;
            padding: 8pt;
            text-align: left;
            font-size: 10pt;
        }

        .order-items-table th {
            background: #f8f9fa;
            font-weight: 600;
        }

        .order-items-table .text-center { text-align: center; }
        .order-items-table .text-end { text-align: right; }

        .totals-section {
            display: flex;
            justify-content: flex-end;
            margin: 20pt 0;
        }

        .totals-table {
            width: 300pt;
            border-collapse: collapse;
        }

        .totals-table td {
            padding: 8pt;
            border: 1px solid #dee2e6;
        }

        .totals-table .text-end { text-align: right; }
        .totals-table .fw-bold { font-weight: 600; }

        .notes-section {
            margin: 20pt 0;
            padding: 15pt;
            background: #f8f9fa;
            border-radius: 5pt;
        }

        .footer-info {
            margin-top: 30pt;
            padding-top: 20pt;
            border-top: 1px solid #dee2e6;
            text-align: center;
            font-size: 9pt;
            color: #000;
        }

        .status-badge {
            display: inline-block;
            padding: 5pt 10pt;
            background: #d1edff;
            color: #000;
            border: 1px solid #0c5460;
            border-radius: 3pt;
            font-size: 10pt;
            font-weight: 600;
        }
    </style>
</head>
<body>

    <!-- Invoice Header - Full Width -->
    <div style="text-align: center; margin-bottom: 20pt; padding: 15pt 10pt;">
        <h1 style="margin: 0 0 8pt 0; font-size: 20pt; font-weight: 700; color: #000; text-transform: uppercase;">
            ' . $document_type . ' #' . htmlspecialchars($document_number) . '
        </h1>';
        
    if ($is_received) {
        $html .= '<div style="display: inline-block; padding: 4pt 12pt; font-size: 9pt; font-weight: 600; text-transform: uppercase; margin-top: 8pt; color: #000;">Received</div>';
    }

    $html .= '</div>

    <!-- Divider -->
    <div style="border-top: 1px solid #dee2e6; margin: 15pt 0;"></div>

    <!-- Main Content Layout -->
    <div style="display: flex; gap: 15pt; margin-bottom: 20pt;">
        <!-- Left Column: Company Details -->
        <div style="flex: 0 0 30%;">
            <div style="padding: 12pt;">
                <h3 style="margin: 0 0 8pt 0; font-size: 12pt; font-weight: 600; color: #000;">From / Company Details</h3>
                <h4 style="margin: 0 0 8pt 0; font-size: 16pt; font-weight: 600; color: #000;">' . htmlspecialchars($settings['company_name'] ?? 'Company Name') . '</h4>';

    if ($settings['company_address']) {
        $html .= '<p style="margin: 0 0 5pt 0; font-size: 10pt;"><strong>📍 Address:</strong> ' . htmlspecialchars($settings['company_address']) . '</p>';
    }
    if ($settings['company_phone']) {
        $html .= '<p style="margin: 0 0 5pt 0; font-size: 10pt;"><strong>📞 Phone:</strong> ' . htmlspecialchars($settings['company_phone']) . '</p>';
    }
    if ($settings['company_email']) {
        $html .= '<p style="margin: 0 0 5pt 0; font-size: 10pt;"><strong>📧 Email:</strong> ' . htmlspecialchars($settings['company_email']) . '</p>';
    }

    $html .= '</div>
        </div>

        <!-- Center Column: Invoice Information -->
        <div style="flex: 0 0 35%;">
            <div style="padding: 12pt;">
                <h3 style="margin: 0 0 8pt 0; font-size: 12pt; font-weight: 600; color: #000;">Invoice Information</h3>
                <p style="margin: 0 0 5pt 0; font-size: 10pt;"><strong>📅 ' . ($is_received ? 'Invoice' : 'Order') . ' Date:</strong> ' . date('M j, Y', strtotime($order['order_date'])) . '</p>
                <p style="margin: 0 0 5pt 0; font-size: 10pt;"><strong>🕒 Created:</strong> ' . date('M j, Y g:i A', strtotime($order['created_at'])) . '</p>
                <p style="margin: 0 0 5pt 0; font-size: 10pt;"><strong>👤 Created By:</strong> ' . htmlspecialchars($order['created_by_name'] ?? 'System') . '</p>';

    if ($is_received && $order['received_date']) {
        $html .= '<p style="margin: 0 0 5pt 0; font-size: 10pt;"><strong>✅ Received Date:</strong> ' . date('M j, Y', strtotime($order['received_date'])) . '</p>';
    }

    if ($order['supplier_invoice_number']) {
        $html .= '<p style="margin: 0 0 5pt 0; font-size: 10pt;"><strong>📄 Supplier Invoice:</strong> ' . htmlspecialchars($order['supplier_invoice_number']) . '</p>';
    }

    $html .= '<p style="margin: 0 0 5pt 0; font-size: 10pt;"><strong>🔖 Order Reference:</strong> ' . htmlspecialchars($order['order_number']) . '</p>';

    $html .= '</div>
        </div>

        <!-- Right Column: Supplier Details -->
        <div style="flex: 0 0 35%;">
            <div style="padding: 12pt;">
                <h3 style="margin: 0 0 8pt 0; font-size: 12pt; font-weight: 600; color: #000;">Bill To / Supplier Details</h3>
                <h4 style="margin: 0 0 8pt 0; font-size: 16pt; font-weight: 600; color: #000;">' . htmlspecialchars($order['supplier_name'] ?? 'Supplier Name') . '</h4>';

    if (!empty($order['phone'])) {
        $html .= '<p style="margin: 0 0 5pt 0; font-size: 10pt;"><strong>📞 Phone:</strong> ' . htmlspecialchars($order['phone']) . '</p>';
    }
    if (!empty($order['email'])) {
        $html .= '<p style="margin: 0 0 5pt 0; font-size: 10pt;"><strong>📧 Email:</strong> ' . htmlspecialchars($order['email']) . '</p>';
    }
    if (!empty($order['address'])) {
        $html .= '<p style="margin: 0 0 5pt 0; font-size: 10pt;"><strong>📍 Address:</strong> ' . htmlspecialchars($order['address']) . '</p>';
    }
    if (!empty($order['supplier_invoice_number'])) {
        $html .= '<p style="margin: 0 0 5pt 0; font-size: 10pt;"><strong>📄 Supplier Invoice:</strong> ' . htmlspecialchars($order['supplier_invoice_number']) . '</p>';
    }

    $html .= '</div>
        </div>
    </div>

    <!-- Divider -->
    <div style="border-top: 1px solid #dee2e6; margin: 15pt 0;"></div>

    <!-- Order Items Table -->
    <table class="order-items-table">
        <thead>
            <tr>
                <th>#</th>
                <th>Product</th>
                <th>SKU</th>
                <th class="text-center">Ordered</th>
                <th class="text-center">Received</th>
                <th class="text-end">Unit Price</th>
                <th class="text-end">Total</th>
            </tr>
        </thead>
        <tbody>';

    $counter = 1;
    $total_ordered = 0;
    $total_received = 0;
    $total_amount = 0;

    foreach ($order['items'] as $item) {
        $ordered_qty = $item['quantity'];
        $received_qty = $item['received_quantity'];
        $unit_price = $item['cost_price'];
        $line_total = $received_qty * $unit_price;

        $total_ordered += $ordered_qty;
        $total_received += $received_qty;
        $total_amount += $line_total;

        $html .= '<tr>
            <td class="text-center">' . $counter . '</td>
            <td>
                <strong>' . htmlspecialchars($item['product_name']) . '</strong><br>';

        if ($item['category_name']) {
            $html .= '<small>' . htmlspecialchars($item['category_name']) . '</small><br>';
        }


        $html .= '</td>
            <td>' . htmlspecialchars($item['sku'] ?? 'N/A') . '</td>
            <td class="text-center">' . $ordered_qty . '</td>
            <td class="text-center">' . $received_qty . '</td>
            <td class="text-end">' . formatCurrency($unit_price, $settings['currency_symbol'] ?? 'KES') . '</td>
            <td class="text-end">' . formatCurrency($line_total, $settings['currency_symbol'] ?? 'KES') . '</td>
        </tr>';

        $counter++;
    }

    $html .= '</tbody></table>

    <!-- Totals Section -->
    <div class="totals-section">
        <table class="totals-table">
            <tr>
                <td><strong>Items Ordered:</strong></td>
                <td class="text-end">' . $total_ordered . '</td>
            </tr>
            <tr>
                <td><strong>Items Received:</strong></td>
                <td class="text-end">' . $total_received . '</td>
            </tr>
            <tr>
                <td><strong>Total Amount:</strong></td>
                <td class="text-end fw-bold">' . formatCurrency($total_amount, $settings['currency_symbol'] ?? 'KES') . '</td>
            </tr>
        </table>
    </div>';

    // Notes section
    if ($order['notes'] || $order['invoice_notes']) {
        $html .= '<div class="notes-section">
            <h4>Notes</h4>';

        if ($order['notes']) {
            $html .= '<p><strong>Order Notes:</strong> ' . nl2br(htmlspecialchars($order['notes'])) . '</p>';
        }

        if ($order['invoice_notes']) {
            $html .= '<p><strong>Invoice Notes:</strong> ' . nl2br(htmlspecialchars($order['invoice_notes'])) . '</p>';
        }

        $html .= '</div>';
    }

    // Footer
    $html .= '<div class="footer-info">
        <p>This is a computer-generated document. No signature required.</p>
        <p>Generated on ' . date('M j, Y \a\t g:i A') . ' by ' . htmlspecialchars($username) . '</p>
    </div>

    <script>
        // Auto-focus on print button for better UX
        window.onload = function() {
            // Hide print controls when printing
            window.addEventListener(\'beforeprint\', function() {
                document.querySelector(\'.print-controls\').style.display = \'none\';
            });

            // Show print controls after printing
            window.addEventListener(\'afterprint\', function() {
                document.querySelector(\'.print-controls\').style.display = \'block\';
            });
        };
    </script>
</body>
</html>';

    return $html;
}

// Handle invoice generation requests
$order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : null;
$download = isset($_GET['download']) ? $_GET['download'] : '0';
$print_mode = isset($_GET['print']) ? $_GET['print'] : '0';

if (!$order_id) {
    die('Order ID is required');
}

// Get order data
$stmt = $conn->prepare("
    SELECT io.*,
           s.name as supplier_name, s.contact_person, s.phone, s.email, s.address,
           u.username as created_by_name
    FROM inventory_orders io
    LEFT JOIN suppliers s ON io.supplier_id = s.id
    LEFT JOIN users u ON io.user_id = u.id
    WHERE io.id = :order_id AND io.status = 'received'
");
$stmt->bindParam(':order_id', $order_id);
$stmt->execute();
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    die('Order not found or not yet received');
}

// Get order items
$stmt = $conn->prepare("
    SELECT ioi.*,
           p.name as product_name, p.sku, p.description,
           c.name as category_name, b.name as brand_name
    FROM inventory_order_items ioi
    LEFT JOIN products p ON ioi.product_id = p.id
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN brands b ON p.brand_id = b.id
    WHERE ioi.order_id = :order_id
    ORDER BY ioi.id ASC
");
$stmt->bindParam(':order_id', $order_id);
$stmt->execute();
$order['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Set headers and output
if ($download === '1') {
    // PDF download headers
    $filename = 'Invoice_' . ($order['invoice_number'] ?? $order['order_number']) . '_' . date('Y-m-d_H-i-s') . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    // Generate PDF using DomPDF
    $options = new Options();
    $options->set('defaultFont', 'Arial');
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);

    $dompdf = new Dompdf($options);
    $html = generateOrderInvoiceHTML($order, $settings, $username);

    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $dompdf->stream($filename, array('Attachment' => true));

} elseif ($print_mode === '1') {
    // Print mode
    $filename = 'Invoice_' . ($order['invoice_number'] ?? $order['order_number']) . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');

    // Generate PDF for print
    $options = new Options();
    $options->set('defaultFont', 'Arial');
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);

    $dompdf = new Dompdf($options);
    $html = generateOrderInvoiceHTML($order, $settings, $username);

    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $dompdf->stream($filename, array('Attachment' => false));

} else {
    // HTML preview
    $filename = 'Invoice_' . ($order['invoice_number'] ?? $order['order_number']) . '.html';
    header('Content-Type: text/html; charset=UTF-8');
    header('Content-Disposition: inline; filename="' . $filename . '"');

    // Generate HTML preview
    echo generateOrderInvoiceHTML($order, $settings, $username);
}

exit;
?>
