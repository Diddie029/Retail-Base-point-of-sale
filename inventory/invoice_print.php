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

// Check if user has permission to view invoices
if (!in_array('manage_products', $permissions) && !in_array('process_sales', $permissions)) {
    header("Location: inventory.php?error=permission_denied");
    exit();
}

// Get invoice ID from URL
$invoice_id = isset($_GET['id']) ? trim($_GET['id']) : null;
if (!$invoice_id) {
    header("Location: inventory.php?error=invalid_invoice");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Function to get invoice data
function getInvoiceData($conn, $invoice_id) {
    try {
        // Get invoice details (only received orders are invoices)
        $stmt = $conn->prepare("
            SELECT io.*,
                   s.name as supplier_name, s.contact_person, s.phone, s.email, s.address,
                   u.username as created_by_name
            FROM inventory_orders io
            LEFT JOIN suppliers s ON io.supplier_id = s.id
            LEFT JOIN users u ON io.user_id = u.id
            WHERE (io.id = :invoice_id OR io.order_number = :order_number OR io.invoice_number = :invoice_number)
            AND io.status = 'received'
        ");
        $stmt->execute([
            ':invoice_id' => is_numeric($invoice_id) ? $invoice_id : 0,
            ':order_number' => $invoice_id,
            ':invoice_number' => $invoice_id
        ]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$invoice) {
            return null;
        }

        // Get invoice items
        $stmt = $conn->prepare("
            SELECT ioi.*,
                   p.name as product_name, p.sku, p.description, p.image_url,
                   c.name as category_name, b.name as brand_name
            FROM inventory_order_items ioi
            LEFT JOIN products p ON ioi.product_id = p.id
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN brands b ON p.brand_id = b.id
            WHERE ioi.order_id = :order_id
            AND ioi.received_quantity > 0
            ORDER BY ioi.id ASC
        ");
        $stmt->bindParam(':order_id', $invoice['id']);
        $stmt->execute();
        $invoice['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate totals
        $invoice['total_received_items'] = 0;
        $invoice['total_received_amount'] = 0;
        foreach ($invoice['items'] as $item) {
            $invoice['total_received_items'] += $item['received_quantity'];
            $invoice['total_received_amount'] += ($item['received_quantity'] * $item['cost_price']);
        }

        return $invoice;
    } catch (PDOException $e) {
        error_log("Error getting invoice data: " . $e->getMessage());
        return null;
    }
}

// Get invoice data
$invoice = getInvoiceData($conn, $invoice_id);
if (!$invoice) {
    header("Location: inventory.php?error=invoice_not_found");
    exit();
}

// Check if auto-print is requested
$auto_print = isset($_GET['print']) && $_GET['print'] == '1';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice <?php echo htmlspecialchars($invoice['invoice_number'] ?? $invoice['order_number']); ?> - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
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

        .invoice-title-full {
            width: 100%;
            text-align: center;
            margin-bottom: 20pt;
            padding: 15pt 0;
            border-bottom: 2px solid #dee2e6;
        }

        .invoice-title-full h2 {
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

        .supplier-info {
            padding-right: 20pt;
        }

        .supplier-info h4 {
            color: var(--primary-color);
            margin-bottom: 15pt;
            font-size: 14pt;
            font-weight: 600;
        }

        .supplier-details {
            background: #f8f9fa;
            padding: 15pt;
            border-radius: 5pt;
            border: 1px solid #e9ecef;
        }

        .supplier-name {
            font-size: 13pt;
            font-weight: 600;
            color: #495057;
            margin-bottom: 10pt;
        }

        .supplier-contact {
            font-size: 10pt;
            margin-bottom: 5pt;
        }

        .supplier-address {
            font-size: 10pt;
            margin-top: 10pt;
            padding-top: 10pt;
            border-top: 1px solid #dee2e6;
        }

        .right-section {
            width: 50%;
            display: flex;
            flex-direction: column;
        }

        .company-info {
            padding-left: 20pt;
            margin-bottom: 20pt;
        }

        .company-info h3 {
            color: var(--primary-color);
            margin-bottom: 10pt;
            font-size: 14pt;
            font-weight: 600;
        }

        .company-details {
            background: #f8f9fa;
            padding: 15pt;
            border-radius: 5pt;
            border: 1px solid #e9ecef;
        }

        .invoice-details {
            padding-left: 20pt;
        }

        .invoice-details h4 {
            color: var(--primary-color);
            margin-bottom: 15pt;
            font-size: 14pt;
            font-weight: 600;
        }

        .invoice-details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8pt;
        }

        .invoice-detail-item {
            font-size: 10pt;
            padding: 8pt;
            background: #f8f9fa;
            border-radius: 3pt;
            border: 1px solid #e9ecef;
        }

        .invoice-detail-item strong {
            color: #495057;
        }

        .invoice-items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20pt 0;
        }

        .invoice-items-table th,
        .invoice-items-table td {
            border: 1px solid #dee2e6;
            padding: 8pt;
            text-align: left;
            font-size: 10pt;
        }

        .invoice-items-table th {
            background: #f8f9fa;
            font-weight: 600;
        }

        .invoice-summary {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin: 20pt 0;
            padding: 15pt;
            background: #f8f9fa;
            border-radius: 5pt;
            border: 1px solid #e9ecef;
        }

        .summary-left {
            flex: 1;
        }

        .summary-right {
            text-align: right;
        }

        .summary-item {
            margin-bottom: 8pt;
            font-size: 10pt;
        }

        .summary-total {
            font-size: 12pt;
            font-weight: 600;
            color: var(--primary-color);
        }

        .notes-section {
            margin: 20pt 0;
            padding: 15pt;
            background: #f8f9fa;
            border-radius: 5pt;
            border: 1px solid #e9ecef;
        }

        .notes-section h5 {
            color: var(--primary-color);
            margin-bottom: 10pt;
            font-size: 12pt;
            font-weight: 600;
        }

        .footer-info {
            margin-top: 30pt;
            padding-top: 20pt;
            border-top: 1px solid #dee2e6;
            text-align: center;
            font-size: 9pt;
            color: #6c757d;
        }

        .customization-panel {
            position: fixed;
            top: 20px;
            right: 20px;
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 8pt;
            padding: 15pt;
            box-shadow: 0 4pt 12pt rgba(0,0,0,0.1);
            z-index: 1000;
            max-width: 250pt;
        }

        .customization-panel h6 {
            margin-bottom: 10pt;
            color: var(--primary-color);
            font-size: 11pt;
        }

        .customization-panel .form-check {
            margin-bottom: 8pt;
        }

        .customization-panel .form-check-label {
            font-size: 9pt;
        }

        .customization-panel .btn {
            font-size: 9pt;
            padding: 5pt 10pt;
        }

        @media print {
            body {
                margin: 0;
                padding: 15pt;
                -webkit-print-color-adjust: exact;
                color-adjust: exact;
            }
            .customization-panel { display: none !important; }
            .no-print { display: none !important; }
            .page-break { page-break-before: always; }
            .page-break-after { page-break-after: always; }
            @page {
                size: A4;
                margin: 1cm;
            }
        }

        .text-center { text-align: center; }
        .text-end { text-align: right; }
        .text-muted { color: #6c757d; }
        .fw-semibold { font-weight: 600; }
        .fw-bold { font-weight: 700; }
        .small { font-size: 0.875em; }
    </style>
</head>
<body>
    <!-- Customization Panel -->
    <div class="customization-panel">
        <h6><i class="bi bi-gear me-2"></i>Print Options</h6>
        <div class="form-check">
            <input class="form-check-input" type="checkbox" id="showSupplierAddress" checked>
            <label class="form-check-label" for="showSupplierAddress">
                Show Supplier Address
            </label>
        </div>
        <div class="form-check">
            <input class="form-check-input" type="checkbox" id="showInvoiceNotes" checked>
            <label class="form-check-label" for="showInvoiceNotes">
                Show Invoice Notes
            </label>
        </div>
        <div class="form-check">
            <input class="form-check-input" type="checkbox" id="showInvoiceSummary" checked>
            <label class="form-check-label" for="showInvoiceSummary">
                Show Invoice Summary
            </label>
        </div>
        <div class="form-check">
            <input class="form-check-input" type="checkbox" id="showFooter" checked>
            <label class="form-check-label" for="showFooter">
                Show Footer
            </label>
        </div>
        <hr class="my-2">
        <button onclick="window.print()" class="btn btn-primary btn-sm w-100">
            <i class="bi bi-printer me-2"></i>Print Invoice
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

    <!-- Invoice Title - Full Width -->
    <div class="invoice-title-full">
        <h2 style="margin: 0; color: var(--primary-color); text-align: center;">Invoice #<?php echo htmlspecialchars($invoice['invoice_number'] ?? $invoice['order_number']); ?></h2>
    </div>

    <!-- Invoice Header -->
    <div class="print-header">
        <!-- Left Section: Company Details + Supplier Information (50%) -->
        <div class="left-section">
            <!-- Company Information -->
            <div class="company-info">
                <h4>Company Details</h4>
                <div class="company-details">
                    <div class="company-name" style="font-size: 13pt; font-weight: 600; color: #495057; margin-bottom: 10pt;"><?php echo htmlspecialchars($settings['company_name'] ?? 'Company Name'); ?></div>
                    <?php if ($settings['company_address']): ?>
                    <div style="margin-bottom: 5pt; font-size: 10pt;"><?php echo nl2br(htmlspecialchars($settings['company_address'])); ?></div>
                    <?php endif; ?>
                    <?php if ($settings['company_phone']): ?>
                    <div style="margin-bottom: 5pt; font-size: 10pt;"><strong>Phone:</strong> <?php echo htmlspecialchars($settings['company_phone']); ?></div>
                    <?php endif; ?>
                    <?php if ($settings['company_email']): ?>
                    <div style="margin-bottom: 5pt; font-size: 10pt;"><strong>Email:</strong> <?php echo htmlspecialchars($settings['company_email']); ?></div>
                    <?php endif; ?>
                    <?php if ($settings['company_website']): ?>
                    <div style="font-size: 10pt;"><strong>Website:</strong> <?php echo htmlspecialchars($settings['company_website']); ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Supplier Information -->
            <div class="supplier-info" style="margin-top: 20pt;">
                <h4>Supplier Information</h4>
                <div class="supplier-details">
                    <div class="supplier-name"><?php echo htmlspecialchars($invoice['supplier_name'] ?? 'N/A'); ?></div>
                    <?php if ($invoice['contact_person']): ?>
                    <div class="supplier-contact">
                        <strong>Contact:</strong> <?php echo htmlspecialchars($invoice['contact_person']); ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($invoice['phone']): ?>
                    <div class="supplier-contact">
                        <strong>Phone:</strong> <?php echo htmlspecialchars($invoice['phone']); ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($invoice['email']): ?>
                    <div class="supplier-contact">
                        <strong>Email:</strong> <?php echo htmlspecialchars($invoice['email']); ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($invoice['address']): ?>
                    <div class="supplier-address">
                        <strong>Address:</strong><br>
                        <span><?php echo nl2br(htmlspecialchars($invoice['address'])); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right Section: Invoice Details (50%) -->
        <div class="right-section">
            <!-- Invoice Details -->
            <div class="invoice-details">
                <h4>Invoice Details</h4>
                <div class="invoice-details-grid">
                    <div class="invoice-detail-item">
                        <strong>Invoice #:</strong><br>
                        <?php echo htmlspecialchars($invoice['invoice_number'] ?? $invoice['order_number']); ?>
                    </div>
                    <div class="invoice-detail-item">
                        <strong>Order Date:</strong><br>
                        <?php echo date('M j, Y', strtotime($invoice['order_date'])); ?>
                    </div>
                    <div class="invoice-detail-item">
                        <strong>Received Date:</strong><br>
                        <?php echo date('M j, Y', strtotime($invoice['received_date'] ?? $invoice['updated_at'])); ?>
                    </div>
                    <div class="invoice-detail-item">
                        <strong>Created By:</strong><br>
                        <?php echo htmlspecialchars($invoice['created_by_name'] ?? 'System'); ?>
                    </div>
                    <?php if ($invoice['supplier_invoice_number']): ?>
                    <div class="invoice-detail-item">
                        <strong>Supplier Invoice:</strong><br>
                        <?php echo htmlspecialchars($invoice['supplier_invoice_number']); ?>
                    </div>
                    <?php endif; ?>
                    <div class="invoice-detail-item">
                        <strong>Total Items:</strong><br>
                        <?php echo $invoice['total_received_items']; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Invoice Items -->
    <h4 id="invoiceItemsHeader" style="margin: 20pt 0 10pt 0; color: var(--primary-color);">Invoice Items</h4>
    <table id="invoiceItemsTable" class="invoice-items-table">
        <thead>
            <tr>
                <th style="width: 5%;">#</th>
                <th style="width: 35%;">Product</th>
                <th style="width: 15%;">SKU</th>
                <th style="width: 10%;" class="text-center">Received</th>
                <th style="width: 15%;" class="text-end">Cost Price</th>
                <th style="width: 20%;" class="text-end">Total</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $counter = 1;
            foreach ($invoice['items'] as $item):
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
                <td class="text-center"><?php echo $item['received_quantity']; ?></td>
                <td class="text-end"><?php echo formatCurrency($item['cost_price'], $settings['currency_symbol'] ?? 'KES'); ?></td>
                <td class="text-end"><?php echo formatCurrency($item['received_quantity'] * $item['cost_price'], $settings['currency_symbol'] ?? 'KES'); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Invoice Summary -->
    <div id="invoiceSummary" class="invoice-summary">
        <div class="summary-left">
            <div class="summary-item">
                <strong>Items Received:</strong> <?php echo $invoice['total_received_items']; ?>
            </div>
        </div>
        <div class="summary-right">
            <div class="summary-total">
                <strong>Invoice Total:</strong> <?php echo formatCurrency($invoice['total_received_amount'], $settings['currency_symbol'] ?? 'KES'); ?>
            </div>
        </div>
    </div>

    <!-- Invoice Notes -->
    <?php if ($invoice['invoice_notes'] || $invoice['notes']): ?>
    <div id="notesSection" class="notes-section">
        <h5>Invoice Notes</h5>
        <div style="font-size: 10pt; line-height: 1.5;"><?php echo nl2br(htmlspecialchars($invoice['invoice_notes'] ?? $invoice['notes'])); ?></div>
    </div>
    <?php endif; ?>

    <!-- Footer -->
    <div id="footerInfo" class="footer-info">
        <p>This is a computer-generated invoice. No signature required.</p>
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
            const showSupplierAddress = document.getElementById('showSupplierAddress').checked ? '1' : '0';
            const showInvoiceNotes = document.getElementById('showInvoiceNotes').checked ? '1' : '0';
            const showInvoiceSummary = document.getElementById('showInvoiceSummary').checked ? '1' : '0';
            const showFooter = document.getElementById('showFooter').checked ? '1' : '0';

            // Build URL with parameters
            const baseUrl = 'generate_pdf.php';
            const params = new URLSearchParams({
                id: '<?php echo urlencode($invoice_id); ?>',
                show_supplier_address: showSupplierAddress,
                show_invoice_notes: showInvoiceNotes,
                show_invoice_summary: showInvoiceSummary,
                show_footer: showFooter
            });

            // Open print-ready HTML page
            const printUrl = baseUrl + '?' + params.toString();
            window.open(printUrl, '_blank');
        }

        // Customization panel functionality
        document.getElementById('showSupplierAddress').addEventListener('change', function() {
            const supplierAddress = document.querySelector('.supplier-address');
            if (supplierAddress) {
                supplierAddress.style.display = this.checked ? 'block' : 'none';
            }
        });

        document.getElementById('showInvoiceNotes').addEventListener('change', function() {
            const notesSection = document.getElementById('notesSection');
            if (notesSection) {
                notesSection.style.display = this.checked ? 'block' : 'none';
            }
        });

        document.getElementById('showInvoiceSummary').addEventListener('change', function() {
            const invoiceSummary = document.getElementById('invoiceSummary');
            invoiceSummary.style.display = this.checked ? 'block' : 'none';
        });

        document.getElementById('showFooter').addEventListener('change', function() {
            const footer = document.getElementById('footerInfo');
            footer.style.display = this.checked ? 'block' : 'none';
        });
    </script>
</body>
</html>
