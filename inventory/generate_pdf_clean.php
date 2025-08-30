<?php
/**
 * Order PDF Generation Script
 *
 * This script generates a PDF version of an order with customizable options.
 *
 * For production use, consider installing a proper PDF library:
 * - TCPDF: composer require tecnickcom/tcpdf
 * - DomPDF: composer require dompdf/dompdf
 * - FPDF: composer require setasign/fpdf
 *
 * Example with DomPDF:
 * require_once 'vendor/autoload.php';
 * use Dompdf\Dompdf;
 *
 * $dompdf = new Dompdf();
 * $dompdf->loadHtml($html);
 * $dompdf->setPaper('A4', 'portrait');
 * $dompdf->render();
 * $dompdf->stream($filename);
 */

session_start();

// Debug session configuration
error_log("Session save path: " . session_save_path());
error_log("Session cookie params: " . print_r(session_get_cookie_params(), true));
error_log("Session status: " . session_status());

// Ensure session is working properly
if (session_status() !== PHP_SESSION_ACTIVE) {
    error_log("Session not active, starting new session");
    session_start();
}
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Validate user exists in database
try {
    $stmt = $conn->prepare("SELECT id FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    if (!$stmt->fetch()) {
        // User doesn't exist, log them out
        session_destroy();
        header("Location: ../auth/login.php?error=user_not_found");
        exit();
    }
} catch (PDOException $e) {
    error_log("User validation error: " . $e->getMessage());
    header("Location: ../auth/login.php?error=db_error");
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

// Check if user has permission to view orders
if (!in_array('manage_products', $permissions) && !in_array('process_sales', $permissions)) {
    header("Location: inventory.php?error=permission_denied");
    exit();
}

// Get order ID from URL
$order_id = isset($_GET['id']) ? trim($_GET['id']) : null;
if (!$order_id) {
    header("Location: inventory.php?error=invalid_order");
    exit();
}

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

        // Calculate received totals
        $order['total_received_items'] = 0;
        $order['total_received_amount'] = 0;
        foreach ($order['items'] as $item) {
            $order['total_received_items'] += $item['received_quantity'];
            $order['total_received_amount'] += ($item['received_quantity'] * $item['cost_price']);
        }

        return $order;
    } catch (PDOException $e) {
        error_log("Error getting order data: " . $e->getMessage());
        return null;
    }
}

// Get order data
$order = getOrderData($conn, $order_id);
if (!$order) {
    header("Location: inventory.php?error=order_not_found");
    exit();
}

// Get customization options from POST/GET
$show_delivery_address = isset($_REQUEST['show_delivery_address']) ? $_REQUEST['show_delivery_address'] : '1';
$show_order_notes = isset($_REQUEST['show_order_notes']) ? $_REQUEST['show_order_notes'] : '1';
$show_order_summary = isset($_REQUEST['show_order_summary']) ? $_REQUEST['show_order_summary'] : '1';
$show_item_descriptions = isset($_REQUEST['show_item_descriptions']) ? $_REQUEST['show_item_descriptions'] : '1';
$show_footer = isset($_REQUEST['show_footer']) ? $_REQUEST['show_footer'] : '1';
$status_display = isset($_REQUEST['status_display']) ? $_REQUEST['status_display'] : 'waiting_for_delivery';
$download_pdf = isset($_REQUEST['download']) ? $_REQUEST['download'] : '0';

// Set headers based on request type
if ($download_pdf === '1') {
    // PDF download headers
    $filename = 'Order_' . $order['order_number'] . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
} else {
    // HTML preview headers
    $filename = 'Order_' . $order['order_number'] . '.html';
    header('Content-Type: text/html; charset=UTF-8');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
}

// Generate content based on request type
if ($download_pdf === '1') {
    // Generate PDF-like content
    generateOrderContent($order, $settings, $username, $show_delivery_address, $show_order_notes, $show_order_summary, $show_item_descriptions, $show_footer, $status_display, false);
} else {
    // Generate HTML preview
    generateOrderContent($order, $settings, $username, $show_delivery_address, $show_order_notes, $show_order_summary, $show_item_descriptions, $show_footer, $status_display, true);
}

