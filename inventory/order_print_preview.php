<?php
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

// Auto-trigger print dialog
$auto_print = isset($_GET['auto_print']) ? $_GET['auto_print'] : false;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order <?php echo htmlspecialchars($order['order_number']); ?> - Print Preview</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }

        body {
            font-family: 'Inter', sans-serif;
            font-size: 12pt;
            line-height: 1.4;
            color: #212529;
            background: white;
            margin: 0;
            padding: 20pt;
        }

        .order-title-full {
            width: 100%;
            text-align: center;
            margin-bottom: 20pt;
            padding: 15pt 0;
            border-bottom: 2px solid #dee2e6;
        }

        .order-title-full h2 {
            font-size: 20pt;
            font-weight: 700;
            margin: 0;
        }

        .print-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 30pt;
            border-bottom: 2px solid #dee2e6;
            padding-bottom: 20pt;
        }

        .left-section {
            width: 50%;
            display: flex;
            flex-direction: column;
        }

        .company-info {
            padding-right: 20pt;
        }

        .order-info {
            text-align: left;
            padding: 0 20pt;
        }

        .order-details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8pt;
            margin-top: 15pt;
        }

        .order-detail-item {
            font-size: 10pt;
            padding: 5pt;
            background: #f8f9fa;
            border-radius: 3pt;
            border: 1px solid #e9ecef;
        }

        .supplier-info {
            flex: 1;
            width: 50%;
            padding-left: 20pt;
            text-align: right;
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

        /* Print-specific styles */
        @media print {
            body {
                margin: 0;
                padding: 15pt;
            }

            .no-print {
                display: none !important;
            }

            .page-break {
                page-break-before: always;
            }

            .page-break-after {
                page-break-after: always;
            }
        }

        /* Screen-only elements for customization */
        @media screen {
            .customization-panel {
                position: fixed;
                top: 20px;
                right: 20px;
                background: white;
                border: 1px solid #dee2e6;
                border-radius: 5px;
                padding: 15px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                z-index: 1000;
                max-width: 300px;
            }

            .customization-panel h6 {
                margin-bottom: 10px;
                font-size: 14px;
            }

            .customization-panel .form-check {
                margin-bottom: 8px;
            }
        }
    </style>
</head>
<body>
    <!-- Customization Panel (Screen Only) -->
    <div class="customization-panel no-print">
        <h6><i class="bi bi-gear me-2"></i>Print Options</h6>
        <div class="form-check">
            <input class="form-check-input" type="checkbox" id="showDeliveryAddress" checked>
            <label class="form-check-label" for="showDeliveryAddress">
                Show Delivery Address
            </label>
        </div>
        <div class="form-check">
            <input class="form-check-input" type="checkbox" id="showOrderNotes" checked>
            <label class="form-check-label" for="showOrderNotes">
                Show Order Notes
            </label>
        </div>
        <div class="form-check">
            <input class="form-check-input" type="checkbox" id="showOrderSummary" checked>
            <label class="form-check-label" for="showOrderSummary">
                Show Order Summary
            </label>
        </div>
        <div class="form-check">
            <input class="form-check-input" type="checkbox" id="showItemDescriptions" checked>
            <label class="form-check-label" for="showItemDescriptions">
                Show Item Descriptions
            </label>
        </div>
        <div class="form-check">
            <input class="form-check-input" type="checkbox" id="showFooter" checked>
            <label class="form-check-label" for="showFooter">
                Show Footer Info
            </label>
        </div>

        <button onclick="window.print()" class="btn btn-primary btn-sm w-100">
            <i class="bi bi-printer me-2"></i>Print Order
        </button>
        <button onclick="downloadServerPDF()" class="btn btn-success btn-sm w-100 mt-2">
            <i class="bi bi-file-earmark-pdf me-2"></i>Download PDF
        </button>
        <button onclick="saveAsPDF()" class="btn btn-info btn-sm w-100 mt-2">
            <i class="bi bi-printer me-2"></i>Save as PDF (Browser)
        </button>
        <button onclick="downloadPDF()" class="btn btn-info btn-sm w-100 mt-2">
            <i class="bi bi-file-earmark-text me-2"></i>Print-Ready View
        </button>
        <button onclick="window.close()" class="btn btn-outline-secondary btn-sm w-100 mt-2">
            <i class="bi bi-x me-2"></i>Close Preview
        </button>
        <hr class="my-2">
        <small class="text-muted">
            <i class="bi bi-printer me-1"></i>
            Use Ctrl+P or Cmd+P to print<br>
            <i class="bi bi-file-earmark-pdf me-1"></i>
            "Download PDF" uses server-side generation (recommended)<br>
            <i class="bi bi-printer me-1"></i>
            "Save as PDF (Browser)" uses browser print dialog<br>
            <i class="bi bi-file-earmark-text me-1"></i>
            Print-Ready View opens simplified version<br>
            <i class="bi bi-info-circle me-1"></i>
            Server PDF is faster and more reliable
        </small>
    </div>

    <!-- Purchase Order Title - Full Width -->
    <div class="order-title-full">
        <h2 style="margin: 0; color: var(--primary-color); text-align: center;">Purchase Order #<?php echo htmlspecialchars($order['order_number']); ?></h2>
    </div>

    <!-- Order Header -->
    <div class="print-header">
        <div class="left-section">
            <div class="company-info">
                <h3 style="margin: 0; color: var(--primary-color);"><?php echo htmlspecialchars($settings['company_name'] ?? 'Company Name'); ?></h3>
                <?php if ($settings['company_address']): ?>
                <p style="margin: 5pt 0; font-size: 10pt;"><?php echo nl2br(htmlspecialchars($settings['company_address'])); ?></p>
                <?php endif; ?>
                <?php if ($settings['company_phone']): ?>
                <p style="margin: 5pt 0; font-size: 10pt;"><strong>Phone:</strong> <?php echo htmlspecialchars($settings['company_phone']); ?></p>
                <?php endif; ?>
                <?php if ($settings['company_email']): ?>
                <p style="margin: 5pt 0; font-size: 10pt;"><strong>Email:</strong> <?php echo htmlspecialchars($settings['company_email']); ?></p>
                <?php endif; ?>
            </div>

            <div class="order-info">
                <div class="order-details-grid">
                    <div class="order-detail-item">
                        <strong>Order Date:</strong> <?php echo date('M j, Y', strtotime($order['order_date'])); ?>
                    </div>
                    <div class="order-detail-item">
                        <strong>Created:</strong> <?php echo date('M j, Y g:i A', strtotime($order['created_at'])); ?>
                    </div>
                    <?php if ($order['expected_date']): ?>
                    <div class="order-detail-item">
                        <strong>Expected Date:</strong> <?php echo date('M j, Y', strtotime($order['expected_date'])); ?>
                    </div>
                    <?php endif; ?>
                    <div class="order-detail-item">
                        <strong>Order ID:</strong> #<?php echo htmlspecialchars($order['id']); ?>
                    </div>
                    <div class="order-detail-item">
                        <strong>Created By:</strong> <?php echo htmlspecialchars($order['created_by_name'] ?? 'System'); ?>
                    </div>
                    <div class="order-detail-item">
                        <strong>Total Items:</strong> <?php echo $order['total_items']; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="supplier-info">
            <h4 style="margin: 0; color: #495057;">Supplier Information</h4>
            <p style="margin: 5pt 0; font-size: 11pt; font-weight: 600;"><?php echo htmlspecialchars($order['supplier_name'] ?? 'N/A'); ?></p>
            <?php if ($order['contact_person']): ?>
            <p style="margin: 5pt 0; font-size: 10pt;"><strong>Contact:</strong> <?php echo htmlspecialchars($order['contact_person']); ?></p>
            <?php endif; ?>
            <?php if ($order['phone']): ?>
            <p style="margin: 5pt 0; font-size: 10pt;"><strong>Phone:</strong> <?php echo htmlspecialchars($order['phone']); ?></p>
            <?php endif; ?>
            <?php if ($order['email']): ?>
            <p style="margin: 5pt 0; font-size: 10pt;"><strong>Email:</strong> <?php echo htmlspecialchars($order['email']); ?></p>
            <?php endif; ?>
            <?php if ($order['address']): ?>
            <div class="delivery-address">
                <strong style="font-size: 10pt;">Delivery Address:</strong><br>
                <span style="font-size: 10pt;"><?php echo nl2br(htmlspecialchars($order['address'])); ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Order Items -->
    <h4 id="orderItemsHeader" style="margin: 20pt 0 10pt 0; color: var(--primary-color);">Order Items</h4>
    <table id="orderItemsTable" class="order-items-table">
        <thead>
            <tr>
                <th style="width: 5%;">#</th>
                <th style="width: 35%;">Product</th>
                <th style="width: 15%;">SKU</th>
                <th style="width: 10%;" class="text-center">Quantity</th>
                <th style="width: 15%;" class="text-end">Unit Price</th>
                <th style="width: 20%;" class="text-end">Total</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $counter = 1;
            foreach ($order['items'] as $item):
            ?>
            <tr>
                <td class="text-center"><?php echo $counter++; ?></td>
                <td>
                    <div style="font-weight: 500;"><?php echo htmlspecialchars($item['product_name']); ?></div>
                    <?php if ($item['category_name']): ?>
                    <small style="color: #6c757d;"><?php echo htmlspecialchars($item['category_name']); ?></small>
                    <?php endif; ?>
                    <?php if ($item['description']): ?>
                    <div style="margin-top: 3pt; font-size: 9pt; color: #6c757d;"><?php echo htmlspecialchars(substr($item['description'], 0, 100)); ?><?php echo strlen($item['description']) > 100 ? '...' : ''; ?></div>
                    <?php endif; ?>
                </td>
                <td><?php echo htmlspecialchars($item['sku'] ?? 'N/A'); ?></td>
                <td class="text-center"><?php echo $item['quantity']; ?></td>
                <td class="text-end"><?php echo formatCurrency($item['cost_price'], $settings['currency_symbol'] ?? 'KES'); ?></td>
                <td class="text-end"><?php echo formatCurrency($item['quantity'] * $item['cost_price'], $settings['currency_symbol'] ?? 'KES'); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Order Summary -->
    <div id="orderSummary" class="order-summary">
        <div class="summary-left">
            <div class="summary-item">
                <strong>Subtotal:</strong> <?php echo formatCurrency($order['total_amount'], $settings['currency_symbol'] ?? 'KES'); ?>
            </div>
            <?php if (isset($order['tax_amount']) && $order['tax_amount'] > 0): ?>
            <div class="summary-item">
                <strong>Tax:</strong> <?php echo formatCurrency($order['tax_amount'], $settings['currency_symbol'] ?? 'KES'); ?>
            </div>
            <?php endif; ?>
            <?php if (isset($order['discount_amount']) && $order['discount_amount'] > 0): ?>
            <div class="summary-item">
                <strong>Discount:</strong> -<?php echo formatCurrency($order['discount_amount'], $settings['currency_symbol'] ?? 'KES'); ?>
            </div>
            <?php endif; ?>
        </div>
        <div class="summary-right">
            <div class="summary-total">
                <strong>Total Amount:</strong> <?php echo formatCurrency($order['total_amount'], $settings['currency_symbol'] ?? 'KES'); ?>
            </div>
        </div>
    </div>

    <!-- Order Notes -->
    <?php if ($order['notes']): ?>
    <div class="notes-section">
        <h5 style="margin: 0 0 10pt 0; color: var(--primary-color);">Order Notes</h5>
        <div style="font-size: 10pt; line-height: 1.5;"><?php echo nl2br(htmlspecialchars($order['notes'])); ?></div>
    </div>
    <?php endif; ?>

    <!-- Footer -->
    <div id="footerInfo" class="footer-info">
        <p>This is a computer-generated document. No signature required.</p>
        <p>Generated on <?php echo date('M j, Y \a\t g:i A'); ?> by <?php echo htmlspecialchars($username); ?></p>
    </div>

    <script>
        // Auto-print if requested
        <?php if ($auto_print): ?>
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 500);
        };
        <?php endif; ?>

        // Save as PDF function using browser's print-to-PDF
        function saveAsPDF() {
            // Hide the customization panel temporarily for PDF generation
            const customizationPanel = document.querySelector('.customization-panel');
            if (customizationPanel) {
                customizationPanel.style.display = 'none';
            }

            // Create a temporary print style to optimize for PDF
            const printStyle = document.createElement('style');
            printStyle.id = 'pdf-print-style';
            printStyle.textContent = `
                @media print {
                    body {
                        margin: 0;
                        padding: 15pt;
                        -webkit-print-color-adjust: exact;
                        color-adjust: exact;
                    }
                    .no-print { display: none !important; }
                    .page-break { page-break-before: always; }
                    .page-break-after { page-break-after: always; }
                    @page {
                        size: A4;
                        margin: 1cm;
                    }
                }
            `;
            document.head.appendChild(printStyle);

            // Use a timeout to ensure styles are applied before printing
            setTimeout(() => {
                // Trigger browser's print dialog with PDF save option
                window.print();

                // Clean up after printing dialog is closed
                setTimeout(() => {
                    // Show customization panel again
                    if (customizationPanel) {
                        customizationPanel.style.display = 'block';
                    }
                    // Remove temporary print style
                    const tempStyle = document.getElementById('pdf-print-style');
                    if (tempStyle) {
                        tempStyle.remove();
                    }
                }, 1000);
            }, 100);
        }

        // PDF Download function (opens simplified HTML version)
        function downloadPDF() {
            // Get current customization settings
            const showDeliveryAddress = document.getElementById('showDeliveryAddress').checked ? '1' : '0';
            const showOrderNotes = document.getElementById('showOrderNotes').checked ? '1' : '0';
            const showOrderSummary = document.getElementById('showOrderSummary').checked ? '1' : '0';
            const showItemDescriptions = document.getElementById('showItemDescriptions').checked ? '1' : '0';
            const showFooter = document.getElementById('showFooter').checked ? '1' : '0';

            // Build URL with parameters
            const baseUrl = 'generate_pdf.php';
            const params = new URLSearchParams({
                id: '<?php echo urlencode($order_id); ?>',
                show_delivery_address: showDeliveryAddress,
                show_order_notes: showOrderNotes,
                show_order_summary: showOrderSummary,
                show_item_descriptions: showItemDescriptions,
                show_footer: showFooter
            });

            // Open print-ready HTML page
            const printUrl = baseUrl + '?' + params.toString();
            window.open(printUrl, '_blank');
        }

        // Server-side PDF download function
        function downloadServerPDF() {
            // Get current customization settings
            const showDeliveryAddress = document.getElementById('showDeliveryAddress').checked ? '1' : '0';
            const showOrderNotes = document.getElementById('showOrderNotes').checked ? '1' : '0';
            const showOrderSummary = document.getElementById('showOrderSummary').checked ? '1' : '0';
            const showItemDescriptions = document.getElementById('showItemDescriptions').checked ? '1' : '0';
            const showFooter = document.getElementById('showFooter').checked ? '1' : '0';

            // Build URL for server-side PDF generation
            const pdfUrl = 'generate_pdf.php?' + new URLSearchParams({
                id: '<?php echo urlencode($order_id); ?>',
                show_delivery_address: showDeliveryAddress,
                show_order_notes: showOrderNotes,
                show_order_summary: showOrderSummary,
                show_item_descriptions: showItemDescriptions,
                show_footer: showFooter,
                download: '1'
            }).toString();

            // Create a temporary link and trigger download
            const link = document.createElement('a');
            link.href = pdfUrl;
            link.style.display = 'none';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        // Customization panel functionality
        document.getElementById('showDeliveryAddress').addEventListener('change', function() {
            const deliveryAddress = document.querySelector('.delivery-address');
            deliveryAddress.style.display = this.checked ? 'block' : 'none';
        });

        document.getElementById('showOrderNotes').addEventListener('change', function() {
            const notesSection = document.querySelector('.notes-section');
            if (notesSection) {
                notesSection.style.display = this.checked ? 'block' : 'none';
            }
        });

        document.getElementById('showOrderSummary').addEventListener('change', function() {
            const orderSummary = document.getElementById('orderSummary');
            orderSummary.style.display = this.checked ? 'block' : 'none';
        });

        document.getElementById('showItemDescriptions').addEventListener('change', function() {
            const descriptions = document.querySelectorAll('.order-items-table td div[style*="font-size: 9pt"]');
            descriptions.forEach(desc => {
                desc.style.display = this.checked ? 'block' : 'none';
            });
        });

        document.getElementById('showFooter').addEventListener('change', function() {
            const footer = document.getElementById('footerInfo');
            footer.style.display = this.checked ? 'block' : 'none';
        });


    </script>
</body>
</html>