// Function to generate order HTML content (shared between PDF and preview)
function generateOrderContent($order, $settings, $username, $show_delivery_address, $show_order_notes, $show_order_summary, $show_item_descriptions, $show_footer, $status_display, $is_preview = false) {
    $html = '';

    if ($is_preview) {
        // HTML Preview with enhanced styling and print controls
        $html .= '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order ' . htmlspecialchars($order['order_number']) . ' - Print Ready</title>
    <style>
        @media print {
            body { margin: 0; padding: 15pt; }
            .no-print { display: none !important; }
            .page-break { page-break-before: always; }
            .page-break-after { page-break-after: always; }
        }

        @media screen {
            body {
                margin: 20px;
                padding: 20px;
                background: #f5f5f5;
                font-family: Arial, sans-serif;
            }
            .print-controls {
                position: fixed;
                top: 20px;
                right: 20px;
                background: white;
                border: 1px solid #ddd;
                border-radius: 8px;
                padding: 15px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                z-index: 1000;
            }
            .print-controls button {
                display: block;
                width: 100%;
                margin-bottom: 10px;
                padding: 8px 12px;
                border: none;
                border-radius: 4px;
                cursor: pointer;
            }
            .print-controls .btn-primary {
                background: #007bff;
                color: white;
            }
            .print-controls .btn-secondary {
                background: #6c757d;
                color: white;
            }
        }
        body {
            font-family: \'DejaVu Sans\', \'Arial\', sans-serif;
            font-size: 12pt;
            line-height: 1.4;
            color: #212529;
            margin: 0;
            padding: 20pt;
        }

        .print-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 30pt;
            border-bottom: 2px solid #dee2e6;
            padding-bottom: 20pt;
        }

        .company-info {
            flex: 1;
            padding-right: 20pt;
        }

        .supplier-info {
            flex: 1;
            padding-left: 20pt;
            text-align: right;
        }

        .order-title {
            text-align: center;
            margin: 20pt 0;
            font-size: 18pt;
            font-weight: 600;
        }

        .order-details {
            display: flex;
            justify-content: space-between;
            margin: 20pt 0;
            padding: 15pt;
            background: #f8f9fa;
            border-radius: 5pt;
        }

        .order-details-left {
            flex: 1;
            padding-right: 20pt;
        }

        .order-details-right {
            flex: 1;
            padding-left: 20pt;
        }

        .status-badge {
            display: inline-block;
            padding: 5pt 10pt;
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
            border-radius: 3pt;
            font-size: 10pt;
            font-weight: 600;
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

        .order-items-table .text-center {
            text-align: center;
        }

        .order-items-table .text-end {
            text-align: right;
        }

        .order-summary {
            display: flex;
            justify-content: space-between;
            margin: 20pt 0;
            padding: 15pt;
            background: #f8f9fa;
            border-radius: 5pt;
        }

        .summary-left {
            flex: 1;
        }

        .summary-right {
            flex: 1;
            text-align: right;
        }

        .summary-item {
            margin-bottom: 5pt;
        }

        .summary-total {
            font-weight: 600;
            font-size: 12pt;
            border-top: 1px solid #dee2e6;
            padding-top: 10pt;
            margin-top: 10pt;
        }

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
            color: #6c757d;
        }

        .delivery-address {
            margin-top: 10pt;
            padding: 10pt;
            background: #fff;
            border: 1px solid #dee2e6;
            border-radius: 3pt;
        }
    </style>
</head>
<body>
    <!-- Print Controls (Screen Only) -->
    <div class="print-controls no-print">
        <h4 style="margin: 0 0 15px 0; font-size: 16px;">Print Controls</h4>
        <button onclick="window.print()" class="btn-primary">
            ðŸ–¨ï¸ Print Order
        </button>
        <button onclick="window.close()" class="btn-secondary">
            âŒ Close
        </button>
        <hr style="margin: 15px 0; border: none; border-top: 1px solid #ddd;">
        <small style="color: #666; font-size: 11px;">
            ðŸ’¡ Use Ctrl+P or Cmd+P to print<br>
            ðŸ“„ Save as PDF from print dialog
        </small>
    </div>';
    } else {
        // PDF-style HTML with minimal styling
        $html .= '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Order ' . htmlspecialchars($order['order_number']) . '</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12pt; line-height: 1.4; margin: 20pt; }
        .header { text-align: center; margin-bottom: 30pt; border-bottom: 2px solid #000; padding-bottom: 20pt; }
        .company-info { text-align: left; margin-bottom: 20pt; }
        .supplier-info { text-align: right; margin-bottom: 20pt; }
        .order-title { font-size: 18pt; font-weight: bold; margin: 20pt 0; }
        .status-badge { display: inline-block; padding: 5pt 10pt; background: #fff3cd; border: 1px solid #000; border-radius: 3pt; }
        .order-details { display: flex; justify-content: space-between; margin: 20pt 0; padding: 15pt; background: #f8f9fa; }
        .order-items { margin: 20pt 0; }
        .order-items table { width: 100%; border-collapse: collapse; }
        .order-items th, .order-items td { border: 1px solid #000; padding: 8pt; text-align: left; }
        .order-items th { background: #f8f9fa; font-weight: bold; }
        .order-summary { margin: 20pt 0; padding: 15pt; background: #f8f9fa; }
        .footer { margin-top: 30pt; padding-top: 20pt; border-top: 1px solid #000; text-align: center; font-size: 9pt; }
    </style>
</head>
<body>';
    }

    // Generate the HTML body content (same for both preview and PDF)
    $html .= generateOrderBody($order, $settings, $username, $show_delivery_address, $show_order_notes, $show_order_summary, $show_item_descriptions, $show_footer, $status_display, $is_preview);

    // Close HTML document
    if ($is_preview) {
        $html .= '<script>
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
        </script>';
    }

    $html .= '</body></html>';

    // Output the content
    echo $html;
    exit;
}

// Function to generate the shared HTML body content
function generateOrderBody($order, $settings, $username, $show_delivery_address, $show_order_notes, $show_order_summary, $show_item_descriptions, $show_footer, $status_display, $is_preview) {
    $html = '';

    // Company and Supplier Header
    $html .= '<div class="' . ($is_preview ? 'print-header' : 'header') . '">
        <div class="company-info">
            <h2>' . htmlspecialchars($settings['company_name'] ?? 'Company Name') . '</h2>';
    if ($settings['company_address']) {
        $html .= '<p>' . nl2br(htmlspecialchars($settings['company_address'])) . '</p>';
    }
    if ($settings['company_phone']) {
        $html .= '<p><strong>Phone:</strong> ' . htmlspecialchars($settings['company_phone']) . '</p>';
    }
    if ($settings['company_email']) {
        $html .= '<p><strong>Email:</strong> ' . htmlspecialchars($settings['company_email']) . '</p>';
    }
    $html .= '</div>

        <div class="supplier-info">
            <h3>Supplier Information</h3>
            <p><strong>' . htmlspecialchars($order['supplier_name'] ?? 'N/A') . '</strong></p>';
    if ($order['contact_person']) {
        $html .= '<p><strong>Contact:</strong> ' . htmlspecialchars($order['contact_person']) . '</p>';
    }
    if ($order['phone']) {
        $html .= '<p><strong>Phone:</strong> ' . htmlspecialchars($order['phone']) . '</p>';
    }
    if ($order['address'] && $show_delivery_address === '1') {
        $html .= '<p><strong>Delivery Address:</strong><br>' . nl2br(htmlspecialchars($order['address'])) . '</p>';
    }
    $html .= '</div></div>';

    // Order Title and Status
    $status_text = ($status_display === 'current') ? ucfirst($order['status']) : 'Waiting for Delivery';
    $html .= '<div class="order-title">
        Purchase Order #' . htmlspecialchars($order['order_number']) . '
        <div class="status-badge">' . $status_text . '</div>
    </div>';

    // Order Details
    $html .= '<div class="order-details">
        <div>
            <p><strong>Order Date:</strong> ' . date('M j, Y', strtotime($order['order_date'])) . '</p>
            <p><strong>Created:</strong> ' . date('M j, Y g:i A', strtotime($order['created_at'])) . '</p>
        </div>
        <div>
            <p><strong>Order ID:</strong> #' . htmlspecialchars($order['id']) . '</p>
            <p><strong>Created By:</strong> ' . htmlspecialchars($order['created_by_name'] ?? 'System') . '</p>
            <p><strong>Total Items:</strong> ' . $order['total_items'] . '</p>
        </div>
    </div>';

    // Order Items Table
    $table_class = $is_preview ? 'order-items-table' : 'order-items';
    $html .= '<div class="' . $table_class . '">
        <h3>Order Items</h3>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Product</th>
                    <th>SKU</th>
                    <th>Quantity</th>
                    <th>Unit Price</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>';

    $counter = 1;
    foreach ($order['items'] as $item) {
        $html .= '<tr>
            <td' . ($is_preview ? ' class="text-center"' : '') . '>' . $counter . '</td>
            <td>
                <strong>' . htmlspecialchars($item['product_name']) . '</strong><br>';
        if ($item['category_name']) {
            $html .= '<small>' . htmlspecialchars($item['category_name']) . '</small><br>';
        }
        if ($item['description'] && $show_item_descriptions === '1') {
            $html .= '<small>' . htmlspecialchars(substr($item['description'], 0, 100)) . '</small>';
        }
        $html .= '</td>
            <td>' . htmlspecialchars($item['sku'] ?? 'N/A') . '</td>
            <td' . ($is_preview ? ' class="text-center"' : '') . '>' . $item['quantity'] . '</td>
            <td' . ($is_preview ? ' class="text-end"' : '') . '>' . formatCurrency($item['cost_price'], $settings['currency_symbol'] ?? 'KES') . '</td>
            <td' . ($is_preview ? ' class="text-end"' : '') . '>' . formatCurrency($item['quantity'] * $item['cost_price'], $settings['currency_symbol'] ?? 'KES') . '</td>
        </tr>';
        $counter++;
    }

    $html .= '</tbody></table></div>';

    // Order Summary
    if ($show_order_summary === '1') {
        $html .= '<div class="order-summary">
            <h3>Order Summary</h3>
            <p><strong>Subtotal:</strong> ' . formatCurrency($order['total_amount'], $settings['currency_symbol'] ?? 'KES') . '</p>
            <p><strong>Total Amount:</strong> ' . formatCurrency($order['total_amount'], $settings['currency_symbol'] ?? 'KES') . '</p>
        </div>';
    }

    // Order Notes
    if ($order['notes'] && $show_order_notes === '1') {
        $html .= '<div class="order-summary">
            <h3>Order Notes</h3>
            <p>' . nl2br(htmlspecialchars($order['notes'])) . '</p>
        </div>';
    }

    // Footer
    if ($show_footer === '1') {
        $footer_class = $is_preview ? 'footer-info' : 'footer';
        $html .= '<div class="' . $footer_class . '">
            <p>This is a computer-generated document. No signature required.</p>
            <p>Generated on ' . date('M j, Y \a\t g:i A') . ' by ' . htmlspecialchars($username) . '</p>
        </div>';
    }

    return $html;
}

// For production use, consider installing a proper PDF library:
// - TCPDF: composer require tecnickcom/tcpdf
// - DomPDF: composer require dompdf/dompdf
// - FPDF: composer require setasign/fpdf

// Example with DomPDF (if installed):
/*
require_once 'vendor/autoload.php';
use Dompdf\Dompdf;

$dompdf = new Dompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream($filename, array('Attachment' => true));
*/
?>
